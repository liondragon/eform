<?php
/**
 * Integration tests for WP-CLI garbage-collection process status.
 *
 * Contract: Garbage collection CLI
 */

require_once __DIR__ . '/../bootstrap.php';

if ( ! class_exists( 'WP_CLI' ) ) {
    class WP_CLI {
        public static $calls = array();

        public static function reset() {
            self::$calls = array();
        }

        public static function warning( $message ) {
            self::$calls[] = array( 'warning', $message );
        }

        public static function success( $message ) {
            self::$calls[] = array( 'success', $message );
        }

        public static function log( $message ) {
            self::$calls[] = array( 'log', $message );
        }

        public static function halt( $exit_code ) {
            self::$calls[] = array( 'halt', (int) $exit_code );
        }
    }
}

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
require_once __DIR__ . '/../../src/Cli/GcCommand.php';

function eforms_test_gc_cli_calls( $method ) {
    return array_values(
        array_filter(
            WP_CLI::$calls,
            function ( $call ) use ( $method ) {
                return isset( $call[0] ) && $call[0] === $method;
            }
        )
    );
}

$missing_uploads = eforms_test_tmp_root( 'eforms-gc-command-missing' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $missing_uploads ) {
        $config['uploads']['dir'] = $missing_uploads;
        return $config;
    }
);
Config::reset_for_tests();
WP_CLI::reset();

$failed = GcCommand::invoke( array(), array( 'dry-run' => true ) );
eforms_test_assert( empty( $failed['ok'] ) && $failed['reason'] === 'uploads_dir_unavailable', 'GC command fixture should fail before opening unavailable storage.' );
eforms_test_assert( count( eforms_test_gc_cli_calls( 'warning' ) ) === 1, 'An actual GC failure should emit one warning.' );
eforms_test_assert( eforms_test_gc_cli_calls( 'halt' ) === array( array( 'halt', 1 ) ), 'An actual GC failure should return a nonzero process status.' );

$uploads_dir = eforms_test_setup_uploads( 'eforms-gc-command-lock' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        return $config;
    }
);
Config::reset_for_tests();
$private = PrivateDir::ensure( $uploads_dir );
eforms_test_assert( is_array( $private ) && ! empty( $private['ok'] ), 'GC command lock fixture should provision private storage.' );
$lock_path = $private['path'] . '/' . GcRunner::LOCK_FILENAME;
$lock_handle = fopen( $lock_path, 'c+' );
eforms_test_assert( is_resource( $lock_handle ) && flock( $lock_handle, LOCK_EX | LOCK_NB ), 'GC command fixture should hold the runner lock.' );
WP_CLI::reset();

$locked = GcCommand::invoke( array(), array( 'dry-run' => true ) );
eforms_test_assert( empty( $locked['ok'] ) && ! empty( $locked['locked'] ), 'Concurrent GC should report a skipped locked run.' );
eforms_test_assert( count( eforms_test_gc_cli_calls( 'warning' ) ) === 1, 'A skipped locked run should emit one warning.' );
eforms_test_assert( eforms_test_gc_cli_calls( 'halt' ) === array(), 'A skipped locked run should remain nonfatal.' );

flock( $lock_handle, LOCK_UN );
fclose( $lock_handle );
WP_CLI::reset();
$success = GcCommand::invoke( array(), array( 'dry-run' => true ) );
eforms_test_assert( ! empty( $success['ok'] ), 'GC command should succeed after lock contention clears.' );
eforms_test_assert( $success['limit'] === Anchors::get( 'GC_DEFAULT_BATCH_LIMIT' ), 'GC command should resolve its omitted batch limit through Anchors.' );
eforms_test_assert( count( eforms_test_gc_cli_calls( 'success' ) ) === 1, 'Successful GC should emit one success message.' );
eforms_test_assert( eforms_test_gc_cli_calls( 'halt' ) === array(), 'Successful GC should not halt the process.' );

$bounded = GcCommand::invoke( array(), array( 'dry-run' => true, 'limit' => 1 ) );
eforms_test_assert( $bounded['limit'] === 1, 'GC command should preserve an explicit positive batch limit.' );
$invalid_limit = GcCommand::invoke( array(), array( 'dry-run' => true, 'limit' => 'invalid' ) );
eforms_test_assert( $invalid_limit['limit'] === Anchors::get( 'GC_DEFAULT_BATCH_LIMIT' ), 'GC runner should normalize an invalid CLI batch limit through Anchors.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
