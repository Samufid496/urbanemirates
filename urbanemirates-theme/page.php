<?php
/**
 * Regular pages (Jobs, Job alerts, Scam safety, Privacy…).
 */
get_header();
?>
<div class="ue-container ue-page">
	<?php while ( have_posts() ) : the_post(); ?>
		<article <?php post_class( 'ue-article' ); ?>>
			<h1 class="ue-page-title"><?php the_title(); ?></h1>
			<div class="ue-content">
				<?php the_content(); ?>
			</div>
		</article>
	<?php endwhile; ?>
</div>
<?php
get_footer();
