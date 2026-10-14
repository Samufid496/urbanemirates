<?php
/**
 * A single job, rendered by the plugin in the approved one-column layout.
 * A desktop sidebar appears only when sidebar ad code is set.
 */
get_header();
$ad = function_exists( 'kj_ad_slot' ) ? kj_ad_slot( 'sidebar' ) : '';
?>
<div class="ue-container ue-page <?php echo $ad ? 'ue-with-sidebar' : ''; ?>">
	<div class="ue-page-main">
		<a class="ue-back" href="<?php echo esc_url( ue_jobs_url() ); ?>"><?php echo ue_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> All jobs</a>
		<?php
		while ( have_posts() ) {
			the_post();
			echo class_exists( 'KJ_Frontend' ) ? KJ_Frontend::render_job( get_the_ID() ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
	</div>
	<?php if ( $ad ) : ?>
		<div class="ue-sidebar"><?php echo $ad; // phpcs:ignore WordPress.Security.EscapeOutput -- admin-provided ad code. ?></div>
	<?php endif; ?>
</div>
<?php
get_footer();
