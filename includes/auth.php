<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const BS_LOGIN_MAX_ATTEMPTS = 10;
const BS_LOGIN_WINDOW_MINUTES = 15;

function bs_count_users(): int
{
    return (int) bs_db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function bs_setup_complete(): bool
{
    return bs_get_setting('setup_complete') === 'true';
}

function bs_find_user_by_username(string $username): ?array
{
    $stmt = bs_db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/** All accounts, for the user-management list on the Settings page. */
function bs_all_users(): array
{
    return bs_db()->query('SELECT id, username, role, created_at FROM users ORDER BY id')->fetchAll();
}

function bs_find_user_by_id(int $id): ?array
{
    $stmt = bs_db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function bs_count_admins(): int
{
    return (int) bs_db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

function bs_update_password(int $userId, string $password): void
{
    $stmt = bs_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([bs_hash_password($password), $userId]);
}

function bs_delete_user(int $userId): void
{
    $stmt = bs_db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$userId]);
}

const BS_MAX_USERS = 4;

function bs_hash_password(string $password): string
{
    $algo = in_array('argon2id', password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    $options = $algo === PASSWORD_BCRYPT ? ['cost' => (int) (bs_config()['bcrypt_cost'] ?? 12)] : [];
    return password_hash($password, $algo, $options);
}

function bs_create_user(string $username, string $password, string $role): void
{
    $stmt = bs_db()->prepare(
        'INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)'
    );
    $stmt->execute([
        'username'      => $username,
        'password_hash' => bs_hash_password($password),
        'role'          => $role,
    ]);
}

function bs_current_user(): ?array
{
    bs_start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = bs_db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        return null;
    }
    return $user;
}

/**
 * Must run at the top of every page. Redirects to /setup.php while no
 * accounts exist yet, and makes /setup.php unreachable once they do.
 */
function bs_require_setup_gate(): void
{
    bs_start_session();
    $complete = bs_setup_complete();
    $script = basename($_SERVER['SCRIPT_NAME']);

    if (!$complete && $script !== 'setup.php') {
        bs_redirect('setup.php');
    }
    if ($complete && $script === 'setup.php' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        bs_redirect('login.php');
    }
}

function bs_require_login(): array
{
    bs_start_session();
    $user = bs_current_user();
    if (!$user) {
        bs_redirect('login.php');
    }
    return $user;
}

function bs_require_admin(): array
{
    $user = bs_require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        require __DIR__ . '/../forbidden.php';
        exit;
    }
    return $user;
}

// --- Login throttling, backed by SQLite (not in-memory — each request may hit
// a different PHP worker process on shared hosting, so in-memory state won't persist). ---

function bs_login_throttled(string $identifier): bool
{
    $stmt = bs_db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE identifier = ? AND attempted_at >= datetime('now', '-" . BS_LOGIN_WINDOW_MINUTES . " minutes')"
    );
    $stmt->execute([$identifier]);
    return ((int) $stmt->fetchColumn()) >= BS_LOGIN_MAX_ATTEMPTS;
}

function bs_record_login_attempt(string $identifier): void
{
    $stmt = bs_db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?)');
    $stmt->execute([$identifier]);
}

function bs_clear_login_attempts(string $identifier): void
{
    $stmt = bs_db()->prepare('DELETE FROM login_attempts WHERE identifier = ?');
    $stmt->execute([$identifier]);
}

const BS_LOGIN_LOG_KEEP = 1000;

/** Audit trail shown to admins on logins.php. Never stores passwords. */
function bs_log_login(string $username, bool $success): void
{
    $pdo = bs_db();
    $stmt = $pdo->prepare('INSERT INTO login_log (username, ip, success, logged_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        mb_substr($username, 0, 100),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $success ? 1 : 0,
        date('Y-m-d H:i:s'),
    ]);
    // Cap the table so a bot hammering the login form can't grow the database forever.
    $pdo->exec('DELETE FROM login_log WHERE id <= (SELECT MAX(id) FROM login_log) - ' . BS_LOGIN_LOG_KEEP);
}

function bs_recent_logins(int $limit = 200): array
{
    $stmt = bs_db()->prepare('SELECT username, ip, success, logged_at FROM login_log ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function bs_login_identifier(string $username): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return $ip . '|' . strtolower($username);
}
