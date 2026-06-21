<?php
/**
 * Declined-submission review store.
 *
 * Contract: Declined Review
 */

require_once __DIR__ . '/Anchors.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Privacy/ClientIp.php';
require_once __DIR__ . '/Security/Entropy.php';
require_once __DIR__ . '/Uploads/PrivateDir.php';
require_once __DIR__ . '/Uploads/UploadValue.php';
require_once __DIR__ . '/Logging/FileSink.php';

if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/Logging.php';
}

class DeclinedReviewLog {
    const DIR = 'declined';
    const FILE_PREFIX = 'declined-';
    const FILE_EXT = '.jsonl';

    public static function capture( $args ) {
        if ( ! is_array( $args ) ) {
            return false;
        }

        $config = isset( $args['config'] ) && is_array( $args['config'] ) ? $args['config'] : Config::get();
        if ( ! Config::bool( $config, array( 'declined_review', 'enable' ), false ) ) {
            return false;
        }

        $record = self::record_from_args( $args, $config );
        if ( empty( $record ) ) {
            return false;
        }

        $ok = self::write_record( $record, $config );
        if ( ! $ok ) {
            self::warn_write_failed( $record, isset( $args['request'] ) ? $args['request'] : null );
        }

        return $ok;
    }

    public static function query( $filters = array(), $config = null ) {
        $config = is_array( $config ) ? $config : Config::get();
        $filters = is_array( $filters ) ? $filters : array();
        $per_page = self::bounded_int( isset( $filters['per_page'] ) ? $filters['per_page'] : null, self::anchor( 'DECLINED_REVIEW_PAGE_SIZE', 50 ), 1, self::anchor( 'DECLINED_REVIEW_PAGE_SIZE', 50 ) );
        $page = self::bounded_int( isset( $filters['page'] ) ? $filters['page'] : null, 1, 1, PHP_INT_MAX );
        $scan = self::scan_records(
            $filters,
            $config,
            function ( $record ) use ( $filters ) {
                return self::matches_filters( $record, $filters );
            },
            self::anchor( 'DECLINED_REVIEW_SCAN_MAX_RECORDS', 5000 )
        );
        $records = $scan['records'];
        usort( $records, array( __CLASS__, 'compare_newest' ) );
        $total = count( $records );
        $offset = ( $page - 1 ) * $per_page;

        return array(
            'records' => array_slice( $records, $offset, $per_page ),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'scanned' => $scan['scanned'],
            'limited' => $scan['limited'],
            'from' => $scan['from'],
            'to' => $scan['to'],
        );
    }

    public static function find( $review_id, $filters = array(), $config = null ) {
        $review_id = is_string( $review_id ) ? trim( $review_id ) : '';
        if ( $review_id === '' ) {
            return array( 'found' => false, 'record' => null, 'multiple' => false );
        }

        $scan = self::scan_records(
            $filters,
            is_array( $config ) ? $config : Config::get(),
            function ( $record ) use ( $review_id ) {
                return isset( $record['review_id'] ) && is_string( $record['review_id'] ) && hash_equals( $record['review_id'], $review_id );
            },
            self::anchor( 'DECLINED_REVIEW_SCAN_MAX_RECORDS', 5000 )
        );
        $matches = $scan['records'];
        usort( $matches, array( __CLASS__, 'compare_newest' ) );
        $best = empty( $matches ) ? null : $matches[0];

        if ( $best === null ) {
            return array( 'found' => false, 'record' => null, 'multiple' => false );
        }

        return array(
            'found' => true,
            'record' => $best,
            'multiple' => count( $matches ) > 1,
        );
    }

    public static function clear_older_than( $older_than_days, $config = null, $now = null ) {
        $config = is_array( $config ) ? $config : Config::get();
        $older_than_days = self::normalize_clear_days( $older_than_days );
        if ( $older_than_days === null ) {
            return self::cleanup_summary( false, 'invalid_days' );
        }

        $now = is_numeric( $now ) ? (int) $now : time();
        $cutoff = $older_than_days === 0 ? null : $now - ( $older_than_days * 86400 );
        return self::delete_declined_files_before_cutoff( $cutoff, $config );
    }

