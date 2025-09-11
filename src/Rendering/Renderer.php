<?php
declare(strict_types=1);

namespace EForms\Rendering;

use EForms\Helpers;
use EForms\Logging;
use EForms\Spec;
use EForms\Validation\TemplateValidator;

class Renderer
{
    /**
     * Registry mapping renderer IDs to callable handlers.
     *
     * Identifiers mirror those used by field descriptors in {@see Spec}.
     */
    private const HANDLERS = [
        '' => [self::class, 'renderInput'],
        'text' => [self::class, 'renderInput'],
        'email' => [self::class, 'renderInput'],
        'url' => [self::class, 'renderInput'],
        'tel' => [self::class, 'renderInput'],
        'tel_us' => [self::class, 'renderInput'],
        'number' => [self::class, 'renderInput'],
        'range' => [self::class, 'renderInput'],
        'date' => [self::class, 'renderInput'],
        'textarea' => [self::class, 'renderTextarea'],
        'textarea_html' => [self::class, 'renderTextarea'],
        'zip' => [self::class, 'renderInput'],
        'zip_us' => [self::class, 'renderInput'],
        'select' => [self::class, 'renderSelect'],
        'radio' => [self::class, 'renderFieldset'],
        'checkbox' => [self::class, 'renderFieldset'],
        'file' => [self::class, 'renderInput'],
        'files' => [self::class, 'renderInput'],
    ];

    /**
     * Resolve a renderer handler by identifier.
     *
     * @throws \RuntimeException when the identifier is unknown
     */
    public static function resolve(string $id): callable
    {
        if (!isset(self::HANDLERS[$id])) {
            throw new \RuntimeException('Unknown renderer ID: ' . $id);
        }
        return self::HANDLERS[$id];
    }

    private static function makeId(string $formId, string $key, string $instanceId): string
    {
        $id = $formId . '-' . $key . '-' . $instanceId;
        if (strlen($id) > 128) {
            $hash = substr(md5($id), 0, 8);
            $start = substr($id, 0, 60);
            $end = substr($id, -60);
            $id = $start . '-' . $hash . '-' . $end;
            if (strlen($id) > 128) {
                $id = substr($id, 0, 119) . '-' . $hash;
            }
        }
        return $id;
    }

    private static function sanitizeFragment(string $html): string
    {
        return \wp_kses_post($html);
    }

