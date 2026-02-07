<?php
/**
 * Integration test for fail2ban line format, retention, and rotation.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

require_once __DIR__ . '/../../src/Logging.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Logging/Fail2banLogger.php';

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

$uploads_dir = eforms_test_tmp_root( 'eforms-fail2ban' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['logging']['mode'] = 'off';
        $config['logging']['fail2ban']['target'] = 'file';
        $config['logging']['fail2ban']['file'] = 'f2b/eforms.log';
        $config['logging']['fail2ban']['retention_days'] = 1;
        $config['privacy']['ip_mode'] = 'hash';
        return $config;
    }
);

Config::reset_for_tests();
Logging::reset_for_tests();
Fail2banLogger::set_max_bytes_for_tests( 120 );

$f2b_dir = $uploads_dir . '/f2b';
mkdir( $f2b_dir, 0700, true );
$stale_rotated = $f2b_dir . '/eforms.log.999';
file_put_contents( $stale_rotated, "old\n" );
touch( $stale_rotated, time() - ( 3 * 86400 ) );

$request = array(
    'remote_addr' => '203.0.113.77',
);

Logging::event(
    'warning',
    'EFORMS_ERR_THROTTLED',
    array( 'form_id' => 'contact' ),
    $request
);

$f2b_path = $f2b_dir . '/eforms.log';
eforms_test_assert( file_exists( $f2b_path ), 'Fail2ban logger should create the configured log file.' );
eforms_test_assert( ! file_exists( $stale_rotated ), 'Fail2ban retention should prune stale rotated files.' );

$line = trim( (string) file_get_contents( $f2b_path ) );
eforms_test_assert( strpos( $line, 'eforms[f2b] ts=' ) === 0, 'Fail2ban line should start with the expected prefix.' );
eforms_test_assert( strpos( $line, 'code=EFORMS_ERR_THROTTLED' ) !== false, 'Fail2ban line should include the error code.' );
eforms_test_assert( strpos( $line, 'ip=203.0.113.77' ) !== false, 'Fail2ban line should emit resolved client IP in plaintext regardless of privacy.ip_mode.' );
eforms_test_assert( strpos( $line, 'form=contact' ) !== false, 'Fail2ban line should include form id.' );

$before = count( preg_split( '/\r?\n/', trim( (string) file_get_contents( $f2b_path ) ) ) );
Logging::event(
    'warning',
    'EFORMS_CONFIG_CLAMPED',
    array( 'form_id' => 'contact' ),
    $request
);
$after = count( preg_split( '/\r?\n/', trim( (string) file_get_contents( $f2b_path ) ) ) );
eforms_test_assert( $before === $after, 'Fail2ban emission should ignore non-EFORMS_ERR_* codes.' );

for ( $i = 0; $i < 8; $i++ ) {
    Logging::event(
        'error',
        'EFORMS_ERR_TOKEN',
        array( 'form_id' => 'contact' ),
        $request
    );
}

eforms_test_assert( file_exists( $f2b_path . '.1' ), 'Fail2ban logger should rotate when file exceeds the internal max size.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
Logging::reset_for_tests();
Fail2banLogger::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
