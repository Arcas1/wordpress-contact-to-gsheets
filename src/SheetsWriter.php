<?php

namespace C2GS;

use Google\Service\Exception as GoogleServiceException;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\ValueRange;

/**
 * Appends a single row to a Google Sheet tab, creating the tab and
 * header row on first use.
 */
class SheetsWriter {

	public const HEADER          = [ 'timestamp', 'form', 'name', 'email', 'message', 'data' ];
	public const READY_TRANSIENT = 'c2gs_tab_ready';
	private const READY_TTL      = 600;

	public function __construct(
		private Sheets $sheets,
		private string $spreadsheetId,
		private string $tabName
	) {}

	/**
	 * @param array{0:string,1:string,2:string,3:string,4:string,5:string} $row
	 */
	public function append( array $row ): void {
		$this->ensureReady();

		$body = new ValueRange( [ 'values' => [ array_values( $row ) ] ] );
		$this->sheets->spreadsheets_values->append(
			$this->spreadsheetId,
			$this->range( 'A:F' ),
			$body,
			[
				// RAW, not USER_ENTERED: submitted text must never be parsed as
				// a formula (a value starting with =, +, -, @ would otherwise
				// become a live formula or an error cell).
				'valueInputOption' => 'RAW',
				'insertDataOption' => 'INSERT_ROWS',
			]
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
		$spreadsheet = $this->sheets->spreadsheets->get(
			$this->spreadsheetId,
			[ 'fields' => 'sheets.properties.title' ]
		);
		foreach ( $spreadsheet->getSheets() as $sheet ) {
			if ( $sheet->getProperties()->getTitle() === $this->tabName ) {
				return;
			}
		}

		$request = new SheetsRequest( [
			'addSheet' => [ 'properties' => [ 'title' => $this->tabName ] ],
		] );
		try {
			$this->sheets->spreadsheets->batchUpdate(
				$this->spreadsheetId,
				new BatchUpdateSpreadsheetRequest( [ 'requests' => [ $request ] ] )
			);
		} catch ( GoogleServiceException $e ) {
			// A concurrent submission may have created the tab first.
			if ( false === stripos( $e->getMessage(), 'already exists' ) ) {
				throw $e;
			}
		}
	}

	private function ensureHeader(): void {
		$response = $this->sheets->spreadsheets_values->get(
			$this->spreadsheetId,
			$this->range( 'A1:F1' )
		);
		$values = $response->getValues();
		if ( ! empty( $values ) && ! empty( $values[0] ) ) {
			return;
		}

		$this->sheets->spreadsheets_values->update(
			$this->spreadsheetId,
			$this->range( 'A1:F1' ),
			new ValueRange( [ 'values' => [ self::HEADER ] ] ),
			[ 'valueInputOption' => 'RAW' ]
		);
	}

	/**
	 * Build a quoted A1 range for the configured tab. Single-quoting is valid
	 * for any sheet title and is required when the title contains spaces or
	 * punctuation; embedded quotes are doubled per the A1 spec.
	 */
	private function range( string $a1 ): string {
		return "'" . str_replace( "'", "''", $this->tabName ) . "'!" . $a1;
	}
}
