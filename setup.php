<?php

require_once __DIR__ . '/includes/auth.php';

bs_require_setup_gate();

// Defense in depth: even if the gate above is ever bypassed, a POST here
// must never succeed once setup has already been completed.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && bs_setup_complete()) {
    http_response_code(403);
    die('Setup has already been completed.');
}

$errors = [];
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bs_csrf_verify();

    $usernameValue = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($usernameValue === '') {
        $errors[] = 'Username is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if (empty($errors)) {
        $pdo = bs_db();
        $pdo->beginTransaction();
        try {
            bs_create_user($usernameValue, $password, 'admin');
            bs_set_setting('setup_complete', 'true');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        bs_flash('success', 'Admin account created — you can now log in. Add the other accounts from Settings once you\'re in.');
        bs_redirect('login.php');
    }
}

$pageTitle = 'First-time setup';
require __DIR__ . '/includes/layout_header.php';
?>

<h1>Welcome to Bloodsuger</h1>
<p class="lede">Create the administrator account to get started. You can add up to <?= BS_MAX_USERS ?> accounts in total — the rest can be added later from Settings.</p>

<?php if ($errors): ?>
  <div class="flash flash--error">
    <ul>
      <?php foreach ($errors as $error): ?>
        <li><?= bs_e($error) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="card setup-form">
  <?= bs_csrf_field() ?>
  <fieldset class="setup-account">
    <legend>Administrator account</legend>

    <label>
      Username
      <input type="text" name="username" value="<?= bs_e($usernameValue) ?>" required autocomplete="off" autofocus>
    </label>

    <label>
      Password
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
    </label>

    <label>
      Confirm password
      <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
    </label>
  </fieldset>

  <button type="submit" class="button button--primary">Create admin account &amp; finish setup</button>
</form>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
