<?php
/**
 * Integration test for logging level-2 desc_sha1 emission.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

require_once __DIR__ . '/../../src/Logging.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';

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

$uploads_dir_level2 = eforms_test_tmp_root( 'eforms-desc-sha1-level2' );
mkdir( $uploads_dir_level2, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir_level2;

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir_level2 ) {
        $config['uploads']['dir'] = $uploads_dir_level2;
        $config['logging']['mode'] = 'jsonl';
        $config['logging']['level'] = 2;
        return $config;
    }
);

Config::reset_for_tests();
Logging::reset_for_tests();

$descriptors = array(
    array( 'key' => 'email', 'type' => 'email' ),
);
$expected = sha1( json_encode( $descriptors ) );

Logging::remember_descriptors( $descriptors );
$request = array( 'remote_addr' => '198.51.100.11', 'uri' => '/submit?eforms_ok=1' );

Logging::event(
    'error',
    'EFORMS_ERR_TOKEN',
    array(
        'form_id' => 'contact',
        'submission_id' => 'subm-1',
    ),
    $request
);

Logging::event(
    'warning',
    'EFORMS_ERR_EMAIL_SEND',
    array(
        'form_id' => 'contact',
        'submission_id' => 'subm-1',
    ),
    $request
);

$files = glob( $uploads_dir_level2 . '/eforms-private/logs/events-*.jsonl' );
eforms_test_assert( is_array( $files ) && count( $files ) >= 1, 'JSONL file should exist for level-2 desc_sha1 checks.' );
$lines = preg_split( '/\r?\n/', trim( (string) file_get_contents( $files[0] ) ) );
eforms_test_assert( is_array( $lines ) && count( $lines ) >= 2, 'Level-2 logging should emit both test events.' );

$first = json_decode( $lines[0], true );
$second = json_decode( $lines[1], true );
eforms_test_assert( isset( $first['desc_sha1'] ) && $first['desc_sha1'] === $expected, 'Level-2 event should include desc_sha1.' );
eforms_test_assert( isset( $second['desc_sha1'] ) && $second['desc_sha1'] === $expected, 'desc_sha1 should be reused for subsequent events in the same request context.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
Logging::reset_for_tests();
eforms_test_remove_tree( $uploads_dir_level2 );

$uploads_dir_level1 = eforms_test_tmp_root( 'eforms-desc-sha1-level1' );
mkdir( $uploads_dir_level1, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir_level1;

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir_level1 ) {
        $config['uploads']['dir'] = $uploads_dir_level1;
        $config['logging']['mode'] = 'jsonl';
        $config['logging']['level'] = 1;
        return $config;
    }
);

Config::reset_for_tests();
Logging::reset_for_tests();
Logging::remember_descriptors( $descriptors );
Logging::event(
    'warning',
    'EFORMS_ERR_TOKEN',
    array(
        'form_id' => 'contact',
        'submission_id' => 'subm-2',
    ),
    $request
);

$files = glob( $uploads_dir_level1 . '/eforms-private/logs/events-*.jsonl' );
eforms_test_assert( is_array( $files ) && count( $files ) >= 1, 'JSONL file should exist for level-1 desc_sha1 checks.' );
$payload = json_decode( trim( (string) file_get_contents( $files[0] ) ), true );
eforms_test_assert( is_array( $payload ), 'Level-1 JSONL payload should decode.' );
eforms_test_assert( ! isset( $payload['desc_sha1'] ), 'Level-1 event should not include desc_sha1.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
Logging::reset_for_tests();
eforms_test_remove_tree( $uploads_dir_level1 );
