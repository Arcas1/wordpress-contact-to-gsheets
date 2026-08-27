<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ErrorLog;

final class ErrorLogTest extends TestCase {

	public function test_add_prepends_and_persists_with_autoload_off(): void {
		Functions\expect( 'get_option' )->once()->with( ErrorLog::OPTION, [] )->andReturn( [] );
		Functions\expect( 'update_option' )->once()->with(
			ErrorLog::OPTION,
			Mockery_capture( $captured ),
			false
		);

		( new ErrorLog() )->add( [
			'form_id'     => 42,
			'plugin_name' => 'cf7',
			'http_code'   => 401,
			'message'     => 'Invalid credentials',
		] );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'cf7', $captured[0]['plugin_name'] );
		$this->assertSame( 401, $captured[0]['http_code'] );
		$this->assertArrayHasKey( 'time', $captured[0] );
	}

	public function test_ring_buffer_caps_at_50_newest_first(): void {
		$existing = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$existing[] = [ 'time' => $i, 'form_id' => $i, 'plugin_name' => 'x', 'http_code' => 0, 'message' => "m$i" ];
		}
		Functions\expect( 'get_option' )->once()->andReturn( $existing );
		Functions\expect( 'update_option' )->once()->with(
			ErrorLog::OPTION,
			Mockery_capture( $captured ),
			false
		);

		( new ErrorLog() )->add( [ 'form_id' => 999, 'plugin_name' => 'new', 'http_code' => 500, 'message' => 'newest' ] );

		$this->assertCount( 50, $captured );
		$this->assertSame( 'new', $captured[0]['plugin_name'] );
		$this->assertSame( 'm48', $captured[49]['message'] );
	}

	public function test_all_reads_option(): void {
		Functions\expect( 'get_option' )->once()->with( ErrorLog::OPTION, [] )->andReturn( [ [ 'message' => 'a' ] ] );
		$this->assertSame( [ [ 'message' => 'a' ] ], ( new ErrorLog() )->all() );
	}
}

/**
 * Mockery argument matcher that captures the received value into $target.
 */
function Mockery_capture( &$target ) {
	return \Mockery::on( function ( $value ) use ( &$target ) {
		$target = $value;
		return true;
	} );
}
