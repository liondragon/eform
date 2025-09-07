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
                        $vals[] = trim((string)$rv);
                    }
                }
                $values[$k] = $vals;
            } else {
                $v = $post[$k] ?? '';
                if (is_array($v)) {
                    $v = '';
                }
                $values[$k] = trim((string)$v);
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
                switch ($f['type']) {
                    case 'email':
                        if (!\is_email($v)) {
                            $errors[$k][] = 'Invalid email.';
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
}