    public static function form(array $tpl, array $meta, array $errors, array $values): string
    {
        $formId = $meta['form_id'];
        $instanceId = $meta['instance_id'];
        $formClass = Helpers::sanitize_id($tpl['id'] ?? $formId);
        // Success message check
        $successHtml = '';
        if (isset($_GET['eforms_success']) && \sanitize_key((string)$_GET['eforms_success']) === $formId) {
            $cookieName = 'eforms_s_' . $formId;
            $cookieVal = $_COOKIE[$cookieName] ?? '';
            if ($cookieVal && str_starts_with($cookieVal, $formId . ':')) {
                $msg = $tpl['success']['message'] ?? 'Success';
                $successHtml = '<div class="eforms-success">' . \esc_html($msg) . '</div>';
                \setcookie($cookieName, '', time() - 3600, '/');
                return $successHtml;
            }
        }

        $clientValidation = $meta['client_validation'] ?? false;
        $labels = [];
        $fieldTags = [];
        $descriptors = $tpl['descriptors'] ?? [];
        foreach ($tpl['fields'] as $lf) {
            if (($lf['type'] ?? '') === 'row_group') continue;
            $lk = $lf['key'];
            if (array_key_exists('label', $lf)) {
                if ($lf['label'] === null) {
                    $labels[$lk] = ucwords(str_replace(['_','-'], ' ', $lk));
                } else {
                    $labels[$lk] = $lf['label'];
                }
            } else {
                $labels[$lk] = ucwords(str_replace(['_','-'], ' ', $lk));
            }
            $desc = $descriptors[$lk] ?? Spec::descriptorFor($lf['type']);
            $fieldTags[$lk] = $desc['html']['tag'] ?? 'input';
        }

        $html = '';
        if (!empty($errors)) {
            $html .= '<div role="alert" tabindex="-1" class="eforms-error-summary"><ul>';
            foreach ($errors as $k => $msgs) {
                if ($k === '_global') {
                    foreach ($msgs as $m) {
                        $html .= '<li>' . \esc_html($m) . '</li>';
                    }
                    continue;
                }
                $id = self::makeId($formId, $k, $instanceId);
                $lab = $labels[$k] ?? $k;
                $aria = '';
                if (($fieldTags[$k] ?? '') === 'fieldset') {
                    $aria = ' aria-describedby="' . \esc_attr($id . '-legend') . '"';
                }
                $html .= '<li><a href="#' . \esc_attr($id) . '"' . $aria . '>' . \esc_html($lab) . '</a>';
                if ($msgs) {
                    $html .= ': ' . \esc_html($msgs[0]);
                }
                $html .= '</li>';
            }
            $html .= '</ul></div>';
        }

        $enctype = $meta['enctype'] ?? 'application/x-www-form-urlencoded';
        $html .= '<form class="eforms-form eforms-form-' . \esc_attr($formClass) . '" method="post"' . ($clientValidation ? '' : ' novalidate') . ' action="' . \esc_url($meta['action']) . '"';
        if ($enctype === 'multipart/form-data') {
            $html .= ' enctype="multipart/form-data"';
        }
        $html .= '>';
        // hidden meta
        $html .= '<input type="hidden" name="form_id" value="' . \esc_attr($formId) . '">';
        $html .= '<input type="hidden" name="instance_id" value="' . \esc_attr($meta['instance_id']) . '">';
        $hpId = 'hp_' . Helpers::random_id(8);
        $html .= '<input type="hidden" name="eforms_hp" id="' . \esc_attr($hpId) . '" value="">';
        $html .= '<input type="hidden" name="timestamp" value="' . (int)$meta['timestamp'] . '">';
        $html .= '<input type="hidden" name="js_ok" value="0">';
        if (!$meta['cacheable']) {
            $token = $meta['hidden_token'] ?? '';
            $html .= '<input type="hidden" name="eforms_token" value="' . \esc_attr($token) . '">';
        } else {
            $html .= '<img src="' . \esc_url(\home_url('/eforms/prime?f=' . $formId)) . '" aria-hidden="true" alt="" width="1" height="1" style="position:absolute;left:-9999px;">';
        }

        $lastText = null;
        foreach ($tpl['fields'] as $tf) {
            $tt = $tf['type'];
            if (in_array($tt, ['textarea','textarea_html','email','name','tel_us','zip_us'], true)) {
                $lastText = $tf['key'];
            }
        }

        $descriptors = $tpl['descriptors'] ?? [];
        $rowStack = [];
        $rowErr = false;
        foreach ($tpl['fields'] as $f) {
            $type = $f['type'];
            if ($type === 'row_group') {
                $tag = $f['tag'] ?? 'div';
                $classes = trim('eforms-row ' . ($f['class'] ?? ''));
                if (($f['mode'] ?? '') === 'start') {
                    $rowStack[] = $tag;
                    $html .= '<' . $tag . ' class="' . \esc_attr($classes) . '">';
                } else {
                    if (!empty($rowStack)) {
                        $open = array_pop($rowStack);
                        $html .= '</' . $open . '>';
                    } else {
                        $rowErr = true;
                    }
                }
                continue;
            }
            $key = $f['key'];
            $desc = $descriptors[$key] ?? Spec::descriptorFor($type);
            $isMulti = !empty($desc['is_multivalue']);
            $id = self::makeId($formId, $key, $instanceId);
            $nameAttr = $formId . '[' . $key . ']' . ($isMulti ? '[]' : '');
            $label = $labels[$key] ?? '';
            $labelHidden = true;
            if (array_key_exists('label', $f) && $f['label'] !== null) {
                $labelHidden = false;
            }
            $labelAttr = $labelHidden ? ' class="visually-hidden"' : '';
            $labelHtml = \esc_html($label);
            if (!empty($f['required'])) {
                $labelHtml .= '<span class="required">*</span>';
            }
            $value = $values[$key] ?? '';
            $fieldErrors = $errors[$key] ?? [];
            $errId = 'error-' . $id;
            $errAttr = $fieldErrors ? ' aria-describedby="' . \esc_attr($errId) . '" aria-invalid="true"' : '';
            $before = isset($f['before_html']) ? self::sanitizeFragment($f['before_html']) : '';
            $after = isset($f['after_html']) ? self::sanitizeFragment($f['after_html']) : '';
            $html .= $before;
            $handler = $desc['handlers']['renderer'] ?? self::resolve($desc['handlers']['renderer_id'] ?? '');
            $ctx = [
                'desc' => $desc,
                'f' => $f,
                'id' => $id,
                'nameAttr' => $nameAttr,
                'labelHtml' => $labelHtml,
                'labelAttr' => $labelAttr,
                'errAttr' => $errAttr,
                'value' => $value,
                'isMulti' => $isMulti,
                'key' => $key,
                'formId' => $formId,
                'instanceId' => $instanceId,
                'lastText' => $lastText,
                'fieldErrors' => $fieldErrors,
                'errId' => $errId,
            ];
            $html .= $handler($ctx);
            if ($fieldErrors) {
                $html .= '<span id="' . \esc_attr($errId) . '" class="eforms-error" role="status" aria-live="polite">' . \esc_html($fieldErrors[0]) . '</span>';
            }
            $html .= $after;
        }
        while (!empty($rowStack)) {
            $rowErr = true;
            $open = array_pop($rowStack);
            $html .= '</' . $open . '>';
        }
        if ($rowErr) {
            Logging::write('warn', TemplateValidator::EFORMS_ERR_ROW_GROUP_UNBALANCED, ['form_id'=>$formId,'instance_id'=>$meta['instance_id'] ?? '']);
        }
        if (!empty($meta['challenge'])) {
            $ch = $meta['challenge'];
            $prov = $ch['provider'];
            $site = $ch['site_key'] ?? '';
            if ($prov === 'turnstile') {
                $html .= '<div class="cf-challenge" data-sitekey="' . \esc_attr($site) . '"></div>';
            } elseif ($prov === 'hcaptcha') {
                $html .= '<div class="h-captcha" data-sitekey="' . \esc_attr($site) . '"></div>';
            } elseif ($prov === 'recaptcha') {
                $html .= '<div class="g-recaptcha" data-sitekey="' . \esc_attr($site) . '"></div>';
            }
        }
        $btn = $tpl['submit_button_text'] ?? 'Submit';
        $html .= '<button type="submit">' . \esc_html($btn) . '</button>';
        $html .= '</form>';
        return $html;
    }

