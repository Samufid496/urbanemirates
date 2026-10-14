<?php
/**
 * Shared helpers: settings, fixed lists (categories, countries), URL and salary utilities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kj_default_settings() {
	return array(
		'api_key'           => '',
		'model'             => 'claude-opus-5-5',
		'effort'            => 'medium',
		'auto_publish'      => 0,      // Publish green jobs without review.
		'expiry_days'       => 30,     // Hide jobs this many days after publishing (if no deadline).
		'digest_hour'       => 8,      // Hour (site timezone) to send the daily email digest.
		'whatsapp_channel'  => '',     // Public WhatsApp Channel link shown on the site.
		'job_schema'        => 1,      // Output Google JobPosting structured data.
		'cv_ai_daily'       => 20,     // AI help actions per person per day in the CV builder.
		'turnstile_site'    => '',     // Cloudflare Turnstile site key (bot protection on forms).
		'turnstile_secret'  => '',     // Cloudflare Turnstile secret key.
		'ad_results'        => '',     // AdSense code shown after the 5th search result (empty = no ad).
		'ad_job'            => '',     // AdSense code on job pages, after "You need".
		'ad_sidebar'        => '',     // AdSense code in the desktop sidebar.
		'inr_rates'         => "AED=24\nSAR=23.5\nQAR=24.2\nKWD=288\nOMR=229\nBHD=234\nUSD=88\nEUR=100\nGBP=117",
	);
}

function kj_setting( $key ) {
	$settings = wp_parse_args( get_option( 'kj_settings', array() ), kj_default_settings() );
	return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
}

/**
 * The API key can live in wp-config.php (safer) or in the settings page.
 */
function kj_api_key() {
	if ( defined( 'KJ_CLAUDE_API_KEY' ) && KJ_CLAUDE_API_KEY ) {
		return KJ_CLAUDE_API_KEY;
	}
	return (string) kj_setting( 'api_key' );
}

/**
 * Job categories, slug => name. Chosen for the Gulf hiring market in 2026.
 */
function kj_categories() {
	return array(
		'healthcare'   => 'Healthcare & Nursing',
		'hospitality'  => 'Hospitality, Hotels & Restaurants',
		'construction' => 'Construction & Civil Engineering',
		'technical'    => 'Engineering & Technicians (MEP, HVAC, Electrical)',
		'oil-gas'      => 'Oil, Gas & Energy',
		'it'           => 'IT, Software & Data',
		'finance'      => 'Accounting, Finance & Banking',
		'sales'        => 'Sales, Retail & Marketing',
		'logistics'    => 'Driving, Logistics & Warehouse',
		'admin'        => 'Admin, Office & Customer Service',
		'other'        => 'Other Jobs',
	);
}

/**
 * Countries, slug => name. Gulf first, then the "abroad" bucket.
 */
function kj_countries() {
	return array(
		'uae'          => 'United Arab Emirates',
		'saudi-arabia' => 'Saudi Arabia',
		'qatar'        => 'Qatar',
		'kuwait'       => 'Kuwait',
		'oman'         => 'Oman',
		'bahrain'      => 'Bahrain',
		'other'        => 'Other Countries',
	);
}

function kj_country_flags() {
	return array(
		'uae'          => '🇦🇪',
		'saudi-arabia' => '🇸🇦',
		'qatar'        => '🇶🇦',
		'kuwait'       => '🇰🇼',
		'oman'         => '🇴🇲',
		'bahrain'      => '🇧🇭',
		'other'        => '🌍',
	);
}

function kj_benefit_labels() {
	return array(
		'visa'          => 'Visa provided',
		'accommodation' => 'Accommodation',
		'food'          => 'Food',
		'transport'     => 'Transport',
		'air_ticket'    => 'Air ticket',
		'medical'       => 'Medical insurance',
	);
}

function kj_job_types() {
	return array(
		'full_time'  => 'Full-time',
		'part_time'  => 'Part-time',
		'contract'   => 'Contract',
		'temporary'  => 'Temporary',
		'internship' => 'Internship',
		'other'      => 'Other',
	);
}

