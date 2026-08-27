<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ErrorLog;
use C2GS\FieldMapper;
use C2GS\GoogleAuth;
use C2GS\SheetsWriter;
use C2GS\SubmissionListener;
use Google\Service\Exception as GoogleServiceException;
use Mockery;

final class SubmissionListenerTest extends TestCase {

	private function payload( array $posted = [ 'your-name' => 'Ada', 'your-email' => 'a@b.com', 'msg' => 'hello there' ] ): array {
		return [
			'insert_id'   => 10,
			'plugin_name' => 'cf7',
			'form_id'     => 42,
			'entry_data'  => [ 'title' => 'Contact Us', 'posted_data' => $posted ],
			'entires'     => [],
		];
	}

	private function mapper(): FieldMapper {
		return new FieldMapper( static fn( $v ) => is_string( $v ) && str_contains( (string) $v, '@' ) );
	}

	public function test_happy_path_appends_mapped_row(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
		Functions\when( 'wp_date' )->justReturn( '2026-08-26T23:00:00-05:00' );

		$writer = Mockery::mock( SheetsWriter::class );
		$writer->shouldReceive( 'append' )->once()->withArgs( function ( $row ) {
			return 'a@b.com' === $row[3] && 'Contact Us' === $row[1] && 'hello there' === $row[4];
		} );

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->never();

		$listener = new SubmissionListener(
			$this->mapper(),
			$log,
			Mockery::mock( GoogleAuth::class ),
			fn() => $writer
		);
		$listener->handle( $this->payload() );
	}

	public function test_returns_early_and_flags_not_connected_when_no_spreadsheet_id(): void {
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [] );
		Functions\expect( 'set_transient' )->once()->with( 'c2gs_not_connected', 1, DAY_IN_SECONDS );

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->never();

		$listener = new SubmissionListener(
			$this->mapper(),
			$log,
			Mockery::mock( GoogleAuth::class ),
			fn() => throw new \RuntimeException( 'factory should not be called' )
		);
		$listener->handle( $this->payload() );
	}

	public function test_swallows_writer_exception_and_logs_it(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
		Functions\when( 'wp_date' )->justReturn( 'T' );
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\expect( 'set_transient' )->once()->with( 'c2gs_fail_count', 1, WEEK_IN_SECONDS );

		$writer = Mockery::mock( SheetsWriter::class );
		$writer->shouldReceive( 'append' )->once()->andThrow( new \RuntimeException( 'network down' ) );

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->once()->withArgs( function ( $rec ) {
			return 42 === $rec['form_id'] && 'cf7' === $rec['plugin_name'] && 'network down' === $rec['message'];
		} );

		$listener = new SubmissionListener(
			$this->mapper(),
			$log,
			Mockery::mock( GoogleAuth::class ),
			fn() => $writer
		);
		$listener->handle( $this->payload() ); // must not throw
	}

	public function test_on_401_forces_refresh_and_retries_once(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
		Functions\when( 'wp_date' )->justReturn( 'T' );

		$failing = Mockery::mock( SheetsWriter::class );
		$failing->shouldReceive( 'append' )->once()->andThrow( new GoogleServiceException( 'unauthorized', 401 ) );
		$ok = Mockery::mock( SheetsWriter::class );
		$ok->shouldReceive( 'append' )->once();

		$auth = Mockery::mock( GoogleAuth::class );
		$auth->shouldReceive( 'forceRefresh' )->once();

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->never();

		$writers  = [ $failing, $ok ];
		$listener = new SubmissionListener(
			$this->mapper(),
			$log,
			$auth,
			function () use ( &$writers ) {
				return array_shift( $writers );
			}
		);
		$listener->handle( $this->payload() );
	}

	public function test_ignores_payload_with_empty_posted_data(): void {
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->never();

		$listener = new SubmissionListener(
			$this->mapper(),
			$log,
			Mockery::mock( GoogleAuth::class ),
			fn() => throw new \RuntimeException( 'should not build writer' )
		);
		$listener->handle( [ 'plugin_name' => 'cf7', 'form_id' => 1, 'entry_data' => [ 'posted_data' => [] ] ] );
	}
}
