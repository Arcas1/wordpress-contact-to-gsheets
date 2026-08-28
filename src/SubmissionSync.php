<?php
/**
 * Shared pipeline: pick the form label, normalise fields, append, log failures.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Every submission source funnels through here: resolve the value for the
 * "form" column (name, else page URL, else referer, else source #id),
 * normalise the fields, append the row (one 401 retry), and log any failure.
 * Never throws.
 */
class SubmissionSync {

	public function __construct(
		private FieldMapper $mapper,
		private ErrorLog $log,
		private GoogleAuth $auth,
		private \Closure $writerFactory
	) {}

	/**
	 * @param string              $source Short label, e.g. "elementor", "metform".
	 * @param string|int          $formId
	 * @param string              $title  Form name if the source knows it.
	 * @param array<string,mixed>  $fields key/label => value.
	 * @param string              $url    Page URL the form was submitted from, if known.
	 */
	public function sync( string $source, string|int $formId, string $title, array $fields, string $url = '' ): void {
		try {
			$settings      = get_option( 'c2gs_settings', [] );
			$spreadsheetId = is_array( $settings ) ? (string) ( $settings['spreadsheet_id'] ?? '' ) : '';
			if ( '' === $spreadsheetId ) {
				set_transient( 'c2gs_not_connected', 1, DAY_IN_SECONDS );
				return;
			}

			$map = $this->mapper->normalize( $fields );
			if ( [] === $map ) {
				return;
			}

			$this->appendWithRetry( $this->formLabel( $source, $formId, $title, $url ), $map );
			delete_transient( 'c2gs_not_connected' );
		} catch ( \Throwable $e ) {
			$this->log->add( [
				'form_id'     => $formId,
				'plugin_name' => $source,
				'http_code'   => $e instanceof ApiException ? (int) $e->getCode() : 0,
				'message'     => $e->getMessage(),
			] );
			$count = (int) get_transient( 'c2gs_fail_count' );
			set_transient( 'c2gs_fail_count', $count + 1, WEEK_IN_SECONDS );
		}
	}

	private function formLabel( string $source, string|int $formId, string $title, string $url ): string {
		if ( '' !== trim( $title ) ) {
			return trim( $title );
		}
		if ( '' !== trim( $url ) ) {
			return trim( $url );
		}
		$referer = function_exists( 'wp_get_referer' ) ? wp_get_referer() : '';
		if ( is_string( $referer ) && '' !== $referer ) {
			return $referer;
		}
		return $source . ' #' . $formId;
	}

	/**
	 * @param array<string,string> $map
	 */
	private function appendWithRetry( string $form, array $map ): void {
		try {
			( $this->writerFactory )()->append( $form, $map );
		} catch ( ApiException $e ) {
			if ( 401 !== (int) $e->getCode() ) {
				throw $e;
			}
			$this->auth->forceRefresh();
			( $this->writerFactory )()->append( $form, $map );
		}
	}
}
