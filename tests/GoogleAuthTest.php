<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\GoogleAuth;
use Google\Client;
use Mockery;

final class GoogleAuthTest extends TestCase {

	private function authWithClient( Client $client ): GoogleAuth {
		return new class( 'cid', 'secret', 'https://site/wp-admin/admin-post.php?action=c2gs_oauth_cb', $client ) extends GoogleAuth {
			private Client $injected;
			public function __construct( string $id, string $secret, string $uri, Client $c ) {
				parent::__construct( $id, $secret, $uri );
				$this->injected = $c;
			}
			protected function newClient(): Client {
				return $this->injected;
			}
		};
	}

	public function test_authed_client_refreshes_when_expired_and_persists_refresh_token(): void {
		$stored = [ 'access_token' => 'old', 'refresh_token' => 'R', 'expires_in' => 3600, 'created' => 1 ];
		Functions\expect( 'get_option' )->with( GoogleAuth::TOKEN_OPTION, [] )->andReturn( $stored );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'setClientId' )->andReturnNull();
		$client->shouldReceive( 'setClientSecret' )->andReturnNull();
		$client->shouldReceive( 'setRedirectUri' )->andReturnNull();
		$client->shouldReceive( 'setScopes' )->andReturnNull();
		$client->shouldReceive( 'setAccessType' )->andReturnNull();
		$client->shouldReceive( 'setPrompt' )->andReturnNull();
		$client->shouldReceive( 'setAccessToken' )->once()->with( $stored );
		$client->shouldReceive( 'isAccessTokenExpired' )->once()->andReturn( true );
		$client->shouldReceive( 'fetchAccessTokenWithRefreshToken' )->once()->with( 'R' )
			->andReturn( [ 'access_token' => 'new', 'expires_in' => 3600 ] );
		$client->shouldReceive( 'getAccessToken' )->andReturn( [ 'access_token' => 'new', 'expires_in' => 3600 ] );

		Functions\expect( 'update_option' )->once()->with(
			GoogleAuth::TOKEN_OPTION,
			Mockery::on( function ( $token ) {
				return 'new' === $token['access_token'] && 'R' === $token['refresh_token'];
			} ),
			false
		);

		$out = $this->authWithClient( $client )->authedClient();
		$this->assertSame( $client, $out );
	}

	public function test_authed_client_throws_when_not_connected(): void {
		Functions\expect( 'get_option' )->with( GoogleAuth::TOKEN_OPTION, [] )->andReturn( [] );
		$this->expectException( \RuntimeException::class );
		$this->authWithClient( Mockery::mock( Client::class ) )->authedClient();
	}

	public function test_exchange_code_returns_false_on_error_response(): void {
		$client = Mockery::mock( Client::class );
		foreach ( [ 'setClientId', 'setClientSecret', 'setRedirectUri', 'setScopes', 'setAccessType', 'setPrompt' ] as $m ) {
			$client->shouldReceive( $m )->andReturnNull();
		}
		$client->shouldReceive( 'fetchAccessTokenWithAuthCode' )->once()->with( 'BADCODE' )
			->andReturn( [ 'error' => 'invalid_grant' ] );
		Functions\expect( 'update_option' )->never();

		$this->assertFalse( $this->authWithClient( $client )->exchangeCode( 'BADCODE' ) );
	}

	public function test_is_connected_reads_option(): void {
		Functions\expect( 'get_option' )->with( GoogleAuth::TOKEN_OPTION, [] )->andReturn( [ 'refresh_token' => 'R' ] );
		$this->assertTrue( $this->authWithClient( Mockery::mock( Client::class ) )->isConnected() );
	}
}
