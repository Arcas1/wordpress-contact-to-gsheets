<?php

namespace C2GS;

use Google\Service\Exception as GoogleServiceException;

/**
 * Handles fv_after_entry_meta_success: map the submission and append it
 * to the sheet. Never throws into WordPress.
 */
final class SubmissionListener {

	public function __construct(
		private FieldMapper $mapper,
		private ErrorLog $log,
		private GoogleAuth $auth,
		private \Closure $writerFactory
	) {}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function handle( array $payload ): void {
		try {
			$settings      = get_option( 'c2gs_settings', [] );
			$spreadsheetId = is_array( $settings ) ? (string) ( $settings['spreadsheet_id'] ?? '' ) : '';
			if ( '' === $spreadsheetId ) {
				set_transient( 'c2gs_not_connected', 1, DAY_IN_SECONDS );
				return;
			}

			$entryData  = (array) ( $payload['entry_data'] ?? [] );
			$postedData = $entryData['posted_data'] ?? [];
			if ( ! is_array( $postedData ) || [] === $postedData ) {
				return;
			}

			$pluginName = (string) ( $payload['plugin_name'] ?? '' );
			$formId     = $payload['form_id'] ?? '';
			$title      = (string) ( $entryData['title'] ?? '' );

			$row = $this->mapper->toRow(
				$pluginName,
				is_scalar( $formId ) ? $formId : '',
				$title,
				$postedData,
				wp_date( 'c' )
			);

			$this->appendWithRetry( $row );
		} catch ( \Throwable $e ) {
			$this->log->add( [
				'form_id'     => $payload['form_id'] ?? null,
				'plugin_name' => (string) ( $payload['plugin_name'] ?? '' ),
				'http_code'   => $e instanceof GoogleServiceException ? (int) $e->getCode() : 0,
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
		} catch ( GoogleServiceException $e ) {
			if ( 401 !== (int) $e->getCode() ) {
				throw $e;
			}
			$this->auth->forceRefresh();
			( $this->writerFactory )()->append( $row );
		}
	}
}
