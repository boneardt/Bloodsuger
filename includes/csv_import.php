<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Contour exports are usually UTF-8, but some regional export settings produce
 * Windows-1252. Detect and normalize to UTF-8 without requiring iconv-lite/mbstring
 * beyond what's already bundled with virtually every PHP build.
 */
function bs_decode_csv_bytes(string $raw): string
{
    if (mb_check_encoding($raw, 'UTF-8')) {
        return preg_replace('/^\xEF\xBB\xBF/', '', $raw); // strip BOM if present
    }
    $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    return $converted !== false ? $converted : $raw;
}

/**
 * Upserts one measurement by timestamp — shared by the CSV importer (one call per
 * row, inside its own transaction) and sync_health.php's Apple Health sync (one
 * call per request, no explicit transaction needed for a single row). Only
 * 'timestamp' and 'value' are required; every other field defaults to null.
 * Returns 'added' or 'updated'.
 */
function bs_upsert_measurement(array $data): string
{
    static $pdo = null;
    static $findStmt = null;
    static $insertStmt = null;
    static $updateStmt = null;

    if ($pdo === null) {
        $pdo = bs_db();
        $findStmt = $pdo->prepare('SELECT id FROM measurements WHERE timestamp = ?');
        $insertStmt = $pdo->prepare(<<<'SQL'
            INSERT INTO measurements
                (timestamp, value, meal_marker, data_source, notes, activity, meal_grams, medication, country, source_file, imported_at, updated_at)
            VALUES
                (:timestamp, :value, :meal_marker, :data_source, :notes, :activity, :meal_grams, :medication, :country, :source_file, datetime('now'), datetime('now'))
            SQL);
        $updateStmt = $pdo->prepare(<<<'SQL'
            UPDATE measurements SET
                value = :value, meal_marker = :meal_marker, data_source = :data_source, notes = :notes,
                activity = :activity, meal_grams = :meal_grams, medication = :medication, country = :country,
                source_file = :source_file, updated_at = datetime('now')
            WHERE timestamp = :timestamp
            SQL);
    }

    $data += [
        'meal_marker' => null, 'data_source' => null, 'notes' => null, 'activity' => null,
        'meal_grams' => null, 'medication' => null, 'country' => null, 'source_file' => null,
    ];

    $findStmt->execute([$data['timestamp']]);
    if ($findStmt->fetchColumn()) {
        $updateStmt->execute($data);
        return 'updated';
    }
    $insertStmt->execute($data);
    return 'added';
}

/**
 * Shared upload validation for both the admin browser form (import.php) and the
 * token-authenticated Shortcut endpoint (sync_csv.php) — same $_FILES shape either
 * way, just a different auth model in front of it. Returns an error message, or
 * null if the upload is valid and ready for bs_import_csv().
 *
 * $requireCsvExtension is on by default (a friendly guardrail for the manual
 * browser form, catching an obviously-wrong file before it even reaches the
 * parser) but is turned off for sync_csv.php: iOS Shortcuts' "Get Contents of
 * URL" file-type form field doesn't reliably preserve the original ".csv"
 * extension on the multipart filename it sends, even though the file content
 * itself is fine — and bs_import_csv() already validates actual CSV content
 * (required columns) regardless of filename, so the extension check is only
 * ever a nice-to-have, not a real safety check.
 */
function bs_validate_csv_upload(?array $file, int $maxBytes, bool $requireCsvExtension = true): ?string
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Please choose a CSV file to upload.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed (error code ' . (int) $file['error'] . '). Please try again.';
    }
    if ($file['size'] > $maxBytes) {
        return 'That file is too large (max ' . round($maxBytes / 1024 / 1024, 1) . ' MB).';
    }
    if ($requireCsvExtension && !preg_match('/\.csv$/i', $file['name'])) {
        return 'Please upload a .csv file.';
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return 'Upload failed. Please try again.';
    }
    return null;
}

/**
 * Parses and upserts a Contour CSV export. Never aborts the whole batch on a bad
 * row — invalid rows are skipped with a reason, valid rows before and after are
 * still imported. Returns ['added' => int, 'updated' => int, 'skipped' => array].
 */
function bs_import_csv(string $filePath, string $originalFilename): array
{
    $raw = file_get_contents($filePath);
    if ($raw === false || $raw === '') {
        return ['added' => 0, 'updated' => 0, 'skipped' => [['row' => 0, 'reason' => 'Could not read the uploaded file', 'raw' => '']]];
    }

    $text = bs_decode_csv_bytes($raw);
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    $handle = fopen('php://temp', 'r+b');
    fwrite($handle, $text);
    rewind($handle);

    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if ($header === false || $header === [null]) {
        fclose($handle);
        return ['added' => 0, 'updated' => 0, 'skipped' => [['row' => 0, 'reason' => 'Empty or unreadable CSV file', 'raw' => '']]];
    }
    $header = array_map('trim', $header);
    $colIndex = array_flip($header);

    foreach (['Dato og Tid', 'BGValue[mmol/L]'] as $requiredCol) {
        if (!isset($colIndex[$requiredCol])) {
            fclose($handle);
            return [
                'added' => 0, 'updated' => 0,
                'skipped' => [['row' => 0, 'reason' => "Missing expected column \"$requiredCol\" — is this a Contour export?", 'raw' => '']],
            ];
        }
    }

    $get = function (array $row) use ($colIndex): callable {
        return function (string $col) use ($row, $colIndex): string {
            $i = $colIndex[$col] ?? null;
            return $i !== null && isset($row[$i]) ? trim((string) $row[$i]) : '';
        };
    };

    $validRows = [];
    $skipped = [];
    $rowNum = 1; // header consumed row 1

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $rowNum++;
        if (count($row) === 1 && trim((string) ($row[0] ?? '')) === '') {
            continue; // blank line
        }
        $field = $get($row);

        $rawTimestamp = $field('Dato og Tid');
        $timestamp = bs_parse_contour_datetime($rawTimestamp);
        if ($timestamp === null) {
            $skipped[] = ['row' => $rowNum, 'reason' => 'Unparseable date/time', 'raw' => $rawTimestamp];
            continue;
        }

        $rawValue = $field('BGValue[mmol/L]');
        $value = bs_parse_decimal($rawValue);
        if ($value === null) {
            $skipped[] = ['row' => $rowNum, 'reason' => 'Non-numeric BG value', 'raw' => $rawValue];
            continue;
        }

        $mealGramsRaw = $field('Måltid[g]');
        $mealGrams = $mealGramsRaw === '' ? null : bs_parse_decimal($mealGramsRaw);

        $notes = str_replace('\\n', "\n", $field('Notater'));

        $validRows[] = [
            'timestamp'   => $timestamp,
            'value'       => $value,
            'meal_marker' => $field('Måltidsmarkør') ?: null,
            'data_source' => $field('Datakilde') ?: null,
            'notes'       => $notes !== '' ? $notes : null,
            'activity'    => $field('Aktivitet') ?: null,
            'meal_grams'  => $mealGrams,
            'medication'  => $field('Medicin') ?: null,
            'country'     => $field('Land') ?: null,
            'source_file' => $originalFilename,
        ];
    }
    fclose($handle);

    $pdo = bs_db();
    $added = 0;
    $updated = 0;

    $pdo->beginTransaction();
    try {
        foreach ($validRows as $data) {
            if (bs_upsert_measurement($data) === 'added') {
                $added++;
            } else {
                $updated++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
}
