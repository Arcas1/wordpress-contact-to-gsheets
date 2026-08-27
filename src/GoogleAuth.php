<?php

namespace C2GS;

use Google\Client;

/**
 * Wraps a Google OAuth 2.0 client for the Sheets scope and persists the
 * token set (with offline refresh token) in a single option.
 */
class GoogleAuth {

	public const TOKEN_OPTION    = 'c2gs_google_token';
	public const CALLBACK_ACTION = 'c2gs_oauth_cb';
	public const SCOPE           = 'https://www.googleapis.com/auth/spreadsheets';

	public function __construct(
		private string $clientId,
		private string $clientSecret,
		private string $redirectUri
	) {}

	protected function newClient(): Client {
		$client = new Client();
		$client->setClientId( $this->clientId );
		$client->setClientSecret( $this->clientSecret );
		$client->setRedirectUri( $this->redirectUri );
		$client->setScopes( [ self::SCOPE ] );
		$client->setAccessType( 'offline' );
		$client->setPrompt( 'consent' );
		return $client;
	}

	public function consentUrl( string $state ): string {
		$client = $this->newClient();
		$client->setState( $state );
		return $client->createAuthUrl();
	}

	public function exchangeCode( string $code ): bool {
		$token = $this->newClient()->fetchAccessTokenWithAuthCode( $code );
		if ( isset( $token['error'] ) ) {
			return false;
		}
		$this->storeToken( $token );
		return true;
	}

	public function isConnected(): bool {
		$token = get_option( self::TOKEN_OPTION, [] );
		return is_array( $token )
			&& ( ! empty( $token['refresh_token'] ) || ! empty( $token['access_token'] ) );
	}

	public function authedClient(): Client {
		$token = get_option( self::TOKEN_OPTION, [] );
		if ( empty( $token ) || ! is_array( $token ) ) {
			throw new \RuntimeException( 'Google account not connected' );
		}

		$client = $this->newClient();
		$client->setAccessToken( $token );

		if ( $client->isAccessTokenExpired() ) {
			$refreshToken = $token['refresh_token'] ?? null;
			if ( ! $refreshToken ) {
				throw new \RuntimeException( 'No refresh token stored; reconnect required' );
			}
			$new = $client->fetchAccessTokenWithRefreshToken( $refreshToken );
			if ( isset( $new['error'] ) ) {
				throw new \RuntimeException( 'Token refresh failed: ' . $new['error'] );
			}
			$merged = array_merge( $token, $client->getAccessToken() );
			if ( empty( $merged['refresh_token'] ) ) {
				$merged['refresh_token'] = $refreshToken;
			}
			$this->storeToken( $merged );
		}

		return $client;
	}

	public function forceRefresh(): void {
		$token        = get_option( self::TOKEN_OPTION, [] );
		$refreshToken = is_array( $token ) ? ( $token['refresh_token'] ?? null ) : null;
		if ( ! $refreshToken ) {
			throw new \RuntimeException( 'No refresh token stored; reconnect required' );
		}
		$new = $this->newClient()->fetchAccessTokenWithRefreshToken( $refreshToken );
		if ( isset( $new['error'] ) ) {
			throw new \RuntimeException( 'Token refresh failed: ' . $new['error'] );
		}
		$merged                  = array_merge( is_array( $token ) ? $token : [], $new );
		$merged['refresh_token'] = $merged['refresh_token'] ?? $refreshToken;
		$this->storeToken( $merged );
	}

	public function disconnect(): void {
		$token = get_option( self::TOKEN_OPTION, [] );
		if ( is_array( $token ) && ! empty( $token['access_token'] ) ) {
			try {
				$this->newClient()->revokeToken( $token );
			} catch ( \Throwable $e ) {
				// Best effort; still clear locally.
			}
		}
		delete_option( self::TOKEN_OPTION );
	}

	/** @param array<string,mixed> $token */
	private function storeToken( array $token ): void {
		if ( ! isset( $token['created'] ) ) {
			$token['created'] = time();
		}
		update_option( self::TOKEN_OPTION, $token, false );
	}
}
