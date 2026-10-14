<?php
/**
 * Home page: hero search, popular searches, countries, latest jobs, alerts band.
 */
get_header();
$has_jobs = class_exists( 'KJ_Frontend' );
?>
<section class="ue-hero">
	<div class="ue-container ue-hero-inner">
		<h1 class="ue-hero-title">Find your next job in the Gulf</h1>
		<p class="ue-hero-sub">Checked Gulf jobs. Free CV builder.</p>
		<?php if ( $has_jobs ) : ?>
			<div class="ue-hero-search">
				<?php echo KJ_Frontend::render_search_form(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<div class="ue-hero-chips">
				<?php echo KJ_Frontend::render_chips(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		<?php else : ?>
			<p class="ue-notice">Activate the Urban Emirates Jobs plugin to show job search.</p>
		<?php endif; ?>
	</div>
</section>

<?php if ( $has_jobs ) : ?>
	<section class="ue-section" id="countries">
		<div class="ue-container">
			<h2 class="ue-section-title ue-center">Jobs by country</h2>
			<?php echo KJ_Frontend::render_countries(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
	</section>

	<?php
	$for_you = is_user_logged_in() && class_exists( 'KJ_Alerts' ) ? KJ_Alerts::jobs_for_user( get_current_user_id(), 4 ) : array();
	if ( $for_you ) :
		?>
		<section class="ue-section">
			<div class="ue-container">
				<div class="ue-section-head">
					<h2 class="ue-section-title">Jobs for you</h2>
					<span class="ue-muted">Based on your CV</span>
				</div>
				<div class="kj"><div class="kj-grid">
					<?php foreach ( $for_you as $job ) : ?>
						<?php echo KJ_Frontend::card( $job->ID ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php endforeach; ?>
				</div></div>
			</div>
		</section>
	<?php endif; ?>

	<section class="ue-section">
		<div class="ue-container">
			<div class="ue-section-head">
				<h2 class="ue-section-title">Latest jobs</h2>
				<a class="ue-link-strong" href="<?php echo esc_url( ue_jobs_url() ); ?>">View all jobs</a>
			</div>
			<?php echo KJ_Frontend::render_latest( 6 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
	</section>

	<section class="ue-section">
		<div class="ue-container">
			<div class="ue-band ue-band-cv">
				<div>
					<h2 class="ue-band-title">Free CV builder for Gulf jobs</h2>
					<p>Step by step, with AI help. Download as PDF or Word.</p>
				</div>
				<div class="ue-band-actions">
					<a class="ue-btn ue-btn-primary" href="<?php echo esc_url( ue_page_url( 'kj_page_cv' ) ); ?>">Build my CV</a>
				</div>
			</div>
		</div>
	</section>

	<section class="ue-section">
		<div class="ue-container">
			<div class="ue-band">
				<div>
					<h2 class="ue-band-title">Never miss a job</h2>
					<p>New jobs by email every morning, or follow us on WhatsApp.</p>
				</div>
				<div class="ue-band-actions">
					<a class="ue-btn ue-btn-outline" href="<?php echo esc_url( ue_page_url( 'kj_page_alerts' ) ); ?>">Get job alerts</a>
					<?php if ( ue_whatsapp_channel() ) : ?>
						<a class="ue-btn ue-btn-wa" href="<?php echo esc_url( ue_whatsapp_channel() ); ?>" target="_blank" rel="noopener"><?php echo ue_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> WhatsApp channel</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</section>
<?php endif; ?>
<?php
get_footer();
