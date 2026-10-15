<?php
/**
 * Copy this file to config.php and adjust the values below for your environment.
 * config.php is gitignored — never commit real secrets or this file with real values.
 */

return [
    // Absolute path to the SQLite database file. Must be writable by PHP.
    // Keep it outside the public webroot if your hosting allows it; otherwise the
    // included .htaccess in data/ blocks direct web access to it.
    'db_path' => __DIR__ . '/data/bloodsuger.sqlite',

    // Require HTTPS for session cookies. Set to true once your site is served over
    // HTTPS (most hosts offer free SSL, e.g. via Let's Encrypt — enable it first).
    // Leave false only for local testing over plain http://localhost.
    'cookie_secure' => true,

    // bcrypt cost factor, used only when Argon2id isn't available on this PHP build.
    'bcrypt_cost' => 12,

    // Maximum accepted CSV upload size, in bytes.
    'max_upload_bytes' => 5 * 1024 * 1024,

    // PHP timezone identifier (see https://www.php.net/timezones) for your own local
    // time. All stored timestamps are naive "local wall-clock" values (no timezone
    // offset), so this must be set correctly for rolling averages and the Apple
    // Health sync to use the right day boundaries and times.
    'timezone' => 'Europe/Copenhagen',
];
