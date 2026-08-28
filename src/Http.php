<?php

namespace C2GS;

/**
 * Thin JSON/HTTP helper over the WordPress HTTP API. Every non-2xx response
 * and every transport error becomes an ApiException whose code is the HTTP
 * status (0 for transport errors).
 */
final class Http {

	private const TIMEOUT = 15;

	/**
	 * Form-encoded POST (used for the OAuth token and revoke endpoints).
	 *
	 * @param array<string,string> $params
	 * @return array<string,mixed> Decoded JSON body ([] when the body is empty).
	 */
	public static function postForm( string $url, array $params ): array {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [ 'Accept' => 'application/json' ],
				'body'    => $params,
			]
		);
		return self::handle( $response, 'POST', $url );
	}

	/**
	 * JSON request with an optional bearer token.
	 *
	 * @param 'GET'|'POST'|'PUT'   $method
	 * @param array<mixed>|null    $body
	 * @return array<string,mixed> Decoded JSON body ([] when the body is empty).
	 */
	public static function json( string $method, string $url, ?string $bearer = null, ?array $body = null ): array {
		$args = [
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => [ 'Accept' => 'application/json' ],
		];
		if ( null !== $bearer ) {
			$args['headers']['Authorization'] = 'Bearer ' . $bearer;
		}
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		return self::handle( $response, $method, $url );
	}

	/**
	 * @param mixed $response Result of a wp_remote_* call.
	 * @return array<string,mixed>
	 */
	private static function handle( $response, string $method, string $url ): array {
		if ( is_wp_error( $response ) ) {
			throw new ApiException( $method . ' ' . self::host( $url ) . ': ' . $response->get_error_message(), 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = '' !== $raw ? json_decode( $raw, true ) : [];
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		if ( $code < 200 || $code >= 300 ) {
			$error   = $data['error'] ?? null;
			$message = '';
			if ( is_array( $error ) ) {
				$message = (string) ( $error['message'] ?? ( $error['error_description'] ?? '' ) );
			} elseif ( is_string( $error ) ) {
				$message = $error;
			} elseif ( is_string( $data['error_description'] ?? null ) ) {
				$message = $data['error_description'];
			}
			throw new ApiException(
				sprintf( '%s %s: HTTP %d%s', $method, self::host( $url ), $code, '' !== $message ? ' ' . $message : '' ),
				$code
			);
		}

		return $data;
	}

	private static function host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? $host : $url;
	}
}
