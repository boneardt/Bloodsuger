<?php

require_once __DIR__ . '/includes/auth.php';

bs_require_setup_gate();
$currentUser = bs_require_admin();

$thresholds = bs_thresholds();
$thresholdErrors = [];
$userErrors = [];
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
            $userErrors[] = 'All ' . BS_MAX_USERS . ' accounts are already used.';
        }
        if ($newUsernameValue === '') {
            $userErrors[] = 'Username is required.';
        } elseif (bs_find_user_by_username($newUsernameValue)) {
            $userErrors[] = 'That username is already taken.';
        }
        if (strlen($newPassword) < 8) {
            $userErrors[] = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $newConfirm) {
            $userErrors[] = 'Password and confirmation do not match.';
        }

        if (empty($userErrors)) {
            bs_create_user($newUsernameValue, $newPassword, $newRoleValue);
            bs_flash('success', 'Account "' . $newUsernameValue . '" created.');
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

  <table class="user-list">
    <thead><tr><th>Username</th><th>Role</th><th>Created</th></tr></thead>
    <tbody>
      <?php foreach ($users as $user): ?>
        <tr>
          <td><?= bs_e($user['username']) ?><?= $user['id'] === $currentUser['id'] ? ' (you)' : '' ?></td>
          <td><span class="role-badge role-badge--<?= bs_e($user['role']) ?>"><?= $user['role'] === 'admin' ? 'Administrator' : 'Read-only' ?></span></td>
          <td><?= bs_e($user['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($canAddUser): ?>
    <h3>Add an account</h3>

    <?php if ($userErrors): ?>
      <div class="flash flash--error">
        <ul>
          <?php foreach ($userErrors as $error): ?><li><?= bs_e($error) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

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
