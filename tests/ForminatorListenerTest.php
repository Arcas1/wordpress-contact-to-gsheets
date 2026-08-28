<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ForminatorListener;
use C2GS\SubmissionSync;
use Mockery;

final class ForminatorListenerTest extends TestCase {

	public function test_builds_slug_map_and_email_hint_and_strips_noise(): void {
		Functions\when( 'forminator_get_form_name' )->justReturn( 'Contact' );

		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'forminator',
			31,
			'Contact',
			[
				'name-1'     => [ 'first-name' => 'Ada', 'last-name' => 'Lovelace' ],
				'email-1'    => 'ada@example.com',
				'textarea-1' => 'Please call me.',
			],
		);

		( new ForminatorListener( $sync ) )->handle(
			null,
			31,
			[
				[ 'name' => 'name-1', 'value' => [ 'first-name' => 'Ada', 'last-name' => 'Lovelace' ] ],
				[ 'name' => 'email-1', 'value' => 'ada@example.com' ],
				[ 'name' => 'textarea-1', 'value' => 'Please call me.' ],
				[ 'name' => 'captcha-1', 'value' => 'xyz' ],
				[ 'name' => 'page-break-1', 'value' => '' ],
			]
		);
	}

	public function test_ignores_when_no_real_fields(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		( new ForminatorListener( $sync ) )->handle( null, 1, [ [ 'name' => 'recaptcha-1', 'value' => 'x' ] ] );
	}

	public function test_ignores_non_array_field_data(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		( new ForminatorListener( $sync ) )->handle( null, 1, null );
	}
}
