<?php

require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Access denied';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
  <h1>Access denied</h1>
  <p>Your account doesn't have permission to view this page. Read-only accounts can view and search measurements and export the PDF report, but can't import data or change settings.</p>
  <p><a href="<?= bs_e(bs_url('index.php')) ?>">Back to dashboard</a></p>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
