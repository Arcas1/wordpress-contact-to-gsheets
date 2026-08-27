# Contact to GSheets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A WordPress plugin that appends every Form Vibes form submission to a Google Sheet in real time, one row per submission.

**Architecture:** A single `fv_after_entry_meta_success` action listener normalizes the submitted fields into a fixed 6-column row and appends it to a configured Google Sheet via `google/apiclient`. OAuth 2.0 (user consent, offline refresh token) authorizes the Sheets API. All work happens synchronously inside the hook, fully wrapped in `try/catch` so a failure never affects the visitor; failures are logged to a capped option and surfaced as an admin notice.

**Tech Stack:** PHP 8.1+, WordPress 6.0+, Composer (`google/apiclient`, `google/apiclient-services`), PHPUnit 10 + Brain Monkey + Mockery for unit tests. No WordPress test-suite dependency.

**Spec:** `docs/superpowers/specs/2026-08-26-contact-to-gsheets-design.md`

## Global Constraints

- PHP `>=8.1`, WordPress `>=6.0`.
- Namespace `C2GS\`; every option/transient/action prefixed `c2gs_`; text domain `contact-to-gsheets`.
- `vendor/` is committed to the repo (target hosts cannot run Composer).
- Real-time only. No cron, no retry queue. The listener body is fully wrapped in `try { … } catch ( \Throwable $e )` and never rethrows into WordPress.
- Options: `c2gs_settings` (autoload on), `c2gs_google_token` (autoload off), `c2gs_error_log` (autoload off).
- Transients: `c2gs_tab_ready` (10 min), `c2gs_fail_count` (1 week), `c2gs_not_connected` (1 day).
- Google OAuth scope: exactly `https://www.googleapis.com/auth/spreadsheets`. Access type `offline`, prompt `consent`.
- OAuth redirect URI: `admin_url( 'admin-post.php?action=c2gs_oauth_cb' )`.
- Row schema, fixed order: `[ timestamp (ISO 8601, site tz), form, name, email, message, data (JSON) ]`.
- Sheets append params: `valueInputOption=USER_ENTERED`, `insertDataOption=INSERT_ROWS`, range `"{tab}!A:F"`.
- Header row written with `valueInputOption=RAW` to `"{tab}!A1:F1"`: `["timestamp","form","name","email","message","data"]`.
- `c2gs_error_log` is a ring buffer, newest-first, hard max 50 entries.
- Capability gate `manage_options` on the settings page and every `admin_post_*` handler.
- Form Vibes internal keys stripped before mapping and before the `data` column: `fv_plugin`, `fv_form_id`, `IP`.

---

## File Structure

```
contact-to-gsheets/
├── contact-to-gsheets.php        # Plugin header, autoload guard, Plugin::instance()->boot()
├── uninstall.php                 # Delete all c2gs_* options + transients
├── composer.json
├── phpunit.xml.dist
├── readme.txt                    # WordPress plugin readme + security note + manual test checklist
├── vendor/                       # committed
├── src/
│   ├── Plugin.php                # Bootstrap: wire hooks, build collaborators, Form Vibes check
│   ├── Settings.php              # Settings API page + admin_post OAuth handlers + admin notices
│   ├── GoogleAuth.php            # Google\Client wrapper: consent URL, code exchange, token store/refresh
│   ├── SubmissionListener.php    # fv_after_entry_meta_success handler
│   ├── FieldMapper.php           # Pure: posted_data -> 6-value row
│   ├── SheetsWriter.php          # Ensure tab + header, append row
│   └── ErrorLog.php              # Capped failure ring buffer in an option
└── tests/
    ├── bootstrap.php
    ├── TestCase.php              # Brain Monkey + Mockery lifecycle
    ├── FieldMapperTest.php
    ├── ErrorLogTest.php
    ├── SheetsWriterTest.php
    ├── GoogleAuthTest.php
    ├── SubmissionListenerTest.php
    └── SettingsTest.php
```

Responsibilities are one-per-file. `FieldMapper` and `ErrorLog` hold the logic worth 80% coverage; the rest is WordPress/Google glue tested at the seams.

---

## Task 1: Project scaffold and test tooling

**Files:**
- Create: `composer.json`
- Create: `contact-to-gsheets.php`
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap.php`
- Create: `tests/TestCase.php`
- Create: `tests/SmokeTest.php`
- Modify: `.gitignore` (stop ignoring `vendor/`)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - Composer PSR-4 autoload: `C2GS\` → `src/`, `C2GS\Tests\` → `tests/`.
  - `C2GS\Tests\TestCase` — abstract base: calls `Brain\Monkey\setUp()` in `setUp()`, `Brain\Monkey\tearDown()` + `Mockery::close()` in `tearDown()`.
  - Global constants for tests: `DAY_IN_SECONDS=86400`, `WEEK_IN_SECONDS=604800`, `MINUTE_IN_SECONDS=60`, `HOUR_IN_SECONDS=3600`.
  - `contact-to-gsheets.php` defines `C2GS_FILE` (= `__FILE__`), `C2GS_DIR` (= `plugin_dir_path( __FILE__ )`), `C2GS_VERSION` (= `'0.1.0'`).

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "wdaza/contact-to-gsheets",
    "description": "Send Form Vibes submissions to a Google Sheet in real time.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.1",
        "google/apiclient": "^2.18",
        "google/apiclient-services": "^0.400"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "brain/monkey": "^2.6",
        "mockery/mockery": "^1.6"
    },
    "autoload": {
        "psr-4": { "C2GS\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "C2GS\\Tests\\": "tests/" }
    },
    "config": {
        "platform": { "php": "8.1" },
        "sort-packages": true,
        "allow-plugins": { "*": false }
    }
}
```

- [ ] **Step 2: Install dependencies**

Run: `composer install`
Expected: `vendor/` created, `vendor/autoload.php` present, exit 0.

Optional size trim (safe to skip): after install, `composer run-script` is not configured; you may delete unused service classes under `vendor/google/apiclient-services/src/` keeping only `Sheets.php` and `Sheets/`. Do this only if repo size matters; it is not required for correctness.

- [ ] **Step 3: Write `contact-to-gsheets.php`**

```php
<?php
/**
 * Plugin Name:       Contact to GSheets
 * Description:        Sends Form Vibes submissions to a Google Sheet in real time.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       contact-to-gsheets
 *
 * @package C2GS
 */

defined( 'ABSPATH' ) || exit;

define( 'C2GS_FILE', __FILE__ );
define( 'C2GS_DIR', plugin_dir_path( __FILE__ ) );
define( 'C2GS_VERSION', '0.1.0' );

$c2gs_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $c2gs_autoload ) ) {
    add_action(
        'admin_notices',
        static function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'Contact to GSheets: vendor/autoload.php missing. Run composer install.', 'contact-to-gsheets' );
            echo '</p></div>';
        }
    );
    return;
}
require $c2gs_autoload;

add_action( 'plugins_loaded', static function () {
    \C2GS\Plugin::instance()->boot();
} );
```

- [ ] **Step 4: Write `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 5: Write `tests/bootstrap.php`**

```php
<?php

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
```

- [ ] **Step 6: Write `tests/TestCase.php`**

```php
<?php

namespace C2GS\Tests;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 7: Write `tests/SmokeTest.php`**

```php
<?php

namespace C2GS\Tests;

final class SmokeTest extends TestCase {

    public function test_autoload_and_constants_available(): void {
        $this->assertSame( 86400, DAY_IN_SECONDS );
        $this->assertTrue( class_exists( \Google\Client::class ) );
    }
}
```

- [ ] **Step 8: Run the smoke test**

Run: `vendor/bin/phpunit`
Expected: PASS, 1 test, 2 assertions.

- [ ] **Step 9: Flip `.gitignore` and commit**

Edit `.gitignore` to:

```
node_modules/
.DS_Store
*.log
.phpunit.result.cache
.phpunit.cache/
```

Then:

```bash
git add composer.json composer.lock contact-to-gsheets.php phpunit.xml.dist tests/ .gitignore vendor/
git commit -m "chore: scaffold plugin, Composer deps, and PHPUnit tooling"
```

---

## Task 2: FieldMapper

**Files:**
- Create: `src/FieldMapper.php`
- Test: `tests/FieldMapperTest.php`

**Interfaces:**
- Consumes: nothing (pure).
- Produces:
  - `C2GS\FieldMapper::__construct( ?callable $isEmail = null )` — `$isEmail` defaults to the string `'is_email'`; injectable for tests.
  - `C2GS\FieldMapper::toRow( string $pluginName, string|int $formId, string $title, array $postedData, string $timestamp ): array` — returns a list of exactly 6 strings in order `[ timestamp, form, name, email, message, data ]`.
  - Constant `C2GS\FieldMapper::INTERNAL_KEYS = [ 'fv_plugin', 'fv_form_id', 'IP' ]`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\FieldMapper;

