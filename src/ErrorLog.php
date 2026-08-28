<?php
/**
 * Capped, newest-first ring buffer of sync failures, stored in one option.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Newest-first ring buffer of sync failures, stored in one option.
 */
class ErrorLog {

	public const OPTION = 'c2gs_error_log';
	public const MAX    = 50;

	/**
	 * @param array{form_id?:mixed,plugin_name?:string,http_code?:int,message:string} $record
	 */
	public function add( array $record ): void {
		$entry = [
			'time'        => time(),
			'form_id'     => $record['form_id'] ?? null,
			'plugin_name' => (string) ( $record['plugin_name'] ?? '' ),
			'http_code'   => (int) ( $record['http_code'] ?? 0 ),
			'message'     => (string) ( $record['message'] ?? '' ),
		];

		$list = get_option( self::OPTION, [] );
		if ( ! is_array( $list ) ) {
			$list = [];
		}

		array_unshift( $list, $entry );
		$list = array_slice( $list, 0, self::MAX );

		update_option( self::OPTION, $list, false );
	}

	/** @return list<array<string,mixed>> */
	public function all(): array {
		$list = get_option( self::OPTION, [] );
		return is_array( $list ) ? $list : [];
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}
}
