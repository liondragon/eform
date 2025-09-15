<?php
declare(strict_types=1);

namespace EForms\Validation;

use EForms\Config;
use EForms\Logging;
use EForms\Rendering\Renderer;

class Validator
{
    /**
     * Registry mapping validator IDs to callable handlers.
     */
    private const VALIDATORS = [
        '' => [self::class, 'validateNone'],
        'text' => [self::class, 'validateNone'],
        'email' => [self::class, 'validateEmail'],
        'url' => [self::class, 'validateUrl'],
        'tel' => [self::class, 'validateNone'],
        'tel_us' => [self::class, 'validateTelUs'],
        'number' => [self::class, 'validateNumber'],
        'range' => [self::class, 'validateNumber'],
        'date' => [self::class, 'validateDate'],
        'textarea' => [self::class, 'validateNone'],
        'textarea_html' => [self::class, 'validateTextareaHtml'],
        'zip' => [self::class, 'validateNone'],
        'zip_us' => [self::class, 'validateZipUs'],
        'select' => [self::class, 'validateChoice'],
        'radio' => [self::class, 'validateChoice'],
        'checkbox' => [self::class, 'validateChoice'],
        'file' => [self::class, 'validateNone'],
        'files' => [self::class, 'validateNone'],
    ];

    /**
     * Resolve a validator handler by identifier.
     *
     * @throws \RuntimeException when the identifier is unknown
     */
    public static function resolve(string $id, ?string $context = null): callable
    {
        if (!isset(self::VALIDATORS[$id])) {
            $msg = 'Unknown validator ID: ' . $id;
            if ($context !== null && $context !== '') {
                $msg .= ' (context: ' . $context . ')';
            }
            throw new \RuntimeException($msg);
        }
        return self::VALIDATORS[$id];
    }

    /**
     * No-op validator used for types without specific validation.
     */
    public static function validateNone($v, array $f, array &$errors)
    {
        return $v;
    }

    /**
     * Validate an email address.
     */
    public static function validateEmail(string $v, array $f, array &$errors): string
    {
        if (!\is_email($v)) {
            $errors[$f['key']][] = 'Invalid email.';
        }
        return $v;
    }

    /**
     * Validate a URL with http/https scheme.
     */
    public static function validateUrl(string $v, array $f, array &$errors): string
    {
        $url = filter_var($v, FILTER_VALIDATE_URL);
        $scheme = strtolower(parse_url((string)$url, PHP_URL_SCHEME));
        if (!$url || !in_array($scheme, ['http', 'https'], true)) {
            $errors[$f['key']][] = 'Invalid URL.';
        }
        return $v;
    }

    /**
     * Validate US ZIP codes.
     */
    public static function validateZipUs(string $v, array $f, array &$errors): string
    {
        if (!preg_match('/^\d{5}$/', $v)) {
            $errors[$f['key']][] = 'Invalid ZIP.';
        }
        return $v;
    }

    /**
     * Validate US telephone numbers.
     */
    public static function validateTelUs(string $v, array $f, array &$errors): string
    {
        $digits = preg_replace('/\D+/', '', $v);
        if (strlen($digits) < 10) {
            $errors[$f['key']][] = 'Invalid phone.';
        }
        return $v;
    }

    /**
     * Validate numeric input (number and range types).
     */
    public static function validateNumber($v, array $f, array &$errors)
    {
        if (!is_numeric($v)) {
            $errors[$f['key']][] = 'Invalid number.';
            return $v;
        }
        $num = $v + 0;
        if (isset($f['min']) && $num < $f['min']) {
            $errors[$f['key']][] = 'Number too low.';
        }
        if (isset($f['max']) && $num > $f['max']) {
            $errors[$f['key']][] = 'Number too high.';
        }
        if (isset($f['step']) && $f['step'] > 0) {
            $base = isset($f['min']) ? $f['min'] : 0;
            $mod = fmod($num - $base, $f['step']);
            if ($mod !== 0.0 && $f['step'] !== 1 && $mod > 1e-8 && $f['step'] - $mod > 1e-8) {
                $errors[$f['key']][] = 'Invalid step.';
            }
        }
        return $v;
    }

