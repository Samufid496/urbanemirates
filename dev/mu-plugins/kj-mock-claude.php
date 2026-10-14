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

// DEV ONLY: /?kj_dev_seed=1 creates sample published jobs for design testing.
add_action( 'wp_loaded', function () {
	if ( ! isset( $_GET['kj_dev_seed'] ) ) {
		return;
	}
	$jobs = array(
		array( 'Staff Nurse', 'Al Noor Hospital', 'Dubai', 'uae', 'healthcare', 6000, 8000, 'AED', '2+ years', array( 'visa', 'accommodation', 'medical' ), 'Registered nurses for inpatient wards at a multi-specialty hospital. Rotating shifts.', array( 'B.Sc or GNM nursing', 'DHA licence or eligibility' ), 'https://www.linkedin.com/jobs/view/111' ),
		array( 'Hotel Receptionist', 'Harbour Hotels', 'Doha', 'qatar', 'hospitality', 4500, 5500, 'QAR', '1–3 years', array( 'visa', 'food', 'transport' ), 'Front desk role at a busy city hotel. Good spoken English needed.', array( 'Hotel front office experience', 'Good English' ), 'https://www.bayt.com/en/qatar/jobs/222' ),
		array( 'Senior Accountant', 'Gulf Trading Co', 'Riyadh', 'saudi-arabia', 'finance', 9000, 12000, 'SAR', '5+ years', array( 'accommodation' ), 'Lead accounts, VAT and month-end close for a trading group.', array( 'B.Com / CA Inter', 'GCC VAT experience' ), 'https://www.naukrigulf.com/job-333' ),
		array( 'Electrician (ITI)', 'Kuwait Build Co', 'Kuwait City', 'kuwait', 'technical', 250, 350, 'KWD', '3+ years', array( 'visa', 'air_ticket' ), 'Building electrical installation and maintenance on commercial sites.', array( 'ITI Electrician', 'Gulf experience preferred' ), 'https://www.bayt.com/en/kuwait/jobs/444' ),
		array( 'Heavy Truck Driver', 'Oman Logistics LLC', 'Muscat', 'oman', 'logistics', 280, 350, 'OMR', '2+ years', array( 'visa', 'accommodation', 'food' ), 'Long-route heavy truck driving across Oman.', array( 'Valid heavy vehicle licence', 'GCC licence is a plus' ), 'https://www.linkedin.com/jobs/view/555' ),
		array( 'Chef de Partie', 'Example Hotels Group', 'Abu Dhabi', 'uae', 'hospitality', 4500, 6000, 'AED', '3+ years', array( 'visa', 'food', 'transport' ), '5-star hotel kitchen, continental section.', array( 'Culinary diploma', '5-star hotel experience' ), 'https://careers.example.com/chef' ),
	);
	foreach ( $jobs as $i => $j ) {
		$id = wp_insert_post( array( 'post_type' => 'kj_job', 'post_status' => 'publish', 'post_title' => $j[0], 'post_content' => $j[10], 'post_date' => wp_date( 'Y-m-d H:i:s', time() - ( $i + 1 ) * 5400 ) ) );
		$meta = array( '_kj_company' => $j[1], '_kj_city' => $j[2], '_kj_salary_min' => $j[5], '_kj_salary_max' => $j[6], '_kj_salary_currency' => $j[7], '_kj_salary_period' => 'month', '_kj_experience' => $j[8], '_kj_benefits' => $j[9], '_kj_requirements' => $j[11], '_kj_apply_url' => $j[12], '_kj_source' => kj_source_name( $j[12] ), '_kj_job_type' => 'full_time', '_kj_risk' => 'green', '_kj_state' => 'ready' );
		foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
		wp_set_object_terms( $id, array( $j[3] ), 'kj_country' );
		wp_set_object_terms( $id, array( $j[4] ), 'kj_category' );
		KJ_Cron::set_expiry( $id );
	}
	echo 'seeded';
	exit;
} );
add_action( 'wp_loaded', function () {
	if ( isset( $_GET['kj_dev_fixfuture'] ) ) {
		foreach ( get_posts( array( 'post_type' => 'kj_job', 'post_status' => 'future', 'fields' => 'ids', 'posts_per_page' => -1 ) ) as $id ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => 'publish', 'post_date' => wp_date( 'Y-m-d H:i:s', time() - 600 ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
		}
		echo 'fixed';
		exit;
	}
} );

// DEV ONLY: fake CV AI answers.
add_filter( 'kj_pre_cv_ai', function ( $pre, $action, $cv ) {
	switch ( $action ) {
		case 'summary':
			return array( 'summary' => 'Accountant with 5 years in UAE trading companies, handling billing, VAT returns and monthly reporting. Residence visa holder in Dubai, available with 1 month notice.' );
		case 'improve':
			return array( 'bullets' => array( 'Managed end-to-end billing and accounts for a Dubai trading company', 'Prepared and filed UAE VAT returns on time', 'Produced monthly financial reports for senior management' ) );
		case 'check':
			return array( 'score' => 8, 'tips' => array( 'Add your UAE driving licence if you have one', 'Add Tally or SAP to skills if you use them' ), 'strengths' => array( 'Clear UAE experience with dates', 'Visa status and notice period stated' ) );
		case 'tailor':
			return array( 'summary' => 'Accountant with 5 years of UAE VAT and month-end experience, ready for a senior role in Riyadh.', 'skills' => 'VAT returns, Month-end close, Excel, Billing', 'notes' => array( 'Highlight your VAT filing experience first', 'The job asks for CA Inter — add it if you have it' ) );
	}
	return $pre;
}, 10, 3 );

// DEV ONLY: /?kj_dev_lint=1 checks every PHP file of our plugin and theme for syntax errors
// (runs before plugins load, so it works even when a file is broken).
if ( isset( $_GET['kj_dev_lint'] ) ) {
	header( 'Content-Type: text/plain' );
	$dirs  = array( WP_CONTENT_DIR . '/plugins/urbanemirates-jobs', WP_CONTENT_DIR . '/themes/urbanemirates-theme' );
	$bad   = 0;
	$count = 0;
	foreach ( $dirs as $dir ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$count++;
			try {
				token_get_all( file_get_contents( $file->getPathname() ), TOKEN_PARSE );
			} catch ( ParseError $e ) {
				$bad++;
				echo 'ERROR ' . str_replace( WP_CONTENT_DIR, '', $file->getPathname() ) . ':' . $e->getLine() . ' ' . $e->getMessage() . "\n";
			}
		}
	}
	echo "checked $count files, $bad with errors\n";
	exit;
}

