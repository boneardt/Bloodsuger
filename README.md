# Bloodsuger

For keeping track of blood sugar measurements. A small self-hosted PHP app: upload Contour glucose-meter CSV exports, see readings colored by clinical target range, track rolling averages, and export a PDF report. Plain PHP + SQLite — no build step, no Composer, deployable by FTP.

## Requirements

- PHP 8.1+ with the `pdo_sqlite` and `mbstring` extensions. Both are enabled by default on virtually every PHP install and shared-hosting preset — nothing extra to install in the normal case.
- Apache with `.htaccess` support (`AllowOverride`) — the bundled `.htaccess` files block direct web access to `data/`, `includes/`, and `vendor/`. On Nginx (which ignores `.htaccess`), add equivalent `location` blocks denying those paths, or move them outside the web root — there's no Nginx config bundled here, so this is on you if you're not on Apache.

## Install — local

1. Make sure you have PHP 8.1+ available (`php -v`). No PHP on your machine? XAMPP, MAMP, or Laragon all bundle one and work fine too.
2. Copy `config.sample.php` to `config.php`. The defaults work for local testing — `cookie_secure` only needs to be `true` once you're serving over real HTTPS.
3. From the project folder, run:
   ```
   php -S localhost:8000
   ```
4. Visit `http://localhost:8000/` — it redirects to the first-run setup wizard automatically.

## Install — your own server / shared hosting

1. Upload everything **except** `config.php` (you'll create it directly on the server) to wherever you want the app to live — the domain root, or a subfolder (e.g. `https://your-domain.example/bloodsuger`). The app detects its own base path automatically, so no path configuration is needed either way.
2. On the server, copy `config.sample.php` to `config.php` and set `cookie_secure` to `true` once HTTPS is enabled for the domain — most hosts offer free SSL (e.g. via Let's Encrypt); turn it on before going further.
3. Make sure the `data/` folder is writable by PHP (it holds the SQLite database, created automatically on first request). Most hosts default new folders to writable; if you get a "database file could not be opened" error, adjust permissions to `755`/`775`.
4. Visit the site — you land on the setup wizard automatically since no accounts exist yet. Create the administrator account there. The wizard permanently disappears once this is done.
5. Log in and, from **Settings**, add the remaining accounts (up to 4 total, mixing administrator and read-only as needed). Import your first CSV from **Import CSV**.

If you have SSH access, `git clone` the repo directly on the server instead of uploading files one by one — everything else above still applies.

## CSV format

The importer was built and tested against a **Contour Plus Blue** meter's CSV export, with **Danish column headers**. It expects:

- `Dato og Tid` (date/time, format `DD.MM.YYYY HH.MM.SS`) and `BGValue[mmol/L]` (decimal-comma numbers, e.g. `7,2`) — **required**; the import is rejected with a clear error if either column is missing.
- `Måltidsmarkør`, `Datakilde`, `Notater`, `Aktivitet`, `Måltid[g]`, `Medicin`, `Land` — optional; a row imports fine if any of these are blank or the column is absent.

**Other languages and meters aren't supported yet.** If your export uses different column headers, the fix is to edit the literal header strings in `includes/csv_import.php` — the required-column check near the top of `bs_import_csv()`, and the `$field('...')` calls further down — to match your own CSV's headers. This is a known limitation, not a bug; contributions to make it configurable are welcome.

## Project layout

- `index.php`, `login.php`, `logout.php`, `setup.php`, `import.php`, `settings.php`, `logins.php`, `export_pdf.php`, `forbidden.php` — one file per page, plain PHP.
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
