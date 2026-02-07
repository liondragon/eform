<?php
/**
 * Integration test for JSONL logging schema, retention, and rotation.
 *
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 * Spec: Configuration (docs/Canonical_Spec.md#sec-configuration)
 */

require_once __DIR__ . '/../../src/Logging.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Logging/JsonlLogger.php';

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

$uploads_dir = eforms_test_tmp_root( 'eforms-logging-jsonl' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['logging']['mode'] = 'jsonl';
        $config['logging']['level'] = 2;
        $config['logging']['headers'] = true;
        $config['logging']['pii'] = false;
        $config['logging']['retention_days'] = 1;
        $config['privacy']['ip_mode'] = 'full';
        return $config;
    }
);

Config::reset_for_tests();
Logging::reset_for_tests();
JsonlLogger::set_max_bytes_for_tests( 220 );

$stale_dir = $uploads_dir . '/eforms-private/logs';
mkdir( $stale_dir, 0700, true );
$stale_file = $stale_dir . '/events-stale.jsonl';
file_put_contents( $stale_file, "{\"stale\":true}\n" );
touch( $stale_file, time() - ( 3 * 86400 ) );

$descriptors = array(
    array( 'key' => 'email', 'type' => 'email' ),
    array( 'key' => 'name', 'type' => 'text' ),
);
Logging::remember_descriptors( $descriptors );

$request = array(
    'remote_addr' => '198.51.100.20',
    'uri' => '/contact?foo=1&eforms_b=2&eforms_a=1',
    'headers' => array(
        'Origin' => 'https://Example.com/path?q=1',
        'User-Agent' => "  ExampleBrowser/1.0\t  ",
    ),
);

Logging::event(
    'warning',
    'EFORMS_ERR_TOKEN',
    array(
        'form_id' => 'contact',
        'submission_id' => 'subm-1',
        'message' => 'token failed',
        'soft_reasons' => array( 'origin_soft' ),
    ),
    $request
);

Logging::event(
    'warning',
    'EFORMS_ERR_TOKEN',
    array(
        'form_id' => 'contact',
        'submission_id' => 'subm-2',
        'message' => str_repeat( 'x', 600 ),
    ),
    $request
);

eforms_test_assert( ! file_exists( $stale_file ), 'JSONL retention should prune stale files.' );

$log_files = glob( $uploads_dir . '/eforms-private/logs/events-*.jsonl' );
eforms_test_assert( is_array( $log_files ) && count( $log_files ) >= 2, 'JSONL rotation should create at least two files when max bytes are exceeded.' );
sort( $log_files );

$payloads = array();
foreach ( $log_files as $file ) {
    $contents = file_get_contents( $file );
    if ( is_string( $contents ) && trim( $contents ) !== '' ) {
        $lines = preg_split( '/\r?\n/', trim( $contents ) );
        if ( is_array( $lines ) ) {
            foreach ( $lines as $line ) {
                if ( ! is_string( $line ) || trim( $line ) === '' ) {
                    continue;
                }

                $decoded = json_decode( $line, true );
                if ( is_array( $decoded ) ) {
                    $payloads[] = $decoded;
                }
            }
        }
    }
}

eforms_test_assert( ! empty( $payloads ), 'JSONL logging should write at least one decodable payload.' );

$payload = null;
foreach ( $payloads as $candidate ) {
    if ( isset( $candidate['submission_id'] ) && $candidate['submission_id'] === 'subm-1' ) {
        $payload = $candidate;
        break;
    }
}
eforms_test_assert( is_array( $payload ), 'JSONL payload should include the first event entry (submission_id=subm-1).' );

eforms_test_assert( isset( $payload['ts'] ) && is_string( $payload['ts'] ), 'JSONL payload should include ts.' );
eforms_test_assert( $payload['severity'] === 'warning', 'JSONL payload should include normalized severity.' );
eforms_test_assert( $payload['code'] === 'EFORMS_ERR_TOKEN', 'JSONL payload should include code.' );
eforms_test_assert( $payload['form_id'] === 'contact', 'JSONL payload should include form_id.' );
eforms_test_assert( $payload['submission_id'] === 'subm-1', 'JSONL payload should include submission_id.' );
eforms_test_assert( $payload['uri'] === '/contact?eforms_a=1&eforms_b=2', 'JSONL payload should include filtered request URI.' );
eforms_test_assert( $payload['ip'] === '198.51.100.0', 'JSONL payload should redact IP when pii=false and privacy.ip_mode=full.' );
eforms_test_assert( isset( $payload['request_id'] ) && is_string( $payload['request_id'] ) && $payload['request_id'] !== '', 'JSONL payload should include request_id.' );

$expected_desc_sha1 = sha1( json_encode( $descriptors ) );
eforms_test_assert( isset( $payload['desc_sha1'] ) && $payload['desc_sha1'] === $expected_desc_sha1, 'JSONL payload should include desc_sha1 at logging.level=2.' );

eforms_test_assert( isset( $payload['meta'] ) && is_array( $payload['meta'] ), 'JSONL payload should include meta object.' );
eforms_test_assert( isset( $payload['meta']['ua'] ) && $payload['meta']['ua'] === 'ExampleBrowser/1.0', 'JSONL meta should include normalized user agent when logging.headers=true.' );
eforms_test_assert( isset( $payload['meta']['origin'] ) && $payload['meta']['origin'] === 'https://example.com', 'JSONL meta should include normalized origin when logging.headers=true.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
Logging::reset_for_tests();
JsonlLogger::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
