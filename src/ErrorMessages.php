<?php
/**
 * Stable public error-code messages.
 *
 * Spec: Error handling (docs/Canonical_Spec.md#sec-error-handling)
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

        return 'Form configuration error.';
    }
}
