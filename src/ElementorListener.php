<?php
/**
 * Adapter: Elementor Pro form submissions to the shared sync pipeline.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Hooks elementor_pro/forms/new_record. Unlike the Form Vibes path, the
 * Elementor form record carries each field's human label, so columns come out
 * readable ("Nombre de la empresa") instead of generated IDs ("field_be8ff7f").
 */
final class ElementorListener {

	public function __construct( private SubmissionSync $sync ) {}

	/**
	 * @param mixed $record  \ElementorPro\Modules\Forms\Classes\Form_Record
	 * @param mixed $handler  Unused.
	 */
	public function handle( $record, $handler = null ): void {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$raw = $record->get( 'fields' );
		if ( ! is_array( $raw ) || [] === $raw ) {
			return;
		}

		$fields = [];
		foreach ( $raw as $id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$label = (string) ( $field['title'] ?? '' );
			if ( '' === $label ) {
				$label = (string) ( $field['id'] ?? $id );
			}
			$fields[ $label ] = $field['value'] ?? '';
		}
		if ( [] === $fields ) {
			return;
		}

		$hasSettings = method_exists( $record, 'get_form_settings' );
		$title       = $hasSettings ? (string) $record->get_form_settings( 'form_name' ) : '';
		$formId      = $hasSettings ? (string) $record->get_form_settings( 'id' ) : '';

		$meta = $record->get( 'meta' );
		$url  = ( is_array( $meta ) && isset( $meta['page_url']['value'] ) ) ? (string) $meta['page_url']['value'] : '';

		$this->sync->sync( 'elementor', '' !== $formId ? $formId : '', $title, $fields, $url );
	}
}
