<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( [ 'c2gs_settings', 'c2gs_google_token', 'c2gs_error_log' ] as $option ) {
	delete_option( $option );
}
foreach ( [ 'c2gs_tab_ready', 'c2gs_fail_count', 'c2gs_not_connected' ] as $transient ) {
	delete_transient( $transient );
}
