<?php

require_once __DIR__ . '/includes/auth.php';

bs_require_setup_gate();
$currentUser = bs_require_admin();

$thresholds = bs_thresholds();
$thresholdErrors = [];
$accountErrors = [];
$newUsernameValue = '';
$newRoleValue = 'readonly';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bs_csrf_verify();
    $formName = $_POST['form_name'] ?? '';

    if ($formName === 'thresholds') {
        $a = bs_parse_decimal((string) ($_POST['red_low_max'] ?? ''));
        $b = bs_parse_decimal((string) ($_POST['green_min'] ?? ''));
        $c = bs_parse_decimal((string) ($_POST['green_max'] ?? ''));
        $d = bs_parse_decimal((string) ($_POST['red_high_min'] ?? ''));

        if ($a === null || $b === null || $c === null || $d === null) {
            $thresholdErrors[] = 'All four thresholds must be numbers.';
        } elseif (!($a < $b && $b < $c && $c < $d)) {
            $thresholdErrors[] = 'Thresholds must be strictly increasing: Red-below < Green-from < Green-to < Red-above.';
        }

        if (empty($thresholdErrors)) {
            bs_set_setting('threshold_red_low_max', (string) $a);
            bs_set_setting('threshold_green_min', (string) $b);
            bs_set_setting('threshold_green_max', (string) $c);
            bs_set_setting('threshold_red_high_min', (string) $d);
            bs_flash('success', 'Thresholds updated. Existing measurements are recolored automatically.');
            bs_redirect('settings.php');
        } else {
            // Re-render with the submitted (invalid) values instead of the stored ones.
            $thresholds = ['red_low_max' => $a, 'green_min' => $b, 'green_max' => $c, 'red_high_min' => $d];
        }
    } elseif ($formName === 'add_user') {
        $newUsernameValue = trim((string) ($_POST['new_username'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newConfirm = (string) ($_POST['new_password_confirm'] ?? '');
        $newRoleValue = ($_POST['new_role'] ?? 'readonly') === 'admin' ? 'admin' : 'readonly';

        if (bs_count_users() >= BS_MAX_USERS) {
            $accountErrors[] = 'All ' . BS_MAX_USERS . ' accounts are already used.';
        }
        if ($newUsernameValue === '') {
            $accountErrors[] = 'Username is required.';
        } elseif (bs_find_user_by_username($newUsernameValue)) {
            $accountErrors[] = 'That username is already taken.';
        }
        if (strlen($newPassword) < 8) {
            $accountErrors[] = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $newConfirm) {
            $accountErrors[] = 'Password and confirmation do not match.';
        }

        if (empty($accountErrors)) {
            bs_create_user($newUsernameValue, $newPassword, $newRoleValue);
            bs_flash('success', 'Account "' . $newUsernameValue . '" created.');
            bs_redirect('settings.php');
        }
    } elseif ($formName === 'change_password') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $target = bs_find_user_by_id($targetId);
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newConfirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!$target) {
            $accountErrors[] = 'That account no longer exists.';
        }
        if (strlen($newPassword) < 8) {
            $accountErrors[] = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $newConfirm) {
            $accountErrors[] = 'Password and confirmation do not match.';
        }

        if (empty($accountErrors)) {
            bs_update_password($targetId, $newPassword);
            bs_flash('success', 'Password updated for "' . $target['username'] . '".');
            bs_redirect('settings.php');
        }
    } elseif ($formName === 'delete_user') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $target = bs_find_user_by_id($targetId);

        if (!$target) {
            $accountErrors[] = 'That account no longer exists.';
        } elseif ($targetId === $currentUser['id']) {
            $accountErrors[] = "You can't delete the account you're currently logged in as.";
        } elseif ($target['role'] === 'admin' && bs_count_admins() <= 1) {
            $accountErrors[] = 'At least one administrator account must remain.';
        }

        if (empty($accountErrors)) {
            bs_delete_user($targetId);
            bs_flash('success', 'Account "' . $target['username'] . '" deleted.');
            bs_redirect('settings.php');
        }
    }
}

$users = bs_all_users();
$canAddUser = count($users) < BS_MAX_USERS;

$pageTitle = 'Settings';
require __DIR__ . '/includes/layout_header.php';
?>

<h1>Settings</h1>

