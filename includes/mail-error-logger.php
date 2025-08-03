<?php
// includes/mail-error-logger.php

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_mail_failed', function ($wp_error) {
    if (is_wp_error($wp_error)) {
        $data = $wp_error->get_error_data();
        enhanced_icf_log('Mail send failure', [
            'error'   => $wp_error->get_error_message(),
            'details' => is_array($data) ? $data : [],
        ]);
    }
});

add_action('phpmailer_init', function ($phpmailer) {
    if (defined('DEBUG_LEVEL') && DEBUG_LEVEL === 3) {
        $phpmailer->SMTPDebug = 3;
        $phpmailer->Debugoutput = function ($str, $level) {
            enhanced_icf_log('PHPMailer Debug', ['debug' => $str, 'level' => $level]);
        };
    }
});
