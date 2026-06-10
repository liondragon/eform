<?php
declare(strict_types=1);
defined('ABSPATH') || exit;
// Rendered when email.email_template="default".
?>
<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
<?php foreach ($display_rows as $row):
    $label = isset($row['label']) ? (string) $row['label'] : '';
    $val = isset($row['value']) ? (string) $row['value'] : '';
    $type = isset($row['type']) ? (string) $row['type'] : 'text';
?>
<tr>
  <th scope="row" style="font-weight:bold;text-align:left;vertical-align:top;padding:0 28px 4px 0;"><?= htmlspecialchars($label, ENT_QUOTES) ?>:</th>
  <td style="vertical-align:top;padding:0 0 4px 0;">
    <?php if ($type === 'email' && $val !== ''): ?>
      <a href="mailto:<?= htmlspecialchars($val, ENT_QUOTES) ?>"><?= htmlspecialchars($val, ENT_QUOTES) ?></a>
    <?php else: ?>
      <?= nl2br(htmlspecialchars($val, ENT_QUOTES)) ?>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</table>
