<?php
/**
 * Shared pipeline: guard config, map fields, append to the sheet, log failures.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Shared pipeline for every submission source: guard configuration, map the
 * fields to the fixed row, append to the sheet (with one 401 retry), and log
 * failures. Never throws.
 */
class SubmissionSync {

	public function __construct(
		private FieldMapper $mapper,
		private ErrorLog $log,
		private GoogleAuth $auth,
		private \Closure $writerFactory
	) {}

	/**
	 * @param string              $source A short label for the "form" column prefix (e.g. "cf7", "metform").
	 * @param string|int           $formId
	 * @param string              $title  Form title; empty falls back to "<source> #<id>".
	 * @param array<string,mixed> $fields field name => value (string or array).
	 */
	public function sync( string $source, string|int $formId, string $title, array $fields, ?string $emailKeyHint = null ): void {
		try {
			$settings      = get_option( 'c2gs_settings', [] );
			$spreadsheetId = is_array( $settings ) ? (string) ( $settings['spreadsheet_id'] ?? '' ) : '';
			if ( '' === $spreadsheetId ) {
				set_transient( 'c2gs_not_connected', 1, DAY_IN_SECONDS );
				return;
			}
			if ( [] === $fields ) {
				return;
			}

			$row = $this->mapper->toRow( $source, $formId, $title, $fields, wp_date( 'c' ), $emailKeyHint );
			$this->appendWithRetry( $row );
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

	/**
	 * @param array{0:string,1:string,2:string,3:string,4:string,5:string} $row
	 */
	private function appendWithRetry( array $row ): void {
		try {
			( $this->writerFactory )()->append( $row );
		} catch ( ApiException $e ) {
			if ( 401 !== (int) $e->getCode() ) {
				throw $e;
			}
			$this->auth->forceRefresh();
			( $this->writerFactory )()->append( $row );
		}
	}
}
