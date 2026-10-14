<?php
/**
 * Outgoing email through your own mailbox (e.g. Hostinger Email) using WordPress's built-in
 * PHPMailer — no mail plugin needed. When no SMTP host is set, WordPress's default mail is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Mail {

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ) );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ) );
		add_action( 'admin_post_kj_test_email', array( __CLASS__, 'handle_test' ) );
	}

	public static function smtp_password() {
		if ( defined( 'KJ_SMTP_PASSWORD' ) && KJ_SMTP_PASSWORD ) {
			return KJ_SMTP_PASSWORD;
		}
		return (string) kj_setting( 'smtp_pass' );
	}

	public static function enabled() {
		return '' !== (string) kj_setting( 'smtp_host' ) && '' !== (string) kj_setting( 'smtp_user' );
	}

	public static function configure( $phpmailer ) {
		if ( ! self::enabled() ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host       = (string) kj_setting( 'smtp_host' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->Port       = (int) kj_setting( 'smtp_port' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->SMTPAuth   = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->Username   = (string) kj_setting( 'smtp_user' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->Password   = self::smtp_password(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->SMTPSecure = 465 === (int) kj_setting( 'smtp_port' ) ? 'ssl' : 'tls'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->Timeout    = 20; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}

	public static function from_email( $email ) {
		$from = (string) kj_setting( 'mail_from' );
		if ( is_email( $from ) ) {
			return $from;
		}
		return self::enabled() && is_email( kj_setting( 'smtp_user' ) ) ? (string) kj_setting( 'smtp_user' ) : $email;
	}

	public static function from_name( $name ) {
		return 'WordPress' === $name ? 'Urban Emirates' : $name;
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'kj_test_email' );
		$to    = wp_get_current_user()->user_email;
		$error = '';
		$catch = function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = kj_send_email( $to, 'Urban Emirates test email', '<p>Your email settings work. Sign-in codes and job alerts will be sent this way.</p>' );
		remove_action( 'wp_mail_failed', $catch );

		set_transient(
			'kj_flash_' . get_current_user_id(),
			$ok ? array( '✅ Test email sent to ' . $to . '. Check the inbox (and Spam).', 'success' ) : array( '❌ Email failed: ' . ( $error ? $error : 'unknown error' ) . '. Check the SMTP settings.', 'error' ),
			60
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=kj_job&page=kj-settings' ) );
		exit;
	}
}
