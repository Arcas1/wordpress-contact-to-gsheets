<?php

namespace C2GS;

/**
 * Google OAuth 2.0 (user consent, offline refresh token) for the Sheets
 * scope, talking straight to the Google endpoints over the WordPress HTTP
 * API. The token set lives in one option, autoload off.
 */
class GoogleAuth {

	public const TOKEN_OPTION    = 'c2gs_google_token';
	public const CALLBACK_ACTION = 'c2gs_oauth_cb';
	public const SCOPE           = 'https://www.googleapis.com/auth/spreadsheets';

	private const AUTH_ENDPOINT   = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_ENDPOINT  = 'https://oauth2.googleapis.com/token';
	private const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';
	private const EXPIRY_SKEW     = 60;

	public function __construct(
		private string $clientId,
		private string $clientSecret,
		private string $redirectUri
	) {}

	public function consentUrl( string $state ): string {
		return self::AUTH_ENDPOINT . '?' . http_build_query(
			[
				'client_id'     => $this->clientId,
				'redirect_uri'  => $this->redirectUri,
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/** @return bool True when the code was exchanged and a token stored. */
	public function exchangeCode( string $code ): bool {
		try {
			$token = Http::postForm(
				self::TOKEN_ENDPOINT,
				[
					'code'          => $code,
					'client_id'     => $this->clientId,
					'client_secret' => $this->clientSecret,
					'redirect_uri'  => $this->redirectUri,
					'grant_type'    => 'authorization_code',
				]
			);
		} catch ( ApiException $e ) {
			return false;
		}
		if ( empty( $token['access_token'] ) ) {
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

	/**
	 * A valid access token, refreshing first when the stored one is expired.
	 *
	 * @throws ApiException When not connected or the refresh fails.
	 */
	public function accessToken(): string {
		$token = get_option( self::TOKEN_OPTION, [] );
		if ( empty( $token ) || ! is_array( $token ) || empty( $token['access_token'] ) ) {
			throw new ApiException( 'Google account not connected', 0 );
		}
		if ( time() >= (int) ( $token['expires_at'] ?? 0 ) ) {
			return $this->refresh( $token );
		}
		return (string) $token['access_token'];
	}

	/**
	 * Refresh unconditionally (used after a 401 on a token that looked valid).
	 *
	 * @throws ApiException
	 */
	public function forceRefresh(): void {
		$token = get_option( self::TOKEN_OPTION, [] );
		$this->refresh( is_array( $token ) ? $token : [] );
	}

	public function disconnect(): void {
		$token = get_option( self::TOKEN_OPTION, [] );
		$revoke = is_array( $token ) ? ( $token['refresh_token'] ?? $token['access_token'] ?? '' ) : '';
		if ( '' !== $revoke ) {
			try {
				Http::postForm( self::REVOKE_ENDPOINT, [ 'token' => (string) $revoke ] );
			} catch ( ApiException $e ) {
				// Best effort; still clear locally.
			}
		}
		delete_option( self::TOKEN_OPTION );
	}

	/**
	 * @param array<string,mixed> $current
	 * @throws ApiException
	 */
	private function refresh( array $current ): string {
		$refreshToken = $current['refresh_token'] ?? '';
		if ( '' === $refreshToken ) {
			throw new ApiException( 'No refresh token stored; reconnect required', 0 );
		}
		$new = Http::postForm(
			self::TOKEN_ENDPOINT,
			[
				'client_id'     => $this->clientId,
				'client_secret' => $this->clientSecret,
				'refresh_token' => (string) $refreshToken,
				'grant_type'    => 'refresh_token',
			]
		);
		if ( empty( $new['access_token'] ) ) {
			throw new ApiException( 'Token refresh returned no access_token', 0 );
		}
		// Google omits refresh_token on refresh; keep the existing one.
		$new['refresh_token'] = $new['refresh_token'] ?? $refreshToken;
		$this->storeToken( array_merge( $current, $new ) );
		return (string) $new['access_token'];
	}

	/** @param array<string,mixed> $token */
	private function storeToken( array $token ): void {
		$expiresIn            = (int) ( $token['expires_in'] ?? 3600 );
		$token['expires_at']  = time() + max( 0, $expiresIn - self::EXPIRY_SKEW );
		unset( $token['expires_in'] );
		update_option( self::TOKEN_OPTION, $token, false );
	}
}
