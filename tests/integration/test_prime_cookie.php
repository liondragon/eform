<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

global $TEST_QUERY_VARS;
$TEST_QUERY_VARS['eforms_prime'] = 1;
// Snapshot and restore query parameters
$getSnapshot = $_GET;
$_GET['f'] = 'contact_us';

do_action('template_redirect');

$_GET = $getSnapshot;
