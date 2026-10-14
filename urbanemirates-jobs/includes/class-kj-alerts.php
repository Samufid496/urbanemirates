<?php
/**
 * Email job alerts: sign-up form with double opt-in, daily digest sent in small batches,
 * one-click unsubscribe, and a Subscribers admin page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Alerts {

	const BATCH = 40; // Emails per cron run; keeps shared-hosting mail limits happy.

	public static function init() {
		add_shortcode( 'kj_subscribe', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_requests' ) );
		add_action( 'admin_post_kj_delete_subscriber', array( __CLASS__, 'handle_delete' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'kj_subscribers';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(190) NOT NULL,
				countries varchar(255) NOT NULL DEFAULT '',
				categories varchar(255) NOT NULL DEFAULT '',
				token char(32) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				created_at datetime NOT NULL,
				confirmed_at datetime NULL,
				last_sent_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY status (status)
			) {$charset};"
		);
	}

	public static function count_confirmed() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', 'active' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ------------------------------------------------------------------ */
	/* Sign-up form                                                        */
	/* ------------------------------------------------------------------ */

	public static function shortcode() {
		$msg      = isset( $_GET['kj_msg'] ) ? sanitize_key( $_GET['kj_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$messages = array(
			'check'     => array( 'ok', '📧 Almost done! We sent you an email. Click the link inside to start your alerts. (Check your Spam folder too.)' ),
			'confirmed' => array( 'ok', '✅ Your job alerts are active. You will get new jobs every morning.' ),
			'unsub'     => array( 'ok', 'You have been unsubscribed. You will not get any more emails from us.' ),
			'invalid'   => array( 'err', 'Please enter a valid email address and accept the terms.' ),
			'slow'      => array( 'err', 'Too many attempts. Please try again in a few minutes.' ),
			'badlink'   => array( 'err', 'This link is not valid or has already been used.' ),
			'bot'       => array( 'err', 'Please complete the security check and try again.' ),
		);

		ob_start();
		echo '<div class="kj">';
		if ( isset( $messages[ $msg ] ) ) {
			echo '<p class="kj-notice kj-notice-' . esc_attr( $messages[ $msg ][0] ) . '">' . esc_html( $messages[ $msg ][1] ) . '</p>';
		}
		?>
		<form class="kj-subscribe" method="post" action="<?php echo esc_url( get_permalink() ); ?>">
			<input type="hidden" name="kj_subscribe" value="1">

			<p><label for="kj-email"><strong>Your email</strong></label>
			<input type="email" id="kj-email" name="kj_email" required placeholder="you@example.com" autocomplete="email"></p>

			<fieldset>
				<legend><strong>Countries</strong> <small>(leave empty for all)</small></legend>
				<div class="kj-checks">
				<?php foreach ( kj_countries() as $slug => $name ) : ?>
					<label><input type="checkbox" name="kj_countries[]" value="<?php echo esc_attr( $slug ); ?>"> <?php echo esc_html( $name ); ?></label>
				<?php endforeach; ?>
				</div>
			</fieldset>

			<fieldset>
				<legend><strong>Job types</strong> <small>(leave empty for all)</small></legend>
				<div class="kj-checks">
				<?php foreach ( kj_categories() as $slug => $name ) : ?>
					<label><input type="checkbox" name="kj_categories[]" value="<?php echo esc_attr( $slug ); ?>"> <?php echo esc_html( $name ); ?></label>
				<?php endforeach; ?>
				</div>
			</fieldset>

			<p><label><input type="checkbox" name="kj_consent" value="1" required> I agree to receive job alert emails. I can unsubscribe any time.</label></p>
			<?php echo KJ_Accounts::bot_fields(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p><button type="submit" class="kj-btn kj-btn-primary">Start my free alerts</button></p>
		</form>
		<?php
		$channel = kj_setting( 'whatsapp_channel' );
		if ( $channel ) {
			echo '<p class="kj-or">Prefer WhatsApp? <a class="kj-btn kj-btn-wa" href="' . esc_url( $channel ) . '" target="_blank" rel="noopener">Follow our WhatsApp Channel</a></p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Subscribe / confirm / unsubscribe                                   */
	/* ------------------------------------------------------------------ */

	public static function handle_requests() {
		// phpcs:disable WordPress.Security.NonceVerification -- public form protected by honeypot, rate limit and email confirmation; nonces break with page caching.
		if ( isset( $_GET['kj_confirm'] ) ) {
			self::confirm( sanitize_key( $_GET['kj_confirm'] ) );
		}
		if ( isset( $_GET['kj_unsub'] ) ) {
			self::unsubscribe( sanitize_key( $_GET['kj_unsub'] ) );
		}
		if ( isset( $_GET['kj_unsub_user'], $_GET['sig'] ) ) {
			self::unsubscribe_user( (int) $_GET['kj_unsub_user'], sanitize_text_field( wp_unslash( $_GET['sig'] ) ) );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['kj_subscribe'] ) ) {
			return;
		}

		$back = remove_query_arg( 'kj_msg', wp_get_referer() ? wp_get_referer() : self::alerts_url() );

		if ( ! empty( $_POST['kj_website'] ) ) {
			wp_safe_redirect( add_query_arg( 'kj_msg', 'check', $back ) ); // Bot: pretend success.
			exit;
		}
		if ( ! KJ_Accounts::bot_check_passes() ) {
			wp_safe_redirect( add_query_arg( 'kj_msg', 'bot', $back ) );
			exit;
		}

		$ip_key = 'kj_sub_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$tries  = (int) get_transient( $ip_key );
		if ( $tries >= 5 ) {
			wp_safe_redirect( add_query_arg( 'kj_msg', 'slow', $back ) );
			exit;
		}
		set_transient( $ip_key, $tries + 1, 15 * MINUTE_IN_SECONDS );

		$email = isset( $_POST['kj_email'] ) ? sanitize_email( wp_unslash( $_POST['kj_email'] ) ) : '';
		if ( ! is_email( $email ) || empty( $_POST['kj_consent'] ) ) {
			wp_safe_redirect( add_query_arg( 'kj_msg', 'invalid', $back ) );
			exit;
		}
		$countries  = isset( $_POST['kj_countries'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['kj_countries'] ) ) : array();
		$categories = isset( $_POST['kj_categories'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['kj_categories'] ) ) : array();
		// phpcs:enable

		$countries  = implode( ',', array_intersect( $countries, array_keys( kj_countries() ) ) );
		$categories = implode( ',', array_intersect( $categories, array_keys( kj_categories() ) ) );

		global $wpdb;
		$table    = self::table();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$token    = $existing ? $existing->token : wp_generate_password( 32, false, false );

		if ( $existing ) {
			// Update preferences; an active subscriber stays active, anyone else must confirm again.
			$wpdb->update(
				$table,
				array(
					'countries'  => $countries,
					'categories' => $categories,
					'status'     => 'active' === $existing->status ? 'active' : 'pending',
				),
				array( 'id' => $existing->id )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'email'      => $email,
					'countries'  => $countries,
					'categories' => $categories,
					'token'      => $token,
					'status'     => 'pending',
					'created_at' => current_time( 'mysql', true ),
				)
			);
		}

		if ( ! $existing || 'active' !== $existing->status ) {
			self::send_confirmation( $email, $token );
		}
		wp_safe_redirect( add_query_arg( 'kj_msg', $existing && 'active' === $existing->status ? 'confirmed' : 'check', $back ) );
		exit;
	}

	private static function alerts_url() {
		$url = get_permalink( (int) get_option( 'kj_page_alerts' ) );
		return $url ? $url : home_url( '/' );
	}

	private static function send_confirmation( $email, $token ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$link = add_query_arg( 'kj_confirm', $token, home_url( '/' ) );
		$body = '<p>Namaskar!</p>'
			. '<p>Please confirm you want daily Gulf &amp; abroad job alerts from ' . esc_html( $site ) . ':</p>'
			. '<p><a href="' . esc_url( $link ) . '" style="display:inline-block;background:#2357E8;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold">Yes, send me job alerts</a></p>'
			. '<p style="color:#666;font-size:13px">If you did not sign up, just ignore this email.</p>';
		self::mail( $email, 'Confirm your job alerts', $body, $token );
	}

	private static function confirm( $token ) {
		global $wpdb;
		$table = self::table();
		$sub   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $sub || strlen( $token ) !== 32 ) {
			wp_safe_redirect( add_query_arg( 'kj_msg', 'badlink', self::alerts_url() ) );
			exit;
		}
		if ( 'active' !== $sub->status ) {
			$wpdb->update(
				$table,
				array(
					'status'       => 'active',
					'confirmed_at' => current_time( 'mysql', true ),
					// Start from now so the first digest doesn't include old jobs twice.
					'last_sent_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $sub->id )
			);
		}
		wp_safe_redirect( add_query_arg( 'kj_msg', 'confirmed', self::alerts_url() ) );
		exit;
	}

	private static function unsubscribe( $token ) {
		global $wpdb;
		if ( strlen( $token ) === 32 ) {
			$wpdb->update( self::table(), array( 'status' => 'unsubscribed' ), array( 'token' => $token ) );
		}
		wp_safe_redirect( add_query_arg( 'kj_msg', 'unsub', self::alerts_url() ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Daily digest                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Called once a day: marks the start of a new digest round.
	 */
	public static function start_digest() {
		update_option( 'kj_digest_run', time(), false );
	}

	/**
	 * Called every 5 minutes: emails the next batch of subscribers who haven't had today's digest.
	 */
	public static function send_batch() {
		$run = (int) get_option( 'kj_digest_run' );
		if ( ! $run ) {
			return;
		}
		$done = self::send_subscriber_batch( $run );
		if ( ! $done ) {
			$done = self::send_account_batch( $run );
		}
		if ( ! $done ) {
			delete_option( 'kj_digest_run' ); // Round finished.
		}
	}

	/**
	 * Jobs published in the two days before the digest round started.
	 */
	private static function recent_jobs( $run ) {
		static $cache = array();
		if ( ! isset( $cache[ $run ] ) ) {
			$cache[ $run ] = get_posts(
				array(
					'post_type'      => 'kj_job',
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'date_query'     => array(
						array(
							'after'     => '2 days ago',
							'before'    => gmdate( 'Y-m-d H:i:s', $run ),
							'column'    => 'post_date_gmt',
							'inclusive' => true,
						),
					),
				)
			);
		}
		return $cache[ $run ];
	}

	/**
	 * Email-only subscribers (the Job Alerts form). Returns how many were processed.
	 */
	private static function send_subscriber_batch( $run ) {
		global $wpdb;
		$table     = self::table();
		$run_mysql = gmdate( 'Y-m-d H:i:s', $run );
		$subs      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'active' AND ( last_sent_at IS NULL OR last_sent_at < %s ) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$run_mysql,
				self::BATCH
			)
		);
		foreach ( $subs as $sub ) {
			$since   = $sub->last_sent_at ? strtotime( $sub->last_sent_at . ' UTC' ) : $run - DAY_IN_SECONDS;
			$matches = array();
			foreach ( self::recent_jobs( $run ) as $job ) {
				if ( strtotime( $job->post_date_gmt . ' UTC' ) <= $since ) {
					continue;
				}
				if ( $sub->countries && ! in_array( kj_term_slug( $job->ID, 'kj_country' ), explode( ',', $sub->countries ), true ) ) {
					continue;
				}
				if ( $sub->categories && ! in_array( kj_term_slug( $job->ID, 'kj_category' ), explode( ',', $sub->categories ), true ) ) {
					continue;
				}
				$matches[] = $job;
			}
			if ( $matches ) {
				self::send_digest( $sub->email, array_slice( $matches, 0, 25 ), add_query_arg( 'kj_unsub', $sub->token, home_url( '/' ) ), 'matching your alerts' );
			}
			$wpdb->update( $table, array( 'last_sent_at' => $run_mysql ), array( 'id' => $sub->id ) );
		}
		return count( $subs );
	}

	/**
	 * Signed-in job seekers who turned on "jobs that match my CV". Returns how many were processed.
	 */
	private static function send_account_batch( $run ) {
		if ( ! class_exists( 'KJ_CV' ) ) {
			return 0;
		}
		$users = get_users(
			array(
				'role'       => KJ_Accounts::ROLE,
				'number'     => self::BATCH,
				'fields'     => array( 'ID', 'user_email', 'user_registered' ),
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => 'kj_job_emails',
						'value' => '1',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'kj_last_digest',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => 'kj_last_digest',
							'value'   => $run,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);
		foreach ( $users as $user ) {
			$last  = (int) get_user_meta( $user->ID, 'kj_last_digest', true );
			$since = max( $last ? $last : $run - DAY_IN_SECONDS, strtotime( $user->user_registered . ' UTC' ) );
			$cv    = KJ_CV::latest_for_user( $user->ID );
			if ( $cv ) {
				$matches = array();
				foreach ( self::recent_jobs( $run ) as $job ) {
					if ( strtotime( $job->post_date_gmt . ' UTC' ) > $since && self::matches_cv( $job, $cv->cv ) ) {
						$matches[] = $job;
					}
				}
				if ( $matches ) {
					self::send_digest( $user->user_email, array_slice( $matches, 0, 25 ), self::account_unsub_url( $user->ID ), 'that match your CV' );
				}
			}
			update_user_meta( $user->ID, 'kj_last_digest', $run );
		}
		return count( $users );
	}

	/**
	 * Does a job fit what the CV says the person wants? Country must match (if chosen), then
	 * the job type or a word from the target job title.
	 */
	public static function matches_cv( $job, array $cv ) {
		$countries = $cv['target']['countries'];
		if ( $countries && ! in_array( kj_term_slug( $job->ID, 'kj_country' ), $countries, true ) ) {
			return false;
		}
		$category = $cv['target']['category'];
		$words    = array();
		$skip     = array( 'and', 'the', 'for', 'senior', 'junior', 'staff', 'assistant', 'manager', 'officer', 'executive', 'head', 'lead' );
		foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $cv['target']['title'] ) ) as $w ) {
			if ( strlen( $w ) >= 3 && ! in_array( $w, $skip, true ) ) {
				$words[] = $w;
			}
		}
		if ( ! $category && ! $words ) {
			return true;
		}
		if ( $category && kj_term_slug( $job->ID, 'kj_category' ) === $category ) {
			return true;
		}
		$title = strtolower( get_the_title( $job ) );
		foreach ( $words as $w ) {
			if ( false !== strpos( $title, $w ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recent jobs for a signed-in person's newest CV ("Jobs for you").
	 */
	public static function jobs_for_user( $user_id, $limit = 6 ) {
		if ( ! class_exists( 'KJ_CV' ) ) {
			return array();
		}
		$cv = KJ_CV::latest_for_user( $user_id );
		if ( ! $cv || ( ! $cv->cv['target']['title'] && ! $cv->cv['target']['category'] ) ) {
			return array();
		}
		$jobs = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'date_query'     => array( array( 'after' => '21 days ago' ) ),
			)
		);
		$out = array();
		foreach ( $jobs as $job ) {
			if ( self::matches_cv( $job, $cv->cv ) ) {
				$out[] = $job;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	public static function account_unsub_url( $user_id ) {
		return add_query_arg(
			array(
				'kj_unsub_user' => (int) $user_id,
				'sig'           => substr( wp_hash( 'unsub|' . (int) $user_id ), 0, 20 ),
			),
			home_url( '/' )
		);
	}

	private static function unsubscribe_user( $user_id, $sig ) {
		if ( hash_equals( substr( wp_hash( 'unsub|' . (int) $user_id ), 0, 20 ), (string) $sig ) ) {
			update_user_meta( (int) $user_id, 'kj_job_emails', 0 );
			update_user_meta( (int) $user_id, 'kj_job_emails_changed_at', time() );
		}
		wp_safe_redirect( add_query_arg( 'kj_msg', 'unsub', self::alerts_url() ) );
		exit;
	}

	private static function send_digest( $email, array $jobs, $unsub_url, $why ) {
		$flags = kj_country_flags();
		$rows  = '';
		foreach ( $jobs as $job ) {
			$slug    = kj_term_slug( $job->ID, 'kj_country' );
			$company = get_post_meta( $job->ID, '_kj_company', true );
			$salary  = kj_format_salary( $job->ID );
			$place   = trim( get_post_meta( $job->ID, '_kj_city', true ) . ', ' . kj_term_name( $job->ID, 'kj_country' ), ', ' );
			$rows   .= '<tr><td style="padding:14px 0;border-bottom:1px solid #e5e5e5">'
				. '<a href="' . esc_url( get_permalink( $job ) ) . '" style="font-size:16px;font-weight:bold;color:#2357E8;text-decoration:none">' . esc_html( get_the_title( $job ) ) . '</a><br>'
				. '<span style="color:#444;font-size:14px">' . esc_html( ( isset( $flags[ $slug ] ) ? $flags[ $slug ] . ' ' : '' ) . $place . ( $company ? ' · ' . $company : '' ) ) . '</span>'
				. ( $salary ? '<br><span style="color:#444;font-size:14px">💰 ' . esc_html( $salary ) . '</span>' : '' )
				. '</td></tr>';
		}
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$count = count( $jobs );
		$body  = '<p>Namaskar! Here ' . ( 1 === $count ? 'is 1 new job' : 'are ' . $count . ' new jobs' ) . ' ' . esc_html( $why ) . ':</p>'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">' . $rows . '</table>'
			. '<p style="margin-top:20px"><a href="' . esc_url( get_permalink( (int) get_option( 'kj_page_jobs' ) ) ) . '" style="color:#2357E8">See all jobs on ' . esc_html( $site ) . ' →</a></p>'
			. '<p style="background:#fff7e6;padding:10px;border-radius:6px;font-size:13px">⚠️ Never pay any fee to get a job. Genuine employers do not charge for visa, registration or training.</p>';
		kj_send_email( $email, $count . ' new Gulf job' . ( 1 === $count ? '' : 's' ) . ' for you — ' . wp_date( 'j M' ), $body, $unsub_url );
	}

	private static function mail( $to, $subject, $inner_html, $token ) {
		return kj_send_email( $to, $subject, $inner_html, add_query_arg( 'kj_unsub', $token, home_url( '/' ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin: Subscribers                                                  */
	/* ------------------------------------------------------------------ */

	public static function page_subscribers() {
		global $wpdb;
		$table = self::table();
		$subs  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cats  = kj_categories();
		$ctrs  = kj_countries();
		?>
		<div class="wrap kj-wrap">
			<h1>Alert Subscribers</h1>
			<p><strong><?php echo (int) self::count_confirmed(); ?></strong> active subscribers. The daily email goes out at <?php echo (int) kj_setting( 'digest_hour' ); ?>:00, in batches of <?php echo (int) self::BATCH; ?> every 5 minutes.</p>
			<table class="widefat striped">
				<thead><tr><th>Email</th><th>Status</th><th>Countries</th><th>Job types</th><th>Joined</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $subs ) : ?>
					<tr><td colspan="6">No subscribers yet. Share your Job Alerts page!</td></tr>
				<?php endif; ?>
				<?php foreach ( $subs as $s ) : ?>
					<tr>
						<td><?php echo esc_html( $s->email ); ?></td>
						<td><?php echo esc_html( ucfirst( $s->status ) ); ?></td>
						<td><?php echo esc_html( $s->countries ? implode( ', ', array_intersect_key( $ctrs, array_flip( explode( ',', $s->countries ) ) ) ) : 'All' ); ?></td>
						<td><?php echo esc_html( $s->categories ? implode( ', ', array_intersect_key( $cats, array_flip( explode( ',', $s->categories ) ) ) ) : 'All' ); ?></td>
						<td><?php echo esc_html( mysql2date( 'j M Y', $s->created_at ) ); ?></td>
						<td><a class="button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kj_delete_subscriber&id=' . (int) $s->id ), 'kj_delete_sub_' . (int) $s->id ) ); ?>" onclick="return confirm('Delete this subscriber permanently?');">Delete</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function handle_delete() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'kj_delete_sub_' . $id );
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ) );
		wp_safe_redirect( admin_url( 'edit.php?post_type=kj_job&page=kj-subscribers' ) );
		exit;
	}
}
