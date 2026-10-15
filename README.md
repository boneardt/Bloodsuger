# Bloodsuger

For keeping track of blood sugar measurements. A small self-hosted PHP app: upload Contour glucose-meter CSV exports, see readings colored by clinical target range, track rolling averages, and export a PDF report. Plain PHP + SQLite — no build step, no Composer, deployable by FTP.

## Requirements

- PHP 8.1+ with the `pdo_sqlite` and `mbstring` extensions. Both are enabled by default on virtually every PHP install and shared-hosting preset — nothing extra to install in the normal case.
- Apache with `.htaccess` support (`AllowOverride`) — the bundled `.htaccess` files block direct web access to `data/`, `includes/`, and `vendor/`. On Nginx (which ignores `.htaccess`), add equivalent `location` blocks denying those paths, or move them outside the web root — there's no Nginx config bundled here, so this is on you if you're not on Apache.

## Install — local

1. Make sure you have PHP 8.1+ available (`php -v`). No PHP on your machine? XAMPP, MAMP, or Laragon all bundle one and work fine too.
2. Copy `config.sample.php` to `config.php`. The defaults work for local testing — `cookie_secure` only needs to be `true` once you're serving over real HTTPS. Set `timezone` to your own (a [PHP timezone identifier](https://www.php.net/timezones)) — it's used for rolling averages and the Apple Health sync.
3. From the project folder, run:
   ```
   php -S localhost:8000
   ```
4. Visit `http://localhost:8000/` — it redirects to the first-run setup wizard automatically.

## Install — your own server / shared hosting

