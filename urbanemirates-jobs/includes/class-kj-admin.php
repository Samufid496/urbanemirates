<?php
/**
 * Admin screens: Add Jobs, Review, WhatsApp post, Settings, and the job details box.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_kj_review', array( __CLASS__, 'handle_review' ) );
		add_action( 'admin_post_kj_publish_green', array( __CLASS__, 'handle_publish_green' ) );
		add_action( 'admin_post_kj_test_claude', array( __CLASS__, 'handle_test' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_kj_job', array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		$parent = 'edit.php?post_type=kj_job';
		$count  = self::count_pending();
		$bubble = $count ? ' <span class="awaiting-mod">' . (int) $count . '</span>' : '';

		add_submenu_page( $parent, 'Add Jobs', 'Add Jobs (paste)', 'edit_others_posts', 'kj-add-jobs', array( __CLASS__, 'page_add' ), 0 );
		add_submenu_page( $parent, 'Review Jobs', 'Review' . $bubble, 'edit_others_posts', 'kj-review', array( __CLASS__, 'page_review' ), 1 );
		add_submenu_page( $parent, 'WhatsApp Post', 'WhatsApp Post', 'edit_others_posts', 'kj-whatsapp', array( __CLASS__, 'page_whatsapp' ) );
		add_submenu_page( $parent, 'Subscribers', 'Subscribers', 'manage_options', 'kj-subscribers', array( 'KJ_Alerts', 'page_subscribers' ) );
		add_submenu_page( $parent, 'Urban Emirates Settings', 'Settings', 'manage_options', 'kj-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'kj_job' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'kj-admin', KJ_URL . 'assets/kj-admin.css', array(), KJ_VERSION );
		wp_enqueue_script( 'kj-admin', KJ_URL . 'assets/kj-admin.js', array(), KJ_VERSION, true );
		wp_localize_script(
			'kj-admin',
			'KJAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'kj_process' ),
				'reviewUrl' => admin_url( 'edit.php?post_type=kj_job&page=kj-review' ),
			)
		);
	}

	private static function count_pending() {
		$counts = wp_count_posts( 'kj_job' );
		return isset( $counts->pending ) ? (int) $counts->pending : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Add Jobs                                                            */
	/* ------------------------------------------------------------------ */

	public static function page_add() {
		$queued    = isset( $_GET['queued'] ) ? (int) $_GET['queued'] : null; // phpcs:ignore WordPress.Security.NonceVerification
		$dupes     = isset( $_GET['dupes'] ) ? (int) $_GET['dupes'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$remaining = KJ_Importer::queue_count();
		?>
		<div class="wrap kj-wrap">
			<h1>Add Jobs</h1>

			<?php if ( 0 === $queued && 0 === $dupes ) : ?>
				<div class="notice notice-warning"><p><strong>No jobs were found in what you pasted.</strong> Paste the job link and the full job description (at least a few lines), with a line of <code>---</code> between jobs.</p></div>
			<?php elseif ( null !== $queued ) : ?>
				<div class="notice notice-success"><p>
					<strong><?php echo (int) $queued; ?> job(s) added to the queue.</strong>
					<?php if ( $dupes ) : ?>
						<?php echo (int) $dupes; ?> skipped because they are already on the site.
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<?php if ( '' === kj_api_key() ) : ?>
				<div class="notice notice-error"><p>Add your Claude API key in <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=kj_job&page=kj-settings' ) ); ?>">Settings</a> before adding jobs.</p></div>
			<?php endif; ?>

			<div class="kj-panel kj-progress" id="kj-progress" data-remaining="<?php echo (int) $remaining; ?>" <?php echo $remaining ? '' : 'hidden'; ?>>
				<h2>Claude is checking your jobs… <span id="kj-remaining"><?php echo (int) $remaining; ?></span> left</h2>
				<p class="description">Keep this page open. If you close it, the remaining jobs are processed in the background every few minutes.</p>
				<ul id="kj-progress-list"></ul>
				<p id="kj-progress-done" hidden><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=kj_job&page=kj-review' ) ); ?>">All done — go to Review →</a></p>
			</div>

			<div class="kj-panel">
				<h2>Paste jobs</h2>
				<ol class="kj-steps">
					<li>Open a job on LinkedIn, Naukri Gulf, Bayt, a company website, etc.</li>
					<li>Copy the <strong>job link</strong> (address bar) and the <strong>job description</strong>, and paste both below.</li>
					<li>For more jobs, put a line with <code>---</code> between them. You can paste up to 30 at once.</li>
				</ol>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kj_queue_jobs">
					<?php wp_nonce_field( 'kj_queue_jobs' ); ?>
					<textarea name="kj_paste" class="kj-paste" rows="18" required placeholder="https://www.linkedin.com/jobs/view/1234567890
Staff Nurse – Dubai
Company: ...
(full job description)
---
https://www.bayt.com/en/uae/jobs/...
Hotel Receptionist – Doha
(full job description)"></textarea>
					<p><button type="submit" class="button button-primary button-hero">Check with Claude &amp; add to review</button></p>
				</form>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Review                                                              */
	/* ------------------------------------------------------------------ */

	public static function page_review() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'review'; // phpcs:ignore WordPress.Security.NonceVerification
		$url = admin_url( 'edit.php?post_type=kj_job&page=kj-review' );

		$problems = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'draft',
				'meta_query'     => array(
					array(
						'key'     => '_kj_state',
						'value'   => array( 'queued', 'processing', 'error' ),
						'compare' => 'IN',
					),
				),
				'posts_per_page' => 100,
			)
		);
		?>
		<div class="wrap kj-wrap">
			<h1>Review Jobs</h1>
			<?php self::stats_bar(); ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo 'review' === $tab ? 'nav-tab-active' : ''; ?>">Waiting for review (<?php echo (int) self::count_pending(); ?>)</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'queue', $url ) ); ?>" class="nav-tab <?php echo 'queue' === $tab ? 'nav-tab-active' : ''; ?>">Queue &amp; problems (<?php echo count( $problems ); ?>)</a>
			</nav>

			<?php
			if ( 'queue' === $tab ) {
				self::render_queue( $problems );
			} else {
				self::render_review();
			}
			?>
		</div>
		<?php
	}

	private static function stats_bar() {
		$counts = wp_count_posts( 'kj_job' );
		global $wpdb;
		$clicks = (int) $wpdb->get_var( "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_kj_clicks'" );
		$subs   = KJ_Alerts::count_confirmed();
		?>
		<div class="kj-stats">
			<div><strong><?php echo (int) $counts->publish; ?></strong><span>Live jobs</span></div>
			<div><strong><?php echo (int) $counts->pending; ?></strong><span>To review</span></div>
			<div><strong><?php echo (int) KJ_Importer::queue_count(); ?></strong><span>In queue</span></div>
			<div><strong><?php echo (int) $clicks; ?></strong><span>Apply clicks</span></div>
			<div><strong><?php echo (int) $subs; ?></strong><span>Alert subscribers</span></div>
		</div>
		<?php
	}

	private static function render_review() {
		$jobs = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'pending',
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		if ( ! $jobs ) {
			echo '<p class="kj-empty">Nothing to review. <a href="' . esc_url( admin_url( 'edit.php?post_type=kj_job&page=kj-add-jobs' ) ) . '">Add jobs →</a></p>';
			return;
		}

		$groups = array(
			'green'  => array(),
			'yellow' => array(),
			'red'    => array(),
		);
		foreach ( $jobs as $job ) {
			$risk              = get_post_meta( $job->ID, '_kj_risk', true );
			$groups[ isset( $groups[ $risk ] ) ? $risk : 'yellow' ][] = $job;
		}

		if ( $groups['green'] ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kj-bulk">
				<input type="hidden" name="action" value="kj_publish_green">
				<?php wp_nonce_field( 'kj_publish_green' ); ?>
				<button class="button button-primary">Publish all <?php echo count( $groups['green'] ); ?> green jobs</button>
			</form>
			<?php
		}

		$titles = array(
			'green'  => '🟢 Looks safe',
			'yellow' => '🟡 Check carefully',
			'red'    => '🔴 Likely scam — reject unless you have verified it',
		);
		foreach ( $groups as $risk => $list ) {
			if ( ! $list ) {
				continue;
			}
			echo '<h2 class="kj-group-title">' . esc_html( $titles[ $risk ] ) . ' (' . count( $list ) . ')</h2>';
			echo '<div class="kj-cards">';
			foreach ( $list as $job ) {
				self::review_card( $job );
			}
			echo '</div>';
		}
	}

	private static function review_card( $job ) {
		$id       = $job->ID;
		$risk     = get_post_meta( $id, '_kj_risk', true );
		$reasons  = (array) get_post_meta( $id, '_kj_risk_reasons', true );
		$missing  = (array) get_post_meta( $id, '_kj_missing', true );
		$benefits = (array) get_post_meta( $id, '_kj_benefits', true );
		$apply    = get_post_meta( $id, '_kj_apply_url', true );
		$labels   = kj_benefit_labels();
		?>
		<div class="kj-card kj-risk-<?php echo esc_attr( $risk ); ?>">
			<div class="kj-card-head">
				<h3><?php echo esc_html( get_the_title( $id ) ); ?></h3>
				<span class="kj-badge kj-badge-<?php echo esc_attr( $risk ); ?>"><?php echo esc_html( kj_risk_label( $risk ) ); ?> · <?php echo (int) get_post_meta( $id, '_kj_risk_score', true ); ?>/100</span>
			</div>
			<p class="kj-meta">
				<?php echo esc_html( get_post_meta( $id, '_kj_company', true ) ?: 'Company not stated' ); ?> ·
				<?php echo esc_html( trim( get_post_meta( $id, '_kj_city', true ) . ', ' . kj_term_name( $id, 'kj_country' ), ', ' ) ); ?> ·
				<?php echo esc_html( kj_term_name( $id, 'kj_category' ) ); ?>
			</p>
			<?php $salary = kj_format_salary( $id ); ?>
			<p><strong>Salary:</strong> <?php echo esc_html( $salary ? $salary : 'Not stated' ); ?></p>
			<?php if ( $benefits ) : ?>
				<p><strong>Benefits:</strong> <?php echo esc_html( implode( ', ', array_intersect_key( $labels, array_flip( $benefits ) ) ) ); ?></p>
			<?php endif; ?>
			<p class="kj-summary"><?php echo esc_html( $job->post_content ); ?></p>
			<?php if ( $reasons ) : ?>
				<p><strong>Why:</strong></p>
				<ul class="kj-reasons"><?php foreach ( $reasons as $r ) { echo '<li>' . esc_html( $r ) . '</li>'; } ?></ul>
			<?php endif; ?>
			<?php if ( $missing ) : ?>
				<p class="kj-missing"><strong>Missing:</strong> <?php echo esc_html( implode( ', ', $missing ) ); ?></p>
			<?php endif; ?>
			<p class="kj-source">
				<?php if ( $apply ) : ?>
					Source: <a href="<?php echo esc_url( $apply ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_post_meta( $id, '_kj_source', true ) ); ?> ↗</a>
				<?php else : ?>
					<span class="kj-warn">No link to the original job — edit and add one before publishing.</span>
				<?php endif; ?>
			</p>
			<div class="kj-actions">
				<?php echo self::action_button( $id, 'publish', 'Publish', 'button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<a class="button" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">Edit</a>
				<?php echo self::action_button( $id, 'reject', 'Reject', 'kj-reject' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		</div>
		<?php
	}

	private static function render_queue( $problems ) {
		if ( ! $problems ) {
			echo '<p class="kj-empty">No queued or failed jobs.</p>';
			return;
		}
		?>
		<table class="widefat striped kj-queue">
			<thead><tr><th>Job</th><th>Status</th><th>Details</th><th>Actions</th></tr></thead>
			<tbody>
			<?php foreach ( $problems as $job ) : ?>
				<?php $state = get_post_meta( $job->ID, '_kj_state', true ); ?>
				<tr>
					<td>
						<strong><?php echo esc_html( get_the_title( $job ) ); ?></strong><br>
						<span class="description"><?php echo esc_html( wp_trim_words( get_post_meta( $job->ID, '_kj_raw', true ), 20 ) ); ?></span>
					</td>
					<td><?php echo esc_html( ucfirst( $state ) ); ?></td>
					<td><?php echo esc_html( get_post_meta( $job->ID, '_kj_error', true ) ); ?></td>
					<td>
						<?php if ( 'error' === $state ) : ?>
							<?php echo self::action_button( $job->ID, 'retry', 'Try again', '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php endif; ?>
						<?php echo self::action_button( $job->ID, 'reject', 'Delete', 'kj-reject' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( KJ_Importer::queue_count() ) : ?>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=kj_job&page=kj-add-jobs' ) ); ?>">Process queue now</a></p>
		<?php endif; ?>
		<?php
	}

	private static function action_button( $id, $do, $label, $class ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'kj_review',
					'job'    => $id,
					'do'     => $do,
				),
				admin_url( 'admin-post.php' )
			),
			'kj_review_' . $id
		);
		return '<a class="button ' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	public static function handle_review() {
		$id = isset( $_GET['job'] ) ? (int) $_GET['job'] : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		if ( ! current_user_can( 'edit_others_posts' ) || 'kj_job' !== get_post_type( $id ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'kj_review_' . $id );

		$tab = '';
		switch ( $do ) {
			case 'publish':
				if ( ! get_post_meta( $id, '_kj_apply_url', true ) ) {
					self::flash( 'Add a link to the original job before publishing.', 'error' );
					wp_safe_redirect( get_edit_post_link( $id, 'url' ) );
					exit;
				}
				self::publish( $id );
				self::flash( 'Job published.' );
				break;
			case 'reject':
				$tab = 'draft' === get_post_status( $id ) ? 'queue' : '';
				wp_trash_post( $id );
				self::flash( 'Job removed (it is in the Trash for 30 days).' );
				break;
			case 'retry':
				update_post_meta( $id, '_kj_state', 'queued' );
				update_post_meta( $id, '_kj_attempts', 0 );
				delete_post_meta( $id, '_kj_error' );
				wp_safe_redirect( admin_url( 'edit.php?post_type=kj_job&page=kj-add-jobs' ) );
				exit;
		}
		wp_safe_redirect( add_query_arg( array_filter( array( 'tab' => $tab ) ), admin_url( 'edit.php?post_type=kj_job&page=kj-review' ) ) );
		exit;
	}

	/**
	 * Publish with today's date, so the job counts as new for search filters and alerts.
	 */
	private static function publish( $id ) {
		wp_update_post(
			array(
				'ID'            => $id,
				'post_status'   => 'publish',
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
			)
		);
	}

	public static function handle_publish_green() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'kj_publish_green' );
		$ids = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'pending',
				'meta_key'       => '_kj_risk',
				'meta_value'     => 'green',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$done = 0;
		foreach ( $ids as $id ) {
			if ( get_post_meta( $id, '_kj_apply_url', true ) ) {
				self::publish( $id );
				$done++;
			}
		}
		self::flash( $done . ' job(s) published.' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=kj_job&page=kj-review' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* WhatsApp Channel post                                               */
	/* ------------------------------------------------------------------ */

	public static function page_whatsapp() {
		$hours = isset( $_GET['hours'] ) ? max( 1, min( 168, (int) $_GET['hours'] ) ) : 24; // phpcs:ignore WordPress.Security.NonceVerification
		$jobs  = get_posts(
			array(
				'post_type'      => 'kj_job',
				'post_status'    => 'publish',
				'posts_per_page' => 15,
				'date_query'     => array( array( 'after' => $hours . ' hours ago' ) ),
			)
		);

		$lines   = array( '*🌍 New Gulf & Abroad Jobs — ' . wp_date( 'j M Y' ) . '*', '' );
		$flags   = kj_country_flags();
		foreach ( $jobs as $job ) {
			$flag    = isset( $flags[ kj_term_slug( $job->ID, 'kj_country' ) ] ) ? $flags[ kj_term_slug( $job->ID, 'kj_country' ) ] : '🌍';
			$company = get_post_meta( $job->ID, '_kj_company', true );
			$salary  = kj_format_salary( $job->ID );
			$lines[] = $flag . ' *' . get_the_title( $job ) . '*' . ( $company ? ' — ' . $company : '' );
			$city    = get_post_meta( $job->ID, '_kj_city', true );
			$lines[] = '📍 ' . trim( $city . ', ' . kj_term_name( $job->ID, 'kj_country' ), ', ' ) . ( $salary ? ' | 💰 ' . $salary : '' );
			$lines[] = '👉 ' . get_permalink( $job );
			$lines[] = '';
		}
		$lines[] = '⚠️ Never pay money to get a job. Genuine employers do not charge fees.';
		$lines[] = '🔔 Daily job alerts: ' . get_permalink( (int) get_option( 'kj_page_alerts' ) );
		$text    = implode( "\n", $lines );
		?>
		<div class="wrap kj-wrap">
			<h1>WhatsApp Channel Post</h1>
			<p>Copy this message and paste it into your WhatsApp Channel or groups. It lists up to 15 jobs published in the last
				<?php foreach ( array( 24, 48, 168 ) as $h ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'hours', $h ) ); ?>" class="<?php echo $h === $hours ? 'kj-current' : ''; ?>"><?php echo esc_html( 168 === $h ? '7 days' : $h . ' hours' ); ?></a>
				<?php endforeach; ?>
			</p>
			<?php if ( ! $jobs ) : ?>
				<p class="kj-empty">No jobs published in this period yet.</p>
			<?php else : ?>
				<textarea id="kj-wa-text" class="kj-paste" rows="22" readonly><?php echo esc_textarea( $text ); ?></textarea>
				<p><button type="button" class="button button-primary" data-kj-copy="#kj-wa-text">Copy message</button></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function register_settings() {
		register_setting(
			'kj_settings',
			'kj_settings',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) )
		);
	}

	public static function sanitize_settings( $input ) {
		$old = wp_parse_args( get_option( 'kj_settings', array() ), kj_default_settings() );
		$out = $old;

		// Keep the stored key when the field is left blank.
		if ( ! empty( $input['api_key'] ) ) {
			$out['api_key'] = sanitize_text_field( $input['api_key'] );
		}
		if ( ! empty( $input['clear_api_key'] ) ) {
			$out['api_key'] = '';
		}
		$out['model']            = array_key_exists( $input['model'] ?? '', KJ_Claude::models() ) ? $input['model'] : 'claude-opus-5-5';
		$out['effort']           = in_array( $input['effort'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $input['effort'] : 'medium';
		$out['auto_publish']     = empty( $input['auto_publish'] ) ? 0 : 1;
		$out['job_schema']       = empty( $input['job_schema'] ) ? 0 : 1;
		$out['expiry_days']      = max( 3, min( 120, (int) ( $input['expiry_days'] ?? 30 ) ) );
		$out['cv_ai_daily']      = max( 1, min( 200, (int) ( $input['cv_ai_daily'] ?? 20 ) ) );
		$out['digest_hour']      = max( 0, min( 23, (int) ( $input['digest_hour'] ?? 8 ) ) );
		$out['whatsapp_channel'] = esc_url_raw( $input['whatsapp_channel'] ?? '' );
		$out['inr_rates']        = sanitize_textarea_field( $input['inr_rates'] ?? '' );

		$out['turnstile_site'] = sanitize_text_field( $input['turnstile_site'] ?? '' );
		if ( ! empty( $input['turnstile_secret'] ) ) {
			$out['turnstile_secret'] = sanitize_text_field( $input['turnstile_secret'] );
		}
		if ( ! empty( $input['clear_turnstile'] ) ) {
			$out['turnstile_site']   = '';
			$out['turnstile_secret'] = '';
		}

		// Ad code is HTML/JS from Google; only admins allowed to post raw HTML may save it unfiltered.
		foreach ( array( 'ad_results', 'ad_job', 'ad_sidebar' ) as $ad ) {
			if ( isset( $input[ $ad ] ) ) {
				$out[ $ad ] = current_user_can( 'unfiltered_html' ) ? trim( $input[ $ad ] ) : wp_kses_post( $input[ $ad ] );
			}
		}

		if ( (int) $old['digest_hour'] !== $out['digest_hour'] ) {
			// Reschedule the digest at the new hour.
			add_action( 'shutdown', array( 'KJ_Cron', 'reschedule_digest' ) );
		}
		return $out;
	}

	public static function page_settings() {
		$s          = wp_parse_args( get_option( 'kj_settings', array() ), kj_default_settings() );
		$key_in_cfg = defined( 'KJ_CLAUDE_API_KEY' ) && KJ_CLAUDE_API_KEY;
		$has_key    = '' !== kj_api_key();
		?>
		<div class="wrap kj-wrap">
			<h1>Urban Emirates Settings</h1>
			<?php
			if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
			}
			?>
			<form method="post" action="options.php">
				<?php settings_fields( 'kj_settings' ); ?>
				<h2>Claude AI</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kj-api-key">Claude API key</label></th>
						<td>
							<?php if ( $key_in_cfg ) : ?>
								<p>✅ Set in <code>wp-config.php</code> (KJ_CLAUDE_API_KEY).</p>
							<?php else : ?>
								<input type="password" id="kj-api-key" name="kj_settings[api_key]" class="regular-text" autocomplete="off" placeholder="<?php echo $has_key ? esc_attr( '•••••••• saved — leave blank to keep' ) : 'sk-ant-...'; ?>">
								<?php if ( $has_key ) : ?>
									<label><input type="checkbox" name="kj_settings[clear_api_key]" value="1"> Remove saved key</label>
								<?php endif; ?>
								<p class="description">Create one at console.anthropic.com → API Keys. Set a monthly spend limit there too.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kj-model">Model</label></th>
						<td>
							<select id="kj-model" name="kj_settings[model]">
								<?php foreach ( KJ_Claude::models() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Opus gives the most careful scam checks. Haiku costs a fraction as much and is usually fine for simple listings.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kj-effort">Thinking effort</label></th>
						<td>
							<select id="kj-effort" name="kj_settings[effort]">
								<?php foreach ( array( 'low', 'medium', 'high' ) as $e ) : ?>
									<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $s['effort'], $e ); ?>><?php echo esc_html( ucfirst( $e ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Higher = more careful but slower and costlier. Medium is a good default.</p>
						</td>
					</tr>
				</table>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kj-cv-ai">CV builder AI limit</label></th>
						<td><input type="number" id="kj-cv-ai" name="kj_settings[cv_ai_daily]" value="<?php echo (int) $s['cv_ai_daily']; ?>" min="1" max="200" class="small-text"> AI actions per person per day (keeps your Claude bill predictable)</td>
					</tr>
				</table>

				<h2>Publishing</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Auto-publish</th>
						<td><label><input type="checkbox" name="kj_settings[auto_publish]" value="1" <?php checked( $s['auto_publish'] ); ?>> Publish 🟢 green jobs immediately without review</label>
						<p class="description">Recommended OFF until you trust the checks.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="kj-expiry">Remove jobs after</label></th>
						<td><input type="number" id="kj-expiry" name="kj_settings[expiry_days]" value="<?php echo (int) $s['expiry_days']; ?>" min="3" max="120" class="small-text"> days (or on the application deadline, if earlier)</td>
					</tr>
					<tr>
						<th scope="row">Google Jobs</th>
						<td><label><input type="checkbox" name="kj_settings[job_schema]" value="1" <?php checked( $s['job_schema'] ); ?>> Add Google "JobPosting" data so jobs can appear in Google job search</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="kj-rates">Approx. INR rates</label></th>
						<td><textarea id="kj-rates" name="kj_settings[inr_rates]" rows="6" class="small-text kj-rates"><?php echo esc_textarea( $s['inr_rates'] ); ?></textarea>
						<p class="description">₹ per 1 unit, one per line (e.g. AED=24). Used to show "≈ ₹" next to salaries. Update occasionally.</p></td>
					</tr>
				</table>

				<h2>Alerts</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kj-hour">Daily email time</label></th>
						<td><input type="number" id="kj-hour" name="kj_settings[digest_hour]" value="<?php echo (int) $s['digest_hour']; ?>" min="0" max="23" class="small-text">:00 (site timezone: <?php echo esc_html( wp_timezone_string() ); ?>)</td>
					</tr>
					<tr>
						<th scope="row"><label for="kj-wa">WhatsApp Channel link</label></th>
						<td><input type="url" id="kj-wa" name="kj_settings[whatsapp_channel]" value="<?php echo esc_attr( $s['whatsapp_channel'] ); ?>" class="regular-text" placeholder="https://whatsapp.com/channel/...">
						<p class="description">Shown as a "Follow on WhatsApp" button on the site.</p></td>
					</tr>
				</table>

				<h2>Bot protection (Cloudflare Turnstile)</h2>
				<p class="description">Free from Cloudflare (dash.cloudflare.com → Turnstile → Add site → urbanemirates.com). Until both keys are set, forms use a hidden trap field and rate limits.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kj-ts-site">Site key</label></th>
						<td><input type="text" id="kj-ts-site" name="kj_settings[turnstile_site]" value="<?php echo esc_attr( $s['turnstile_site'] ); ?>" class="regular-text" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="kj-ts-secret">Secret key</label></th>
						<td>
							<input type="password" id="kj-ts-secret" name="kj_settings[turnstile_secret]" class="regular-text" autocomplete="off" placeholder="<?php echo $s['turnstile_secret'] ? esc_attr( '•••••••• saved — leave blank to keep' ) : ''; ?>">
							<?php if ( $s['turnstile_secret'] ) : ?>
								<label><input type="checkbox" name="kj_settings[clear_turnstile]" value="1"> Remove Turnstile keys</label>
							<?php endif; ?>
							<p class="description"><?php echo KJ_Accounts::turnstile_enabled() ? '✅ Turnstile is on.' : 'Turnstile is off.'; ?></p>
						</td>
					</tr>
				</table>

				<h2>Ads (optional)</h2>
				<p class="description">Paste Google AdSense ad unit code here once your site is approved. Leave empty for no ads. Ads never appear on the safety, sign-up, CV builder or account pages, or next to the Apply button.</p>
				<table class="form-table" role="presentation">
					<?php
					$ad_fields = array(
						'ad_results' => 'Search results (after the 5th job)',
						'ad_job'     => 'Job page (after "You need")',
						'ad_sidebar' => 'Desktop sidebar',
					);
					foreach ( $ad_fields as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="kj-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><textarea id="kj-<?php echo esc_attr( $key ); ?>" name="kj_settings[<?php echo esc_attr( $key ); ?>]" rows="4" class="large-text code"><?php echo esc_textarea( $s[ $key ] ); ?></textarea></td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Test Claude connection</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kj_test_claude">
				<?php wp_nonce_field( 'kj_test_claude' ); ?>
				<p>Sends one sample job to Claude to check your key and model work.</p>
				<?php submit_button( 'Run test', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'kj_test_claude' );
		$ok = KJ_Claude::test_connection();
		if ( true === $ok ) {
			self::flash( '✅ Claude is connected and working.' );
		} else {
			self::flash( '❌ ' . $ok->get_error_message(), 'error' );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=kj_job&page=kj-settings' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Job details box on the edit screen                                  */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'kj-details', 'Job details', array( __CLASS__, 'render_meta_box' ), 'kj_job', 'normal', 'high' );
	}

	private static function fields() {
		return array(
			'_kj_apply_url'       => array( 'Link to original job (Apply button)', 'url' ),
			'_kj_company'         => array( 'Company', 'text' ),
			'_kj_city'            => array( 'City', 'text' ),
			'_kj_experience'      => array( 'Experience', 'text' ),
			'_kj_salary_min'      => array( 'Salary from', 'number' ),
			'_kj_salary_max'      => array( 'Salary to', 'number' ),
			'_kj_salary_currency' => array( 'Currency (AED, SAR, QAR…)', 'text' ),
			'_kj_salary_period'   => array( 'Salary per', 'period' ),
			'_kj_salary_text'     => array( 'Salary note', 'text' ),
			'_kj_job_type'        => array( 'Job type', 'jobtype' ),
			'_kj_deadline'        => array( 'Application deadline', 'date' ),
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'kj_save_details', 'kj_details_nonce' );
		$risk = get_post_meta( $post->ID, '_kj_risk', true );
		if ( $risk ) {
			echo '<p><span class="kj-badge kj-badge-' . esc_attr( $risk ) . '">' . esc_html( kj_risk_label( $risk ) ) . '</span> ';
			echo esc_html( implode( ' · ', (array) get_post_meta( $post->ID, '_kj_risk_reasons', true ) ) ) . '</p>';
		}
		echo '<p class="description">The main text box above is the short summary shown to job seekers.</p>';
		echo '<div class="kj-fields">';
		foreach ( self::fields() as $key => $field ) {
			list( $label, $type ) = $field;
			$value                = get_post_meta( $post->ID, $key, true );
			echo '<p><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
			if ( 'period' === $type || 'jobtype' === $type ) {
				$options = 'period' === $type
					? array( 'month' => 'Month', 'year' => 'Year', 'day' => 'Day', 'hour' => 'Hour', 'unknown' => '—' )
					: kj_job_types();
				echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
				foreach ( $options as $k => $l ) {
					echo '<option value="' . esc_attr( $k ) . '"' . selected( $value, $k, false ) . '>' . esc_html( $l ) . '</option>';
				}
				echo '</select>';
			} else {
				$step = 'number' === $type ? ' step="any" min="0"' : '';
				echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="widefat"' . $step . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</p>';
		}
		echo '</div>';

		$benefits = (array) get_post_meta( $post->ID, '_kj_benefits', true );
		echo '<p><strong>Benefits:</strong> ';
		foreach ( kj_benefit_labels() as $k => $l ) {
			echo '<label class="kj-check"><input type="checkbox" name="_kj_benefits[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $benefits, true ), true, false ) . '> ' . esc_html( $l ) . '</label> ';
		}
		echo '</p>';

		$reqs = (array) get_post_meta( $post->ID, '_kj_requirements', true );
		echo '<p><label for="_kj_requirements"><strong>Key requirements</strong> (one per line)</label>';
		echo '<textarea id="_kj_requirements" name="_kj_requirements" rows="4" class="widefat">' . esc_textarea( implode( "\n", array_filter( $reqs ) ) ) . '</textarea></p>';

		$raw = get_post_meta( $post->ID, '_kj_raw', true );
		if ( $raw ) {
			echo '<details><summary>Original pasted text</summary><pre class="kj-raw">' . esc_html( $raw ) . '</pre></details>';
		}
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['kj_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kj_details_nonce'] ) ), 'kj_save_details' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( self::fields() as $key => $field ) {
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per type below.
			switch ( $field[1] ) {
				case 'url':
					$value = esc_url_raw( trim( $raw ) );
					break;
				case 'number':
					$value = max( 0, (float) $raw );
					break;
				case 'date':
					$value = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
					break;
				default:
					$value = sanitize_text_field( $raw );
			}
			if ( '_kj_salary_currency' === $key ) {
				$value = strtoupper( substr( $value, 0, 3 ) );
			}
			update_post_meta( $post_id, $key, $value );
		}

		$url = get_post_meta( $post_id, '_kj_apply_url', true );
		if ( $url ) {
			update_post_meta( $post_id, '_kj_url_hash', md5( kj_normalize_url( $url ) ) );
			update_post_meta( $post_id, '_kj_source', kj_source_name( $url ) );
		}

		$benefits = isset( $_POST['_kj_benefits'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['_kj_benefits'] ) ) : array();
		update_post_meta( $post_id, '_kj_benefits', array_values( array_intersect( $benefits, array_keys( kj_benefit_labels() ) ) ) );

		$reqs = isset( $_POST['_kj_requirements'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_kj_requirements'] ) ) : '';
		update_post_meta( $post_id, '_kj_requirements', array_values( array_filter( array_map( 'trim', explode( "\n", $reqs ) ) ) ) );

		// Deadline edits should move the expiry date too.
		KJ_Cron::set_expiry( $post_id );
	}

	/* ------------------------------------------------------------------ */
	/* One-off notices                                                     */
	/* ------------------------------------------------------------------ */

	private static function flash( $message, $type = 'success' ) {
		set_transient( 'kj_flash_' . get_current_user_id(), array( $message, $type ), 60 );
	}

	public static function notices() {
		$flash = get_transient( 'kj_flash_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'kj_flash_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $flash[1] ), esc_html( $flash[0] ) );
		}
	}
}
