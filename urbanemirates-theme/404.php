<?php
/**
 * Not found: send people back to the job search.
 */
get_header();
?>
<div class="ue-container ue-page ue-center">
	<h1 class="ue-page-title">This page isn't here</h1>
	<p class="ue-muted">The job may have expired or been removed. Try a new search:</p>
	<?php if ( class_exists( 'KJ_Frontend' ) ) : ?>
		<div class="ue-404-search"><?php echo KJ_Frontend::render_search_form(); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<?php endif; ?>
</div>
<?php
get_footer();
