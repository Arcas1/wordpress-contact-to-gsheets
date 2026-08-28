<?php

namespace C2GS\Tests;

use C2GS\FieldMapper;

final class FieldMapperTest extends TestCase {

	private function mapper(): FieldMapper {
		return new FieldMapper();
	}

	public function test_keeps_real_fields_by_their_key(): void {
		$out = $this->mapper()->normalize( [
			'Nombre de la empresa' => 'Acme Inc',
			'name'                 => 'Ada',
			'email'                => 'ada@example.com',
			'message'              => 'Hola',
		] );

		$this->assertSame(
			[
				'Nombre de la empresa' => 'Acme Inc',
				'name'                 => 'Ada',
				'email'                => 'ada@example.com',
				'message'              => 'Hola',
			],
			$out
		);
	}

	public function test_strips_internal_and_captcha_keys(): void {
		$out = $this->mapper()->normalize( [
			'email'                    => 'a@b.com',
			'fv_plugin'                => 'elementor',
			'fv_form_id'               => '12',
			'IP'                       => '1.2.3.4',
			'form_id'                  => '1b208a1',
			'post_id'                  => '75',
			'referer_title'            => 'Cotiza servicios',
			'g-recaptcha-response'     => 'token',
			'g-recaptcha-response-v3'  => 'token3',
		] );

		$this->assertSame( [ 'email' => 'a@b.com' ], $out );
	}

	public function test_drops_empty_values(): void {
		$out = $this->mapper()->normalize( [
			'name'    => 'Ada',
			'phone'   => '',
			'notes'   => '   ',
			'company' => 'Acme',
		] );
		$this->assertSame( [ 'name' => 'Ada', 'company' => 'Acme' ], $out );
	}

	public function test_flattens_array_values(): void {
		$out = $this->mapper()->normalize( [
			'interests' => [ 'sales', 'support' ],
			'name'      => [ 'first' => 'Ada', 'last' => 'Lovelace' ],
		] );
		$this->assertSame(
			[ 'interests' => 'sales, support', 'name' => 'Ada, Lovelace' ],
			$out
		);
	}
}
