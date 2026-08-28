<?php
/**
 * Adapter: Formidable Forms submissions to the shared sync pipeline.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Adapter for Formidable Forms. Hooks frm_after_create_entry. Submitted values
 * arrive in $_POST['item_meta'] keyed by numeric field id, so the field
 * definitions are loaded to turn ids into labels and to spot the email field.
 */
class FormidableListener {

	/**
	 * Formidable field types that carry no answer.
	 */
	private const SKIP_TYPES = [ 'captcha', 'break', 'divider', 'html', 'summary', 'end_divider' ];

	public function __construct( private SubmissionSync $sync ) {}

	/**
	 * @param int|string          $entryId
	 * @param int|string          $formId
	 * @param array<string,mixed> $args Formidable passes compact('is_child').
	 */
	public function handle( $entryId, $formId = 0, $args = [] ): void {
		if ( is_array( $args ) && ! empty( $args['is_child'] ) ) {
			return; // Repeater / embedded sub-entry; the parent entry carries the data.
		}

		$meta = $this->postedMeta();
		if ( [] === $meta ) {
			return;
		}

		$fields = [];
		foreach ( $this->fieldsForForm( (int) $formId ) as $field ) {
			$id = $field['id'] ?? null;
			if ( null === $id || ! array_key_exists( $id, $meta ) ) {
				continue;
			}
			$type = (string) ( $field['type'] ?? '' );
			if ( in_array( $type, self::SKIP_TYPES, true ) ) {
				continue;
			}
			$label = (string) ( $field['name'] ?? ( 'field_' . $id ) );
			if ( '' === $label ) {
				$label = 'field_' . $id;
			}
			$fields[ $label ] = $meta[ $id ];
		}
		if ( [] === $fields ) {
			return;
		}

		$title = '';
		if ( class_exists( '\FrmForm' ) && method_exists( '\FrmForm', 'getName' ) && is_scalar( $formId ) ) {
			$title = (string) \FrmForm::getName( (int) $formId );
		}

		$this->sync->sync( 'formidable', is_scalar( $formId ) ? $formId : '', $title, $fields );
	}

	/**
	 * @return array<int|string,mixed>
	 */
	protected function postedMeta(): array {
		// Reached only from Formidable's frm_after_create_entry hook, which fires
		// after Formidable has validated the submission (including its nonce).
		if ( ! isset( $_POST['item_meta'] ) || ! is_array( $_POST['item_meta'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Formidable verified the submission before firing this hook.
			return [];
		}
		$raw = wp_unslash( $_POST['item_meta'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Formidable verified the submission before firing this hook.
		return function_exists( 'map_deep' ) ? map_deep( $raw, 'sanitize_textarea_field' ) : (array) $raw;
	}

	/**
	 * Field definitions for a form as a list of [ id, name, type ].
	 * Overridable for testing.
	 *
	 * @return list<array{id:int|string,name:string,type:string}>
	 */
	protected function fieldsForForm( int $formId ): array {
		if ( ! class_exists( '\FrmField' ) || ! method_exists( '\FrmField', 'get_all_for_form' ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) \FrmField::get_all_for_form( $formId ) as $field ) {
			$out[] = [
				'id'   => is_object( $field ) ? ( $field->id ?? '' ) : ( $field['id'] ?? '' ),
				'name' => is_object( $field ) ? ( $field->name ?? '' ) : ( $field['name'] ?? '' ),
				'type' => is_object( $field ) ? ( $field->type ?? '' ) : ( $field['type'] ?? '' ),
			];
		}
		return $out;
	}
}