/**
 * Normalise a job URL so the same job pasted twice is detected as a duplicate.
 */
function kj_normalize_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return '';
	}
	$host  = strtolower( preg_replace( '/^(www\.|[a-z]{2}\.)(?=linkedin\.com$)/', '', strtolower( $parts['host'] ) ) );
	$host  = preg_replace( '/^www\./', '', $host );
	$path  = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
	$query = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}

	// LinkedIn search pages carry the job in ?currentJobId=.
	if ( 'linkedin.com' === $host && ! empty( $query['currentJobId'] ) ) {
		return 'linkedin.com/jobs/view/' . preg_replace( '/\D/', '', $query['currentJobId'] );
	}
	if ( 'linkedin.com' === $host && preg_match( '#/jobs/view/(?:[^/]*-)?(\d+)#', $path, $m ) ) {
		return 'linkedin.com/jobs/view/' . $m[1];
	}

	// Drop tracking parameters, keep ones that identify the job (e.g. Indeed ?jk=).
	foreach ( array_keys( $query ) as $key ) {
		if ( preg_match( '/^(utm_|trk|ref|tracking|src|source|fbclid|gclid|from)/i', $key ) ) {
			unset( $query[ $key ] );
		}
	}
	ksort( $query );
	$qs = $query ? '?' . http_build_query( $query ) : '';
	return $host . $path . $qs;
}

/**
 * Friendly source name from a URL, e.g. "LinkedIn", "Bayt", or the domain.
 */
function kj_source_name( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return '';
	}
	$known = array(
		'linkedin.'    => 'LinkedIn',
		'naukrigulf.'  => 'Naukri Gulf',
		'naukri.'      => 'Naukri',
		'bayt.'        => 'Bayt',
		'gulftalent.'  => 'GulfTalent',
		'indeed.'      => 'Indeed',
		'glassdoor.'   => 'Glassdoor',
		'dubizzle.'    => 'Dubizzle',
		'monstergulf.' => 'Monster Gulf',
		'founditgulf.' => 'Foundit Gulf',
		'drjobpro.'    => 'Dr Job',
		'jooble.'      => 'Jooble',
	);
	foreach ( $known as $needle => $name ) {
		if ( false !== strpos( $host, $needle ) ) {
			return $name;
		}
	}
	return preg_replace( '/^www\./', '', $host );
}

/**
 * Approximate INR per 1 unit of currency, from the editable settings list.
 */
function kj_inr_rates() {
	$rates = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) kj_setting( 'inr_rates' ) ) as $line ) {
		if ( preg_match( '/^\s*([A-Z]{3})\s*=\s*([\d.]+)\s*$/i', $line, $m ) ) {
			$rates[ strtoupper( $m[1] ) ] = (float) $m[2];
		}
	}
	return $rates;
}

/**
 * Human salary line, e.g. "AED 3,000 – 4,500 / month (≈ ₹72,000 – ₹1,08,000)".
 */
function kj_format_salary( $post_id, $with_inr = true ) {
	$min      = (float) get_post_meta( $post_id, '_kj_salary_min', true );
	$max      = (float) get_post_meta( $post_id, '_kj_salary_max', true );
	$currency = strtoupper( (string) get_post_meta( $post_id, '_kj_salary_currency', true ) );
	$period   = (string) get_post_meta( $post_id, '_kj_salary_period', true );

	if ( $min <= 0 && $max <= 0 ) {
		$text = (string) get_post_meta( $post_id, '_kj_salary_text', true );
		return $text ? $text : '';
	}
	if ( $max <= 0 ) {
		$max = $min;
	}
	if ( $min <= 0 ) {
		$min = $max;
	}

	$range  = number_format_i18n( $min ) . ( $max > $min ? '–' . number_format_i18n( $max ) : '' );
	$suffix = in_array( $period, array( 'month', 'year', 'hour', 'day' ), true ) ? ' / ' . $period : '';
	$out    = trim( $currency . ' ' . $range ) . $suffix;

	$rates = kj_inr_rates();
	if ( $with_inr && $currency && 'INR' !== $currency && isset( $rates[ $currency ] ) ) {
		$inr_min = kj_format_inr( $min * $rates[ $currency ] );
		$inr_max = kj_format_inr( $max * $rates[ $currency ] );
		$out    .= ' (≈ ₹' . $inr_min . ( $max > $min ? ' – ₹' . $inr_max : '' ) . ')';
	}
	return $out;
}

