<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pdf_builder.php';

bs_require_setup_gate();
bs_require_login(); // both admin and readonly may export

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Invalid or missing from/to date (expected YYYY-MM-DD).');
}

bs_stream_chart_pdf_download($from, $to);