    private static function renderTextControl(callable $emit, array $c): string
    {
        $desc = $c['desc'];
        $f = $c['f'];
        $id = $c['id'];
        $nameAttr = $c['nameAttr'];
        $labelHtml = $c['labelHtml'];
        $labelAttr = $c['labelAttr'];
        $errAttr = $c['errAttr'];
        $value = $c['value'];
        $key = $c['key'];
        $lastText = $c['lastText'];
        $attrs = self::controlAttrs($desc, $f);
        if (!empty($f['required'])) $attrs .= ' required';
        if (!empty($f['placeholder'])) $attrs .= ' placeholder="' . \esc_attr($f['placeholder']) . '"';
        if (!empty($f['autocomplete'])) $attrs .= ' autocomplete="' . \esc_attr($f['autocomplete']) . '"';
        $extraHint = ($key === $lastText && ($desc['html']['type'] ?? '') !== 'file') ? ' enterkeyhint="send"' : '';
        $attrs .= $errAttr . $extraHint;
        $html = '<label for="' . \esc_attr($id) . '"' . $labelAttr . '>' . $labelHtml . '</label>';
        $html .= $emit($c, $attrs);
        return $html;
    }

    private static function renderInput(array $c): string
    {
        return self::renderTextControl(function (array $ctx, string $attrs): string {
            $desc = $ctx['desc'];
            $f = $ctx['f'];
            $id = $ctx['id'];
            $nameAttr = $ctx['nameAttr'];
            $value = $ctx['value'];
            if (isset($f['size'])) $attrs .= ' size="' . (int)$f['size'] . '"';
            if (($desc['html']['type'] ?? '') === 'file' && !empty($f['accept']) && is_array($f['accept'])) {
                $accept = self::acceptAttr($f['accept']);
                if ($accept !== '') $attrs .= ' accept="' . \esc_attr($accept) . '"';
            }
            return '<input id="' . \esc_attr($id) . '" name="' . \esc_attr($nameAttr) . '" value="' . \esc_attr((string)$value) . '"' . $attrs . '>';
        }, $c);
    }

    private static function renderTextarea(array $c): string
    {
        return self::renderTextControl(function (array $ctx, string $attrs): string {
            $id = $ctx['id'];
            $nameAttr = $ctx['nameAttr'];
            $value = $ctx['value'];
            return '<textarea id="' . \esc_attr($id) . '" name="' . \esc_attr($nameAttr) . '"' . $attrs . '>' . \esc_textarea((string)$value) . '</textarea>';
        }, $c);
    }

