<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

global $TEST_QUERY_VARS;
$TEST_QUERY_VARS['eforms_submit'] = 1;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=';

ob_start();
do_action('template_redirect');
$out = ob_get_clean();
file_put_contents(__DIR__ . '/tmp/out_boundary_empty.txt', $out);
