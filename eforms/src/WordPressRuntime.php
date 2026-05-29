<?php
/**
 * Fail-closed wrappers for required WordPress runtime APIs.
 */

class WordPressRuntime {
    public static function safe_redirect( $url, $status ) {
        if ( ! function_exists( 'wp_safe_redirect' ) ) {
            return false;
        }

        return wp_safe_redirect( $url, $status ) === true;
    }
}
