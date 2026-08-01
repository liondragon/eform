<?php
/**
 * Private uploads directory hardening.
 *
 * Contract: Shared lifecycle and storage contract
 */

class PrivateDirLease {
    private $handle;
    private $private_dir;
    private $exclusive;

    public function __construct( $handle, $private_dir, $exclusive ) {
        $this->handle = $handle;
        $this->private_dir = $private_dir;
        $this->exclusive = (bool) $exclusive;
    }

    public function private_dir() {
        return $this->private_dir;
    }

    public function exclusive() {
        return $this->exclusive;
    }

    public function release() {
        if ( is_resource( $this->handle ) ) {
            @flock( $this->handle, LOCK_UN );
            fclose( $this->handle );
        }
        $this->handle = null;
    }

    public function __destruct() {
        $this->release();
    }
}

class PrivateDir {
    const DIR_NAME = 'eforms-private';
    const DIRECTORY_MODE = 0700;
    const FILE_MODE = 0600;
    const REVIEW_DIRECTORY_MODE = 0750;
    const REVIEW_FILE_MODE = 0640;
    const LIFECYCLE_LOCK_FILENAME = 'upload-lifecycle.lock';
    const PURGE_MARKER_FILENAME = 'managed-purged';

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
        $private = self::ensure_root( $uploads_dir );
        if ( empty( $private['ok'] ) ) {
            return $private;
        }

        $path = $private['path'];
        foreach ( self::deny_file_specs() as $spec ) {
            if ( ! self::ensure_file( $path . '/' . $spec['filename'], $spec['content'] ) ) {
                return self::result( false, $path, $spec['error'] );
            }
        }

