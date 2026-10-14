<?php
/**
 * Fallback template (posts, archives).
 */
get_header();
?>
<div class="ue-container ue-page">
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'ue-article' ); ?>>
				<?php if ( is_singular() ) : ?>
					<h1 class="ue-page-title"><?php the_title(); ?></h1>
				<?php else : ?>
					<h2 class="ue-page-title ue-page-title-sm"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<?php endif; ?>
				<div class="ue-content"><?php is_singular() ? the_content() : the_excerpt(); ?></div>
			</article>
		<?php endwhile; ?>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<h1 class="ue-page-title">Nothing here yet</h1>
		<p><a href="<?php echo esc_url( ue_jobs_url() ); ?>">Browse jobs</a></p>
	<?php endif; ?>
</div>
<?php
get_footer();
