<?php
/**
 * Exception type for non-success responses from the Google OAuth and Sheets REST APIs.
 *
 * @package   C2GS
 * @author    Arcas1
 * @copyright 2026 Arcas1
 * @license   GPL-2.0-or-later
 * @link      https://github.com/Arcas1/wordpress-contact-to-gsheets
 */

namespace C2GS;

/**
 * Raised for any non-success response from the Google OAuth or Sheets REST
 * APIs. getCode() carries the HTTP status (0 for transport errors).
 */
final class ApiException extends \RuntimeException {}
