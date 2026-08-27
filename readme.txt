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
