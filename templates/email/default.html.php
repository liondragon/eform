<?php
declare(strict_types=1);
defined('ABSPATH') || exit;
// Rendered when email.email_template="default".
?>
<p>
Form: <?= htmlspecialchars($meta['form_id'] ?? '', ENT_QUOTES) ?><br>
Instance: <?= htmlspecialchars($meta['instance_id'] ?? '', ENT_QUOTES) ?><br>
Submitted: <?= htmlspecialchars($meta['submitted_at'] ?? '', ENT_QUOTES) ?>
</p>
<table>
<?php foreach ($include_fields as $key):
    if (isset($canonical['_uploads'][$key])) {
        $names = array_column($canonical['_uploads'][$key], 'original_name_safe');
        $val = implode(', ', $names);
    } else {
        $val = $canonical[$key] ?? ($meta[$key] ?? '');
    }
?>
<tr><th><?= htmlspecialchars($key, ENT_QUOTES) ?></th><td><?= nl2br(htmlspecialchars((string)$val, ENT_QUOTES)) ?></td></tr>
<?php endforeach; ?>
</table>
