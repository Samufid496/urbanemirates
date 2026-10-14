<?php
/**
 * Plugin Name:       Urban Emirates Jobs
 * Description:       Urban Emirates (urbanemirates.com): checked Gulf jobs for the Konkani community. Paste jobs you find, Claude checks them for scams and fills in the details, you review and publish. Includes search, filters and daily email alerts.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Urban Emirates
 * License:           GPL-2.0-or-later
 * Text Domain:       urbanemirates-jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KJ_VERSION', '1.0.0' );
define( 'KJ_FILE', __FILE__ );
define( 'KJ_DIR', plugin_dir_path( __FILE__ ) );
define( 'KJ_URL', plugin_dir_url( __FILE__ ) );

require_once KJ_DIR . 'includes/helpers.php';
require_once KJ_DIR . 'includes/class-kj-post-types.php';
require_once KJ_DIR . 'includes/class-kj-claude.php';
require_once KJ_DIR . 'includes/class-kj-importer.php';
require_once KJ_DIR . 'includes/class-kj-admin.php';
require_once KJ_DIR . 'includes/class-kj-frontend.php';
require_once KJ_DIR . 'includes/class-kj-alerts.php';
require_once KJ_DIR . 'includes/class-kj-cron.php';
require_once KJ_DIR . 'includes/class-kj-accounts.php';

KJ_Post_Types::init();
KJ_Importer::init();
KJ_Admin::init();
KJ_Frontend::init();
KJ_Alerts::init();
KJ_Cron::init();
KJ_Accounts::init();

// Bump when install steps change; sites upgrade themselves on the next page load.
define( 'KJ_SCHEMA', 2 );
add_action( 'init', 'kj_maybe_upgrade', 20 );

register_activation_hook( __FILE__, 'kj_activate' );
register_deactivation_hook( __FILE__, 'kj_deactivate' );

function kj_activate() {
	KJ_Post_Types::register();
	kj_install();
	KJ_Cron::schedule();
	flush_rewrite_rules();
}

/**
 * Everything that must exist for the plugin to work. Safe to run again.
 */
function kj_install() {
	KJ_Post_Types::seed_terms();
	KJ_Alerts::create_table();
	KJ_Frontend::create_pages();
	KJ_Accounts::add_role();
	KJ_Accounts::create_pages();
	if ( false === get_option( 'kj_settings' ) ) {
		add_option( 'kj_settings', kj_default_settings() );
	}
	update_option( 'kj_schema', KJ_SCHEMA );
}

function kj_maybe_upgrade() {
	if ( (int) get_option( 'kj_schema' ) < KJ_SCHEMA ) {
		kj_install();
		flush_rewrite_rules( false );
	}
}

function kj_deactivate() {
	KJ_Cron::unschedule();
	flush_rewrite_rules();
}
