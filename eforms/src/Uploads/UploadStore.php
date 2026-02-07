<?php
/**
 * Upload storage and retention helpers.
 *
 * Spec: Uploads (docs/Canonical_Spec.md#sec-uploads)
 * Spec: Uploads filename policy (docs/Canonical_Spec.md#sec-uploads-filenames)
 */

require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/PrivateDir.php';
require_once __DIR__ . '/UploadPolicy.php';

class UploadStore {
    const UPLOADS_DIR = 'uploads';

    /**
     * Move validated uploads into private storage after ledger reservation.
     *
     * @param array $context TemplateContext array.
     * @param array $values Coerced values array (or {values: ...} payload).
     * @param string $submission_id UUIDv4 submission id.
     * @param string $uploads_dir Base uploads dir.
     * @return array{ok: bool, values?: array, stored?: array, reason?: string}
     */
    public static function move_after_ledger( $context, $values, $submission_id, $uploads_dir ) {
        $values = self::extract_values( $values );
        $descriptors = array();

        if ( is_array( $context ) && isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ) {
            $descriptors = $context['descriptors'];
        }

        if ( empty( $descriptors ) ) {
            return array(
                'ok' => true,
                'values' => $values,
                'stored' => array(),
            );
        }

        $uploads_dir = is_string( $uploads_dir ) ? rtrim( $uploads_dir, '/\\' ) : '';
        if ( $uploads_dir === '' || ! is_dir( $uploads_dir ) || ! is_writable( $uploads_dir ) ) {
            return self::error_result( 'uploads_dir_unavailable' );
        }

        $private = PrivateDir::ensure( $uploads_dir );
        if ( ! is_array( $private ) || empty( $private['ok'] ) ) {
            $reason = is_array( $private ) && isset( $private['error'] ) ? $private['error'] : 'private_dir_unavailable';
            return self::error_result( $reason );
        }

        $base_dir = rtrim( $private['path'], '/\\' ) . '/' . self::UPLOADS_DIR;
        if ( ! self::ensure_dir( $base_dir, 0700 ) || ! self::ensure_deny_files( $base_dir ) ) {
            return self::error_result( 'uploads_store_unavailable' );
        }

        $stored = array();
        $file_index = 0;
        $date_dir = gmdate( 'Ymd' );
        $date_path = $base_dir . '/' . $date_dir;
        if ( ! self::ensure_dir( $date_path, 0700 ) ) {
            return self::error_result( 'uploads_store_unavailable' );
        }

        foreach ( $descriptors as $descriptor ) {
            if ( ! is_array( $descriptor ) ) {
                continue;
            }

            $key = isset( $descriptor['key'] ) && is_string( $descriptor['key'] ) ? $descriptor['key'] : '';
            if ( $key === '' ) {
                continue;
            }

            $type = isset( $descriptor['type'] ) && is_string( $descriptor['type'] ) ? $descriptor['type'] : '';
            if ( $type !== 'file' && $type !== 'files' ) {
                continue;
            }

            $value = array_key_exists( $key, $values ) ? $values[ $key ] : null;
            $normalized = self::normalize_upload_value( $value );
            $items = $normalized['items'];
            $single = $normalized['single'];

            if ( empty( $items ) ) {
                continue;
            }

            $updated_items = array();
            foreach ( $items as $item ) {
                if ( ! self::is_upload_item( $item ) ) {
                    self::cleanup_paths( $stored );
                    return self::error_result( 'upload_item_invalid' );
                }

                $tmp_name = isset( $item['tmp_name'] ) && is_string( $item['tmp_name'] ) ? $item['tmp_name'] : '';
                if ( $tmp_name === '' || ! is_file( $tmp_name ) ) {
                    self::cleanup_paths( $stored );
                    return self::error_result( 'upload_tmp_missing' );
                }

                $original_safe = '';
                if ( isset( $item['original_name_safe'] ) && is_string( $item['original_name_safe'] ) ) {
                    $original_safe = $item['original_name_safe'];
                } elseif ( isset( $item['original_name'] ) && is_string( $item['original_name'] ) ) {
                    $original_safe = $item['original_name'];
                }

                $extension = UploadPolicy::extension_from_name( $original_safe );
                $sha256 = hash_file( 'sha256', $tmp_name );
                if ( ! is_string( $sha256 ) || $sha256 === '' ) {
                    self::cleanup_paths( $stored );
                    return self::error_result( 'upload_hash_failed' );
                }

                $sha16 = substr( $sha256, 0, 16 );
                $file_index++;

                $filename = self::stored_filename( $submission_id, $file_index, $sha16, $extension );
                $relpath = $date_dir . '/' . $filename;
                $final = $base_dir . '/' . $relpath;

                if ( file_exists( $final ) ) {
                    self::cleanup_paths( $stored );
                    return self::error_result( 'upload_collision' );
                }

                $tmp_path = $date_path . '/.' . $submission_id . '-' . $file_index . '.' . self::temp_suffix();
                if ( ! self::copy_to_temp( $tmp_name, $tmp_path ) ) {
                    self::cleanup_paths( $stored );
                    return self::error_result( 'upload_write_failed' );
                }

                if ( ! self::ensure_permissions( $tmp_path, 0600 ) ) {
                    @unlink( $tmp_path );
                    self::cleanup_paths( $stored );
                    return self::error_result( 'upload_write_failed' );
                }

                if ( file_exists( $final ) ) {
                    @unlink( $tmp_path );
                    self::cleanup_paths( $stored );
                    return self::error_result( 'upload_collision' );
                }

                // Educational note: finalize with temp->rename in the same directory for atomicity.
                $renamed = @rename( $tmp_path, $final );
                if ( ! $renamed || ! file_exists( $final ) ) {
                    @unlink( $tmp_path );
                    self::cleanup_paths( $stored );
                    return self::error_result( 'upload_rename_failed' );
                }

                if ( ! self::ensure_permissions( $final, 0600 ) ) {
                    self::cleanup_paths( $stored );
                    return self::error_result( 'upload_chmod_failed' );
                }

                @unlink( $tmp_name );

                $bytes = isset( $item['size'] ) && is_numeric( $item['size'] ) ? (int) $item['size'] : 0;
                $item['tmp_name'] = '';
                $item['stored'] = array(
                    'path' => $final,
                    'relpath' => $relpath,
                    'file_index' => $file_index,
                    'sha256' => $sha256,
                    'sha16' => $sha16,
                    'bytes' => $bytes,
                );

                $updated_items[] = $item;
                $stored[] = array(
                    'path' => $final,
                    'relpath' => $relpath,
                );
            }

            $values[ $key ] = $single ? $updated_items[0] : $updated_items;
        }

        return array(
            'ok' => true,
            'values' => $values,
            'stored' => $stored,
        );
    }

