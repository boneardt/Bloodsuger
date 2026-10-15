<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csv_import.php';

bs_require_setup_gate();
$currentUser = bs_require_admin();

$result = null;
$formError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bs_csrf_verify();

    $maxBytes = (int) (bs_config()['max_upload_bytes'] ?? 5 * 1024 * 1024);
    $file = $_FILES['csv'] ?? null;
    $formError = bs_validate_csv_upload($file, $maxBytes);

    if ($formError === null) {
        $result = bs_import_csv($file['tmp_name'], basename($file['name']));
    }
}

$pageTitle = 'Import CSV';
require __DIR__ . '/includes/layout_header.php';
?>

<h1>Import CSV</h1>
<p class="lede">Upload a Contour glucose meter export. Rows are matched by date/time — importing the same file again updates existing readings instead of duplicating them.</p>

<?php if ($formError): ?>
  <div class="flash flash--error"><?= bs_e($formError) ?></div>
<?php endif; ?>

<?php if ($result): ?>
  <div class="card import-result">
    <p class="import-result__summary">
      <strong><?= (int) $result['added'] ?></strong> added,
      <strong><?= (int) $result['updated'] ?></strong> updated,
      <strong><?= count($result['skipped']) ?></strong> skipped
    </p>
    <?php if (!empty($result['skipped'])): ?>
      <details class="import-result__skipped">
        <summary><?= count($result['skipped']) ?> row(s) skipped — click to see why</summary>
        <table>
          <thead><tr><th>Row</th><th>Reason</th><th>Raw value</th></tr></thead>
          <tbody>
            <?php foreach ($result['skipped'] as $skip): ?>
              <tr>
                <td><?= (int) $skip['row'] ?></td>
                <td><?= bs_e($skip['reason']) ?></td>
                <td><code><?= bs_e($skip['raw']) ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card">
  <?= bs_csrf_field() ?>
  <label>
    CSV file
    <input type="file" name="csv" accept=".csv,text/csv" required>
  </label>
  <button type="submit" class="button button--primary">Upload &amp; import</button>
</form>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
