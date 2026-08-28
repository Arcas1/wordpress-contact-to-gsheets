<?php
/**
 * Adapter: Form Vibes submissions to the shared sync pipeline.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

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

		// Elementor Pro has its own listener with real field labels; skip the
		// Form Vibes copy to avoid a duplicate row.
		if ( 'elementor' === $source && class_exists( '\ElementorPro\Modules\Forms\Module' ) ) {
			return;
		}

		$formId = $payload['form_id'] ?? ( $entryData['form_id'] ?? '' );
		$title  = (string) ( $entryData['title'] ?? '' );
		$url    = (string) ( $entryData['url'] ?? '' );

		$this->sync->sync( $source, is_scalar( $formId ) ? $formId : '', $title, $fields, $url );
	}
}
