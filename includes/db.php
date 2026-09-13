<?php

function bs_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/../config.php';
        if (!is_file($path)) {
            http_response_code(500);
            die('Missing config.php — copy config.sample.php to config.php and adjust it for your environment.');
        }
        $config = require $path;
    }
    return $config;
}

function bs_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = bs_config();
    $dbPath = $config['db_path'];

    $dbDir = dirname($dbPath);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0770, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    bs_migrate($pdo);

    return $pdo;
}

function bs_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role          TEXT NOT NULL CHECK (role IN ('admin', 'readonly')),
            created_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS measurements (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp    TEXT NOT NULL UNIQUE,
            value        REAL NOT NULL,
            meal_marker  TEXT,
            data_source  TEXT,
            notes        TEXT,
            activity     TEXT,
            meal_grams   REAL,
            medication   TEXT,
            country      TEXT,
            source_file  TEXT,
            imported_at  TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
        )
        SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_measurements_timestamp ON measurements(timestamp)');

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS settings (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS login_attempts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier   TEXT NOT NULL,
            attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
        SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_identifier ON login_attempts(identifier, attempted_at)');

    $defaults = [
        // Clinically-informed defaults (fasting target 4-7, post-meal target 8-10) — admin can adjust in Settings.
        'threshold_red_low_max'  => '3.9',
        'threshold_green_min'    => '4.4',
        'threshold_green_max'    => '10.0',
        'threshold_red_high_min' => '13.0',
        'setup_complete'         => 'false',
    ];

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
    foreach ($defaults as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
}
