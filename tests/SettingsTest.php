<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ErrorLog;
use C2GS\GoogleAuth;
use C2GS\Settings;
use Mockery;

final class SettingsTest extends TestCase {

	private function settings(): Settings {
		return new Settings( Mockery::mock( GoogleAuth::class ), Mockery::mock( ErrorLog::class ) );
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
	}

	public function test_sanitize_trims_fields_and_defaults_tab_name(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$out = $this->settings()->sanitize( [
			'client_id'      => '  abc.apps.googleusercontent.com  ',
			'client_secret'  => ' s3cr3t ',
			'spreadsheet_id' => ' 1A2b3C_-xyz ',
			'tab_name'       => '  ',
		] );

		$this->assertSame( 'abc.apps.googleusercontent.com', $out['client_id'] );
		$this->assertSame( 's3cr3t', $out['client_secret'] );
		$this->assertSame( '1A2b3C_-xyz', $out['spreadsheet_id'] );
		$this->assertSame( 'Submissions', $out['tab_name'] );
	}

	public function test_sanitize_rejects_bad_spreadsheet_id_and_keeps_old_value(): void {
		Functions\when( 'get_option' )->justReturn( [ 'spreadsheet_id' => 'GOOD_OLD_ID' ] );
		Functions\expect( 'add_settings_error' )->once();

		$out = $this->settings()->sanitize( [
			'client_id'      => 'x',
			'client_secret'  => 'y',
			'spreadsheet_id' => 'has spaces/and/slashes',
			'tab_name'       => 'Leads',
		] );

		$this->assertSame( 'GOOD_OLD_ID', $out['spreadsheet_id'] );
		$this->assertSame( 'Leads', $out['tab_name'] );
	}

	public function test_redirect_uri_uses_callback_action(): void {
		Functions\when( 'admin_url' )->alias( static fn( $p ) => 'https://site/wp-admin/' . $p );
		$this->assertSame(
			'https://site/wp-admin/admin-post.php?action=c2gs_oauth_cb',
			$this->settings()->redirectUri()
		);
	}

	public function test_parse_client_json_reads_web_client(): void {
		$json = json_encode( [
			'web' => [
				'client_id'     => '123-abc.apps.googleusercontent.com',
				'client_secret' => 'GOCSPX-secret',
				'redirect_uris' => [ 'https://site/wp-admin/admin-post.php?action=c2gs_oauth_cb' ],
			],
		] );

		$out = $this->settings()->parseClientJson( $json );

		$this->assertSame( '123-abc.apps.googleusercontent.com', $out['client_id'] );
		$this->assertSame( 'GOCSPX-secret', $out['client_secret'] );
		$this->assertArrayNotHasKey( 'error', $out );
	}

	public function test_parse_client_json_reads_installed_client(): void {
		$json = json_encode( [ 'installed' => [ 'client_id' => 'id', 'client_secret' => 'sec' ] ] );
		$out  = $this->settings()->parseClientJson( $json );
		$this->assertSame( 'id', $out['client_id'] );
		$this->assertSame( 'sec', $out['client_secret'] );
	}

	public function test_parse_client_json_rejects_service_account_key(): void {
		$json = json_encode( [ 'type' => 'service_account', 'private_key' => '-----BEGIN', 'client_email' => 'x@y.iam' ] );
		$out  = $this->settings()->parseClientJson( $json );
		$this->assertArrayHasKey( 'error', $out );
		$this->assertStringContainsString( 'service account', $out['error'] );
	}

	public function test_parse_client_json_rejects_garbage(): void {
		$this->assertArrayHasKey( 'error', $this->settings()->parseClientJson( 'not json' ) );
		$this->assertArrayHasKey( 'error', $this->settings()->parseClientJson( json_encode( [ 'web' => [ 'client_id' => 'x' ] ] ) ) );
	}
}
