<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pdf_builder.php';

bs_require_setup_gate();
bs_require_login(); // both admin and readonly may export

bs_stream_pdf_download();