<section class="card">
  <h2>Thresholds</h2>
  <p class="lede">These decide the color shown for every reading, on the dashboard and in the PDF export — changing them recolors all existing data immediately, nothing needs to be re-imported.</p>

  <?php if ($thresholdErrors): ?>
    <div class="flash flash--error">
      <ul>
        <?php foreach ($thresholdErrors as $error): ?><li><?= bs_e($error) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="threshold-form">
    <?= bs_csrf_field() ?>
    <input type="hidden" name="form_name" value="thresholds">

    <div class="threshold-preview" aria-hidden="true">
      <span class="threshold-preview__segment threshold-preview__segment--red">Red</span>
      <span class="threshold-preview__segment threshold-preview__segment--yellow">Yellow</span>
      <span class="threshold-preview__segment threshold-preview__segment--green">Green</span>
      <span class="threshold-preview__segment threshold-preview__segment--yellow">Yellow</span>
      <span class="threshold-preview__segment threshold-preview__segment--red">Red</span>
    </div>

    <label>
      Red below <span class="hint">(too low)</span>
      <input type="number" step="0.1" name="red_low_max" value="<?= bs_e((string) $thresholds['red_low_max']) ?>" required>
    </label>
    <label>
      Green from
      <input type="number" step="0.1" name="green_min" value="<?= bs_e((string) $thresholds['green_min']) ?>" required>
    </label>
    <label>
      Green to
      <input type="number" step="0.1" name="green_max" value="<?= bs_e((string) $thresholds['green_max']) ?>" required>
    </label>
    <label>
      Red above <span class="hint">(too high)</span>
      <input type="number" step="0.1" name="red_high_min" value="<?= bs_e((string) $thresholds['red_high_min']) ?>" required>
    </label>

    <button type="submit" class="button button--primary">Save thresholds</button>
  </form>
</section>

<section class="card">
  <h2>Accounts (<?= count($users) ?> of <?= BS_MAX_USERS ?>)</h2>

  <?php if ($accountErrors): ?>
    <div class="flash flash--error">
      <ul>
        <?php foreach ($accountErrors as $error): ?><li><?= bs_e($error) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="table-scroll">
    <table class="user-list">
      <thead><tr><th>Username</th><th>Role</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td><?= bs_e($user['username']) ?><?= $user['id'] === $currentUser['id'] ? ' (you)' : '' ?></td>
            <td><span class="role-badge role-badge--<?= bs_e($user['role']) ?>"><?= $user['role'] === 'admin' ? 'Administrator' : 'Read-only' ?></span></td>
            <td><?= bs_e($user['created_at']) ?></td>
            <td class="user-list__actions">
              <details class="inline-action">
                <summary class="button button--ghost button--small">Change password</summary>
                <form method="post" class="inline-action__form">
                  <?= bs_csrf_field() ?>
                  <input type="hidden" name="form_name" value="change_password">
                  <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                  <label>
                    New password
                    <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
                  </label>
                  <label>
                    Confirm new password
                    <input type="password" name="new_password_confirm" required minlength="8" autocomplete="new-password">
                  </label>
                  <button type="submit" class="button button--primary button--small">Update password</button>
                </form>
              </details>

              <?php if ($user['id'] !== $currentUser['id']): ?>
                <form method="post" class="inline-action__form" onsubmit="return confirm('Delete this account? This cannot be undone.');">
                  <?= bs_csrf_field() ?>
                  <input type="hidden" name="form_name" value="delete_user">
                  <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                  <button type="submit" class="button button--ghost button--small">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canAddUser): ?>
    <h3>Add an account</h3>

    <form method="post" class="add-user-form">
      <?= bs_csrf_field() ?>
      <input type="hidden" name="form_name" value="add_user">

      <label>
        Username
        <input type="text" name="new_username" value="<?= bs_e($newUsernameValue) ?>" required autocomplete="off">
      </label>
      <label>
        Password
        <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
      </label>
      <label>
        Confirm password
        <input type="password" name="new_password_confirm" required minlength="8" autocomplete="new-password">
      </label>
      <label>
        Role
        <select name="new_role">
          <option value="readonly" <?= $newRoleValue === 'readonly' ? 'selected' : '' ?>>Read-only</option>
          <option value="admin" <?= $newRoleValue === 'admin' ? 'selected' : '' ?>>Administrator</option>
        </select>
      </label>

      <button type="submit" class="button button--primary">Create account</button>
    </form>
  <?php else: ?>
    <p class="hint">All <?= BS_MAX_USERS ?> accounts are in use.</p>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
