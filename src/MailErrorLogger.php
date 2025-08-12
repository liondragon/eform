<?php
// src/MailErrorLogger.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles logging for mail errors and PHPMailer debug output.
 */
class Mail_Error_Logger {
    /**
     * Logging instance.
     *
     * @var Logging
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param Logging $logger Logging instance for writing logs.
     */
    public function __construct( Logging $logger ) {
        $this->logger = $logger;

        add_action( 'wp_mail_failed', [ $this, 'log_mail_failure' ] );
        add_action( 'phpmailer_init', [ $this, 'maybe_enable_phpmailer_debug' ] );
    }

    /**
     * Logs WP mail errors using Logging::LEVEL_ERROR.
     *
     * @param WP_Error $wp_error The WordPress error object.
     * @return void
     */
    public function log_mail_failure( $wp_error ) {
        if ( is_wp_error( $wp_error ) && $this->logger ) {
            $data = $wp_error->get_error_data();
            $this->logger->log(
                'Mail send failure',
                Logging::LEVEL_ERROR,
                [
                    'error'   => $wp_error->get_error_message(),
                    'details' => is_array( $data ) ? $data : [],
                ]
            );
        }
    }

    /**
     * Enables PHPMailer debugging when DEBUG_LEVEL is 3.
     * Logs output using Logging::LEVEL_INFO.
     *
     * @param PHPMailer $phpmailer PHPMailer instance.
     * @return void
     */
    public function maybe_enable_phpmailer_debug( $phpmailer ) {
        if ( defined( 'DEBUG_LEVEL' ) && DEBUG_LEVEL === 3 && $this->logger ) {
            $phpmailer->SMTPDebug  = 3;
            $logger = $this->logger;
            $phpmailer->Debugoutput = function ( $str, $level ) use ( $logger ) {
                if ( $logger ) {
                    $logger->log( 'PHPMailer Debug', Logging::LEVEL_INFO, [ 'debug' => $str, 'phpmailer_level' => $level ] );
                }
            };
        }
    }
}
