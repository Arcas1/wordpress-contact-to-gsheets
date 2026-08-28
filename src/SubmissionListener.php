<?php

namespace C2GS;

/**
 * Adapter for Form Vibes: reads the fv_after_entry_meta_success payload and
 * hands it to SubmissionSync. Covers every form plugin Form Vibes integrates
 * (Contact Form 7, WPForms, Elementor, Gravity Forms, and more).
 */
final class SubmissionListener {

	public function __construct( private SubmissionSync $sync ) {}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function handle( array $payload ): void {
		$entryData = is_array( $payload['entry_data'] ?? null ) ? $payload['entry_data'] : [];

		// Form Vibes 1.5.3 puts the submitted fields in 'entires' (its own
		// misspelling). Fall back to other shapes for resilience.
		$fields = $payload['entires']
			?? $payload['entries']
			?? ( $entryData['posted_data'] ?? [] );
		if ( ! is_array( $fields ) || [] === $fields ) {
			return;
		}

		$source = (string) ( $payload['plugin_name'] ?? ( $entryData['form_plugin'] ?? 'form-vibes' ) );
		$formId = $payload['form_id'] ?? ( $entryData['form_id'] ?? '' );
		$title  = (string) ( $entryData['title'] ?? '' );

		$this->sync->sync( $source, is_scalar( $formId ) ? $formId : '', $title, $fields );
	}
}