        return self::result( true, $path, '' );
    }

    public static function subdir( $uploads_dir, $name, $create = true ) {
        return self::subdir_path( $uploads_dir, $name, $create, false );
    }

    public static function protected_subdir( $uploads_dir, $name, $create = true ) {
        return self::subdir_path( $uploads_dir, $name, $create, true );
    }

    public static function protected_review_subdir( $uploads_dir, $name, $create = true ) {
        return self::subdir_path( $uploads_dir, $name, $create, true, self::REVIEW_DIRECTORY_MODE );
    }

    public static function existing_protected_subdir( $uploads_dir, $name ) {
        if ( ! is_string( $name ) || $name === '' || preg_match( '/[\\\\\\/]/', $name ) === 1 ) {
            return '';
        }

        $private_path = self::path( $uploads_dir );
        if ( $private_path === '' || is_link( $private_path ) || ! is_dir( $private_path ) ) {
            return '';
        }

        $path = rtrim( $private_path, '/\\' ) . '/' . $name;
        return ! is_link( $path ) && is_dir( $path ) && self::has_deny_files( $path ) ? $path : '';
    }

    public static function existing_protected_review_subdir( $uploads_dir, $name ) {
        $path = self::existing_protected_subdir( $uploads_dir, $name );
        $private_path = self::path( $uploads_dir );
        return $path !== ''
            && self::ensure_existing_review_directory( $private_path )
            && self::ensure_existing_review_directory( $path )
            ? $path
            : '';
    }

    public static function ensure_existing_review_directory( $path ) {
        return is_string( $path )
            && $path !== ''
            && ! is_link( $path )
            && is_dir( $path )
            && self::ensure_permissions( $path, self::REVIEW_DIRECTORY_MODE );
    }

    public static function ensure_existing_private_directory( $path ) {
        return is_string( $path )
            && $path !== ''
            && ! is_link( $path )
            && is_dir( $path )
            && self::ensure_permissions( $path, self::DIRECTORY_MODE );
    }

    public static function ensure_existing_review_file( $path ) {
        return self::ensure_existing_file_mode( $path, self::REVIEW_FILE_MODE );
    }

    public static function ensure_existing_private_file( $path ) {
        return self::ensure_existing_file_mode( $path, self::FILE_MODE );
    }

    public static function leased_subdir( $lease, $name, $create = true, $with_protection = false ) {
        if ( ! $lease instanceof PrivateDirLease ) {
            return '';
        }

        return self::subdir_at_private_path( $lease->private_dir(), $name, $create, $with_protection );
    }

    public static function leased_review_subdir( $lease, $name, $create = true, $with_protection = false ) {
        if ( ! $lease instanceof PrivateDirLease ) {
            return '';
        }

        return self::subdir_at_private_path( $lease->private_dir(), $name, $create, $with_protection, self::REVIEW_DIRECTORY_MODE );
    }

    public static function leased_relative_dir( $lease, $relative, $create = true ) {
        return self::leased_relative_dir_with_mode( $lease, $relative, $create, self::DIRECTORY_MODE );
    }

    private static function leased_relative_dir_with_mode( $lease, $relative, $create, $mode ) {
        if ( ! $lease instanceof PrivateDirLease || ! is_string( $relative ) || $relative === '' || strpos( $relative, '\\' ) !== false || $relative[0] === '/' ) {
            return '';
        }

        $path = $lease->private_dir();
        if ( ! is_string( $path ) || $path === '' || is_link( $path ) || ! is_dir( $path ) ) {
            return '';
        }

        foreach ( explode( '/', $relative ) as $part ) {
            if ( $part === '' || $part === '.' || $part === '..' ) {
                return '';
            }
            $path = rtrim( $path, '/\\' ) . '/' . $part;
            if ( is_link( $path ) ) {
                return '';
            }
            if ( is_dir( $path ) ) {
                if ( ! self::ensure_permissions( $path, $mode ) ) {
                    return '';
                }
                continue;
            }
            if ( ! $create || file_exists( $path ) || ! @mkdir( $path, $mode ) || is_link( $path ) || ! is_dir( $path ) || ! self::ensure_permissions( $path, $mode ) ) {
                return '';
            }
        }

        return $path;
    }

    /**
     * Resolve an existing relative directory without creating missing path
     * components. Missing paths are safe no-ops; links and non-directories are
     * reported separately so callers can fail closed.
     */
    public static function leased_existing_relative_dir_result( $lease, $relative ) {
        return self::leased_existing_relative_dir_result_with_mode( $lease, $relative, self::DIRECTORY_MODE );
    }

    public static function leased_existing_review_relative_dir_result( $lease, $relative ) {
        return self::leased_existing_relative_dir_result_with_mode( $lease, $relative, self::REVIEW_DIRECTORY_MODE );
    }

    private static function leased_existing_relative_dir_result_with_mode( $lease, $relative, $mode ) {
        if ( ! $lease instanceof PrivateDirLease || ! is_string( $relative ) || $relative === '' || strpos( $relative, '\\' ) !== false || $relative[0] === '/' ) {
            return array( 'ok' => false, 'exists' => false, 'path' => '' );
        }

        $path = $lease->private_dir();
        if ( ! is_string( $path ) || $path === '' || is_link( $path ) || ! is_dir( $path ) ) {
            return array( 'ok' => false, 'exists' => false, 'path' => '' );
        }

        foreach ( explode( '/', $relative ) as $part ) {
            if ( $part === '' || $part === '.' || $part === '..' ) {
                return array( 'ok' => false, 'exists' => false, 'path' => '' );
            }
            $path = rtrim( $path, '/\\' ) . '/' . $part;
            if ( is_link( $path ) || ( file_exists( $path ) && ! is_dir( $path ) ) ) {
                return array( 'ok' => false, 'exists' => false, 'path' => '' );
            }
            if ( ! file_exists( $path ) ) {
                return array( 'ok' => true, 'exists' => false, 'path' => '' );
            }
            if ( ! self::ensure_permissions( $path, $mode ) ) {
                return array( 'ok' => false, 'exists' => false, 'path' => '' );
            }
        }

        return array( 'ok' => true, 'exists' => true, 'path' => $path );
    }

    private static function subdir_path( $uploads_dir, $name, $create, $with_protection, $mode = self::DIRECTORY_MODE ) {
        if ( ! is_string( $name ) || $name === '' || preg_match( '/[\\\\\\/]/', $name ) === 1 ) {
            return '';
        }

        $private_path = '';
        if ( $create ) {
            $private = self::ensure( $uploads_dir );
            if ( ! is_array( $private ) || empty( $private['ok'] ) || empty( $private['path'] ) ) {
                return '';
            }
            $private_path = $private['path'];
        } else {
            $base = is_string( $uploads_dir ) ? rtrim( $uploads_dir, '/\\' ) : '';
            if ( $base === '' || ! is_dir( $base ) || ! is_writable( $base ) ) {
                return '';
            }
            $private_path = self::path( $uploads_dir );
            if ( $private_path === '' || ! is_dir( $private_path ) ) {
                return '';
            }
        }

        return self::subdir_at_private_path( $private_path, $name, $create, $with_protection, $mode );
    }

    private static function subdir_at_private_path( $private_path, $name, $create, $with_protection, $mode = self::DIRECTORY_MODE ) {
        if ( ! is_string( $name ) || $name === '' || preg_match( '/[\\\\\\/]/', $name ) === 1 ) {
            return '';
        }
        if ( ! is_string( $private_path ) || $private_path === '' || ! is_dir( $private_path ) || is_link( $private_path ) ) {
            return '';
        }

        $path = rtrim( $private_path, '/\\' ) . '/' . $name;
        if ( is_link( $path ) ) {
            return '';
        }

        if ( is_dir( $path ) ) {
            if ( ! self::ensure_permissions( $path, $mode ) ) {
                return '';
            }
            if ( $with_protection && ! self::ensure_deny_files( $path ) ) {
                return '';
            }
            return $path;
        }

        if ( ! $create ) {
            return '';
        }

        if ( ! self::ensure_dir( $path, $mode ) ) {
            return '';
        }

        if ( $with_protection && ! self::ensure_deny_files( $path ) ) {
            return '';
        }

        return $path;
    }

    public static function bounded_entries_result( $dir, $after, $limit, $directories_only = false, $name_pattern = '' ) {
        $limit = max( 0, (int) $limit );
        if ( $limit < 1 ) {
            return array( 'ok' => true, 'entries' => array(), 'reason' => '' );
        }
        if ( is_link( $dir ) ) {
            return array( 'ok' => false, 'entries' => array(), 'reason' => 'directory_invalid' );
        }
        if ( ! file_exists( $dir ) ) {
            return array( 'ok' => true, 'entries' => array(), 'reason' => '' );
        }
        if ( ! is_dir( $dir ) ) {
            return array( 'ok' => false, 'entries' => array(), 'reason' => 'directory_invalid' );
        }

        $entries = array();
        try {
            $iterator = new FilesystemIterator( $dir, FilesystemIterator::SKIP_DOTS );
            foreach ( $iterator as $entry ) {
                $name = $entry->getFilename();
                if ( strcmp( $name, $after ) <= 0
                    || $entry->isLink()
                    || ( $directories_only && ! $entry->isDir() )
                    || ( $name_pattern !== '' && preg_match( $name_pattern, $name ) !== 1 )
                ) {
                    continue;
                }
                $entries[] = $name;
                sort( $entries, SORT_STRING );
                if ( count( $entries ) > $limit ) {
                    array_pop( $entries );
                }
            }
        } catch ( Throwable $error ) {
            return array( 'ok' => false, 'entries' => array(), 'reason' => 'directory_enumeration_failed' );
        }
        return array( 'ok' => true, 'entries' => $entries, 'reason' => '' );
    }

    public static function acquire_write_lease( $uploads_dir ) {
        return self::acquire_lifecycle_lease( $uploads_dir, false, false, false );
    }

    public static function acquire_purge_lease( $uploads_dir ) {
        return self::acquire_lifecycle_lease( $uploads_dir, true, true, true );
    }

    public static function mark_purged( $lease ) {
        if ( ! $lease instanceof PrivateDirLease || ! $lease->exclusive() ) {
            return false;
        }
        $path = rtrim( $lease->private_dir(), '/\\' ) . '/' . self::PURGE_MARKER_FILENAME;
        if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
            return false;
        }
        $handle = @fopen( $path, 'c+b' );
        if ( $handle === false || ! @chmod( $path, self::FILE_MODE ) || ! @ftruncate( $handle, 0 ) ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
            return false;
        }
        $written = @fwrite( $handle, "purged\n" );
        $flushed = ! function_exists( 'fflush' ) || @fflush( $handle );
        fclose( $handle );
        return $written === 7 && $flushed;
    }

    public static function is_purged( $uploads_dir ) {
        $private_dir = self::path( $uploads_dir );
        if ( $private_dir === '' ) {
            return false;
        }
        $path = $private_dir . '/' . self::PURGE_MARKER_FILENAME;
        return file_exists( $path ) || is_link( $path );
    }

    public static function resume_after_install( $uploads_dir ) {
        $private_dir = self::path( $uploads_dir );
        if ( $private_dir === '' || ! is_dir( $private_dir ) ) {
            return true;
        }
        $lease = self::acquire_lifecycle_lease( $uploads_dir, true, false, true );
        if ( ! $lease instanceof PrivateDirLease ) {
            return false;
        }
        $marker = $private_dir . '/' . self::PURGE_MARKER_FILENAME;
        if ( ! file_exists( $marker ) && ! is_link( $marker ) ) {
            return true;
        }
        return is_file( $marker ) && ! is_link( $marker ) && @unlink( $marker );
    }

    private static function acquire_lifecycle_lease( $uploads_dir, $exclusive, $nonblocking, $allow_purged ) {
        $private_path = self::path( $uploads_dir );
        $base = is_string( $uploads_dir ) ? rtrim( $uploads_dir, '/\\' ) : '';
        if ( $private_path === '' || $base === '' || ! is_dir( $base ) || ! is_writable( $base ) || is_link( $private_path ) ) {
            return false;
        }
        if ( is_dir( $private_path ) ) {
            $private = self::result( true, $private_path, '' );
        } else {
            if ( file_exists( $private_path ) ) {
                return false;
            }
            $private = self::ensure_root( $uploads_dir );
        }
        if ( ! is_array( $private ) || empty( $private['ok'] ) || empty( $private['path'] ) ) {
            return false;
        }

        $lock_path = rtrim( $private['path'], '/\\' ) . '/' . self::LIFECYCLE_LOCK_FILENAME;
        if ( is_link( $lock_path ) || ( file_exists( $lock_path ) && ! is_file( $lock_path ) ) ) {
            return false;
        }
        $handle = @fopen( $lock_path, 'c+b' );
        if ( $handle === false ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
            return false;
        }
        $operation = $exclusive ? LOCK_EX : LOCK_SH;
        if ( $nonblocking ) {
            $operation |= LOCK_NB;
        }
        if ( ! @flock( $handle, $operation ) ) {
            fclose( $handle );
            return false;
        }
        if ( ! self::ensure_existing_review_directory( $private['path'] ) || ! @chmod( $lock_path, self::FILE_MODE ) ) {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
            return false;
        }
        $marker = rtrim( $private['path'], '/\\' ) . '/' . self::PURGE_MARKER_FILENAME;
        $purged = file_exists( $marker ) || is_link( $marker );
        if ( ! $allow_purged && $purged ) {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
            return false;
        }
        if ( ! $purged && ! self::ensure_deny_files( $private['path'] ) ) {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
            return false;
        }
        return new PrivateDirLease( $handle, $private['path'], $exclusive );
    }

    private static function ensure_root( $uploads_dir ) {
        $path = self::path( $uploads_dir );
        if ( $path === '' ) {
            return self::result( false, '', 'uploads_dir_missing' );
        }

        $base = rtrim( (string) $uploads_dir, '/\\' );
        if ( $base === '' || ! is_dir( $base ) || ! is_writable( $base ) ) {
            return self::result( false, $path, 'uploads_dir_unwritable' );
        }

        if ( ! self::ensure_dir( $path, self::REVIEW_DIRECTORY_MODE ) ) {
            return self::result( false, $path, 'private_dir_unavailable' );
        }

        return self::result( true, $path, '' );
    }

    private static function ensure_dir( $path, $mode = self::DIRECTORY_MODE ) {
        if ( is_link( $path ) ) {
            return false;
        }

        if ( is_dir( $path ) ) {
            return self::ensure_permissions( $path, $mode );
        }

        $created = @mkdir( $path, $mode, true );
        if ( ! $created && ! is_dir( $path ) ) {
            return false;
        }

        return self::ensure_permissions( $path, $mode );
    }

    private static function ensure_file( $path, $content ) {
        if ( is_link( $path ) ) {
            return false;
        }

        if ( file_exists( $path ) ) {
            if ( ! is_file( $path ) ) {
                return false;
            }

            if ( (string) @file_get_contents( $path ) !== (string) $content && ! self::rewrite_file( $path, $content ) ) {
                return false;
            }

            return self::ensure_permissions( $path, self::FILE_MODE );
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

        return self::ensure_permissions( $path, self::FILE_MODE );
    }

    private static function rewrite_file( $path, $content ) {
        if ( is_link( $path ) || ! is_file( $path ) ) {
            return false;
        }

        $content = (string) $content;
        $written = @file_put_contents( $path, $content, LOCK_EX );
        return is_int( $written ) && $written === strlen( $content );
    }

    private static function ensure_deny_files( $dir ) {
        if ( ! is_string( $dir ) || $dir === '' || ! is_dir( $dir ) || is_link( $dir ) ) {
            return false;
        }

        $base = rtrim( $dir, '/\\' );
        foreach ( self::deny_file_specs() as $spec ) {
            if ( ! self::ensure_file( $base . '/' . $spec['filename'], $spec['content'] ) ) {
                return false;
            }
        }

        return true;
    }

    private static function has_deny_files( $dir ) {
        if ( ! is_string( $dir ) || $dir === '' || ! is_dir( $dir ) || is_link( $dir ) ) {
            return false;
        }

        $base = rtrim( $dir, '/\\' );
        foreach ( self::deny_file_specs() as $spec ) {
            $path = $base . '/' . $spec['filename'];
            if ( is_link( $path ) || ! is_file( $path ) ) {
                return false;
            }
            if ( (string) @file_get_contents( $path ) !== (string) $spec['content'] ) {
                return false;
            }
        }

        return true;
    }

    private static function deny_file_specs() {
        return array(
            array(
                'filename' => self::INDEX_FILENAME,
                'content' => self::INDEX_CONTENT,
                'error' => 'private_dir_index_failed',
            ),
            array(
                'filename' => self::HTACCESS_FILENAME,
                'content' => self::HTACCESS_CONTENT,
                'error' => 'private_dir_htaccess_failed',
            ),
            array(
                'filename' => self::WEBCONFIG_FILENAME,
                'content' => self::WEBCONFIG_CONTENT,
                'error' => 'private_dir_webconfig_failed',
            ),
        );
    }

    private static function ensure_permissions( $path, $mode ) {
        if ( @chmod( $path, $mode ) ) {
            return true;
        }

        return false;
    }

    private static function ensure_existing_file_mode( $path, $mode ) {
        return is_string( $path )
            && $path !== ''
            && ! is_link( $path )
            && is_file( $path )
            && self::ensure_permissions( $path, $mode );
    }

    private static function result( $ok, $path, $error ) {
        return array(
            'ok' => (bool) $ok,
            'path' => $path,
            'error' => $error,
        );
    }
}
