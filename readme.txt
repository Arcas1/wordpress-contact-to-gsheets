=== Contact to GSheets ===
Contributors: arcas1
Tags: google sheets, contact form, form vibes, elementor forms, spreadsheet
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send every WordPress form submission to a Google Sheet in real time. Works with Form Vibes, Elementor, CF7, WPForms, Fluent Forms and more.

== Description ==

Contact to GSheets copies every form submission on your site into a Google
Sheet the moment it is sent. One row per submission:

`timestamp | form | name | email | message | data`

The name, email and message columns are detected automatically from the
submitted fields. The `data` column keeps the full submission as JSON, so you
never lose a field. The target tab and its header row are created for you on
the first submission.

No Zapier, no monthly fee, no external service in the middle: the plugin talks
straight to the Google Sheets API from your server using a Google account you
connect once.

= Supported form plugins =

**Through Form Vibes** (install the free Form Vibes plugin; it captures these):
Contact Form 7, WPForms, Elementor Forms, Gravity Forms, Ninja Forms, WS Form,
Everest Forms, Caldera Forms, Bricks, Beaver Builder.

**Directly, with no extra plugin:**
MetForm, Fluent Forms, Forminator, Formidable Forms, and the Jetpack / block
contact form.

= Why you might want it =

* Keep a live spreadsheet of leads without checking the WordPress admin.
* Share submissions with people who do not have a WordPress login.
* Build charts, filters and formulas on top of your form data in Sheets.
* One sheet for every form on the site, or point each site at its own sheet.

= How it works =

Each form plugin has a small adapter that listens for its "submission saved"
event and normalises the fields. Every adapter feeds one shared pipeline that
maps the submission to the fixed row and appends it to your sheet over HTTPS.
If Google cannot be reached, the failure is logged and shown as an admin
notice; the visitor's form still submits normally and the full data stays in
your form plugin.

Setup is guided: the plugin ships a step-by-step **Setup guide** tab, in
English and Spanish, that walks you through creating the Google credentials and
connecting your account (about 10 minutes, one time).

= Español =

Contact to GSheets copia cada envio de formulario de tu sitio a una hoja de
Google Sheets en el momento en que se envia. Una fila por envio:

`timestamp | form | name | email | message | data`

Las columnas de nombre, correo y mensaje se detectan solas a partir de los
campos enviados. La columna `data` guarda el envio completo como JSON, asi no
pierdes ningun dato. La pestana de destino y su fila de encabezado se crean
automaticamente en el primer envio.

Sin Zapier, sin cuota mensual y sin servicios externos de por medio: el plugin
habla directamente con la API de Google Sheets desde tu servidor, usando una
cuenta de Google que conectas una sola vez.

**Formularios compatibles.** A traves de Form Vibes (plugin gratuito):
Contact Form 7, WPForms, Elementor Forms, Gravity Forms, Ninja Forms, WS Form,
Everest Forms, Caldera Forms, Bricks, Beaver Builder. De forma directa, sin
plugins extra: MetForm, Fluent Forms, Forminator, Formidable Forms y el
formulario de contacto de Jetpack / bloque.

**Para que sirve.** Manten una hoja de calculo viva con tus contactos sin
entrar al panel de WordPress, compartela con personas que no tienen usuario de
WordPress, y arma graficos, filtros y formulas sobre los datos en Sheets.

La configuracion es guiada: el plugin incluye una pestana **Setup guide**, en
ingles y espanol, que te lleva paso a paso a crear las credenciales de Google
y conectar tu cuenta (unos 10 minutos, una sola vez).

== Installation ==

1. Install and activate the plugin (Plugins -> Add New -> Upload Plugin, or
   search for it in the plugin directory).
2. Go to **Settings -> Contact to GSheets** and open the **Setup guide** tab.
3. Follow the guide: create a Google Cloud project, enable the Google Sheets
   API, create an OAuth 2.0 Client ID of type "Web application", and add the
   redirect URI the settings page shows you.
4. Back on the **Settings** tab, upload the OAuth client JSON you downloaded
   from Google (or paste the Client ID and Client Secret), add your Spreadsheet
   ID, and Save.
5. Click **Connect Google** and grant access to Google Sheets.
6. Submit a test form. A new row appears in your sheet within a few seconds.

If you use Contact Form 7, WPForms, Elementor, Gravity Forms, Ninja Forms or
WS Form, also install the free **Form Vibes** plugin so those submissions are
captured. MetForm, Fluent Forms, Forminator, Formidable Forms and the Jetpack
contact form work without it.

== Frequently Asked Questions ==

= Do I need a paid Google account? =

No. A free personal Google account works. The Google Cloud project and the
Sheets API are free at this volume.

= Does the sheet have to exist first? =

The spreadsheet has to exist and the Google account you connect must be able to
edit it. You do not need to create the tab or the header row; the plugin does
that on the first submission.

= My @gmail.com connection stopped working after about a week. =

While the Google OAuth consent screen is in "Testing" status, Google expires
the connection after 7 days. On the consent screen click "Publish app"
(Publishing status: In production), then reconnect. This is covered in the
Setup guide.

= Where are my Google credentials stored? =

The Client Secret and OAuth tokens are stored in the WordPress options table
(not autoloaded). WordPress does not encrypt options at rest, so anyone with
database or admin access to the site can read them. Restrict that access and
prefer a dedicated Google account scoped to just the one spreadsheet.

= What happens if Google is unreachable when a form is submitted? =

The submission still completes for the visitor. The sync failure is logged and
shown as an admin notice, and the full data remains in your form plugin. There
is no automatic retry; delivery is real time only.

= How are the name, email and message columns filled? =

Automatically, by looking at the field names and types. The full submission is
always written to the `data` column as JSON, so nothing is lost even when a
guess is wrong. There is no per-form mapping screen.

= Does it work on multisite? =

It runs per site. Network activation is not specifically supported.

== Screenshots ==

1. The Settings tab: Google connection status, credentials, spreadsheet ID.
2. The built-in Setup guide tab (English / Spanish).
3. Submissions arriving as rows in a Google Sheet.
4. The "Recent sync failures" list on the settings page.

== Changelog ==

= 0.5.0 =
* Replaced the bundled Google API PHP client with direct HTTPS calls to the
  Google OAuth and Sheets endpoints. The plugin no longer ships Guzzle or any
  third-party runtime library, and cannot conflict with other plugins.
* Added a nonce to the "Connect Google" action.
* readme and headers prepared for the WordPress.org plugin directory.

= 0.4.0 =
* Upload the Google OAuth client JSON to fill Client ID and Client Secret
  instead of copying them by hand.

= 0.3.0 =
* Added direct support for Forminator, Formidable Forms, and the Jetpack /
  block contact form.

= 0.2.0 =
* Added direct support for MetForm and Fluent Forms.

= 0.1.0 =
* First release. Form Vibes submissions to Google Sheets in real time, with a
  guided OAuth setup, auto-created tab and header, and a failure log.

== Upgrade Notice ==

= 0.5.0 =
The Google API client is gone; the plugin is now dependency-free. Re-check the
Settings page shows "Connected" after updating.
