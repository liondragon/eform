<?php
// includes/logger.php

if ( ! function_exists( 'eform_get_safe_fields' ) ) {
    /**
     * Retrieve fields considered safe for logging.
     *
     * Allows overriding via the `eform_log_safe_fields` option or filter.
     *
     * @param array|null $form_data Optional form data for filter context.
     * @return array List of safe field keys.
     */
    function eform_get_safe_fields( $form_data = null ) {
        $safe_fields = [ 'name', 'zip' ];
        if ( function_exists( 'get_option' ) ) {
            $option_fields = get_option( 'eform_log_safe_fields', [] );
            if ( ! empty( $option_fields ) && is_array( $option_fields ) ) {
                $safe_fields = $option_fields;
            }
        }
        if ( function_exists( 'apply_filters' ) ) {
            $safe_fields = apply_filters( 'eform_log_safe_fields', $safe_fields, $form_data );
        }
        return $safe_fields;
    }
}

class Logger {
    /**
     * Cached path to the log file.
     *
     * @var string|null
     */
    private $log_file;

    /**
     * Write a log entry.
     *
     * @param string     $message   Human readable message.
     * @param string     $level     Severity level (e.g. info, warning, error).
     * @param array      $context   Additional context to record.
     * @param array|null $form_data Optional form data for safe logging.
     */
    public function log($message, $level = 'info', $context = [], $form_data = null) {
        $server = $_SERVER;

        // Prepare the log file if it hasn't been prepared yet, rotation is needed,
        // or the file path is missing/unwritable.
        if (
            empty( $this->log_file ) ||
            $this->should_rotate() ||
            ! file_exists( $this->log_file ) ||
            ! is_writable( dirname( $this->log_file ) )
        ) {
            $this->prepare_log_file();
        }

        $log_file = $this->log_file;

        $safe_data = $this->get_safe_form_data( $form_data );
        if ( null !== $safe_data ) {
            $context['form_data'] = $safe_data;
        }

        $context['timestamp'] = date('c');
        $context['ip']        = $this->get_ip();
        $context['source']    = 'Enhanced iContact Form';
        $context['level']     = $level;
        $context['message']   = $message;
        $context['user_agent'] = isset($server['HTTP_USER_AGENT']) ? sanitize_text_field($server['HTTP_USER_AGENT']) : '';
        $context['referrer']  = isset($server['HTTP_REFERER']) ? sanitize_text_field($server['HTTP_REFERER']) : 'No referrer';

        $jsonLogEntry = json_encode($context, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonLogEntry = 'Log encoding error: ' . json_last_error_msg();
        }

        if ( ! empty( $log_file ) && is_writable( dirname( $log_file ) ) ) {
            $written = false;
            $handle  = fopen( $log_file, 'a' );
            if ( $handle ) {
                if ( flock( $handle, LOCK_EX ) ) {
                    $written = ( false !== fwrite( $handle, $jsonLogEntry . "\n" ) );
                    flock( $handle, LOCK_UN );
                }
                fclose( $handle );
            }
            if ( ! $written ) {
                error_log( $jsonLogEntry );
            } else {
                $perms = fileperms( $log_file ) & 0777;
                if ( 0640 !== $perms ) {
                    if ( ! chmod( $log_file, 0640 ) ) { // Restrict log file permissions
                        error_log( 'Failed to set permissions on log file: ' . $log_file );
                    }
                }
            }
        } else {
            error_log( $jsonLogEntry );
        }
    }

    /**
     * Prepare the log file for writing, including directory creation and rotation.
     *
     * @return string Path to the log file.
     */
    private function prepare_log_file() {
        $log_dir = WP_CONTENT_DIR . '/uploads/logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        if ( empty( $this->log_file ) ) {
            $this->log_file = $log_dir . '/forms.log';
        }

        if ( ! file_exists( $this->log_file ) ) {
            if ( ! touch( $this->log_file ) ) {
                error_log( 'Failed to create log file: ' . $this->log_file );
            }
            if ( ! chmod( $this->log_file, 0640 ) ) {
                error_log( 'Failed to set permissions on log file: ' . $this->log_file );
            }
        }

        if ( $this->should_rotate() ) {
            $timestamp    = date( 'YmdHis' );
            $rotated_file = $log_dir . '/forms-' . $timestamp . '.log';
            if ( ! rename( $this->log_file, $rotated_file ) ) {
                error_log( 'Failed to rotate log file: ' . $this->log_file );
            }
            if ( ! touch( $this->log_file ) ) {
                error_log( 'Failed to create new log file after rotation: ' . $this->log_file );
            }
            if ( ! chmod( $this->log_file, 0640 ) ) {
                error_log( 'Failed to set permissions on log file: ' . $this->log_file );
            }

            $this->purge_old_logs( $log_dir );
        }

        return $this->log_file;
    }

    /**
     * Delete rotated log files older than the configured retention period.
     *
     * @param string $log_dir Directory containing the log files.
     */
    private function purge_old_logs( $log_dir ) {
        $retention_days = defined( 'EFORM_LOG_RETENTION_DAYS' ) ? EFORM_LOG_RETENTION_DAYS : 30;
        if ( function_exists( 'apply_filters' ) ) {
            $retention_days = apply_filters( 'eform_log_retention_days', $retention_days, $log_dir );
        }

        $retention_days = (int) $retention_days;
        if ( $retention_days <= 0 ) {
            return;
        }

        $day_in_seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
        $threshold      = time() - ( $retention_days * $day_in_seconds );

        foreach ( glob( $log_dir . '/forms-*.log' ) as $file ) {
            if ( filemtime( $file ) < $threshold ) {
                @unlink( $file );
            }
        }
    }

    /**
     * Determine whether the current log file should be rotated based on size.
     *
     * @param string|null $file Optional path to check; defaults to the current log file.
     * @return bool True if rotation is needed, otherwise false.
     */
    private function should_rotate( $file = null ) {
        $file = $file ?: $this->log_file;

        if ( empty( $file ) ) {
            return false;
        }

        $max_size = defined( 'EFORM_LOG_FILE_MAX_SIZE' ) ? EFORM_LOG_FILE_MAX_SIZE : 5 * 1024 * 1024;
        if ( function_exists( 'apply_filters' ) ) {
            $max_size = apply_filters( 'eform_log_file_max_size', $max_size, $file );
        }

        return file_exists( $file ) && filesize( $file ) >= $max_size;
    }

    /**
     * Retrieve sanitized form data containing only safe fields.
     *
     * @param array|null $form_data Raw form submission data.
     * @return array|null Filtered form data or null if not applicable.
     */
    private function get_safe_form_data( $form_data ) {
        if ( defined( 'DEBUG_LEVEL' ) && DEBUG_LEVEL == 2 && $form_data !== null ) {
            $safe_fields = eform_get_safe_fields( $form_data );
            return array_intersect_key( $form_data, array_flip( $safe_fields ) );
        }

        return null;
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

