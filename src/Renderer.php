<?php
declare(strict_types=1);

namespace EForms;

class Renderer
{
    private static function makeId(string $formId, string $key): string
    {
        $id = 'eforms-' . $formId . '-' . $key;
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
        $allowed = [
            'a' => ['href' => []],
            'strong' => [],
            'em' => [],
            'span' => ['class' => []],
            'p' => [],
            'br' => [],
        ];
        return \wp_kses($html, $allowed);
    }

    public static function form(array $tpl, array $meta, array $errors, array $values): string
    {
        $formId = $meta['form_id'];
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
                $id = self::makeId($formId, $k);
                $html .= '<li><a href="#' . \esc_attr($id) . '">' . \esc_html($k) . '</a>';
                if ($msgs) {
                    $html .= ': ' . \esc_html($msgs[0]);
                }
                $html .= '</li>';
            }
            $html .= '</ul></div>';
        }

        $html .= '<form method="post"' . ($clientValidation ? '' : ' novalidate') . ' action="' . \esc_url($meta['action']) . '">';
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

        foreach ($tpl['fields'] as $f) {
            $type = $f['type'];
            if ($type === 'row_group') {
                $tag = $f['tag'] ?? 'div';
                $class = isset($f['class']) ? ' class="' . \esc_attr($f['class']) . '"' : '';
                if (($f['mode'] ?? '') === 'start') {
                    $html .= "<{$tag}{$class}>";
                } else {
                    $html .= "</{$tag}>";
                }
                continue;
            }
            $key = $f['key'];
            $id = self::makeId($formId, $key);
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
                    $html .= '<textarea id="' . \esc_attr($id) . '" name="' . \esc_attr($key) . '"';
                    if (!empty($f['required'])) $html .= ' required';
                    $html .= $errAttr . '>' . \esc_textarea((string)$value) . '</textarea>';
                    break;
                case 'select':
                    $html .= '<label for="' . \esc_attr($id) . '">' . \esc_html($label) . '</label>';
                    $html .= '<select id="' . \esc_attr($id) . '" name="' . \esc_attr($key) . '"';
                    if (!empty($f['required'])) $html .= ' required';
                    $html .= $errAttr . '>';
                    foreach ($f['options'] ?? [] as $opt) {
                        $disabled = !empty($opt['disabled']);
                        $html .= '<option value="' . \esc_attr($opt['key']) . '"' . ($disabled ? ' disabled' : '');
                        if ((string)$value === (string)$opt['key']) $html .= ' selected';
                        $html .= '>' . \esc_html($opt['label']) . '</option>';
                    }
                    $html .= '</select>';
                    break;
                case 'radio':
                case 'checkbox':
                    $html .= '<fieldset';
                    if (!empty($f['required'])) $html .= ' required';
                    if ($fieldErrors) $html .= ' aria-describedby="' . \esc_attr($errId) . '" aria-invalid="true"';
                    $html .= '><legend>' . \esc_html($label) . '</legend>';
                    foreach ($f['options'] ?? [] as $opt) {
                        $idOpt = self::makeId($formId, $key . '-' . $opt['key']);
                        $html .= '<label><input type="' . ($type === 'radio' ? 'radio' : 'checkbox') . '" name="' . \esc_attr($key) . '" value="' . \esc_attr($opt['key']) . '" id="' . \esc_attr($idOpt) . '"';
                        if ((string)$value === (string)$opt['key']) $html .= ' checked';
                        if (!empty($opt['disabled'])) $html .= ' disabled';
                        if (!empty($f['required'])) $html .= ' required';
                        $html .= '> ' . \esc_html($opt['label']) . '</label>';
                    }
                    $html .= '</fieldset>';
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
                    $html .= '<input type="' . \esc_attr($inputType) . '" id="' . \esc_attr($id) . '" name="' . \esc_attr($key) . '" value="' . \esc_attr((string)$value) . '"';
                    if (!empty($f['required'])) $html .= ' required';
                    $html .= $extra . $errAttr . '>';
                    break;
            }
            if ($fieldErrors) {
                $html .= '<span id="' . \esc_attr($errId) . '" class="eforms-error">' . \esc_html($fieldErrors[0]) . '</span>';
            }
            $html .= $after;
        }
        $btn = $tpl['submit_button_text'] ?? 'Submit';
        $html .= '<button type="submit">' . \esc_html($btn) . '</button>';
        $html .= '</form>';
        return $html;
    }
}