    /**
     * Validate date input.
     */
    public static function validateDate(string $v, array $f, array &$errors): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $errors[$f['key']][] = 'Invalid date.';
            return $v;
        }
        $ts = strtotime($v);
        if ($ts === false) {
            $errors[$f['key']][] = 'Invalid date.';
            return $v;
        }
        if (isset($f['min']) && $ts < strtotime((string)$f['min'])) {
            $errors[$f['key']][] = 'Date too early.';
        }
        if (isset($f['max']) && $ts > strtotime((string)$f['max'])) {
            $errors[$f['key']][] = 'Date too late.';
        }
        if (isset($f['step']) && $f['step'] > 0) {
            $base = isset($f['min']) ? strtotime((string)$f['min']) : 0;
            $mod = ($ts - $base) % ((int)$f['step'] * 86400);
            if ($mod !== 0) {
                $errors[$f['key']][] = 'Invalid step.';
            }
        }
        return $v;
    }

    /**
     * Validate HTML textarea content length and sanitize.
     */
    public static function validateTextareaHtml(string $v, array $f, array &$errors): string
    {
        $max = (int) Config::get('validation.textarea_html_max_bytes', 32768);
        if (strlen($v) > $max) {
            $errors[$f['key']][] = 'This content is too long.';
            Logging::write('warn', 'EFORMS_ERR_HTML_TOO_LARGE', ['form_id'=>$f['form_id'] ?? '', 'field'=>$f['key']]);
            return '';
        }
        $san = \wp_kses_post($v);
        if (strlen($san) > $max) {
            $errors[$f['key']][] = 'This content is too long.';
            Logging::write('warn', 'EFORMS_ERR_HTML_TOO_LARGE', ['form_id'=>$f['form_id'] ?? '', 'field'=>$f['key']]);
            return '';
        }
        return $san;
    }

    /**
     * Validate choice inputs (select/radio/checkbox single value).
     */
    public static function validateChoice(string $v, array $f, array &$errors): string
    {
        $enabled = [];
        foreach ($f['options'] ?? [] as $opt) {
            if (empty($opt['disabled'])) {
                $enabled[] = $opt['key'];
            }
        }
        if ($v !== '' && !in_array($v, $enabled, true)) {
            $errors[$f['key']][] = 'Invalid choice.';
        }
        return $v;
    }

    private static function isMultivalue(array $f): bool
    {
        $type = $f['type'] ?? '';
        if ($type === 'checkbox') return true;
        if ($type === 'select' && !empty($f['multiple'])) return true;
        return false;
    }

    private static function nfc(string $v): string
    {
        if (class_exists('\\Normalizer')) {
            $n = \Normalizer::normalize($v, \Normalizer::FORM_C);
            if ($n !== false) return $n;
        }
        return $v;
    }
    public static function descriptors(array $tpl): array
    {
        if (isset($tpl['descriptors']) && is_array($tpl['descriptors'])) {
            return $tpl['descriptors'];
        }
        $desc = [];
        foreach ($tpl['fields'] as $f) {
            if (($f['type'] ?? '') === 'row_group') {
                continue;
            }
            $handlers = $f['handlers'] ?? [];
            $hid = $handlers['validator_id'] ?? ($f['type'] ?? '');
            $nid = $handlers['normalizer_id'] ?? ($f['type'] ?? '');
            $rid = $handlers['renderer_id'] ?? ($f['type'] ?? '');
            $f['form_id'] = $tpl['id'] ?? '';
            $f['is_multivalue'] = self::isMultivalue($f);
            $f['name_tpl'] = $f['form_id'] . '[' . $f['key'] . ']' . ($f['is_multivalue'] ? '[]' : '');
            $f['id_prefix'] = $f['form_id'] . '-' . $f['key'] . '-';
            $ctxBase = 'fields.' . ($f['key'] ?? '');
            $f['handlers'] = $handlers + [
                'validator'  => self::resolve($hid, $ctxBase . '.validator'),
                'normalizer' => Normalizer::resolve($nid, $ctxBase . '.normalizer'),
            ];
            if (!array_key_exists('renderer', $handlers)) {
                $f['handlers']['renderer'] = Renderer::resolve($rid, $ctxBase . '.renderer');
            }
            $desc[$f['key']] = $f;
        }
        return $desc;
    }

    public static function normalize(array $tpl, array $post, ?array $desc = null): array
    {
        $desc = $desc ?? self::descriptors($tpl);
        $values = [];
        foreach ($desc as $k => $f) {
            $norm = $f['handlers']['normalizer'] ?? [Normalizer::class, 'identity'];
            if (!empty($f['is_multivalue'])) {
                $raw = $post[$k] ?? [];
                if (!is_array($raw)) {
                    $raw = [];
                }
                $vals = [];
                foreach ($raw as $rv) {
                    if (is_scalar($rv)) {
                        $sv = function_exists('\\wp_unslash') ? \wp_unslash($rv) : stripslashes((string) $rv);
                        $sv = self::nfc((string) $sv);
                        $sv = trim($sv);
                        $vals[] = $norm($sv);
                    }
                }
                $values[$k] = $vals;
            } else {
                $v = $post[$k] ?? '';
                if (is_array($v)) {
                    // Preserve arrays for scalar fields so validation can detect invalid input
                    $values[$k] = $v;
                    continue;
                }
                $sv = function_exists('\\wp_unslash') ? \wp_unslash($v) : stripslashes((string) $v);
                $sv = self::nfc((string) $sv);
                $sv = trim($sv);
                $values[$k] = $norm($sv);
            }
        }
        return $values;
    }

    public static function validate(array $tpl, array $desc, array $values): array
    {
        $errors = [];
        $canonical = [];
        foreach ($desc as $k => $f) {
            $v = $values[$k] ?? (!empty($f['is_multivalue']) ? [] : '');
            if (!empty($f['is_multivalue'])) {
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
            if (is_array($v)) {
                $errors[$k][] = 'Invalid input.';
                $canonical[$k] = '';
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
                if (isset($f['handlers']['validator']) && is_callable($f['handlers']['validator'])) {
                    $v = $f['handlers']['validator']($v, $f, $errors);
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
            $norm = $f['handlers']['normalizer'] ?? [Normalizer::class, 'identity'];
            $v = $values[$k] ?? (!empty($f['is_multivalue']) ? [] : '');
            if (!empty($f['is_multivalue'])) {
                $out[$k] = array_map($norm, is_array($v) ? $v : []);
            } else {
                $out[$k] = $norm($v);
            }
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
                    $target = $rule['target'] ?? '';
                    $field = $rule['field'] ?? '';
                    $equals = (string)($rule['equals'] ?? '');
                    if ((string)($values[$field] ?? '') === $equals && self::isEmpty($values[$target] ?? null)) {
                        $errors[$target][] = 'This field is required.';
                    }
                    break;
                case 'required_if_any':
                    $target = $rule['target'] ?? '';
                    $fields = $rule['fields'] ?? [];
                    $equalsAny = $rule['equals_any'] ?? [];
                    $trigger = false;
                    foreach ($fields as $f) {
                        $val = (string)($values[$f] ?? '');
                        if (in_array($val, array_map('strval', $equalsAny), true)) { $trigger = true; break; }
                    }
                    if ($trigger && self::isEmpty($values[$target] ?? null)) {
                        $errors[$target][] = 'This field is required.';
                    }
                    break;
                case 'required_unless':
                    $target = $rule['target'] ?? '';
                    $field = $rule['field'] ?? '';
                    $equals = (string)($rule['equals'] ?? '');
                    if ((string)($values[$field] ?? '') !== $equals && self::isEmpty($values[$target] ?? null)) {
                        $errors[$target][] = 'This field is required.';
                    }
                    break;
                case 'matches':
                    $target = $rule['target'] ?? '';
                    $field = $rule['field'] ?? '';
                    if (($values[$target] ?? null) !== ($values[$field] ?? null)) {
                        $errors[$target][] = 'Fields must match.';
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
