<?php

/**
 * Webhook for an iOS Shortcut: reads Blood Glucose samples from Apple Health
 * (already populated by the Contour Diabetes app) and POSTs them here one at a
 * time. Authenticated by a long shared-secret token (see Settings), not a
 * session/CSRF — this is a non-browser client. Plain-text responses only, since
 * building/parsing JSON inside iOS Shortcuts is fragile.
 */

require_once __DIR__ . '/includes/csv_import.php';

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('POST only');
}

bs_require_sync_token();

$rawTimestamp = (string) ($_POST['timestamp'] ?? '');
$rawValue = (string) ($_POST['value'] ?? '');

if ($rawTimestamp === '' || $rawValue === '') {
    http_response_code(422);
    die('missing timestamp or value');
}

try {
    $incoming = new DateTime($rawTimestamp);
} catch (Throwable $e) {
    http_response_code(422);
    die('unparseable timestamp — expected ISO 8601, e.g. 2026-10-14T08:30:00+02:00');
}
$incoming->setTimezone(new DateTimeZone(date_default_timezone_get()));
$timestamp = $incoming->format('Y-m-d\TH:i:s');

$value = bs_parse_decimal($rawValue);
if ($value === null || $value < 1.0 || $value > 40.0) {
    http_response_code(422);
    die('value must be a number between 1 and 40 (mmol/L) — check your Health app\'s Blood Glucose unit is mmol/L, not mg/dL');
}

$result = bs_upsert_measurement([
    'timestamp'   => $timestamp,
    'value'       => $value,
    'data_source' => 'Apple Health',
]);

echo $result; // "added" or "updated"
