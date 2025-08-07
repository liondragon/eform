<?php
// includes/mail-error-logger.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles logging for mail errors and PHPMailer debug output.
 */
class Mail_Error_Logger {
    /**
     * Logger instance.
     *
     * @var Logger
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param Logger $logger Logger instance for writing logs.
     */
    public function __construct( Logger $logger ) {
        $this->logger = $logger;

        add_action( 'wp_mail_failed', [ $this, 'log_mail_failure' ] );
        add_action( 'phpmailer_init', [ $this, 'maybe_enable_phpmailer_debug' ] );
    }

    /**
     * Logs WP mail errors using Logger::LEVEL_ERROR.
     *
     * @param WP_Error $wp_error The WordPress error object.
     * @return void
     */
    public function log_mail_failure( $wp_error ) {
        if ( is_wp_error( $wp_error ) ) {
            $data = $wp_error->get_error_data();
            $this->logger->log(
                'Mail send failure',
                Logger::LEVEL_ERROR,
                [
                    'error'   => $wp_error->get_error_message(),
                    'details' => is_array( $data ) ? $data : [],
                ]
            );
        }
    }

    /**
     * Enables PHPMailer debugging when DEBUG_LEVEL is 3.
     * Logs output using Logger::LEVEL_INFO.
     *
     * @param PHPMailer $phpmailer PHPMailer instance.
     * @return void
     */
    public function maybe_enable_phpmailer_debug( $phpmailer ) {
        if ( defined( 'DEBUG_LEVEL' ) && DEBUG_LEVEL === 3 ) {
            $phpmailer->SMTPDebug  = 3;
            $phpmailer->Debugoutput = function ( $str, $level ) {
                $this->logger->log( 'PHPMailer Debug', Logger::LEVEL_INFO, [ 'debug' => $str, 'phpmailer_level' => $level ] );
            };
        }
    }
}
