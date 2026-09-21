# Bloodsuger

A self-hosted PHP app for tracking blood glucose readings: upload Contour meter CSV exports, view color-coded readings and rolling averages, export a PDF report. See `README.md` for setup/deployment steps.

## Stack & constraints

- **Plain PHP 8.1+, no framework, no build step, no Composer.** One `.php` file per page at the project root (`index.php`, `login.php`, `setup.php`, `import.php`, `settings.php`, `export_pdf.php`); shared logic in `includes/`.
- **SQLite via PDO** (`data/bloodsuger.sqlite`, gitignored, created automatically at first request by `includes/db.php`). No MySQL.
- **Deployment target: IONOS shared hosting, FTP-only (no SSH/shell access).** This is why there's no Composer — the PDF library (`vendor/tfpdf/`) was downloaded directly from its GitHub source and vendored as plain files.
- **Site is deployed in a subdirectory**, not domain root (`boneardt.co.uk/Bloodsugar`). Every internal link/redirect/asset path MUST go through `bs_url()` / `bs_asset()` (in `includes/helpers.php`), never a hardcoded leading `/`. These auto-detect the base path from `$_SERVER['SCRIPT_NAME']`, so the app also still works if moved to root or a different folder name later.
- **No local PHP or Node available in the dev environment this was built in.** Code has only been manually reviewed, never executed. Test with `php -S localhost:8000` (or on the real IONOS host) before trusting changes — don't assume correctness from review alone.

## Key decisions (the "why" isn't obvious from the code alone)

- **Threshold model is 4 boundaries (bands), not a single cutoff.** `value < A` → red (too low), `A–B` → yellow, `B–C` → green, `C–D` → yellow, `> D` → red (too high). This came from a real clinic whiteboard showing different targets for fasting (4–7) vs. post-meal (8–10) — glucose has danger at both ends, so a single red/green split isn't clinically right. Logic lives in `includes/color_classifier.php` (`bs_classify_detail()`).
- **Status is never color-only — user is colorblind.** Every status indicator (badges, chips, average-card border, PDF status column) pairs its color with a distinct icon (▼▽✓△▲) and an explicit text label (Low / Borderline low / In range / Borderline high / High), never relying on hue alone. The PDF table has a text status-code column (L/BL/OK/BH/H) alongside the color swatch for the same reason.
- **Status colors are fixed exact values, not theme-derived tints**: red `#ff0000`, yellow `#ffff00`, green `#008000`, defined once in `:root` in `assets/css/style.css` and used identically in light and dark mode. Each pairs with a `-contrast` text color chosen for readability — importantly, **pure yellow text on the page background is nearly unreadable**, so status is always shown as a solid-fill chip/badge/border with contrasting text (white on red/green, black on yellow), never as raw colored text sitting directly on the page background.
- **Login throttling is stored in SQLite (`login_attempts` table), not in-memory.** Shared hosting may route requests to different PHP worker processes, so an in-memory rate limiter wouldn't actually work.
- **Login audit trail**: every successful and failed login is written to the `login_log` table (username as typed, IP, time — never the password) and shown on the admin-only `logins.php`. Pruned to the newest 1000 rows in `bs_log_login()`. Throttled attempts aren't logged. Separate from `login_attempts`, which is only for rate limiting.
- **Setup wizard only creates the admin account.** The remaining accounts (up to `BS_MAX_USERS = 4` total, in `includes/auth.php`) are added later from the Settings page's "Accounts" section, not during first-run setup.
- **CSV import upserts on the parsed timestamp** (unique column on `measurements.timestamp`), matching the Contour export's `DD.MM.YYYY HH.MM.SS` format. Re-importing the same file is idempotent (reports "updated", not "added" again). Bad rows are skipped with a reason, never abort the whole batch — see `includes/csv_import.php`.
- **Passwords**: `password_hash()` with Argon2id if the PHP build supports it, falling back to bcrypt (cost from `config.php`). Never stored or logged in plaintext anywhere.
- **PDF fonts**: `vendor/tfpdf/` bundles DejaVu Sans TTFs specifically for correct æ/ø/å rendering (the source CSV notes are in Danish). Don't swap in a font without checking Unicode coverage.

## Live deployment — protect the data

The app is **already deployed and holds real data** at `boneardt.co.uk/Bloodsugar`. When telling the user what to upload for an update:

- **Never overwrite `data/` (contains `bloodsuger.sqlite` and possibly `-wal`/`-shm`) or the server's `config.php`.** The server's `config.php` differs from the local dev one (`cookie_secure` is `true` there).
- Safe to overwrite: root `*.php` pages, `includes/`, `assets/`, root `.htaccess`, `config.sample.php`. `vendor/` only changes if tFPDF is touched.
- Schema changes must be additive and idempotent (`CREATE TABLE IF NOT EXISTS` in `bs_migrate()` in `includes/db.php`), because it runs against the existing live database on every request. Never write a migration that drops or rewrites existing tables without an explicit backup step.
- Remind the user to download `data/bloodsuger.sqlite` before any risky update.

## Things to double check before shipping a change

- Any new page/link must use `bs_url()`/`bs_asset()` — grep for hardcoded `href="/`, `action="/`, `src="/"` or `header('Location: /` before committing.
- Any new form that mutates state needs `bs_csrf_field()` + `bs_csrf_verify()`.
- Any admin-only route needs `bs_require_admin()` (not just hiding the nav link) — read-only accounts must be blocked server-side.
