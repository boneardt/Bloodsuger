<?php

require_once __DIR__ . '/db.php';

function bs_e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * The app can be deployed at the domain root or in a subdirectory
 * (e.g. https://boneardt.co.uk/Bloodsugar). Every link, redirect, and asset
 * reference must go through bs_url()/bs_asset() rather than a hardcoded
 * leading slash, so the app keeps working wherever it's uploaded.
 */
function bs_base_path(): string
{
    static $base = null;
    if ($base === null) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($script));
        $base = rtrim($dir, '/');
        if ($base === '.' || $base === '/') {
            $base = '';
        }
    }
    return $base;
}

function bs_url(string $path): string
{
    return bs_base_path() . '/' . ltrim($path, '/');
}

function bs_asset(string $path): string
{
    return bs_url('assets/' . ltrim($path, '/'));
}

function bs_redirect(string $path): void
{
    header('Location: ' . bs_url($path));
    exit;
}

function bs_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $config = bs_config();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => bs_base_path() . '/',
        'secure'   => (bool) ($config['cookie_secure'] ?? true),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('bloodsuger_sid');
    session_start();
}

// --- Settings ---

function bs_settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $rows = bs_db()->query('SELECT key, value FROM settings')->fetchAll();
        $cache = [];
        foreach ($rows as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache;
}

function bs_get_setting(string $key, ?string $default = null): ?string
{
    return bs_settings()[$key] ?? $default;
}

function bs_set_setting(string $key, string $value): void
{
    $stmt = bs_db()->prepare(
        "INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, datetime('now'))
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at"
    );
    $stmt->execute(['key' => $key, 'value' => $value]);
}

function bs_thresholds(): array
{
    return [
        'red_low_max'  => (float) bs_get_setting('threshold_red_low_max', '3.9'),
        'green_min'    => (float) bs_get_setting('threshold_green_min', '4.4'),
        'green_max'    => (float) bs_get_setting('threshold_green_max', '10.0'),
        'red_high_min' => (float) bs_get_setting('threshold_red_high_min', '13.0'),
    ];
}

function bs_sync_token(): string
{
    return bs_get_setting('sync_token', '');
}

function bs_regenerate_sync_token(): string
{
    $token = bin2hex(random_bytes(32));
    bs_set_setting('sync_token', $token);
    return $token;
}

/**
 * Auth for sync_health.php — a non-browser client (an iOS Shortcut), so there's no
 * session/CSRF token to check. Instead it's a long shared secret sent as a header,
 * compared with hash_equals() like the CSRF check below. Exits the request on failure.
 */
function bs_require_sync_token(): void
{
    $provided = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';
    $expected = bs_sync_token();
    if ($expected === '' || !is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(401);
        header('Content-Type: text/plain');
        die('unauthorized');
    }
}

// --- Flash messages (one-time, shown on next page render) ---

function bs_flash(string $type, string $message): void
{
    bs_start_session();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function bs_take_flashes(): array
{
    bs_start_session();
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// --- CSRF protection ---

function bs_csrf_token(): string
{
    bs_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function bs_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . bs_e(bs_csrf_token()) . '">';
}

function bs_csrf_verify(): void
{
    bs_start_session();
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid or expired form submission. Please go back, refresh the page, and try again.');
    }
}

// --- Contour CSV parsing helpers ---

function bs_parse_contour_datetime(string $raw): ?string
{
    $raw = trim($raw);
    if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2})\.(\d{2})\.(\d{2})$/', $raw, $m)) {
        return null;
    }
    [, $day, $month, $year, $hour, $minute, $second] = $m;
    $day = (int) $day;
    $month = (int) $month;
    $year = (int) $year;
    $hour = (int) $hour;
    $minute = (int) $minute;
    $second = (int) $second;

    if ($hour > 23 || $minute > 59 || $second > 59) {
        return null;
    }
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
}

function bs_parse_decimal(string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $normalized = str_replace(',', '.', $raw);
    if (!is_numeric($normalized)) {
        return null;
    }
    return (float) $normalized;
}

function bs_format_dt_display(string $isoLike): string
{
    $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $isoLike);
    if (!$dt) {
        return $isoLike;
    }
    return $dt->format('d M Y, H:i');
}
