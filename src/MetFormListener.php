<?php
/**
 * Adapter: MetForm submissions to the shared sync pipeline.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Adapter for MetForm (Wpmet), which Form Vibes 1.5.3 does not integrate.
 * Hooks metform_after_store_form_data and hands the fields to SubmissionSync.
 */
final class MetFormListener {

	/**
	 * MetForm control/plumbing keys that are not real form fields.
	 */
	private const NOISE = [
		'form_nonce',
		'hidden-fields',
		'g-recaptcha-response',
		'g-recaptcha-response-v3',
		'mf-captcha-challenge',
		'mf_payment_first',
		'mf_payment_completed',
		'mf_payment_method',
		'mf_entry_id',
		'mf_page_id',
		'mf-listing-optin',
	];

	public function __construct( private SubmissionSync $sync ) {}

	/**
	 * @param int|string          $formId
	 * @param mixed               $formData     Expected array<string,mixed> of field => value.
	 * @param mixed               $formSettings Expected array<string,mixed>.
	 * @param mixed               $attributes   Unused; MetForm passes email_field_name / file info here.
	 */
	public function handle( $formId, $formData = [], $formSettings = [], $attributes = [] ): void {
		if ( ! is_array( $formData ) || [] === $formData ) {
			return;
		}

		$fields = array_diff_key( $formData, array_flip( self::NOISE ) );
		foreach ( array_keys( $fields ) as $key ) {
			if ( str_starts_with( (string) $key, 'g-recaptcha' ) ) {
				unset( $fields[ $key ] );
			}
		}
		if ( [] === $fields ) {
			return;
		}

		$title = '';
		if ( is_array( $formSettings ) && ! empty( $formSettings['form_title'] ) ) {
			$title = (string) $formSettings['form_title'];
		}
		if ( '' === $title && is_scalar( $formId ) && function_exists( 'get_the_title' ) ) {
			$fetched = get_the_title( (int) $formId );
			$title   = is_string( $fetched ) ? $fetched : '';
		}

		$this->sync->sync( 'metform', is_scalar( $formId ) ? $formId : '', $title, $fields );
	}
}
