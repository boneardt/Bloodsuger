<?php

require_once __DIR__ . '/includes/auth.php';

bs_require_setup_gate();
$currentUser = bs_require_admin();

$logins = bs_recent_logins(200);

$pageTitle = 'Logins';
require __DIR__ . '/includes/layout_header.php';
?>

<h1>Logins</h1>
<p class="lede">The latest 200 login attempts, newest first — successful and failed. Only administrators can see this page.</p>

<section class="card">
  <?php if (empty($logins)): ?>
    <p class="empty-state">No logins recorded yet.</p>
  <?php else: ?>
    <div class="table-scroll">
      <table class="user-list">
        <thead><tr><th>Time</th><th>Username</th><th>Result</th><th>IP address</th></tr></thead>
        <tbody>
          <?php foreach ($logins as $login): ?>
            <tr>
              <td><?= bs_e($login['logged_at']) ?></td>
              <td><?= bs_e($login['username']) ?></td>
              <td>
                <?php if ((int) $login['success'] === 1): ?>
                  <span class="badge badge--green"><span class="badge__icon" aria-hidden="true">✓</span> Success</span>
                <?php else: ?>
                  <span class="badge badge--red"><span class="badge__icon" aria-hidden="true">✕</span> Failed</span>
                <?php endif; ?>
              </td>
              <td><?= bs_e($login['ip']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
