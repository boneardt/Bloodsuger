<?php

require_once __DIR__ . '/includes/auth.php';

bs_require_setup_gate();
bs_start_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bs_csrf_verify();
    $_SESSION = [];
    session_destroy();
}

bs_redirect('login.php');
