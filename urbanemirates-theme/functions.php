<?php
/**
 * Urban Emirates theme setup. The job features live in the Urban Emirates Jobs plugin;
 * this theme provides the page chrome (header, footer, home page) in the Direction D design.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UE_THEME_VERSION', '1.0.0' );

add_action( 'after_setup_theme', 'ue_setup' );
function ue_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'responsive-embeds' );
	// Tells the plugin this theme prints job pages itself (single-kj_job.php).
	add_theme_support( 'kj-job-templates' );

	register_nav_menus(
		array(
			'primary' => 'Main menu',
			'footer'  => 'Footer menu',
		)
	);
}

add_action( 'wp_enqueue_scripts', 'ue_assets' );
function ue_assets() {
	wp_enqueue_style( 'ue-theme', get_stylesheet_uri(), array(), UE_THEME_VERSION );
}

// Preload the main font file so text paints in Figtree straight away.
add_action( 'wp_head', 'ue_preload_font', 1 );
function ue_preload_font() {
	echo '<link rel="preload" href="' . esc_url( get_template_directory_uri() . '/assets/fonts/figtree-latin.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
}

// Lighter pages: no emoji scripts.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );

/**
 * Small helpers that work even if the plugin is switched off.
 */
function ue_page_url( $option ) {
	$id = (int) get_option( $option );
	return $id ? get_permalink( $id ) : home_url( '/' );
}

function ue_jobs_url( $args = array() ) {
	return class_exists( 'KJ_Frontend' ) ? KJ_Frontend::jobs_url( $args ) : home_url( '/' );
}

function ue_whatsapp_channel() {
	return function_exists( 'kj_setting' ) ? (string) kj_setting( 'whatsapp_channel' ) : '';
}

function ue_icon( $name ) {
	return function_exists( 'kj_icon' ) ? kj_icon( $name ) : '';
}

/**
 * Default menu when none has been set up in Appearance → Menus.
 */
function ue_default_menu_items() {
	return array(
		array( 'Jobs', ue_jobs_url() ),
		array( 'Countries', home_url( '/#countries' ) ),
		array( 'Scam safety', ue_page_url( 'kj_page_safety' ) ),
	);
}

function ue_nav( $location, $class ) {
	if ( has_nav_menu( $location ) ) {
		wp_nav_menu(
			array(
				'theme_location' => $location,
				'container'      => false,
				'menu_class'     => $class,
				'depth'          => 1,
			)
		);
		return;
	}
	echo '<ul class="' . esc_attr( $class ) . '">';
	foreach ( ue_default_menu_items() as $item ) {
		echo '<li><a href="' . esc_url( $item[1] ) . '">' . esc_html( $item[0] ) . '</a></li>';
	}
	echo '</ul>';
}

function ue_wordmark( $tag = 'a' ) {
	$inner = '<span class="ue-wordmark-a">urban</span><span class="ue-wordmark-b">emirates</span>';
	if ( 'a' === $tag ) {
		return '<a class="ue-wordmark" href="' . esc_url( home_url( '/' ) ) . '" aria-label="Urban Emirates home">' . $inner . '</a>';
	}
	return '<span class="ue-wordmark">' . $inner . '</span>';
}

/**
 * Site search goes to the job search instead of the blog search.
 */
add_action( 'template_redirect', 'ue_redirect_search' );
function ue_redirect_search() {
	if ( is_search() && class_exists( 'KJ_Frontend' ) ) {
		wp_safe_redirect( ue_jobs_url( array( 'q' => get_search_query() ) ) );
		exit;
	}
}
