<?php
/**
 * Background jobs (WP-Cron):
 *   every 5 min : process one queued job, send a batch of digest emails
 *   daily @ hour: start the email digest round
 *   daily       : hide expired jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Cron {

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'kj_every_five', array( __CLASS__, 'every_five' ) );
		add_action( 'kj_daily_digest', array( 'KJ_Alerts', 'start_digest' ) );
		add_action( 'kj_daily_cleanup', array( __CLASS__, 'expire_jobs' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_change' ), 10, 3 );
	}

	public static function schedules( $schedules ) {
		$schedules['kj_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every 5 minutes',
		);
		return $schedules;
	}

	public static function schedule() {
		// cron_schedules may not be filtered yet during activation.
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		if ( ! wp_next_scheduled( 'kj_every_five' ) ) {
			wp_schedule_event( time() + 60, 'kj_five_minutes', 'kj_every_five' );
		}
		if ( ! wp_next_scheduled( 'kj_daily_cleanup' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', 'kj_daily_cleanup' );
		}
		if ( ! wp_next_scheduled( 'kj_daily_digest' ) ) {
			wp_schedule_event( self::next_digest_time(), 'daily', 'kj_daily_digest' );
		}
	}

	public static function unschedule() {
		foreach ( array( 'kj_every_five', 'kj_daily_cleanup', 'kj_daily_digest' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public static function reschedule_digest() {
		wp_clear_scheduled_hook( 'kj_daily_digest' );
		wp_schedule_event( self::next_digest_time(), 'daily', 'kj_daily_digest' );
	}

	/**
	 * Next occurrence of the digest hour in the site's timezone, as a UTC timestamp.
	 */
	private static function next_digest_time() {
		$tz   = wp_timezone();
		$now  = new DateTimeImmutable( 'now', $tz );
		$next = $now->setTime( (int) kj_setting( 'digest_hour' ), 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	public static function every_five() {
		self::reset_stale();
		KJ_Importer::process_next();
		KJ_Alerts::send_batch();
	}

	/**
	 * A job stuck in "processing" for 10+ minutes (e.g. the request timed out) goes back to the queue.
	 */
	private static function reset_stale() {
		$ids = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'draft',
				'meta_key'       => '_kj_state',
				'meta_value'     => 'processing',
				'fields'         => 'ids',
				'posts_per_page' => 20,
			)
		);
		foreach ( $ids as $id ) {
			if ( (int) get_post_meta( $id, '_kj_claimed_at', true ) < time() - 10 * MINUTE_IN_SECONDS ) {
				update_post_meta( $id, '_kj_state', 'queued' );
			}
		}
	}

	public static function on_status_change( $new, $old, $post ) {
		if ( 'kj_job' === $post->post_type && 'publish' === $new && 'publish' !== $old ) {
			self::set_expiry( $post->ID );
		}
	}

	/**
	 * Expiry = the earlier of (publish date + N days) and the application deadline.
	 */
	public static function set_expiry( $post_id ) {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		$published = get_post_time( 'U', true, $post_id );
		$expires   = wp_date( 'Y-m-d', $published + (int) kj_setting( 'expiry_days' ) * DAY_IN_SECONDS );
		$deadline  = get_post_meta( $post_id, '_kj_deadline', true );
		if ( $deadline && $deadline < $expires ) {
			$expires = $deadline;
		}
		update_post_meta( $post_id, '_kj_expires', $expires );
	}

	/**
	 * Unpublish jobs whose expiry date has passed. They stay in the admin as drafts.
	 */
	public static function expire_jobs() {
		$ids = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 500,
				'meta_query'     => array(
					array(
						'key'     => '_kj_expires',
						'value'   => wp_date( 'Y-m-d' ),
						'compare' => '<',
						'type'    => 'DATE',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
			update_post_meta( $id, '_kj_state', 'expired' );
		}
	}
}
