<?php
/**
 * Private uploads directory hardening.
 *
 * Spec: Shared lifecycle and storage contract (docs/Canonical_Spec.md#sec-shared-lifecycle)
 */

class PrivateDir {
    const DIR_NAME = 'eforms-private';

    const INDEX_FILENAME = 'index.html';
    const HTACCESS_FILENAME = '.htaccess';
    const WEBCONFIG_FILENAME = 'web.config';

    const INDEX_CONTENT = '<!doctype html><title></title>';
    const HTACCESS_CONTENT = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    const WEBCONFIG_CONTENT = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n";

    /**
     * Resolve the private storage directory path.
     */
    public static function path( $uploads_dir ) {
        if ( ! is_string( $uploads_dir ) || $uploads_dir === '' ) {
            return '';
        }

        $base = rtrim( $uploads_dir, '/\\' );
        if ( $base === '' ) {
            return '';
        }

        return $base . '/' . self::DIR_NAME;
    }

    /**
     * Ensure the private storage directory and deny-rule files exist.
     */
    public static function ensure( $uploads_dir ) {
        $path = self::path( $uploads_dir );
        if ( $path === '' ) {
            return self::result( false, '', 'uploads_dir_missing' );
        }

        $base = rtrim( (string) $uploads_dir, '/\\' );
        if ( $base === '' || ! is_dir( $base ) || ! is_writable( $base ) ) {
            return self::result( false, $path, 'uploads_dir_unwritable' );
        }

        if ( ! self::ensure_dir( $path ) ) {
            return self::result( false, $path, 'private_dir_unavailable' );
        }

        $index_path = $path . '/' . self::INDEX_FILENAME;
        if ( ! self::ensure_file( $index_path, self::INDEX_CONTENT ) ) {
            return self::result( false, $path, 'private_dir_index_failed' );
        }

        $htaccess_path = $path . '/' . self::HTACCESS_FILENAME;
        if ( ! self::ensure_file( $htaccess_path, self::HTACCESS_CONTENT ) ) {
            return self::result( false, $path, 'private_dir_htaccess_failed' );
        }

        $webconfig_path = $path . '/' . self::WEBCONFIG_FILENAME;
        if ( ! self::ensure_file( $webconfig_path, self::WEBCONFIG_CONTENT ) ) {
            return self::result( false, $path, 'private_dir_webconfig_failed' );
        }

        return self::result( true, $path, '' );
    }

    private static function ensure_dir( $path ) {
        if ( is_dir( $path ) ) {
            return self::ensure_permissions( $path, 0700 );
        }

        $created = @mkdir( $path, 0700, true );
        if ( ! $created && ! is_dir( $path ) ) {
            return false;
        }

        return self::ensure_permissions( $path, 0700 );
    }

    private static function ensure_file( $path, $content ) {
        if ( file_exists( $path ) ) {
            if ( ! is_file( $path ) ) {
                return false;
            }

            return self::ensure_permissions( $path, 0600 );
        }

        // Use exclusive-create to avoid overwriting existing files.
        $handle = @fopen( $path, 'xb' );
        if ( $handle === false ) {
            return false;
        }

        $written = @fwrite( $handle, (string) $content );
        fclose( $handle );

        if ( $written === false ) {
            return false;
        }

        return self::ensure_permissions( $path, 0600 );
    }

    private static function ensure_permissions( $path, $mode ) {
        if ( @chmod( $path, $mode ) ) {
            return true;
        }

        return false;
    }

    private static function result( $ok, $path, $error ) {
        return array(
            'ok' => (bool) $ok,
            'path' => $path,
            'error' => $error,
        );
    }
}
