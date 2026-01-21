<?php
/**
 * Template loader for shipped JSON form templates.
 *
 * Educational note: this loader only handles file I/O + JSON decoding and a
 * minimal version gate. Structural validation happens in TemplateValidator.
 *
 * Spec: Template JSON (docs/Canonical_Spec.md#sec-template-json)
 * Spec: Versioning & cache keys (docs/Canonical_Spec.md#sec-template-versioning)
 */

require_once __DIR__ . '/../Errors.php';

class TemplateLoader {
    const SLUG_PATTERN = '/^[a-z0-9-]+$/';

    /**
     * Load a template by slug.
     *
     * @param string $form_id Filename stem under templates/forms.
     * @param string|null $base_dir Optional override for tests.
     * @return array { ok, template, version, path, errors }
     */
    public static function load( $form_id, $base_dir = null ) {
        $errors = new Errors();

        if ( ! self::is_allowed_slug( $form_id ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_KEY' );
            return self::result( false, null, null, null, $errors );
        }

        $dir = $base_dir;
        if ( ! is_string( $dir ) || $dir === '' ) {
            $dir = self::default_base_dir();
        }

        if ( ! is_dir( $dir ) ) {
            $errors->add_global( 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
            return self::result( false, null, null, null, $errors );
        }

        $path = rtrim( $dir, '/\\' ) . '/' . $form_id . '.json';
        if ( ! is_readable( $path ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_REQUIRED' );
            return self::result( false, null, null, $path, $errors );
        }

        $raw = file_get_contents( $path );
        if ( $raw === false ) {
            $errors->add_global( 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
            return self::result( false, null, null, $path, $errors );
        }

        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
            return self::result( false, null, null, $path, $errors );
        }

        $version = self::normalize_version( $decoded, $path, $errors );
        if ( $version === null ) {
            return self::result( false, null, null, $path, $errors );
        }

        return self::result( true, $decoded, $version, $path, $errors );
    }

    private static function is_allowed_slug( $form_id ) {
        return is_string( $form_id ) && $form_id !== '' && preg_match( self::SLUG_PATTERN, $form_id ) === 1;
    }

    private static function default_base_dir() {
        return dirname( __DIR__, 2 ) . '/templates/forms';
    }

    private static function normalize_version( $template, $path, $errors ) {
        if ( isset( $template['version'] ) ) {
            if ( ! is_string( $template['version'] ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_TYPE' );
                return null;
            }

            return $template['version'];
        }

        $mtime = @filemtime( $path );
        if ( $mtime === false ) {
            return '0';
        }

        return (string) $mtime;
    }

    private static function result( $ok, $template, $version, $path, $errors ) {
        return array(
            'ok'       => (bool) $ok,
            'template' => $template,
            'version'  => $version,
            'path'     => $path,
            'errors'   => $errors,
        );
    }
}

