<?php
/**
 * JSONL logging sink with rotation and retention.
 *
 * Contract: Logging
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';
require_once __DIR__ . '/FileSink.php';

class JsonlLogger {
    const LOG_DIR = 'logs';
    const FILE_PREFIX = 'events-';
    const FILE_EXT = '.jsonl';

    private static $max_bytes_override = null;

    /**
     * Write one JSON event line.
     *
     * @param array $event
     * @param array $config
     * @return bool
     */
    public static function write_event( $event, $config ) {
        if ( ! is_array( $event ) ) {
            return false;
        }

        $dir = self::logs_dir( $config );
        if ( $dir === '' ) {
            return false;
        }

        $line = FileSink::json_line( $event );
        if ( $line === '' ) {
            return false;
        }

        self::prune_old_files( $dir, self::retention_days( $config ) );
        return FileSink::append_dated_jsonl( $dir, self::FILE_PREFIX, self::FILE_EXT, $line, self::max_bytes() );
    }

    /**
     * Test helper to make rotation deterministic.
     *
     * @param int|null $bytes
     * @return void
     */
    public static function set_max_bytes_for_tests( $bytes ) {
        if ( $bytes === null ) {
            self::$max_bytes_override = null;
            return;
        }

        $value = (int) $bytes;
        self::$max_bytes_override = $value > 0 ? $value : 1;
    }

    public static function reset_for_tests() {
        self::$max_bytes_override = null;
    }

    private static function logs_dir( $config ) {
        return PrivateDir::subdir( Config::value( $config, array( 'uploads', 'dir' ), '' ), self::LOG_DIR, true );
    }

    private static function retention_days( $config ) {
        $days = Config::value( $config, array( 'logging', 'retention_days' ), 1 );
        $days = is_numeric( $days ) ? (int) $days : 1;
        return $days > 0 ? $days : 1;
    }

    private static function max_bytes() {
        if ( is_int( self::$max_bytes_override ) && self::$max_bytes_override > 0 ) {
            return self::$max_bytes_override;
        }

        return FileSink::DEFAULT_MAX_BYTES;
    }

    private static function prune_old_files( $dir, $retention_days ) {
        FileSink::prune_old_files(
            $dir,
            $retention_days,
            function ( $entry ) {
                return FileSink::dated_file_date( $entry, self::FILE_PREFIX, self::FILE_EXT ) !== '';
            }
        );
    }
}
