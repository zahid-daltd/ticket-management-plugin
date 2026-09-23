<?php
/**
 * Plugin Name:       Affiniti WP Support
 * Plugin URI:        https://dataaffiniti.com/plugins/affiniti-wp-support
 * Description:       Self-contained product service/complaint ticket system. Guest ticket form via shortcode, staff admin panel, and a versioned REST API (wpsd/v1) for mobile apps and partner systems. No external SaaS required.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Data Affiniti LTD
 * Author URI:        https://dataaffiniti.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       affiniti-wp-support
 * Domain Path:       /languages
 *
 * @package WPSD
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants. All prefixed to avoid collisions.
if ( ! defined( 'WPSD_VERSION' ) ) {
	define( 'WPSD_VERSION', '1.0.0' );
}
if ( ! defined( 'WPSD_PLUGIN_FILE' ) ) {
	define( 'WPSD_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WPSD_PLUGIN_DIR' ) ) {
	define( 'WPSD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPSD_PLUGIN_URL' ) ) {
	define( 'WPSD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WPSD_REST_NAMESPACE' ) ) {
	define( 'WPSD_REST_NAMESPACE', 'wpsd/v1' );
}
if ( ! defined( 'WPSD_DB_VERSION' ) ) {
	define( 'WPSD_DB_VERSION', '1.0.0' );
}

/**
 * Minimum environment checks (fail gracefully with an admin notice).
 *
 * @return bool True when the environment is supported.
 */
function wpsd_is_supported_environment() {
	global $wp_version;
	$php_ok = version_compare( PHP_VERSION, '8.0', '>=' );
	$wp_ok  = isset( $wp_version ) && version_compare( $wp_version, '6.4', '>=' );
	return $php_ok && $wp_ok;
}

/**
 * Show an admin notice when requirements are not met.
 */
function wpsd_requirements_notice() {
	if ( wpsd_is_supported_environment() ) {
		return;
	}
	?>
	<div class="notice notice-error"><p>
		<?php
		echo esc_html__(
			'Affiniti WP Support requires WordPress 6.4+ and PHP 8.0+. The plugin is inactive until requirements are met.',
			'affiniti-wp-support'
		);
		?>
	</p></div>
	<?php
}
add_action( 'admin_notices', 'wpsd_requirements_notice' );

/**
 * Load all plugin classes. Safe to include twice (require_once everywhere).
 */
function wpsd_load_includes() {
	$base = WPSD_PLUGIN_DIR . 'includes/';
	require_once $base . 'class-wpsd-db.php';
	require_once $base . 'class-wpsd-roles.php';
	require_once $base . 'class-wpsd-validator.php';
	require_once $base . 'class-wpsd-rate-limit.php';
	require_once $base . 'class-wpsd-api-auth.php';
	require_once $base . 'class-wpsd-lookups.php';
	require_once $base . 'class-wpsd-tickets.php';
	require_once $base . 'class-wpsd-attachments.php';
	require_once $base . 'class-wpsd-notifications.php';
	require_once $base . 'class-wpsd-rest.php';
	require_once $base . 'class-wpsd-admin.php';
	require_once $base . 'class-wpsd-public.php';
	require_once $base . 'class-wpsd-plugin.php';
}
wpsd_load_includes();

/**
 * Runs on plugin activation: creates tables, roles, options, seed data.
 */
function wpsd_activate() {
	require_once WPSD_PLUGIN_DIR . 'includes/class-wpsd-activator.php';
	WPSD_Activator::activate();
}
register_activation_hook( __FILE__, 'wpsd_activate' );

/**
 * Runs on plugin deactivation: flushes rewrite/rules caches, clears cron.
 */
function wpsd_deactivate() {
	require_once WPSD_PLUGIN_DIR . 'includes/class-wpsd-deactivator.php';
	WPSD_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'wpsd_deactivate' );

/**
 * Begins execution of the plugin.
 */
function wpsd_run() {
	if ( ! wpsd_is_supported_environment() ) {
		return;
	}
	$plugin = new WPSD_Plugin();
	$plugin->run();
}
wpsd_run();