/**
 * Indian digit grouping: 108000 -> 1,08,000.
 */
function kj_format_inr( $amount ) {
	$amount = (string) (int) round( $amount, -2 );
	if ( strlen( $amount ) <= 3 ) {
		return $amount;
	}
	$last3 = substr( $amount, -3 );
	$rest  = substr( $amount, 0, -3 );
	$rest  = preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', $rest );
	return $rest . ',' . $last3;
}

function kj_risk_label( $risk ) {
	$labels = array(
		'green'  => 'Looks safe',
		'yellow' => 'Check carefully',
		'red'    => 'Likely scam',
	);
	return isset( $labels[ $risk ] ) ? $labels[ $risk ] : 'Not checked';
}

/**
 * Get the term name for a job's country or category.
 */
function kj_term_name( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
}

function kj_term_slug( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->slug : '';
}


/**
 * Two-letter country codes for tiles and schema.
 */
function kj_country_codes() {
	return array(
		'uae'          => 'AE',
		'saudi-arabia' => 'SA',
		'qatar'        => 'QA',
		'kuwait'       => 'KW',
		'oman'         => 'OM',
		'bahrain'      => 'BH',
		'other'        => 'Abroad',
	);
}

/**
 * Short benefit names for cards and the "Included" line.
 */
function kj_short_benefit( $label ) {
	$short = array(
		'Visa provided'     => 'Visa',
		'Medical insurance' => 'Medical',
	);
	return isset( $short[ $label ] ) ? $short[ $label ] : $label;
}

function kj_time_ago( $post_id ) {
	return human_time_diff( get_post_time( 'U', true, $post_id ), time() ) . ' ago';
}

/**
 * Compact salary for the facts row, e.g. "AED 6–8k" or "KWD 250–350".
 */
