<?php
/**
 * Job seeker accounts: sign in with a 6-digit email code (no passwords), My account page,
 * saved jobs, job email preference, download my data, delete my account.
 *
 * Job seekers get the "ue_seeker" role, never see wp-admin, and are the only users who can
 * sign in by email code. Staff (admins/editors) keep signing in with a password at wp-login.php.
 *
 * Shortcodes: [kj_signin], [kj_account]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Accounts {

	const ROLE          = 'ue_seeker';
	const CODE_TTL      = 600; // Seconds a code stays valid.
	const CODE_ATTEMPTS = 5;

	public static function init() {
		add_shortcode( 'kj_signin', array( __CLASS__, 'shortcode_signin' ) );
		add_shortcode( 'kj_account', array( __CLASS__, 'shortcode_account' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_requests' ) );
		add_action( 'admin_init', array( __CLASS__, 'block_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'kj_job_secondary_action', array( __CLASS__, 'save_button' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'turnstile_script' ) );
	}

	public static function add_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Job seeker', array( 'read' => true ) );
		}
	}

	public static function create_pages() {
		$pages = array(
			'kj_page_signin'  => array( 'Sign in', '[kj_signin]' ),
			'kj_page_account' => array( 'My account', '[kj_account]' ),
		);
		foreach ( $pages as $option => $page ) {
			$existing = (int) get_option( $option );
			if ( $existing && get_post( $existing ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page[0],
					'post_content' => $page[1],
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( $option, $id );
			}
		}
	}

	public static function signin_url( $redirect = '' ) {
		$url = get_permalink( (int) get_option( 'kj_page_signin' ) );
		$url = $url ? $url : wp_login_url();
		return $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url ) : $url;
	}

	public static function account_url() {
		$url = get_permalink( (int) get_option( 'kj_page_account' ) );
		return $url ? $url : home_url( '/' );
	}

	public static function is_seeker( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && $user->exists() && in_array( self::ROLE, (array) $user->roles, true );
	}

	/* ------------------------------------------------------------------ */
	/* Bot protection: Cloudflare Turnstile when keys are set, else honeypot */
	/* ------------------------------------------------------------------ */

	public static function turnstile_enabled() {
		return (string) kj_setting( 'turnstile_site' ) !== '' && (string) kj_setting( 'turnstile_secret' ) !== '';
	}

	public static function turnstile_script() {
		if ( ! self::turnstile_enabled() ) {
			return;
		}
		$pages = array( (int) get_option( 'kj_page_signin' ), (int) get_option( 'kj_page_alerts' ) );
		if ( is_page( $pages ) ) {
			wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare serves the current version.
		}
	}

	/**
	 * The bot check markup for a form: Turnstile widget, plus a hidden honeypot field.
	 */
	public static function bot_fields() {
		$out = '<p class="kj-hp" aria-hidden="true"><label>Leave empty <input type="text" name="kj_website" tabindex="-1" autocomplete="off"></label></p>';
		if ( self::turnstile_enabled() ) {
			$out .= '<div class="cf-turnstile" data-sitekey="' . esc_attr( kj_setting( 'turnstile_site' ) ) . '" data-theme="light" data-size="flexible"></div>';
		}
		return $out;
	}

	/**
	 * True when the submitted form passes the bot checks.
	 */
	public static function bot_check_passes() {
		// phpcs:disable WordPress.Security.NonceVerification -- called from handlers that verify their own nonces.
		if ( ! empty( $_POST['kj_website'] ) ) {
			return false;
		}
		if ( ! self::turnstile_enabled() ) {
			return true;
		}
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		// phpcs:enable
		if ( '' === $token ) {
			return false;
		}
		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 15,
				'body'    => array(
					'secret'   => kj_setting( 'turnstile_secret' ),
					'response' => $token,
					'remoteip' => kj_client_ip(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return true; // Cloudflare unreachable: don't lock real people out; rate limits still apply.
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $data['success'] );
	}

	/* ------------------------------------------------------------------ */
	/* Request handling                                                    */
	/* ------------------------------------------------------------------ */

	public static function handle_requests() {
		// phpcs:disable WordPress.Security.NonceVerification -- each branch verifies its nonce.
		if ( isset( $_GET['kj_export'] ) ) {
			self::export_data();
		}
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['kj_auth'] ) ) {
			return;
		}
		$action = sanitize_key( $_POST['kj_auth'] );
		// phpcs:enable
		switch ( $action ) {
			case 'send_code':
				self::send_code();
				break;
			case 'verify_code':
				self::verify_code();
				break;
			case 'resend':
				self::resend_code();
				break;
			case 'save_job':
				self::toggle_saved_job();
				break;
			case 'job_emails':
				self::update_job_emails();
				break;
			case 'delete_account':
				self::delete_account();
				break;
		}
	}

	private static function back_to_signin( $args ) {
		wp_safe_redirect( add_query_arg( $args, self::signin_url() ) );
		exit;
	}

	private static function redirect_target() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$to = $to ? wp_validate_redirect( $to, '' ) : '';
		return $to ? $to : self::account_url();
	}

	private static function send_code() {
		check_admin_referer( 'kj_send_code' );
		$keep = array( 'redirect_to' => rawurlencode( self::redirect_target() ) );

		if ( ! self::bot_check_passes() ) {
			self::back_to_signin( $keep + array( 'kj_err' => 'bot' ) );
		}
		$email = isset( $_POST['kj_email'] ) ? sanitize_email( wp_unslash( $_POST['kj_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			self::back_to_signin( $keep + array( 'kj_err' => 'email' ) );
		}
		if ( empty( $_POST['kj_terms'] ) ) {
			self::back_to_signin( $keep + array( 'kj_err' => 'terms' ) );
		}
		if ( ! kj_rate_limit( 'code_ip_' . kj_client_ip(), 10, HOUR_IN_SECONDS ) || ! kj_rate_limit( 'code_email_' . strtolower( $email ), 5, HOUR_IN_SECONDS ) ) {
			self::back_to_signin( $keep + array( 'kj_err' => 'slow' ) );
		}

		$user = get_user_by( 'email', $email );
		if ( $user && ! self::is_seeker( $user ) ) {
			// Staff accounts must use their password; never allow code sign-in for them.
			self::back_to_signin( $keep + array( 'kj_err' => 'staff' ) );
		}

		$token = wp_generate_password( 32, false, false );
		self::store_code(
			$token,
			array(
				'email'      => $email,
				'job_emails' => ! empty( $_POST['kj_job_emails'] ),
				'redirect'   => self::redirect_target(),
			)
		);
		wp_safe_redirect( add_query_arg( array( 't' => $token ), self::signin_url() ) );
		exit;
	}

	/**
	 * Create a fresh code for this sign-in attempt and email it.
	 */
	private static function store_code( $token, array $data ) {
		$code              = (string) wp_rand( 100000, 999999 );
		$data['hash']      = wp_hash( $code . '|' . $token );
		$data['attempts']  = 0;
		$data['issued_at'] = time();
		set_transient( 'kj_code_' . $token, $data, self::CODE_TTL );

		$body = '<p style="font-size:16px">Your Urban Emirates sign-in code is:</p>'
			. '<p style="font-size:34px;font-weight:900;letter-spacing:8px;margin:8px 0 18px">' . esc_html( $code ) . '</p>'
			. '<p style="color:#5E6577">It works for 10 minutes. If you did not ask for this, you can ignore this email — nobody can sign in without the code.</p>';
		kj_send_email( $data['email'], $code . ' is your Urban Emirates code', $body );
	}

	private static function resend_code() {
		check_admin_referer( 'kj_resend' );
		$token = isset( $_POST['t'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_POST['t'] ) ) : '';
		$data  = get_transient( 'kj_code_' . $token );
		if ( ! $data ) {
			self::back_to_signin( array( 'kj_err' => 'expired' ) );
		}
		if ( time() - (int) $data['issued_at'] < 30 || ! kj_rate_limit( 'code_email_' . strtolower( $data['email'] ), 5, HOUR_IN_SECONDS ) ) {
			self::back_to_signin( array( 't' => $token, 'kj_err' => 'wait' ) );
		}
		self::store_code( $token, $data );
		self::back_to_signin( array( 't' => $token, 'kj_msg' => 'resent' ) );
	}

	private static function verify_code() {
		check_admin_referer( 'kj_verify_code' );
		$token = isset( $_POST['t'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_POST['t'] ) ) : '';
		$code  = isset( $_POST['kj_code'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['kj_code'] ) ) : '';
		$data  = get_transient( 'kj_code_' . $token );

		if ( ! $data ) {
			self::back_to_signin( array( 'kj_err' => 'expired' ) );
		}
		if ( $data['attempts'] >= self::CODE_ATTEMPTS ) {
			delete_transient( 'kj_code_' . $token );
			self::back_to_signin( array( 'kj_err' => 'locked' ) );
		}
		if ( ! hash_equals( $data['hash'], wp_hash( $code . '|' . $token ) ) ) {
			$data['attempts']++;
			set_transient( 'kj_code_' . $token, $data, max( 60, self::CODE_TTL - ( time() - (int) $data['issued_at'] ) ) );
			self::back_to_signin( array( 't' => $token, 'kj_err' => 'code' ) );
		}
		delete_transient( 'kj_code_' . $token );

		$user = get_user_by( 'email', $data['email'] );
		if ( ! $user ) {
			$user = self::create_user( $data['email'], ! empty( $data['job_emails'] ) );
			if ( is_wp_error( $user ) ) {
				self::back_to_signin( array( 'kj_err' => 'create' ) );
			}
		} elseif ( ! self::is_seeker( $user ) ) {
			self::back_to_signin( array( 'kj_err' => 'staff' ) );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		update_user_meta( $user->ID, 'kj_last_signin', time() );
		$to = $data['redirect'] ? wp_validate_redirect( $data['redirect'], '' ) : '';
		wp_safe_redirect( $to ? $to : self::account_url() );
		exit;
	}

	private static function create_user( $email, $job_emails ) {
		$base  = sanitize_user( strtok( $email, '@' ), true );
		$base  = $base ? $base : 'seeker';
		$login = $base;
		$n     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ( ++$n );
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ), // Never used: sign-in is by email code.
				'role'         => self::ROLE,
				'display_name' => ucwords( trim( preg_replace( '/[._\-]+|\d+/', ' ', strtok( $email, '@' ) ) ) ) ?: 'Friend',
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_user_meta( $id, 'kj_terms_accepted_at', time() );
		update_user_meta( $id, 'kj_job_emails', $job_emails ? 1 : 0 );
		update_user_meta( $id, 'kj_job_emails_changed_at', time() );
		return get_user_by( 'id', $id );
	}

	/* ------------------------------------------------------------------ */
	/* Sign-in page                                                        */
	/* ------------------------------------------------------------------ */

	private static function mask_email( $email ) {
		list( $local, $domain ) = array_pad( explode( '@', $email, 2 ), 2, '' );
		return substr( $local, 0, 2 ) . str_repeat( '•', max( 2, min( 6, strlen( $local ) - 2 ) ) ) . '@' . $domain;
	}

	public static function shortcode_signin() {
		// phpcs:disable WordPress.Security.NonceVerification -- display only.
		$err      = isset( $_GET['kj_err'] ) ? sanitize_key( $_GET['kj_err'] ) : '';
		$msg      = isset( $_GET['kj_msg'] ) ? sanitize_key( $_GET['kj_msg'] ) : '';
		$token    = isset( $_GET['t'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['t'] ) ) : '';
		$redirect = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), self::account_url() ) : self::account_url();
		// phpcs:enable

		if ( is_user_logged_in() && ! $token ) {
			return '<div class="kj kj-auth"><p>You are signed in.</p><p><a class="kj-btn kj-btn-primary" href="' . esc_url( self::account_url() ) . '">Go to My account</a></p></div>';
		}

		$errors = array(
			'bot'     => 'Please complete the security check and try again.',
			'email'   => 'Please enter a valid email address.',
			'terms'   => 'Please accept the Terms and Privacy Policy to continue.',
			'slow'    => 'Too many codes requested. Please wait an hour and try again.',
			'staff'   => 'This email belongs to a staff account. Please sign in with your password at the admin login.',
			'expired' => 'That code has expired. Please ask for a new one.',
			'locked'  => 'Too many wrong codes. Please ask for a new one.',
			'code'    => 'That code is not right. Check the email and try again.',
			'wait'    => 'Please wait a few seconds before asking for another code.',
			'create'  => 'Something went wrong creating your account. Please try again.',
		);
		$messages = array(
			'resent'  => 'We sent you a new code.',
			'deleted' => 'Your account and all your data have been deleted.',
		);

		$data = $token ? get_transient( 'kj_code_' . $token ) : false;

		ob_start();
		echo '<div class="kj kj-auth">';
		if ( isset( $errors[ $err ] ) ) {
			echo '<p class="kj-notice kj-notice-err" role="alert">' . esc_html( $errors[ $err ] ) . '</p>';
		}
		if ( isset( $messages[ $msg ] ) ) {
			echo '<p class="kj-notice kj-notice-ok" role="status">' . esc_html( $messages[ $msg ] ) . '</p>';
		}

		if ( $data ) :
			?>
			<h1 class="kj-auth-title">Check your email</h1>
			<p class="kj-auth-sub">We sent a 6-digit code to <strong><?php echo esc_html( self::mask_email( $data['email'] ) ); ?></strong>. It works for 10 minutes.</p>
			<form class="kj-auth-form" method="post" action="<?php echo esc_url( self::signin_url() ); ?>">
				<input type="hidden" name="kj_auth" value="verify_code">
				<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( 'kj_verify_code' ); ?>
				<label class="kj-input-label">6-digit code
					<input class="kj-input kj-input-code" type="text" name="kj_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
				</label>
				<button type="submit" class="kj-btn kj-btn-primary">Sign in</button>
			</form>
			<form class="kj-auth-links" method="post" action="<?php echo esc_url( self::signin_url() ); ?>">
				<input type="hidden" name="kj_auth" value="resend">
				<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( 'kj_resend' ); ?>
				<button type="submit" class="kj-link-btn">Send a new code</button>
				<span aria-hidden="true">·</span>
				<a href="<?php echo esc_url( self::signin_url() ); ?>">Use a different email</a>
			</form>
			<?php
		else :
			?>
			<h1 class="kj-auth-title">Build your Gulf CV. Free.</h1>
			<p class="kj-auth-sub">Sign in with your email. We'll send you a 6-digit code — no password needed.</p>
			<form class="kj-auth-form" method="post" action="<?php echo esc_url( self::signin_url() ); ?>">
				<input type="hidden" name="kj_auth" value="send_code">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
				<?php wp_nonce_field( 'kj_send_code' ); ?>
				<label class="kj-input-label">Email
					<input class="kj-input" type="email" name="kj_email" autocomplete="email" required>
				</label>
				<label class="kj-consent">
					<input type="checkbox" name="kj_terms" value="1" required>
					<span>I agree to the <a href="<?php echo esc_url( KJ_Legal::terms_url() ); ?>" target="_blank">Terms</a> and <a href="<?php echo esc_url( KJ_Legal::privacy_url() ); ?>" target="_blank">Privacy Policy</a>.</span>
				</label>
				<label class="kj-consent">
					<input type="checkbox" name="kj_job_emails" value="1">
					<span>Email me jobs that match my CV. <span class="kj-muted">(Optional — stop any time.)</span></span>
				</label>
				<?php echo self::bot_fields(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<button type="submit" class="kj-btn kj-btn-primary">Send me a code</button>
			</form>
			<ul class="kj-auth-points">
				<li><?php echo kj_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Download your CV as PDF or Word</li>
				<li><?php echo kj_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>AI helps you write every section</li>
				<li><?php echo kj_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Your data is yours — delete it any time</li>
			</ul>
			<?php
		endif;
		echo '</div>';
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* My account                                                          */
	/* ------------------------------------------------------------------ */

	public static function shortcode_account() {
		if ( ! is_user_logged_in() ) {
			return '<div class="kj kj-auth"><p>Please sign in to see your account.</p><p><a class="kj-btn kj-btn-primary" href="' . esc_url( self::signin_url( self::account_url() ) ) . '">Sign in</a></p></div>';
		}
		$user  = wp_get_current_user();
		$saved = self::saved_jobs( $user->ID );
		// phpcs:ignore WordPress.Security.NonceVerification
		$msg = isset( $_GET['kj_msg'] ) ? sanitize_key( $_GET['kj_msg'] ) : '';

		ob_start();
		?>
		<div class="kj kj-account">
			<?php if ( 'confirm' === $msg ) : ?>
				<p class="kj-notice kj-notice-err" role="alert">Your account was not deleted. Type DELETE in capital letters to confirm.</p>
			<?php endif; ?>
			<?php if ( 'emails_on' === $msg || 'emails_off' === $msg ) : ?>
				<p class="kj-notice kj-notice-ok" role="status"><?php echo esc_html( 'emails_on' === $msg ? 'Job emails are on.' : 'Job emails are off.' ); ?></p>
			<?php endif; ?>
			<header class="kj-account-head">
				<div>
					<h1 class="kj-account-hi">Hi, <?php echo esc_html( $user->display_name ); ?></h1>
					<p class="kj-muted"><?php echo esc_html( $user->user_email ); ?></p>
				</div>
				<a class="kj-btn kj-btn-outline kj-btn-sm" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a>
			</header>

			<section class="kj-account-section">
				<div class="kj-account-section-head">
					<h3 class="kj-label">My CVs</h3>
					<?php echo apply_filters( 'kj_account_new_cv_link', '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
				<?php
				$cvs = apply_filters( 'kj_account_cvs_html', '', $user->ID );
				echo $cvs ? $cvs : '<p class="kj-account-empty">The free CV builder is coming soon.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</section>

			<section class="kj-account-section">
				<h3 class="kj-label">Job emails</h3>
				<form class="kj-account-row" method="post" action="<?php echo esc_url( self::account_url() ); ?>">
					<input type="hidden" name="kj_auth" value="job_emails">
					<?php wp_nonce_field( 'kj_job_emails' ); ?>
					<span class="kj-account-row-text">
						<strong>Daily jobs that match my CV</strong>
						<span class="kj-muted"><?php echo esc_html( get_user_meta( $user->ID, 'kj_job_emails', true ) ? 'On · every morning' : 'Off' ); ?></span>
					</span>
					<?php if ( get_user_meta( $user->ID, 'kj_job_emails', true ) ) : ?>
						<button class="kj-btn kj-btn-outline kj-btn-sm" type="submit" name="kj_on" value="0">Turn off</button>
					<?php else : ?>
						<button class="kj-btn kj-btn-primary kj-btn-sm" type="submit" name="kj_on" value="1">Turn on</button>
					<?php endif; ?>
				</form>
			</section>

			<?php $for_you = KJ_Alerts::jobs_for_user( $user->ID, 4 ); ?>
			<?php if ( $for_you ) : ?>
				<section class="kj-account-section">
					<h3 class="kj-label">Jobs for you</h3>
					<ul class="kj-account-list">
						<?php foreach ( $for_you as $job ) : ?>
							<li><a href="<?php echo esc_url( get_permalink( $job ) ); ?>"><strong><?php echo esc_html( get_the_title( $job ) ); ?></strong><span class="kj-muted"><?php echo esc_html( trim( get_post_meta( $job->ID, '_kj_company', true ) . ' · ' . get_post_meta( $job->ID, '_kj_city', true ) . ' · ' . kj_time_ago( $job->ID ), ' ·' ) ); ?></span></a></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<section class="kj-account-section">
				<h3 class="kj-label">Saved jobs</h3>
				<?php if ( ! $saved ) : ?>
					<p class="kj-account-empty">Tap the bookmark on any job to save it here.</p>
				<?php else : ?>
					<ul class="kj-account-list">
						<?php foreach ( $saved as $job ) : ?>
							<li><a href="<?php echo esc_url( get_permalink( $job ) ); ?>"><strong><?php echo esc_html( get_the_title( $job ) ); ?></strong><span class="kj-muted"><?php echo esc_html( trim( get_post_meta( $job->ID, '_kj_company', true ) . ' · ' . get_post_meta( $job->ID, '_kj_city', true ), ' ·' ) ); ?></span></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<section class="kj-account-section">
				<h3 class="kj-label">Your data</h3>
				<ul class="kj-account-list">
					<li><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'kj_export', 1, self::account_url() ), 'kj_export' ) ); ?>"><strong>Download my data</strong></a></li>
				</ul>
				<details class="kj-delete">
					<summary>Delete my account</summary>
					<form method="post" action="<?php echo esc_url( self::account_url() ); ?>">
						<input type="hidden" name="kj_auth" value="delete_account">
						<?php wp_nonce_field( 'kj_delete_account' ); ?>
						<p>This permanently deletes your account, CVs, saved jobs and email preferences. It cannot be undone.</p>
						<label class="kj-input-label">Type DELETE to confirm
							<input class="kj-input" type="text" name="kj_confirm" autocomplete="off" required>
						</label>
						<button type="submit" class="kj-btn kj-btn-danger">Delete my account</button>
					</form>
				</details>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function require_login() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::signin_url() );
			exit;
		}
	}

	private static function update_job_emails() {
		self::require_login();
		check_admin_referer( 'kj_job_emails' );
		$on = ! empty( $_POST['kj_on'] );
		update_user_meta( get_current_user_id(), 'kj_job_emails', $on ? 1 : 0 );
		update_user_meta( get_current_user_id(), 'kj_job_emails_changed_at', time() );
		wp_safe_redirect( add_query_arg( 'kj_msg', $on ? 'emails_on' : 'emails_off', self::account_url() ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Saved jobs                                                          */
	/* ------------------------------------------------------------------ */

	public static function saved_ids( $user_id ) {
		return array_map( 'intval', array_filter( (array) get_user_meta( $user_id, 'kj_saved_jobs', true ) ) );
	}

	private static function saved_jobs( $user_id ) {
		$ids = self::saved_ids( $user_id );
		if ( ! $ids ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'orderby'        => 'post__in',
				'posts_per_page' => 100,
			)
		);
	}

	/**
	 * The bookmark button beside Apply on a job page.
	 */
	public static function save_button( $html, $job_id ) {
		if ( ! is_user_logged_in() ) {
			return '<a class="kj-icon-btn kj-save" href="' . esc_url( self::signin_url( get_permalink( $job_id ) ) ) . '" aria-label="Sign in to save this job">' . kj_icon( 'bookmark' ) . '</a>';
		}
		$saved = in_array( (int) $job_id, self::saved_ids( get_current_user_id() ), true );
		return '<form method="post" action="' . esc_url( get_permalink( $job_id ) ) . '" class="kj-save-form">'
			. '<input type="hidden" name="kj_auth" value="save_job"><input type="hidden" name="job" value="' . (int) $job_id . '">'
			. wp_nonce_field( 'kj_save_job', '_wpnonce', true, false )
			. '<button type="submit" class="kj-icon-btn kj-save' . ( $saved ? ' is-saved' : '' ) . '" aria-pressed="' . ( $saved ? 'true' : 'false' ) . '" aria-label="' . ( $saved ? 'Remove from saved jobs' : 'Save this job' ) . '">' . kj_icon( 'bookmark' ) . '</button></form>';
	}

	private static function toggle_saved_job() {
		self::require_login();
		check_admin_referer( 'kj_save_job' );
		$job = isset( $_POST['job'] ) ? (int) $_POST['job'] : 0;
		if ( 'kj_job' !== get_post_type( $job ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$ids = self::saved_ids( get_current_user_id() );
		$ids = in_array( $job, $ids, true ) ? array_values( array_diff( $ids, array( $job ) ) ) : array_merge( array( $job ), $ids );
		update_user_meta( get_current_user_id(), 'kj_saved_jobs', array_slice( $ids, 0, 200 ) );
		wp_safe_redirect( get_permalink( $job ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Download my data / Delete my account                                */
	/* ------------------------------------------------------------------ */

	private static function export_data() {
		self::require_login();
		check_admin_referer( 'kj_export' );
		$user = wp_get_current_user();

		global $wpdb;
		$alerts = $wpdb->get_row( $wpdb->prepare( 'SELECT email, countries, categories, status, created_at, confirmed_at FROM ' . KJ_Alerts::table() . ' WHERE email = %s', $user->user_email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$data = array(
			'exported_at' => gmdate( 'c' ),
			'website'     => home_url( '/' ),
			'account'     => array(
				'email'        => $user->user_email,
				'name'         => $user->display_name,
				'created'      => $user->user_registered,
				'terms_agreed' => gmdate( 'c', (int) get_user_meta( $user->ID, 'kj_terms_accepted_at', true ) ),
				'job_emails'   => (bool) get_user_meta( $user->ID, 'kj_job_emails', true ),
			),
			'saved_jobs'  => array_map(
				function ( $job ) {
					return array(
						'title' => get_the_title( $job ),
						'link'  => get_permalink( $job ),
					);
				},
				self::saved_jobs( $user->ID )
			),
			'job_alerts'  => $alerts ? $alerts : null,
		);
		$data = apply_filters( 'kj_export_user_data', $data, $user->ID );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="urbanemirates-my-data.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private static function delete_account() {
		self::require_login();
		check_admin_referer( 'kj_delete_account' );
		$user = wp_get_current_user();
		if ( ! self::is_seeker( $user ) ) {
			wp_die( 'Staff accounts can only be removed by an administrator.' );
		}
		$confirm = isset( $_POST['kj_confirm'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['kj_confirm'] ) ) ) ) : '';
		if ( 'DELETE' !== $confirm ) {
			wp_safe_redirect( add_query_arg( 'kj_msg', 'confirm', self::account_url() ) );
			exit;
		}

		do_action( 'kj_before_delete_user', $user->ID ); // CV module removes CVs and photos.

		global $wpdb;
		$wpdb->delete( KJ_Alerts::table(), array( 'email' => $user->user_email ) );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_logout();
		wp_delete_user( $user->ID );
		self::back_to_signin( array( 'kj_msg' => 'deleted' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Keep job seekers out of wp-admin                                    */
	/* ------------------------------------------------------------------ */

	public static function block_admin() {
		if ( wp_doing_ajax() || ! self::is_seeker() ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}
		wp_safe_redirect( self::account_url() );
		exit;
	}

	public static function admin_bar( $show ) {
		return self::is_seeker() ? false : $show;
	}
}
