<?php
/**
 * Public site: search, job cards, single job page, apply redirect, Google JobPosting data.
 *
 * Markup follows the locked "Direction D" design. The Urban Emirates theme calls the
 * render_* methods directly; with any other theme the shortcodes and the_content
 * filter produce the same markup.
 *
 * Shortcodes:
 *   [kj_job_search]            Search bar + filters + results (main Jobs page).
 *   [kj_latest_jobs count="6"] Latest jobs grid.
 *   [kj_country_links]         Country tiles.
 *   [kj_topic_chips]           Popular search chips.
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
		add_shortcode( 'kj_country_links', array( __CLASS__, 'render_countries' ) );
		add_shortcode( 'kj_topic_chips', array( __CLASS__, 'render_chips' ) );
		add_filter( 'the_content', array( __CLASS__, 'single_job_content' ) );
		add_action( 'template_redirect', array( __CLASS__, 'apply_redirect' ) );
		add_action( 'wp_head', array( __CLASS__, 'job_schema' ) );
	}

	public static function assets() {
		wp_enqueue_style( 'kj-style', KJ_URL . 'assets/kj-style.css', array(), KJ_VERSION );
	}

	public static function jobs_url( $args = array() ) {
		$url = get_permalink( (int) get_option( 'kj_page_jobs' ) );
		return add_query_arg( $args, $url ? $url : home_url( '/' ) );
	}

	/**
	 * Create the Jobs, Job Alerts and Safety pages on activation (once).
	 */
	public static function create_pages() {
		$pages = array(
			'kj_page_jobs'   => array( 'Jobs', '[kj_job_search]' ),
			'kj_page_alerts' => array( 'Job Alerts', "Get new Gulf &amp; abroad jobs in your inbox every morning. Choose the countries and job types you want. It's free, and you can unsubscribe any time.\n\n[kj_subscribe]" ),
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
			. "<p>Every job on Urban Emirates is checked by AI for common scam signs and reviewed by a person before it is published. We link to the original job post; you always apply on the original website. We cannot guarantee every job, so please stay careful.</p>\n"
			. "<p>Saw something suspicious? Tell us and we will remove it.</p>";
	}

	/* ------------------------------------------------------------------ */
	/* Search                                                              */
	/* ------------------------------------------------------------------ */

	public static function request_filters() {
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

	/**
	 * The filters as URL arguments (empty ones dropped), for links that keep the search.
	 */
	private static function filter_args( array $f ) {
		return array_filter(
			array(
				'q'        => $f['q'],
				'country'  => $f['country'],
				'category' => $f['category'],
				'benefit'  => $f['benefit'],
				'posted'   => $f['posted'],
				'salary'   => $f['salary'] ? 1 : '',
			)
		);
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

	/**
	 * The big pill search bar: job/skill + country + job type.
	 */
	public static function render_search_form( array $f = array(), $size = 'large' ) {
		$f = wp_parse_args(
			$f,
			array(
				'q'        => '',
				'country'  => '',
				'category' => '',
			)
		);
		ob_start();
		?>
		<form class="kj-searchbar kj-searchbar-<?php echo esc_attr( $size ); ?>" method="get" action="<?php echo esc_url( self::jobs_url() ); ?>" role="search">
			<label class="kj-field kj-field-q">
				<?php echo kj_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="kj-sr">Job title or skill</span>
				<input type="search" name="q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="Job title or skill — nurse, driver, accountant">
			</label>
			<label class="kj-field kj-field-select">
				<span class="kj-sr">Country</span>
				<select name="country">
					<option value="">All countries</option>
					<?php foreach ( kj_countries() as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f['country'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="kj-field kj-field-select">
				<span class="kj-sr">Job type</span>
				<select name="category">
					<option value="">All job types</option>
					<?php foreach ( kj_categories() as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f['category'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="kj-btn kj-btn-primary">Search</button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function shortcode_search() {
		$f   = self::request_filters();
		$q   = self::query( $f );
		$now = self::filter_args( $f );

		// Active filters as removable chips.
		$labels  = array();
		$names   = array(
			'country'  => kj_countries(),
			'category' => kj_categories(),
			'benefit'  => kj_benefit_labels(),
		);
		$periods = array(
			1  => 'Last 24 hours',
			3  => 'Last 3 days',
			7  => 'Last 7 days',
			30 => 'Last 30 days',
		);
		foreach ( $now as $key => $value ) {
			if ( 'q' === $key ) {
				$label = '“' . $value . '”';
			} elseif ( 'country' === $key && 'uae' === $value ) {
				$label = 'UAE';
			} elseif ( isset( $names[ $key ] ) ) {
				$label = $names[ $key ][ $value ];
			} elseif ( 'posted' === $key ) {
				$label = $periods[ $value ];
			} else {
				$label = 'Salary shown';
			}
			$labels[ $key ] = $label;
		}

		ob_start();
		?>
		<div class="kj kj-results">
			<?php echo self::render_search_form( $f, 'compact' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<div class="kj-filterbar">
				<details class="kj-more-filters">
					<summary class="kj-chip kj-chip-outline"><?php echo kj_icon( 'sliders' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> Filters</summary>
					<form class="kj-filter-panel" method="get" action="<?php echo esc_url( self::jobs_url() ); ?>">
						<?php foreach ( array( 'q', 'country', 'category' ) as $keep ) : ?>
							<?php if ( '' !== $f[ $keep ] ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $keep ); ?>" value="<?php echo esc_attr( $f[ $keep ] ); ?>">
							<?php endif; ?>
						<?php endforeach; ?>
						<label>Benefit
							<select name="benefit">
								<option value="">Any</option>
								<?php foreach ( kj_benefit_labels() as $slug => $name ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f['benefit'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>Posted
							<select name="posted">
								<option value="0">Any time</option>
								<?php foreach ( $periods as $d => $label ) : ?>
									<option value="<?php echo (int) $d; ?>" <?php selected( $f['posted'], $d ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="kj-check"><input type="checkbox" name="salary" value="1" <?php checked( $f['salary'] ); ?>> Only jobs that show salary</label>
						<button type="submit" class="kj-btn kj-btn-primary">Show jobs</button>
					</form>
				</details>
				<?php foreach ( $labels as $key => $label ) : ?>
					<a class="kj-chip kj-chip-active" href="<?php echo esc_url( self::jobs_url( array_diff_key( $now, array( $key => 1 ) ) ) ); ?>" aria-label="Remove filter: <?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?> <span aria-hidden="true">✕</span></a>
				<?php endforeach; ?>
			</div>

			<p class="kj-count">
				<?php echo esc_html( sprintf( _n( '%s job found', '%s jobs found', $q->found_posts, 'urbanemirates-jobs' ), number_format_i18n( $q->found_posts ) ) ); ?>
				<span>· Newest first</span>
			</p>

			<?php if ( $q->have_posts() ) : ?>
				<div class="kj-list">
					<?php
					$i = 0;
					while ( $q->have_posts() ) {
						$q->the_post();
						echo self::card( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
						if ( 5 === ++$i ) {
							echo kj_ad_slot( 'results' ); // phpcs:ignore WordPress.Security.EscapeOutput -- admin-provided ad code.
						}
					}
					wp_reset_postdata();
					?>
				</div>
				<?php echo self::pagination( $q, $f ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php else : ?>
				<div class="kj-empty">
					<p><strong>No jobs match your search right now.</strong></p>
					<p>Try fewer filters, or get an email when new jobs arrive.</p>
					<p><a class="kj-btn kj-btn-outline" href="<?php echo esc_url( get_permalink( (int) get_option( 'kj_page_alerts' ) ) ); ?>">Get job alerts</a></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function pagination( WP_Query $q, array $f ) {
		if ( $q->max_num_pages < 2 ) {
			return '';
		}
		$base = self::filter_args( $f );
		$out  = '<nav class="kj-pages" aria-label="Job pages">';
		if ( $f['paged'] > 1 ) {
			$out .= '<a class="kj-btn kj-btn-outline" href="' . esc_url( self::jobs_url( $base + array( 'pg' => $f['paged'] - 1 ) ) ) . '">Previous</a>';
		}
		$out .= '<span>Page ' . (int) $f['paged'] . ' of ' . (int) $q->max_num_pages . '</span>';
		if ( $f['paged'] < $q->max_num_pages ) {
			$out .= '<a class="kj-btn kj-btn-outline" href="' . esc_url( self::jobs_url( $base + array( 'pg' => $f['paged'] + 1 ) ) ) . '">Next</a>';
		}
		return $out . '</nav>';
	}

	public static function shortcode_latest( $atts ) {
		$atts = shortcode_atts( array( 'count' => 6 ), $atts );
		return self::render_latest( (int) $atts['count'] );
	}

	public static function render_latest( $count = 6 ) {
		$q = self::query( array(), max( 1, min( 30, (int) $count ) ) );
		if ( ! $q->have_posts() ) {
			return '<p class="kj-muted">New jobs are added every day. Check back soon.</p>';
		}
		$out = '<div class="kj"><div class="kj-grid">';
		while ( $q->have_posts() ) {
			$q->the_post();
			$out .= self::card( get_the_ID() );
		}
		wp_reset_postdata();
		return $out . '</div></div>';
	}

	public static function render_countries() {
		$out = '<div class="kj"><div class="kj-countries">';
		foreach ( kj_countries() as $slug => $name ) {
			$codes = kj_country_codes();
			$label = 'uae' === $slug ? 'UAE' : ( 'other' === $slug ? 'Other countries' : $name );
			$out  .= '<a class="kj-country" href="' . esc_url( self::jobs_url( array( 'country' => $slug ) ) ) . '">'
				. '<span class="kj-country-code">' . esc_html( $codes[ $slug ] ) . '</span>'
				. '<span class="kj-country-name">' . esc_html( $label ) . '</span>'
				. '<span class="kj-country-link">View jobs ›</span></a>';
		}
		return $out . '</div></div>';
	}

	public static function render_chips() {
		$chips = array(
			array( 'Visa provided', array( 'benefit' => 'visa' ) ),
			array( 'Nurse', array( 'q' => 'nurse' ) ),
			array( 'Driver', array( 'q' => 'driver' ) ),
			array( 'Hotel & restaurant', array( 'category' => 'hospitality' ) ),
			array( 'Electrician', array( 'q' => 'electrician' ) ),
			array( 'Accountant', array( 'q' => 'accountant' ) ),
			array( 'Construction', array( 'category' => 'construction' ) ),
			array( 'IT & software', array( 'category' => 'it' ) ),
		);
		$out = '<div class="kj"><div class="kj-chips">';
		foreach ( $chips as $chip ) {
			$out .= '<a class="kj-chip kj-chip-outline" href="' . esc_url( self::jobs_url( $chip[1] ) ) . '">' . esc_html( $chip[0] ) . ' ' . kj_icon( 'chevron' ) . '</a>';
		}
		return $out . '</div></div>';
	}

	/**
	 * Initials for the small company badge, e.g. "Al Noor Hospital" → "AN".
	 */
	private static function initials( $company, $title ) {
		$source = $company ? $company : $title;
		$words  = preg_split( '/[\s\-&,.]+/u', trim( $source ), -1, PREG_SPLIT_NO_EMPTY );
		$out    = '';
		foreach ( array_slice( $words, 0, 2 ) as $w ) {
			$out .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) );
		}
		return $out ? $out : '•';
	}

	/**
	 * A job card for lists and grids.
	 */
	public static function card( $id ) {
		$company  = get_post_meta( $id, '_kj_company', true );
		$city     = get_post_meta( $id, '_kj_city', true );
		$country  = kj_term_slug( $id, 'kj_country' );
		$cname    = 'uae' === $country ? 'UAE' : kj_term_name( $id, 'kj_country' );
		// "Dubai" for the UAE (well known), "Doha, Qatar" elsewhere.
		$place = $city ? ( 'uae' === $country ? $city : trim( $city . ', ' . $cname, ', ' ) ) : $cname;
		$salary   = kj_format_salary( $id, false );
		$exp      = get_post_meta( $id, '_kj_experience', true );
		$benefits = array_intersect_key( kj_benefit_labels(), array_flip( (array) get_post_meta( $id, '_kj_benefits', true ) ) );
		$benefits = array_map( 'kj_short_benefit', $benefits );
		$source   = get_post_meta( $id, '_kj_source', true );

		$out  = '<article class="kj-card">';
		$out .= '<div class="kj-card-top"><div class="kj-card-heading">';
		$out .= '<h3 class="kj-card-title"><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3>';
		$out .= '<p class="kj-card-company">' . esc_html( $company ? $company : 'Company not named' ) . '</p></div>';
		$out .= '<span class="kj-badge-initials" aria-hidden="true">' . esc_html( self::initials( $company, get_the_title( $id ) ) ) . '</span></div>';

		$out .= '<ul class="kj-card-facts">';
		if ( $exp ) {
			$out .= '<li>' . kj_icon( 'briefcase' ) . esc_html( $exp ) . '</li>';
		}
		if ( $salary ) {
			$out .= '<li>' . kj_icon( 'cash' ) . esc_html( $salary ) . '</li>';
		}
		if ( $place ) {
			$out .= '<li>' . kj_icon( 'pin' ) . esc_html( $place ) . '</li>';
		}
		$out .= '</ul>';

		if ( $benefits ) {
			$out .= '<p class="kj-card-perks">' . esc_html( implode( ' · ', array_slice( $benefits, 0, 3 ) ) ) . '</p>';
		}

		$out .= '<p class="kj-card-foot"><span>' . esc_html( kj_time_ago( $id ) . ( $source ? ' · via ' . $source : '' ) ) . '</span>'
			. '<span class="kj-checked">' . kj_icon( 'shield' ) . 'Checked</span></p>';
		$out .= '</article>';
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Single job page                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Fallback for themes other than Urban Emirates: render the job page inside the_content.
	 */
	public static function single_job_content( $content ) {
		if ( current_theme_supports( 'kj-job-templates' ) || ! is_singular( 'kj_job' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		remove_filter( 'the_content', array( __CLASS__, 'single_job_content' ) );
		return self::render_job( get_the_ID(), false );
	}

	/**
	 * The job page, as approved: one calm column, facts row, sticky Apply bar.
	 *
	 * @param int  $id         Job ID.
	 * @param bool $with_title Whether to print the title block (themes that print their own title pass false).
	 */
	public static function render_job( $id, $with_title = true ) {
		$company   = get_post_meta( $id, '_kj_company', true );
		$city      = get_post_meta( $id, '_kj_city', true );
		$country   = kj_term_name( $id, 'kj_country' );
		$salary    = kj_salary_short( $id );
		$inr       = kj_salary_inr( $id );
		$period    = get_post_meta( $id, '_kj_salary_period', true );
		$exp       = get_post_meta( $id, '_kj_experience', true );
		$type      = get_post_meta( $id, '_kj_job_type', true );
		$types     = kj_job_types();
		$deadline  = get_post_meta( $id, '_kj_deadline', true );
		$source    = get_post_meta( $id, '_kj_source', true );
		$reqs      = array_filter( (array) get_post_meta( $id, '_kj_requirements', true ) );
		$benefits  = array_intersect_key( kj_benefit_labels(), array_flip( (array) get_post_meta( $id, '_kj_benefits', true ) ) );
		$benefits  = array_map( 'kj_short_benefit', $benefits );
		$summary   = get_post_field( 'post_content', $id );
		$apply     = add_query_arg( 'kj_go', $id, home_url( '/' ) );
		$share     = 'https://wa.me/?text=' . rawurlencode( get_the_title( $id ) . ( $country ? ' – ' . $country : '' ) . "\n" . get_permalink( $id ) );
		$safety    = get_permalink( (int) get_option( 'kj_page_safety' ) );
		$per_label = in_array( $period, array( 'month', 'year', 'day', 'hour' ), true ) ? 'Salary / ' . $period : 'Salary';

		ob_start();
		?>
		<article class="kj kj-job">
			<?php if ( $with_title ) : ?>
				<header class="kj-job-head">
					<div>
						<?php if ( $company ) : ?><p class="kj-job-company"><?php echo esc_html( $company ); ?></p><?php endif; ?>
						<h1 class="kj-job-title"><?php echo esc_html( get_the_title( $id ) ); ?></h1>
						<p class="kj-job-meta"><?php echo esc_html( trim( $city . ', ' . ( 'uae' === kj_term_slug( $id, 'kj_country' ) ? 'UAE' : $country ), ', ' ) . ' · ' . kj_time_ago( $id ) ); ?></p>
					</div>
					<a class="kj-icon-btn" href="<?php echo esc_url( $share ); ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><?php echo kj_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
				</header>
			<?php endif; ?>

			<dl class="kj-facts">
				<div>
					<dt><?php echo esc_html( $per_label ); ?></dt>
					<dd><?php echo esc_html( $salary ? $salary : 'Not stated' ); ?></dd>
					<?php if ( $inr ) : ?><dd class="kj-facts-sub">≈ <?php echo esc_html( $inr ); ?></dd><?php endif; ?>
				</div>
				<div>
					<dt>Experience</dt>
					<dd><?php echo esc_html( $exp ? $exp : 'Not stated' ); ?></dd>
				</div>
				<div>
					<dt>Job type</dt>
					<dd><?php echo esc_html( $type && isset( $types[ $type ] ) && 'other' !== $type ? $types[ $type ] : '—' ); ?></dd>
				</div>
			</dl>

			<?php if ( $benefits ) : ?>
				<section class="kj-section">
					<h2 class="kj-label">Included</h2>
					<ul class="kj-included">
						<?php foreach ( $benefits as $label ) : ?>
							<li><?php echo kj_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( $summary ) : ?>
				<section class="kj-section">
					<h2 class="kj-label">About the job</h2>
					<div class="kj-prose"><?php echo wp_kses_post( wpautop( $summary ) ); ?></div>
				</section>
			<?php endif; ?>

			<?php if ( $reqs ) : ?>
				<section class="kj-section">
					<h2 class="kj-label">You need</h2>
					<ul class="kj-prose-list">
						<?php foreach ( $reqs as $r ) : ?><li><?php echo esc_html( $r ); ?></li><?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<p class="kj-source">
				<?php
				$bits = array();
				if ( $source ) {
					$bits[] = 'Source: ' . $source;
				}
				if ( $deadline ) {
					$bits[] = 'Apply by ' . wp_date( 'j M Y', strtotime( $deadline ) );
				}
				echo esc_html( implode( ' · ', $bits ) );
				?>
			</p>
			<?php echo apply_filters( 'kj_job_after_source', '', $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- e.g. the "Tailor my CV" link. ?>

			<?php echo kj_ad_slot( 'job' ); // phpcs:ignore WordPress.Security.EscapeOutput -- admin-provided ad code. ?>

			<div class="kj-applybar">
				<p class="kj-applybar-note"><?php echo kj_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> Checked for scams · <a href="<?php echo esc_url( $safety ); ?>">Never pay a fee for any job</a></p>
				<div class="kj-applybar-actions">
					<?php echo apply_filters( 'kj_job_secondary_action', '', $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- e.g. the Save button from the accounts module. ?>
					<a class="kj-btn kj-btn-primary kj-btn-block" href="<?php echo esc_url( $apply ); ?>" target="_blank" rel="nofollow noopener">Apply on <?php echo esc_html( $source ? $source : 'original website' ); ?> <?php echo kj_icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
				</div>
			</div>
		</article>
		<?php
		return ob_get_clean();
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
		$codes   = kj_country_codes();
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
						'addressCountry'  => isset( $codes[ $country ] ) && 'other' !== $country ? $codes[ $country ] : '',
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
