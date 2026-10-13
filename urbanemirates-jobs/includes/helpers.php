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
function kj_format_salary( $post_id ) {
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

	$range  = number_format_i18n( $min ) . ( $max > $min ? ' – ' . number_format_i18n( $max ) : '' );
	$suffix = in_array( $period, array( 'month', 'year', 'hour', 'day' ), true ) ? ' / ' . $period : '';
	$out    = trim( $currency . ' ' . $range ) . $suffix;

	$rates = kj_inr_rates();
	if ( $currency && 'INR' !== $currency && isset( $rates[ $currency ] ) ) {
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
