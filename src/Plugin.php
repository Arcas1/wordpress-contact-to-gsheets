<?php
/**
 * Bootstrap: wires the settings page and every form-source hook.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

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

		// Form Vibes covers CF7, WPForms, Gravity, Ninja, WS Form, Caldera,
		// Bricks, Beaver Builder, Everest Forms through one hook.
		add_action( 'fv_after_entry_meta_success', [ new SubmissionListener( $sync ), 'handle' ] );

		// Direct adapters for forms Form Vibes 1.5.3 does not integrate, or
		// integrates without field labels (Elementor).
		add_action( 'elementor_pro/forms/new_record', [ new ElementorListener( $sync ), 'handle' ], 20, 2 );
		add_action( 'metform_after_store_form_data', [ new MetFormListener( $sync ), 'handle' ], 10, 4 );
		add_action( 'fluentform/submission_inserted', [ new FluentFormListener( $sync ), 'handle' ], 20, 3 );
		add_action( 'forminator_custom_form_submit_before_set_fields', [ new ForminatorListener( $sync ), 'handle' ], 20, 3 );
		add_action( 'frm_after_create_entry', [ new FormidableListener( $sync ), 'handle' ], 20, 3 );
		add_action( 'grunion_after_feedback_post_inserted', [ new JetpackListener( $sync ), 'handle' ], 20, 4 );
	}

	public function buildWriterFactory( GoogleAuth $auth ): \Closure {
		return static function () use ( $auth ): SheetsWriter {
			$settings      = get_option( 'c2gs_settings', [] );
			$settings      = is_array( $settings ) ? $settings : [];
			$spreadsheetId = (string) ( $settings['spreadsheet_id'] ?? '' );
			$tabName       = (string) ( $settings['tab_name'] ?? 'Submissions' );
			if ( '' === $spreadsheetId ) {
				throw new ApiException( 'No spreadsheet configured', 0 );
			}
			if ( '' === $tabName ) {
				$tabName = 'Submissions';
			}
			return new SheetsWriter( $auth, $spreadsheetId, $tabName );
		};
	}
}
