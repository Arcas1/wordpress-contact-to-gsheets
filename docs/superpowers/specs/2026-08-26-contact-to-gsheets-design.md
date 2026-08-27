# Contact to GSheets — Design Spec

**Date:** 2026-08-26
**Status:** Approved (design), pending implementation plan

## Purpose

A WordPress plugin that sends every new form submission captured by
**Form Vibes 1.5.3** to a Google Sheet in real time, one row per
submission. Works regardless of which underlying form plugin produced
the submission (Contact Form 7, WPForms, Elementor Forms, Gravity
Forms — any integration Form Vibes supports).

## Requirements

### Functional

- On each successful Form Vibes entry save, append one row to a
  configured Google Sheet tab.
- Row schema (fixed, single tab for all forms):

  | Column | Source |
  |--------|--------|
  | `timestamp` | `wp_date('c')` at time of submission (ISO 8601, site timezone) |
  | `form` | Form title/label from the Form Vibes payload; falls back to `plugin_name #form_id` |
  | `name` | Auto-detected from submitted fields |
  | `email` | Auto-detected from submitted fields |
  | `message` | Auto-detected from submitted fields |
  | `data` | `wp_json_encode` of the full sanitized posted-data map |

- Auto-create the target tab if missing; auto-write the header row if
  row 1 is empty.
- Admin settings page to configure Google OAuth credentials, connect a
  Google account, set the spreadsheet ID and tab name, and view recent
  sync failures.

### Non-functional

- Real-time only. No retry queue, no cron batching. A failed append is
  logged and surfaced to the admin; the row is not re-attempted
  automatically.
- A failure anywhere in the sync path must never break or block the
  visitor's form submission.
- PHP 8.1+, WordPress 6.0+, Form Vibes active.

### Out of scope

- Backfilling existing `wp_fv_entries` rows.
- Retry queue / cron flushing.
- Per-form field-mapping UI (auto-detect only).
- Multisite network activation.
- Encryption at rest for stored secrets/tokens.
- Any path that does not go through Form Vibes.

## Integration point

Form Vibes 1.5.3 fires this action in its integration base class
(`inc/integrations/base.php`) after every successful entry-meta write,
for all form-plugin integrations:

```php
do_action( 'fv_after_entry_meta_success', [
    'insert_id'   => $insert_id,
    'plugin_name' => $plugin_name,   // e.g. 'cf7', 'wpforms', 'elementor', 'gravityforms'
    'form_id'     => $form_id,
    'entry_data'  => $entry_data,    // includes 'posted_data' => [ field_name => value ], plus 'title', 'url', timestamps
    'entires'     => $entires,       // rows inserted as entry meta (note: upstream typo 'entires')
] );
```

The plugin hooks `fv_after_entry_meta_success` only. It reads
`entry_data['posted_data']` as the field map and `entry_data['title']`
(when present) as the form label.

Field values in `posted_data`: strings, or arrays already flattened by
Form Vibes with `implode(', ', ...)`. Form Vibes injects `fv_plugin`
and `fv_form_id` keys, and optionally an `IP` key — these are stripped
before mapping and before building the `data` column.

## Architecture

Structured OOP, Composer PSR-4 autoload, `vendor/` committed to the
repo (target WordPress hosts cannot run Composer). `google/apiclient`
(current major, PHP 8.1+) provides the Google client and Sheets
service.

### Classes (`src/`)

