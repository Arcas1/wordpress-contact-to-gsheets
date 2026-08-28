<?php
/**
 * Plugin Name:       Contact to GSheets
 * Description:        Sends Form Vibes submissions to a Google Sheet in real time.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       contact-to-gsheets
 *
 * @package C2GS
 */

defined( 'ABSPATH' ) || exit;

define( 'C2GS_FILE', __FILE__ );
define( 'C2GS_DIR', plugin_dir_path( __FILE__ ) );
define( 'C2GS_VERSION', '0.3.0' );

$c2gs_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $c2gs_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Contact to GSheets: vendor/autoload.php missing. Run composer install.', 'contact-to-gsheets' );
			echo '</p></div>';
		}
	);
	return;
}
require $c2gs_autoload;

add_action( 'plugins_loaded', static function () {
	\C2GS\Plugin::instance()->boot();
} );
