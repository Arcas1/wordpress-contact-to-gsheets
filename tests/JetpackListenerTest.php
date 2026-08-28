<?php

namespace C2GS\Tests;

use C2GS\JetpackListener;
use C2GS\SubmissionSync;
use Mockery;

/**
 * Minimal stand-in for Jetpack's Contact_Form_Field.
 */
final class FakeJetpackField {

	/** @var array<string,string> */
	private array $attrs;
	public mixed $value;

	/**
	 * @param array<string,string> $attrs
	 */
	public function __construct( array $attrs, mixed $value ) {
		$this->attrs = $attrs;
		$this->value = $value;
	}

	public function get_attribute( string $key ): string {
		return $this->attrs[ $key ] ?? '';
	}
}

final class JetpackListenerTest extends TestCase {

	public function test_reads_field_objects_and_flags_email_field(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'jetpack',
			55,
			'Contact',
			[
				'Name'    => 'Ada',
				'Email'   => 'ada@example.com',
				'Message' => 'Hello there team.',
			],
			'Email'
		);

		( new JetpackListener( $sync ) )->handle(
			55,
			[
				1 => new FakeJetpackField( [ 'label' => 'Name', 'type' => 'name' ], 'Ada' ),
				2 => new FakeJetpackField( [ 'label' => 'Email', 'type' => 'email' ], 'ada@example.com' ),
				3 => new FakeJetpackField( [ 'label' => 'Message', 'type' => 'textarea' ], 'Hello there team.' ),
			],
			false,
			[ 'entry_title' => 'Contact' ]
		);
	}

	public function test_skips_spam(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->never();

		( new JetpackListener( $sync ) )->handle(
			1,
			[ 1 => new FakeJetpackField( [ 'label' => 'Email', 'type' => 'email' ], 'a@b.com' ) ],
			true,
			[]
		);
	}

	public function test_accepts_plain_array_fields(): void {
		$sync = Mockery::mock( SubmissionSync::class );
		$sync->shouldReceive( 'sync' )->once()->with(
			'jetpack',
			2,
			'',
			[ 'Your email' => 'a@b.com' ],
			'Your email'
		);

		( new JetpackListener( $sync ) )->handle(
			2,
			[ 'f1' => [ 'label' => 'Your email', 'type' => 'email', 'value' => 'a@b.com' ] ],
			false,
			[]
		);
	}
}