| Class | Responsibility | WP deps |
|-------|----------------|---------|
| `Plugin` | Singleton bootstrap. Require Composer autoload, wire hooks, register activation/admin-notice checks, instantiate collaborators. | yes |
| `Settings` | Register the settings page (**Settings → Contact to GSheets**) via the Settings API. Fields: Google Client ID, Client Secret, Spreadsheet ID, Tab name (default `Submissions`). Render: copyable redirect URI, Connect/Disconnect button, connection status, recent-failure list. Persists to option `c2gs_settings`. | yes |
| `GoogleAuth` | Wrap `Google\Client`. Scope `https://www.googleapis.com/auth/spreadsheets`. Build consent URL; handle the OAuth callback; exchange code; store token set (`access_token`, `refresh_token`, `expires_at`) in option `c2gs_google_token` (autoload off). Transparently refresh via refresh token and persist the new token. Disconnect: revoke + delete option. | yes |
| `SubmissionListener` | `add_action( 'fv_after_entry_meta_success' )`. Extract `plugin_name`, `form_id`, label, `posted_data`. Call `FieldMapper` then `SheetsWriter`. Wrap the whole body in try/catch: on any `Throwable`, write to `ErrorLog` and bump the `c2gs_fail_count` transient; never rethrow. No-op (with a one-time "not connected" admin notice) when no token or no spreadsheet ID is configured. | yes |
| `FieldMapper` | Pure. No WordPress calls except `is_email()` (injected or wrapped for testability). Strip internal keys (`fv_plugin`, `fv_form_id`, `IP`). Produce the ordered 6-value row. | no (injectable) |
| `SheetsWriter` | Use `Google\Service\Sheets`. Ensure the tab exists (spreadsheet metadata → `batchUpdate` `addSheet` when missing). Ensure the header row (read `Tab!A1:F1`; if empty, `values.update` the header). Append via `values.append` with `valueInputOption=USER_ENTERED`, `insertDataOption=INSERT_ROWS`, range `Tab!A:F`. Cache "tab+header ready" in a 10-minute transient (`c2gs_tab_ready`) to skip metadata/header calls on subsequent submissions. | yes (transient) |
| `ErrorLog` | Ring buffer of the last 50 failures in option `c2gs_error_log`: `{ time, form_id, plugin_name, http_code, message }`. Helpers to append and to render for the settings page. | yes |

### Options / storage

| Key | Autoload | Contents |
|-----|----------|----------|
| `c2gs_settings` | yes | `client_id`, `client_secret`, `spreadsheet_id`, `tab_name` |
| `c2gs_google_token` | no | `access_token`, `refresh_token`, `expires_at`, `scope` |
| `c2gs_error_log` | no | array (max 50) of failure records |
| `c2gs_fail_count` (transient) | — | int; drives the admin failure notice, cleared on dismiss |
| `c2gs_tab_ready` (transient, 10 min) | — | bool; skip tab/header preflight |

### OAuth callback

Redirect URI: `https://SITE/wp-admin/admin-post.php?action=c2gs_oauth_cb`
(admin-post pattern, no rewrite rules). Handler verifies a nonce in
the `state` parameter, exchanges `code`, stores the token set, and
redirects back to the settings page with a status flag.

The OAuth client in Google Cloud must be type **Web application** with
that exact redirect URI registered. The settings page displays the URI
for copy/paste.

## Auto-detection rules (`FieldMapper`)

Given the cleaned `posted_data` map (internal keys removed):

- **email**: first value for which `is_email()` returns true. Fallback:
  value of the first field whose key matches `/e-?mail|correo/i`.
- **name**: value of the first field whose key matches `/name|nombre/i`
  and whose value is not the detected email. Fallback: first remaining
  non-email scalar value.
- **message**: the longest string value among fields not already chosen
  as name/email. Fallback: newline-joined `"{key}: {value}"` of all
  remaining fields.
- **data**: `wp_json_encode` of the full cleaned map (before
  name/email/message extraction), values cast to string.
- **form label**: `entry_data['title']` when non-empty, else
  `"{plugin_name} #{form_id}"`.
- **row order**: `[ timestamp, form, name, email, message, data ]`.

Any column with no detected value is written as an empty string.

## Data flow

```
visitor submits form (CF7 / WPForms / Elementor / Gravity)
  -> form plugin processes
  -> Form Vibes inserts entry + entry meta
  -> do_action( 'fv_after_entry_meta_success', $payload )
     -> SubmissionListener::handle( $payload )        [try/catch Throwable]
        -> guard: token + spreadsheet_id present?  no -> admin notice, return
        -> FieldMapper::toRow( plugin_name, form_id, title, posted_data ) -> array(6)
        -> SheetsWriter::append( row )
           -> transient c2gs_tab_ready?  no -> ensure tab, ensure header, set transient
           -> Google Sheets values.append
        -> on Throwable: ErrorLog::add(...), bump c2gs_fail_count transient
```

