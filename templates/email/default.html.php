<?php
declare(strict_types=1);
defined('ABSPATH') || exit;
// Rendered when email.email_template="default".
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>eForms submission</title>
</head>
<body>
<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
<?php foreach ($display_rows as $row):
    $label = isset($row['label']) ? (string) $row['label'] : '';
    $val = isset($row['value']) ? (string) $row['value'] : '';
    $type = isset($row['type']) ? (string) $row['type'] : 'text';
    $url = isset($row['url']) ? (string) $row['url'] : '';
    $expires = isset($row['expires_label']) ? (string) $row['expires_label'] : '';
?>
<tr>
  <th scope="row" style="font-weight:bold;text-align:left;vertical-align:top;padding:0 28px 4px 0;"><?= htmlspecialchars($label, ENT_QUOTES) ?>:</th>
  <td style="vertical-align:top;padding:0 0 4px 0;">
    <?php if ($type === 'email' && $val !== ''): ?>
      <a href="mailto:<?= htmlspecialchars($val, ENT_QUOTES) ?>"><?= htmlspecialchars($val, ENT_QUOTES) ?></a>
    <?php elseif ($type === 'gallery' && $url !== ''): ?>
      <?= htmlspecialchars($val, ENT_QUOTES) ?><br>
      <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>">Review photos</a><br>
      <small>Available until <?= htmlspecialchars($expires, ENT_QUOTES) ?></small>
    <?php else: ?>
      <?= nl2br(htmlspecialchars($val, ENT_QUOTES)) ?>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</table>
</body>
</html>
