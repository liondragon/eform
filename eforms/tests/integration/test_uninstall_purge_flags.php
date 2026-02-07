<?php
/**
 * Integration test for uninstall purge flag behavior.
 *
 * Spec: Architecture and file layout (docs/Canonical_Spec.md#sec-architecture)
 * Spec: Configuration (docs/Canonical_Spec.md#sec-configuration)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => isset( $GLOBALS['eforms_test_uploads_dir'] ) ? $GLOBALS['eforms_test_uploads_dir'] : '',
        );
    }
}

if ( ! function_exists( 'eforms_test_remove_tree' ) ) {
    function eforms_test_remove_tree( $path ) {
        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            @unlink( $path );
            return;
        }

        $items = array_diff( scandir( $path ), array( '.', '..' ) );
        foreach ( $items as $item ) {
            eforms_test_remove_tree( $path . '/' . $item );
        }
        @rmdir( $path );
    }
}

if ( ! function_exists( 'eforms_test_uninstall_write_file' ) ) {
    function eforms_test_uninstall_write_file( $path, $content = 'x' ) {
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0700, true );
        }

        file_put_contents( $path, $content );
        chmod( $path, 0600 );
    }
}

if ( ! function_exists( 'eforms_test_uninstall_seed_runtime' ) ) {
    function eforms_test_uninstall_seed_runtime( $uploads_dir ) {
        $private = PrivateDir::ensure( $uploads_dir );
        eforms_test_assert( is_array( $private ) && ! empty( $private['ok'] ), 'Test setup should create private directory.' );
        $private_dir = $private['path'];

        $paths = array(
            'token' => $private_dir . '/tokens/aa/token.json',
            'ledger' => $private_dir . '/ledger/contact/aa/submission.used',
            'upload' => $private_dir . '/uploads/20260101/file.bin',
            'throttle' => $private_dir . '/throttle/aa/ip.tally',
            'log' => $private_dir . '/logs/events-20260101.jsonl',
            'f2b' => rtrim( $uploads_dir, '/\\' ) . '/f2b/eforms.log',
            'f2b_rotated' => rtrim( $uploads_dir, '/\\' ) . '/f2b/eforms.log.1',
            'sentinel' => rtrim( $uploads_dir, '/\\' ) . '/keep-me.txt',
        );

        eforms_test_uninstall_write_file( $paths['token'], '{}' );
        eforms_test_uninstall_write_file( $paths['ledger'], '1' );
        eforms_test_uninstall_write_file( $paths['upload'], 'payload' );
        eforms_test_uninstall_write_file( $paths['throttle'], '1' );
        eforms_test_uninstall_write_file( $paths['log'], '{"ok":true}' . "\n" );
        eforms_test_uninstall_write_file( $paths['f2b'], "eforms[f2b]\n" );
        eforms_test_uninstall_write_file( $paths['f2b_rotated'], "eforms[f2b]\n" );
        eforms_test_uninstall_write_file( $paths['sentinel'], 'keep' );

        return $paths;
    }
}

if ( ! function_exists( 'eforms_test_uninstall_run' ) ) {
    function eforms_test_uninstall_run( $uploads_dir, $purge_logs, $purge_uploads ) {
        eforms_test_set_filter(
            'eforms_config',
            function ( $config ) use ( $uploads_dir, $purge_logs, $purge_uploads ) {
                $config['uploads']['dir'] = $uploads_dir;
                $config['install']['uninstall']['purge_logs'] = (bool) $purge_logs;
                $config['install']['uninstall']['purge_uploads'] = (bool) $purge_uploads;
                $config['logging']['fail2ban']['file'] = 'f2b/eforms.log';
                return $config;
            }
        );

        Config::reset_for_tests();
        if ( function_exists( 'eforms_uninstall_run' ) ) {
            eforms_uninstall_run();
            return;
        }

        require __DIR__ . '/../../uninstall.php';
    }
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    define( 'WP_UNINSTALL_PLUGIN', true );
}

$uploads_dir = eforms_test_tmp_root( 'eforms-uninstall-purge' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

// Case 1: Both flags disabled; nothing should be removed.
$case1 = eforms_test_uninstall_seed_runtime( $uploads_dir );
eforms_test_uninstall_run( $uploads_dir, false, false );
eforms_test_assert( file_exists( $case1['token'] ), 'Token file should remain when purge flags are disabled.' );
eforms_test_assert( file_exists( $case1['log'] ), 'Log file should remain when purge flags are disabled.' );
eforms_test_assert( file_exists( $case1['f2b_rotated'] ), 'Fail2ban rotated file should remain when purge flags are disabled.' );

eforms_test_remove_tree( $uploads_dir . '/eforms-private' );
eforms_test_remove_tree( $uploads_dir . '/f2b' );

// Case 2: purge_logs=true should remove logs + fail2ban artifacts only.
$case2 = eforms_test_uninstall_seed_runtime( $uploads_dir );
eforms_test_uninstall_run( $uploads_dir, true, false );
eforms_test_assert( ! file_exists( $case2['log'] ), 'Log file should be removed when purge_logs=true.' );
eforms_test_assert( ! file_exists( dirname( $case2['log'] ) ), 'Logs directory should be removed when purge_logs=true.' );
eforms_test_assert( ! file_exists( $case2['f2b'] ), 'Fail2ban file should be removed when purge_logs=true.' );
eforms_test_assert( ! file_exists( $case2['f2b_rotated'] ), 'Fail2ban rotated siblings should be removed when purge_logs=true.' );
eforms_test_assert( file_exists( $case2['token'] ), 'Token file should remain when only purge_logs=true.' );
eforms_test_assert( file_exists( $case2['upload'] ), 'Upload file should remain when only purge_logs=true.' );
eforms_test_assert( file_exists( $case2['sentinel'] ), 'Unrelated files under uploads root must not be removed.' );

eforms_test_remove_tree( $uploads_dir . '/eforms-private' );
eforms_test_remove_tree( $uploads_dir . '/f2b' );

// Case 3: purge_uploads=true should remove non-log runtime artifacts only.
$case3 = eforms_test_uninstall_seed_runtime( $uploads_dir );
eforms_test_uninstall_run( $uploads_dir, false, true );
eforms_test_assert( ! file_exists( $case3['token'] ), 'Token file should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( dirname( $case3['token'] ) ), 'Tokens subtree should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['ledger'] ), 'Ledger markers should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['upload'] ), 'Upload files should be removed when purge_uploads=true.' );
eforms_test_assert( ! file_exists( $case3['throttle'] ), 'Throttle state should be removed when purge_uploads=true.' );
eforms_test_assert( file_exists( $case3['log'] ), 'Logs should remain when purge_logs=false.' );
eforms_test_assert( file_exists( $case3['f2b'] ), 'Fail2ban file should remain when purge_logs=false.' );
eforms_test_assert( file_exists( $case3['sentinel'] ), 'Unrelated files under uploads root must not be removed.' );

eforms_test_remove_tree( $uploads_dir . '/eforms-private' );

// Case 4: purge_logs=true should still clean fail2ban family even if private dir is absent.
$f2b_only = rtrim( $uploads_dir, '/\\' ) . '/f2b/eforms.log';
$f2b_only_rotated = $f2b_only . '.1';
eforms_test_uninstall_write_file( $f2b_only, "eforms[f2b]\n" );
eforms_test_uninstall_write_file( $f2b_only_rotated, "eforms[f2b]\n" );
eforms_test_uninstall_run( $uploads_dir, true, false );
eforms_test_assert( ! file_exists( $f2b_only ), 'Fail2ban file should be removed even when private dir is missing.' );
eforms_test_assert( ! file_exists( $f2b_only_rotated ), 'Fail2ban rotated siblings should be removed even when private dir is missing.' );
eforms_test_assert( file_exists( $case3['sentinel'] ), 'Unrelated files under uploads root must not be removed when only fail2ban cleanup runs.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
