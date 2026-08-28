<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ApiException;
use C2GS\ErrorLog;
use C2GS\FieldMapper;
use C2GS\GoogleAuth;
use C2GS\SheetsWriter;
use C2GS\SubmissionSync;
use Mockery;

final class SubmissionSyncTest extends TestCase {

	private function sync( \Closure $factory, ?ErrorLog $log = null, ?GoogleAuth $auth = null ): SubmissionSync {
		return new SubmissionSync(
			new FieldMapper(),
			$log ?? Mockery::mock( ErrorLog::class ),
			$auth ?? Mockery::mock( GoogleAuth::class ),
			$factory
		);
	}

	public function test_normalises_and_appends_with_the_form_title(): void {
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
		Functions\when( 'delete_transient' )->justReturn( true );

		$writer = Mockery::mock( SheetsWriter::class );
		$writer->shouldReceive( 'append' )->once()->with(
			'Contact page',
			[ 'name' => 'Ada', 'email' => 'a@b.com' ]
		);

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->never();

		$this->sync( fn() => $writer, $log )->sync(
			'elementor',
			'1b208a1',
			'Contact page',
			[ 'name' => 'Ada', 'email' => 'a@b.com', 'g-recaptcha-response' => 'x', 'phone' => '' ]
		);
	}

	public function test_form_column_falls_back_to_url_then_referer(): void {
		Functions\expect( 'get_option' )->twice()->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
		Functions\when( 'delete_transient' )->justReturn( true );

		$writer = Mockery::mock( SheetsWriter::class );
		$writer->shouldReceive( 'append' )->once()->with( 'https://site/contact', [ 'name' => 'Ada' ] );
		$writer->shouldReceive( 'append' )->once()->with( 'https://site/referer', [ 'name' => 'Bob' ] );

		Functions\when( 'wp_get_referer' )->justReturn( 'https://site/referer' );

		$s = $this->sync( fn() => $writer );
		$s->sync( 'metform', 5, '', [ 'name' => 'Ada' ], 'https://site/contact' );
		$s->sync( 'metform', 5, '', [ 'name' => 'Bob' ] );
	}

	public function test_returns_early_when_no_spreadsheet_id(): void {
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [] );
		Functions\expect( 'set_transient' )->once()->with( 'c2gs_not_connected', 1, DAY_IN_SECONDS );

		$this->sync( fn() => throw new \RuntimeException( 'no' ) )
			->sync( 'cf7', 1, 'X', [ 'name' => 'Ada' ] );
	}

	public function test_ignores_submission_with_no_real_fields(): void {
		$this->expectNotToPerformAssertions();
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );

		$this->sync( fn() => throw new \RuntimeException( 'no' ) )
			->sync( 'cf7', 1, 'X', [ 'g-recaptcha-response' => 'x', 'form_id' => '9', 'blank' => '' ] );
	}

	public function test_swallows_writer_exception_and_logs_it(): void {
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\expect( 'set_transient' )->once()->with( 'c2gs_fail_count', 1, WEEK_IN_SECONDS );

		$writer = Mockery::mock( SheetsWriter::class );
		$writer->shouldReceive( 'append' )->once()->andThrow( new ApiException( 'boom', 500 ) );

		$log = Mockery::mock( ErrorLog::class );
		$log->shouldReceive( 'add' )->once()->withArgs( function ( $rec ) {
			return 42 === $rec['form_id'] && 'fluentform' === $rec['plugin_name']
				&& 500 === $rec['http_code'] && 'boom' === $rec['message'];
		} );

		$this->sync( fn() => $writer, $log )->sync( 'fluentform', 42, 'Contact', [ 'name' => 'Ada' ] );
	}

	public function test_on_401_forces_refresh_and_retries_once(): void {
		Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
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
		$factory = function () use ( &$writers ) {
			return array_shift( $writers );
		};
		$this->sync( $factory, $log, $auth )->sync( 'cf7', 1, 'X', [ 'name' => 'Ada' ] );
	}
}
