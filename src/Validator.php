<?php
declare(strict_types=1);

namespace EForms;

class Validator
{
    private static function isMultivalue(array $f): bool
    {
        $type = $f['type'] ?? '';
        if ($type === 'checkbox') return true;
        if ($type === 'select' && !empty($f['multiple'])) return true;
        return false;
    }
    public static function descriptors(array $tpl): array
    {
        $desc = [];
        foreach ($tpl['fields'] as $f) {
            if (($f['type'] ?? '') === 'row_group') {
                continue;
            }
            $desc[$f['key']] = $f;
        }
        return $desc;
    }

    public static function normalize(array $tpl, array $post): array
    {
        $values = [];
        foreach ($tpl['fields'] as $f) {
            if (($f['type'] ?? '') === 'row_group') continue;
            $k = $f['key'];
            if (self::isMultivalue($f)) {
                $raw = $post[$k] ?? [];
                if (!is_array($raw)) {
                    $raw = [];
                }
                $vals = [];
                foreach ($raw as $rv) {
                    if (is_scalar($rv)) {
                        $sv = function_exists('\\wp_unslash') ? \wp_unslash($rv) : stripslashes((string)$rv);
                        $vals[] = trim((string)$sv);
                    }
                }
                $values[$k] = $vals;
            } else {
                $v = $post[$k] ?? '';
                if (is_array($v)) {
                    $v = '';
                }
                $sv = function_exists('\\wp_unslash') ? \wp_unslash($v) : stripslashes((string)$v);
                $values[$k] = trim((string)$sv);
            }
        }
        return $values;
    }