    /**
     * Apply retention policy to stored uploads.
     *
     * @param array $stored Stored entries from move_after_ledger().
     * @param array $config Config snapshot.
     * @return void
     */
    public static function apply_retention( $stored, $config ) {
        $retention = self::retention_seconds( $config );
        if ( $retention > 0 ) {
            return;
        }

        self::cleanup_paths( $stored );
    }

    private static function stored_filename( $submission_id, $file_index, $sha16, $extension ) {
        $suffix = $extension !== '' ? '.' . $extension : '';
        return $submission_id . '-' . $file_index . '-' . $sha16 . $suffix;
    }

    private static function retention_seconds( $config ) {
        if ( is_array( $config )
            && isset( $config['uploads'] )
            && is_array( $config['uploads'] )
            && isset( $config['uploads']['retention_seconds'] )
            && is_numeric( $config['uploads']['retention_seconds'] )
        ) {
            $value = (int) $config['uploads']['retention_seconds'];
            return $value > 0 ? $value : 0;
        }

        return 0;
    }

    private static function extract_values( $values ) {
        if ( is_array( $values ) && isset( $values['values'] ) && is_array( $values['values'] ) ) {
            return $values['values'];
        }

        return is_array( $values ) ? $values : array();
    }

    private static function normalize_upload_value( $value ) {
        if ( $value === null ) {
            return array( 'items' => array(), 'single' => false );
        }

        if ( self::is_upload_item( $value ) ) {
            return array( 'items' => array( $value ), 'single' => true );
        }

        if ( is_array( $value ) ) {
            return array( 'items' => $value, 'single' => false );
        }

        return array( 'items' => array(), 'single' => false );
    }

    private static function is_upload_item( $value ) {
        if ( ! is_array( $value ) ) {
            return false;
        }

        return array_key_exists( 'tmp_name', $value )
            && array_key_exists( 'original_name', $value )
            && array_key_exists( 'size', $value )
            && array_key_exists( 'error', $value );
    }

    private static function copy_to_temp( $source, $dest ) {
        $read = @fopen( $source, 'rb' );
        if ( $read === false ) {
            return false;
        }

        $write = @fopen( $dest, 'xb' );
        if ( $write === false ) {
            fclose( $read );
            return false;
        }

        $ok = true;
        $copied = stream_copy_to_stream( $read, $write );
        if ( $copied === false ) {
            $ok = false;
        }

        if ( function_exists( 'fflush' ) ) {
            @fflush( $write );
        }

        fclose( $read );
        fclose( $write );

        if ( ! $ok ) {
            @unlink( $dest );
        }

        return $ok;
    }

    private static function ensure_dir( $path, $mode ) {
        if ( is_dir( $path ) ) {
            return self::ensure_permissions( $path, $mode );
        }

        if ( file_exists( $path ) ) {
            return false;
        }

        $created = @mkdir( $path, $mode, true );
        if ( ! $created && ! is_dir( $path ) ) {
            return false;
        }

        return self::ensure_permissions( $path, $mode );
    }

    private static function ensure_permissions( $path, $mode ) {
        return (bool) @chmod( $path, $mode );
    }

    private static function ensure_deny_files( $dir ) {
        $index = $dir . '/' . PrivateDir::INDEX_FILENAME;
        if ( ! self::ensure_file( $index, PrivateDir::INDEX_CONTENT ) ) {
            return false;
        }

        $htaccess = $dir . '/' . PrivateDir::HTACCESS_FILENAME;
        if ( ! self::ensure_file( $htaccess, PrivateDir::HTACCESS_CONTENT ) ) {
            return false;
        }

        $webconfig = $dir . '/' . PrivateDir::WEBCONFIG_FILENAME;
        if ( ! self::ensure_file( $webconfig, PrivateDir::WEBCONFIG_CONTENT ) ) {
            return false;
        }

        return true;
    }

    private static function ensure_file( $path, $content ) {
        if ( file_exists( $path ) ) {
            if ( ! is_file( $path ) ) {
                return false;
            }

            return self::ensure_permissions( $path, 0600 );
        }

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

    private static function cleanup_paths( $stored ) {
        if ( ! is_array( $stored ) ) {
            return;
        }

        foreach ( $stored as $entry ) {
            $path = is_array( $entry ) && isset( $entry['path'] ) && is_string( $entry['path'] ) ? $entry['path'] : '';
            if ( $path !== '' && is_file( $path ) ) {
                @unlink( $path );
            }
        }
    }

    private static function temp_suffix() {
        return bin2hex( random_bytes( 8 ) );
    }

    private static function error_result( $reason ) {
        return array(
            'ok' => false,
            'reason' => $reason,
        );
    }
}