## Error handling

- **Not configured** (no token or no spreadsheet ID): listener returns
  early; a single dismissible admin notice explains the plugin is not
  connected.
- **Google 401 / auth error**: `GoogleAuth` forces one token refresh;
  `SheetsWriter` retries the append once. Still failing → log.
- **Any other `Throwable`** in the listener path: caught, logged to
  `ErrorLog`, `c2gs_fail_count` transient incremented. The visitor's
  submission is unaffected.
- **Form Vibes not active**: admin notice on every admin page; the
  plugin does not deactivate itself and its hook simply never fires.
- **Admin failure notice**: shown while `c2gs_fail_count` > 0, links to
  the settings page failure list, cleared on dismiss.

## Security notes

- `client_secret` and OAuth tokens live in `wp_options` with autoload
  off. WordPress provides no encryption at rest; `readme.txt`
  documents that database access equals Google access. Encrypting
  these is explicitly out of scope for this version.
- OAuth callback validates a nonce carried in `state`.
- All settings inputs sanitized (`sanitize_text_field`); spreadsheet
  ID validated against `/^[A-Za-z0-9_-]+$/`.
- `data` column values cast to string and JSON-encoded; no HTML
  rendering of submitted content anywhere in the plugin.
- Capability check `manage_options` on the settings page and the OAuth
  callback handler.

## Testing

### Unit — PHPUnit, no WordPress runtime

- `FieldMapper`:
  - email/name/message detection across representative payload shapes
    for CF7, WPForms, Elementor, Gravity Forms.
  - internal-key stripping (`fv_plugin`, `fv_form_id`, `IP`).
  - array-valued fields (already comma-joined) handled.
  - `data` column is valid JSON containing every non-internal field.
  - row order and empty-string fallback for missing columns.
  - form-label fallback when `title` is absent.
- `ErrorLog`: ring buffer never exceeds 50; newest-first ordering;
  record shape.

### Unit — Brain Monkey (WP functions mocked)

- `SubmissionListener`: correct extraction from the payload; guard
  returns early when unconfigured; a thrown `SheetsWriter` error is
  swallowed and logged, not rethrown.

### Mocked collaborator

- `SheetsWriter` with a fake `Google\Service\Sheets`:
  - `addSheet` issued when the tab is absent.
  - header `values.update` issued when `A1:F1` is empty.
  - `values.append` called with `USER_ENTERED` / `INSERT_ROWS` and the
    expected range.
  - preflight skipped when `c2gs_tab_ready` transient is set.
- No live Google API calls in CI.

### Manual test plan

1. Configure client ID/secret, complete the Connect Google flow, set
   spreadsheet ID + tab.
2. Submit one form of each type (CF7, WPForms, Elementor, Gravity);
   confirm one correct row each, header auto-created.
3. Revoke the token in the Google account; submit again; confirm the
   admin failure notice and a matching `ErrorLog` entry, and that the
   visitor still saw a normal form success.
4. Re-connect; submit; confirm syncing resumes.
5. Delete the tab; submit; confirm the tab and header are recreated.

### Coverage

80% line coverage on the non-WordPress classes (`FieldMapper`,
`ErrorLog`, and the row-building logic).

## Deliverables

```
contact-to-gsheets/
├── contact-to-gsheets.php      # header + autoload + Plugin::instance()
├── uninstall.php               # delete c2gs_* options
├── composer.json
├── vendor/                     # committed
├── src/
│   ├── Plugin.php
│   ├── Settings.php
│   ├── GoogleAuth.php
│   ├── SubmissionListener.php
│   ├── FieldMapper.php
│   ├── SheetsWriter.php
│   └── ErrorLog.php
├── tests/
│   ├── FieldMapperTest.php
│   ├── ErrorLogTest.php
│   ├── SubmissionListenerTest.php
│   └── SheetsWriterTest.php
├── phpunit.xml.dist
└── readme.txt
```

## Open questions

None outstanding. All configuration and behavior decisions resolved
during brainstorming.