function kj_salary_short( $post_id ) {
	$min      = (float) get_post_meta( $post_id, '_kj_salary_min', true );
	$max      = (float) get_post_meta( $post_id, '_kj_salary_max', true );
	$currency = strtoupper( (string) get_post_meta( $post_id, '_kj_salary_currency', true ) );
	if ( $min <= 0 && $max <= 0 ) {
		$text = (string) get_post_meta( $post_id, '_kj_salary_text', true );
		return $text;
	}
	$min = $min > 0 ? $min : $max;
	$max = $max > 0 ? $max : $min;
	$fmt = function ( $n ) {
		if ( $n >= 1000 && 0 == fmod( $n, 100 ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
			return rtrim( rtrim( number_format( $n / 1000, 1, '.', '' ), '0' ), '.' );
		}
		return number_format_i18n( $n );
	};
	$k     = $min >= 1000 && 0 == fmod( $min, 100 ) && 0 == fmod( $max, 100 ); // phpcs:ignore Universal.Operators.StrictComparisons
	$range = $k ? $fmt( $min ) . ( $max > $min ? '–' . $fmt( $max ) : '' ) . 'k' : number_format_i18n( $min ) . ( $max > $min ? '–' . number_format_i18n( $max ) : '' );
	return trim( $currency . ' ' . $range );
}

/**
 * Approximate rupees for the same salary, e.g. "₹1.4–1.9 lakh" or "₹72,000".
 */
function kj_salary_inr( $post_id ) {
	$min      = (float) get_post_meta( $post_id, '_kj_salary_min', true );
	$max      = (float) get_post_meta( $post_id, '_kj_salary_max', true );
	$currency = strtoupper( (string) get_post_meta( $post_id, '_kj_salary_currency', true ) );
	$rates    = kj_inr_rates();
	if ( ( $min <= 0 && $max <= 0 ) || ! isset( $rates[ $currency ] ) ) {
		return '';
	}
	$lo  = $min > 0 ? $min : $max;
	$hi  = $max > 0 ? $max : $lo;
	$min = $lo * $rates[ $currency ];
	$max = $hi * $rates[ $currency ];
	if ( $min >= 100000 ) {
		$a = rtrim( rtrim( number_format( $min / 100000, 1 ), '0' ), '.' );
		$b = rtrim( rtrim( number_format( $max / 100000, 1 ), '0' ), '.' );
		return '₹' . $a . ( $b !== $a ? '–' . $b : '' ) . ' lakh';
	}
	return '₹' . kj_format_inr( $min ) . ( $max > $min ? '–' . kj_format_inr( $max ) : '' );
}

/**
 * A reserved ad space. Prints nothing until AdSense code is pasted in Settings.
 */
function kj_ad_slot( $slot ) {
	$code = (string) kj_setting( 'ad_' . $slot );
	if ( '' === trim( $code ) ) {
		return '';
	}
	return '<aside class="kj-ad kj-ad-' . esc_attr( $slot ) . '" aria-label="Advertisement"><span class="kj-ad-label">Advertisement</span>' . $code . '</aside>';
}

/**
 * Inline line icons (stroke, currentColor). Decorative: hidden from screen readers.
 */
function kj_icon( $name ) {
	$paths = array(
		'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'sliders'   => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
		'chevron'   => '<path d="m9 18 6-6-6-6"/>',
		'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		'cash'      => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
		'pin'       => '<path d="M12 22s7-6 7-12a7 7 0 0 0-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
		'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'check'     => '<path d="M20 6 9 17l-5-5"/>',
		'share'     => '<path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M16 6l-4-4-4 4"/><path d="M12 2v13"/>',
		'external'  => '<path d="M7 17 17 7"/><path d="M8 7h9v9"/>',
		'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
		'close'     => '<path d="M6 6l12 12M18 6 6 18"/>',
		'bell'      => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
		'bookmark'  => '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
		'whatsapp'  => '<path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/>',
		'back'      => '<path d="M15 18l-6-6 6-6"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg class="kj-icon kj-icon-' . esc_attr( $name ) . '" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}


/**
 * Send a branded HTML email. $unsubscribe_url adds an unsubscribe link and header (for job emails).
 */
function kj_send_email( $to, $subject, $inner_html, $unsubscribe_url = '' ) {
	$html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;padding:8px;color:#121A2E;line-height:1.55">'
		. '<p style="margin:0 0 18px;font-size:22px;font-weight:900;letter-spacing:-0.5px"><span style="color:#2357E8">urban</span>emirates</p>'
		. $inner_html
		. '<p style="color:#8A90A2;font-size:12px;margin-top:32px;border-top:1px solid #ECEEF3;padding-top:12px">Urban Emirates · Checked Gulf jobs. Free CV builder.';
	if ( $unsubscribe_url ) {
		$html .= ' · <a href="' . esc_url( $unsubscribe_url ) . '" style="color:#8A90A2">Unsubscribe</a>';
	}
	$html .= '</p></div>';

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	if ( $unsubscribe_url ) {
		$headers[] = 'List-Unsubscribe: <' . esc_url_raw( $unsubscribe_url ) . '>';
	}
	return wp_mail( $to, $subject, $html, $headers );
}

/**
 * Simple fixed-window rate limit. Returns false when $key has been used $max times within $window seconds.
 */
function kj_rate_limit( $key, $max, $window ) {
	$name  = 'kj_rl_' . md5( $key );
	$count = (int) get_transient( $name );
	if ( $count >= $max ) {
		return false;
	}
	set_transient( $name, $count + 1, $window );
	return true;
}

function kj_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * A primary-coloured email button.
 */
function kj_email_button( $url, $label ) {
	return '<p style="margin:22px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#2357E8;color:#ffffff;padding:13px 24px;border-radius:999px;text-decoration:none;font-weight:bold">' . esc_html( $label ) . '</a></p>';
}
