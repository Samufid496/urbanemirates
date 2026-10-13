<?php
/**
 * Public site: job search shortcode, job cards, single job page, apply redirect, Google JobPosting data.
 *
 * Shortcodes:
 *   [kj_job_search]            Search box + filters + results (main Jobs page).
 *   [kj_latest_jobs count="6"] Latest jobs, e.g. on the home page.
 *   [kj_country_links]         Country quick-pick buttons with job counts.
 *   [kj_subscribe]             Job alert sign-up form (see KJ_Alerts).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Frontend {

	const PER_PAGE = 20;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_shortcode( 'kj_job_search', array( __CLASS__, 'shortcode_search' ) );
		add_shortcode( 'kj_latest_jobs', array( __CLASS__, 'shortcode_latest' ) );
		add_shortcode( 'kj_country_links', array( __CLASS__, 'shortcode_countries' ) );
		add_filter( 'the_content', array( __CLASS__, 'single_job_content' ) );
		add_action( 'template_redirect', array( __CLASS__, 'apply_redirect' ) );
		add_action( 'wp_head', array( __CLASS__, 'job_schema' ) );
	}

	public static function assets() {
		wp_enqueue_style( 'kj-style', KJ_URL . 'assets/kj-style.css', array(), KJ_VERSION );
	}

	/**
	 * Create the Jobs, Job Alerts and Safety pages on activation (once).
	 */
	public static function create_pages() {
		$pages = array(
			'kj_page_jobs'   => array( 'Jobs', "[kj_country_links]\n\n[kj_job_search]" ),
			'kj_page_alerts' => array( 'Job Alerts', "Get new Gulf & abroad jobs in your inbox every morning. Choose the countries and job types you want. It's free, and you can unsubscribe any time.\n\n[kj_subscribe]" ),
			'kj_page_safety' => array( 'Job Scam Safety', self::safety_page_content() ),
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

	private static function safety_page_content() {
		return "<h2>Genuine employers never ask you to pay</h2>\n"
			. "<ul>\n"
			. "<li><strong>Never pay</strong> for registration, visa, processing, medical, training or a \"refundable deposit\" to get a job.</li>\n"
			. "<li>In India, only <strong>recruiting agents registered on the Government's eMigrate portal</strong> (emigrate.gov.in) may recruit for Gulf countries. Ask for the registration number and check it there.</li>\n"
			. "<li>Be careful when the only contact is a personal WhatsApp number or a Gmail/Yahoo address.</li>\n"
			. "<li>Do not send your passport, Aadhaar or bank details until you have a written offer from a company you have verified.</li>\n"
			. "<li>Read your offer letter and employment contract fully before you travel. Keep copies.</li>\n"
			. "<li>Pay that looks far too high for the job is a warning sign.</li>\n"
			. "</ul>\n"
			. "<h2>How we check jobs</h2>\n"
			. "<p>Every job on this site is checked by AI for common scam signs and reviewed by a volunteer before it is published. We link to the original job post; you always apply on the original website. We cannot guarantee every job, so please stay careful.</p>\n"
			. "<p>Saw something suspicious? Tell us and we will remove it.</p>";
	}

	/* ------------------------------------------------------------------ */
	/* Search                                                              */
	/* ------------------------------------------------------------------ */

	private static function request_filters() {
		// phpcs:disable WordPress.Security.NonceVerification -- public read-only search.
		$f = array(
			'q'        => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
			'country'  => isset( $_GET['country'] ) ? sanitize_key( $_GET['country'] ) : '',
			'category' => isset( $_GET['category'] ) ? sanitize_key( $_GET['category'] ) : '',
			'benefit'  => isset( $_GET['benefit'] ) ? sanitize_key( $_GET['benefit'] ) : '',
			'posted'   => isset( $_GET['posted'] ) ? (int) $_GET['posted'] : 0,
			'salary'   => isset( $_GET['salary'] ) ? '1' === $_GET['salary'] : false,
			'paged'    => isset( $_GET['pg'] ) ? max( 1, (int) $_GET['pg'] ) : 1,
		);
		// phpcs:enable
		if ( ! array_key_exists( $f['country'], kj_countries() ) ) {
			$f['country'] = '';
		}
		if ( ! array_key_exists( $f['category'], kj_categories() ) ) {
			$f['category'] = '';
		}
		if ( ! array_key_exists( $f['benefit'], kj_benefit_labels() ) ) {
			$f['benefit'] = '';
		}
		if ( ! in_array( $f['posted'], array( 1, 3, 7, 30 ), true ) ) {
			$f['posted'] = 0;
		}
		return $f;
	}

	public static function query( array $f, $per_page = self::PER_PAGE ) {
		$args = array(
			'post_type'      => 'kj_job',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => isset( $f['paged'] ) ? $f['paged'] : 1,
			'tax_query'      => array(),
			'meta_query'     => array(),
		);
		if ( ! empty( $f['q'] ) ) {
			$args['s'] = $f['q'];
		}
		if ( ! empty( $f['country'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'kj_country',
				'field'    => 'slug',
				'terms'    => $f['country'],
			);
		}
		if ( ! empty( $f['category'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'kj_category',
				'field'    => 'slug',
				'terms'    => $f['category'],
			);
		}
		if ( ! empty( $f['benefit'] ) ) {
			// Benefits are stored as a serialised array; match the quoted value.
			$args['meta_query'][] = array(
				'key'     => '_kj_benefits',
				'value'   => '"' . $f['benefit'] . '"',
				'compare' => 'LIKE',
			);
		}
		if ( ! empty( $f['salary'] ) ) {
			$args['meta_query'][] = array(
				'key'     => '_kj_salary_max',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		}
		if ( ! empty( $f['posted'] ) ) {
			$args['date_query'] = array( array( 'after' => $f['posted'] . ' days ago' ) );
		}
		return new WP_Query( $args );
	}

	public static function shortcode_search() {
		$f      = self::request_filters();
		$q      = self::query( $f );
		$action = get_permalink();

		ob_start();
		?>
		<div class="kj">
			<form class="kj-search" method="get" action="<?php echo esc_url( $action ); ?>" role="search">
				<div class="kj-search-main">
					<label class="kj-sr" for="kj-q">Search jobs</label>
					<input type="search" id="kj-q" name="q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="Job title or skill — e.g. nurse, driver, accountant">
					<button type="submit" class="kj-btn kj-btn-primary">Search</button>
				</div>
				<div class="kj-filters">
					<label class="kj-sr" for="kj-country">Country</label>
					<select id="kj-country" name="country">
						<option value="">All countries</option>
						<?php foreach ( kj_countries() as $slug => $name ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f['country'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="kj-sr" for="kj-category">Category</label>
					<select id="kj-category" name="category">
						<option value="">All job types</option>
						<?php foreach ( kj_categories() as $slug => $name ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f['category'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="kj-sr" for="kj-benefit">Benefit</label>
					<select id="kj-benefit" name="benefit">
						<option value="">Any benefits</option>
						<?php foreach ( kj_benefit_labels() as $slug => $name ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f['benefit'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="kj-sr" for="kj-posted">Posted</label>
					<select id="kj-posted" name="posted">
						<option value="0">Any time</option>
						<?php foreach ( array( 1 => 'Last 24 hours', 3 => 'Last 3 days', 7 => 'Last 7 days', 30 => 'Last 30 days' ) as $d => $label ) : ?>
							<option value="<?php echo (int) $d; ?>" <?php selected( $f['posted'], $d ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="kj-check-inline"><input type="checkbox" name="salary" value="1" <?php checked( $f['salary'] ); ?>> Salary shown</label>
				</div>
			</form>

			<p class="kj-count">
				<?php echo esc_html( sprintf( _n( '%s job found', '%s jobs found', $q->found_posts, 'konkani-jobs' ), number_format_i18n( $q->found_posts ) ) ); ?>
				<?php if ( array_filter( array( $f['q'], $f['country'], $f['category'], $f['benefit'], $f['posted'], $f['salary'] ) ) ) : ?>
					· <a href="<?php echo esc_url( $action ); ?>">Clear filters</a>
				<?php endif; ?>
			</p>

			<?php if ( $q->have_posts() ) : ?>
				<div class="kj-list">
					<?php
					while ( $q->have_posts() ) {
						$q->the_post();
						echo self::card( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
					}
					wp_reset_postdata();
					?>
				</div>
				<?php echo self::pagination( $q, $f ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php else : ?>
				<div class="kj-empty">
					<p>No jobs match your search right now.</p>
					<p><a class="kj-btn" href="<?php echo esc_url( get_permalink( (int) get_option( 'kj_page_alerts' ) ) ); ?>">🔔 Get an alert when new jobs are added</a></p>
				</div>
			<?php endif; ?>
			<?php echo self::follow_box(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function pagination( WP_Query $q, array $f ) {
		if ( $q->max_num_pages < 2 ) {
			return '';
		}
		$base = array_filter(
			array(
				'q'        => $f['q'],
				'country'  => $f['country'],
				'category' => $f['category'],
				'benefit'  => $f['benefit'],
				'posted'   => $f['posted'],
				'salary'   => $f['salary'] ? 1 : '',
			)
		);
		$out = '<nav class="kj-pages" aria-label="Job pages">';
		if ( $f['paged'] > 1 ) {
			$out .= '<a class="kj-btn" href="' . esc_url( add_query_arg( $base + array( 'pg' => $f['paged'] - 1 ), get_permalink() ) ) . '">← Previous</a>';
		}
		$out .= '<span>Page ' . (int) $f['paged'] . ' of ' . (int) $q->max_num_pages . '</span>';
		if ( $f['paged'] < $q->max_num_pages ) {
			$out .= '<a class="kj-btn" href="' . esc_url( add_query_arg( $base + array( 'pg' => $f['paged'] + 1 ), get_permalink() ) ) . '">Next →</a>';
		}
		return $out . '</nav>';
	}

	public static function shortcode_latest( $atts ) {
		$atts = shortcode_atts( array( 'count' => 6 ), $atts );
		$q    = self::query( array(), max( 1, min( 30, (int) $atts['count'] ) ) );
		if ( ! $q->have_posts() ) {
			return '';
		}
		$out = '<div class="kj"><div class="kj-list">';
		while ( $q->have_posts() ) {
			$q->the_post();
			$out .= self::card( get_the_ID() );
		}
		wp_reset_postdata();
		$jobs_page = get_permalink( (int) get_option( 'kj_page_jobs' ) );
		$out      .= '</div><p class="kj-more"><a class="kj-btn kj-btn-primary" href="' . esc_url( $jobs_page ) . '">See all jobs →</a></p></div>';
		return $out;
	}

	public static function shortcode_countries() {
		$jobs_page = get_permalink( (int) get_option( 'kj_page_jobs' ) );
		$flags     = kj_country_flags();
		$out       = '<div class="kj"><div class="kj-countries">';
		foreach ( kj_countries() as $slug => $name ) {
			$term  = get_term_by( 'slug', $slug, 'kj_country' );
			$count = $term ? (int) $term->count : 0;
			$label = 'other' === $slug ? 'Other' : ( 'uae' === $slug ? 'UAE' : $name );
			$out  .= '<a class="kj-country" href="' . esc_url( add_query_arg( 'country', $slug, $jobs_page ) ) . '">'
				. '<span class="kj-flag" aria-hidden="true">' . esc_html( $flags[ $slug ] ) . '</span>'
				. '<span>' . esc_html( $label ) . '</span><small>' . esc_html( sprintf( _n( '%s job', '%s jobs', $count, 'konkani-jobs' ), number_format_i18n( $count ) ) ) . '</small></a>';
		}
		return $out . '</div></div>';
	}

	/**
	 * A compact job card for lists.
	 */
	public static function card( $id ) {
		$company  = get_post_meta( $id, '_kj_company', true );
		$city     = get_post_meta( $id, '_kj_city', true );
		$country  = kj_term_name( $id, 'kj_country' );
		$flags    = kj_country_flags();
		$flag     = isset( $flags[ kj_term_slug( $id, 'kj_country' ) ] ) ? $flags[ kj_term_slug( $id, 'kj_country' ) ] : '🌍';
		$salary   = kj_format_salary( $id );
		$benefits = array_intersect_key( kj_benefit_labels(), array_flip( (array) get_post_meta( $id, '_kj_benefits', true ) ) );

		$out  = '<article class="kj-card">';
		$out .= '<h3 class="kj-card-title"><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3>';
		$out .= '<p class="kj-card-meta">';
		if ( $company ) {
			$out .= '<span>🏢 ' . esc_html( $company ) . '</span>';
		}
		$out .= '<span>' . esc_html( $flag ) . ' ' . esc_html( trim( $city . ', ' . $country, ', ' ) ) . '</span>';
		$out .= '</p>';
		if ( $salary ) {
			$out .= '<p class="kj-salary">💰 ' . esc_html( $salary ) . '</p>';
		}
		if ( $benefits ) {
			$out .= '<p class="kj-tags">';
			foreach ( $benefits as $label ) {
				$out .= '<span class="kj-tag">' . esc_html( $label ) . '</span>';
			}
			$out .= '</p>';
		}
		$out .= '<p class="kj-card-foot"><span>' . esc_html( kj_term_name( $id, 'kj_category' ) ) . '</span>'
			. '<span>' . esc_html( human_time_diff( get_post_time( 'U', true, $id ), time() ) ) . ' ago · via ' . esc_html( get_post_meta( $id, '_kj_source', true ) ) . '</span></p>';
		$out .= '</article>';
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Single job page                                                     */
	/* ------------------------------------------------------------------ */

	public static function single_job_content( $content ) {
		if ( ! is_singular( 'kj_job' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id       = get_the_ID();
		$company  = get_post_meta( $id, '_kj_company', true );
		$city     = get_post_meta( $id, '_kj_city', true );
		$country  = kj_term_name( $id, 'kj_country' );
		$salary   = kj_format_salary( $id );
		$exp      = get_post_meta( $id, '_kj_experience', true );
		$type     = get_post_meta( $id, '_kj_job_type', true );
		$types    = kj_job_types();
		$deadline = get_post_meta( $id, '_kj_deadline', true );
		$source   = get_post_meta( $id, '_kj_source', true );
		$reqs     = array_filter( (array) get_post_meta( $id, '_kj_requirements', true ) );
		$benefits = array_intersect_key( kj_benefit_labels(), array_flip( (array) get_post_meta( $id, '_kj_benefits', true ) ) );
		$apply    = add_query_arg( 'kj_go', $id, home_url( '/' ) );
		$share    = 'https://wa.me/?text=' . rawurlencode( get_the_title( $id ) . ( $country ? ' – ' . $country : '' ) . "\n" . get_permalink( $id ) );

		ob_start();
		?>
		<div class="kj kj-single">
			<dl class="kj-facts">
				<?php if ( $company ) : ?><div><dt>Company</dt><dd><?php echo esc_html( $company ); ?></dd></div><?php endif; ?>
				<div><dt>Location</dt><dd><?php echo esc_html( trim( $city . ', ' . $country, ', ' ) ); ?></dd></div>
				<div><dt>Salary</dt><dd><?php echo esc_html( $salary ? $salary : 'Not stated' ); ?></dd></div>
				<?php if ( $exp ) : ?><div><dt>Experience</dt><dd><?php echo esc_html( $exp ); ?></dd></div><?php endif; ?>
				<?php if ( $type && isset( $types[ $type ] ) && 'other' !== $type ) : ?><div><dt>Job type</dt><dd><?php echo esc_html( $types[ $type ] ); ?></dd></div><?php endif; ?>
				<div><dt>Category</dt><dd><?php echo esc_html( kj_term_name( $id, 'kj_category' ) ); ?></dd></div>
				<?php if ( $deadline ) : ?><div><dt>Apply by</dt><dd><?php echo esc_html( wp_date( 'j M Y', strtotime( $deadline ) ) ); ?></dd></div><?php endif; ?>
				<div><dt>Posted</dt><dd><?php echo esc_html( get_the_date( 'j M Y', $id ) ); ?></dd></div>
			</dl>

			<?php if ( $benefits ) : ?>
				<p class="kj-tags"><?php foreach ( $benefits as $label ) : ?><span class="kj-tag">✓ <?php echo esc_html( $label ); ?></span><?php endforeach; ?></p>
			<?php endif; ?>

			<h2 class="kj-h">About this job</h2>
			<div class="kj-summary"><?php echo wp_kses_post( $content ); ?></div>

			<?php if ( $reqs ) : ?>
				<h2 class="kj-h">Requirements</h2>
				<ul class="kj-reqs"><?php foreach ( $reqs as $r ) : ?><li><?php echo esc_html( $r ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>

			<div class="kj-apply-box">
				<a class="kj-btn kj-btn-primary kj-btn-big" href="<?php echo esc_url( $apply ); ?>" target="_blank" rel="nofollow noopener">Apply on <?php echo esc_html( $source ? $source : 'original website' ); ?> →</a>
				<a class="kj-btn kj-btn-wa" href="<?php echo esc_url( $share ); ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
				<p class="kj-small">You will apply on the original website. We only share the job; we are not the employer.</p>
			</div>

			<div class="kj-warning">
				<strong>⚠️ Stay safe:</strong> Never pay any fee to get a job. Genuine employers and licensed agents don't ask for money for visa, registration or training.
				<a href="<?php echo esc_url( get_permalink( (int) get_option( 'kj_page_safety' ) ) ); ?>">Read our safety tips</a>
			</div>
			<?php echo self::follow_box(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function follow_box() {
		$alerts  = get_permalink( (int) get_option( 'kj_page_alerts' ) );
		$channel = kj_setting( 'whatsapp_channel' );
		$out     = '<div class="kj-follow"><p><strong>Never miss a job.</strong> Get new Gulf jobs every morning.</p><p>';
		$out    .= '<a class="kj-btn" href="' . esc_url( $alerts ) . '">📧 Email alerts</a>';
		if ( $channel ) {
			$out .= ' <a class="kj-btn kj-btn-wa" href="' . esc_url( $channel ) . '" target="_blank" rel="noopener">Follow on WhatsApp</a>';
		}
		return $out . '</p></div>';
	}

	/**
	 * /?kj_go=123 → count the click, then send the visitor to the original job post.
	 */
	public static function apply_redirect() {
		if ( ! isset( $_GET['kj_go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id  = (int) $_GET['kj_go']; // phpcs:ignore WordPress.Security.NonceVerification
		$url = 'kj_job' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? get_post_meta( $id, '_kj_apply_url', true ) : '';
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		update_post_meta( $id, '_kj_clicks', (int) get_post_meta( $id, '_kj_clicks', true ) + 1 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		wp_redirect( $url, 302, 'Urban Emirates' ); // phpcs:ignore WordPress.Security.SafeRedirect -- external job sites by design; URL comes from admin-reviewed post meta.
		exit;
	}

	/**
	 * Google for Jobs structured data on single job pages.
	 */
	public static function job_schema() {
		if ( ! kj_setting( 'job_schema' ) || ! is_singular( 'kj_job' ) ) {
			return;
		}
		$id      = get_queried_object_id();
		$company = get_post_meta( $id, '_kj_company', true );
		$expires = get_post_meta( $id, '_kj_expires', true );
		$country = kj_term_slug( $id, 'kj_country' );
		$codes   = array(
			'uae'          => 'AE',
			'saudi-arabia' => 'SA',
			'qatar'        => 'QA',
			'kuwait'       => 'KW',
			'oman'         => 'OM',
			'bahrain'      => 'BH',
		);
		$types   = array(
			'full_time'  => 'FULL_TIME',
			'part_time'  => 'PART_TIME',
			'contract'   => 'CONTRACTOR',
			'temporary'  => 'TEMPORARY',
			'internship' => 'INTERN',
		);

		$data = array(
			'@context'           => 'https://schema.org/',
			'@type'              => 'JobPosting',
			'title'              => get_the_title( $id ),
			'description'        => wpautop( esc_html( get_post_field( 'post_content', $id ) ) ),
			'datePosted'         => get_the_date( 'c', $id ),
			'directApply'        => false,
			'hiringOrganization' => array(
				'@type' => 'Organization',
				'name'  => $company ? $company : 'Confidential',
			),
			'jobLocation'        => array(
				'@type'   => 'Place',
				'address' => array_filter(
					array(
						'@type'           => 'PostalAddress',
						'addressLocality' => get_post_meta( $id, '_kj_city', true ),
						'addressCountry'  => isset( $codes[ $country ] ) ? $codes[ $country ] : '',
					)
				),
			),
		);
		if ( $expires ) {
			$data['validThrough'] = gmdate( 'c', strtotime( $expires . ' 23:59:59' ) );
		}
		$type = get_post_meta( $id, '_kj_job_type', true );
		if ( isset( $types[ $type ] ) ) {
			$data['employmentType'] = $types[ $type ];
		}
		$min = (float) get_post_meta( $id, '_kj_salary_min', true );
		$max = (float) get_post_meta( $id, '_kj_salary_max', true );
		$cur = get_post_meta( $id, '_kj_salary_currency', true );
		$per = strtoupper( (string) get_post_meta( $id, '_kj_salary_period', true ) );
		if ( $max > 0 && $cur && in_array( $per, array( 'MONTH', 'YEAR', 'DAY', 'HOUR' ), true ) ) {
			$data['baseSalary'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => $cur,
				'value'    => array(
					'@type'    => 'QuantitativeValue',
					'minValue' => $min > 0 ? $min : $max,
					'maxValue' => $max,
					'unitText' => $per,
				),
			);
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}
}
