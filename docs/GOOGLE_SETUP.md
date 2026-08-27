# Google setup guide

This plugin talks to Google Sheets using **OAuth 2.0 user consent** (not a
plain API key). You create an OAuth client in Google Cloud, paste its Client
ID and Client secret into the plugin, then click "Connect Google" once. The
plugin stores a refresh token and keeps syncing on its own after that.

Total time: about 10 minutes. You need a Google account that can edit the
target spreadsheet, and access to the WordPress admin.

---

## Overview of the pieces

| Piece | Where it comes from | Where it goes |
|-------|--------------------|---------------|
| Google Cloud project | You create it | Container for everything below |
| Google Sheets API (enabled) | You toggle it on | Lets the project call Sheets |
| OAuth consent screen | You configure it once | Google shows it when you click Connect |
| OAuth Client ID + secret | Credentials page | Plugin settings: "Google Client ID" / "Google Client Secret" |
| Authorized redirect URI | Copied from the plugin settings page | Pasted back into the OAuth client |
| Spreadsheet ID | The sheet's URL | Plugin settings: "Spreadsheet ID" |

---

## Step 1: Create a Google Cloud project

1. Go to https://console.cloud.google.com/
2. Top bar, project dropdown, **New Project**.
3. Name it something like `wp-contact-to-gsheets`. No organization needed for
   a personal account.
4. **Create**, then make sure that new project is selected in the top bar.

## Step 2: Enable the Google Sheets API

1. Go to https://console.cloud.google.com/apis/library
2. Search **Google Sheets API**, open it, click **Enable**.
3. You do not need Google Drive API.

## Step 3: Configure the OAuth consent screen

1. Go to https://console.cloud.google.com/auth/overview (APIs and Services,
   OAuth consent screen).
2. **User type:**
   - **Internal** if your account is part of a Google Workspace org and only
     org accounts will connect. Simplest, no verification, tokens do not
     expire on a schedule.
   - **External** for a normal @gmail.com account. Pick this if unsure.
3. Fill the required fields: App name (e.g. `Contact to GSheets`), user
   support email, developer contact email. Logo and links are optional.
4. **Scopes:** click **Add or remove scopes**, filter for
   `https://www.googleapis.com/auth/spreadsheets`, tick it, **Update**.
   This is the only scope the plugin requests.
5. **Test users** (External only): add the exact Google account you will
   click "Connect Google" with. If you skip this you get `access_denied`.
6. Save.

### Important: publishing status and the 7-day token expiry

If the consent screen stays in **Testing** status (External apps), Google
**expires the refresh token after 7 days**. The plugin would then stop
syncing silently until you reconnect.

For a set-and-forget integration:

- **External:** on the OAuth consent screen, set **Publishing status** to
  **In production** (button: "Publish app"). For the single `spreadsheets`
  scope with one user this does not trigger a full Google verification
  review; you will see an "unverified app" warning on the consent screen
  that you click through once with "Advanced -> Go to <app> (unsafe)".
- **Internal:** nothing to do, tokens are long-lived.

## Step 4: Create the OAuth Client ID

1. Go to https://console.cloud.google.com/apis/credentials
2. **Create credentials -> OAuth client ID**.
3. **Application type: Web application**. (Not "Desktop", not "TVs and
   Limited Input" - the plugin uses a browser redirect.)
4. Name: `wp-plugin`.
5. **Authorized redirect URIs -> Add URI.** Paste the exact URI shown on the
   plugin settings page. It looks like:

   ```
   https://YOUR-SITE.com/wp-admin/admin-post.php?action=c2gs_oauth_cb
   ```

   It must match character for character, including `https` and no trailing
   slash. If your site is reachable at both `www` and non-`www`, add both.
6. **Create.** A dialog shows **Your Client ID** and **Your Client Secret**.
   Copy both now (you can also download the JSON, but you only need these two
   strings). The secret can be viewed again later on the credentials page.

## Step 5: Get the Spreadsheet ID

1. Open (or create) the Google Sheet that will receive submissions.
2. The account you will connect with must be able to **edit** this sheet
   (be the owner or have Editor access). The plugin does not use a service
   account, so there is nothing to "share with a bot".
3. Copy the ID from the URL, the part between `/d/` and `/edit`:

   ```
   https://docs.google.com/spreadsheets/d/1AbCdEf_gHiJkLmNoPqRsTuVwXyZ1234567890/edit#gid=0
                                          ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
                                          this is the Spreadsheet ID
   ```

4. You do **not** need to create the tab or header row. The plugin creates
   the tab (default name `Submissions`) and writes the header
   `timestamp | form | name | email | message | data` on the first
   submission.

## Step 6: Connect in WordPress

1. WordPress admin: **Settings -> Contact to GSheets**.
2. Paste **Google Client ID**, **Google Client Secret**, **Spreadsheet ID**.
   Set **Tab name** (or leave `Submissions`). **Save Changes**.
3. Confirm the **redirect URI** shown on the page matches what you entered in
   Step 4. If not, fix it in Google Cloud.
4. Click **Connect Google**.
5. Choose the Google account from Step 5. If you see "Google hasn't verified
   this app", click **Advanced -> Go to <app name> (unsafe)** - this is your
   own app.
6. Grant the "See, edit, create, and delete all your Google Sheets
   spreadsheets" permission. You land back on the settings page showing
   **Connected**.

## Step 7: Test

1. Submit one of your forms on the front end (Contact Form 7, WPForms,
   Elementor Forms, or Gravity Forms - anything Form Vibes captures).
2. Open the sheet. A new row appears in the `Submissions` tab within a few
   seconds.
3. Check the "Recent sync failures" list on the settings page is empty.

---

## Troubleshooting

| Symptom | Cause and fix |
|---------|---------------|
| `redirect_uri_mismatch` on the consent screen | The URI in the OAuth client (Step 4) does not exactly match the plugin's redirect URI. Copy it again from the settings page. Watch for `http` vs `https`, `www`, trailing slash. |
| `access_denied` right after choosing the account | External app in Testing status and this account is not in **Test users**. Add it (Step 3.5), or publish the app (Step 3). |
| Connected, but rows never appear; failures list shows `403` / `PERMISSION_DENIED` | The connected Google account cannot edit that spreadsheet, or the Spreadsheet ID is wrong. Give the account Editor access, re-check the ID. |
| Failures list shows `404` | Spreadsheet ID is wrong or the sheet was deleted. |
| Failures list shows `400` and mentions the range or tab | Tab name has an unusual character. Rename the tab in settings to something simple. |
| Worked for a week, then stopped; failures show `invalid_grant` | External app still in **Testing**: the refresh token expired after 7 days. Publish the app (Step 3), then click **Connect Google** again. |
| `401` once, then recovers | Normal. The access token expired and the plugin refreshed it. Only a persistent 401 is a problem. |
| "Contact to GSheets is not connected to Google" notice | You have not completed **Connect Google**, or the Spreadsheet ID field is empty. |

## Revoking access

- In the plugin: **Settings -> Contact to GSheets -> Disconnect**. This
  revokes the token with Google and clears it locally.
- In the Google account: https://myaccount.google.com/connections , find the
  app, **Remove access**.

## Security note

The Client secret and the OAuth tokens are stored in the WordPress `options`
table (not encrypted at rest - WordPress has no built-in mechanism for
that). Anyone with database access or admin access to the site can read them
and reach the connected Google account. Restrict both, and use a dedicated
Google account that only has access to the one spreadsheet rather than a
personal account with broad Sheets access.
