<?php

require_once __DIR__ . '/db.php';

/**
 * Stored timestamps are naive local wall-clock strings (not UTC), so cutoffs must be
 * computed in PHP using the same local formatting used at import time and bound as
 * parameters — never via SQLite's UTC datetime('now').
 */
function bs_local_cutoff(int $daysAgo): string
{
    $dt = new DateTime('now');
    $dt->modify("-{$daysAgo} days");
    return $dt->format('Y-m-d\TH:i:s');
}

function bs_average_window(?int $days): array
{
    $pdo = bs_db();
    if ($days === null) {
        $stmt = $pdo->query('SELECT AVG(value) AS avg_value, COUNT(*) AS n FROM measurements');
    } else {
        $stmt = $pdo->prepare('SELECT AVG(value) AS avg_value, COUNT(*) AS n FROM measurements WHERE timestamp >= ?');
        $stmt->execute([bs_local_cutoff($days)]);
    }
    $row = $stmt->fetch();
    return [
        'avg' => $row['avg_value'] !== null ? round((float) $row['avg_value'], 1) : null,
        'n'   => (int) $row['n'],
    ];
}

function bs_all_averages(): array
{
    return [
        'd7'  => bs_average_window(7),
        'd14' => bs_average_window(14),
        'd30' => bs_average_window(30),
        'all' => bs_average_window(null),
    ];
}
