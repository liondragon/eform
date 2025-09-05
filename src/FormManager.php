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

        // Enqueue assets only when rendering
        $this->enqueueAssetsIfNeeded();

        $clientValidation = (bool) Config::get('html5.client_validation', false);

        $html = sprintf("<!-- eforms: form_id=%s cacheable=%s -->\n", \esc_html($formId), $cacheable ? 'true' : 'false');
        $html .= '<form method="post"' . ($clientValidation ? '' : ' novalidate') . ' action="' . \esc_url(\home_url('/eforms/submit')) . '">';
        $html .= sprintf('<input type="hidden" name="form_id" value="%s">', \esc_attr($formId));
        $html .= sprintf('<input type="hidden" name="instance_id" value="%s">', \esc_attr($instanceId));
        $html .= '<input type="hidden" name="eforms_hp" value="">';
        $html .= sprintf('<input type="hidden" name="timestamp" value="%d">', $timestamp);
        $html .= '<input type="hidden" name="js_ok" value="0">';
        if (!$cacheable) {
            $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $instanceId;
            $html .= sprintf('<input type="hidden" name="eforms_token" value="%s">', \esc_attr($token));
        }
        if ($cacheable) {
            // Prime cookie token via 204 pixel
            $html .= sprintf('<img src="%s" aria-hidden="true" alt="" width="1" height="1" style="position:absolute;left:-9999px;">',
                \esc_url(\home_url('/eforms/prime?f=' . $formId))
            );
        }
        $html .= '</form>';
        return $html;
    }

    public function enqueueAssetsIfNeeded(): void
    {
        // Register
        \wp_register_style(
            'eforms-forms',
            \plugins_url('assets/forms.css', \EForms\PLUGIN_DIR . '/eforms.php'),
            [],
            @filemtime(\EForms\ASSETS_DIR . '/forms.css') ?: \EForms\VERSION
        );
        \wp_register_script(
            'eforms-forms',
            \plugins_url('assets/forms.js', \EForms\PLUGIN_DIR . '/eforms.php'),
            [],
            @filemtime(\EForms\ASSETS_DIR . '/forms.js') ?: \EForms\VERSION,
            ['in_footer' => true]
        );
        // Enqueue
        if (!Config::get('assets.css_disable', false)) {
            \wp_enqueue_style('eforms-forms');
        }
        \wp_enqueue_script('eforms-forms');
    }
}
