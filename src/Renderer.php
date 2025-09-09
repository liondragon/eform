<?php
declare(strict_types=1);

namespace EForms;

class Renderer
{
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
        $common = ['class' => []];
        $allowed = [
            'a' => ['href' => [], 'class' => []],
            'strong' => $common,
            'em' => $common,
            'span' => $common,
            'p' => $common,
            'br' => $common,
            'div' => $common,
            'h1' => $common,
            'h2' => $common,
            'h3' => $common,
            'h4' => $common,
            'h5' => $common,
            'h6' => $common,
            'ul' => $common,
            'ol' => $common,
            'li' => $common,
        ];
        return \wp_kses($html, $allowed, ['http','https','mailto']);
    }

    public static function form(array $tpl, array $meta, array $errors, array $values): string
    {
        $formId = $meta['form_id'];
        $instanceId = $meta['instance_id'];
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
                $html .= '<li><a href="#' . \esc_attr($id) . '">' . \esc_html($k) . '</a>';
                if ($msgs) {
                    $html .= ': ' . \esc_html($msgs[0]);
                }
                $html .= '</li>';
            }
            $html .= '</ul></div>';
        }

        $enctype = $meta['enctype'] ?? 'application/x-www-form-urlencoded';
        $html .= '<form method="post"' . ($clientValidation ? '' : ' novalidate') . ' action="' . \esc_url($meta['action']) . '"';
        if ($enctype === 'multipart/form-data') {
            $html .= ' enctype="multipart/form-data"';
        }
        $html .= '>';
        // hidden meta
        $html .= '<input type="hidden" name="form_id" value="' . \esc_attr($formId) . '">';
        $html .= '<input type="hidden" name="instance_id" value="' . \esc_attr($meta['instance_id']) . '">';
        $html .= '<input type="hidden" name="eforms_hp" value="">';
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
            $id = self::makeId($formId, $key, $instanceId);
            $label = $f['label'] ?? ucwords(str_replace(['_','-'], ' ', $key));
            $value = $values[$key] ?? '';
            $fieldErrors = $errors[$key] ?? [];
            $errId = 'error-' . $id;
            $errAttr = '';
            if ($fieldErrors) {
                $errAttr = ' aria-describedby="' . \esc_attr($errId) . '" aria-invalid="true"';
            }
            $before = isset($f['before_html']) ? self::sanitizeFragment($f['before_html']) : '';
            $after = isset($f['after_html']) ? self::sanitizeFragment($f['after_html']) : '';
            $html .= $before;
            switch ($type) {
                case 'textarea':
                    $html .= '<label for="' . \esc_attr($id) . '">' . \esc_html($label) . '</label>';
                    $extraHint = ($key === $lastText) ? ' enterkeyhint="send"' : '';
                    $html .= '<textarea id="' . \esc_attr($id) . '" name="' . \esc_attr($formId . '[' . $key . ']') . '"';
                    if (!empty($f['required'])) $html .= ' required';
                    if (!empty($f['placeholder'])) $html .= ' placeholder="' . \esc_attr($f['placeholder']) . '"';
                    if (!empty($f['autocomplete'])) $html .= ' autocomplete="' . \esc_attr($f['autocomplete']) . '"';
                    if (!empty($f['max_length'])) $html .= ' maxlength="' . (int)$f['max_length'] . '"';
                    $html .= $errAttr . $extraHint . '>' . \esc_textarea((string)$value) . '</textarea>';
                    break;
                case 'textarea_html':
                    $html .= '<label for="' . \esc_attr($id) . '">' . \esc_html($label) . '</label>';
                    $extraHint = ($key === $lastText) ? ' enterkeyhint="send"' : '';
                    $html .= '<textarea id="' . \esc_attr($id) . '" name="' . \esc_attr($formId . '[' . $key . ']') . '"';
                    if (!empty($f['required'])) $html .= ' required';
                    if (!empty($f['placeholder'])) $html .= ' placeholder="' . \esc_attr($f['placeholder']) . '"';
                    if (!empty($f['autocomplete'])) $html .= ' autocomplete="' . \esc_attr($f['autocomplete']) . '"';
                    if (!empty($f['max_length'])) $html .= ' maxlength="' . (int)$f['max_length'] . '"';
                    $html .= $errAttr . $extraHint . '>' . \esc_textarea((string)$value) . '</textarea>';
                    break;
                case 'select':
                    $html .= '<label for="' . \esc_attr($id) . '">' . \esc_html($label) . '</label>';
                    $multiple = !empty($f['multiple']);
                    $nameAttr = $formId . '[' . $key . ']' . ($multiple ? '[]' : '');
                    $vals = $multiple && is_array($value) ? $value : (string)$value;
                    $html .= '<select id="' . \esc_attr($id) . '" name="' . \esc_attr($nameAttr) . '"';
                    if ($multiple) $html .= ' multiple';
                    if (!empty($f['required'])) $html .= ' required';
                    if (!empty($f['autocomplete'])) $html .= ' autocomplete="' . \esc_attr($f['autocomplete']) . '"';
                    if (!empty($f['size'])) $html .= ' size="' . (int)$f['size'] . '"';
                    $html .= $errAttr . '>';
                    foreach ($f['options'] ?? [] as $opt) {
                        $disabled = !empty($opt['disabled']);
                        $html .= '<option value="' . \esc_attr($opt['key']) . '"' . ($disabled ? ' disabled' : '');
                        if ($multiple) {
                            if (in_array($opt['key'], (array)$vals, true)) $html .= ' selected';
                        } else {
                            if ((string)$vals === (string)$opt['key']) $html .= ' selected';
                        }
                        $html .= '>' . \esc_html($opt['label']) . '</option>';
                    }
                    $html .= '</select>';
                    break;
                case 'radio':
                case 'checkbox':
                    $html .= '<fieldset id="' . \esc_attr($id) . '"';
                    if (!empty($f['required'])) $html .= ' required';
                    if ($fieldErrors) $html .= ' aria-describedby="' . \esc_attr($errId) . '" aria-invalid="true"';
                    $html .= '><legend>' . \esc_html($label) . '</legend>';
                    $vals = $type === 'checkbox' ? (array)$value : $value;
                    foreach ($f['options'] ?? [] as $opt) {
                        $idOpt = self::makeId($formId, $key . '-' . $opt['key'], $instanceId);
                        $nameAttr = $type === 'checkbox' ? $formId . '[' . $key . '][]' : $formId . '[' . $key . ']';
                        $html .= '<label><input type="' . ($type === 'radio' ? 'radio' : 'checkbox') . '" name="' . \esc_attr($nameAttr) . '" value="' . \esc_attr($opt['key']) . '" id="' . \esc_attr($idOpt) . '"';
                        if ($type === 'checkbox') {
                            if (in_array($opt['key'], (array)$vals, true)) $html .= ' checked';
                        } else {
                            if ((string)$vals === (string)$opt['key']) $html .= ' checked';
                        }
                        if (!empty($opt['disabled'])) $html .= ' disabled';
                        if (!empty($f['required'])) $html .= ' required';
                        $html .= '> ' . \esc_html($opt['label']) . '</label>';
                    }
                    $html .= '</fieldset>';
                    break;
                case 'file':
                case 'files':
                    $nameAttr = $formId . '[' . $key . ']' . ($type === 'files' ? '[]' : '');
                    $html .= '<label for="' . \esc_attr($id) . '">' . \esc_html($label) . '</label>';
                    $html .= '<input type="file" id="' . \esc_attr($id) . '" name="' . \esc_attr($nameAttr) . '"';
                    if ($type === 'files') $html .= ' multiple';
                    if (!empty($f['required'])) $html .= ' required';
                    if (!empty($f['accept']) && is_array($f['accept'])) {
                        $accept = self::acceptAttr($f['accept']);
                        if ($accept !== '') $html .= ' accept="' . \esc_attr($accept) . '"';
                    }
                    $html .= $errAttr . '>';
                    break;
                case 'email':
                case 'name':
                case 'tel_us':
                case 'zip_us':
                default:
                    $inputType = 'text';
                    $extra = '';
                    if ($type === 'email') {
                        $inputType = 'email';
                        $extra .= ' inputmode="email" spellcheck="false" autocapitalize="off"';
                    } elseif ($type === 'tel_us') {
                        $inputType = 'tel';
                        $extra .= ' inputmode="tel"';
                    } elseif ($type === 'zip_us') {
                        $inputType = 'text';
                        $extra .= ' inputmode="numeric" pattern="\d{5}" maxlength="5"';
                    }
                    $html .= '<label for="' . \esc_attr($id) . '">' . \esc_html($label) . '</label>';
                    $extraHint = ($key === $lastText) ? ' enterkeyhint="send"' : '';
                    $html .= '<input type="' . \esc_attr($inputType) . '" id="' . \esc_attr($id) . '" name="' . \esc_attr($formId . '[' . $key . ']') . '" value="' . \esc_attr((string)$value) . '"';
                    if (!empty($f['required'])) $html .= ' required';
                    if (!empty($f['placeholder'])) $html .= ' placeholder="' . \esc_attr($f['placeholder']) . '"';
                    if (!empty($f['autocomplete'])) $html .= ' autocomplete="' . \esc_attr($f['autocomplete']) . '"';
                    if (!empty($f['max_length'])) $html .= ' maxlength="' . (int)$f['max_length'] . '"';
                    if ($f['min'] !== null) $html .= ' min="' . \esc_attr((string)$f['min']) . '"';
                    if ($f['max'] !== null) $html .= ' max="' . \esc_attr((string)$f['max']) . '"';
                    if (!empty($f['pattern'])) $html .= ' pattern="' . \esc_attr($f['pattern']) . '"';
                    if (!empty($f['size'])) $html .= ' size="' . (int)$f['size'] . '"';
                    $html .= $extra . $errAttr . $extraHint . '>';
                    break;
            }
            if ($fieldErrors) {
                $html .= '<span id="' . \esc_attr($errId) . '" class="eforms-error">' . \esc_html($fieldErrors[0]) . '</span>';
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
        $btn = $tpl['submit_button_text'] ?? 'Submit';
        $html .= '<button type="submit">' . \esc_html($btn) . '</button>';
        $html .= '</form>';
        return $html;
    }

    private static function acceptAttr(array $tokens): string
    {
        $map = Spec::acceptTokenMap();
        $out = [];
        foreach ($tokens as $t) {
            $t = trim((string)$t);
            if (isset($map[$t])) {
                $out[] = $map[$t];
            } elseif ($t !== '') {
                $out[] = $t;
            }
        }
        return implode(',', $out);
    }
}
