<?php
/**
 * Appends one row to a Google Sheet tab over the Sheets REST API v4.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Appends a single row to a Google Sheet tab over the Sheets REST API v4,
 * creating the tab and header row on first use. Values are written RAW so a
 * submission starting with "=" is never evaluated as a formula.
 */
class SheetsWriter {

	public const HEADER          = [ 'timestamp', 'form', 'name', 'email', 'message', 'data' ];
	public const READY_TRANSIENT = 'c2gs_tab_ready';
	private const READY_TTL      = 600;
	private const BASE           = 'https://sheets.googleapis.com/v4/spreadsheets/';

	public function __construct(
		private GoogleAuth $auth,
		private string $spreadsheetId,
		private string $tabName
	) {}

	/**
	 * @param array{0:string,1:string,2:string,3:string,4:string,5:string} $row
	 * @throws ApiException
	 */
	public function append( array $row ): void {
		$this->ensureReady();

		Http::json(
			'POST',
			$this->valuesUrl( 'A:F', ':append', [ 'valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS' ] ),
			$this->auth->accessToken(),
			[ 'values' => [ array_values( $row ) ] ]
		);
	}

	private function ensureReady(): void {
		if ( get_transient( self::READY_TRANSIENT ) ) {
			return;
		}
		$this->ensureTab();
		$this->ensureHeader();
		set_transient( self::READY_TRANSIENT, 1, self::READY_TTL );
	}

	private function ensureTab(): void {
		$token = $this->auth->accessToken();
		$meta  = Http::json( 'GET', self::BASE . rawurlencode( $this->spreadsheetId ) . '?fields=sheets.properties.title', $token );

		foreach ( $meta['sheets'] ?? [] as $sheet ) {
			if ( ( $sheet['properties']['title'] ?? null ) === $this->tabName ) {
				return;
			}
		}

		try {
			Http::json(
				'POST',
				self::BASE . rawurlencode( $this->spreadsheetId ) . ':batchUpdate',
				$token,
				[ 'requests' => [ [ 'addSheet' => [ 'properties' => [ 'title' => $this->tabName ] ] ] ] ]
			);
		} catch ( ApiException $e ) {
			// A concurrent submission may have created the tab first.
			if ( false === stripos( $e->getMessage(), 'already exists' ) ) {
				throw $e;
			}
		}
	}

	private function ensureHeader(): void {
		$token    = $this->auth->accessToken();
		$existing = Http::json( 'GET', $this->valuesUrl( 'A1:F1' ), $token );
		$values   = $existing['values'] ?? [];
		if ( ! empty( $values ) && ! empty( $values[0] ) ) {
			return;
		}

		Http::json(
			'PUT',
			$this->valuesUrl( 'A1:F1', '', [ 'valueInputOption' => 'RAW' ] ),
			$token,
			[ 'values' => [ self::HEADER ] ]
		);
	}

	/**
	 * Build a Sheets values URL for a cell range in the configured tab.
	 * Single-quoting the tab title is valid for any name and required when it
	 * contains spaces or punctuation.
	 *
	 * @param array<string,string> $query
	 */
	private function valuesUrl( string $a1, string $suffix = '', array $query = [] ): string {
		$range = "'" . str_replace( "'", "''", $this->tabName ) . "'!" . $a1;
		$url   = self::BASE . rawurlencode( $this->spreadsheetId ) . '/values/' . rawurlencode( $range ) . $suffix;
		return $query ? $url . '?' . http_build_query( $query ) : $url;
	}
}
