<?php
// src/Logging.php

class Logging {
    /**
     * Log level for informational messages.
     */
    public const LEVEL_INFO = 'info';

    /**
     * Log level for warnings.
     */
    public const LEVEL_WARNING = 'warning';

    /**
     * Log level for errors.
     */
    public const LEVEL_ERROR = 'error';

    /**
     * Default maximum log file size in bytes before rotation occurs.
     */
    public const DEFAULT_MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * Default number of days to retain rotated log files.
     */
    public const DEFAULT_RETENTION_DAYS = 30;

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
     * @param string     $level     Severity level (e.g. Logging::LEVEL_INFO, Logging::LEVEL_WARNING, Logging::LEVEL_ERROR).
     * @param array      $context   Additional context to record.
     * @param array|null $form_data Optional form data for safe logging.
     */
    public function log( $message, $level = self::LEVEL_INFO, $context = [], $form_data = null ) {
        $this->prepare_log_file();
        $context = $this->format_context( $message, $level, $context, $form_data );
        $this->write_log_entry( $context );
    }

    /**
     * Assemble the log context.
     *
     * @param string     $message   Human readable message.
     * @param string     $level     Severity level.
     * @param array      $context   Additional context to record.
     * @param array|null $form_data Optional form data for safe logging.
     * @return array                 Complete log context.
     */
    private function format_context( $message, $level, array $context, $form_data ) {
        $server = $_SERVER;

        $safe_data = $this->get_safe_form_data( $form_data );
        if ( null !== $safe_data ) {
            $context['form_data'] = $safe_data;
        }

        $context['timestamp']   = date( 'c' );
        $ip = $this->get_ip();
        if ( null !== $ip ) {
            $context['ip'] = $ip;
        }
        $context['source']      = 'Enhanced iContact Form';
        $context['level']       = $level;
        $context['message']     = $message;
        $context['user_agent']  = isset( $server['HTTP_USER_AGENT'] ) ? sanitize_text_field( $server['HTTP_USER_AGENT'] ) : '';
        $context['referrer']    = isset( $server['HTTP_REFERER'] ) ? sanitize_text_field( $server['HTTP_REFERER'] ) : 'No referrer';
        $context['request_uri'] = isset( $server['REQUEST_URI'] ) ? sanitize_text_field( $server['REQUEST_URI'] ) : '';
        if ( isset( $context['template'] ) ) {
            $context['template'] = sanitize_text_field( $context['template'] );
        }

        return $context;
    }

    /**
     * Write a context array to the log file.
     *
     * @param array $context The context to log.
     */
    private function write_log_entry( array $context ) {
        $log_file     = $this->log_file;
        $jsonLogEntry = wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            $jsonLogEntry = 'Log encoding error: ' . json_last_error_msg();
        }

        if ( ! empty( $log_file ) && is_writable( dirname( $log_file ) ) ) {
            $written = ( false !== file_put_contents( $log_file, $jsonLogEntry . "\n", FILE_APPEND | LOCK_EX ) );
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
        $default_dir = dirname( WP_CONTENT_DIR ) . '/eform-logs';
        $log_dir     = defined( 'EFORM_LOG_DIR' ) ? EFORM_LOG_DIR : $default_dir;
        if ( function_exists( 'apply_filters' ) ) {
            $log_dir = apply_filters( 'eform_log_dir', $log_dir );
        }

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        $this->create_deny_files( $log_dir );

        if ( empty( $this->log_file ) ) {
            $this->log_file = $log_dir . '/forms.jsonl';
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
            $rotated_file = $log_dir . '/forms-' . $timestamp . '.jsonl';
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
     * Create web server deny files to block direct access to logs.
     *
     * @param string $log_dir Directory containing log files.
     */
    private function create_deny_files( $log_dir ) {
        $files = [
            '.htaccess'  => "Require all denied\n",
            'index.html' => '',
        ];

        foreach ( $files as $name => $contents ) {
            $path = $log_dir . '/' . $name;
            if ( ! file_exists( $path ) ) {
                file_put_contents( $path, $contents, LOCK_EX );
            }
        }
    }

    /**
     * Delete rotated log files older than the configured retention period.
     *
     * @param string $log_dir Directory containing the log files.
     */
    private function purge_old_logs( $log_dir ) {
        $retention_days = defined( 'EFORM_LOG_RETENTION_DAYS' ) ? EFORM_LOG_RETENTION_DAYS : self::DEFAULT_RETENTION_DAYS;
        if ( function_exists( 'apply_filters' ) ) {
            $retention_days = apply_filters( 'eform_log_retention_days', $retention_days, $log_dir );
        }

        $retention_days = (int) $retention_days;
        if ( $retention_days <= 0 ) {
            return;
        }

        $day_in_seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
        $threshold      = time() - ( $retention_days * $day_in_seconds );

        foreach ( glob( $log_dir . '/forms-*.jsonl' ) as $file ) {
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

        $max_size = defined( 'EFORM_LOG_FILE_MAX_SIZE' ) ? EFORM_LOG_FILE_MAX_SIZE : self::DEFAULT_MAX_FILE_SIZE;
        if ( function_exists( 'apply_filters' ) ) {
            $max_size = apply_filters( 'eform_log_file_max_size', $max_size, $file );
        }

        clearstatcache( true, $file );
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

        $ip = null;

        foreach ( $candidates as $key ) {
            if ( ! empty( $server[ $key ] ) ) {
                $ipList = explode( ',', $server[ $key ] );
                foreach ( $ipList as $candidate ) {
                    $candidate = trim( $candidate );

                    // Filter out local/private/reserved IPs
                    if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                        $ip = $candidate;
                        break 2;
                    }
                }
            }
        }

        // Fallback (still return something, even if private)
        if ( null === $ip ) {
            foreach ( $candidates as $key ) {
                if ( ! empty( $server[ $key ] ) ) {
                    $ipList = explode( ',', $server[ $key ] );
                    foreach ( $ipList as $candidate ) {
                        $candidate = trim( $candidate );
                        if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                            $ip = $candidate;
                            break 2;
                        }
                    }
                }
            }
        }

        if ( null === $ip ) {
            $ip = 'UNKNOWN';
        }

        $mode = defined( 'EFORMS_IP_MODE' ) ? EFORMS_IP_MODE : 'masked';

        if ( 'none' === $mode ) {
            return null;
        }

        if ( 'UNKNOWN' === $ip ) {
            return $ip;
        }

        if ( 'hash' === $mode ) {
            $salt = defined( 'EFORMS_IP_SALT' ) ? EFORMS_IP_SALT : '';
            return hash( 'sha256', $ip . $salt );
        }

        if ( 'full' === $mode ) {
            return $ip;
        }

        // Default: masked
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $parts    = explode( '.', $ip );
            $parts[3] = '0';
            return implode( '.', $parts );
        }

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $parts = explode( ':', $ip );
            for ( $i = 4; $i < count( $parts ); $i++ ) {
                $parts[ $i ] = '0000';
            }
            return implode( ':', $parts );
        }

        return $ip;
    }
}

