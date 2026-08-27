<?php

namespace C2GS;

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
			$this->tabName . '!A:F',
			$body,
			[
				'valueInputOption' => 'USER_ENTERED',
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
		$spreadsheet = $this->sheets->spreadsheets->get( $this->spreadsheetId );
		foreach ( $spreadsheet->getSheets() as $sheet ) {
			if ( $sheet->getProperties()->getTitle() === $this->tabName ) {
				return;
			}
		}

		$request = new SheetsRequest( [
			'addSheet' => [ 'properties' => [ 'title' => $this->tabName ] ],
		] );
		$this->sheets->spreadsheets->batchUpdate(
			$this->spreadsheetId,
			new BatchUpdateSpreadsheetRequest( [ 'requests' => [ $request ] ] )
		);
	}

	private function ensureHeader(): void {
		$response = $this->sheets->spreadsheets_values->get(
			$this->spreadsheetId,
			$this->tabName . '!A1:F1'
		);
		$values = $response->getValues();
		if ( ! empty( $values ) && ! empty( $values[0] ) ) {
			return;
		}

		$this->sheets->spreadsheets_values->update(
			$this->spreadsheetId,
			$this->tabName . '!A1:F1',
			new ValueRange( [ 'values' => [ self::HEADER ] ] ),
			[ 'valueInputOption' => 'RAW' ]
		);
	}
}
