# Contact to GSheets

WordPress plugin that sends every form submission to a Google Sheet in real
time, one row per submission.

Column A is the form (its name, or the page URL it was submitted from). Every
other column is one of the form's own fields, added to the header row the first
time it appears. Different forms share one tab; manual renames / reordering in
the sheet are respected. The tab and header row are created automatically on
the first submission.

## Supported forms

**Through Form Vibes** (install Form Vibes 1.5.3+; it captures these):
Contact Form 7, WPForms, Elementor Forms, Gravity Forms, Ninja Forms, WS Form,
Everest Forms, Caldera Forms, Bricks, Beaver Builder.

**Directly, no Form Vibes needed:**
MetForm, Fluent Forms, Forminator, Formidable Forms, Jetpack / block contact
form.

## Install

1. Download `contact-to-gsheets-vX.Y.Z.zip` from the
   [Releases](https://github.com/Arcas1/wordpress-contact-to-gsheets/releases)
   page (or build one with `make zip`).
2. WordPress admin: Plugins -> Add New -> Upload Plugin -> pick the zip ->
   Activate.
3. Settings -> Contact to GSheets. Follow the built-in **Setup guide** tab
   (English / Spanish) to create the Google OAuth client, then Connect Google.

Full walkthrough: [`docs/GOOGLE_SETUP.md`](docs/GOOGLE_SETUP.md).

## Requirements

- WordPress 6.0+
- PHP 8.1+
- A Google account that can edit the target spreadsheet

## How it works

Each form source has a small adapter that hooks that plugin's submission action
and normalizes the fields. Every adapter feeds one shared pipeline
(`SubmissionSync`): normalize the fields, resolve the form label, then
`SheetsWriter` aligns the values to a dynamic column set (extending the header
row when a new field shows up) and appends via direct HTTPS calls to the Google
Sheets REST API (`wp_remote_*`, no bundled SDK). One retry on a 401; failures
go to a capped option surfaced as an admin notice. A failure never blocks the
visitor's submission.

Delivery is real-time only: no cron, no retry queue. A submission that cannot
reach Google is logged, not re-sent; the full data stays in the source form
plugin.

## Development

```bash
composer install      # dev deps (PHPUnit, Brain Monkey, Mockery)
composer test         # or: vendor/bin/phpunit
make zip              # build/contact-to-gsheets-vX.Y.Z.zip (no-dev, packaged)
```

Tests are unit-level with WordPress and the Google client mocked; no WordPress
test suite required. The version string in `contact-to-gsheets.php` drives the
zip name.

## Security

The Google Client Secret and OAuth tokens are stored in the WordPress options
table (autoload off). WordPress does not encrypt options at rest. Restrict
admin and database access, and prefer a dedicated Google account scoped to just
the one spreadsheet. See the security note in `readme.txt`.

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
