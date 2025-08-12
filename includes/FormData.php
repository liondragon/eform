<?php
// includes/FormData.php

/**
 * Basic data container for form state used during rendering.
 */
class FormData {
    /**
     * Collected form field values.
     *
     * @var array
     */
    public $form_data = [];

    /**
     * Validation errors keyed by field.
     *
     * @var array
     */
    public $field_errors = [];

    /**
     * Format a phone number for display.
     *
     * Subclasses may override to provide specific formatting logic.
     */
    public function format_phone( string $digits ): string {
        return $digits;
    }
}
