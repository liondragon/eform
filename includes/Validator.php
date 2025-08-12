<?php
// includes/Validator.php

class Validator {
    public function sanitize_submission( array $field_map, array $submitted_data ): array {
        $data           = [];
        $invalid_fields = [];

        foreach ( $field_map as $field => $details ) {
            $value = $submitted_data[ $field ] ?? '';
            if ( is_array( $value ) ) {
                $invalid_fields[] = $field;
                continue;
            }
            $sanitize_cb    = $details['sanitize_cb'];
            $data[ $field ] = $sanitize_cb( $value );
        }

        return [
            'data'           => $data,
            'invalid_fields' => $invalid_fields,
        ];
    }

    public function validate_submission( array $field_map, array $data ): array {
        $errors = [];

        foreach ( $field_map as $field => $details ) {
            $validate_cb = $details['validate_cb'];
            $error       = $validate_cb( $data[ $field ] ?? '', $details );
            if ( $error ) {
                $errors[ $field ] = $error;
            }
        }

        return $errors;
    }
}
