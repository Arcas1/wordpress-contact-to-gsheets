<?php

namespace C2GS;

/**
 * Adapter for Forminator (WPMU DEV). Hooks
 * forminator_custom_form_submit_before_set_fields, whose third argument is a
 * list of [ 'name' => <field slug>, 'value' => <mixed> ]. Slugs encode the
 * field type (email-1, name-1, textarea-1), so no separate label lookup is
 * needed.
 */
final class ForminatorListener {

	/**
	 * Slug prefixes that are not real answer fields.
	 */
	private const NOISE_PREFIXES = [
		'captcha',
		'recaptcha',
		'hcaptcha',
		'turnstile',
		'stripe',
		'paypal',
		'page-break',
		'html-',
		'section-',
	];

	public function __construct( private SubmissionSync $sync ) {}

	/**
	 * @param mixed      $entry     Forminator_Form_Entry_Model (unused).
	 * @param int|string $formId
	 * @param mixed      $fieldData Expected list of [ 'name' => string, 'value' => mixed ].
	 */
	public function handle( $entry, $formId = 0, $fieldData = [] ): void {
		if ( ! is_array( $fieldData ) || [] === $fieldData ) {
			return;
		}

		$fields    = [];
		$emailHint = null;
		foreach ( $fieldData as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['name'] ) ) {
				continue;
			}
			$slug = (string) $item['name'];
			if ( '' === $slug || $this->isNoise( $slug ) ) {
				continue;
			}
			$fields[ $slug ] = $item['value'] ?? '';
			if ( null === $emailHint && str_starts_with( $slug, 'email-' ) ) {
				$emailHint = $slug;
			}
		}
		if ( [] === $fields ) {
			return;
		}

		$title = '';
		if ( function_exists( 'forminator_get_form_name' ) && is_scalar( $formId ) ) {
			$title = (string) forminator_get_form_name( $formId );
		}

		$this->sync->sync( 'forminator', is_scalar( $formId ) ? $formId : '', $title, $fields, $emailHint );
	}

	private function isNoise( string $slug ): bool {
		foreach ( self::NOISE_PREFIXES as $prefix ) {
			if ( str_starts_with( $slug, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
