<?php
// includes/class-validation-exception.php

class ValidationException extends \Exception {
    /**
     * Underlying WP_Error instance.
     *
     * @var WP_Error
     */
    private $error;

    public function __construct( WP_Error $error ) {
        $this->error = $error;
        parent::__construct( $error->get_error_message() );
    }

    /**
     * Retrieve the wrapped WP_Error instance.
     *
     * @return WP_Error
     */
    public function get_error(): WP_Error {
        return $this->error;
    }
}
