<?php
/**
 * Plugin Name: Sushant Media Scanner
 * Plugin URI: https://thesushant.in/sushant-media-scanner
 * Description: Scan your Media Library and detect where images are used across posts, pages, featured images, and custom fields. Shows usage status with "where used" links.
 * Version: 1.2.0
 * Author: Sushant
 * Author URI: https://thesushant.in/sushant
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sushant-media-scanner
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SMSCAN_VERSION', '1.2.0' );
define( 'SMSCAN_FILE', __FILE__ );
define( 'SMSCAN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMSCAN_URL', plugin_dir_url( __FILE__ ) );

// Load core classes
require_once SMSCAN_PATH . 'includes/class-smscan-core.php';
require_once SMSCAN_PATH . 'includes/class-smscan-scanner.php';
require_once SMSCAN_PATH . 'includes/class-smscan-admin.php';
require_once SMSCAN_PATH . 'includes/class-smscan-ajax.php';
require_once SMSCAN_PATH . 'includes/class-smscan-export.php';
require_once SMSCAN_PATH . 'includes/class-smscan-telemetry.php';

/**
 * Initialize the plugin.
 */
function smscan_init() {
	$core    = new Smscan_Core();
	$scanner = new Smscan_Scanner( $core );
	
	new Smscan_Admin( $core, $scanner );
	new Smscan_Ajax( $core, $scanner );
	new Smscan_Export( $core );
	new Smscan_Telemetry( $core );
}
add_action( 'plugins_loaded', 'smscan_init' );

// Register activation hook
register_activation_hook( __FILE__, 'smscan_activate' );
function smscan_activate() {
	$core = new Smscan_Core();
	$core->activate();
}