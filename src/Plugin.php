<?php

namespace C2GS;

use Google\Service\Sheets;

final class Plugin {

	private static ?Plugin $instance = null;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$settings = get_option( 'c2gs_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		$log    = new ErrorLog();
		$mapper = new FieldMapper();

		$auth = new GoogleAuth(
			(string) ( $settings['client_id'] ?? '' ),
			(string) ( $settings['client_secret'] ?? '' ),
			admin_url( 'admin-post.php?action=' . GoogleAuth::CALLBACK_ACTION )
		);

		( new Settings( $auth, $log ) )->register();

		$listener = new SubmissionListener(
			$mapper,
			$log,
			$auth,
			$this->buildWriterFactory( $auth )
		);

		add_action( 'fv_after_entry_meta_success', [ $listener, 'handle' ] );
	}

	public function buildWriterFactory( GoogleAuth $auth ): \Closure {
		return static function () use ( $auth ): SheetsWriter {
			$settings      = get_option( 'c2gs_settings', [] );
			$settings      = is_array( $settings ) ? $settings : [];
			$spreadsheetId = (string) ( $settings['spreadsheet_id'] ?? '' );
			$tabName       = (string) ( $settings['tab_name'] ?? 'Submissions' );
			if ( '' === $spreadsheetId ) {
				throw new \RuntimeException( 'No spreadsheet configured' );
			}
			if ( '' === $tabName ) {
				$tabName = 'Submissions';
			}
			$service = new Sheets( $auth->authedClient() );
			return new SheetsWriter( $service, $spreadsheetId, $tabName );
		};
	}
}
