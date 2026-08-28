<?php

namespace C2GS\Tests;

use C2GS\FluentFormListener;
use C2GS\SubmissionSync;
use Mockery;

final class FluentFormListenerTest extends TestCase {

	public function test_strips_meta_keys_and_reads_id_and_title_from_form_object(): void {
		$form = (object) [ 'id' => 8, 'title' => 'Newsletter signup' ];

		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'fluentform',
			8,
			'Newsletter signup',
			[ 'email' => 'a@b.com', 'names' => [ 'first_name' => 'Ada' ] ]
		);

		( new FluentFormListener( $sync ) )->handle(
			101,
			[
				'email'                        => 'a@b.com',
				'names'                        => [ 'first_name' => 'Ada' ],
				'_wp_http_referer'             => '/contact',
				'__fluent_form_embded_post_id' => '3',
				'g-recaptcha-response'         => 'token',
			],
			$form
		);
	}

	public function test_ignores_when_no_real_fields_remain(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		( new FluentFormListener( $sync ) )->handle(
			1,
			[ '_wp_http_referer' => '/x', 'cf-turnstile-response' => 'y' ],
			(object) [ 'id' => 1, 'title' => 'X' ]
		);
	}

	public function test_tolerates_missing_form_object(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with( 'fluentform', '', '', [ 'email' => 'a@b.com' ] );

		( new FluentFormListener( $sync ) )->handle( 1, [ 'email' => 'a@b.com' ], null );
	}
}
