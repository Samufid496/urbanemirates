<?php
/**
 * Site footer.
 */
?>
</main>
<footer class="ue-footer">
	<div class="ue-container ue-footer-inner">
		<div class="ue-footer-brand">
			<?php echo ue_wordmark(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p>Checked Gulf jobs. Free CV builder.</p>
			<p class="ue-footer-note">We share jobs from other websites and always link to the original post. We are not the employer and never charge job seekers.</p>
		</div>
		<nav class="ue-footer-nav" aria-label="Footer">
			<?php
			if ( has_nav_menu( 'footer' ) ) {
				wp_nav_menu(
					array(
						'theme_location' => 'footer',
						'container'      => false,
						'menu_class'     => 'ue-footer-list',
						'depth'          => 1,
					)
				);
			} else {
				$links = array(
					array( 'Jobs', ue_jobs_url() ),
					array( 'CV builder', ue_page_url( 'kj_page_cv' ) ),
					array( 'Job alerts', ue_page_url( 'kj_page_alerts' ) ),
					array( 'Scam safety', ue_page_url( 'kj_page_safety' ) ),
				);
				$privacy = get_privacy_policy_url();
				if ( $privacy ) {
					$links[] = array( 'Privacy policy', $privacy );
				}
				echo '<ul class="ue-footer-list">';
				foreach ( $links as $link ) {
					echo '<li><a href="' . esc_url( $link[1] ) . '">' . esc_html( $link[0] ) . '</a></li>';
				}
				echo '</ul>';
			}
			?>
		</nav>
	</div>
	<div class="ue-container ue-footer-bottom">
		<span>© <?php echo esc_html( wp_date( 'Y' ) ); ?> Urban Emirates</span>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
