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

		$sync = new SubmissionSync(
			$mapper,
			$log,
			$auth,
			$this->buildWriterFactory( $auth )
		);

		// Form Vibes covers CF7, WPForms, Elementor, Gravity, Ninja, WS Form,
		// Caldera, Bricks, Beaver Builder, Everest Forms through one hook.
		add_action( 'fv_after_entry_meta_success', [ new SubmissionListener( $sync ), 'handle' ] );

		// Direct adapters for popular forms Form Vibes 1.5.3 does not integrate.
		add_action( 'metform_after_store_form_data', [ new MetFormListener( $sync ), 'handle' ], 10, 4 );
		add_action( 'fluentform/submission_inserted', [ new FluentFormListener( $sync ), 'handle' ], 20, 3 );
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
