<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\MetFormListener;
use C2GS\SubmissionSync;
use Mockery;

final class MetFormListenerTest extends TestCase {

	public function test_strips_metform_noise_and_uses_form_title_from_settings(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'metform',
			12,
			'Quote request',
			[ 'mf-email' => 'a@b.com', 'mf-listing-fname' => 'Ada', 'message' => 'hi' ]
		);

		( new MetFormListener( $sync ) )->handle(
			12,
			[
				'mf-email'         => 'a@b.com',
				'mf-listing-fname' => 'Ada',
				'message'          => 'hi',
				'form_nonce'       => 'abc',
				'hidden-fields'    => '[]',
				'g-recaptcha-response' => 'token',
				'mf_entry_id'      => '99',
			],
			[ 'form_title' => 'Quote request' ],
			[ 'email_field_name' => 'mf-email' ]
		);
	}

	public function test_falls_back_to_get_the_title_when_settings_have_no_title(): void {
		Functions\expect( 'get_the_title' )->once()->with( 5 )->andReturn( 'Contact page form' );

		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'metform',
			5,
			'Contact page form',
			[ 'mf-email' => 'x@y.com' ]
		);

		( new MetFormListener( $sync ) )->handle( 5, [ 'mf-email' => 'x@y.com' ], [], [] );
	}

	public function test_ignores_when_only_noise_fields_present(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		( new MetFormListener( $sync ) )->handle( 1, [ 'form_nonce' => 'a', 'g-recaptcha-response' => 'b' ], [], [] );
	}

	public function test_ignores_non_array_form_data(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		( new MetFormListener( $sync ) )->handle( 1, null, [], [] );
	}
}
