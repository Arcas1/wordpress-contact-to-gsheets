<?php
/**
 * Appends one row to a single Google Sheet tab with a dynamic column set.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * One tab, one row per submission. Column A is the form (name / URL); every
 * other column is a form field, added to the header the first time it is
 * seen. Values are written RAW so a submission starting with "=" is never
 * evaluated as a formula.
 */
class SheetsWriter {

	public const FORM_COLUMN     = 'form';
	public const COLUMNS_OPTION  = 'c2gs_columns';
	public const SYNCED_TRANSIENT = 'c2gs_columns_synced';
	private const SYNCED_TTL      = 600;
	private const BASE            = 'https://sheets.googleapis.com/v4/spreadsheets/';

	public function __construct(
		private GoogleAuth $auth,
		private string $spreadsheetId,
		private string $tabName
	) {}

	/**
	 * @param string               $form   Value for column A.
	 * @param array<string,string>  $fields key => value for the remaining columns.
	 * @throws ApiException
	 */
	public function append( string $form, array $fields ): void {
		$this->ensureTab();
		$columns = $this->ensureColumns( array_keys( $fields ) );

		$row = [];
		foreach ( $columns as $column ) {
			$row[] = self::FORM_COLUMN === $column ? $form : ( $fields[ $column ] ?? '' );
		}

		Http::json(
			'POST',
			$this->valuesUrl( 'A1', ':append', [ 'valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS' ] ),
			$this->auth->accessToken(),
			[ 'values' => [ $row ] ]
		);
	}

	private function ensureTab(): void {
		if ( get_transient( self::SYNCED_TRANSIENT ) ) {
			return; // Tab + columns confirmed recently.
		}
		$meta = Http::json(
			'GET',
			self::BASE . rawurlencode( $this->spreadsheetId ) . '?fields=sheets.properties.title',
			$this->auth->accessToken()
		);
		foreach ( $meta['sheets'] ?? [] as $sheet ) {
			if ( ( $sheet['properties']['title'] ?? null ) === $this->tabName ) {
				return;
			}
		}
		try {
			Http::json(
				'POST',
				self::BASE . rawurlencode( $this->spreadsheetId ) . ':batchUpdate',
				$this->auth->accessToken(),
				[ 'requests' => [ [ 'addSheet' => [ 'properties' => [ 'title' => $this->tabName ] ] ] ] ]
			);
		} catch ( ApiException $e ) {
			if ( false === stripos( $e->getMessage(), 'already exists' ) ) {
				throw $e;
			}
		}
	}

	/**
	 * Return the ordered column list, extending the sheet's header row when a
	 * submission brings a field that has no column yet.
	 *
	 * @param list<string> $fieldKeys
	 * @return list<string>
	 */
	private function ensureColumns( array $fieldKeys ): array {
		$known   = get_option( self::COLUMNS_OPTION, [] );
		$known   = is_array( $known ) && $known ? array_values( array_map( 'strval', $known ) ) : [ self::FORM_COLUMN ];
		$missing = array_values( array_diff( $fieldKeys, $known ) );

		if ( [] === $missing && get_transient( self::SYNCED_TRANSIENT ) ) {
			return $known;
		}

		// Read the live header so manual renames / reordering in the sheet win.
		$response = Http::json( 'GET', $this->valuesUrl( '1:1' ), $this->auth->accessToken() );
		$live     = $response['values'][0] ?? [];
		$live     = is_array( $live ) ? array_values( array_map( 'strval', $live ) ) : [];

		$final = $live ?: [ self::FORM_COLUMN ];
		if ( ! in_array( self::FORM_COLUMN, $final, true ) ) {
			array_unshift( $final, self::FORM_COLUMN );
		}
		foreach ( array_merge( $known, $fieldKeys ) as $column ) {
			if ( '' !== $column && ! in_array( $column, $final, true ) ) {
				$final[] = $column;
			}
		}

		if ( $final !== $live ) {
			Http::json(
				'PUT',
				$this->valuesUrl( 'A1', '', [ 'valueInputOption' => 'RAW' ] ),
				$this->auth->accessToken(),
				[ 'values' => [ $final ] ]
			);
		}

		update_option( self::COLUMNS_OPTION, $final, false );
		set_transient( self::SYNCED_TRANSIENT, 1, self::SYNCED_TTL );
		return $final;
	}

	/**
	 * @param array<string,string> $query
	 */
	private function valuesUrl( string $a1, string $suffix = '', array $query = [] ): string {
		$range = "'" . str_replace( "'", "''", $this->tabName ) . "'!" . $a1;
		$url   = self::BASE . rawurlencode( $this->spreadsheetId ) . '/values/' . rawurlencode( $range ) . $suffix;
		return $query ? $url . '?' . http_build_query( $query ) : $url;
	}
}
