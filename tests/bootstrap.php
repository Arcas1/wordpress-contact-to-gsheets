<?php

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

// Minimal stand-in so SubmissionListener's "Elementor Pro is active" guard can
// be exercised. ElementorListener itself is tested directly, not through this.
if ( ! class_exists( 'ElementorPro\Modules\Forms\Module' ) ) {
	eval( 'namespace ElementorPro\Modules\Forms; class Module {}' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
