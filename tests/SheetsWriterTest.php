<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\GoogleAuth;
use C2GS\SheetsWriter;
use Mockery;

final class SheetsWriterTest extends TestCase {

	use HttpMockTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->mockHttp();
	}

	private function auth(): GoogleAuth {
		$auth = Mockery::mock( GoogleAuth::class );
		$auth->shouldReceive( 'accessToken' )->andReturn( 'TOK' );
		return $auth;
	}

	public function test_append_skips_preflight_when_transient_set(): void {
		Functions\expect( 'get_transient' )->once()->with( SheetsWriter::READY_TRANSIENT )->andReturn( 1 );
		Functions\expect( 'set_transient' )->never();
		$this->queueResponse( 200, [] ); // append

		( new SheetsWriter( $this->auth(), 'SS_ID', 'Submissions' ) )
			->append( [ 'r1', 'r2', 'r3', 'r4', 'r5', 'r6' ] );

		$this->assertCount( 1, $this->httpCalls );
		$call = $this->httpCalls[0];
		$this->assertSame( 'POST', $call['method'] );
		$this->assertStringContainsString( '/v4/spreadsheets/SS_ID/values/', $call['url'] );
		$this->assertStringContainsString( rawurlencode( "'Submissions'!A:F" ) . ':append', $call['url'] );
		$this->assertStringContainsString( 'valueInputOption=RAW', $call['url'] );
		$this->assertStringContainsString( 'insertDataOption=INSERT_ROWS', $call['url'] );
		$this->assertSame( [ [ 'r1', 'r2', 'r3', 'r4', 'r5', 'r6' ] ], json_decode( $call['args']['body'], true )['values'] );
		$this->assertSame( 'Bearer TOK', $call['args']['headers']['Authorization'] );
	}

	public function test_append_creates_missing_tab_then_writes_header(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()->with( SheetsWriter::READY_TRANSIENT, 1, 600 );

		$this->queueResponse( 200, [ 'sheets' => [ [ 'properties' => [ 'title' => 'Other' ] ] ] ] ); // GET metadata
		$this->queueResponse( 200, [] ); // POST batchUpdate addSheet
		$this->queueResponse( 200, [] ); // GET A1:F1 -> empty
		$this->queueResponse( 200, [] ); // PUT header
		$this->queueResponse( 200, [] ); // POST append

		( new SheetsWriter( $this->auth(), 'SS_ID', 'Submissions' ) )
			->append( [ 'a', 'b', 'c', 'd', 'e', 'f' ] );

		$this->assertStringContainsString( ':batchUpdate', $this->httpCalls[1]['url'] );
		$this->assertSame(
			'Submissions',
			json_decode( $this->httpCalls[1]['args']['body'], true )['requests'][0]['addSheet']['properties']['title']
		);
		$this->assertSame( 'PUT', $this->httpCalls[3]['method'] );
		$this->assertSame( SheetsWriter::HEADER, json_decode( $this->httpCalls[3]['args']['body'], true )['values'][0] );
		$this->assertSame( 'POST', $this->httpCalls[4]['method'] );
	}

	public function test_append_leaves_existing_header_alone(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once();

		$this->queueResponse( 200, [ 'sheets' => [ [ 'properties' => [ 'title' => 'Submissions' ] ] ] ] ); // metadata: tab exists
		$this->queueResponse( 200, [ 'values' => [ SheetsWriter::HEADER ] ] ); // header present
		$this->queueResponse( 200, [] ); // append

		( new SheetsWriter( $this->auth(), 'SS_ID', 'Submissions' ) )
			->append( [ 'a', 'b', 'c', 'd', 'e', 'f' ] );

		$this->assertCount( 3, $this->httpCalls );
		foreach ( $this->httpCalls as $call ) {
			$this->assertStringNotContainsString( ':batchUpdate', $call['url'] );
			$this->assertNotSame( 'PUT', $call['method'] );
		}
	}

	public function test_quotes_tab_name_with_spaces_in_the_range(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( 1 );
		$this->queueResponse( 200, [] );

		( new SheetsWriter( $this->auth(), 'SS_ID', 'Form Submissions' ) )
			->append( [ 'a', 'b', 'c', 'd', 'e', 'f' ] );

		$this->assertStringContainsString( rawurlencode( "'Form Submissions'!A:F" ), $this->httpCalls[0]['url'] );
	}
}
