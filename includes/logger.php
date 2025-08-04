<?php
// includes/logger.php

class Logger {
    public function log($message, $context = [], $form_data = null) {
        $server   = $_SERVER;
        $log_file = WP_CONTENT_DIR . '/forms.log';

        if (defined('DEBUG_LEVEL') && DEBUG_LEVEL == 2 && $form_data !== null) {
            $safe_data         = array_intersect_key($form_data, array_flip(['name', 'zip']));
            $context['form_data'] = $safe_data;
        }

        $context['ip']        = $this->get_ip();
        $context['source']    = 'Enhanced iContact Form';
        $context['message']   = $message;
        $context['user_agent'] = isset($server['HTTP_USER_AGENT']) ? sanitize_text_field($server['HTTP_USER_AGENT']) : '';
        $context['referrer']  = isset($server['HTTP_REFERER']) ? sanitize_text_field($server['HTTP_REFERER']) : 'No referrer';

        $jsonLogEntry = json_encode($context, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonLogEntry = 'Log encoding error: ' . json_last_error_msg();
        }

        if (!empty($log_file) && is_writable(dirname($log_file))) {
            error_log($jsonLogEntry . "\n", 3, $log_file);
        } else {
            error_log($jsonLogEntry);
        }
    }

    public function get_ip() {
        $server = $_SERVER;
        $candidates = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'REMOTE_ADDR'
        ];

        foreach ($candidates as $key) {
            if (!empty($server[$key])) {
                $ipList = explode(',', $server[$key]);
                foreach ($ipList as $ip) {
                    $ip = trim($ip);

                    // Filter out local/private/reserved IPs
                    if (
                        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    ) {
                        return $ip;
                    }
                }
            }
        }

        // Fallback (still return something, even if private)
        foreach ($candidates as $key) {
            if (!empty($server[$key])) {
                $ipList = explode(',', $server[$key]);
                foreach ($ipList as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return 'UNKNOWN';
    }
}

