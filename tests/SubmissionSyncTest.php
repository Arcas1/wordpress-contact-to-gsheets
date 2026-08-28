<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ErrorLog;
use C2GS\FieldMapper;
use C2GS\GoogleAuth;
use C2GS\SheetsWriter;
use C2GS\SubmissionSync;
use C2GS\ApiException;
use Mockery;

final class SubmissionSyncTest extends TestCase {

	private function mapper(): FieldMapper {
		return new FieldMapper( static fn( $v ) => is_string( $v ) && str_contains( (string) $v, '@' ) );
	}

	private function fields(): array {
		return [ 'your-name' => 'Ada', 'your-email' => 'a@b.com', 'msg' => 'hello there' ];
	}

	public function test_maps_and_appends_row(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
		Functions\when( 'wp_date' )->justReturn( '2026-08-27T10:00:00-05:00' );
		Functions\when( 'delete_transient' )->justReturn( true );

		$writer = Mockery::mock( SheetsWriter::class );
		$writer->shouldReceive( 'append' )->once()->withArgs( function ( $row ) {
			return 'metform #7' === $row[1] && 'a@b.com' === $row[3] && 'Ada' === $row[2] && 'hello there' === $row[4];
		} );

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->never();

		( new SubmissionSync( $this->mapper(), $log, Mockery::mock( GoogleAuth::class ), fn() => $writer ) )
			->sync( 'metform', 7, '', $this->fields() );
	}

	public function test_returns_early_and_flags_not_connected_when_no_spreadsheet_id(): void {
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [] );
		Functions\expect( 'set_transient' )->once()->with( 'c2gs_not_connected', 1, DAY_IN_SECONDS );

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->never();

		( new SubmissionSync( $this->mapper(), $log, Mockery::mock( GoogleAuth::class ), fn() => throw new \RuntimeException( 'factory not expected' ) ) )
			->sync( 'cf7', 1, 'X', $this->fields() );
	}

	public function test_ignores_empty_fields(): void {
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->never();

		( new SubmissionSync( $this->mapper(), $log, Mockery::mock( GoogleAuth::class ), fn() => throw new \RuntimeException( 'factory not expected' ) ) )
			->sync( 'cf7', 1, 'X', [] );
	}

	public function test_swallows_writer_exception_and_logs_it(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
		Functions\when( 'wp_date' )->justReturn( 'T' );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\expect( 'set_transient' )->once()->with( 'c2gs_fail_count', 1, WEEK_IN_SECONDS );

		$writer = Mockery::mock( SheetsWriter::class );
		$writer->shouldReceive( 'append' )->once()->andThrow( new \RuntimeException( 'network down' ) );

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->once()->withArgs( function ( $rec ) {
			return 42 === $rec['form_id'] && 'fluentform' === $rec['plugin_name'] && 'network down' === $rec['message'];
		} );

		( new SubmissionSync( $this->mapper(), $log, Mockery::mock( GoogleAuth::class ), fn() => $writer ) )
			->sync( 'fluentform', 42, 'Contact', $this->fields() ); // must not throw
	}

	public function test_on_401_forces_refresh_and_retries_once(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
		Functions\when( 'wp_date' )->justReturn( 'T' );
		Functions\when( 'delete_transient' )->justReturn( true );

		$failing = Mockery::mock( SheetsWriter::class );
		$failing->shouldReceive( 'append' )->once()->andThrow( new ApiException( 'unauthorized', 401 ) );
		$ok = Mockery::mock( SheetsWriter::class );
		$ok->shouldReceive( 'append' )->once();

		$auth = Mockery::mock( GoogleAuth::class );
		$auth->shouldReceive( 'forceRefresh' )->once();

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->never();

		$writers = [ $failing, $ok ];
		( new SubmissionSync( $this->mapper(), $log, $auth, function () use ( &$writers ) {
			return array_shift( $writers );
		} ) )->sync( 'cf7', 1, 'X', $this->fields() );
	}
}