    private static function renderSelect(array $c): string
    {
        $desc = $c['desc'];
        $f = $c['f'];
        $id = $c['id'];
        $nameAttr = $c['nameAttr'];
        $labelHtml = $c['labelHtml'];
        $labelAttr = $c['labelAttr'];
        $errAttr = $c['errAttr'];
        $value = $c['value'];
        $isMulti = $c['isMulti'];
        $attrs = self::controlAttrs($desc, $f);
        if (!empty($f['required'])) $attrs .= ' required';
        if (!empty($f['autocomplete'])) $attrs .= ' autocomplete="' . \esc_attr($f['autocomplete']) . '"';
        if (!empty($f['size'])) $attrs .= ' size="' . (int)$f['size'] . '"';
        $vals = $isMulti ? (array)$value : (string)$value;
        $html = '<label for="' . \esc_attr($id) . '"' . $labelAttr . '>' . $labelHtml . '</label>';
        $html .= '<select id="' . \esc_attr($id) . '" name="' . \esc_attr($nameAttr) . '"' . $attrs . $errAttr . '>';
        foreach ($f['options'] ?? [] as $opt) {
            $disabled = !empty($opt['disabled']);
            $html .= '<option value="' . \esc_attr($opt['key']) . '"' . ($disabled ? ' disabled' : '');
            if ($isMulti) {
                if (in_array($opt['key'], (array)$vals, true)) $html .= ' selected';
            } else {
                if ((string)$vals === (string)$opt['key']) $html .= ' selected';
            }
            $html .= '>' . \esc_html($opt['label']) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private static function renderFieldset(array $c): string
    {
        $desc = $c['desc'];
        $f = $c['f'];
        $id = $c['id'];
        $nameAttr = $c['nameAttr'];
        $labelHtml = $c['labelHtml'];
        $labelAttr = $c['labelAttr'];
        $fieldErrors = $c['fieldErrors'];
        $value = $c['value'];
        $isMulti = $c['isMulti'];
        $key = $c['key'];
        $formId = $c['formId'];
        $instanceId = $c['instanceId'];
        $errId = $c['errId'];
        $attrs = self::controlAttrs($desc, $f);
        $legendId = $id . '-legend';
        $html = '<fieldset id="' . \esc_attr($id) . '"' . $attrs . ' tabindex="-1"';
        if (!empty($f['required'])) $html .= ' aria-required="true"';
        if ($fieldErrors) $html .= ' aria-describedby="' . \esc_attr($errId) . '" aria-invalid="true"';
        $html .= '><legend id="' . \esc_attr($legendId) . '"' . $labelAttr . '>' . $labelHtml . '</legend>';
        $vals = $isMulti ? (array)$value : $value;
        foreach ($f['options'] ?? [] as $opt) {
            $idOpt = self::makeId($formId, $key . '-' . $opt['key'], $instanceId);
            $html .= '<label><input type="' . ($isMulti ? 'checkbox' : 'radio') . '" name="' . \esc_attr($nameAttr) . '" value="' . \esc_attr($opt['key']) . '" id="' . \esc_attr($idOpt) . '"';
            if ($isMulti) {
                if (in_array($opt['key'], (array)$vals, true)) $html .= ' checked';
            } else {
                if ((string)$vals === (string)$opt['key']) $html .= ' checked';
            }
            if (!empty($opt['disabled'])) $html .= ' disabled';
            if (!empty($f['required'])) $html .= ' required';
            $html .= '> ' . \esc_html($opt['label']) . '</label>';
        }
        $html .= '</fieldset>';
        return $html;
    }

    private static function controlAttrs(array $desc, array $f): string
    {
        $html = $desc['html'] ?? [];
        $attrs = '';
        foreach ($html as $k => $v) {
            if ($k === 'tag' || $k === 'attrs_mirror') continue;
            if ($k === 'multiple' && $v === true) {
                $attrs .= ' multiple';
                continue;
            }
            if (isset($f[$k])) {
                continue;
            }
            if ($v !== null) {
                $attrs .= ' ' . $k . '="' . \esc_attr((string)$v) . '"';
            }
        }
        $mirror = $html['attrs_mirror'] ?? [];
        $map = ['maxlength' => 'max_length', 'minlength' => 'min_length'];
        foreach ($mirror as $attr => $def) {
            $key = $map[$attr] ?? $attr;
            $val = $f[$key] ?? $def;
            if ($val !== null) {
                $attrs .= ' ' . $attr . '="' . \esc_attr((string)$val) . '"';
            }
        }
        if (!isset($mirror['step']) && isset($f['step']) && $f['step'] !== null) {
            $attrs .= ' step="' . \esc_attr((string)$f['step']) . '"';
        }
        return $attrs;
    }

    private static function acceptAttr(array $tokens): string
    {
        $map = Spec::acceptTokenMap();
        $out = [];
        foreach ($tokens as $t) {
            $t = trim((string)$t);
            if (isset($map[$t])) {
                foreach (array_keys($map[$t]) as $mime) {
                    $out[] = $mime;
                }
            } elseif ($t !== '') {
                $out[] = $t;
            }
        }
        return implode(',', array_unique($out));
    }
}