final class FieldMapperTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'wp_json_encode' )->alias(
            static fn( $data ) => json_encode( $data )
        );
    }

    private function mapper(): FieldMapper {
        // Deterministic is_email stub: value contains "@" and a dot after it.
        return new FieldMapper(
            static fn( $v ) => is_string( $v ) && (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $v )
        );
    }

    public function test_detects_email_name_and_message_from_cf7_shape(): void {
        $posted = [
            'your-name'    => 'Ada Lovelace',
            'your-email'   => 'ada@example.com',
            'your-subject' => 'Hi',
            'your-message' => 'I would like a quote for 200 units, please call me back.',
            'fv_plugin'    => 'cf7',
            'fv_form_id'   => '42',
        ];

        $row = $this->mapper()->toRow( 'cf7', 42, 'Contact Us', $posted, '2026-08-26T23:00:00-05:00' );

        $this->assertCount( 6, $row );
        $this->assertSame( '2026-08-26T23:00:00-05:00', $row[0] );
        $this->assertSame( 'Contact Us', $row[1] );
        $this->assertSame( 'Ada Lovelace', $row[2] );
        $this->assertSame( 'ada@example.com', $row[3] );
        $this->assertSame( 'I would like a quote for 200 units, please call me back.', $row[4] );

        $data = json_decode( $row[5], true );
        $this->assertArrayNotHasKey( 'fv_plugin', $data );
        $this->assertArrayNotHasKey( 'fv_form_id', $data );
        $this->assertSame( 'ada@example.com', $data['your-email'] );
    }

    public function test_falls_back_to_plugin_and_form_id_when_title_empty(): void {
        $row = $this->mapper()->toRow( 'wpforms', 7, '', [ 'email' => 'x@y.com' ], 'T' );
        $this->assertSame( 'wpforms #7', $row[1] );
    }

    public function test_flattens_array_values_and_keeps_them_in_data(): void {
        $posted = [
            'name'      => 'Bob',
            'email'     => 'bob@example.com',
            'interests' => [ 'sales', 'support' ],
        ];
        $row  = $this->mapper()->toRow( 'gravityforms', 1, 'X', $posted, 'T' );
        $data = json_decode( $row[5], true );
        $this->assertSame( 'sales, support', $data['interests'] );
    }

    public function test_email_key_regex_fallback_when_is_email_never_matches(): void {
        $posted = [ 'contact_correo' => 'not-a-real-email-but-labeled', 'nombre' => 'Sam' ];
        $row    = $this->mapper()->toRow( 'elementor', 1, 'X', $posted, 'T' );
        $this->assertSame( 'not-a-real-email-but-labeled', $row[3] );
        $this->assertSame( 'Sam', $row[2] );
    }

    public function test_missing_columns_are_empty_strings(): void {
        $row = $this->mapper()->toRow( 'cf7', 1, 'X', [ 'only' => 'a@b.com' ], 'T' );
        $this->assertSame( '', $row[2] ); // name
        $this->assertSame( 'a@b.com', $row[3] );
        $this->assertSame( '', $row[4] ); // message (no non-email fields remain)
    }

    public function test_message_is_longest_remaining_value(): void {
        $posted = [
            'name'  => 'Jo',
            'email' => 'jo@example.com',
            'phone' => '555-1000',
            'notes' => 'This sentence is clearly the longest free text field in the form.',
        ];
        $row = $this->mapper()->toRow( 'cf7', 1, 'X', $posted, 'T' );
        $this->assertSame( 'This sentence is clearly the longest free text field in the form.', $row[4] );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter FieldMapperTest`
Expected: FAIL — `Error: Class "C2GS\FieldMapper" not found`.

- [ ] **Step 3: Implement `src/FieldMapper.php`**

```php
<?php

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
    public function toRow( string $pluginName, string|int $formId, string $title, array $postedData, string $timestamp ): array {
        $clean = $this->clean( $postedData );

        [ $emailKey, $email ] = $this->pickEmail( $clean );
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
    private function pickEmail( array $clean ): array {
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter FieldMapperTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add src/FieldMapper.php tests/FieldMapperTest.php
git commit -m "feat: FieldMapper normalizes posted data into the sheet row"
```

---

## Task 3: ErrorLog

**Files:**
- Create: `src/ErrorLog.php`
- Test: `tests/ErrorLogTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `C2GS\ErrorLog::add( array $record ): void` — `$record` keys: `form_id` (mixed, nullable), `plugin_name` (string), `http_code` (int), `message` (string). Prepends `{ time: time(), form_id, plugin_name, http_code, message }`, trims to 50, `update_option( 'c2gs_error_log', $list, false )`.
  - `C2GS\ErrorLog::all(): array` — newest-first list from `get_option( 'c2gs_error_log', [] )`.
  - `C2GS\ErrorLog::clear(): void` — `delete_option( 'c2gs_error_log' )`.
  - Constants: `ErrorLog::OPTION = 'c2gs_error_log'`, `ErrorLog::MAX = 50`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ErrorLog;

final class ErrorLogTest extends TestCase {

    public function test_add_prepends_and_persists_with_autoload_off(): void {
        Functions\expect( 'get_option' )->once()->with( ErrorLog::OPTION, [] )->andReturn( [] );
        Functions\expect( 'update_option' )->once()->with(
            ErrorLog::OPTION,
            Mockery_capture( $captured ),
            false
        );

        ( new ErrorLog() )->add( [
            'form_id'     => 42,
            'plugin_name' => 'cf7',
            'http_code'   => 401,
            'message'     => 'Invalid credentials',
        ] );

        $this->assertCount( 1, $captured );
        $this->assertSame( 'cf7', $captured[0]['plugin_name'] );
        $this->assertSame( 401, $captured[0]['http_code'] );
        $this->assertArrayHasKey( 'time', $captured[0] );
    }

    public function test_ring_buffer_caps_at_50_newest_first(): void {
        $existing = [];
        for ( $i = 0; $i < 50; $i++ ) {
            $existing[] = [ 'time' => $i, 'form_id' => $i, 'plugin_name' => 'x', 'http_code' => 0, 'message' => "m$i" ];
        }
        Functions\expect( 'get_option' )->once()->andReturn( $existing );
        Functions\expect( 'update_option' )->once()->with(
            ErrorLog::OPTION,
            Mockery_capture( $captured ),
            false
        );

        ( new ErrorLog() )->add( [ 'form_id' => 999, 'plugin_name' => 'new', 'http_code' => 500, 'message' => 'newest' ] );

        $this->assertCount( 50, $captured );
        $this->assertSame( 'new', $captured[0]['plugin_name'] );
        $this->assertSame( 'm48', $captured[49]['message'] );
    }

    public function test_all_reads_option(): void {
        Functions\expect( 'get_option' )->once()->with( ErrorLog::OPTION, [] )->andReturn( [ [ 'message' => 'a' ] ] );
        $this->assertSame( [ [ 'message' => 'a' ] ], ( new ErrorLog() )->all() );
    }
}

/**
 * Mockery argument matcher that captures the received value into $target.
 */
function Mockery_capture( &$target ) {
    return \Mockery::on( function ( $value ) use ( &$target ) {
        $target = $value;
        return true;
    } );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ErrorLogTest`
Expected: FAIL — `Class "C2GS\ErrorLog" not found`.

- [ ] **Step 3: Implement `src/ErrorLog.php`**

```php
<?php

namespace C2GS;

/**
 * Newest-first ring buffer of sync failures, stored in one option.
 */
final class ErrorLog {

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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ErrorLogTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/ErrorLog.php tests/ErrorLogTest.php
git commit -m "feat: ErrorLog capped failure ring buffer"
```

---

## Task 4: SheetsWriter

**Files:**
- Create: `src/SheetsWriter.php`
- Test: `tests/SheetsWriterTest.php`

**Interfaces:**
- Consumes: `Google\Service\Sheets` (from `google/apiclient-services`), with public properties `->spreadsheets` (`Google\Service\Sheets\Resource\Spreadsheets`) and `->spreadsheets_values` (`Google\Service\Sheets\Resource\SpreadsheetsValues`).
- Produces:
  - `C2GS\SheetsWriter::__construct( Google\Service\Sheets $sheets, string $spreadsheetId, string $tabName )`.
  - `C2GS\SheetsWriter::append( array $row ): void` — `$row` is the 6-string list from `FieldMapper`. Runs the tab/header preflight (skipped when transient `c2gs_tab_ready` is set), then `spreadsheets_values->append`.
  - Constant `SheetsWriter::HEADER = [ 'timestamp', 'form', 'name', 'email', 'message', 'data' ]`.
  - Constant `SheetsWriter::READY_TRANSIENT = 'c2gs_tab_ready'`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\SheetsWriter;
use Google\Service\Sheets;
use Google\Service\Sheets\Resource\Spreadsheets as SpreadsheetsResource;
use Google\Service\Sheets\Resource\SpreadsheetsValues as ValuesResource;
use Google\Service\Sheets\Sheet as SheetModel;
use Google\Service\Sheets\SheetProperties;
use Google\Service\Sheets\Spreadsheet as SpreadsheetModel;
use Google\Service\Sheets\ValueRange;
use Mockery;

final class SheetsWriterTest extends TestCase {

    private function sheetsMockWithTab( string $existingTitle ): Sheets {
        $props = Mockery::mock( SheetProperties::class );
        $props->shouldReceive( 'getTitle' )->andReturn( $existingTitle );
        $sheet = Mockery::mock( SheetModel::class );
        $sheet->shouldReceive( 'getProperties' )->andReturn( $props );
        $spreadsheet = Mockery::mock( SpreadsheetModel::class );
        $spreadsheet->shouldReceive( 'getSheets' )->andReturn( [ $sheet ] );

        $sheetsResource = Mockery::mock( SpreadsheetsResource::class );
        $sheetsResource->shouldReceive( 'get' )->with( 'SS_ID' )->andReturn( $spreadsheet );

        $sheets = Mockery::mock( Sheets::class );
        $sheets->spreadsheets        = $sheetsResource;
        $sheets->spreadsheets_values = Mockery::mock( ValuesResource::class );
        return $sheets;
    }

    public function test_append_skips_preflight_when_transient_set(): void {
        Functions\expect( 'get_transient' )->once()->with( SheetsWriter::READY_TRANSIENT )->andReturn( 1 );
        Functions\expect( 'set_transient' )->never();

        $sheets = Mockery::mock( Sheets::class );
        $sheets->spreadsheets        = Mockery::mock( SpreadsheetsResource::class );
        $sheets->spreadsheets_values = Mockery::mock( ValuesResource::class );

        $sheets->spreadsheets->shouldReceive( 'get' )->never();
        $sheets->spreadsheets_values->shouldReceive( 'append' )->once()->withArgs(
            function ( $id, $range, $body, $opts ) {
                return 'SS_ID' === $id
                    && 'Submissions!A:F' === $range
                    && $body instanceof ValueRange
                    && [ [ 'r1', 'r2', 'r3', 'r4', 'r5', 'r6' ] ] === $body->getValues()
                    && 'USER_ENTERED' === $opts['valueInputOption']
                    && 'INSERT_ROWS' === $opts['insertDataOption'];
            }
        );

        ( new SheetsWriter( $sheets, 'SS_ID', 'Submissions' ) )
            ->append( [ 'r1', 'r2', 'r3', 'r4', 'r5', 'r6' ] );
    }

    public function test_append_creates_missing_tab_then_writes_header(): void {
        Functions\expect( 'get_transient' )->once()->andReturn( false );
        Functions\expect( 'set_transient' )->once()->with( SheetsWriter::READY_TRANSIENT, 1, 600 );

        $sheets = $this->sheetsMockWithTab( 'SomeOtherTab' );

        $sheets->spreadsheets->shouldReceive( 'batchUpdate' )->once()->withArgs(
            function ( $id, $body ) {
                return 'SS_ID' === $id
                    && 'Submissions' === $body->getRequests()[0]->getAddSheet()->getProperties()->getTitle();
            }
        );

        $emptyHeader = Mockery::mock( ValueRange::class );
        $emptyHeader->shouldReceive( 'getValues' )->andReturn( null );
        $sheets->spreadsheets_values->shouldReceive( 'get' )->once()
            ->with( 'SS_ID', 'Submissions!A1:F1' )->andReturn( $emptyHeader );

        $sheets->spreadsheets_values->shouldReceive( 'update' )->once()->withArgs(
            function ( $id, $range, $body, $opts ) {
                return 'Submissions!A1:F1' === $range
                    && SheetsWriter::HEADER === $body->getValues()[0]
                    && 'RAW' === $opts['valueInputOption'];
            }
        );

        $sheets->spreadsheets_values->shouldReceive( 'append' )->once();

        ( new SheetsWriter( $sheets, 'SS_ID', 'Submissions' ) )
            ->append( [ 'a', 'b', 'c', 'd', 'e', 'f' ] );
    }

    public function test_append_leaves_existing_header_alone(): void {
        Functions\expect( 'get_transient' )->once()->andReturn( false );
        Functions\expect( 'set_transient' )->once();

        $sheets = $this->sheetsMockWithTab( 'Submissions' ); // tab already exists
        $sheets->spreadsheets->shouldReceive( 'batchUpdate' )->never();

        $filledHeader = Mockery::mock( ValueRange::class );
        $filledHeader->shouldReceive( 'getValues' )->andReturn( [ SheetsWriter::HEADER ] );
        $sheets->spreadsheets_values->shouldReceive( 'get' )->once()->andReturn( $filledHeader );
        $sheets->spreadsheets_values->shouldReceive( 'update' )->never();
        $sheets->spreadsheets_values->shouldReceive( 'append' )->once();

        ( new SheetsWriter( $sheets, 'SS_ID', 'Submissions' ) )
            ->append( [ 'a', 'b', 'c', 'd', 'e', 'f' ] );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SheetsWriterTest`
Expected: FAIL — `Class "C2GS\SheetsWriter" not found`.

- [ ] **Step 3: Implement `src/SheetsWriter.php`**

```php
<?php

namespace C2GS;

use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\ValueRange;

/**
 * Appends a single row to a Google Sheet tab, creating the tab and
 * header row on first use.
 */
final class SheetsWriter {

    public const HEADER           = [ 'timestamp', 'form', 'name', 'email', 'message', 'data' ];
    public const READY_TRANSIENT  = 'c2gs_tab_ready';
    private const READY_TTL       = 600;

    public function __construct(
        private Sheets $sheets,
        private string $spreadsheetId,
        private string $tabName
    ) {}

    /**
     * @param array{0:string,1:string,2:string,3:string,4:string,5:string} $row
     */
    public function append( array $row ): void {
        $this->ensureReady();

        $body = new ValueRange( [ 'values' => [ array_values( $row ) ] ] );
        $this->sheets->spreadsheets_values->append(
            $this->spreadsheetId,
            $this->tabName . '!A:F',
            $body,
            [
                'valueInputOption' => 'USER_ENTERED',
                'insertDataOption' => 'INSERT_ROWS',
            ]
        );
    }

    private function ensureReady(): void {
        if ( get_transient( self::READY_TRANSIENT ) ) {
            return;
        }
        $this->ensureTab();
        $this->ensureHeader();
        set_transient( self::READY_TRANSIENT, 1, self::READY_TTL );
    }

    private function ensureTab(): void {
        $spreadsheet = $this->sheets->spreadsheets->get( $this->spreadsheetId );
        foreach ( $spreadsheet->getSheets() as $sheet ) {
            if ( $sheet->getProperties()->getTitle() === $this->tabName ) {
                return;
            }
        }

        $request = new SheetsRequest( [
            'addSheet' => [ 'properties' => [ 'title' => $this->tabName ] ],
        ] );
        $this->sheets->spreadsheets->batchUpdate(
            $this->spreadsheetId,
            new BatchUpdateSpreadsheetRequest( [ 'requests' => [ $request ] ] )
        );
    }

    private function ensureHeader(): void {
        $response = $this->sheets->spreadsheets_values->get(
            $this->spreadsheetId,
            $this->tabName . '!A1:F1'
        );
        $values = $response->getValues();
        if ( ! empty( $values ) && ! empty( $values[0] ) ) {
            return;
        }

        $this->sheets->spreadsheets_values->update(
            $this->spreadsheetId,
            $this->tabName . '!A1:F1',
            new ValueRange( [ 'values' => [ self::HEADER ] ] ),
            [ 'valueInputOption' => 'RAW' ]
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter SheetsWriterTest`
Expected: PASS, 3 tests. If `getAddSheet()` is unavailable on the model in your installed `apiclient-services` version, assert instead on `$body->getRequests()[0]['addSheet']['properties']['title']` — the model exposes array access.

- [ ] **Step 5: Commit**

```bash
git add src/SheetsWriter.php tests/SheetsWriterTest.php
git commit -m "feat: SheetsWriter ensures tab and header, appends row"
```

---

## Task 5: GoogleAuth

**Files:**
- Create: `src/GoogleAuth.php`
- Test: `tests/GoogleAuthTest.php`

**Interfaces:**
- Consumes: `Google\Client` from `google/apiclient`.
- Produces:
  - `C2GS\GoogleAuth::__construct( string $clientId, string $clientSecret, string $redirectUri )`.
  - `C2GS\GoogleAuth::consentUrl( string $state ): string`.
  - `C2GS\GoogleAuth::exchangeCode( string $code ): bool` — stores token on success, returns `false` on `error` in the response.
  - `C2GS\GoogleAuth::isConnected(): bool` — true when `c2gs_google_token` option holds a `refresh_token` or `access_token`.
  - `C2GS\GoogleAuth::authedClient(): \Google\Client` — returns a client with a valid access token, refreshing via the stored refresh token when expired. Throws `\RuntimeException` when not connected or refresh fails.
  - `C2GS\GoogleAuth::forceRefresh(): void` — unconditionally refreshes using the stored refresh token and persists the merged token. Throws `\RuntimeException` on failure.
  - `C2GS\GoogleAuth::disconnect(): void` — best-effort `revokeToken`, then `delete_option( 'c2gs_google_token' )`.
  - `protected newClient(): \Google\Client` — overridable seam for tests.
  - Constants: `GoogleAuth::TOKEN_OPTION = 'c2gs_google_token'`, `GoogleAuth::CALLBACK_ACTION = 'c2gs_oauth_cb'`, `GoogleAuth::SCOPE = 'https://www.googleapis.com/auth/spreadsheets'`.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\GoogleAuth;
use Google\Client;
use Mockery;

final class GoogleAuthTest extends TestCase {

    private function authWithClient( Client $client ): GoogleAuth {
        return new class( 'cid', 'secret', 'https://site/wp-admin/admin-post.php?action=c2gs_oauth_cb', $client ) extends GoogleAuth {
            private Client $injected;
            public function __construct( string $id, string $secret, string $uri, Client $c ) {
                parent::__construct( $id, $secret, $uri );
                $this->injected = $c;
            }
            protected function newClient(): Client {
                return $this->injected;
            }
        };
    }

    public function test_authed_client_refreshes_when_expired_and_persists_refresh_token(): void {
        $stored = [ 'access_token' => 'old', 'refresh_token' => 'R', 'expires_in' => 3600, 'created' => 1 ];
        Functions\expect( 'get_option' )->with( GoogleAuth::TOKEN_OPTION, [] )->andReturn( $stored );

        $client = Mockery::mock( Client::class );
        $client->shouldReceive( 'setClientId' )->andReturnNull();
        $client->shouldReceive( 'setClientSecret' )->andReturnNull();
        $client->shouldReceive( 'setRedirectUri' )->andReturnNull();
        $client->shouldReceive( 'setScopes' )->andReturnNull();
        $client->shouldReceive( 'setAccessType' )->andReturnNull();
        $client->shouldReceive( 'setPrompt' )->andReturnNull();
        $client->shouldReceive( 'setAccessToken' )->once()->with( $stored );
        $client->shouldReceive( 'isAccessTokenExpired' )->once()->andReturn( true );
        $client->shouldReceive( 'fetchAccessTokenWithRefreshToken' )->once()->with( 'R' )
            ->andReturn( [ 'access_token' => 'new', 'expires_in' => 3600 ] );
        $client->shouldReceive( 'getAccessToken' )->andReturn( [ 'access_token' => 'new', 'expires_in' => 3600 ] );

        Functions\expect( 'update_option' )->once()->with(
            GoogleAuth::TOKEN_OPTION,
            Mockery::on( function ( $token ) {
                return 'new' === $token['access_token'] && 'R' === $token['refresh_token'];
            } ),
            false
        );

        $out = $this->authWithClient( $client )->authedClient();
        $this->assertSame( $client, $out );
    }

    public function test_authed_client_throws_when_not_connected(): void {
        Functions\expect( 'get_option' )->with( GoogleAuth::TOKEN_OPTION, [] )->andReturn( [] );
        $this->expectException( \RuntimeException::class );
        $this->authWithClient( Mockery::mock( Client::class ) )->authedClient();
    }

    public function test_exchange_code_returns_false_on_error_response(): void {
        $client = Mockery::mock( Client::class );
        foreach ( [ 'setClientId', 'setClientSecret', 'setRedirectUri', 'setScopes', 'setAccessType', 'setPrompt' ] as $m ) {
            $client->shouldReceive( $m )->andReturnNull();
        }
        $client->shouldReceive( 'fetchAccessTokenWithAuthCode' )->once()->with( 'BADCODE' )
            ->andReturn( [ 'error' => 'invalid_grant' ] );
        Functions\expect( 'update_option' )->never();

        $this->assertFalse( $this->authWithClient( $client )->exchangeCode( 'BADCODE' ) );
    }

    public function test_is_connected_reads_option(): void {
        Functions\expect( 'get_option' )->with( GoogleAuth::TOKEN_OPTION, [] )->andReturn( [ 'refresh_token' => 'R' ] );
        $this->assertTrue( $this->authWithClient( Mockery::mock( Client::class ) )->isConnected() );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter GoogleAuthTest`
Expected: FAIL — `Class "C2GS\GoogleAuth" not found`.

- [ ] **Step 3: Implement `src/GoogleAuth.php`**

```php
<?php

namespace C2GS;

use Google\Client;

/**
 * Wraps a Google OAuth 2.0 client for the Sheets scope and persists the
 * token set (with offline refresh token) in a single option.
 */
class GoogleAuth {

    public const TOKEN_OPTION    = 'c2gs_google_token';
    public const CALLBACK_ACTION = 'c2gs_oauth_cb';
    public const SCOPE           = 'https://www.googleapis.com/auth/spreadsheets';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri
    ) {}

    protected function newClient(): Client {
        $client = new Client();
        $client->setClientId( $this->clientId );
        $client->setClientSecret( $this->clientSecret );
        $client->setRedirectUri( $this->redirectUri );
        $client->setScopes( [ self::SCOPE ] );
        $client->setAccessType( 'offline' );
        $client->setPrompt( 'consent' );
        return $client;
    }

    public function consentUrl( string $state ): string {
        $client = $this->newClient();
        $client->setState( $state );
        return $client->createAuthUrl();
    }

    public function exchangeCode( string $code ): bool {
        $token = $this->newClient()->fetchAccessTokenWithAuthCode( $code );
        if ( isset( $token['error'] ) ) {
            return false;
        }
        $this->storeToken( $token );
        return true;
    }

    public function isConnected(): bool {
        $token = get_option( self::TOKEN_OPTION, [] );
        return is_array( $token )
            && ( ! empty( $token['refresh_token'] ) || ! empty( $token['access_token'] ) );
    }

    public function authedClient(): Client {
        $token = get_option( self::TOKEN_OPTION, [] );
        if ( empty( $token ) || ! is_array( $token ) ) {
            throw new \RuntimeException( 'Google account not connected' );
        }

        $client = $this->newClient();
        $client->setAccessToken( $token );

        if ( $client->isAccessTokenExpired() ) {
            $refreshToken = $token['refresh_token'] ?? null;
            if ( ! $refreshToken ) {
                throw new \RuntimeException( 'No refresh token stored; reconnect required' );
            }
            $new = $client->fetchAccessTokenWithRefreshToken( $refreshToken );
            if ( isset( $new['error'] ) ) {
                throw new \RuntimeException( 'Token refresh failed: ' . $new['error'] );
            }
            $merged = array_merge( $token, $client->getAccessToken() );
            if ( empty( $merged['refresh_token'] ) ) {
                $merged['refresh_token'] = $refreshToken;
            }
            $this->storeToken( $merged );
        }

        return $client;
    }

    public function forceRefresh(): void {
        $token        = get_option( self::TOKEN_OPTION, [] );
        $refreshToken = is_array( $token ) ? ( $token['refresh_token'] ?? null ) : null;
        if ( ! $refreshToken ) {
            throw new \RuntimeException( 'No refresh token stored; reconnect required' );
        }
        $new = $this->newClient()->fetchAccessTokenWithRefreshToken( $refreshToken );
        if ( isset( $new['error'] ) ) {
            throw new \RuntimeException( 'Token refresh failed: ' . $new['error'] );
        }
        $merged                  = array_merge( is_array( $token ) ? $token : [], $new );
        $merged['refresh_token'] = $merged['refresh_token'] ?? $refreshToken;
        $this->storeToken( $merged );
    }

    public function disconnect(): void {
        $token = get_option( self::TOKEN_OPTION, [] );
        if ( is_array( $token ) && ! empty( $token['access_token'] ) ) {
            try {
                $this->newClient()->revokeToken( $token );
            } catch ( \Throwable $e ) {
                // Best effort; still clear locally.
            }
        }
        delete_option( self::TOKEN_OPTION );
    }

    /** @param array<string,mixed> $token */
    private function storeToken( array $token ): void {
        if ( ! isset( $token['created'] ) ) {
            $token['created'] = time();
        }
        update_option( self::TOKEN_OPTION, $token, false );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter GoogleAuthTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src/GoogleAuth.php tests/GoogleAuthTest.php
git commit -m "feat: GoogleAuth OAuth client wrapper with offline token refresh"
```

---

## Task 6: SubmissionListener

**Files:**
- Create: `src/SubmissionListener.php`
- Test: `tests/SubmissionListenerTest.php`

**Interfaces:**
- Consumes:
  - `C2GS\FieldMapper::toRow(...)` (Task 2).
  - `C2GS\ErrorLog::add(...)` (Task 3).
  - `C2GS\GoogleAuth` (Task 5) — for `forceRefresh()` on a 401.
  - A `\Closure` `$writerFactory` with signature `fn(): C2GS\SheetsWriter` that builds a ready `SheetsWriter` from `GoogleAuth::authedClient()` and the configured spreadsheet id/tab. It throws `\RuntimeException` if unconfigured.
- Produces:
  - `C2GS\SubmissionListener::__construct( FieldMapper $mapper, ErrorLog $log, GoogleAuth $auth, \Closure $writerFactory )`.
  - `C2GS\SubmissionListener::handle( array $payload ): void` — the `fv_after_entry_meta_success` callback. Reads `$payload['plugin_name']`, `$payload['form_id']`, `$payload['entry_data']['title']`, `$payload['entry_data']['posted_data']`. Never throws.

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ErrorLog;
use C2GS\FieldMapper;
use C2GS\GoogleAuth;
use C2GS\SheetsWriter;
use C2GS\SubmissionListener;
use Google\Service\Exception as GoogleServiceException;
use Mockery;

final class SubmissionListenerTest extends TestCase {

    private function payload( array $posted = [ 'your-email' => 'a@b.com', 'msg' => 'hello there' ] ): array {
        return [
            'insert_id'   => 10,
            'plugin_name' => 'cf7',
            'form_id'     => 42,
            'entry_data'  => [ 'title' => 'Contact Us', 'posted_data' => $posted ],
            'entires'     => [],
        ];
    }

    private function mapper(): FieldMapper {
        return new FieldMapper( static fn( $v ) => is_string( $v ) && str_contains( (string) $v, '@' ) );
    }

    public function test_happy_path_appends_mapped_row(): void {
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
        Functions\when( 'wp_date' )->justReturn( '2026-08-26T23:00:00-05:00' );

        $writer = Mockery::mock( SheetsWriter::class );
        $writer->shouldReceive( 'append' )->once()->withArgs( function ( $row ) {
            return 'a@b.com' === $row[3] && 'Contact Us' === $row[1] && 'hello there' === $row[4];
        } );

        $log = Mockery::mock( ErrorLog::class );
        $log->shouldReceive( 'add' )->never();

        $listener = new SubmissionListener(
            $this->mapper(),
            $log,
            Mockery::mock( GoogleAuth::class ),
            fn() => $writer
        );
        $listener->handle( $this->payload() );
    }

    public function test_returns_early_and_flags_not_connected_when_no_spreadsheet_id(): void {
        Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [] );
        Functions\expect( 'set_transient' )->once()->with( 'c2gs_not_connected', 1, DAY_IN_SECONDS );

        $log = Mockery::mock( ErrorLog::class );
        $log->shouldReceive( 'add' )->never();

        $listener = new SubmissionListener(
            $this->mapper(),
            $log,
            Mockery::mock( GoogleAuth::class ),
            fn() => throw new \RuntimeException( 'factory should not be called' )
        );
        $listener->handle( $this->payload() );
    }

    public function test_swallows_writer_exception_and_logs_it(): void {
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
        Functions\when( 'wp_date' )->justReturn( 'T' );
        Functions\when( 'get_transient' )->justReturn( 0 );
        Functions\expect( 'set_transient' )->once()->with( 'c2gs_fail_count', 1, WEEK_IN_SECONDS );

        $writer = Mockery::mock( SheetsWriter::class );
        $writer->shouldReceive( 'append' )->once()->andThrow( new \RuntimeException( 'network down' ) );

        $log = Mockery::mock( ErrorLog::class );
        $log->shouldReceive( 'add' )->once()->withArgs( function ( $rec ) {
            return 42 === $rec['form_id'] && 'cf7' === $rec['plugin_name'] && 'network down' === $rec['message'];
        } );

        $listener = new SubmissionListener(
            $this->mapper(),
            $log,
            Mockery::mock( GoogleAuth::class ),
            fn() => $writer
        );
        $listener->handle( $this->payload() ); // must not throw
    }

    public function test_on_401_forces_refresh_and_retries_once(): void {
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );
        Functions\when( 'wp_date' )->justReturn( 'T' );

        $failing = Mockery::mock( SheetsWriter::class );
        $failing->shouldReceive( 'append' )->once()->andThrow( new GoogleServiceException( 'unauthorized', 401 ) );
        $ok = Mockery::mock( SheetsWriter::class );
        $ok->shouldReceive( 'append' )->once();

        $auth = Mockery::mock( GoogleAuth::class );
        $auth->shouldReceive( 'forceRefresh' )->once();

        $log = Mockery::mock( ErrorLog::class );
        $log->shouldReceive( 'add' )->never();

        $writers = [ $failing, $ok ];
        $listener = new SubmissionListener(
            $this->mapper(),
            $log,
            $auth,
            function () use ( &$writers ) {
                return array_shift( $writers );
            }
        );
        $listener->handle( $this->payload() );
    }

    public function test_ignores_payload_with_empty_posted_data(): void {
        Functions\expect( 'get_option' )->with( 'c2gs_settings', [] )->andReturn( [ 'spreadsheet_id' => 'SS' ] );

        $log = Mockery::mock( ErrorLog::class );
        $log->shouldReceive( 'add' )->never();

        $listener = new SubmissionListener(
            $this->mapper(),
            $log,
            Mockery::mock( GoogleAuth::class ),
            fn() => throw new \RuntimeException( 'should not build writer' )
        );
        $listener->handle( [ 'plugin_name' => 'cf7', 'form_id' => 1, 'entry_data' => [ 'posted_data' => [] ] ] );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SubmissionListenerTest`
Expected: FAIL — `Class "C2GS\SubmissionListener" not found`.

- [ ] **Step 3: Implement `src/SubmissionListener.php`**

```php
<?php

namespace C2GS;

use Google\Service\Exception as GoogleServiceException;

/**
 * Handles fv_after_entry_meta_success: map the submission and append it
 * to the sheet. Never throws into WordPress.
 */
final class SubmissionListener {

    public function __construct(
        private FieldMapper $mapper,
        private ErrorLog $log,
        private GoogleAuth $auth,
        private \Closure $writerFactory
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function handle( array $payload ): void {
        try {
            $settings      = get_option( 'c2gs_settings', [] );
            $spreadsheetId = is_array( $settings ) ? (string) ( $settings['spreadsheet_id'] ?? '' ) : '';
            if ( '' === $spreadsheetId ) {
                set_transient( 'c2gs_not_connected', 1, DAY_IN_SECONDS );
                return;
            }

            $entryData  = (array) ( $payload['entry_data'] ?? [] );
            $postedData = $entryData['posted_data'] ?? [];
            if ( ! is_array( $postedData ) || [] === $postedData ) {
                return;
            }

            $pluginName = (string) ( $payload['plugin_name'] ?? '' );
            $formId     = $payload['form_id'] ?? '';
            $title      = (string) ( $entryData['title'] ?? '' );

            $row = $this->mapper->toRow(
                $pluginName,
                is_scalar( $formId ) ? $formId : '',
                $title,
                $postedData,
                wp_date( 'c' )
            );

            $this->appendWithRetry( $row );
        } catch ( \Throwable $e ) {
            $this->log->add( [
                'form_id'     => $payload['form_id'] ?? null,
                'plugin_name' => (string) ( $payload['plugin_name'] ?? '' ),
                'http_code'   => $e instanceof GoogleServiceException ? (int) $e->getCode() : 0,
                'message'     => $e->getMessage(),
            ] );
            $count = (int) get_transient( 'c2gs_fail_count' );
            set_transient( 'c2gs_fail_count', $count + 1, WEEK_IN_SECONDS );
        }
    }

    /**
     * @param array{0:string,1:string,2:string,3:string,4:string,5:string} $row
     */
    private function appendWithRetry( array $row ): void {
        try {
            ( $this->writerFactory )()->append( $row );
        } catch ( GoogleServiceException $e ) {
            if ( 401 !== (int) $e->getCode() ) {
                throw $e;
            }
            $this->auth->forceRefresh();
            ( $this->writerFactory )()->append( $row );
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter SubmissionListenerTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/SubmissionListener.php tests/SubmissionListenerTest.php
git commit -m "feat: SubmissionListener maps and appends Form Vibes entries"
```

---

## Task 7: Settings page and OAuth admin-post handlers

**Files:**
- Create: `src/Settings.php`
- Test: `tests/SettingsTest.php`

**Interfaces:**
- Consumes: `C2GS\GoogleAuth` (Task 5), `C2GS\ErrorLog` (Task 3).
- Produces:
  - `C2GS\Settings::__construct( GoogleAuth $auth, ErrorLog $log )`.
  - `C2GS\Settings::register(): void` — adds hooks: `admin_menu` (settings page under Settings), `admin_init` (`register_setting`), `admin_post_c2gs_oauth_start`, `admin_post_c2gs_oauth_cb`, `admin_post_c2gs_oauth_disconnect`, `admin_post_c2gs_dismiss_failures`, `admin_notices`.
  - `C2GS\Settings::sanitize( mixed $input ): array` — the `register_setting` callback. Returns `[ 'client_id', 'client_secret', 'spreadsheet_id', 'tab_name' ]`, all `sanitize_text_field`-clean; `tab_name` defaults to `'Submissions'`; rejects a `spreadsheet_id` not matching `/^[A-Za-z0-9_-]+$/` by keeping the previously stored value and calling `add_settings_error`.
  - `C2GS\Settings::SETTINGS_OPTION = 'c2gs_settings'`, `C2GS\Settings::PAGE_SLUG = 'contact-to-gsheets'`, `C2GS\Settings::STATE_NONCE = 'c2gs_oauth'`.
  - `C2GS\Settings::redirectUri(): string` — `admin_url( 'admin-post.php?action=' . GoogleAuth::CALLBACK_ACTION )`.

- [ ] **Step 1: Write failing tests (sanitize + redirect URI)**

```php
<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\ErrorLog;
use C2GS\GoogleAuth;
use C2GS\Settings;
use Mockery;

final class SettingsTest extends TestCase {

    private function settings(): Settings {
        return new Settings( Mockery::mock( GoogleAuth::class ), Mockery::mock( ErrorLog::class ) );
    }

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
    }

    public function test_sanitize_trims_fields_and_defaults_tab_name(): void {
        Functions\when( 'get_option' )->justReturn( [] );

        $out = $this->settings()->sanitize( [
            'client_id'      => '  abc.apps.googleusercontent.com  ',
            'client_secret'  => ' s3cr3t ',
            'spreadsheet_id' => ' 1A2b3C_-xyz ',
            'tab_name'       => '  ',
        ] );

        $this->assertSame( 'abc.apps.googleusercontent.com', $out['client_id'] );
        $this->assertSame( 's3cr3t', $out['client_secret'] );
        $this->assertSame( '1A2b3C_-xyz', $out['spreadsheet_id'] );
        $this->assertSame( 'Submissions', $out['tab_name'] );
    }

    public function test_sanitize_rejects_bad_spreadsheet_id_and_keeps_old_value(): void {
        Functions\when( 'get_option' )->justReturn( [ 'spreadsheet_id' => 'GOOD_OLD_ID' ] );
        Functions\expect( 'add_settings_error' )->once();

        $out = $this->settings()->sanitize( [
            'client_id'      => 'x',
            'client_secret'  => 'y',
            'spreadsheet_id' => 'has spaces/and/slashes',
            'tab_name'       => 'Leads',
        ] );

        $this->assertSame( 'GOOD_OLD_ID', $out['spreadsheet_id'] );
        $this->assertSame( 'Leads', $out['tab_name'] );
    }

    public function test_redirect_uri_uses_callback_action(): void {
        Functions\when( 'admin_url' )->alias( static fn( $p ) => 'https://site/wp-admin/' . $p );
        $this->assertSame(
            'https://site/wp-admin/admin-post.php?action=c2gs_oauth_cb',
            $this->settings()->redirectUri()
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SettingsTest`
Expected: FAIL — `Class "C2GS\Settings" not found`.

- [ ] **Step 3: Implement `src/Settings.php`**

```php
<?php

namespace C2GS;

/**
 * Settings page (Settings -> Contact to GSheets) plus the admin-post
 * handlers for the OAuth connect / disconnect flow and admin notices.
 */
final class Settings {

    public const SETTINGS_OPTION = 'c2gs_settings';
    public const PAGE_SLUG       = 'contact-to-gsheets';
    public const STATE_NONCE     = 'c2gs_oauth';

    public function __construct(
        private GoogleAuth $auth,
        private ErrorLog $log
    ) {}

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addPage' ] );
        add_action( 'admin_init', [ $this, 'registerSetting' ] );
        add_action( 'admin_post_c2gs_oauth_start', [ $this, 'handleOauthStart' ] );
        add_action( 'admin_post_' . GoogleAuth::CALLBACK_ACTION, [ $this, 'handleOauthCallback' ] );
        add_action( 'admin_post_c2gs_oauth_disconnect', [ $this, 'handleDisconnect' ] );
        add_action( 'admin_post_c2gs_dismiss_failures', [ $this, 'handleDismissFailures' ] );
        add_action( 'admin_notices', [ $this, 'renderNotices' ] );
    }

    public function redirectUri(): string {
        return admin_url( 'admin-post.php?action=' . GoogleAuth::CALLBACK_ACTION );
    }

    public function addPage(): void {
        add_options_page(
            __( 'Contact to GSheets', 'contact-to-gsheets' ),
            __( 'Contact to GSheets', 'contact-to-gsheets' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    public function registerSetting(): void {
        register_setting(
            'c2gs_group',
            self::SETTINGS_OPTION,
            [ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize' ] ]
        );
    }

    /**
     * @param mixed $input
     * @return array{client_id:string,client_secret:string,spreadsheet_id:string,tab_name:string}
     */
    public function sanitize( $input ): array {
        $input   = is_array( $input ) ? $input : [];
        $current = get_option( self::SETTINGS_OPTION, [] );
        $current = is_array( $current ) ? $current : [];

        $clientId     = sanitize_text_field( $input['client_id'] ?? '' );
        $clientSecret = sanitize_text_field( $input['client_secret'] ?? '' );
        $tabName      = sanitize_text_field( $input['tab_name'] ?? '' );
        if ( '' === $tabName ) {
            $tabName = 'Submissions';
        }

        $spreadsheetId = sanitize_text_field( $input['spreadsheet_id'] ?? '' );
        if ( '' !== $spreadsheetId && ! preg_match( '/^[A-Za-z0-9_-]+$/', $spreadsheetId ) ) {
            add_settings_error(
                self::SETTINGS_OPTION,
                'bad_spreadsheet_id',
                __( 'Spreadsheet ID contains invalid characters; keeping the previous value.', 'contact-to-gsheets' )
            );
            $spreadsheetId = (string) ( $current['spreadsheet_id'] ?? '' );
        }

        return [
            'client_id'      => $clientId,
            'client_secret'  => $clientSecret,
            'spreadsheet_id' => $spreadsheetId,
            'tab_name'       => $tabName,
        ];
    }

    public function handleOauthStart(): void {
        $this->assertCap();
        $state = wp_create_nonce( self::STATE_NONCE );
        wp_redirect( $this->auth->consentUrl( $state ) );
        exit;
    }

    public function handleOauthCallback(): void {
        $this->assertCap();
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

        $status = 'error';
        if ( $code && wp_verify_nonce( $state, self::STATE_NONCE ) && $this->auth->exchangeCode( $code ) ) {
            $status = 'connected';
        }
        wp_safe_redirect( add_query_arg( 'c2gs_oauth', $status, $this->settingsUrl() ) );
        exit;
    }

    public function handleDisconnect(): void {
        $this->assertCap();
        check_admin_referer( 'c2gs_disconnect' );
        $this->auth->disconnect();
        wp_safe_redirect( add_query_arg( 'c2gs_oauth', 'disconnected', $this->settingsUrl() ) );
        exit;
    }

    public function handleDismissFailures(): void {
        $this->assertCap();
        check_admin_referer( 'c2gs_dismiss_failures' );
        delete_transient( 'c2gs_fail_count' );
        $this->log->clear();
        wp_safe_redirect( $this->settingsUrl() );
        exit;
    }

    public function renderNotices(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! $this->formVibesActive() ) {
            $this->notice( 'error', __( 'Contact to GSheets needs the Form Vibes plugin active to capture submissions.', 'contact-to-gsheets' ) );
        }

        if ( get_transient( 'c2gs_not_connected' ) ) {
            $this->notice(
                'warning',
                sprintf(
                    /* translators: %s: settings page URL */
                    __( 'Contact to GSheets is not connected to Google. <a href="%s">Open settings</a>.', 'contact-to-gsheets' ),
                    esc_url( $this->settingsUrl() )
                )
            );
        }

        $failCount = (int) get_transient( 'c2gs_fail_count' );
        if ( $failCount > 0 ) {
            $this->notice(
                'error',
                sprintf(
                    /* translators: 1: failure count, 2: settings page URL */
                    _n(
                        '%1$d Form Vibes submission failed to sync to Google Sheets. <a href="%2$s">Details</a>.',
                        '%1$d Form Vibes submissions failed to sync to Google Sheets. <a href="%2$s">Details</a>.',
                        $failCount,
                        'contact-to-gsheets'
                    ),
                    $failCount,
                    esc_url( $this->settingsUrl() )
                )
            );
        }
    }

    public function renderPage(): void {
        $this->assertCap();
        $settings = get_option( self::SETTINGS_OPTION, [] );
        $settings = is_array( $settings ) ? $settings : [];
        $connected = $this->auth->isConnected();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Contact to GSheets', 'contact-to-gsheets' ); ?></h1>

            <h2><?php esc_html_e( 'Google connection', 'contact-to-gsheets' ); ?></h2>
            <p>
                <?php esc_html_e( 'Add this exact redirect URI to your Google Cloud OAuth client (type: Web application):', 'contact-to-gsheets' ); ?><br />
                <code><?php echo esc_html( $this->redirectUri() ); ?></code>
            </p>
            <p>
                <?php if ( $connected ) : ?>
                    <strong style="color:#1a7f37;"><?php esc_html_e( 'Connected', 'contact-to-gsheets' ); ?></strong>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                        <input type="hidden" name="action" value="c2gs_oauth_disconnect" />
                        <?php wp_nonce_field( 'c2gs_disconnect' ); ?>
                        <button class="button"><?php esc_html_e( 'Disconnect', 'contact-to-gsheets' ); ?></button>
                    </form>
                <?php else : ?>
                    <strong style="color:#b32d2e;"><?php esc_html_e( 'Not connected', 'contact-to-gsheets' ); ?></strong>
                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin-post.php?action=c2gs_oauth_start' ) ); ?>">
                        <?php esc_html_e( 'Connect Google', 'contact-to-gsheets' ); ?>
                    </a>
                <?php endif; ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields( 'c2gs_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="c2gs_client_id"><?php esc_html_e( 'Google Client ID', 'contact-to-gsheets' ); ?></label></th>
                        <td><input name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[client_id]" id="c2gs_client_id" type="text" class="regular-text" value="<?php echo esc_attr( $settings['client_id'] ?? '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="c2gs_client_secret"><?php esc_html_e( 'Google Client Secret', 'contact-to-gsheets' ); ?></label></th>
                        <td><input name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[client_secret]" id="c2gs_client_secret" type="password" class="regular-text" value="<?php echo esc_attr( $settings['client_secret'] ?? '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="c2gs_spreadsheet_id"><?php esc_html_e( 'Spreadsheet ID', 'contact-to-gsheets' ); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[spreadsheet_id]" id="c2gs_spreadsheet_id" type="text" class="regular-text" value="<?php echo esc_attr( $settings['spreadsheet_id'] ?? '' ); ?>" />
                            <p class="description"><?php esc_html_e( 'The long ID from the sheet URL: docs.google.com/spreadsheets/d/THIS_PART/edit', 'contact-to-gsheets' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="c2gs_tab_name"><?php esc_html_e( 'Tab name', 'contact-to-gsheets' ); ?></label></th>
                        <td><input name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[tab_name]" id="c2gs_tab_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['tab_name'] ?? 'Submissions' ); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e( 'Recent sync failures', 'contact-to-gsheets' ); ?></h2>
            <?php $failures = $this->log->all(); ?>
            <?php if ( empty( $failures ) ) : ?>
                <p><?php esc_html_e( 'None recorded.', 'contact-to-gsheets' ); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="c2gs_dismiss_failures" />
                    <?php wp_nonce_field( 'c2gs_dismiss_failures' ); ?>
                    <button class="button"><?php esc_html_e( 'Clear failure log', 'contact-to-gsheets' ); ?></button>
                </form>
                <table class="widefat striped" style="margin-top:8px;">
                    <thead><tr>
                        <th><?php esc_html_e( 'Time', 'contact-to-gsheets' ); ?></th>
                        <th><?php esc_html_e( 'Form', 'contact-to-gsheets' ); ?></th>
                        <th><?php esc_html_e( 'HTTP', 'contact-to-gsheets' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'contact-to-gsheets' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $failures as $f ) : ?>
                        <tr>
                            <td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $f['time'] ?? 0 ) ) ); ?></td>
                            <td><?php echo esc_html( ( $f['plugin_name'] ?? '' ) . ' #' . ( $f['form_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $f['http_code'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $f['message'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function settingsUrl(): string {
        return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
    }

    private function assertCap(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'contact-to-gsheets' ) );
        }
    }

    private function formVibesActive(): bool {
        return in_array(
            'form-vibes/form-vibes.php',
            (array) get_option( 'active_plugins', [] ),
            true
        );
    }

    private function notice( string $level, string $html ): void {
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr( $level ),
            wp_kses( $html, [ 'a' => [ 'href' => [] ], 'strong' => [] ] )
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter SettingsTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Settings.php tests/SettingsTest.php
git commit -m "feat: settings page and OAuth connect/disconnect handlers"
```

---

## Task 8: Plugin bootstrap, uninstall, readme, manual test checklist

**Files:**
- Create: `src/Plugin.php`
- Create: `uninstall.php`
- Create: `readme.txt`
- Test: `tests/PluginTest.php`

**Interfaces:**
- Consumes: every class from Tasks 2–7.
- Produces:
  - `C2GS\Plugin::instance(): Plugin` — singleton.
  - `C2GS\Plugin::boot(): void` — idempotent. Builds `ErrorLog`, `FieldMapper`, `GoogleAuth` (from `c2gs_settings`), `Settings`, `SubmissionListener` with a writer-factory closure, registers `Settings::register()`, and `add_action( 'fv_after_entry_meta_success', [ $listener, 'handle' ] )`.
  - `C2GS\Plugin::buildWriterFactory( GoogleAuth $auth ): \Closure` — returns `fn(): SheetsWriter`; reads `c2gs_settings` for `spreadsheet_id` / `tab_name`, calls `$auth->authedClient()`, wraps it in `new \Google\Service\Sheets( $client )`, returns `new SheetsWriter( $service, $spreadsheetId, $tabName )`. Throws `\RuntimeException` if `spreadsheet_id` is empty.

- [ ] **Step 1: Write failing test**

```php
<?php

namespace C2GS\Tests;

use Brain\Monkey\Functions;
use C2GS\Plugin;

final class PluginTest extends TestCase {

    public function test_boot_registers_the_form_vibes_hook(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://site/wp-admin/' . $p );

        Functions\expect( 'add_action' )
            ->atLeast()->once()
            ->with( 'fv_after_entry_meta_success', \Mockery::type( 'array' ) );

        // All other add_action / add_options_page calls are permitted.
        Functions\when( 'add_options_page' )->justReturn( null );

        Plugin::instance()->boot();
    }

    public function test_instance_is_singleton(): void {
        $this->assertSame( Plugin::instance(), Plugin::instance() );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PluginTest`
Expected: FAIL — `Class "C2GS\Plugin" not found`.

- [ ] **Step 3: Implement `src/Plugin.php`**

```php
<?php

namespace C2GS;

use Google\Service\Sheets;

final class Plugin {

    private static ?Plugin $instance = null;
    private bool $booted = false;

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        $settings = get_option( 'c2gs_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];

        $log   = new ErrorLog();
        $mapper = new FieldMapper();

        $auth = new GoogleAuth(
            (string) ( $settings['client_id'] ?? '' ),
            (string) ( $settings['client_secret'] ?? '' ),
            admin_url( 'admin-post.php?action=' . GoogleAuth::CALLBACK_ACTION )
        );

        ( new Settings( $auth, $log ) )->register();

        $listener = new SubmissionListener(
            $mapper,
            $log,
            $auth,
            $this->buildWriterFactory( $auth )
        );

        add_action( 'fv_after_entry_meta_success', [ $listener, 'handle' ] );
    }

    public function buildWriterFactory( GoogleAuth $auth ): \Closure {
        return static function () use ( $auth ): SheetsWriter {
            $settings      = get_option( 'c2gs_settings', [] );
            $settings      = is_array( $settings ) ? $settings : [];
            $spreadsheetId = (string) ( $settings['spreadsheet_id'] ?? '' );
            $tabName       = (string) ( $settings['tab_name'] ?? 'Submissions' );
            if ( '' === $spreadsheetId ) {
                throw new \RuntimeException( 'No spreadsheet configured' );
            }
            if ( '' === $tabName ) {
                $tabName = 'Submissions';
            }
            $service = new Sheets( $auth->authedClient() );
            return new SheetsWriter( $service, $spreadsheetId, $tabName );
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter PluginTest`
Expected: PASS, 2 tests.

- [ ] **Step 5: Write `uninstall.php`**

```php
<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( [ 'c2gs_settings', 'c2gs_google_token', 'c2gs_error_log' ] as $option ) {
    delete_option( $option );
}
foreach ( [ 'c2gs_tab_ready', 'c2gs_fail_count', 'c2gs_not_connected' ] as $transient ) {
    delete_transient( $transient );
}
```

- [ ] **Step 6: Write `readme.txt`**

```
=== Contact to GSheets ===
Requires at least: 6.0
Requires PHP: 8.1
License: GPLv2 or later

Sends Form Vibes form submissions to a Google Sheet in real time, one row per submission.

== Description ==

On every successful Form Vibes entry (Contact Form 7, WPForms, Elementor Forms,
Gravity Forms, and other Form Vibes integrations), this plugin appends one row to
a Google Sheet tab you configure:

timestamp | form | name | email | message | data

The name, email, and message columns are auto-detected from the submitted fields.
The data column holds the complete submission as JSON. A missing tab and header
row are created automatically on the first submission.

== Setup ==

1. In Google Cloud, create an OAuth 2.0 Client ID of type "Web application".
2. Add this redirect URI to that client (shown on the settings page):
   https://YOUR-SITE/wp-admin/admin-post.php?action=c2gs_oauth_cb
3. In WordPress: Settings -> Contact to GSheets. Paste the Client ID and Client
   Secret, the Spreadsheet ID (from the sheet URL), and a tab name.
4. Click "Connect Google" and grant access to Google Sheets.
5. Submit a test form and confirm the row appears.

== Security note ==

The Google Client Secret and OAuth tokens are stored in the WordPress options
table (autoload off). WordPress does not encrypt options at rest: anyone with
database or admin access can read them and reach the connected Google account.
Restrict admin and database access accordingly, and use "Disconnect" (which
revokes the token) before decommissioning the site.

== Limitations ==

* Real-time only. If Google is unreachable when a form is submitted, that row is
  not retried automatically; the failure is logged and shown as an admin notice,
  and the full submission remains in Form Vibes.
* Existing Form Vibes entries are not backfilled.
* Field mapping is automatic; there is no per-form override.
```

- [ ] **Step 7: Add the manual test checklist to the readme**

Append to `readme.txt`:

```

== Manual test checklist (maintainers) ==

1. Configure Client ID/Secret, Spreadsheet ID, tab name; click Connect Google and
   complete consent. Settings page shows "Connected".
2. Submit one form of each installed type (CF7, WPForms, Elementor, Gravity).
   Each produces exactly one row; header row auto-created on the first.
3. In the Google account security settings, remove this app's access. Submit a
   form again. Confirm: the visitor still sees the normal form success, an admin
   notice reports the failure, and "Recent sync failures" lists a matching row.
4. Click Connect Google again, re-consent, submit. Syncing resumes.
5. Delete the tab in the sheet. Submit a form. The tab and header row are
   recreated and the new row is appended.
6. Deactivate Form Vibes. Confirm the admin notice about Form Vibes appears and
   no PHP errors occur on form pages.
```

- [ ] **Step 8: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all tests from Tasks 1–8 green.

- [ ] **Step 9: Commit**

```bash
git add src/Plugin.php uninstall.php readme.txt tests/PluginTest.php
git commit -m "feat: plugin bootstrap, uninstall cleanup, readme, and test checklist"
```

---

## Self-Review

**1. Spec coverage:**

| Spec item | Task |
|-----------|------|
| Hook `fv_after_entry_meta_success` only | Task 6, Task 8 |
| 6-column row, fixed order, ISO 8601 site-tz timestamp | Task 2 (`toRow`), Task 6 (`wp_date('c')`) |
| Strip `fv_plugin` / `fv_form_id` / `IP` | Task 2 (`INTERNAL_KEYS`) |
| Auto-detect email / name / message rules | Task 2 (`pickEmail` / `pickName` / `pickMessage`) |
| `data` column = `wp_json_encode` of cleaned map | Task 2 |
| Form label fallback `plugin #id` | Task 2 |
| Auto-create tab + header row | Task 4 (`ensureTab` / `ensureHeader`) |
| Append params `USER_ENTERED` / `INSERT_ROWS` / `A:F` | Task 4 |
| 10-min `c2gs_tab_ready` preflight cache | Task 4 |
| OAuth 2.0, offline, scope spreadsheets, consent prompt | Task 5 |
| Token set in `c2gs_google_token`, autoload off, refresh persists | Task 5 |
| `admin-post.php?action=c2gs_oauth_cb` redirect URI + nonce in `state` | Task 5, Task 7 |
| Google 401 → force refresh + one retry | Task 6 (`appendWithRetry`) |
| Not configured → early return + `c2gs_not_connected` notice | Task 6, Task 7 |
| All `Throwable` caught in listener; form never breaks | Task 6 |
| `c2gs_error_log` ring buffer max 50, newest-first | Task 3 |
| `c2gs_fail_count` transient drives admin notice, cleared on dismiss | Task 6, Task 7 |
| Settings page fields (client id/secret, spreadsheet id, tab) | Task 7 |
| Spreadsheet ID validated `/^[A-Za-z0-9_-]+$/` | Task 7 (`sanitize`) |
| Capability `manage_options` on page + handlers | Task 7 (`assertCap`, `renderNotices`) |
| Form Vibes-not-active admin notice | Task 7 (`formVibesActive`) |
| `vendor/` committed | Task 1 (Step 9) |
| PHP 8.1 / WP 6.0 headers | Task 1 (Step 3) |
| `uninstall.php` deletes all `c2gs_*` state | Task 8 |
| Unit tests: FieldMapper across 4 form shapes, ErrorLog cap, listener swallow, SheetsWriter mocked | Tasks 2, 3, 4, 6 |
| readme security note (no encryption at rest) | Task 8 (Step 6) |
| Manual test plan | Task 8 (Step 7) |

No gaps.

**2. Placeholder scan:** No "TBD"/"TODO"/"handle edge cases"/"similar to Task N". Every code step has literal code. Test steps contain real assertions. Task 4 Step 4 notes a concrete fallback assertion for an API-version difference rather than leaving it open.

**3. Type consistency:**
- `FieldMapper::toRow( string, string|int, string, array, string ): array` — same signature in Task 2 definition, Task 6 call (`is_scalar($formId) ? $formId : ''`), and Task 6 tests.
- `SheetsWriter::__construct( Sheets, string, string )` / `append( array ): void` — consistent across Tasks 4, 6, 8.
- `GoogleAuth::authedClient()`, `forceRefresh()`, `exchangeCode()`, `isConnected()`, `consentUrl()`, `disconnect()`, `newClient()` (protected) — defined Task 5, consumed Tasks 6–8 with matching names.
- `ErrorLog::add()/all()/clear()` and constants `OPTION`/`MAX` — consistent Tasks 3, 6, 7.
- `SubmissionListener::__construct( FieldMapper, ErrorLog, GoogleAuth, \Closure )` / `handle( array ): void` — consistent Tasks 6, 8.
- Writer factory closure shape `fn(): SheetsWriter` (throws when unconfigured) — same in Task 6 consumption, Task 8 `buildWriterFactory`.
- Option/transient names (`c2gs_settings`, `c2gs_google_token`, `c2gs_error_log`, `c2gs_tab_ready`, `c2gs_fail_count`, `c2gs_not_connected`) — identical everywhere and in `uninstall.php`.
- `GoogleAuth::CALLBACK_ACTION = 'c2gs_oauth_cb'` used to build the redirect URI in both `Settings` and `Plugin`.

Consistent.
