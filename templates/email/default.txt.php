<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

// Rendered when email.email_template="default".
echo 'Form: ' . ($meta['form_id'] ?? '') . "\n";
echo 'Submission: ' . ($meta['submission_id'] ?? '') . "\n";
if (!empty($meta['slot'] ?? null)) {
    echo 'Slot: ' . ($meta['slot'] ?? '') . "\n";
}
echo 'Submitted: ' . ($meta['submitted_at'] ?? '') . "\n\n";
foreach ($include_fields as $key) {
    if (isset($canonical['_uploads'][$key])) {
        $names = array_column($canonical['_uploads'][$key], 'original_name_safe');
        $val = implode(', ', $names);
    } else {
        $val = $canonical[$key] ?? ($meta[$key] ?? '');
    }
    echo $key . ': ' . $val . "\n";
}
