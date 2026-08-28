<?php
/**
 * Turns a submitted-fields map into the fixed six-column sheet row.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Turns a Form Vibes posted-data map into the fixed 6-column sheet row.
 *
 * Pure: the only outside call is an injectable email validator (default
 * WordPress is_email) and wp_json_encode for the data column.
 */
final class FieldMapper {

	public const INTERNAL_KEYS = [ 'fv_plugin', 'fv_form_id', 'IP' ];

	/** @var callable */
	private $isEmail;

	public function __construct( ?callable $isEmail = null ) {
		$this->isEmail = $isEmail ?? 'is_email';
	}

	/**
	 * @param array<string,mixed> $postedData Field name => value (string or array).
	 * @return array{0:string,1:string,2:string,3:string,4:string,5:string}
	 */
	public function toRow( string $pluginName, string|int $formId, string $title, array $postedData, string $timestamp, ?string $emailKeyHint = null ): array {
		$clean = $this->clean( $postedData );

		[ $emailKey, $email ] = $this->pickEmail( $clean, $emailKeyHint );
		[ $nameKey, $name ]   = $this->pickName( $clean, $emailKey, $email );
		$message              = $this->pickMessage( $clean, $emailKey, $nameKey );

		$form = '' !== $title ? $title : $pluginName . ' #' . $formId;
		$data = (string) wp_json_encode( $clean );

		return [ $timestamp, $form, $name, $email, $message, $data ];
	}

	/**
	 * @param array<string,mixed> $posted
	 * @return array<string,string>
	 */
	private function clean( array $posted ): array {
		$out = [];
		foreach ( $posted as $key => $value ) {
			if ( in_array( $key, self::INTERNAL_KEYS, true ) ) {
				continue;
			}
			$out[ (string) $key ] = is_array( $value )
				? implode( ', ', array_map( 'strval', $this->flatten( $value ) ) )
				: (string) $value;
		}
		return $out;
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

	/**
	 * @param array<string,string> $clean
	 * @return array{0:?string,1:string}
	 */
	private function pickEmail( array $clean, ?string $hint = null ): array {
		if ( null !== $hint && '' !== $hint && array_key_exists( $hint, $clean ) && '' !== $clean[ $hint ] ) {
			return [ $hint, $clean[ $hint ] ];
		}
		foreach ( $clean as $key => $value ) {
			if ( ( $this->isEmail )( $value ) ) {
				return [ $key, $value ];
			}
		}
		foreach ( $clean as $key => $value ) {
			if ( preg_match( '/e-?mail|correo/i', $key ) ) {
				return [ $key, $value ];
			}
		}
		return [ null, '' ];
	}

	/**
	 * @param array<string,string> $clean
	 * @return array{0:?string,1:string}
	 */
	private function pickName( array $clean, ?string $emailKey, string $email ): array {
		foreach ( $clean as $key => $value ) {
			if ( $key === $emailKey ) {
				continue;
			}
			if ( preg_match( '/name|nombre/i', $key ) && $value !== $email ) {
				return [ $key, $value ];
			}
		}
		foreach ( $clean as $key => $value ) {
			if ( $key === $emailKey || '' === $value ) {
				continue;
			}
			return [ $key, $value ];
		}
		return [ null, '' ];
	}

	/**
	 * @param array<string,string> $clean
	 */
	private function pickMessage( array $clean, ?string $emailKey, ?string $nameKey ): string {
		$remaining = [];
		foreach ( $clean as $key => $value ) {
			if ( $key === $emailKey || $key === $nameKey ) {
				continue;
			}
			$remaining[ $key ] = $value;
		}
		if ( [] === $remaining ) {
			return '';
		}

		$longest = '';
		foreach ( $remaining as $value ) {
			if ( mb_strlen( $value ) > mb_strlen( $longest ) ) {
				$longest = $value;
			}
		}
		if ( '' !== $longest ) {
			return $longest;
		}

		$pairs = [];
		foreach ( $remaining as $key => $value ) {
			$pairs[] = $key . ': ' . $value;
		}
		return implode( "\n", $pairs );
	}
}
