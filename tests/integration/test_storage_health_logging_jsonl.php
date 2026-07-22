<?php
/**
 * Integration test for storage-health JSONL logging disclosure boundaries.
 *
 * Contract: Shared lifecycle and storage contract
 * Contract: Logging
 */

require_once __DIR__ . '/../../src/Logging.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Logging/JsonlLogger.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';

$uploads_dir = eforms_test_tmp_root( 'eforms-storage-health-jsonl' );
mkdir( $uploads_dir, 0700, true );
$private = PrivateDir::ensure( $uploads_dir );
file_put_contents( $private['path'] . '/' . PrivateDir::PURGE_MARKER_FILENAME, "purged\n" );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['logging']['mode'] = 'jsonl';
        $config['logging']['level'] = 1;
        $config['logging']['pii'] = false;
        return $config;
    }
);
Config::reset_for_tests();
Logging::reset_for_tests();
JsonlLogger::reset_for_tests();
StorageHealth::reset_for_tests();

$result = StorageHealth::check( $uploads_dir );
eforms_test_assert( $result['ok'] === false && $result['reason'] === 'managed_purged', 'JSONL fixture should trigger a storage health warning.' );
$log_files = glob( $private['path'] . '/logs/events-*.jsonl' );
eforms_test_assert( is_array( $log_files ) && count( $log_files ) === 1, 'Storage health warning should write one JSONL log.' );
$jsonl = (string) file_get_contents( $log_files[0] );
eforms_test_assert( strpos( $jsonl, 'EFORMS_ERR_STORAGE_UNAVAILABLE' ) !== false, 'Storage health JSONL should include the storage unavailable code.' );
eforms_test_assert( strpos( $jsonl, '"reason":"managed_purged"' ) !== false, 'Storage health JSONL should include the closed reason code.' );
eforms_test_assert( strpos( $jsonl, $uploads_dir ) === false && strpos( $jsonl, 'eforms-private' ) === false, 'Storage health JSONL should not disclose raw storage paths.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
Logging::reset_for_tests();
JsonlLogger::reset_for_tests();
StorageHealth::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
