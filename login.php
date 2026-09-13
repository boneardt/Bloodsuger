<?php

require_once __DIR__ . '/includes/auth.php';

bs_require_setup_gate();

if (bs_current_user()) {
    bs_redirect('index.php');
}

$error = null;
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bs_csrf_verify();

    $usernameValue = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $identifier = bs_login_identifier($usernameValue);

    if (bs_login_throttled($identifier)) {
        $error = 'Too many login attempts. Please wait 15 minutes and try again.';
    } else {
        $user = bs_find_user_by_username($usernameValue);
        if ($user && password_verify($password, $user['password_hash'])) {
            bs_clear_login_attempts($identifier);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            bs_redirect('index.php');
        } else {
            bs_record_login_attempt($identifier);
            $error = 'Invalid username or password.';
        }
    }
}

$pageTitle = 'Log in';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="auth-page">
  <h1>Log in</h1>

  <?php if ($error): ?>
    <div class="flash flash--error"><?= bs_e($error) ?></div>
  <?php endif; ?>

  <form method="post" class="card">
    <?= bs_csrf_field() ?>
    <label>
      Username
      <input type="text" name="username" value="<?= bs_e($usernameValue) ?>" required autofocus>
    </label>
    <label>
      Password
      <input type="password" name="password" required>
    </label>
    <button type="submit" class="button button--primary">Log in</button>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
