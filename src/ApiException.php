<?php

namespace C2GS;

/**
 * Raised for any non-success response from the Google OAuth or Sheets REST
 * APIs. getCode() carries the HTTP status (0 for transport errors).
 */
final class ApiException extends \RuntimeException {}
