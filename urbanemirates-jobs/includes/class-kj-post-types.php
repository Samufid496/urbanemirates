<?php
/**
 * The "Job" post type plus Job Category and Country taxonomies.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Post_Types {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_post_type(
			'kj_job',
			array(
				'labels'       => array(
					'name'          => 'Jobs',
					'singular_name' => 'Job',
					'add_new_item'  => 'Add Job',
					'edit_item'     => 'Edit Job',
					'all_items'     => 'All Jobs',
					'menu_name'     => 'Urban Emirates',
				),
				'public'       => true,
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'job' ),
				'menu_icon'    => 'dashicons-portfolio',
				'supports'     => array( 'title', 'editor', 'custom-fields' ),
				'show_in_rest' => false,
			)
		);

		register_taxonomy(
			'kj_category',
			'kj_job',
			array(
				'labels'            => array(
					'name'          => 'Job Categories',
					'singular_name' => 'Job Category',
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'jobs-in' ),
			)
		);

		register_taxonomy(
			'kj_country',
			'kj_job',
			array(
				'labels'            => array(
					'name'          => 'Countries',
					'singular_name' => 'Country',
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'jobs-country' ),
			)
		);
	}

	public static function seed_terms() {
		foreach ( kj_categories() as $slug => $name ) {
			if ( ! term_exists( $slug, 'kj_category' ) ) {
				wp_insert_term( $name, 'kj_category', array( 'slug' => $slug ) );
			}
		}
		foreach ( kj_countries() as $slug => $name ) {
			if ( ! term_exists( $slug, 'kj_country' ) ) {
				wp_insert_term( $name, 'kj_country', array( 'slug' => $slug ) );
			}
		}
	}
}
