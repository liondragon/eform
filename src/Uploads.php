<?php
// src/Uploads.php
/**
 * Handle file uploads: normalization, storage, and garbage collection.
 */
class Uploads {
    /** Error code when accept[] intersection is empty. */
    public const ERR_ACCEPT_EMPTY = 'EFORMS_ERR_ACCEPT_EMPTY';

    /** Total bytes seen in this request. */
    private static $request_bytes = 0;

    /** Total files seen in this request. */
    private static $request_count = 0;

    /** Bytes per field for this request. */
    private static $field_bytes = [];

    /** File count per field for this request. */
    private static $field_count = [];
    /**
     * Return path to uploads directory, creating it if necessary.
     */
    public static function get_dir(): string {
        // Prefer directory one level above wp-content if writable.
        $base = dirname( WP_CONTENT_DIR );
        $dir  = $base . '/eforms-uploads';
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
            $dir = WP_CONTENT_DIR . '/eforms-uploads';
        }
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            @chmod( $dir, 0700 );
        }
        self::create_deny_files( $dir );
        return $dir;
    }

    /**
     * Create web-server deny files in uploads directory.
     */
    private static function create_deny_files( string $dir ): void {
        $files = [
            '.htaccess'  => "Require all denied\n",
            'index.html' => '',
        ];
        foreach ( $files as $name => $contents ) {
            $path = $dir . '/' . $name;
            if ( ! file_exists( $path ) ) {
                file_put_contents( $path, $contents, LOCK_EX );
            }
        }
    }

    /**
     * Normalize the $_FILES array into a predictable shape.
     *
     * @param array $files Raw $_FILES.
     * @return array Normalized structure [ field_key => [ {tmp_name, name, size, error}, ... ] ]
     */
    public static function normalize_files_array( array $files ): array {
        $normalized = [];
        foreach ( $files as $field => $data ) {
            if ( ! is_array( $data ) ) {
                continue;
            }
            if ( is_array( $data['name'] ?? null ) ) {
                $count = count( $data['name'] );
                for ( $i = 0; $i < $count; $i++ ) {
                    $normalized[ $field ][ $i ] = [
                        'tmp_name' => $data['tmp_name'][ $i ] ?? '',
                        'name'     => $data['name'][ $i ] ?? '',
                        'size'     => $data['size'][ $i ] ?? 0,
                        'error'    => $data['error'][ $i ] ?? 0,
                    ];
                }
            } else {
                $normalized[ $field ][0] = [
                    'tmp_name' => $data['tmp_name'] ?? '',
                    'name'     => $data['name'] ?? '',
                    'size'     => $data['size'] ?? 0,
                    'error'    => $data['error'] ?? 0,
                ];
            }
        }
        return $normalized;
    }

    /**
     * Store an uploaded file on disk using hashed filename.
     *
     * @param array   $item     Single normalized file array.
     * @param string  $field    Field key (used in metadata).
     * @param array   $accept   Allowed MIME types from the field definition.
     * @param Logging $logger   Optional logger instance.
     * @return array|null Metadata about stored file or null on failure.
     */
    public static function store_uploaded_file( array $item, string $field, array $accept = [], Logging $logger = null ) {
        if ( ( $item['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || (int) $item['size'] <= 0 ) {
            return null;
        }

        $size = (int) $item['size'];

        // Enforce per-file byte limit.
        $max_file_bytes = defined( 'EFORMS_UPLOAD_MAX_FILE_BYTES' ) ? (int) EFORMS_UPLOAD_MAX_FILE_BYTES : PHP_INT_MAX;
        if ( $size > $max_file_bytes ) {
            return null;
        }

        // Enforce per-request and per-field limits.
        $max_request_bytes = defined( 'EFORMS_UPLOAD_MAX_REQUEST_BYTES' ) ? (int) EFORMS_UPLOAD_MAX_REQUEST_BYTES : PHP_INT_MAX;
        $max_request_count = defined( 'EFORMS_UPLOAD_MAX_REQUEST_COUNT' ) ? (int) EFORMS_UPLOAD_MAX_REQUEST_COUNT : PHP_INT_MAX;
        $max_field_bytes   = defined( 'EFORMS_UPLOAD_MAX_FIELD_BYTES' ) ? (int) EFORMS_UPLOAD_MAX_FIELD_BYTES : PHP_INT_MAX;
        $max_field_count   = defined( 'EFORMS_UPLOAD_MAX_FIELD_COUNT' ) ? (int) EFORMS_UPLOAD_MAX_FIELD_COUNT : PHP_INT_MAX;

        $field_bytes = self::$field_bytes[ $field ] ?? 0;
        $field_count = self::$field_count[ $field ] ?? 0;

        if (
            self::$request_bytes + $size > $max_request_bytes ||
            self::$request_count + 1 > $max_request_count ||
            $field_bytes + $size > $max_field_bytes ||
            $field_count + 1 > $max_field_count
        ) {
            return null;
        }

        // Determine allowed MIME types from registries.
        $allowed = self::get_allowed_mime_types();
        $accept  = array_filter( array_map( 'strval', $accept ) );
        $intersection = $accept ? array_intersect( $accept, $allowed ) : $allowed;
        if ( $accept && empty( $intersection ) ) {
            $logger = $logger ?: new Logging();
            $logger->log( self::ERR_ACCEPT_EMPTY, Logging::LEVEL_ERROR, [ 'field' => $field ] );
            return null;
        }

        $uploads = self::get_dir();
        $subdir  = $uploads . '/' . date( 'Ymd' );
        if ( ! is_dir( $subdir ) ) {
            wp_mkdir_p( $subdir );
            @chmod( $subdir, 0700 );
        }

        $orig     = sanitize_file_name( $item['name'] ?? 'file' );
        $ext      = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
        $slug     = sanitize_title( pathinfo( $orig, PATHINFO_FILENAME ) );
        $hash     = substr( sha1_file( $item['tmp_name'] ), 0, 16 );
        $seq      = 1;
        do {
            $stored   = sprintf( '%s-%s-%d.%s', $slug ?: 'file', $hash, $seq, $ext );
            $dest     = $subdir . '/' . $stored;
            $seq++;
        } while ( file_exists( $dest ) );

        $mime = function_exists( 'finfo_open' ) ? mime_content_type( $item['tmp_name'] ) : '';
        if ( empty( $mime ) || 'application/octet-stream' === $mime || ! in_array( $mime, $intersection, true ) ) {
            return null;
        }

        if ( ! move_uploaded_file( $item['tmp_name'], $dest ) ) {
            return null;
        }
        @chmod( $dest, 0600 );

        $sha256 = hash_file( 'sha256', $dest );

        // Update request and field counters after successful move.
        self::$request_bytes += $size;
        self::$request_count++;
        self::$field_bytes[ $field ] = $field_bytes + $size;
        self::$field_count[ $field ] = $field_count + 1;

        return [
            'field'          => $field,
            'original_name'  => $orig,
            'stored_path'    => str_replace( $uploads . '/', '', $dest ),
            'size'           => $size,
            'mime'           => $mime,
            'sha256'         => $sha256,
        ];
    }

    /**
     * Retrieve allowed MIME types from defined registries or defaults.
     *
     * @return array List of allowed MIME types.
     */
    private static function get_allowed_mime_types(): array {
        $allowed = [];
        $consts  = get_defined_constants( true );
        foreach ( $consts['user'] ?? [] as $name => $value ) {
            if ( 0 === strpos( $name, 'EFORMS_UPLOAD_ALLOWED_' ) && is_array( $value ) ) {
                $allowed = array_merge( $allowed, $value );
            }
        }
        if ( empty( $allowed ) ) {
            $allowed = [ 'image/jpeg', 'image/png', 'application/pdf' ];
        }
        return array_values( array_unique( array_map( 'strval', $allowed ) ) );
    }

    /**
     * Opportunistically run garbage collection of old uploads.
     *
     * @param int $chance Inverse probability (e.g., 20 => 1/20).
     */
    public static function maybe_gc( int $chance = 20 ): void {
        if ( random_int( 1, max( 1, $chance ) ) === 1 ) {
            self::gc();
        }
    }

    /**
     * Delete files older than retention period.
     */
    public static function gc(): void {
        $dir       = self::get_dir();
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $retention = defined( 'EFORMS_UPLOAD_RETENTION_SECONDS' ) ? (int) EFORMS_UPLOAD_RETENTION_SECONDS : 86400;
        $now       = time();
        $iterator  = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                if ( $now - $file->getMTime() > $retention ) {
                    @unlink( $file->getPathname() );
                }
            } elseif ( $file->isDir() ) {
                @rmdir( $file->getPathname() );
            }
        }
    }
}
