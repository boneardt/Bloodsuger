# Bloodsuger

For keeping track of blood sugar measurements. A small self-hosted PHP app: upload Contour glucose-meter CSV exports, see readings colored by clinical target range, track rolling averages, and export a PDF report. Plain PHP + SQLite — no build step, no Composer, deployable by FTP.

## Requirements

- PHP 8.1+ with `pdo_sqlite`, `mbstring`, and `gd` or no special extension beyond the defaults (all of these ship enabled on IONOS PHP hosting by default).
- Apache with `.htaccess` support (`AllowOverride`) — used to block direct web access to `data/`, `includes/`, and `vendor/`.

## Local testing

There's no local PHP install available in the environment this was built in, so it hasn't been run yet — test it with PHP's built-in server before relying on it:

```
php -S localhost:8000
```

Then visit `http://localhost:8000/` — it should redirect to the first-run setup wizard. `config.php` (already present, gitignored) has `cookie_secure` set to `false` so login works over plain `http://localhost` during testing.

## Deploying to IONOS (or any shared PHP host)

1. Upload everything **except** `config.php` (it's gitignored — you'll create it directly on the server) via FTP to the target folder, e.g. `Bloodsugar/` under your webspace, so the site is reachable at `https://boneardt.co.uk/Bloodsugar`.
2. On the server, copy `config.sample.php` to `config.php` and set `cookie_secure` to `true` once your domain has HTTPS enabled (IONOS provides free SSL in the hosting control panel — turn it on first).
3. Make sure the `data/` folder is writable by PHP (it holds the SQLite database, created automatically on first request). Most shared hosts default new folders to writable; if you get a "database file could not be opened" error, adjust permissions to `755`/`775` via your FTP client or File Manager.
4. Visit the site — you'll land on the setup wizard automatically since no accounts exist yet. Create the administrator account there. The wizard permanently disappears once this is done.
5. Log in and, from **Settings**, add the remaining accounts (up to 4 total, mixing administrator and read-only as needed). Import your first CSV from **Import CSV**.

The app auto-detects whatever subdirectory it's deployed in (root or `/Bloodsugar` or anything else) — no path configuration needed beyond where you upload the files.

## Project layout

- `index.php`, `login.php`, `logout.php`, `setup.php`, `import.php`, `settings.php`, `export_pdf.php`, `forbidden.php` — one file per page, plain PHP.
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
