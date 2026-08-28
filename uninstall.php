<?php
/**
 * Uninstall cleanup: remove every option and transient the plugin creates.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( [ 'c2gs_settings', 'c2gs_google_token', 'c2gs_error_log', 'c2gs_columns' ] as $option ) {
	delete_option( $option );
}
foreach ( [ 'c2gs_tab_ready', 'c2gs_columns_synced', 'c2gs_fail_count', 'c2gs_not_connected' ] as $transient ) {
	delete_transient( $transient );
}
