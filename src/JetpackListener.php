<?php
/**
 * Adapter: Jetpack / block contact form submissions to the shared sync pipeline.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Adapter for the Jetpack / Gutenberg contact form. Hooks
 * grunion_after_feedback_post_inserted; the second argument is the visible
 * field set (Contact_Form_Field objects keyed by id).
 */
final class JetpackListener {

	public function __construct( private SubmissionSync $sync ) {}

	/**
	 * @param int|string $postId
	 * @param mixed      $fields      Map of id => Contact_Form_Field (or array/scalar).
	 * @param bool       $isSpam
	 * @param mixed      $entryValues Metadata (entry_title, permalink, ...).
	 */
	public function handle( $postId, $fields = [], $isSpam = false, $entryValues = [] ): void {
		if ( $isSpam || ! is_array( $fields ) || [] === $fields ) {
			return;
		}

		$map = [];
		foreach ( $fields as $key => $field ) {
			[ $label, $value ] = $this->readField( $key, $field );
			if ( '' === $label ) {
				continue;
			}
			$map[ $label ] = $value;
		}
		if ( [] === $map ) {
			return;
		}

		$entryValues = is_array( $entryValues ) ? $entryValues : [];
		$title       = ! empty( $entryValues['entry_title'] ) ? (string) $entryValues['entry_title'] : '';
		$url         = ! empty( $entryValues['entry_permalink'] ) ? (string) $entryValues['entry_permalink'] : '';

		$this->sync->sync( 'jetpack', is_scalar( $postId ) ? $postId : '', $title, $map, $url );
	}

	/**
	 * @param int|string $key
	 * @param mixed      $field
	 * @return array{0:string,1:mixed}
	 */
	private function readField( $key, $field ): array {
		$fallbackLabel = is_string( $key ) ? $key : ( 'field_' . $key );

		if ( is_object( $field ) ) {
			$label = method_exists( $field, 'get_attribute' ) ? (string) $field->get_attribute( 'label' ) : '';
			$value = property_exists( $field, 'value' ) ? $field->value : '';
			return [
				'' !== $label ? $label : $fallbackLabel,
				( is_scalar( $value ) || is_array( $value ) ) ? $value : '',
			];
		}

		if ( is_array( $field ) ) {
			return [
				(string) ( $field['label'] ?? $fallbackLabel ),
				$field['value'] ?? '',
			];
		}

		return [ $fallbackLabel, is_scalar( $field ) ? (string) $field : '' ];
	}
}
