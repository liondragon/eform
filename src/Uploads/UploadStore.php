<?php
/**
 * Upload storage and retention helpers.
 *
 * Contract: Uploads
 * Contract: Uploads filename policy
 */

require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Security/Entropy.php';
require_once __DIR__ . '/../Submission/Ledger.php';
require_once __DIR__ . '/PrivateDir.php';
require_once __DIR__ . '/UploadBatchStore.php';
require_once __DIR__ . '/UploadPolicy.php';
require_once __DIR__ . '/UploadValue.php';

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
        return self::move( $context, $values, $submission_id, $uploads_dir, false );
    }

    /**
     * Store or exactly recover a synchronous file set for a staged submission
     * while its finalized aggregate is exclusively locked.
     */
    public static function move_staged_after_ledger( $context, $values, $submission_id, $uploads_dir ) {
        return UploadBatchStore::run_synchronous_commit(
            $submission_id,
            $uploads_dir,
            function ( $lifecycle ) use ( $context, $values, $submission_id, $uploads_dir ) {
                return self::move( $context, $values, $submission_id, $uploads_dir, true, $lifecycle );
            }
        );
    }

    private static function move( $context, $values, $submission_id, $uploads_dir, $allow_existing, $lifecycle = null ) {
        $values = self::extract_values( $values );
        $descriptors = array();

        if ( is_array( $context ) && isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ) {
            $descriptors = $context['descriptors'];
        }

        $upload_fields = array();
        $field_ordinal = 0;
        foreach ( $descriptors as $descriptor ) {
            $field_ordinal++;
            if ( ! is_array( $descriptor ) ) {
                continue;
            }

            $key = isset( $descriptor['key'] ) && is_string( $descriptor['key'] ) ? $descriptor['key'] : '';
            $type = isset( $descriptor['type'] ) && is_string( $descriptor['type'] ) ? $descriptor['type'] : '';
            if ( $key === '' || ( $type !== 'file' && $type !== 'files' ) ) {
                continue;
            }

            $value = array_key_exists( $key, $values ) ? $values[ $key ] : null;
            $normalized = UploadValue::items_with_single( $value );
            if ( empty( $normalized['items'] ) ) {
                continue;
            }
            $upload_fields[] = array(
                'field_ordinal' => $field_ordinal,
                'key' => $key,
                'items' => $normalized['items'],
                'single' => $normalized['single'],
            );
        }

        if ( empty( $upload_fields ) ) {
            if ( PrivateDir::is_purged( $uploads_dir ) ) {
                return self::error_result( 'upload_lifecycle_unavailable' );
            }
            if ( $allow_existing ) {
                if ( ! $lifecycle instanceof PrivateDirLease || rtrim( $lifecycle->private_dir(), '/\\' ) !== PrivateDir::path( $uploads_dir ) ) {
                    return self::error_result( 'upload_lifecycle_unavailable' );
                }
                $recovery = PrivateDir::leased_existing_relative_dir_result(
                    $lifecycle,
                    self::UPLOADS_DIR . '/' . Helpers::h2( $submission_id ) . '/' . $submission_id
                );
                if ( empty( $recovery['ok'] ) ) {
                    return self::error_result( 'upload_cleanup_failed' );
                }
                if ( ! empty( $recovery['exists'] ) && ! self::cleanup_unreferenced_recovery_files( $recovery['path'], array(), $submission_id ) ) {
                    return self::error_result( 'upload_cleanup_failed' );
                }
            }
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

        $lifecycle = $lifecycle instanceof PrivateDirLease ? $lifecycle : PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return self::error_result( 'upload_lifecycle_unavailable' );
        }
        if ( rtrim( $lifecycle->private_dir(), '/\\' ) !== PrivateDir::path( $uploads_dir ) ) {
            return self::error_result( 'upload_lifecycle_unavailable' );
        }

        $base_dir = PrivateDir::leased_subdir( $lifecycle, self::UPLOADS_DIR, true, true );
        if ( $base_dir === '' ) {
            return self::error_result( 'uploads_store_unavailable' );
        }

        $stored = array();
        $created = array();
        $destination_relpath = $allow_existing
            ? Helpers::h2( $submission_id ) . '/' . $submission_id
            : gmdate( 'Ymd' );
        $destination_path = PrivateDir::leased_relative_dir( $lifecycle, self::UPLOADS_DIR . '/' . $destination_relpath, true );
        if ( $destination_path === '' ) {
            return self::error_result( 'uploads_store_unavailable' );
        }

        foreach ( $upload_fields as $upload_field ) {
            $field_ordinal = $upload_field['field_ordinal'];
            $key = $upload_field['key'];
            $items = $upload_field['items'];
            $single = $upload_field['single'];

            $updated_items = array();
            $item_position = 0;
            foreach ( $items as $item ) {
                if ( ! UploadValue::is_item( $item ) ) {
                    self::rollback_created( $created, $allow_existing );
                    return self::error_result( 'upload_item_invalid' );
                }

                $tmp_name = isset( $item['tmp_name'] ) && is_string( $item['tmp_name'] ) ? $item['tmp_name'] : '';
                if ( $tmp_name === '' || ! is_file( $tmp_name ) ) {
                    self::rollback_created( $created, $allow_existing );
                    return self::error_result( 'upload_tmp_missing' );
                }

                $original_safe = UploadValue::name_for_storage( $item );

                $extension = UploadPolicy::extension_from_name( $original_safe );
                $sha256 = hash_file( 'sha256', $tmp_name );
                if ( ! is_string( $sha256 ) || $sha256 === '' ) {
                    self::rollback_created( $created, $allow_existing );
                    return self::error_result( 'upload_hash_failed' );
                }

                $sha16 = substr( $sha256, 0, 16 );
                $item_ordinal = UploadValue::input_ordinal( $item, $item_position );
                $item_position++;

                $filename = self::stored_filename( $submission_id, $field_ordinal, $item_ordinal, $sha16, $extension );
                $relpath = $destination_relpath . '/' . $filename;
                $final = $base_dir . '/' . $relpath;

                if ( $allow_existing ) {
                    $existing = self::existing_destination( $destination_path, $destination_relpath, $submission_id, $field_ordinal, $item_ordinal, $filename, $sha256 );
                    if ( empty( $existing['ok'] ) ) {
                        self::rollback_created( $created, $allow_existing );
                        return self::error_result( 'upload_collision' );
                    }
                    if ( $existing['path'] !== '' ) {
                        @unlink( $tmp_name );
                        $item['tmp_name'] = '';
                        $item['stored'] = array(
                            'path' => $existing['path'],
                            'relpath' => $existing['relpath'],
                            'field_ordinal' => $field_ordinal,
                            'item_ordinal' => $item_ordinal,
                            'sha256' => $sha256,
                            'sha16' => $sha16,
                            'bytes' => $existing['bytes'],
                        );
                        $updated_items[] = $item;
                        $stored[] = array( 'path' => $existing['path'], 'relpath' => $existing['relpath'] );
                        continue;
                    }
                } elseif ( file_exists( $final ) ) {
                    self::rollback_created( $created, $allow_existing );
                    return self::error_result( 'upload_collision' );
                }

                $tmp_path = $destination_path . '/.' . $submission_id . '-' . $field_ordinal . '-' . $item_ordinal . '.' . self::temp_suffix();
                if ( ! self::copy_to_temp( $tmp_name, $tmp_path ) ) {
                    self::rollback_created( $created, $allow_existing );
                    return self::error_result( 'upload_write_failed' );
                }

                if ( ! self::ensure_permissions( $tmp_path, PrivateDir::FILE_MODE ) ) {
                    @unlink( $tmp_path );
                    self::rollback_created( $created, $allow_existing );
                    return self::error_result( 'upload_write_failed' );
                }

                if ( file_exists( $final ) ) {
                    @unlink( $tmp_path );
                    self::rollback_created( $created, $allow_existing );
                    return self::error_result( 'upload_collision' );
                }

                // Educational note: finalize with temp->rename in the same directory for atomicity.
                $renamed = @rename( $tmp_path, $final );
                if ( ! $renamed || ! file_exists( $final ) ) {
                    @unlink( $tmp_path );
                    self::rollback_created( $created, $allow_existing );
                    return self::error_result( 'upload_rename_failed' );
                }

                if ( ! self::ensure_permissions( $final, PrivateDir::FILE_MODE ) ) {
                    @unlink( $final );
                    self::rollback_created( $created, $allow_existing );
                    return self::error_result( 'upload_chmod_failed' );
                }

                $created[] = array(
                    'path' => $final,
                    'relpath' => $relpath,
                );

                @unlink( $tmp_name );

                $bytes = isset( $item['size'] ) && is_numeric( $item['size'] ) ? (int) $item['size'] : 0;
                $item['tmp_name'] = '';
                $item['stored'] = array(
                    'path' => $final,
                    'relpath' => $relpath,
                    'field_ordinal' => $field_ordinal,
                    'item_ordinal' => $item_ordinal,
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

        if ( $allow_existing && ! self::cleanup_unreferenced_recovery_files( $destination_path, $stored, $submission_id ) ) {
            return self::error_result( 'upload_cleanup_failed' );
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
     * @return bool Whether retention cleanup completed or was unnecessary.
     */
    public static function apply_retention( $stored, $config ) {
        $retention = self::retention_seconds( $config );
        if ( $retention > 0 ) {
            return true;
        }

        $configured_uploads_dir = Config::value( $config, array( 'uploads', 'dir' ), '' );
        $uploads_dir = is_string( $configured_uploads_dir ) ? rtrim( $configured_uploads_dir, '/\\' ) : '';
        $lifecycle = PrivateDir::acquire_write_lease( $uploads_dir );
        if ( ! $lifecycle instanceof PrivateDirLease ) {
            return false;
        }
        self::cleanup_paths( $stored );
        $lifecycle->release();
        return true;
    }

    private static function stored_filename( $submission_id, $field_ordinal, $item_ordinal, $sha16, $extension ) {
        $suffix = $extension !== '' ? '.' . $extension : '';
        return $submission_id . '-' . $field_ordinal . '-' . $item_ordinal . '-' . $sha16 . $suffix;
    }

    /**
     * Whether a stored path has the exact staged synchronous-recovery layout.
     */
    public static function is_staged_recovery_path( $uploads_dir, $path ) {
        return self::staged_recovery_submission_id( $uploads_dir, $path ) !== '';
    }

    /**
     * Return the submission id encoded by an exact staged recovery path.
     */
    public static function staged_recovery_submission_id( $uploads_dir, $path ) {
        $uploads_root = rtrim( (string) $uploads_dir, '/\\' );
        if ( $uploads_root === '' || ! is_string( $path ) || strpos( $path, $uploads_root . '/' ) !== 0 ) {
            return '';
        }
        $relative = substr( $path, strlen( $uploads_root ) + 1 );
        $parts = explode( '/', $relative );
        if ( count( $parts ) !== 3 ) {
            return '';
        }
        list( $shard, $submission_id, $filename ) = $parts;
        $matches = preg_match( '/^[0-9a-f]{2}$/', $shard ) === 1
            && preg_match( Ledger::SUBMISSION_ID_REGEX, $submission_id ) === 1
            && hash_equals( Helpers::h2( $submission_id ), $shard )
            && preg_match( self::recovery_filename_pattern( $submission_id ), $filename ) === 1;
        return $matches ? $submission_id : '';
    }

    private static function existing_destination( $submission_path, $submission_relpath, $submission_id, $field_ordinal, $item_ordinal, $filename, $sha256 ) {
        $matches = array();
        $prefix = $submission_id . '-' . $field_ordinal . '-' . $item_ordinal . '-';
        $entries = @glob( $submission_path . '/' . $prefix . '*', GLOB_NOSORT );
        if ( ! is_array( $entries ) ) {
            return array( 'ok' => false, 'path' => '', 'relpath' => '', 'bytes' => 0 );
        }
        foreach ( $entries as $path ) {
            if ( is_link( $path ) ) {
                return array( 'ok' => false, 'path' => '', 'relpath' => '', 'bytes' => 0 );
            }
            if ( is_file( $path ) ) {
                $entry = basename( $path );
                $matches[] = array( 'path' => $path, 'relpath' => $submission_relpath . '/' . $entry, 'filename' => $entry );
                if ( count( $matches ) > 1 ) {
                    return array( 'ok' => false, 'path' => '', 'relpath' => '', 'bytes' => 0 );
                }
            }
        }
        if ( empty( $matches ) ) {
            return array( 'ok' => true, 'path' => '', 'relpath' => '', 'bytes' => 0 );
        }

        $path = $matches[0]['path'];
        $existing_hash = hash_file( 'sha256', $path );
        $bytes = @filesize( $path );
        if ( ! hash_equals( $filename, $matches[0]['filename'] )
            || ! is_string( $existing_hash )
            || ! hash_equals( $sha256, $existing_hash )
            || ! is_int( $bytes )
            || $bytes < 0
        ) {
            return array( 'ok' => false, 'path' => '', 'relpath' => '', 'bytes' => 0 );
        }
        return array( 'ok' => true, 'path' => $path, 'relpath' => $matches[0]['relpath'], 'bytes' => $bytes );
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

    private static function ensure_permissions( $path, $mode ) {
        return (bool) @chmod( $path, $mode );
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

    private static function rollback_created( $created, $allow_existing ) {
        if ( ! $allow_existing ) {
            self::cleanup_paths( $created );
        }
    }

    private static function cleanup_unreferenced_recovery_files( $dir, $stored, $submission_id ) {
        if ( ! file_exists( $dir ) ) {
            return true;
        }
        if ( is_link( $dir ) || ! is_dir( $dir ) ) {
            return false;
        }
        $keep = array();
        foreach ( $stored as $entry ) {
            if ( is_array( $entry ) && isset( $entry['path'] ) && is_string( $entry['path'] ) ) {
                $keep[ $entry['path'] ] = true;
            }
        }
        $entries = @scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return false;
        }
        $pattern = self::recovery_filename_pattern( $submission_id );
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' || preg_match( $pattern, $entry ) !== 1 ) {
                continue;
            }
            $path = rtrim( $dir, '/\\' ) . '/' . $entry;
            if ( isset( $keep[ $path ] ) ) {
                continue;
            }
            if ( is_link( $path ) || ! is_file( $path ) || ! @unlink( $path ) ) {
                return false;
            }
        }
        return true;
    }

    private static function recovery_filename_pattern( $submission_id ) {
        return '/^' . preg_quote( $submission_id, '/' ) . '-[0-9]+-[0-9]+-[0-9a-f]{16}(?:\.[a-z0-9]+)?$/';
    }

    private static function temp_suffix() {
        $suffix = Entropy::hex( 8 );
        return $suffix !== '' ? $suffix : (string) getmypid();
    }

    private static function error_result( $reason ) {
        return array(
            'ok' => false,
            'reason' => $reason,
        );
    }
}
