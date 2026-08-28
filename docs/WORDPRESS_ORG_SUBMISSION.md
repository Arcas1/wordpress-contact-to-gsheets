# Submitting to the WordPress.org plugin directory

Status of the code: the plugin ships **no third-party runtime code**, all output
is escaped, all input sanitized, capability + nonce checks are in place, and it
is fully translatable. `make zip` produces the review artifact (~36 KB).

## Before you submit - things only you can do

1. **wordpress.org account.** Create one at https://login.wordpress.org/register
   if you do not have it. Your username becomes the `Contributors` value in
   `readme.txt` (currently `arcas1` - change it if your username differs, it
   must be lowercase and exact).

2. **Plugin display name.** `readme.txt` and the plugin header currently say
   **"Contact to GSheets"**. "GSheets" is a nickname for a Google product and
   the review team may ask you to change it. Safer options that describe the
   function without leading with a trademark:
   - Form Entries to Google Sheets
   - Submissions to Google Sheets Sync
   The **slug** (`contact-to-gsheets`) can stay even if the display name
   changes - only the `=== Name ===` line in `readme.txt` and `Plugin Name:` in
   the header need editing. Do not rename `src/`, the `C2GS\` namespace, the
   `c2gs_` option prefix or the `contact-to-gsheets` text domain.

3. **Screenshots.** `readme.txt` declares four. Produce PNGs named
   `screenshot-1.png` ... `screenshot-4.png` (1280x960 or similar):
   1. Settings tab
   2. Setup guide tab
   3. Rows arriving in a Google Sheet
   4. The "Recent sync failures" list
   These go in the SVN **`assets/`** directory, **not** in the plugin zip.

4. **Banner / icon (optional but recommended).** `assets/banner-1544x500.png`,
   `assets/banner-772x250.png`, `assets/icon-256x256.png`.

## Step 1 - submit for review

1. Go to https://wordpress.org/plugins/developers/add/
2. Upload `build/contact-to-gsheets-v0.5.0.zip` (rebuild with `make zip` after
   any edit).
3. Wait for the review email. First review is usually a few days to a few
   weeks. They will reply with any required changes; fix and reply in the same
   thread (do not open a new submission).

## Step 2 - once approved: the SVN repo

You get an SVN URL like `https://plugins.svn.wordpress.org/<your-slug>/`.

```bash
svn co https://plugins.svn.wordpress.org/<your-slug>/ svn-c2gs
cd svn-c2gs

# Plugin files go in trunk/ (everything from the built stage, not the repo root)
rsync -a --delete \
  --exclude '.git' --exclude 'tests' --exclude 'docs' --exclude 'vendor' \
  --exclude 'Makefile' --exclude 'composer.*' --exclude 'phpunit.xml.dist' \
  --exclude 'build' --exclude '.gitignore' \
  /path/to/wordpress-contact-to-gsheets/ trunk/

# Screenshots / banners / icon go in assets/ (top level, sibling of trunk/)
cp /path/to/screenshot-*.png assets/

svn add --force trunk assets
svn ci -m "Initial release 0.5.0"

# Tag the release
svn cp trunk tags/0.5.0
svn ci -m "Tag 0.5.0"
```

`Stable tag: 0.5.0` in `trunk/readme.txt` is what tells wordpress.org which tag
to serve. Keep it in sync with the tag you create.

## Releasing a new version later

1. Bump `Version:` in `contact-to-gsheets.php` and `Stable tag:` +
   `== Changelog ==` in `readme.txt`.
2. `make zip` and test.
3. Copy the files into `trunk/`, `svn ci`, then `svn cp trunk tags/X.Y.Z` and
   `svn ci`.

## What the review will look at (already handled)

- No bundled libraries, no external HTTP except the Google APIs the plugin
  exists to talk to.
- `$_GET` / `$_POST` / `$_FILES` are unslashed and sanitized; the two reads that
  rely on an upstream nonce carry an explaining `phpcs:ignore ... -- reason`.
- Every echo is escaped; `wp_kses` limits the HTML in admin notices and the
  setup guide.
- Settings use the Settings API with a sanitize callback; the OAuth callback
  validates a nonce carried in `state`; "Connect Google" is nonce-protected.
- `uninstall.php` removes every `c2gs_*` option and transient.
- Text domain matches the slug; strings are wrapped; a `.pot` is included.
- GPLv2+ with `LICENSE` and per-file header.
