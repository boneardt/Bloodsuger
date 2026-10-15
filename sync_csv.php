<?php

/**
 * Webhook for an iOS Shortcut set up as a Share Sheet action: the Contour Diabetes
 * app's own "My Care → Reports → Raw Data (csv)" export is shared directly to this
 * endpoint instead of being saved and uploaded by hand through import.php. Same
 * $_FILES shape and the same bs_import_csv() as the browser upload — this gets the
 * full CSV (notes, activity, meal marker, etc.), unlike the Apple Health sync, which
 * only ever has what Contour chooses to write into HealthKit.
 *
 * Authenticated the same way as sync_health.php: a shared secret token (see
 * Settings), not a session/CSRF, since this is a non-browser client. Plain-text
 * responses only.
 */

require_once __DIR__ . '/includes/csv_import.php';

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('POST only');
}

bs_require_sync_token();

$maxBytes = (int) (bs_config()['max_upload_bytes'] ?? 5 * 1024 * 1024);
$file = $_FILES['csv'] ?? null;
// requireCsvExtension=false: iOS Shortcuts' file-type form field doesn't reliably
// keep the ".csv" extension on the filename it sends, even though the content is
// fine — see bs_validate_csv_upload()'s doc comment for the full explanation.
$error = bs_validate_csv_upload($file, $maxBytes, false);

if ($error !== null) {
    http_response_code(422);
    die($error);
}

$result = bs_import_csv($file['tmp_name'], basename($file['name']));

// Temporary diagnostics while debugging the iOS Shortcut path: the Shortcut's own
// notification display has proven unreliable when triggered from a Share Sheet
// Action Extension, so write what actually happened to a plain log file instead —
// check it via FTP rather than relying on anything shown on the phone.
$receivedBytes = filesize($file['tmp_name']);
$logLine = sprintf(
    "[%s] received=%d bytes name=%s added=%d updated=%d skipped=%d",
    date('Y-m-d H:i:s'),
    $receivedBytes,
    basename($file['name']),
    $result['added'],
    $result['updated'],
    count($result['skipped'])
);
if (!empty($result['skipped'])) {
    $first = $result['skipped'][0];
    $rawSnippet = mb_substr((string) $first['raw'], 0, 200);
    $logLine .= sprintf(' | first skip: row=%d reason=%s raw="%s"', $first['row'], $first['reason'], $rawSnippet);
}
// Also capture the first/last 300 bytes of what was actually received on disk,
// so we can see directly whether the multipart upload delivered the full file.
$rawContent = file_get_contents($file['tmp_name']);
$logLine .= sprintf(
    "\n  content start: %s\n  content end:   %s",
    addcslashes(mb_substr($rawContent, 0, 300), "\n\r"),
    addcslashes(mb_substr($rawContent, -300), "\n\r")
);
file_put_contents(__DIR__ . '/data/sync_debug.log', $logLine . "\n\n", FILE_APPEND | LOCK_EX);

echo sprintf(
    "added=%d updated=%d skipped=%d",
    $result['added'],
    $result['updated'],
    count($result['skipped'])
);
