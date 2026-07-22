<?php
/**
 * Stable public error-code messages.
 *
 * Contract: Error handling
 */

class ErrorMessages {
    const EMAIL_SEND = 'We couldn\'t send your request right now, so it may not have reached us. Please try again in a few minutes. If the issue keeps happening, call 720.900.5278 or message us directly.';

    /**
     * Resolve a stable public message for an error code.
     *
     * @param string $code Stable error code.
     * @return string Public message.
     */
    public static function message( $code ) {
        if ( $code === 'EFORMS_ERR_STORAGE_UNAVAILABLE' ) {
            return 'Form configuration error: server storage is unavailable.';
        }

        if ( $code === 'EFORMS_ERR_DUPLICATE_FORM_ID' ) {
            return 'Form configuration error: duplicate form id on page.';
        }

        if ( $code === 'EFORMS_ERR_THROTTLED' ) {
            return 'Please wait a moment and try again.';
        }

        if ( $code === 'EFORMS_ERR_TOKEN' ) {
            return 'This form was already submitted or has expired - please reload the page.';
        }

        if ( $code === 'EFORMS_ERR_EMAIL_SEND' ) {
            return self::EMAIL_SEND;
        }

        if ( $code === 'EFORMS_ERR_CHALLENGE_FAILED' ) {
            return 'Please complete the verification and submit again.';
        }

        if ( $code === 'EFORMS_ERR_FIELD_REQUIRED' ) {
            return 'Please complete this field.';
        }

        if ( $code === 'EFORMS_ERR_ONE_OF_REQUIRED' ) {
            return 'Please complete at least one of these fields.';
        }

        if ( $code === 'EFORMS_ERR_MUTUALLY_EXCLUSIVE' ) {
            return 'Please provide only one of these fields.';
        }

        if ( $code === 'EFORMS_ERR_FIELD_INVALID' ) {
            return 'Please check this field.';
        }

        return 'Form configuration error.';
    }

    /**
     * Resolve the visitor-facing field label used by field error copy.
     *
     * @param array $field Validated field descriptor/template field.
     * @param string $field_key Field key fallback.
     * @return string Visitor-facing field label.
     */
    public static function field_label_text( $field, $field_key ) {
        if ( is_array( $field ) && isset( $field['label'] ) && is_string( $field['label'] ) && $field['label'] !== '' ) {
            return $field['label'];
        }

        if ( ! is_string( $field_key ) || $field_key === '' ) {
            return 'Field';
        }

        $label = str_replace( array( '_', '-' ), ' ', $field_key );
        $label = preg_replace( '/\s+/', ' ', $label );
        $label = trim( $label );

        return ucwords( $label );
    }

    /**
     * Resolve the most specific field label for error messages.
     *
     * @param array $field Validated field descriptor/template field.
     * @param string $field_key Field key fallback.
     * @return string Visitor-facing field error label.
     */
    public static function field_error_label_text( $field, $field_key ) {
        $label = self::field_label_text( $field, $field_key );
        $placeholder = is_array( $field ) && isset( $field['placeholder'] ) && is_string( $field['placeholder'] )
            ? self::normalize_label_text( $field['placeholder'] )
            : '';
        if ( $placeholder !== '' && stripos( $placeholder, $label ) === 0 && strlen( $placeholder ) > strlen( $label ) ) {
            return $placeholder;
        }

        return $label;
    }

    /**
     * Resolve public copy for a field-scoped error when label/type context is available.
     *
     * @param string $code Stable error code.
     * @param string $label_text Visitor-facing field label.
     * @param string $field_type Validated field descriptor type.
     * @return string Public message.
     */
    public static function field_message( $code, $label_text, $field_type = '' ) {
        $label_text = trim( preg_replace( '/\s+/', ' ', (string) $label_text ) );
        $field_type = is_string( $field_type ) ? $field_type : '';
        if ( $label_text === '' ) {
            return self::message( $code );
        }

        if ( $code === 'EFORMS_ERR_FIELD_REQUIRED' ) {
            return 'Please complete ' . $label_text . '.';
        }

        if ( $code === 'EFORMS_ERR_FIELD_INVALID' ) {
            if ( $field_type === 'email' ) {
                return $label_text . ' must be a valid email address.';
            }

            if ( $field_type === 'tel_us' ) {
                return $label_text . ' must be a valid phone number.';
            }

            if ( $field_type === 'zip_us' ) {
                return $label_text . ' must be a valid 5-digit ZIP code.';
            }

            if ( $field_type === 'url' ) {
                return $label_text . ' must be a valid URL.';
            }

            if ( $field_type === 'number' || $field_type === 'range' ) {
                return $label_text . ' must be a valid number.';
            }

            if ( $field_type === 'date' ) {
                return $label_text . ' must be a valid date.';
            }

            return $label_text . ' is invalid.';
        }

        return self::message( $code );
    }

    private static function normalize_label_text( $label ) {
        $label = preg_replace( '/\s*\*\s*$/', '', (string) $label );
        $label = preg_replace( '/\s+/', ' ', $label );
        return trim( $label );
    }
}
