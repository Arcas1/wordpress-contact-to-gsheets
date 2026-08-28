<?php

namespace C2GS;

/**
 * Adapter for Fluent Forms (WPManageNinja), which Form Vibes 1.5.3 does not
 * integrate. Hooks fluentform/submission_inserted and hands the fields to
 * SubmissionSync.
 */
final class FluentFormListener {

	/**
	 * Fluent Forms plumbing keys that are not real form fields.
	 */
	private const NOISE = [
		'_wp_http_referer',
		'__fluent_form_embded_post_id',
		'_fluent_form_embded_post_id',
		'_fluentform_5_fluentformnonce',
		'g-recaptcha-response',
		'h-captcha-response',
		'cf-turnstile-response',
	];

	public function __construct( private SubmissionSync $sync ) {}

	/**
	 * @param int|string $insertId
	 * @param mixed      $formData Expected array<string,mixed> of field => value.
	 * @param mixed      $form     Expected object with ->id and ->title.
	 */
	public function handle( $insertId, $formData = [], $form = null ): void {
		if ( ! is_array( $formData ) || [] === $formData ) {
			return;
		}

		$fields = array_diff_key( $formData, array_flip( self::NOISE ) );
		foreach ( array_keys( $fields ) as $key ) {
			$k = (string) $key;
			if ( str_starts_with( $k, '_' ) || str_contains( $k, 'recaptcha' ) || str_contains( $k, 'turnstile' ) ) {
				unset( $fields[ $key ] );
			}
		}
		if ( [] === $fields ) {
			return;
		}

		$formId = ( is_object( $form ) && isset( $form->id ) ) ? $form->id : '';
		$title  = ( is_object( $form ) && ! empty( $form->title ) ) ? (string) $form->title : '';

		$this->sync->sync( 'fluentform', is_scalar( $formId ) ? $formId : '', $title, $fields );
	}
}
