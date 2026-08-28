<?php

namespace C2GS\Tests;

use C2GS\SubmissionListener;
use C2GS\SubmissionSync;
use Mockery;

final class SubmissionListenerTest extends TestCase {

	public function test_extracts_form_vibes_1_5_3_entires_key_and_calls_sync(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'cf7',
			42,
			'',
			[ 'your-name' => 'Ada', 'your-email' => 'a@b.com' ]
		);

		( new SubmissionListener( $sync ) )->handle( [
			'plugin_name' => 'cf7',
			'form_id'     => 42,
			'entry_data'  => [ 'form_plugin' => 'cf7', 'form_id' => 42 ],
			'entires'     => [ 'your-name' => 'Ada', 'your-email' => 'a@b.com' ],
		] );
	}

	public function test_falls_back_to_entry_data_posted_data_and_title(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'wpforms',
			3,
			'Support',
			[ 'email' => 'a@b.com' ]
		);

		( new SubmissionListener( $sync ) )->handle( [
			'plugin_name' => 'wpforms',
			'form_id'     => 3,
			'entry_data'  => [ 'title' => 'Support', 'posted_data' => [ 'email' => 'a@b.com' ] ],
		] );
	}

	public function test_ignores_payload_without_array_fields(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		( new SubmissionListener( $sync ) )->handle( [ 'plugin_name' => 'cf7', 'form_id' => 1 ] );
	}
}
