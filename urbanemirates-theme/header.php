<?php
/**
 * Site header: wordmark, menu, Job alerts / WhatsApp buttons. On phones the menu folds into a disclosure.
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#FFFFFF">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="ue-skip" href="#main">Skip to content</a>
<header class="ue-header">
	<div class="ue-container ue-header-inner">
		<?php echo ue_wordmark(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<nav class="ue-nav" aria-label="Main">
			<?php ue_nav( 'primary', 'ue-nav-list' ); ?>
		</nav>

		<div class="ue-header-actions">
			<?php ue_account_link( 'ue-header-link' ); ?>
			<a class="ue-btn ue-btn-outline ue-btn-sm" href="<?php echo esc_url( ue_page_url( 'kj_page_alerts' ) ); ?>">Job alerts</a>
			<?php if ( ue_whatsapp_channel() ) : ?>
				<a class="ue-btn ue-btn-wa ue-btn-sm ue-hide-sm" href="<?php echo esc_url( ue_whatsapp_channel() ); ?>" target="_blank" rel="noopener"><?php echo ue_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> WhatsApp</a>
			<?php endif; ?>
		</div>

		<details class="ue-menu">
			<summary class="ue-menu-toggle" aria-label="Menu">
				<span class="ue-menu-open"><?php echo ue_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<span class="ue-menu-close"><?php echo ue_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			</summary>
			<div class="ue-menu-panel">
				<?php ue_nav( 'primary', 'ue-menu-list' ); ?>
				<?php ue_account_link( 'ue-btn ue-btn-primary' ); ?>
				<a class="ue-btn ue-btn-outline" href="<?php echo esc_url( ue_page_url( 'kj_page_alerts' ) ); ?>">Job alerts</a>
				<?php if ( ue_whatsapp_channel() ) : ?>
					<a class="ue-btn ue-btn-wa" href="<?php echo esc_url( ue_whatsapp_channel() ); ?>" target="_blank" rel="noopener"><?php echo ue_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> WhatsApp channel</a>
				<?php endif; ?>
			</div>
		</details>
	</div>
</header>
<main id="main" class="ue-main">