1. Upload everything **except** `config.php` (you'll create it directly on the server) to wherever you want the app to live — the domain root, or a subfolder (e.g. `https://your-domain.example/bloodsuger`). The app detects its own base path automatically, so no path configuration is needed either way.
2. On the server, copy `config.sample.php` to `config.php` and set `cookie_secure` to `true` once HTTPS is enabled for the domain — most hosts offer free SSL (e.g. via Let's Encrypt); turn it on before going further. Also set `timezone` to your own.
3. Make sure the `data/` folder is writable by PHP (it holds the SQLite database, created automatically on first request). Most hosts default new folders to writable; if you get a "database file could not be opened" error, adjust permissions to `755`/`775`.
4. Visit the site — you land on the setup wizard automatically since no accounts exist yet. Create the administrator account there. The wizard permanently disappears once this is done.
5. Log in and, from **Settings**, add the remaining accounts (up to 4 total, mixing administrator and read-only as needed). Import your first CSV from **Import CSV**.

If you have SSH access, `git clone` the repo directly on the server instead of uploading files one by one — everything else above still applies.

## CSV format

The importer was built and tested against a **Contour Plus Blue** meter's CSV export, with **Danish column headers**. It expects:

- `Dato og Tid` (date/time, format `DD.MM.YYYY HH.MM.SS`) and `BGValue[mmol/L]` (decimal-comma numbers, e.g. `7,2`) — **required**; the import is rejected with a clear error if either column is missing.
- `Måltidsmarkør`, `Datakilde`, `Notater`, `Aktivitet`, `Måltid[g]`, `Medicin`, `Land` — optional; a row imports fine if any of these are blank or the column is absent.

**Other languages and meters aren't supported yet.** If your export uses different column headers, the fix is to edit the literal header strings in `includes/csv_import.php` — the required-column check near the top of `bs_import_csv()`, and the `$field('...')` calls further down — to match your own CSV's headers. This is a known limitation, not a bug; contributions to make it configurable are welcome.

## Automated sync (iPhone)

Beyond the manual **Import CSV** page (always available, no setup needed), there are two ways to automate getting readings in from an iPhone with a **Shortcut** — no custom app, nothing to install beyond what's already there. Both are authenticated by the one **sync token** shown in **Settings** under "Automated sync", sent as an `X-Sync-Token` header. Both rely only on Apple's own documented Shortcuts actions, but are still worth testing carefully with your own data before trusting them daily.

### Option A: share the Contour app's CSV report (full data — recommended)

The Contour Diabetes app has its own CSV export (My Care → Reports → Raw Data (csv) → Share). This sends the **exact same CSV** Import CSV already accepts — notes, activity, meal marker, meal grams, medication, country, everything — so this is the more complete of the two options.

**Important: this has to be a two-tap routine, not one-tap.** Contour's Share action hands third-party extensions (including a Shortcut) an auto-generated cover-note instead of the actual CSV — confirmed through extensive testing, not fixable from the Shortcut side. Only Contour's own "Gem i Arkiver" (Save to Files) gets the real file, so the Shortcut has to be triggered from *there* instead of from Contour directly.

**1. Get the CSV import URL and sync token** from Settings.

**2. Build the Shortcut** (Shortcuts app → `+`):
1. Tap the Shortcut's settings (ⓘ) → **Use as Share Sheet Action** (or **Action Extension**, naming varies by iOS version) → restrict accepted types to "Files".
2. Add **Get Contents of URL**:
   - Method: `POST`
   - URL: the CSV import URL from Settings
   - Headers: `X-Sync-Token` = the sync token from Settings
   - Request Body: `Form`, one field named `csv`, type **File**, value: **Shortcut Input** (the file received from the share sheet).
3. Optionally add **Show Notification** after it, showing the response (it replies with a line like `added=3 updated=12 skipped=0`) — though in testing, notifications triggered from a Share Sheet Action Extension weren't always reliably displayed; don't take a missing notification alone as proof of failure, check the dashboard too.

**3. Use it — two taps:**
1. In Contour: Reports → Raw Data (csv) → Share → **"Gem i Arkiver"** (Save to Files) — *not* your Shortcut directly.
2. Open the **Files** app, find that saved file, tap **Share** on it, and pick your Shortcut this time.

### Option C: a Mac watches a synced folder (fully automatic, no Shortcut needed)

If you have a Mac that's usually on, this is the least-friction option of the three: **one tap** on your phone, and the Mac does the rest on its own — no Shortcut, no Share Sheet involved at all.

**How it works:** you save Contour's CSV export into a folder that's synced via iCloud Drive to a folder on your Mac. A small agent on the Mac, triggered automatically by macOS the moment that folder changes (not polling), finds the newest Contour export and uploads it.

**Setup — run the installer:**

1. Pick (or create) a folder that syncs via iCloud Drive to both your iPhone's Files app and your Mac — confirm it actually syncs by saving a test file from the phone and checking it appears on the Mac within a minute or two before going further. A plain folder under `~/Documents` on the Mac usually works, but verify it rather than assume — iCloud Drive sync can be surprisingly flaky to debug otherwise.
2. Clone this repo onto the Mac (or just download `tools/mac-agent/`).
3. Open Terminal, `cd` into the repo, and run:
   ```
   ./tools/mac-agent/install.sh
   ```
4. It'll ask for the CSV import URL and sync token (from your site's Settings → "Automated sync") and the folder from step 1 (you can drag the folder from Finder into the Terminal window instead of typing the path), then set everything up and walk you through the one manual step that can't be automated: granting Full Disk Access to `/bin/bash` in System Settings, which `launchd`-run scripts need in order to see your Documents folder at all (silently blocked otherwise, no error — macOS's privacy protections, not a bug).

To remove it later (or before re-running with different settings), run `./tools/mac-agent/uninstall.sh`.

<details>
<summary>What the installer actually sets up, if you'd rather do it by hand or want to see what it's doing</summary>

