<?php
/**
 * Plugin Name:       Contact to GSheets
 * Plugin URI:        https://github.com/Arcas1/wordpress-contact-to-gsheets
 * Description:        Sends WordPress form submissions to a Google Sheet in real time, one row per submission. Works with Form Vibes (CF7, WPForms, Elementor, Gravity, Ninja, WS Form, and more) plus MetForm, Fluent Forms, Forminator, Formidable Forms, and the Jetpack contact form.
 * Version:           0.6.0
 * Requires at least: 6.0
 * Tested up to:      6.8
 * Requires PHP:      8.1
 * Author:            Arcas1
 * Author URI:        https://github.com/Arcas1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contact-to-gsheets
 *
 * @package C2GS
 *
 * Contact to GSheets is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option) any
 * later version. It is distributed WITHOUT ANY WARRANTY; without even the
 * implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See
 * https://www.gnu.org/licenses/gpl-2.0.html for details.
 */

defined( 'ABSPATH' ) || exit;

define( 'C2GS_FILE', __FILE__ );
define( 'C2GS_DIR', plugin_dir_path( __FILE__ ) );
define( 'C2GS_VERSION', '0.6.0' );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'C2GS\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( 'C2GS\\' ) ) );
		$path     = __DIR__ . '/src/' . $relative . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

add_action( 'init', static function () {
	load_plugin_textdomain( 'contact-to-gsheets', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

add_action( 'plugins_loaded', static function () {
	\C2GS\Plugin::instance()->boot();
} );