    public static function prune_expired( $config = null, $now = null, $options = array() ) {
        $config = is_array( $config ) ? $config : Config::get();
        $retention_days = Config::value( $config, array( 'declined_review', 'retention_days' ), 1 );
        $retention_days = self::normalize_retention_days( $retention_days );
        if ( $retention_days === null ) {
            return self::cleanup_summary( false, 'invalid_days' );
        }

        $now = is_numeric( $now ) ? (int) $now : time();
        $cutoff = $now - ( $retention_days * 86400 );
        return self::delete_declined_files_before_cutoff( $cutoff, $config, $options );
    }

    private static function record_from_args( $args, $config ) {
        $context = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : array();
        $request = isset( $args['request'] ) ? $args['request'] : null;
        $security = isset( $args['security'] ) && is_array( $args['security'] ) ? $args['security'] : array();
        $value_stage = self::safe_token( isset( $args['value_stage'] ) ? $args['value_stage'] : '' );
        $review_id = Entropy::uuid_v4();
        $review_id = is_string( $review_id ) ? $review_id : '';
        if ( $review_id === '' ) {
            return array();
        }
        $request_id = Logging::request_id( $request );
        $fields = array();
        if ( $value_stage !== 'metadata_only' ) {
            $fields = self::bounded_fields(
                self::declared_values( $context, isset( $args['values'] ) ? $args['values'] : array() )
            );
        }

        return array(
            'review_id' => $review_id,
            'ts' => gmdate( 'c' ),
            'form_id' => self::safe_token( isset( $args['form_id'] ) ? $args['form_id'] : '' ),
            'submission_id' => self::safe_token( isset( $security['submission_id'] ) ? $security['submission_id'] : '' ),
            'request_id' => self::safe_token( is_string( $request_id ) && $request_id !== '' ? $request_id : $review_id ),
            'decision_code' => self::safe_token( isset( $args['decision_code'] ) ? $args['decision_code'] : '' ),
            'decision_phase' => self::safe_token( isset( $args['decision_phase'] ) ? $args['decision_phase'] : '' ),
            'value_stage' => $value_stage,
            'soft_reasons' => self::string_list( isset( $security['soft_reasons'] ) ? $security['soft_reasons'] : array() ),
            'honeypot' => ! empty( $args['honeypot'] ),
            'challenge' => isset( $args['challenge'] ) && is_array( $args['challenge'] ) ? self::safe_meta( $args['challenge'] ) : array(),
            'ip' => ClientIp::present( ClientIp::resolve( $request, $config ), $config ),
            'uri' => Helpers::filtered_uri( $request ),
            'fields' => $fields,
            'uploads' => self::upload_metadata( $context, isset( $args['uploads'] ) ? $args['uploads'] : array() ),
        );
    }

    private static function write_record( $record, $config ) {
        $dir = self::declined_dir( $config, true );
        if ( $dir === '' ) {
            return false;
        }

        self::prune_expired( $config );
        $line = FileSink::json_line( $record );
        if ( $line === '' ) {
            return false;
        }

        return FileSink::append_dated_jsonl( $dir, self::FILE_PREFIX, self::FILE_EXT, $line, FileSink::DEFAULT_MAX_BYTES );
    }

    private static function declined_dir( $config, $create ) {
        return PrivateDir::subdir( Config::value( $config, array( 'uploads', 'dir' ), '' ), self::DIR, $create );
    }

    public static function is_declined_file( $entry ) {
        return FileSink::dated_file_date( $entry, self::FILE_PREFIX, self::FILE_EXT ) !== '';
    }

    public static function max_clear_days() {
        return self::anchor( 'RETENTION_DAYS_MAX', 365 );
    }

    public static function normalize_clear_days( $value ) {
        return self::normalize_days( $value, true );
    }

