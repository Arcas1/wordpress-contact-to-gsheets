<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\FormidableListener;
use C2GS\SubmissionSync;
use Mockery;

final class FormidableListenerTest extends TestCase {

	/**
	 * FormidableListener with the two external seams stubbed.
	 *
	 * @param array<int|string,mixed> $meta
	 * @param list<array<string,mixed>> $fields
	 */
	private function listener( SubmissionSync $sync, array $meta, array $fields ): FormidableListener {
		return new class( $sync, $meta, $fields ) extends FormidableListener {
			/** @var array<int|string,mixed> */
			private array $metaStub;
			/** @var list<array<string,mixed>> */
			private array $fieldsStub;

			public function __construct( SubmissionSync $sync, array $meta, array $fields ) {
				parent::__construct( $sync );
				$this->metaStub   = $meta;
				$this->fieldsStub = $fields;
			}

			protected function postedMeta(): array {
				return $this->metaStub;
			}

			protected function fieldsForForm( int $formId ): array {
				return $this->fieldsStub;
			}
		};
	}

	public function test_maps_field_ids_to_labels_and_flags_email(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'formidable',
			9,
			'',
			[
				'Full name'   => 'Ada',
				'Correo'      => 'ada@example.com',
				'Tu consulta' => 'Long message here.',
			],
			'Correo'
		);

		$listener = $this->listener(
			$sync,
			[ 25 => 'Ada', 26 => 'ada@example.com', 27 => 'Long message here.', 99 => 'ignored' ],
			[
				[ 'id' => 25, 'name' => 'Full name', 'type' => 'text' ],
				[ 'id' => 26, 'name' => 'Correo', 'type' => 'email' ],
				[ 'id' => 27, 'name' => 'Tu consulta', 'type' => 'textarea' ],
				[ 'id' => 40, 'name' => 'Captcha', 'type' => 'captcha' ],
			]
		);

		$listener->handle( 100, 9, [ 'is_child' => false ] );
	}

	public function test_skips_repeater_child_entries(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		$this->listener( $sync, [ 1 => 'x' ], [ [ 'id' => 1, 'name' => 'F', 'type' => 'text' ] ] )
			->handle( 100, 9, [ 'is_child' => true ] );
	}

	public function test_ignores_when_meta_empty(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		$this->listener( $sync, [], [ [ 'id' => 1, 'name' => 'F', 'type' => 'text' ] ] )
			->handle( 100, 9, [] );
	}
}
