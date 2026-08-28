<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;

/**
 * Stubs the WordPress HTTP API so C2GS\Http (and callers) can be exercised
 * without real network access.
 */
trait HttpMockTrait {

	/**
	 * Queue of responses; each wp_remote_* call shifts one off.
	 * A response is [ 'code' => int, 'body' => string|array, 'wp_error' => ?string ].
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $httpQueue = [];

	/**
	 * Requests captured, in order: [ 'method' => string, 'url' => string, 'args' => array ].
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $httpCalls = [];

	protected function mockHttp(): void {
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c ) => parse_url( $u, $c ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] ?? '' );

		$handler = function ( $url, $args = [] ) {
			$this->httpCalls[] = [
				'method' => strtoupper( (string) ( $args['method'] ?? ( isset( $args['body'] ) ? 'POST' : 'GET' ) ) ),
				'url'    => $url,
				'args'   => $args,
			];
			$next = array_shift( $this->httpQueue ) ?? [ 'code' => 200, 'body' => [] ];
			if ( ! empty( $next['wp_error'] ) ) {
				return new \WP_Error( 'http_request_failed', $next['wp_error'] );
			}
			$body = $next['body'] ?? [];
			return [
				'response' => [ 'code' => $next['code'] ?? 200 ],
				'body'     => is_string( $body ) ? $body : json_encode( $body ),
			];
		};

		Functions\when( 'wp_remote_post' )->alias( $handler );
		Functions\when( 'wp_remote_get' )->alias( $handler );
		Functions\when( 'wp_remote_request' )->alias( $handler );
	}

	/**
	 * @param array<string,mixed>|string $body
	 */
	protected function queueResponse( int $code, $body = [] ): void {
		$this->httpQueue[] = [ 'code' => $code, 'body' => $body ];
	}

	protected function queueWpError( string $message = 'boom' ): void {
		$this->httpQueue[] = [ 'wp_error' => $message ];
	}
}