- `~/.config/bloodsuger/token` (600 perms) and `~/.config/bloodsuger/config` (`SYNC_URL=`, `WATCH_FOLDER=`) — your settings, never committed anywhere.
- `~/Library/Application Support/Bloodsuger/sync.sh` — reads that config, finds the newest `ContourCSVReport*.csv` in the watched folder, and `curl`s it to `sync_csv.php` with the token as an `X-Sync-Token` header.
- `~/Library/LaunchAgents/com.bloodsuger.sync.plist` — a `launchd` agent with a `WatchPaths` entry on your folder, running that script, loaded via `launchctl bootstrap`.
- The Full Disk Access grant described above (the installer can open the right System Settings pane for you, but can't flip the toggle itself).

</details>

**Use it:** Contour → Share → "Gem i Arkiver" into your synced folder. That's it — the Mac picks it up on its own within a few seconds, as long as it's on and signed in. If the Mac is asleep when you save, nothing is lost — the next time the folder changes, the script always grabs whichever Contour export is newest.

### Option B: read from Apple Health (lighter, less data)

If the Contour app is set to share Blood Glucose readings into Apple Health, a Shortcut can read them from there instead — but Health only ever has what Contour chose to write into it (the reading's value and timestamp, and possibly a before/after-meal tag), never free-text notes or activity.

**1. Get the Apple Health sync URL and sync token** from Settings.

**2. Build the Shortcut:**
1. **Find Health Samples** — Type: `Blood Glucose`. Sort by Start Date, descending. Limit to around 20 (adjust if you test less often and want to cover more history per run).
2. **Repeat with Each** — on the samples found above. Inside the repeat:
   1. **Format Date** — input: Repeat Item's Start Date. Format: ISO 8601 (include the date, time, and timezone offset).
   2. **Get Contents of URL**:
      - Method: `POST`
      - URL: the Apple Health sync URL from Settings
      - Headers: `X-Sync-Token` = the sync token from Settings
      - Request Body: `Form`, two fields — `timestamp` = the formatted date from the step above, `value` = Repeat Item's Blood Glucose Sample (its numeric value).

**3. Run it** by tapping the Shortcut. This only moves data that's *already* in Apple Health, so the Contour app still needs to have synced with the meter over Bluetooth first, same as today.

### Both options

Check the dashboard after running either Shortcut — new rows appear with "Apple Health" or the CSV's own data source as their data source (visible when you tap a row open). Run the same Shortcut again immediately: the same readings come back as "updated" rather than duplicating, since the server matches by exact timestamp — safe to re-run as often as you like.

**Troubleshooting:**
- A `401` response means the token doesn't match — copy it again from Settings (it may have been regenerated, which invalidates both URLs' old token at once).
- A `422` response means the upload/data was rejected — for the Health sync, check your Health app's Blood Glucose unit is mmol/L, not mg/dL (Health app → Browse → Blood Glucose → unit); for the CSV sync, check the shared file is actually the Raw Data CSV, not one of the PDF reports.

## Project layout

- `index.php`, `login.php`, `logout.php`, `setup.php`, `import.php`, `settings.php`, `logins.php`, `sync_health.php`, `sync_csv.php`, `export_pdf.php`, `forbidden.php` — one file per page, plain PHP.
- `includes/` — shared logic: `db.php` (SQLite connection + schema), `auth.php` (sessions, login, roles), `helpers.php` (CSRF, flashes, CSV field parsing), `csv_import.php`, `color_classifier.php`, `aggregates.php`, `pdf_builder.php`, and the shared page chrome (`layout_header.php` / `layout_footer.php`).
- `assets/` — self-hosted fonts, CSS, and the two small JS files (theme toggle, dashboard filtering/tap-to-expand).
- `vendor/tfpdf/` — the [tFPDF](https://github.com/Setasign/tFPDF) library (vendored directly, no Composer) used for the PDF export, with bundled DejaVu Sans fonts for correct æ/ø/å rendering.
- `data/` — the SQLite database file lives here at runtime (gitignored).

## Notes on the threshold model

Colors are computed live from four admin-configurable boundaries (Settings page), never stored on a measurement — changing them recolors all historical data immediately:

```
value <  A            -> RED     (too low)
A <= value <  B        -> YELLOW  (borderline low)
B <= value <= C         -> GREEN   (in range)
C <  value <= D         -> YELLOW  (borderline high)
value >  D              -> RED     (too high)
```

Defaults (3.9 / 4.4 / 10.0 / 13.0 mmol/L) are seeded from a clinic-provided target range and can be changed at any time by an administrator.

## License

MIT — see [LICENSE](LICENSE).
