<?php
/**
 * Included by every page after auth checks have run. Expects (optionally):
 *   $pageTitle   string  <title> and top-of-page heading context
 *   $currentUser array|null  from bs_current_user(), if not already fetched by the caller
 */
$currentUser = $currentUser ?? bs_current_user();
$flashes = bs_take_flashes();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= bs_e(($pageTitle ?? 'Dashboard') . ' — Bloodsuger') ?></title>
<script>
(function () {
  try {
    var saved = localStorage.getItem('bs-theme');
    if (saved === 'light' || saved === 'dark') {
      document.documentElement.setAttribute('data-theme', saved);
    }
  } catch (e) {}
})();
</script>
<link rel="stylesheet" href="<?= bs_e(bs_asset('css/style.css')) ?>">
</head>
<body>
<header class="site-header">
  <div class="site-header__inner">
    <a class="brand" href="<?= bs_e(bs_url('index.php')) ?>">Bloodsuger</a>
    <?php if ($currentUser): ?>
    <nav class="nav">
      <a href="<?= bs_e(bs_url('index.php')) ?>">Dashboard</a>
      <?php if ($currentUser['role'] === 'admin'): ?>
        <a href="<?= bs_e(bs_url('import.php')) ?>">Import CSV</a>
        <a href="<?= bs_e(bs_url('settings.php')) ?>">Settings</a>
        <a href="<?= bs_e(bs_url('logins.php')) ?>">Logins</a>
      <?php endif; ?>
      <a href="<?= bs_e(bs_url('export_pdf.php')) ?>">Export PDF</a>
      <form action="<?= bs_e(bs_url('logout.php')) ?>" method="post" class="logout-form">
        <?= bs_csrf_field() ?>
        <button type="submit" class="link-button">Log out (<?= bs_e($currentUser['username']) ?>)</button>
      </form>
    </nav>
    <?php endif; ?>
    <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle light and dark mode" title="Toggle light and dark mode">
      <span aria-hidden="true">&#9680;</span>
    </button>
  </div>
</header>
<main class="container">
  <?php foreach ($flashes as $flash): ?>
    <div class="flash flash--<?= bs_e($flash['type']) ?>"><?= bs_e($flash['message']) ?></div>
  <?php endforeach; ?>
