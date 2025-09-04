<?php
declare(strict_types=1);

namespace EForms;

class FormManager
{
    public function render(string $formId, array $opts = []): string
    {
        $formId = \sanitize_key($formId);
        $cacheable = $opts['cacheable'] ?? true;
        $cacheable = (bool) $cacheable;
        $instanceId = bin2hex(random_bytes(16));
        $timestamp = time();

        $html = sprintf("<!-- eforms stage1 placeholder: form_id=%s cacheable=%s -->", \esc_html($formId), $cacheable ? 'true' : 'false');
        $html .= '<form method="post" novalidate>';
        $html .= sprintf('<input type="hidden" name="form_id" value="%s">', \esc_attr($formId));
        $html .= sprintf('<input type="hidden" name="instance_id" value="%s">', \esc_attr($instanceId));
        $html .= '<input type="hidden" name="eforms_hp" value="">';
        $html .= sprintf('<input type="hidden" name="timestamp" value="%d">', $timestamp);
        $html .= '<input type="hidden" name="js_ok" value="0">';
        if (!$cacheable) {
            $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $instanceId;
            $html .= sprintf('<input type="hidden" name="eforms_token" value="%s">', \esc_attr($token));
        }
        $html .= '</form>';
        return $html;
    }

    public function enqueueAssetsIfNeeded(): void
    {
        // no-op for now
    }
}
