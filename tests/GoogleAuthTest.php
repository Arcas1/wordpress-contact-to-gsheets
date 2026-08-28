<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ApiException;
use C2GS\GoogleAuth;
use Mockery;

final class GoogleAuthTest extends TestCase {

	use HttpMockTrait;

	private function auth(): GoogleAuth {
		return new GoogleAuth( 'cid', 'secret', 'https://site/wp-admin/admin-post.php?action=c2gs_oauth_cb' );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->mockHttp();
	}

	public function test_consent_url_carries_the_expected_params(): void {
		$url = $this->auth()->consentUrl( 'STATE123' );
		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth?', $url );
		$this->assertStringContainsString( 'client_id=cid', $url );
		$this->assertStringContainsString( 'access_type=offline', $url );
		$this->assertStringContainsString( 'prompt=consent', $url );
		$this->assertStringContainsString( 'state=STATE123', $url );
		$this->assertStringContainsString( 'scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fspreadsheets', $url );
	}

	public function test_access_token_returns_stored_token_when_not_expired(): void {
		Functions\expect( 'get_option' )->once()->with( GoogleAuth::TOKEN_OPTION, [] )->andReturn(
			[ 'access_token' => 'live', 'refresh_token' => 'R', 'expires_at' => time() + 1000 ]
		);
		Functions\expect( 'update_option' )->never();

		$this->assertSame( 'live', $this->auth()->accessToken() );
		$this->assertCount( 0, $this->httpCalls );
	}

	public function test_access_token_refreshes_when_expired_and_keeps_refresh_token(): void {
		Functions\expect( 'get_option' )->once()->with( GoogleAuth::TOKEN_OPTION, [] )->andReturn(
			[ 'access_token' => 'old', 'refresh_token' => 'R', 'expires_at' => time() - 10 ]
		);
		$this->queueResponse( 200, [ 'access_token' => 'fresh', 'expires_in' => 3600 ] );

		Functions\expect( 'update_option' )->once()->with(
			GoogleAuth::TOKEN_OPTION,
			Mockery::on( function ( $token ) {
				return 'fresh' === $token['access_token']
					&& 'R' === $token['refresh_token']
					&& $token['expires_at'] > time()
					&& ! isset( $token['expires_in'] );
			} ),
			false
		);

		$this->assertSame( 'fresh', $this->auth()->accessToken() );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $this->httpCalls[0]['url'] );
		$this->assertSame( 'refresh_token', $this->httpCalls[0]['args']['body']['grant_type'] );
	}

	public function test_access_token_throws_when_not_connected(): void {
		Functions\expect( 'get_option' )->once()->andReturn( [] );
		$this->expectException( ApiException::class );
		$this->auth()->accessToken();
	}

	public function test_exchange_code_stores_token_on_success(): void {
		$this->queueResponse( 200, [ 'access_token' => 'A', 'refresh_token' => 'R', 'expires_in' => 3600 ] );
		Functions\expect( 'update_option' )->once()->with(
			GoogleAuth::TOKEN_OPTION,
			Mockery::on( fn( $t ) => 'A' === $t['access_token'] && 'R' === $t['refresh_token'] ),
			false
		);

		$this->assertTrue( $this->auth()->exchangeCode( 'CODE' ) );
		$this->assertSame( 'authorization_code', $this->httpCalls[0]['args']['body']['grant_type'] );
	}

	public function test_exchange_code_returns_false_on_error_response(): void {
		$this->queueResponse( 400, [ 'error' => 'invalid_grant' ] );
		Functions\expect( 'update_option' )->never();

		$this->assertFalse( $this->auth()->exchangeCode( 'BAD' ) );
	}

	public function test_is_connected_reads_option(): void {
		Functions\expect( 'get_option' )->once()->with( GoogleAuth::TOKEN_OPTION, [] )->andReturn( [ 'refresh_token' => 'R' ] );
		$this->assertTrue( $this->auth()->isConnected() );
	}

	public function test_disconnect_revokes_and_deletes_option(): void {
		Functions\expect( 'get_option' )->once()->andReturn( [ 'refresh_token' => 'R', 'access_token' => 'A' ] );
		$this->queueResponse( 200, [] );
		Functions\expect( 'delete_option' )->once()->with( GoogleAuth::TOKEN_OPTION );

		$this->auth()->disconnect();
		$this->assertSame( 'https://oauth2.googleapis.com/revoke', $this->httpCalls[0]['url'] );
	}
}
