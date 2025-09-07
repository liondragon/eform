<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

global $TEST_QUERY_VARS;
$TEST_QUERY_VARS['eforms_submit'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';

// Trigger the router
ob_start();
do_action('template_redirect');
ob_end_clean();

