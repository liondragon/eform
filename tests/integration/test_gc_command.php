<?php
/**
 * Integration tests for WP-CLI garbage-collection process status.
 *
 * Contract: Garbage collection CLI
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/managed_upload_fixtures.php';

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

function eforms_test_gc_command_batch_binding( $token, $field_key, $accept_until ) {
    return array(
        'raw_token' => $token,
        'form_id' => 'gc-command-validation',
        'instance_id' => 'gc-command-validation-instance',
        'field_key' => $field_key,
        'accept_until' => $accept_until,
    );
}

function eforms_test_gc_command_file_fingerprints( $root ) {
    $root = rtrim( $root, '/\\' );
    $out = array();
    if ( ! is_dir( $root ) ) {
        return $out;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ( $iterator as $entry ) {
        if ( ! $entry->isFile() || $entry->isLink() ) {
            continue;
        }
        $path = $entry->getPathname();
        $relative = substr( $path, strlen( $root ) + 1 );
        $out[ str_replace( '\\', '/', $relative ) ] = hash_file( 'sha256', $path );
    }
    ksort( $out, SORT_STRING );
    return $out;
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

$version_uploads = eforms_test_setup_uploads( 'eforms-gc-command-validation-version' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $version_uploads ) {
        $config['uploads']['dir'] = $version_uploads;
        return $config;
    }
);
Config::reset_for_tests();
$version_now = 1700000000;
$version_accept_until = $version_now + 600;
$version_delete_after = $version_accept_until + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
$version_field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 1048576,
    'max_files' => 1,
    'max_total_bytes' => 1048576,
);
$version_secret = eforms_test_managed_batch_secret( "\x61" );
$version_binding = eforms_test_gc_command_batch_binding( 'token-gc-command-validation-version', 'gc_command_photos', $version_accept_until );
$version_identity = str_repeat( 'a', 64 );
$version_created = UploadBatchStore::create_batch(
    $version_binding,
    $version_secret,
    $version_field,
    $version_uploads,
    $version_now,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    $version_identity
);
eforms_test_assert( ! empty( $version_created['ok'] ), 'Validation retirement fixture should create a Worker-owned batch.' );
$version_batch_id = $version_created['batch']['batch_id'];
$version_authorized = UploadBatchStore::worker_authorize_intent(
    $version_batch_id,
    $version_secret,
    'retained_photo',
    0,
    'retained-photo.png',
    128,
    'image/png',
    $version_uploads,
    array(
        'now' => $version_now + 1,
        'storage_identity' => $version_identity,
        'validation_contract_version' => 'retiring-v1',
        'upload_until' => $version_now + 120,
        'accept_until' => $version_accept_until,
        'validation_until' => $version_accept_until + 120,
        'staged_delete_after' => $version_delete_after,
    )
);
eforms_test_assert( ! empty( $version_authorized['ok'] ), 'Validation retirement fixture should authorize a retiring-version intent.' );
$version_manifest_path = $version_uploads . '/eforms-private/staged/' . Helpers::h2( $version_batch_id ) . '/' . $version_batch_id . '/' . UploadBatchStore::MANIFEST_FILENAME;
$version_manifest = json_decode( file_get_contents( $version_manifest_path ), true );
$version_capacity_only_manifest = $version_manifest;
$version_capacity_only_manifest['intents'] = array();
file_put_contents( $version_manifest_path, json_encode( $version_capacity_only_manifest, JSON_UNESCAPED_SLASHES ) );
WP_CLI::reset();
$missing_marker_retirement = GcCommand::invoke( array(), array( 'verify-validation-retirement' => 'retiring-v1' ) );
eforms_test_assert(
    empty( $missing_marker_retirement['ok'] )
        && $missing_marker_retirement['reason'] === 'retirement_marker_missing'
        && eforms_test_gc_cli_calls( 'halt' ) === array( array( 'halt', 1 ) ),
    'Validation retirement verification should fail closed until the version-specific barrier exists.'
);
WP_CLI::reset();
$begun_retirement = GcCommand::invoke( array(), array( 'begin-validation-retirement' => 'retiring-v1' ) );
eforms_test_assert(
    ! empty( $begun_retirement['ok'] )
        && $begun_retirement['state'] === 'active'
        && eforms_test_gc_cli_calls( 'success' ) !== array()
        && eforms_test_gc_cli_calls( 'halt' ) === array(),
    'Beginning validation retirement should persist the version-specific barrier.'
);
WP_CLI::reset();
$capacity_only_retirement = GcCommand::invoke( array(), array( 'verify-validation-retirement' => 'retiring-v1' ) );
eforms_test_assert(
    ! empty( $capacity_only_retirement['ok'] )
        && ! empty( $capacity_only_retirement['blocked'] )
        && $capacity_only_retirement['references'] === 1
        && $capacity_only_retirement['pending'] === 1
        && $capacity_only_retirement['by_family']['capacity'] === 1,
    'Validation retirement should block on a Worker capacity-only reservation whose manifest write was lost.'
);
file_put_contents( $version_manifest_path, json_encode( $version_manifest, JSON_UNESCAPED_SLASHES ) );
$version_completed = UploadBatchStore::worker_complete_stored_receipt(
    $version_batch_id,
    $version_secret,
    'retained_photo',
    eforms_test_worker_stored_receipt( $version_manifest, 'retained_photo', 'retained-version', 'retained-etag' ),
    $version_uploads,
    $version_now + 2
);
eforms_test_assert( ! empty( $version_completed['ok'] ), 'Validation retirement fixture should register a retained Worker item.' );
$version_claim = UploadBatchStore::worker_claim_finalization(
    $version_batch_id,
    $version_secret,
    $version_binding,
    $version_field,
    array( UploadValue::review_staged_item( $version_completed['item'] ) ),
    'gc-command-validation-submission',
    $version_uploads,
    $version_now + 3
);
eforms_test_assert( ! empty( $version_claim['ok'] ), 'Validation retirement fixture should claim finalization with the retained Worker item.' );
$version_finalized = UploadBatchStore::worker_finalize(
    $version_batch_id,
    'gc-command-validation-submission',
    $version_uploads,
    $version_now + 4
);
eforms_test_assert( ! empty( $version_finalized['ok'] ), 'Validation retirement fixture should finalize the retained Worker manifest.' );
$version_submission_manifest_path = $version_uploads . '/eforms-private/submissions/' . Helpers::h2( 'gc-command-validation-submission' ) . '/gc-command-validation-submission/' . UploadBatchStore::MANIFEST_FILENAME;
$runner_scan = GcRunner::run( array( 'dry_run' => true, 'limit' => 10, 'now' => $version_now + 10 ) );
eforms_test_assert(
    ! empty( $runner_scan['ok'] )
        && $runner_scan['reason'] === ''
        && isset( $runner_scan['by_type']['finalized_submissions']['scanned'] )
        && $runner_scan['by_type']['finalized_submissions']['scanned'] === 1
        && $runner_scan['by_type']['finalized_submissions']['errors'] === 0,
    'Managed GC runner should scan finalized schema-7 Worker manifests without a schema-6 manifest error.'
);

$fingerprints_before = eforms_test_gc_command_file_fingerprints( $version_uploads . '/eforms-private' );
WP_CLI::reset();
$blocked_retirement = GcCommand::invoke( array(), array( 'verify-validation-retirement' => 'retiring-v1' ) );
$fingerprints_after_blocked = eforms_test_gc_command_file_fingerprints( $version_uploads . '/eforms-private' );
eforms_test_assert(
    ! empty( $blocked_retirement['ok'] )
        && ! empty( $blocked_retirement['blocked'] )
        && $blocked_retirement['reason'] === 'retained_validation_contract_references'
        && $blocked_retirement['references'] === 1
        && $blocked_retirement['accepted_artifacts'] === 1
        && $blocked_retirement['pending'] === 0
        && $blocked_retirement['by_family']['finalized'] === 1,
    'Validation retirement should block while a retained accepted Worker artifact references the retiring contract.'
);
eforms_test_assert( eforms_test_gc_cli_calls( 'halt' ) === array( array( 'halt', 1 ) ), 'Blocked validation retirement should return a nonzero operator status.' );
eforms_test_assert( $fingerprints_after_blocked === $fingerprints_before, 'Blocked validation retirement verification must not write manifests, capacity, locks, or marker progress.' );

WP_CLI::reset();
$ready_missing_marker = GcCommand::invoke( array(), array( 'verify-validation-retirement' => 'future-v2' ) );
eforms_test_assert(
    empty( $ready_missing_marker['ok'] )
        && $ready_missing_marker['reason'] === 'retirement_marker_missing'
        && eforms_test_gc_cli_calls( 'halt' ) === array( array( 'halt', 1 ) ),
    'Ready validation retirement should also require a matching barrier.'
);
WP_CLI::reset();
$future_begun = GcCommand::invoke( array(), array( 'begin-validation-retirement' => 'future-v2' ) );
eforms_test_assert( ! empty( $future_begun['ok'] ) && $future_begun['state'] === 'active', 'A separate validation version should get its own retirement barrier.' );
$fingerprints_before_ready = eforms_test_gc_command_file_fingerprints( $version_uploads . '/eforms-private' );
WP_CLI::reset();
$ready_retirement = GcCommand::invoke( array(), array( 'verify-validation-retirement' => 'future-v2' ) );
$fingerprints_after_ready = eforms_test_gc_command_file_fingerprints( $version_uploads . '/eforms-private' );
eforms_test_assert(
    ! empty( $ready_retirement['ok'] )
        && empty( $ready_retirement['blocked'] )
        && $ready_retirement['reason'] === 'ready'
        && $ready_retirement['marker_state'] === 'ready'
        && $ready_retirement['references'] === 0
        && $ready_retirement['scanned'] === 1,
    'Validation retirement should pass when retained manifests do not reference the requested contract.'
);
eforms_test_assert( count( eforms_test_gc_cli_calls( 'success' ) ) === 1 && eforms_test_gc_cli_calls( 'halt' ) === array(), 'Ready validation retirement should emit success and not halt.' );
eforms_test_assert( $fingerprints_after_ready !== $fingerprints_before_ready, 'Ready validation retirement verification should persist bounded marker readiness.' );

WP_CLI::reset();
$current_begun = GcCommand::invoke( array(), array( 'begin-validation-retirement' => WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION ) );
eforms_test_assert( ! empty( $current_begun['ok'] ), 'The current validation version fixture should create a barrier for completion refusal proof.' );
WP_CLI::reset();
$current_complete = GcCommand::invoke(
    array(),
    array(
        'complete-validation-retirement' => WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION,
        '_worker_health_result' => array(
            'ok' => true,
            'worker_ready' => true,
            'validation_contract_ready' => true,
            'validation_contract_version' => WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION,
        ),
    )
);
eforms_test_assert(
    empty( $current_complete['ok'] )
        && $current_complete['reason'] === 'wordpress_validation_contract_not_switched'
        && eforms_test_gc_cli_calls( 'halt' ) === array( array( 'halt', 1 ) ),
    'Validation retirement completion must fail while WordPress still signs the retired version.'
);

WP_CLI::reset();
$unconfirmed_begun = GcCommand::invoke( array(), array( 'begin-validation-retirement' => 'unconfirmed-v2' ) );
eforms_test_assert( ! empty( $unconfirmed_begun['ok'] ), 'The unconfirmed validation version fixture should create a barrier.' );
WP_CLI::reset();
$unconfirmed_ready = GcCommand::invoke( array(), array( 'verify-validation-retirement' => 'unconfirmed-v2' ) );
eforms_test_assert( ! empty( $unconfirmed_ready['ok'] ) && $unconfirmed_ready['reason'] === 'ready', 'The unconfirmed validation version fixture should become ready.' );
WP_CLI::reset();
$unconfirmed_complete = GcCommand::invoke(
    array(),
    array(
        'complete-validation-retirement' => 'unconfirmed-v2',
        '_worker_health_result' => array(
            'ok' => true,
            'worker_ready' => true,
            'validation_contract_ready' => true,
            'validation_contract_version' => 'wrong-version',
        ),
    )
);
eforms_test_assert(
    empty( $unconfirmed_complete['ok'] )
        && $unconfirmed_complete['reason'] === 'worker_validation_contract_unconfirmed'
        && eforms_test_gc_cli_calls( 'halt' ) === array( array( 'halt', 1 ) ),
    'Validation retirement completion must retain a ready barrier when Worker health does not confirm the configured contract.'
);

WP_CLI::reset();
$future_complete = GcCommand::invoke(
    array(),
    array(
        'complete-validation-retirement' => 'future-v2',
        '_worker_health_result' => array(
            'ok' => true,
            'worker_ready' => true,
            'validation_contract_ready' => true,
            'validation_contract_version' => WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION,
        ),
    )
);
eforms_test_assert(
    ! empty( $future_complete['ok'] )
        && $future_complete['state'] === 'complete'
        && eforms_test_gc_cli_calls( 'success' ) !== array()
        && eforms_test_gc_cli_calls( 'halt' ) === array(),
    'Validation retirement completion should remove a ready barrier only after Worker health confirms the configured version.'
);
WP_CLI::reset();
$future_after_complete = GcCommand::invoke( array(), array( 'verify-validation-retirement' => 'future-v2' ) );
eforms_test_assert(
    empty( $future_after_complete['ok'] )
        && $future_after_complete['reason'] === 'retirement_marker_missing'
        && eforms_test_gc_cli_calls( 'halt' ) === array( array( 'halt', 1 ) ),
    'Completed validation retirement should remove the barrier.'
);

$version_manifest_backup_path = $version_submission_manifest_path . '.retirement-missing';
eforms_test_assert( rename( $version_submission_manifest_path, $version_manifest_backup_path ), 'The validation retirement fixture should temporarily remove a finalized manifest.' );
WP_CLI::reset();
$missing_manifest_retirement = GcCommand::invoke( array(), array( 'verify-validation-retirement' => 'retiring-v1' ) );
eforms_test_assert(
    empty( $missing_manifest_retirement['ok'] )
        && $missing_manifest_retirement['reason'] === 'manifest_invalid'
        && eforms_test_gc_cli_calls( 'halt' ) === array( array( 'halt', 1 ) ),
    'Validation retirement readiness must fail closed when a finalized aggregate manifest is missing.'
);
eforms_test_assert( rename( $version_manifest_backup_path, $version_submission_manifest_path ), 'The validation retirement fixture should restore the finalized manifest.' );

$version_schema7_manifest = json_decode( file_get_contents( $version_submission_manifest_path ), true );
$version_schema6_worker_manifest = $version_schema7_manifest;
$version_schema6_worker_manifest['version'] = UploadBatchStore::MANIFEST_VERSION;
file_put_contents( $version_submission_manifest_path, json_encode( $version_schema6_worker_manifest, JSON_UNESCAPED_SLASHES ) );
WP_CLI::reset();
$unsupported_retirement = GcCommand::invoke( array(), array( 'verify-validation-retirement' => 'retiring-v1' ) );
eforms_test_assert(
    empty( $unsupported_retirement['ok'] )
        && $unsupported_retirement['reason'] === 'unsupported_worker_manifest_version'
        && eforms_test_gc_cli_calls( 'halt' ) === array( array( 'halt', 1 ) ),
    'Validation retirement readiness must fail closed on retained Worker manifests from an unsupported schema.'
);
file_put_contents( $version_submission_manifest_path, json_encode( $version_schema7_manifest, JSON_UNESCAPED_SLASHES ) );

eforms_test_remove_tree( $version_uploads );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