// DEV ONLY: /?kj_dev_pdf=CVID&template=gulf shows a CV's PDF inline (no login) for visual checks.
add_action( 'wp_loaded', function () {
	if ( ! isset( $_GET['kj_dev_pdf'] ) ) {
		return;
	}
	$row = KJ_CV::get( (int) $_GET['kj_dev_pdf'] );
	$t   = isset( $_GET['template'] ) ? sanitize_key( $_GET['template'] ) : $row->template;
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: inline; filename="cv.pdf"' );
	echo isset( $_GET['docx'] ) ? KJ_CV_Render::docx( $row->cv, $t ) : KJ_CV_Render::pdf( $row->cv, $t );
	exit;
} );

// DEV ONLY: /?kj_dev_pdfview=CVID&template=gulf renders the PDF to an image with PDF.js for visual checks.
add_action( 'wp_loaded', function () {
	if ( ! isset( $_GET['kj_dev_pdfview'] ) ) {
		return;
	}
	$src = add_query_arg( array( 'kj_dev_pdf' => (int) $_GET['kj_dev_pdfview'], 'template' => sanitize_key( $_GET['template'] ?? 'gulf' ) ), home_url( '/' ) );
	?><!doctype html><html><head><meta charset="utf-8"><title>PDF check</title>
	<style>body{margin:0;background:#888;display:flex;flex-direction:column;align-items:center;gap:16px;padding:16px}canvas{background:#fff;box-shadow:0 2px 12px rgba(0,0,0,.3)}</style>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script></head><body>
	<script>
	pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
	pdfjsLib.getDocument(<?php echo wp_json_encode( $src ); ?>).promise.then(async function(pdf){
		document.title='pages:'+pdf.numPages;
		for(let i=1;i<=pdf.numPages;i++){const page=await pdf.getPage(i);const vp=page.getViewport({scale:1.3});const c=document.createElement('canvas');c.width=vp.width;c.height=vp.height;document.body.appendChild(c);await page.render({canvasContext:c.getContext('2d'),viewport:vp}).promise;}
	}).catch(function(e){document.body.textContent='ERROR '+e.message;});
	</script></body></html><?php
	exit;
} );
