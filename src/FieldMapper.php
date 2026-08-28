<?php
/**
 * Turns a submitted-fields map into clean label => value pairs for the sheet.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Normalises a raw posted-data map (field key/label => value) into a flat
 * map of strings, dropping framework plumbing keys and empty values.
 */
final class FieldMapper {

	/** Exact keys that are never real answers. */
	public const INTERNAL_KEYS = [
		'fv_plugin',
		'fv_form_id',
		'IP',
		'form_id',
		'post_id',
		'queried_id',
		'referer_title',
		'_wpnonce',
		'_wp_http_referer',
		'action',
	];

	/** Key prefixes that are never real answers (captcha widgets, etc). */
	private const NOISE_PREFIXES = [
		'g-recaptcha',
		'h-captcha',
		'cf-turnstile',
		'recaptcha',
	];

	/**
	 * @param array<string,mixed> $posted Field key/label => value (value may be an array).
	 * @return array<string,string> Clean key => string, empty values removed.
	 */
	public function normalize( array $posted ): array {
		$out = [];
		foreach ( $posted as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || $this->isInternal( $key ) ) {
				continue;
			}

			$string = is_array( $value )
				? implode( ', ', array_map( 'strval', $this->flatten( $value ) ) )
				: (string) $value;

			$string = trim( $string );
			if ( '' === $string ) {
				continue;
			}
			$out[ $key ] = $string;
		}
		return $out;
	}

	private function isInternal( string $key ): bool {
		if ( in_array( $key, self::INTERNAL_KEYS, true ) ) {
			return true;
		}
		foreach ( self::NOISE_PREFIXES as $prefix ) {
			if ( 0 === stripos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private function flatten( array $value ): array {
		$flat = [];
		array_walk_recursive( $value, static function ( $v ) use ( &$flat ) {
			$flat[] = $v;
		} );
		return $flat;
	}
}
