<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

echo 'Form: ' . ($meta['form_id'] ?? '') . "\n";
echo 'Instance: ' . ($meta['instance_id'] ?? '') . "\n";
echo 'Submitted: ' . ($meta['submitted_at'] ?? '') . "\n\n";
foreach ($include_fields as $key) {
    $val = $canonical[$key] ?? ($meta[$key] ?? '');
    echo $key . ': ' . $val . "\n";
}
