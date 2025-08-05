<?php
// includes/logger.php

class Logger {
    public function log($message, $context = [], $form_data = null) {
        $server = $_SERVER;

        // Store logs in a location outside of the plugin directory.
        // Using uploads/logs keeps the file out of typical web-accessible paths.
        $log_dir  = WP_CONTENT_DIR . '/uploads/logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        $log_file = $log_dir . '/forms.log';
        if ( ! file_exists( $log_file ) ) {
            touch( $log_file );
            @chmod( $log_file, 0640 ); // Ensure restrictive permissions on creation
        }

        if (defined('DEBUG_LEVEL') && DEBUG_LEVEL == 2 && $form_data !== null) {
            $safe_data         = array_intersect_key($form_data, array_flip(['name', 'zip']));
            $context['form_data'] = $safe_data;
        }

        $context['timestamp'] = date('c');
        $context['ip']        = $this->get_ip();
        $context['source']    = 'Enhanced iContact Form';
        $context['message']   = $message;
        $context['user_agent'] = isset($server['HTTP_USER_AGENT']) ? sanitize_text_field($server['HTTP_USER_AGENT']) : '';
        $context['referrer']  = isset($server['HTTP_REFERER']) ? sanitize_text_field($server['HTTP_REFERER']) : 'No referrer';

        $jsonLogEntry = json_encode($context, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonLogEntry = 'Log encoding error: ' . json_last_error_msg();
        }

        if ( ! empty( $log_file ) && is_writable( dirname( $log_file ) ) ) {
            error_log( $jsonLogEntry . "\n", 3, $log_file );
            $perms = fileperms( $log_file ) & 0777;
            if ( 0640 !== $perms ) {
                @chmod( $log_file, 0640 ); // Restrict log file permissions
            }
        } else {
            error_log( $jsonLogEntry );
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