    private static function normalize_retention_days( $value ) {
        return self::normalize_days( $value, false );
    }

    private static function delete_declined_files_before_cutoff( $cutoff, $config, $options = array() ) {
        $dir = self::declined_dir( $config, false );
        if ( $dir === '' ) {
            return self::cleanup_summary( true, '' );
        }

        return FileSink::delete_matching_files(
            $dir,
            array( __CLASS__, 'is_declined_file' ),
            function ( $entry, $path ) use ( $cutoff ) {
                if ( $cutoff === null ) {
                    return true;
                }
                $mtime = @filemtime( $path );
                return is_int( $mtime ) && $mtime <= $cutoff;
            },
            $options
        );
    }

    private static function cleanup_summary( $ok, $reason ) {
        return array(
            'ok' => (bool) $ok,
            'reason' => (string) $reason,
            'scanned' => 0,
            'candidates' => 0,
            'candidate_bytes' => 0,
            'deleted' => 0,
            'deleted_bytes' => 0,
            'failed' => 0,
            'reached_limit' => false,
        );
    }

    private static function normalize_days( $value, $allow_zero ) {
        if ( is_int( $value ) ) {
            $days = $value;
        } elseif ( is_string( $value ) && preg_match( '/^[0-9]+$/', $value ) === 1 ) {
            $days = (int) $value;
        } elseif ( is_float( $value ) && floor( $value ) === $value ) {
            $days = (int) $value;
        } else {
            return null;
        }

        $min = $allow_zero ? 0 : self::anchor( 'RETENTION_DAYS_MIN', 1 );
        if ( $days < $min || $days > self::max_clear_days() ) {
            return null;
        }

        return $days;
    }

    private static function scan_records( $filters, $config, $match_callback, $max_scan ) {
        $filters = is_array( $filters ) ? $filters : array();
        $window = self::date_window( $filters );
        $records = array();
        $scanned = 0;
        $limited = false;
        $dir = self::declined_dir( $config, false );
        $max_scan = max( 0, (int) $max_scan );

        if ( $dir !== '' ) {
            foreach ( self::files_for_window( $dir, $window['from'], $window['to'] ) as $file ) {
                $handle = @fopen( $file, 'rb' );
                if ( $handle === false ) {
                    continue;
                }
                while ( ( $line = fgets( $handle ) ) !== false ) {
                    $line = trim( $line );
                    if ( $line === '' ) {
                        continue;
                    }
                    $record = json_decode( $line, true );
                    if ( ! is_array( $record ) ) {
                        continue;
                    }
                    if ( $scanned >= $max_scan ) {
                        $limited = true;
                        fclose( $handle );
                        break 2;
                    }
                    $scanned++;
                    if ( call_user_func( $match_callback, $record ) ) {
                        $records[] = $record;
                    }
                }
                fclose( $handle );
            }
        }

        return array(
            'records' => $records,
            'scanned' => $scanned,
            'limited' => $limited,
            'from' => $window['from'],
            'to' => $window['to'],
        );
    }

    private static function files_for_window( $dir, $from, $to ) {
        $entries = @scandir( $dir );
        if ( ! is_array( $entries ) ) {
            return array();
        }

        $files = array();
        foreach ( $entries as $entry ) {
            $date = FileSink::dated_file_date( $entry, self::FILE_PREFIX, self::FILE_EXT );
            if ( $date === '' || $date < $from || $date > $to ) {
                continue;
            }
            $files[] = rtrim( $dir, '/\\' ) . '/' . $entry;
        }

        rsort( $files, SORT_STRING );
        return $files;
    }

