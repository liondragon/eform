<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

// Rendered when email.email_template="default".
$width = 0;
foreach ($display_rows as $row) {
    $label = isset($row['label']) ? (string) $row['label'] : '';
    $width = max($width, strlen($label));
}

foreach ($display_rows as $row) {
    $label = isset($row['label']) ? (string) $row['label'] : '';
    $val = isset($row['value']) ? (string) $row['value'] : '';
    echo str_pad($label . ':', $width + 2) . $val . "\n";
    if (isset($row['type']) && $row['type'] === 'gallery') {
        $url = isset($row['url']) ? (string) $row['url'] : '';
        $available = isset($row['available_label']) ? (string) $row['available_label'] : '';
        echo str_pad('Review:', $width + 2) . $url . "\n";
        echo str_pad('Available until:', $width + 2) . $available . "\n";
    }
}
