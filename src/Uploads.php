<?php
// src/Uploads.php
/**
 * Handle file uploads: normalization, storage, and garbage collection.
 */
class Uploads {
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
     * @param array  $item     Single normalized file array.
     * @param string $field    Field key (used in metadata).
     * @return array|null Metadata about stored file or null on failure.
     */
    public static function store_uploaded_file( array $item, string $field ) {
        if ( ( $item['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || (int) $item['size'] <= 0 ) {
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

        if ( ! move_uploaded_file( $item['tmp_name'], $dest ) ) {
            return null;
        }
        @chmod( $dest, 0600 );

        $sha256 = hash_file( 'sha256', $dest );
        $mime   = function_exists( 'finfo_open' ) ? mime_content_type( $dest ) : 'application/octet-stream';

        return [
            'field'          => $field,
            'original_name'  => $orig,
            'stored_path'    => str_replace( $uploads . '/', '', $dest ),
            'size'           => (int) $item['size'],
            'mime'           => $mime,
            'sha256'         => $sha256,
        ];
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