    public static function validate(array $tpl, array $desc, array $values): array
    {
        $errors = [];
        $canonical = [];
        foreach ($desc as $k => $f) {
            $v = $values[$k] ?? (self::isMultivalue($f) ? [] : '');
            if (self::isMultivalue($f)) {
                if (!empty($f['required']) && count($v) === 0) {
                    $errors[$k][] = 'This field is required.';
                }
                $max = (int) Config::get('validation.max_items_per_multivalue', 50);
                if (count($v) > $max) {
                    $errors[$k][] = 'Too many selections.';
                }
                $opts = array_column($f['options'] ?? [], 'key');
                $enabled = [];
                foreach ($f['options'] ?? [] as $opt) {
                    if (empty($opt['disabled'])) {
                        $enabled[] = $opt['key'];
                    }
                }
                $clean = [];
                foreach ($v as $vv) {
                    if (in_array($vv, $enabled, true) && !in_array($vv, $clean, true)) {
                        $clean[] = $vv;
                    } elseif (!in_array($vv, $enabled, true)) {
                        $errors[$k][] = 'Invalid choice.';
                        break;
                    }
                }
                $canonical[$k] = $clean;
                continue;
            }
            if (!empty($f['required']) && $v === '') {
                $errors[$k][] = 'This field is required.';
            }
            if ($v !== '') {
                if (isset($f['max_length']) && strlen($v) > $f['max_length']) {
                    $errors[$k][] = 'Too long.';
                }
                if (isset($f['pattern']) && @preg_match('#^'.$f['pattern'].'$#', $v) !== 1) {
                    $errors[$k][] = 'Invalid format.';
                }
                switch ($f['type']) {
                    case 'email':
                        if (!\is_email($v)) {
                            $errors[$k][] = 'Invalid email.';
                        }
                        break;
                    case 'url':
                        $url = filter_var($v, FILTER_VALIDATE_URL);
                        $scheme = strtolower(parse_url((string)$url, PHP_URL_SCHEME));
                        if (!$url || !in_array($scheme, ['http','https'], true)) {
                            $errors[$k][] = 'Invalid URL.';
                        }
                        break;
                    case 'zip_us':
                        if (!preg_match('/^\d{5}$/', $v)) {
                            $errors[$k][] = 'Invalid ZIP.';
                        }
                        break;
                    case 'tel_us':
                        $digits = preg_replace('/\D+/', '', $v);
                        if (strlen($digits) < 10) {
                            $errors[$k][] = 'Invalid phone.';
                        }
                        break;
                    case 'number':
                    case 'range':
                        if (!is_numeric($v)) {
                            $errors[$k][] = 'Invalid number.';
                            break;
                        }
                        $num = $v + 0;
                        if (isset($f['min']) && $num < $f['min']) {
                            $errors[$k][] = 'Number too low.';
                        }
                        if (isset($f['max']) && $num > $f['max']) {
                            $errors[$k][] = 'Number too high.';
                        }
                        if (isset($f['step']) && $f['step'] > 0) {
                            $base = isset($f['min']) ? $f['min'] : 0;
                            $mod = fmod($num - $base, $f['step']);
                            if ($mod !== 0.0 && $f['step'] !== 1 && $mod > 1e-8 && $f['step'] - $mod > 1e-8) {
                                $errors[$k][] = 'Invalid step.';
                            }
                        }
                        break;
                    case 'date':
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                            $errors[$k][] = 'Invalid date.';
                            break;
                        }
                        $ts = strtotime($v);
                        if ($ts === false) {
                            $errors[$k][] = 'Invalid date.';
                            break;
                        }
                        if (isset($f['min']) && $ts < strtotime((string)$f['min'])) {
                            $errors[$k][] = 'Date too early.';
                        }
                        if (isset($f['max']) && $ts > strtotime((string)$f['max'])) {
                            $errors[$k][] = 'Date too late.';
                        }
                        if (isset($f['step']) && $f['step'] > 0) {
                            $base = isset($f['min']) ? strtotime((string)$f['min']) : 0;
                            $mod = ($ts - $base) % ((int)$f['step'] * 86400);
                            if ($mod !== 0) {
                                $errors[$k][] = 'Invalid step.';
                            }
                        }
                        break;
                    case 'textarea_html':
                        $max = (int) Config::get('validation.textarea_html_max_bytes', 32768);
                        if (strlen($v) > $max) {
                            $errors[$k][] = 'Content too long.';
                            Logging::write('warn', 'EFORMS_ERR_HTML_TOO_LARGE', ['form_id'=>$tpl['id'] ?? '', 'field'=>$k]);
                            $v = '';
                        } else {
                            $san = \wp_kses_post($v);
                            if (strlen($san) > $max) {
                                $errors[$k][] = 'Content too long.';
                                Logging::write('warn', 'EFORMS_ERR_HTML_TOO_LARGE', ['form_id'=>$tpl['id'] ?? '', 'field'=>$k]);
                                $v = '';
                            } else {
                                $v = $san;
                            }
                        }
                        break;
                    case 'select':
                    case 'radio':
                    case 'checkbox':
                        $opts = array_column($f['options'] ?? [], 'key');
                        $enabled = [];
                        foreach ($f['options'] ?? [] as $opt) {
                            if (empty($opt['disabled'])) {
                                $enabled[] = $opt['key'];
                            }
                        }
                        if ($v !== '' && !in_array($v, $enabled, true)) {
                            $errors[$k][] = 'Invalid choice.';
                        }
                        break;
                }
            }
            $canonical[$k] = $v;
        }
        // Cross-field rules
        self::applyRules($tpl['rules'] ?? [], $canonical, $errors, $desc);
        return ['errors'=>$errors,'values'=>$canonical];
    }

    public static function coerce(array $tpl, array $desc, array $values): array
    {
        $out = [];
        foreach ($desc as $k => $f) {
            $v = $values[$k] ?? (self::isMultivalue($f) ? [] : '');
            switch ($f['type']) {
                case 'email':
                    if ($v !== '' && strpos($v, '@') !== false) {
                        [$local,$domain] = explode('@', $v, 2);
                        $v = $local . '@' . strtolower($domain);
                    }
                    break;
                case 'tel_us':
                    $digits = preg_replace('/\D+/', '', $v);
                    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                        $digits = substr($digits, 1);
                    }
                    $v = $digits;
                    break;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private static function isEmpty($v): bool
    {
        return is_array($v) ? count($v) === 0 : ($v === '' || $v === null);
    }

    private static function applyRules(array $rules, array $values, array &$errors, array $desc): void
    {
        foreach ($rules as $rule) {
            $type = $rule['rule'] ?? '';
            switch ($type) {
                case 'required_if':
                    $field = $rule['field'] ?? '';
                    $other = $rule['other'] ?? '';
                    $equals = (string)($rule['equals'] ?? '');
                    if ((string)($values[$other] ?? '') === $equals && self::isEmpty($values[$field] ?? null)) {
                        $errors[$field][] = 'This field is required.';
                    }
                    break;
                case 'required_if_any':
                    $field = $rule['field'] ?? '';
                    $fields = $rule['fields'] ?? [];
                    $equalsAny = $rule['equals_any'] ?? [];
                    $trigger = false;
                    foreach ($fields as $f) {
                        $val = (string)($values[$f] ?? '');
                        if (in_array($val, array_map('strval', $equalsAny), true)) { $trigger = true; break; }
                    }
                    if ($trigger && self::isEmpty($values[$field] ?? null)) {
                        $errors[$field][] = 'This field is required.';
                    }
                    break;
                case 'required_unless':
                    $field = $rule['field'] ?? '';
                    $other = $rule['other'] ?? '';
                    $equals = (string)($rule['equals'] ?? '');
                    if ((string)($values[$other] ?? '') !== $equals && self::isEmpty($values[$field] ?? null)) {
                        $errors[$field][] = 'This field is required.';
                    }
                    break;
                case 'matches':
                    $field = $rule['field'] ?? '';
                    $other = $rule['other'] ?? '';
                    if (($values[$field] ?? null) !== ($values[$other] ?? null)) {
                        $errors[$field][] = 'Fields must match.';
                    }
                    break;
                case 'one_of':
                    $fields = $rule['fields'] ?? [];
                    $count = 0;
                    foreach ($fields as $f) {
                        if (!self::isEmpty($values[$f] ?? null)) $count++;
                    }
                    if ($count === 0 || $count > 1) {
                        foreach ($fields as $f) {
                            if ($count === 0 || !self::isEmpty($values[$f] ?? null)) {
                                $errors[$f][] = $count === 0 ? 'One field is required.' : 'Only one field allowed.';
                            }
                        }
                    }
                    break;
                case 'mutually_exclusive':
                    $fields = $rule['fields'] ?? [];
                    $count = 0;
                    foreach ($fields as $f) {
                        if (!self::isEmpty($values[$f] ?? null)) $count++;
                    }
                    if ($count > 1) {
                        foreach ($fields as $f) {
                            if (!self::isEmpty($values[$f] ?? null)) {
                                $errors[$f][] = 'Fields are mutually exclusive.';
                            }
                        }
                    }
                    break;
                default:
                    Logging::write('warn', TemplateValidator::EFORMS_ERR_SCHEMA_ENUM, ['rule'=>$type]);
                    $errors['_global'][] = 'Form configuration error.';
                    break;
            }
        }
    }
}
