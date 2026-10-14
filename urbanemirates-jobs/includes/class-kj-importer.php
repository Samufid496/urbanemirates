<?php
/**
 * Paste → queue → Claude → review pipeline.
 *
 * Pasted jobs are saved as draft "kj_job" posts with _kj_state = queued, then processed
 * one at a time (by the admin page over AJAX, with WP-Cron as a backup) so a slow API
 * call never hits the host's PHP time limit.
 *
 * _kj_state values: queued → processing → ready (pending review / published)
 *                   or error / duplicate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Importer {

	const MAX_ATTEMPTS = 3;

	public static function init() {
		add_action( 'wp_ajax_kj_process_next', array( __CLASS__, 'ajax_process_next' ) );
		add_action( 'admin_post_kj_queue_jobs', array( __CLASS__, 'handle_paste' ) );
	}

	/**
	 * Split pasted text into separate jobs on lines of --- or ===.
	 */
	public static function split_paste( $text ) {
		$blocks = preg_split( '/^\s*(?:-{3,}|={3,})\s*$/m', str_replace( "\r\n", "\n", (string) $text ) );
		$jobs   = array();
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( strlen( $block ) < 40 ) {
				continue; // Too short to be a job; probably a stray line.
			}
			$url = '';
			if ( preg_match( '#https?://[^\s<>"\']+#i', $block, $m ) ) {
				$url = rtrim( $m[0], '.,);' );
			}
			$jobs[] = array(
				'text' => $block,
				'url'  => $url,
			);
		}
		return $jobs;
	}

	/**
	 * Find an existing (non-trashed) job with the same normalised URL.
	 */
	public static function find_by_url( $url ) {
		$norm = kj_normalize_url( $url );
		if ( '' === $norm ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'meta_key'       => '_kj_url_hash',
				'meta_value'     => md5( $norm ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Save pasted jobs to the queue. Returns array( queued, duplicates ).
	 */
	public static function queue( array $jobs ) {
		$queued     = 0;
		$duplicates = 0;
		foreach ( $jobs as $job ) {
			if ( $job['url'] && self::find_by_url( $job['url'] ) ) {
				$duplicates++;
				continue;
			}
			$source  = $job['url'] ? kj_source_name( $job['url'] ) : 'pasted text';
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'kj_job',
					'post_status'  => 'draft',
					'post_title'   => 'Queued job (' . $source . ')',
					'post_content' => '',
					'post_author'  => get_current_user_id(),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			update_post_meta( $post_id, '_kj_state', 'queued' );
			update_post_meta( $post_id, '_kj_raw', wp_slash( $job['text'] ) );
			update_post_meta( $post_id, '_kj_attempts', 0 );
			if ( $job['url'] ) {
				update_post_meta( $post_id, '_kj_apply_url', esc_url_raw( $job['url'] ) );
				update_post_meta( $post_id, '_kj_url_hash', md5( kj_normalize_url( $job['url'] ) ) );
				update_post_meta( $post_id, '_kj_source', $source );
			}
			$queued++;
		}
		return array( $queued, $duplicates );
	}

	public static function queue_count() {
		$ids = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'draft',
				'meta_key'       => '_kj_state',
				'meta_value'     => 'queued',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		return count( $ids );
	}

	/**
	 * Claim and process the oldest queued job. Returns a result array, or null if the queue is empty.
	 */
	public static function process_next() {
		global $wpdb;

		$ids = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'draft',
				'meta_key'       => '_kj_state',
				'meta_value'     => 'queued',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'posts_per_page' => 5,
			)
		);

		foreach ( $ids as $post_id ) {
			// Atomic claim so the admin page and WP-Cron never process the same job twice.
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = 'processing' WHERE post_id = %d AND meta_key = '_kj_state' AND meta_value = 'queued'",
					$post_id
				)
			);
			if ( 1 === (int) $claimed ) {
				wp_cache_delete( $post_id, 'post_meta' );
				update_post_meta( $post_id, '_kj_claimed_at', time() );
				return self::process( $post_id );
			}
		}
		return null;
	}

	/**
	 * Run Claude on one job and save the result.
	 */
	public static function process( $post_id ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$raw    = (string) get_post_meta( $post_id, '_kj_raw', true );
		$url    = (string) get_post_meta( $post_id, '_kj_apply_url', true );
		$result = KJ_Claude::analyze_job( $raw, $url );

		if ( is_wp_error( $result ) ) {
			$attempts = (int) get_post_meta( $post_id, '_kj_attempts', true ) + 1;
			update_post_meta( $post_id, '_kj_attempts', $attempts );
			$data  = $result->get_error_data();
			$retry = ! empty( $data['retry'] ) && $attempts < self::MAX_ATTEMPTS;
			update_post_meta( $post_id, '_kj_state', $retry ? 'queued' : 'error' );
			update_post_meta( $post_id, '_kj_error', $result->get_error_message() );
			return array(
				'id'      => $post_id,
				'state'   => $retry ? 'queued' : 'error',
				'title'   => get_the_title( $post_id ),
				'message' => $result->get_error_message() . ( $retry ? ' (will retry)' : '' ),
				'stop'    => 'kj_no_key' === $result->get_error_code(),
			);
		}

		if ( empty( $result['is_job_posting'] ) ) {
			update_post_meta( $post_id, '_kj_state', 'error' );
			update_post_meta( $post_id, '_kj_error', 'Claude says this text is not a job posting.' );
			return array(
				'id'      => $post_id,
				'state'   => 'error',
				'title'   => get_the_title( $post_id ),
				'message' => 'Not a job posting',
			);
		}

		return self::save_result( $post_id, $result );
	}

	private static function save_result( $post_id, array $r ) {
		$title   = sanitize_text_field( $r['title'] );
		$company = sanitize_text_field( $r['company'] );
		$country = array_key_exists( $r['country'], kj_countries() ) ? $r['country'] : 'other';

		// Same title + company + country already on the site → duplicate.
		$content_hash = md5( strtolower( $title . '|' . $company . '|' . $country ) );
		$dupes        = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => array( 'publish', 'pending', 'future', 'private' ),
				'meta_key'       => '_kj_content_hash',
				'meta_value'     => $content_hash,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'post__not_in'   => array( $post_id ),
			)
		);
		if ( $company && $dupes ) {
			update_post_meta( $post_id, '_kj_state', 'duplicate' );
			update_post_meta( $post_id, '_kj_error', 'Same job already exists: #' . $dupes[0] );
			wp_trash_post( $post_id );
			return array(
				'id'      => $post_id,
				'state'   => 'duplicate',
				'title'   => $title,
				'message' => 'Duplicate of an existing job — skipped',
			);
		}

		$apply_url = (string) get_post_meta( $post_id, '_kj_apply_url', true );
		if ( '' === $apply_url && ! empty( $r['apply_url'] ) && wp_http_validate_url( $r['apply_url'] ) ) {
			$apply_url = esc_url_raw( $r['apply_url'] );
			update_post_meta( $post_id, '_kj_apply_url', $apply_url );
			update_post_meta( $post_id, '_kj_url_hash', md5( kj_normalize_url( $apply_url ) ) );
			update_post_meta( $post_id, '_kj_source', kj_source_name( $apply_url ) );
		}

		$risk = in_array( $r['risk_level'], array( 'green', 'yellow', 'red' ), true ) ? $r['risk_level'] : 'yellow';

		// A job with no way to apply can't be published as-is.
		if ( '' === $apply_url && 'green' === $risk ) {
			$risk                 = 'yellow';
			$r['risk_reasons'][]  = 'No link to the original job post was provided.';
		}

		$deadline = '';
		if ( ! empty( $r['deadline'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $r['deadline'] ) ) {
			$deadline = $r['deadline'];
		}

		$benefits = array();
		foreach ( array_keys( kj_benefit_labels() ) as $key ) {
			if ( ! empty( $r['benefits'][ $key ] ) ) {
				$benefits[] = $key;
			}
		}

		$auto_publish = kj_setting( 'auto_publish' ) && 'green' === $risk;

		$update = array(
			'ID'           => $post_id,
			'post_title'   => $title ? $title : 'Untitled job',
			'post_name'    => '',
			'post_content' => sanitize_textarea_field( $r['summary'] ),
			'post_status'  => $auto_publish ? 'publish' : 'pending',
		);
		if ( $auto_publish ) {
			$update['post_date']     = current_time( 'mysql' );
			$update['post_date_gmt'] = current_time( 'mysql', true );
		}
		wp_update_post( $update );

		$meta = array(
			'_kj_company'          => $company,
			'_kj_city'             => sanitize_text_field( $r['city'] ),
			'_kj_job_type'         => array_key_exists( $r['job_type'], kj_job_types() ) ? $r['job_type'] : 'other',
			'_kj_experience'       => sanitize_text_field( $r['experience'] ),
			'_kj_salary_min'       => max( 0, (float) $r['salary_min'] ),
			'_kj_salary_max'       => max( 0, (float) $r['salary_max'] ),
			'_kj_salary_currency'  => strtoupper( substr( sanitize_text_field( $r['salary_currency'] ), 0, 3 ) ),
			'_kj_salary_period'    => sanitize_key( $r['salary_period'] ),
			'_kj_salary_text'      => sanitize_text_field( $r['salary_text'] ),
			'_kj_benefits'         => $benefits,
			'_kj_deadline'         => $deadline,
			'_kj_requirements'     => array_map( 'sanitize_text_field', (array) $r['key_requirements'] ),
			'_kj_missing'          => array_map( 'sanitize_text_field', (array) $r['missing_info'] ),
			'_kj_risk'             => $risk,
			'_kj_risk_score'       => max( 0, min( 100, (int) $r['risk_score'] ) ),
			'_kj_risk_reasons'     => array_map( 'sanitize_text_field', (array) $r['risk_reasons'] ),
			'_kj_content_hash'     => $content_hash,
			'_kj_state'            => 'ready',
			'_kj_ai_model'         => isset( $r['_usage']['model'] ) ? sanitize_text_field( $r['_usage']['model'] ) : '',
			'_kj_ai_tokens'        => isset( $r['_usage'] ) ? (int) $r['_usage']['input_tokens'] + (int) $r['_usage']['output_tokens'] : 0,
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		delete_post_meta( $post_id, '_kj_error' );

		wp_set_object_terms( $post_id, array( $country ), 'kj_country' );
		wp_set_object_terms( $post_id, array( array_key_exists( $r['category'], kj_categories() ) ? $r['category'] : 'other' ), 'kj_category' );
		KJ_Cron::set_expiry( $post_id ); // Auto-published jobs: the deadline is only known now.

		return array(
			'id'      => $post_id,
			'state'   => $auto_publish ? 'published' : 'ready',
			'title'   => $title,
			'risk'    => $risk,
			'message' => kj_risk_label( $risk ) . ( $auto_publish ? ' — published automatically' : ' — waiting for your review' ),
		);
	}

	/**
	 * Admin form submit: queue the pasted jobs, then send the user back to watch processing.
	 */
	public static function handle_paste() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( 'You are not allowed to add jobs.' );
		}
		check_admin_referer( 'kj_queue_jobs' );

		$text = isset( $_POST['kj_paste'] ) ? wp_unslash( $_POST['kj_paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw job text, sanitised after Claude parses it.
		$text = wp_check_invalid_utf8( $text, true ); // Drop broken characters, keep the rest of the paste.
		$jobs = self::split_paste( $text );

		list( $queued, $dupes ) = self::queue( $jobs );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'kj-add-jobs',
					'queued' => $queued,
					'dupes'  => $dupes,
				),
				admin_url( 'edit.php?post_type=kj_job' )
			)
		);
		exit;
	}

	public static function ajax_process_next() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( 'Not allowed', 403 );
		}
		check_ajax_referer( 'kj_process', 'nonce' );

		$result = self::process_next();
		wp_send_json_success(
			array(
				'result'    => $result,
				'remaining' => self::queue_count(),
			)
		);
	}
}
