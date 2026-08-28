<?php

namespace C2GS\Tests;

use C2GS\ElementorListener;
use C2GS\SubmissionSync;
use Mockery;

/**
 * Minimal stand-in for Elementor Pro's Form_Record.
 */
final class FakeElementorRecord {

	/** @param array<string,mixed> $data */
	public function __construct( private array $data, private array $settings ) {}

	public function get( string $key ) {
		return $this->data[ $key ] ?? null;
	}

	public function get_form_settings( string $key ) {
		return $this->settings[ $key ] ?? '';
	}
}

final class ElementorListenerTest extends TestCase {

	public function test_uses_field_labels_form_name_and_page_url(): void {
		$record = new FakeElementorRecord(
			[
				'fields' => [
					'field_be8ff7f' => [ 'id' => 'field_be8ff7f', 'title' => 'Nombre de la empresa', 'value' => 'Acme' ],
					'name'          => [ 'id' => 'name', 'title' => 'Name', 'value' => 'Ada' ],
					'email'         => [ 'id' => 'email', 'title' => 'Email', 'value' => 'ada@acme.com' ],
					'field_83ac527' => [ 'id' => 'field_83ac527', 'title' => 'Telefono', 'value' => '3001234567' ],
				],
				'meta'   => [ 'page_url' => [ 'value' => 'https://xegmenta.com/cotiza' ] ],
			],
			[ 'form_name' => 'Formulario de Contacto', 'id' => '1b208a1' ]
		);

		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'elementor',
			'1b208a1',
			'Formulario de Contacto',
			[
				'Nombre de la empresa' => 'Acme',
				'Name'                 => 'Ada',
				'Email'                => 'ada@acme.com',
				'Telefono'             => '3001234567',
			],
			'https://xegmenta.com/cotiza'
		);

		( new ElementorListener( $sync ) )->handle( $record );
	}

	public function test_falls_back_to_field_id_when_a_label_is_missing(): void {
		$record = new FakeElementorRecord(
			[ 'fields' => [ 'field_x' => [ 'id' => 'field_x', 'value' => 'v' ] ] ],
			[ 'form_name' => 'F', 'id' => '9' ]
		);

		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with( 'elementor', '9', 'F', [ 'field_x' => 'v' ], '' );

		( new ElementorListener( $sync ) )->handle( $record );
	}

	public function test_ignores_a_record_with_no_fields(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		( new ElementorListener( $sync ) )->handle(
			new FakeElementorRecord( [ 'fields' => [] ], [] )
		);
		( new ElementorListener( $sync ) )->handle( null );
	}
}
