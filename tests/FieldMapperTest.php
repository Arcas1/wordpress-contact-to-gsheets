<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\FieldMapper;

final class FieldMapperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data ) => json_encode( $data )
		);
	}

	private function mapper(): FieldMapper {
		// Deterministic is_email stub: value contains "@" and a dot after it.
		return new FieldMapper(
			static fn( $v ) => is_string( $v ) && (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $v )
		);
	}

	public function test_detects_email_name_and_message_from_cf7_shape(): void {
		$posted = [
			'your-name'    => 'Ada Lovelace',
			'your-email'   => 'ada@example.com',
			'your-subject' => 'Hi',
			'your-message' => 'I would like a quote for 200 units, please call me back.',
			'fv_plugin'    => 'cf7',
			'fv_form_id'   => '42',
		];

		$row = $this->mapper()->toRow( 'cf7', 42, 'Contact Us', $posted, '2026-08-26T23:00:00-05:00' );

		$this->assertCount( 6, $row );
		$this->assertSame( '2026-08-26T23:00:00-05:00', $row[0] );
		$this->assertSame( 'Contact Us', $row[1] );
		$this->assertSame( 'Ada Lovelace', $row[2] );
		$this->assertSame( 'ada@example.com', $row[3] );
		$this->assertSame( 'I would like a quote for 200 units, please call me back.', $row[4] );

		$data = json_decode( $row[5], true );
		$this->assertArrayNotHasKey( 'fv_plugin', $data );
		$this->assertArrayNotHasKey( 'fv_form_id', $data );
		$this->assertSame( 'ada@example.com', $data['your-email'] );
	}

	public function test_falls_back_to_plugin_and_form_id_when_title_empty(): void {
		$row = $this->mapper()->toRow( 'wpforms', 7, '', [ 'email' => 'x@y.com' ], 'T' );
		$this->assertSame( 'wpforms #7', $row[1] );
	}

	public function test_flattens_array_values_and_keeps_them_in_data(): void {
		$posted = [
			'name'      => 'Bob',
			'email'     => 'bob@example.com',
			'interests' => [ 'sales', 'support' ],
		];
		$row  = $this->mapper()->toRow( 'gravityforms', 1, 'X', $posted, 'T' );
		$data = json_decode( $row[5], true );
		$this->assertSame( 'sales, support', $data['interests'] );
	}

	public function test_email_key_regex_fallback_when_is_email_never_matches(): void {
		$posted = [ 'contact_correo' => 'not-a-real-email-but-labeled', 'nombre' => 'Sam' ];
		$row    = $this->mapper()->toRow( 'elementor', 1, 'X', $posted, 'T' );
		$this->assertSame( 'not-a-real-email-but-labeled', $row[3] );
		$this->assertSame( 'Sam', $row[2] );
	}

	public function test_missing_columns_are_empty_strings(): void {
		$row = $this->mapper()->toRow( 'cf7', 1, 'X', [ 'only' => 'a@b.com' ], 'T' );
		$this->assertSame( '', $row[2] ); // name
		$this->assertSame( 'a@b.com', $row[3] );
		$this->assertSame( '', $row[4] ); // message (no non-email fields remain)
	}

	public function test_message_is_longest_remaining_value(): void {
		$posted = [
			'name'  => 'Jo',
			'email' => 'jo@example.com',
			'phone' => '555-1000',
			'notes' => 'This sentence is clearly the longest free text field in the form.',
		];
		$row = $this->mapper()->toRow( 'cf7', 1, 'X', $posted, 'T' );
		$this->assertSame( 'This sentence is clearly the longest free text field in the form.', $row[4] );
	}
}
