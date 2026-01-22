<?php
/**
 * Email template rendering helpers.
 *
 * Educational note: templates are PHP files that receive structured inputs
 * and are rendered via output buffering for deterministic assembly.
 *
 * Spec: Email templates (docs/Canonical_Spec.md#sec-email-templates)
 */

class Templates {
    public static function render( $name, $is_html, $data, $base_dir = null ) {
        if ( ! is_string( $name ) || $name === '' ) {
            return array( 'ok' => false, 'reason' => 'template_name_invalid' );
        }

        $base = is_string( $base_dir ) && $base_dir !== ''
            ? rtrim( $base_dir, '/\\' )
            : rtrim( dirname( __DIR__, 2 ) . '/templates/email', '/\\' );

        $suffix = $is_html ? '.html.php' : '.txt.php';
        $path = $base . '/' . $name . $suffix;

        if ( ! is_file( $path ) ) {
            return array( 'ok' => false, 'reason' => 'template_missing', 'path' => $path );
        }

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', dirname( $path ) . '/' );
        }

        $canonical = array();
        $include_fields = array();
        $meta = array();
        $uploads = array();

        if ( is_array( $data ) ) {
            if ( isset( $data['canonical'] ) && is_array( $data['canonical'] ) ) {
                $canonical = $data['canonical'];
            }
            if ( isset( $data['include_fields'] ) && is_array( $data['include_fields'] ) ) {
                $include_fields = $data['include_fields'];
            }
            if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
                $meta = $data['meta'];
            }
            if ( isset( $data['uploads'] ) && is_array( $data['uploads'] ) ) {
                $uploads = $data['uploads'];
            }
        }

        ob_start();
        include $path;
        $body = ob_get_clean();

        if ( ! is_string( $body ) ) {
            $body = '';
        }

        return array(
            'ok' => true,
            'body' => $body,
            'path' => $path,
        );
    }
}
