<?php
/**
 * DEV ONLY — fakes Claude responses so the plugin can be tested locally without an API key.
 * Never upload this file to the live site.
 */
add_filter(
	'kj_pre_analyze',
	function ( $pre, $text, $url ) {
		if ( false !== stripos( $text, 'FORCE_ERROR' ) ) {
			return new WP_Error( 'kj_api', 'Mock API error', array( 'retry' => false ) );
		}
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), function ( $l ) {
			return $l && ! preg_match( '#^https?://#', $l );
		} ) );
		$title   = preg_replace( '/\s*[–-]\s*.*$/u', '', $lines[0] );
		$country = 'other';
		foreach ( array( 'dubai' => 'uae', 'abu dhabi' => 'uae', 'doha' => 'qatar', 'riyadh' => 'saudi-arabia', 'kuwait' => 'kuwait', 'muscat' => 'oman', 'manama' => 'bahrain' ) as $k => $v ) {
			if ( false !== stripos( $text, $k ) ) {
				$country = $v;
				break;
			}
		}
		$scam    = (bool) preg_match( '/\b(fee|deposit|pay rs)\b/i', $text );
		$company = preg_match( '/Company:\s*(.+)/i', $text, $m ) ? trim( $m[1] ) : '';
		$salary  = preg_match( '/(AED|QAR|SAR|KWD|OMR|BHD)\s*([\d,]+)\s*[-–]\s*([\d,]+)/i', $text, $s );
		$risk    = $scam ? 'red' : ( $company ? 'green' : 'yellow' );
		return array(
			'is_job_posting'   => true,
			'title'            => $title,
			'company'          => $company,
			'city'             => preg_match( '/(Dubai|Doha|Riyadh|Muscat|Manama|Abu Dhabi)/i', $text, $c ) ? $c[1] : '',
			'country'          => $country,
			'category'         => stripos( $text, 'nurse' ) !== false ? 'healthcare' : ( stripos( $text, 'hotel' ) !== false ? 'hospitality' : 'other' ),
			'job_type'         => 'full_time',
			'experience'       => '2+ years',
			'salary_min'       => $salary ? (float) str_replace( ',', '', $s[2] ) : 0,
			'salary_max'       => $salary ? (float) str_replace( ',', '', $s[3] ) : 0,
			'salary_currency'  => $salary ? strtoupper( $s[1] ) : '',
			'salary_period'    => $salary ? 'month' : 'unknown',
			'salary_text'      => '',
			'benefits'         => array(
				'visa'          => stripos( $text, 'visa' ) !== false,
				'accommodation' => stripos( $text, 'accommodation' ) !== false,
				'food'          => false,
				'transport'     => stripos( $text, 'transport' ) !== false,
				'air_ticket'    => false,
				'medical'       => false,
			),
			'deadline'         => '',
			'apply_url'        => '',
			'summary'          => 'Mock summary: ' . $title . ' role. ' . ( $company ? $company . ' is hiring.' : 'Employer not named.' ),
			'key_requirements' => array( 'Relevant diploma or degree', '2+ years experience' ),
			'missing_info'     => $company ? array() : array( 'company name' ),
			'risk_level'       => $risk,
			'risk_score'       => $scam ? 90 : ( $company ? 10 : 45 ),
			'risk_reasons'     => $scam ? array( 'Asks the candidate to pay a fee' ) : ( $company ? array( 'Named employer, realistic salary' ) : array( 'Company name missing' ) ),
			'_usage'           => array( 'model' => 'mock', 'input_tokens' => 0, 'output_tokens' => 0 ),
		);
	},
	10,
	3
);

// DEV ONLY: capture outgoing emails instead of sending, and show them at /?kj_dev_mail=1
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$log   = (array) get_option( 'kj_dev_mails', array() );
	$log[] = array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'] );
	update_option( 'kj_dev_mails', array_slice( $log, -20 ), false );
	return true;
}, 10, 2 );
add_action( 'init', function () {
	if ( isset( $_GET['kj_dev_mail'] ) ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		print_r( get_option( 'kj_dev_mails', array() ) );
		exit;
	}
} );
add_action( 'wp_loaded', function () {
	if ( isset( $_GET['kj_dev_reset'] ) ) {
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}kj_subscribers SET last_sent_at = '2026-10-13 18:00:00'" );
	}
	if ( isset( $_GET['kj_dev_cron'] ) ) {
		do_action( sanitize_key( $_GET['kj_dev_cron'] ) );
		echo 'ran ' . esc_html( $_GET['kj_dev_cron'] );
		exit;
	}
} );
add_action( 'init', function () {
	if ( isset( $_GET['kj_dev_dbg'] ) ) {
		global $wpdb;
		header( 'Content-Type: text/plain' );
		print_r( $wpdb->get_results( "SELECT email,status,countries,last_sent_at,confirmed_at FROM {$wpdb->prefix}kj_subscribers" ) );
		print_r( $wpdb->get_results( "SELECT ID,post_title,post_status,post_date,post_date_gmt FROM {$wpdb->posts} WHERE post_type='kj_job'" ) );
		echo 'run=' . get_option( 'kj_digest_run' ) . ' now=' . time() . ' ' . gmdate( 'Y-m-d H:i:s' );
		exit;
	}
}, 1 );
add_action( 'init', function () {
	if ( isset( $_GET['kj_dev_q'] ) ) {
		header( 'Content-Type: text/plain' );
		$q = new WP_Query( array(
			'post_type' => 'kj_job', 'post_status' => 'publish', 'posts_per_page' => 200,
			'date_query' => array( array( 'after' => '2 days ago', 'before' => gmdate( 'Y-m-d H:i:s' ), 'column' => 'post_date_gmt', 'inclusive' => true ) ),
		) );
		echo $q->request . "\n\n";
		foreach ( $q->posts as $p ) { echo $p->ID . ' ' . $p->post_title . ' ' . kj_term_slug( $p->ID, 'kj_country' ) . "\n"; }
		exit;
	}
}, 1 );
add_action( 'wp_loaded', function () {
	if ( isset( $_GET['kj_dev_opt'] ) ) {
		header( 'Content-Type: text/plain' );
		print_r( get_option( 'kj_settings' ) );
		echo "\nnext digest: " . wp_date( 'Y-m-d H:i', wp_next_scheduled( 'kj_daily_digest' ) );
		echo "\nnext five: " . wp_date( 'Y-m-d H:i', wp_next_scheduled( 'kj_every_five' ) );
		exit;
	}
} );