    private static function date_window( $filters ) {
        $today = gmdate( 'Ymd' );
        $default_days = self::anchor( 'DECLINED_REVIEW_ADMIN_DEFAULT_DAYS', 7 );
        $max_days = self::anchor( 'DECLINED_REVIEW_ADMIN_MAX_DAYS', 31 );
        $to = self::date_filter( isset( $filters['to'] ) ? $filters['to'] : '', $today );
        $from_default = gmdate( 'Ymd', self::date_timestamp( $to ) - ( ( $default_days - 1 ) * 86400 ) );
        $from = self::date_filter( isset( $filters['from'] ) ? $filters['from'] : '', $from_default );
        if ( $from > $to ) {
            $from = $to;
        }

        $span = (int) floor( ( self::date_timestamp( $to ) - self::date_timestamp( $from ) ) / 86400 ) + 1;
        if ( $span > $max_days ) {
            $from = gmdate( 'Ymd', self::date_timestamp( $to ) - ( ( $max_days - 1 ) * 86400 ) );
        }

        return array( 'from' => $from, 'to' => $to );
    }

    private static function matches_filters( $record, $filters ) {
        foreach ( array( 'form_id', 'decision_code' ) as $key ) {
            if ( isset( $filters[ $key ] ) && is_string( $filters[ $key ] ) && trim( $filters[ $key ] ) !== '' ) {
                if ( ! isset( $record[ $key ] ) || (string) $record[ $key ] !== trim( $filters[ $key ] ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function compare_newest( $a, $b ) {
        $at = isset( $a['ts'] ) && is_string( $a['ts'] ) ? $a['ts'] : '';
        $bt = isset( $b['ts'] ) && is_string( $b['ts'] ) ? $b['ts'] : '';
        return strcmp( $bt, $at );
    }

    private static function declared_values( $context, $values ) {
        $values = is_array( $values ) ? $values : array();
        $descriptors = isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ? $context['descriptors'] : array();
        $out = array();
        foreach ( $descriptors as $descriptor ) {
            if ( ! is_array( $descriptor ) || empty( $descriptor['key'] ) || ! is_string( $descriptor['key'] ) ) {
                continue;
            }
            $key = $descriptor['key'];
            if ( array_key_exists( $key, $values ) ) {
                $out[ $key ] = $values[ $key ];
            }
        }
        return $out;
    }

    private static function bounded_fields( $values ) {
        $out = array();
        $remaining = self::anchor( 'DECLINED_REVIEW_RECORD_FIELDS_MAX_BYTES', 65536 );
        $count = 0;
        foreach ( $values as $key => $value ) {
            if ( $count >= self::anchor( 'DECLINED_REVIEW_MAX_FIELDS', 100 ) || $remaining <= 0 ) {
                break;
            }
            if ( ! is_string( $key ) || $key === '' ) {
                continue;
            }
            $bounded = self::bounded_value( $value, $remaining );
            $out[ $key ] = $bounded['value'];
            $remaining -= $bounded['bytes'];
            $count++;
        }
        return $out;
    }

    private static function bounded_value( $value, $remaining ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $entry ) {
                if ( $remaining <= 0 ) {
                    break;
                }
                if ( ! is_scalar( $entry ) && $entry !== null && ! is_array( $entry ) ) {
                    continue;
                }
                $bounded = self::bounded_value( $entry, $remaining );
                $out[ is_scalar( $key ) ? (string) $key : count( $out ) ] = $bounded['value'];
                $remaining -= $bounded['bytes'];
            }
            $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $out ) : json_encode( $out );
            return array( 'value' => $out, 'bytes' => is_string( $encoded ) ? strlen( $encoded ) : 0 );
        }

        if ( $value === null ) {
            return array( 'value' => null, 'bytes' => 0 );
        }

        $string = is_scalar( $value ) ? (string) $value : '';
        if ( preg_match( '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $string ) === 1 ) {
            $string = '[binary omitted]';
        } else {
            $string = str_replace( array( "\r\n", "\r" ), "\n", $string );
        }
        $string = preg_replace( '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', '', $string );
        $max = min( self::anchor( 'DECLINED_REVIEW_FIELD_MAX_BYTES', 4096 ), max( 0, (int) $remaining ) );
        if ( strlen( $string ) > $max ) {
            $string = substr( $string, 0, $max );
        }
        return array( 'value' => $string, 'bytes' => strlen( $string ) );
    }

    private static function upload_metadata( $context, $uploads ) {
        $descriptors = isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ? $context['descriptors'] : array();
        $uploads = is_array( $uploads ) ? $uploads : array();
        $upload_map = UploadValue::file_map_from_payload( $uploads, self::anchor( 'DECLINED_REVIEW_FIELD_MAX_BYTES', 4096 ), true );
        $out = array();
        foreach ( $descriptors as $descriptor ) {
            if ( ! is_array( $descriptor ) || empty( $descriptor['key'] ) || ! is_string( $descriptor['key'] ) ) {
                continue;
            }
            $type = isset( $descriptor['type'] ) && is_string( $descriptor['type'] ) ? $descriptor['type'] : '';
            if ( $type !== 'file' && $type !== 'files' ) {
                continue;
            }
            $key = $descriptor['key'];
            $value = array_key_exists( $key, $uploads ) ? $uploads[ $key ] : null;
            if ( $value === null && array_key_exists( $key, $upload_map ) ) {
                $value = $upload_map[ $key ];
            }

            foreach ( UploadValue::items( $value, false ) as $item ) {
                $out[ $key ][] = self::upload_item_meta( $item );
            }
        }
        return $out;
    }

    private static function upload_item_meta( $item ) {
        return array(
            'original_name_safe' => self::bounded_value( UploadValue::display_name( $item ), self::anchor( 'DECLINED_REVIEW_FIELD_MAX_BYTES', 4096 ) )['value'],
            'size' => isset( $item['size'] ) && is_numeric( $item['size'] ) ? (int) $item['size'] : 0,
            'error' => isset( $item['error'] ) && is_numeric( $item['error'] ) ? (int) $item['error'] : 0,
            'type' => isset( $item['type'] ) && is_string( $item['type'] ) ? $item['type'] : '',
        );
    }

    private static function safe_meta( $meta ) {
        $out = array();
        foreach ( $meta as $key => $value ) {
            if ( is_string( $key ) && is_scalar( $value ) ) {
                $out[ $key ] = (string) $value;
            }
        }
        return $out;
    }

    private static function warn_write_failed( $record, $request ) {
        Logging::event(
            'warning',
            'EFORMS_DECLINED_REVIEW_WRITE_FAILED',
            array(
                'form_id' => isset( $record['form_id'] ) ? $record['form_id'] : '',
                'submission_id' => isset( $record['submission_id'] ) ? $record['submission_id'] : '',
                'reason' => 'declined_review_write_failed',
            ),
            $request
        );
    }

    private static function date_filter( $value, $fallback ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $value ) === 1 ) {
            return str_replace( '-', '', $value );
        }
        if ( preg_match( '/^[0-9]{8}$/', $value ) === 1 ) {
            return $value;
        }
        return $fallback;
    }

    private static function date_timestamp( $ymd ) {
        $time = DateTime::createFromFormat( 'Ymd H:i:s', $ymd . ' 00:00:00', new DateTimeZone( 'UTC' ) );
        return $time instanceof DateTime ? $time->getTimestamp() : time();
    }

    private static function anchor( $name, $fallback ) {
        $value = Anchors::get( $name );
        return is_int( $value ) && $value > 0 ? $value : $fallback;
    }

    private static function bounded_int( $value, $fallback, $min, $max ) {
        $value = is_numeric( $value ) ? (int) $value : (int) $fallback;
        return max( $min, min( $max, $value ) );
    }

    private static function safe_token( $value ) {
        $value = is_scalar( $value ) ? (string) $value : '';
        $value = preg_replace( '/[^A-Za-z0-9_.:-]/', '', $value );
        return is_string( $value ) ? substr( $value, 0, 128 ) : '';
    }

    private static function string_list( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }
        return array_values( array_filter( $value, 'is_string' ) );
    }

}
