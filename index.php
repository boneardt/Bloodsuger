<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/aggregates.php';
require_once __DIR__ . '/includes/color_classifier.php';

bs_require_setup_gate();
$currentUser = bs_require_login();

$thresholds = bs_thresholds();
$averages = bs_all_averages();

$rows = bs_db()->query('SELECT * FROM measurements ORDER BY timestamp DESC')->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/layout_header.php';
?>

<h1>Dashboard</h1>

<section class="averages" aria-label="Rolling averages">
  <?php foreach ([
      ['d7', '7-day average'],
      ['d14', '14-day average'],
      ['d30', '30-day average'],
      ['all', 'All-time average'],
  ] as [$avgKey, $avgLabel]):
      $avg = $averages[$avgKey];
      // The average itself is judged against the same thresholds as individual
      // readings, so you can tell at a glance whether the trend — not just a
      // single reading — is good or bad.
      $avgDetail = $avg['avg'] !== null ? bs_classify_detail($avg['avg'], $thresholds) : null;
  ?>
    <div class="average-card<?= $avgDetail ? ' average-card--' . bs_e($avgDetail['color']) : '' ?>">
      <span class="average-card__label"><?= bs_e($avgLabel) ?></span>
      <span class="average-card__value"><?= $avg['avg'] !== null ? bs_e(number_format($avg['avg'], 1)) : '—' ?></span>
      <span class="average-card__n"><?= $avg['n'] ?> reading<?= $avg['n'] === 1 ? '' : 's' ?></span>
      <?php if ($avgDetail): ?>
        <span class="badge badge--<?= bs_e($avgDetail['color']) ?> average-card__status">
          <span class="badge__icon" aria-hidden="true"><?= bs_e($avgDetail['icon']) ?></span>
          <span class="badge__label"><?= bs_e($avgDetail['label']) ?></span>
        </span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="filters" aria-label="Filter measurements">
  <div class="filters__row">
    <label class="filter-field">
      From
      <input type="date" id="filter-from">
    </label>
    <label class="filter-field">
      To
      <input type="date" id="filter-to">
    </label>
    <label class="filter-field filter-field--grow">
      Search notes
      <input type="search" id="filter-search" placeholder="e.g. cykling, æg…">
    </label>
  </div>
  <div class="filters__chips" role="group" aria-label="Filter by status">
    <label class="chip chip--green"><input type="checkbox" class="filter-color" value="green" checked> <span aria-hidden="true">✓</span> In range</label>
    <label class="chip chip--yellow"><input type="checkbox" class="filter-color" value="yellow" checked> <span aria-hidden="true">△</span> Borderline</label>
    <label class="chip chip--red"><input type="checkbox" class="filter-color" value="red" checked> <span aria-hidden="true">▲</span> Out of range</label>
    <button type="button" id="filter-clear" class="button button--ghost">Clear filters</button>
  </div>
  <p class="filter-count"><span id="filter-count-visible"><?= count($rows) ?></span> of <?= count($rows) ?> measurements shown</p>
</section>

<section class="measurements" aria-label="Measurements">
  <?php if (empty($rows)): ?>
    <p class="empty-state">No measurements yet. <?php if ($currentUser['role'] === 'admin'): ?><a href="<?= bs_e(bs_url('import.php')) ?>">Import a CSV</a> to get started.<?php else: ?>Ask an administrator to import a CSV.<?php endif; ?></p>
  <?php else: ?>
    <?php foreach ($rows as $row):
        $detail = bs_classify_detail((float) $row['value'], $thresholds);
        $color = $detail['color'];
        $hasNotes = !empty($row['notes']);
        $hasActivity = !empty($row['activity']);
        $hasLocation = !empty($row['country']);
        $detailFields = [
            'Meal marker' => $row['meal_marker'],
            'Activity'    => $row['activity'],
            'Meal (g)'    => $row['meal_grams'] !== null ? number_format((float) $row['meal_grams'], 0) : null,
            'Medication'  => $row['medication'],
            'Country'     => $row['country'],
            'Data source' => $row['data_source'],
        ];
    ?>
      <div class="measurement-row"
           data-timestamp="<?= bs_e($row['timestamp']) ?>"
           data-value="<?= bs_e((string) $row['value']) ?>"
           data-color="<?= bs_e($color) ?>"
           data-notes="<?= bs_e(mb_strtolower($row['notes'] ?? '', 'UTF-8')) ?>">
        <button type="button" class="measurement-row__summary">
          <span class="measurement-row__date"><?= bs_e(bs_format_dt_display($row['timestamp'])) ?></span>
          <?php if ($hasActivity): ?>
            <span class="row-icon" data-tooltip="<?= bs_e($row['activity']) ?>" aria-hidden="true">&#127939;</span>
          <?php endif; ?>
          <?php if ($hasLocation): ?>
            <span class="row-icon" data-tooltip="<?= bs_e($row['country']) ?>" aria-hidden="true">&#128205;</span>
          <?php endif; ?>
          <?php if ($hasNotes): ?>
            <span class="row-icon" data-tooltip="<?= bs_e($row['notes']) ?>" aria-hidden="true">&#128172;</span>
          <?php endif; ?>
          <span class="measurement-row__value badge badge--<?= bs_e($color) ?>">
            <span class="badge__icon" aria-hidden="true"><?= bs_e($detail['icon']) ?></span>
            <span class="badge__value"><?= bs_e(number_format((float) $row['value'], 1)) ?> mmol/L</span>
            <span class="badge__label"><?= bs_e($detail['label']) ?></span>
          </span>
        </button>
        <div class="measurement-row__detail">
          <?php foreach ($detailFields as $label => $value): ?>
            <?php if ($value !== null && $value !== ''): ?>
              <p><strong><?= bs_e($label) ?>:</strong> <?= bs_e($value) ?></p>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($hasNotes): ?>
            <p class="measurement-row__full-note"><strong>Notes:</strong> <?= nl2br(bs_e($row['notes'])) ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php
$pageDashboardJs = true;
require __DIR__ . '/includes/layout_footer.php';
?>
