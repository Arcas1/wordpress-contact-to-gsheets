<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\SheetsWriter;
use Google\Service\Sheets;
use Google\Service\Sheets\Resource\Spreadsheets as SpreadsheetsResource;
use Google\Service\Sheets\Resource\SpreadsheetsValues as ValuesResource;
use Google\Service\Sheets\Sheet as SheetModel;
use Google\Service\Sheets\SheetProperties;
use Google\Service\Sheets\Spreadsheet as SpreadsheetModel;
use Google\Service\Sheets\ValueRange;
use Mockery;

final class SheetsWriterTest extends TestCase {

	private function sheetsMockWithTab( string $existingTitle ): Sheets {
		$props = Mockery::mock( SheetProperties::class );
		$props->shouldReceive( 'getTitle' )->andReturn( $existingTitle );
		$sheet = Mockery::mock( SheetModel::class );
		$sheet->shouldReceive( 'getProperties' )->andReturn( $props );
		$spreadsheet = Mockery::mock( SpreadsheetModel::class );
		$spreadsheet->shouldReceive( 'getSheets' )->andReturn( [ $sheet ] );

		$sheetsResource = Mockery::mock( SpreadsheetsResource::class );
		$sheetsResource->shouldReceive( 'get' )->with( 'SS_ID', Mockery::type( 'array' ) )->andReturn( $spreadsheet );

		$sheets = Mockery::mock( Sheets::class );
		$sheets->spreadsheets        = $sheetsResource;
		$sheets->spreadsheets_values = Mockery::mock( ValuesResource::class );
		return $sheets;
	}

	public function test_append_skips_preflight_when_transient_set(): void {
		Functions\expect( 'get_transient' )->once()->with( SheetsWriter::READY_TRANSIENT )->andReturn( 1 );
		Functions\expect( 'set_transient' )->never();

		$sheets                      = Mockery::mock( Sheets::class );
		$sheets->spreadsheets        = Mockery::mock( SpreadsheetsResource::class );
		$sheets->spreadsheets_values = Mockery::mock( ValuesResource::class );

		$sheets->spreadsheets->shouldReceive( 'get' )->never();
		$sheets->spreadsheets_values->shouldReceive( 'append' )->once()->withArgs(
			function ( $id, $range, $body, $opts ) {
				return 'SS_ID' === $id
					&& "'Submissions'!A:F" === $range
					&& $body instanceof ValueRange
					&& [ [ 'r1', 'r2', 'r3', 'r4', 'r5', 'r6' ] ] === $body->getValues()
					&& 'RAW' === $opts['valueInputOption']
					&& 'INSERT_ROWS' === $opts['insertDataOption'];
			}
		);

		( new SheetsWriter( $sheets, 'SS_ID', 'Submissions' ) )
			->append( [ 'r1', 'r2', 'r3', 'r4', 'r5', 'r6' ] );
	}

	public function test_append_creates_missing_tab_then_writes_header(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()->with( SheetsWriter::READY_TRANSIENT, 1, 600 );

		$sheets = $this->sheetsMockWithTab( 'SomeOtherTab' );

		$sheets->spreadsheets->shouldReceive( 'batchUpdate' )->once()->withArgs(
			function ( $id, $body ) {
				$title = $body->getRequests()[0]->getAddSheet()->getProperties()->getTitle();
				return 'SS_ID' === $id && 'Submissions' === $title;
			}
		);

		$emptyHeader = Mockery::mock( ValueRange::class );
		$emptyHeader->shouldReceive( 'getValues' )->andReturn( null );
		$sheets->spreadsheets_values->shouldReceive( 'get' )->once()
			->with( 'SS_ID', "'Submissions'!A1:F1" )->andReturn( $emptyHeader );

		$sheets->spreadsheets_values->shouldReceive( 'update' )->once()->withArgs(
			function ( $id, $range, $body, $opts ) {
				return "'Submissions'!A1:F1" === $range
					&& SheetsWriter::HEADER === $body->getValues()[0]
					&& 'RAW' === $opts['valueInputOption'];
			}
		);

		$sheets->spreadsheets_values->shouldReceive( 'append' )->once();

		( new SheetsWriter( $sheets, 'SS_ID', 'Submissions' ) )
			->append( [ 'a', 'b', 'c', 'd', 'e', 'f' ] );
	}

	public function test_append_leaves_existing_header_alone(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once();

		$sheets = $this->sheetsMockWithTab( 'Submissions' ); // tab already exists
		$sheets->spreadsheets->shouldReceive( 'batchUpdate' )->never();

		$filledHeader = Mockery::mock( ValueRange::class );
		$filledHeader->shouldReceive( 'getValues' )->andReturn( [ SheetsWriter::HEADER ] );
		$sheets->spreadsheets_values->shouldReceive( 'get' )->once()->andReturn( $filledHeader );
		$sheets->spreadsheets_values->shouldReceive( 'update' )->never();
		$sheets->spreadsheets_values->shouldReceive( 'append' )->once();

		( new SheetsWriter( $sheets, 'SS_ID', 'Submissions' ) )
			->append( [ 'a', 'b', 'c', 'd', 'e', 'f' ] );
	}

	public function test_quotes_tab_name_with_spaces_in_ranges(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 1 );

		$sheets                      = Mockery::mock( Sheets::class );
		$sheets->spreadsheets        = Mockery::mock( SpreadsheetsResource::class );
		$sheets->spreadsheets_values = Mockery::mock( ValuesResource::class );
		$sheets->spreadsheets_values->shouldReceive( 'append' )->once()->withArgs(
			function ( $id, $range ) {
				return "'Form Submissions'!A:F" === $range;
			}
		);

		( new SheetsWriter( $sheets, 'SS_ID', 'Form Submissions' ) )
			->append( [ 'a', 'b', 'c', 'd', 'e', 'f' ] );
	}
}
