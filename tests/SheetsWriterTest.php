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

	private function body( int $call ): array {
		return json_decode( $this->httpCalls[ $call ]['args']['body'], true );
	}

	public function test_appends_aligned_row_when_columns_known_and_synced(): void {
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( [ 'form', 'name', 'email' ] );
		Functions\expect( 'update_option' )->never();

		$this->queueResponse( 200, [] ); // append only

		( new SheetsWriter( $this->auth(), 'SS', 'Submissions' ) )
			->append( 'Contact page', [ 'email' => 'a@b.com', 'name' => 'Ada' ] );

		$this->assertCount( 1, $this->httpCalls );
		$call = $this->httpCalls[0];
		$this->assertStringContainsString( rawurlencode( "'Submissions'!A1" ) . ':append', $call['url'] );
		$this->assertStringContainsString( 'valueInputOption=RAW', $call['url'] );
		$this->assertSame( [ [ 'Contact page', 'Ada', 'a@b.com' ] ], $this->body( 0 )['values'] );
	}

	public function test_extends_header_when_a_new_field_appears(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( [ 'form', 'name' ] );
		Functions\expect( 'update_option' )->once()->with(
			SheetsWriter::COLUMNS_OPTION,
			[ 'form', 'name', 'email' ],
			false
		);
		Functions\expect( 'set_transient' )->once()->with( SheetsWriter::SYNCED_TRANSIENT, 1, 600 );

		$this->queueResponse( 200, [ 'sheets' => [ [ 'properties' => [ 'title' => 'Submissions' ] ] ] ] ); // GET metadata
		$this->queueResponse( 200, [ 'values' => [ [ 'form', 'name' ] ] ] ); // GET header row
		$this->queueResponse( 200, [] ); // PUT header
		$this->queueResponse( 200, [] ); // POST append

		( new SheetsWriter( $this->auth(), 'SS', 'Submissions' ) )
			->append( 'Contact page', [ 'name' => 'Ada', 'email' => 'a@b.com' ] );

		$this->assertSame( 'PUT', $this->httpCalls[2]['method'] );
		$this->assertSame( [ [ 'form', 'name', 'email' ] ], $this->body( 2 )['values'] );
		$this->assertSame( [ [ 'Contact page', 'Ada', 'a@b.com' ] ], $this->body( 3 )['values'] );
	}

	public function test_creates_tab_and_seeds_form_column_on_a_blank_sheet(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );

		$this->queueResponse( 200, [ 'sheets' => [ [ 'properties' => [ 'title' => 'Sheet1' ] ] ] ] ); // GET metadata: tab missing
		$this->queueResponse( 200, [] ); // POST batchUpdate addSheet
		$this->queueResponse( 200, [] ); // GET header row -> empty
		$this->queueResponse( 200, [] ); // PUT header
		$this->queueResponse( 200, [] ); // POST append

		( new SheetsWriter( $this->auth(), 'SS', 'Submissions' ) )
			->append( 'Contact page', [ 'name' => 'Ada' ] );

		$this->assertStringContainsString( ':batchUpdate', $this->httpCalls[1]['url'] );
		$this->assertSame( [ [ 'form', 'name' ] ], $this->body( 3 )['values'] );
		$this->assertSame( [ [ 'Contact page', 'Ada' ] ], $this->body( 4 )['values'] );
	}

	public function test_quotes_tab_name_with_spaces(): void {
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( [ 'form', 'x' ] );
		$this->queueResponse( 200, [] );

		( new SheetsWriter( $this->auth(), 'SS', 'Form Submissions' ) )
			->append( 'p', [ 'x' => 'y' ] );

		$this->assertStringContainsString( rawurlencode( "'Form Submissions'!A1" ), $this->httpCalls[0]['url'] );
	}
}
