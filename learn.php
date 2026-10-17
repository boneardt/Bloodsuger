<?php

require_once __DIR__ . '/includes/auth.php';

bs_require_setup_gate();
bs_require_login(); // informational page, open to admin and readonly alike

$pageTitle = 'Learn';
require __DIR__ . '/includes/layout_header.php';
?>

<h1>Learn about diabetes &amp; blood glucose</h1>
<p class="lede">General background on diabetes and blood glucose — not medical advice. Your own healthcare provider's guidance always takes priority, and the thresholds that actually color your readings on this site are whatever's set on the <a href="<?= bs_e(bs_url('settings.php')) ?>">Settings</a> page, not the examples below.</p>

<section class="card">
  <h2>What is blood glucose?</h2>
  <p>Blood glucose (blood sugar) is the amount of glucose carried in your blood, usually measured in mmol/L or mg/dL. The body constantly adjusts it using insulin and other hormones, and it naturally rises after meals and falls between them. In diabetes, that regulation doesn't work the way it should, so glucose can swing too high or too low without extra care — testing, medication, diet, or activity.</p>
  <p>Clinics typically set a target range rather than a single number, often with a different target before meals (fasting) than after — which is exactly why this app uses four boundaries rather than one. Your own clinic's numbers, entered in Settings, are the ones that matter for your readings.</p>
</section>

<section class="card">
  <h2>Types of diabetes</h2>
  <p><strong>Type 1</strong> is an autoimmune condition where the body stops producing insulin, usually starting in childhood or early adulthood. It always requires insulin treatment.</p>
  <p><strong>Type 2</strong> develops when the body becomes resistant to insulin or doesn't produce enough of it, often influenced by a mix of genetics, age, and lifestyle factors. It's managed with some combination of diet, activity, medication, and sometimes insulin.</p>
  <p><strong>Gestational diabetes</strong> develops during pregnancy and usually resolves after birth, though it raises the risk of Type 2 diabetes later on.</p>
</section>

<section class="card">
  <h2>Hypoglycemia &amp; hyperglycemia</h2>
  <p><strong>Hypoglycemia</strong> (low blood sugar) can cause shakiness, sweating, dizziness, or confusion, and needs prompt treatment (usually fast-acting sugar). <strong>Hyperglycemia</strong> (high blood sugar) tends to build up more slowly, with symptoms like thirst, fatigue, or frequent urination.</p>
  <p>Both ends carry real risk, which is why this app's color model (see the thresholds on <a href="<?= bs_e(bs_url('settings.php')) ?>">Settings</a>) flags readings that are too low just as clearly as readings that are too high, rather than treating "high" as the only thing to watch for.</p>
</section>

<section class="card">
  <h2>Further reading &amp; research</h2>
  <p class="hint">A short, hand-picked list — not automatically updated, so it may not reflect the very latest research at any given moment.</p>
  <ul>
    <li><a href="https://www.nhs.uk/conditions/type-2-diabetes/" target="_blank" rel="noopener">NHS — Type 2 diabetes</a> <span class="hint">general patient information</span></li>
    <li><a href="https://www.who.int/news-room/fact-sheets/detail/diabetes" target="_blank" rel="noopener">World Health Organization — Diabetes fact sheet</a> <span class="hint">global statistics and overview</span></li>
    <li><a href="https://www.diabetes.org.uk/research" target="_blank" rel="noopener">Diabetes UK — Our research</a> <span class="hint">UK charity's funded research projects</span></li>
    <li><a href="https://diabetes.org/about-diabetes" target="_blank" rel="noopener">American Diabetes Association — About diabetes</a> <span class="hint">overview of Type 1, Type 2, and gestational diabetes</span></li>
  </ul>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
