<?php
/**
 * Integration tests for the operator runtime health diagnostic.
 *
 * Contract: Runtime Health Diagnostic
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Cli/RuntimeHealthCommand.php';
require_once __DIR__ . '/../../src/Diagnostics/RuntimeHealthDiagnostic.php';

$uploads_dir = eforms_test_setup_uploads( 'eforms-runtime-health' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['throttle']['enable'] = true;
        return $config;
    }
);
Config::reset_snapshot();

$observations = array(
    'fileinfo' => true,
    'memory_limit' => -1,
    'execution_limit' => 0,
    'disk_total_bytes' => Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ) + Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
    'disk_free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ),
    'upload_max_filesize' => '64M',
    'post_max_size' => '64M',
);
$result = RuntimeHealthDiagnostic::run( $observations );
$command_result = RuntimeHealthCommand::run( $observations );
$rows = RuntimeHealthDiagnostic::rows( $result );

eforms_test_assert( $result['ok'] === true, 'Runtime health should pass with warnings in the default test runtime.' );
eforms_test_assert( $result['exit_code'] === 0, 'Warn-only runtime health should expose exit_code=0.' );
eforms_test_assert( count( $result['checks'] ) === 16, 'Runtime health should run the focused readiness check set.' );
eforms_test_assert( $command_result['checks'] === $result['checks'], 'CLI adapter should expose the shared runtime health result without its own check implementation.' );
eforms_test_assert( function_exists( 'eforms_cli_doctor' ), 'Bootstrap should expose the wp eforms doctor handler.' );
eforms_test_assert( RuntimeHealthDiagnostic::summary_line( $result ) === '15 passed, 1 warning, 0 failed', 'Runtime health should summarize pass/warn/fail counts.' );

$checks = array();
foreach ( $result['checks'] as $check ) {
    $checks[ $check['name'] ] = $check;
}

foreach ( array( 'uploads-base', 'private-storage', 'runtime-dirs', 'managed-upload-dirs', 'staged-artifact-readiness', 'review-route-readiness', 'review-preview-readiness', 'managed-capacity', 'staged-request-limits', 'staged-throttle', 'templates', 'mail-format', 'gc-readiness', 'cli-bootstrap', 'config-sources', 'challenge-config' ) as $name ) {
    eforms_test_assert( isset( $checks[ $name ] ), 'Missing runtime health check: ' . $name );
    eforms_test_assert( isset( $checks[ $name ]['observed'] ) && $checks[ $name ]['observed'] !== '', 'Runtime health should report observed result: ' . $name );
    eforms_test_assert( isset( $checks[ $name ]['expected'] ) && $checks[ $name ]['expected'] !== '', 'Runtime health should report expected result: ' . $name );
}

eforms_test_assert( $checks['cli-bootstrap']['result'] === 'WARN', 'Non-CLI test runtime should produce a CLI bootstrap warning.' );
eforms_test_assert( $checks['gc-readiness']['result'] === 'PASS', 'Fresh runtime storage should pass GC dry-run readiness.' );
eforms_test_assert( $checks['runtime-dirs']['notes'] === 'temporary probes cleaned', 'Runtime dir check should report probe cleanup.' );
eforms_test_assert( $checks['mail-format']['result'] === 'PASS', 'Default mail format should pass with full HTML and text alternative.' );
eforms_test_assert( $checks['challenge-config']['result'] === 'PASS', 'Challenge config should pass when challenge mode is off.' );
eforms_test_assert( $checks['config-sources']['observed'] === 'provenance available; client preparation off recipe v1', 'Runtime health should report the configured browser preparation mode and fixed recipe version.' );
eforms_test_assert( $checks['managed-upload-dirs']['result'] === 'PASS', 'Protected writable managed directories should pass.' );
eforms_test_assert( $checks['staged-artifact-readiness']['result'] === 'PASS', 'Valid fileinfo and bounded image inspection should pass.' );
eforms_test_assert( $checks['review-route-readiness']['result'] === 'PASS', 'Rewrite-based pretty permalinks should pass managed review route readiness.' );
eforms_test_assert( $checks['review-preview-readiness']['result'] === 'PASS' && $checks['review-preview-readiness']['observed'] === 'disabled', 'Default local no-processing should keep optional review previews disabled.' );
eforms_test_assert( $checks['managed-capacity']['result'] === 'PASS', 'Consistent accounting on a provisioned filesystem should pass.' );
eforms_test_assert( $checks['staged-request-limits']['result'] === 'PASS', 'PHP request limits above the largest staged field should pass.' );
eforms_test_assert( $checks['staged-throttle']['result'] === 'PASS', 'Enabled valid per-IP throttle settings should pass staged readiness.' );

update_option( 'permalink_structure', '' );
$plain_permalink_failure = RuntimeHealthDiagnostic::run( $observations );
$plain_permalink_checks = array();
foreach ( $plain_permalink_failure['checks'] as $check ) {
    $plain_permalink_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $plain_permalink_failure['ok'] === false && $plain_permalink_checks['review-route-readiness']['result'] === 'FAIL', 'Plain permalinks should fail readiness before managed review links can be emailed.' );
update_option( 'permalink_structure', '/%postname%/' );

$capacity_now = time();
$capacity_secret = rtrim( strtr( base64_encode( str_repeat( "\x62", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
$capacity_field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 1048576,
    'max_files' => 1,
    'max_total_bytes' => 1048576,
);
$capacity_binding = array(
    'raw_token' => 'runtime-health-capacity-token',
    'form_id' => 'runtime-health-capacity',
    'instance_id' => 'runtime-health-capacity-instance',
    'field_key' => 'photos',
    'accept_until' => $capacity_now + 3600,
);
$capacity_batch = UploadBatchStore::create_batch( $capacity_binding, $capacity_secret, $capacity_field, $uploads_dir, $capacity_now );
eforms_test_assert( ! empty( $capacity_batch['ok'] ), 'The capacity-warning fixture should create a managed batch.' );
$capacity_source = eforms_test_write_file( $uploads_dir, 'runtime-health-capacity.png', eforms_test_fixture_bytes( 'staged-landscape.png' ) );
$capacity_source_bytes = filesize( $capacity_source );
$capacity_upload_id = 'runtime_health_photo';
$capacity_put = UploadBatchStore::put_item(
    $capacity_batch['batch']['batch_id'],
    $capacity_secret,
    $capacity_upload_id,
    0,
    array(
        'tmp_name' => $capacity_source,
        'original_name' => 'Runtime Health.png',
        'size' => $capacity_source_bytes,
        'error' => UPLOAD_ERR_OK,
    ),
    $uploads_dir,
    array(
        'now' => $capacity_now,
        'memory_limit' => -1,
        'execution_limit' => 0,
        'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
    )
);
eforms_test_assert( ! empty( $capacity_put['ok'] ), 'The capacity-warning fixture should materialize a managed item.' );
$capacity_record_path = $uploads_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
$capacity_record = eforms_test_managed_capacity_record( $uploads_dir );
$capacity_reservation_id = hash( 'sha256', $capacity_batch['batch']['batch_id'] . "\0" . $capacity_upload_id );
$capacity_manifest_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $capacity_batch['batch']['batch_id'] ) . '/' . $capacity_batch['batch']['batch_id'] . '/' . UploadBatchStore::MANIFEST_FILENAME;
$capacity_manifest = json_decode( file_get_contents( $capacity_manifest_path ), true );
$capacity_item = $capacity_manifest['items'][ $capacity_upload_id ];
$capacity_reserved_bytes = $capacity_item['bytes'];
$capacity_record['reservations'][ $capacity_reservation_id ] = array(
    'batch_id' => $capacity_batch['batch']['batch_id'],
    'upload_id' => $capacity_upload_id,
    'bytes' => $capacity_reserved_bytes,
    'transient_bytes' => 0,
    'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_LOCAL,
    'artifact_store_identity' => UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY,
    'cleanup_started' => false,
    'intent_id' => 'runtime-health-settlement-recovery',
    'object_key' => $capacity_item['object_key'],
    'created_at' => $capacity_now,
);
file_put_contents( $capacity_record_path, json_encode( $capacity_record ) );
chmod( $capacity_record_path, 0600 );
$capacity_warning = RuntimeHealthDiagnostic::run( $observations );
$capacity_warning_checks = array();
foreach ( $capacity_warning['checks'] as $check ) {
    $capacity_warning_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $capacity_warning_checks['managed-capacity']['result'] === 'WARN', 'Consistent accounting with a materialized unsettled reservation should warn instead of passing.' );
eforms_test_assert( strpos( $capacity_warning_checks['managed-capacity']['notes'], 'wp eforms gc --reconcile-capacity' ) !== false, 'The unsettled-capacity warning should identify the reconciliation command.' );
$capacity_reconcile = UploadBatchStore::reconcile_capacity( $uploads_dir, $capacity_now - 1, $capacity_now );
eforms_test_assert( ! empty( $capacity_reconcile['ok'] ), 'The capacity-warning fixture should reconcile its committed reservation.' );
$capacity_delete = UploadBatchStore::delete_item( $capacity_batch['batch']['batch_id'], $capacity_secret, $capacity_upload_id, $uploads_dir, $capacity_now );
eforms_test_assert( ! empty( $capacity_delete['ok'] ), 'The capacity-warning fixture should release its managed item.' );

$integer_width_failure = RuntimeHealthDiagnostic::run( array_merge( $observations, array( 'php_int_size' => 4 ) ) );
$integer_width_checks = array();
foreach ( $integer_width_failure['checks'] as $check ) {
    $integer_width_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $integer_width_failure['ok'] === false, 'A 32-bit PHP runtime should fail managed staged-upload readiness.' );
eforms_test_assert( $integer_width_checks['managed-capacity']['result'] === 'FAIL' && $integer_width_checks['managed-capacity']['observed'] === '32-bit PHP integers', 'Managed-capacity readiness should identify unsupported integer width explicitly.' );

foreach ( $rows as $row ) {
    eforms_test_assert( strpos( $row['observed'], $uploads_dir ) === false, 'Runtime health rows should not expose raw upload paths.' );
    eforms_test_assert( strpos( $row['notes'], $uploads_dir ) === false, 'Runtime health notes should not expose raw upload paths.' );
}

$private_dir = $uploads_dir . '/eforms-private';
foreach ( array( 'tokens', 'ledger', 'logs', 'throttle', 'staged', 'submissions', 'artifacts' ) as $dir ) {
    eforms_test_assert( is_dir( $private_dir . '/' . $dir ), 'Runtime health should leave usable runtime dir: ' . $dir );
    eforms_test_assert( glob( $private_dir . '/' . $dir . '/' . RuntimeHealthDiagnostic::PROBE_FILENAME . '-*' ) === array(), 'Runtime health should remove its unique probe file for: ' . $dir );
}
eforms_test_assert( ( fileperms( $private_dir . '/' . UploadBatchStore::STAGED_DIR ) & 0777 ) === PrivateDir::DIRECTORY_MODE, 'Runtime health should keep the open staged root owner-private.' );
foreach ( array( UploadBatchStore::SUBMISSIONS_DIR, UploadBatchStore::ARTIFACTS_DIR ) as $dir ) {
    eforms_test_assert( ( fileperms( $private_dir . '/' . $dir ) & 0777 ) === PrivateDir::REVIEW_DIRECTORY_MODE, 'Runtime health should preserve group traversal on managed review dir: ' . $dir );
    foreach ( array( PrivateDir::INDEX_FILENAME, PrivateDir::HTACCESS_FILENAME, PrivateDir::WEBCONFIG_FILENAME ) as $file ) {
        eforms_test_assert( is_file( $private_dir . '/' . $dir . '/' . $file ), 'Managed upload dir should keep deny-rule file: ' . $dir . '/' . $file );
    }
}

$config = Config::get();
eforms_test_assert( $config['uploads']['dir'] === $uploads_dir, 'Runtime health should not mutate config state.' );

$purged_uploads_dir = eforms_test_setup_uploads( 'eforms-runtime-health-purged' );
$purged_private = PrivateDir::ensure( $purged_uploads_dir );
file_put_contents( $purged_private['path'] . '/' . PrivateDir::PURGE_MARKER_FILENAME, "purged\n" );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $purged_uploads_dir ) {
        $config['uploads']['dir'] = $purged_uploads_dir;
        $config['throttle']['enable'] = true;
        return $config;
    }
);
Config::reset_snapshot();
$purged_result = RuntimeHealthDiagnostic::run( $observations );
$purged_checks = array();
foreach ( $purged_result['checks'] as $check ) {
    $purged_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $purged_checks['private-storage']['observed'] === 'managed_purged', 'Runtime health should respect the retained purge barrier before private-storage probes.' );
eforms_test_assert( $purged_checks['runtime-dirs']['observed'] === 'managed_purged', 'Runtime health should respect the retained purge barrier before runtime-dir probes.' );
eforms_test_assert( $purged_checks['managed-upload-dirs']['observed'] === 'managed_purged', 'Runtime health should respect the retained purge barrier before managed-dir probes.' );
foreach ( array( 'tokens', 'ledger', 'logs', 'throttle', UploadBatchStore::STAGED_DIR, UploadBatchStore::SUBMISSIONS_DIR ) as $dir ) {
    eforms_test_assert( ! is_dir( $purged_private['path'] . '/' . $dir ), 'Runtime health should not create private probe dir behind purge barrier: ' . $dir );
}
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['throttle']['enable'] = true;
        return $config;
    }
);
Config::reset_snapshot();

if ( function_exists( 'symlink' ) ) {
    $probe_link_dir = eforms_test_setup_uploads( 'eforms-runtime-health-linked-probe' );
    $probe_private = PrivateDir::ensure( $probe_link_dir );
    $tokens_dir = PrivateDir::subdir( $probe_link_dir, Security::TOKENS_DIR, true );
    $outside_probe = eforms_test_write_file( $probe_link_dir, 'outside-doctor-probe', 'outside' );
    $legacy_probe = $tokens_dir . '/' . RuntimeHealthDiagnostic::PROBE_FILENAME;
    $stale_probe = $legacy_probe . '-0000000000000000';
    file_put_contents( $legacy_probe, 'legacy' );
    symlink( $outside_probe, $stale_probe );
    eforms_test_set_filter(
        'eforms_config',
        function ( $config ) use ( $probe_link_dir ) {
            $config['uploads']['dir'] = $probe_link_dir;
            $config['throttle']['enable'] = true;
            return $config;
        }
    );
    Config::reset_snapshot();
    $linked_probe = RuntimeHealthDiagnostic::run( $observations );
    $linked_probe_checks = array();
    foreach ( $linked_probe['checks'] as $check ) {
        $linked_probe_checks[ $check['name'] ] = $check;
    }
    eforms_test_assert( $linked_probe_checks['runtime-dirs']['result'] === 'PASS', 'A stale probe name from another or crashed diagnostic should not block a new invocation.' );
    eforms_test_assert( file_get_contents( $legacy_probe ) === 'legacy' && is_link( $stale_probe ) && file_get_contents( $outside_probe ) === 'outside', 'Runtime health should create and remove only its invocation-owned probe.' );
    eforms_test_remove_tree( $probe_private['path'] );
    eforms_test_remove_tree( $probe_link_dir );
    eforms_test_set_filter(
        'eforms_config',
        function ( $config ) use ( $uploads_dir ) {
            $config['uploads']['dir'] = $uploads_dir;
            $config['throttle']['enable'] = true;
            return $config;
        }
    );
    Config::reset_snapshot();
}

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['throttle']['enable'] = false;
        return $config;
    }
);
Config::reset_snapshot();
$throttle_failure = RuntimeHealthDiagnostic::run( $observations );
$throttle_checks = array();
foreach ( $throttle_failure['checks'] as $check ) {
    $throttle_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $throttle_failure['ok'] === false, 'Disabled throttling should fail staged production readiness.' );
eforms_test_assert( $throttle_checks['staged-throttle']['result'] === 'FAIL', 'Disabled throttling should produce a staged-throttle failure.' );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['throttle']['enable'] = true;
        $config['throttle']['per_ip']['max_per_minute'] = 30;
        return $config;
    }
);
Config::reset_snapshot();
$undersized_throttle = RuntimeHealthDiagnostic::run( $observations );
$undersized_throttle_checks = array();
foreach ( $undersized_throttle['checks'] as $check ) {
    $undersized_throttle_checks[ $check['name'] ] = $check;
}
$required_staged_requests = Anchors::get( 'STAGED_THROTTLED_REQUESTS_PER_BATCH' )
    + ( Anchors::get( 'STAGED_THROTTLED_REQUESTS_PER_ITEM' ) * 24 );
eforms_test_assert( $required_staged_requests === 49, 'The diagnostic request budget should match the one-create-plus-two-per-item staged flow.' );
eforms_test_assert( $undersized_throttle_checks['staged-throttle']['result'] === 'FAIL', 'A retained throttle limit below one complete active staged batch should fail readiness.' );
eforms_test_assert( strpos( $undersized_throttle_checks['staged-throttle']['observed'], 'needs ' . $required_staged_requests ) !== false, 'The throttle failure should report the active staged request requirement.' );

$image_failure = RuntimeHealthDiagnostic::run( array_merge( $observations, array( 'fileinfo' => false ) ) );
$image_checks = array();
foreach ( $image_failure['checks'] as $check ) {
    $image_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $image_checks['staged-artifact-readiness']['result'] === 'FAIL', 'Missing fileinfo should fail staged artifact readiness.' );

$templates_dir = dirname( __DIR__, 2 ) . '/templates/forms';
$invalid_template_path = $templates_dir . '/runtime-health-invalid.json';
$invalid_template = json_decode( file_get_contents( $templates_dir . '/upload-test.json' ), true );
eforms_test_assert( is_array( $invalid_template ), 'The invalid runtime-health fixture should derive from the valid upload template.' );
$invalid_template['id'] = 'runtime-health-invalid';
$invalid_template['unknown_root'] = true;
foreach ( $invalid_template['fields'] as &$field ) {
    if ( isset( $field['upload_mode'] ) && $field['upload_mode'] === 'staged' ) {
        $field['accept'] = array( 'image' );
    }
}
unset( $field );
file_put_contents( $invalid_template_path, json_encode( $invalid_template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
try {
    $invalid_template_result = RuntimeHealthDiagnostic::run( $observations );
    $invalid_template_checks = array();
    foreach ( $invalid_template_result['checks'] as $check ) {
        $invalid_template_checks[ $check['name'] ] = $check;
    }
    eforms_test_assert( $invalid_template_checks['templates']['result'] === 'FAIL', 'Runtime health should reject structurally invalid shipped templates.' );
    eforms_test_assert( $invalid_template_checks['staged-artifact-readiness']['result'] === 'PASS', 'Invalid template fields must not influence staged readiness.' );
} finally {
    @unlink( $invalid_template_path );
}

$inspection_failure = RuntimeHealthDiagnostic::run( array_merge( $observations, array( 'image_inspection' => false ) ) );
$inspection_checks = array();
foreach ( $inspection_failure['checks'] as $check ) {
    $inspection_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $inspection_checks['staged-artifact-readiness']['result'] === 'FAIL', 'Missing bounded image inspection should fail staged artifact readiness.' );

$request_failure = RuntimeHealthDiagnostic::run( array_merge( $observations, array( 'upload_max_filesize' => '1M', 'post_max_size' => '1M' ) ) );
$request_checks = array();
foreach ( $request_failure['checks'] as $check ) {
    $request_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $request_checks['staged-request-limits']['result'] === 'FAIL', 'Undersized PHP request limits should fail staged readiness.' );

$effective_boundary = RuntimeHealthDiagnostic::run( array_merge( $observations, array( 'upload_max_filesize' => '19M', 'post_max_size' => '20M' ) ) );
$effective_boundary_checks = array();
foreach ( $effective_boundary['checks'] as $check ) {
    $effective_boundary_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $effective_boundary_checks['staged-request-limits']['result'] === 'PASS', 'PHP limits should be checked against the effective 18 MiB artifact cap rather than a larger raw template declaration.' );

$multipart_boundary = RuntimeHealthDiagnostic::run( array_merge( $observations, array( 'upload_max_filesize' => '18M', 'post_max_size' => '18M' ) ) );
$multipart_checks = array();
foreach ( $multipart_boundary['checks'] as $check ) {
    $multipart_checks[ $check['name'] ] = $check;
}
eforms_test_assert( Anchors::get( 'STAGED_MULTIPART_OVERHEAD_BYTES' ) === 1048576, 'The staged request diagnostic should use the fixed 1 MiB multipart allowance.' );
eforms_test_assert( $multipart_checks['staged-request-limits']['result'] === 'FAIL', 'A request cap equal to the effective staged file ceiling should fail because multipart framing also consumes bytes.' );

$capacity_path = $uploads_dir . '/eforms-private/' . UploadBatchStore::CAPACITY_FILENAME;
file_put_contents( $capacity_path, '{invalid' );
$capacity_failure = RuntimeHealthDiagnostic::run( $observations );
$capacity_checks = array();
foreach ( $capacity_failure['checks'] as $check ) {
    $capacity_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $capacity_checks['managed-capacity']['result'] === 'FAIL', 'Unreadable capacity accounting should fail staged readiness.' );
unlink( $capacity_path );

if ( function_exists( 'symlink' ) ) {
    symlink( $uploads_dir . '/eforms-private/missing-capacity.json', $capacity_path );
    $linked_capacity_failure = RuntimeHealthDiagnostic::run( $observations );
    $linked_capacity_checks = array();
    foreach ( $linked_capacity_failure['checks'] as $check ) {
        $linked_capacity_checks[ $check['name'] ] = $check;
    }
    eforms_test_assert( $linked_capacity_checks['managed-capacity']['result'] === 'FAIL', 'Broken symlinked capacity accounting should fail staged readiness.' );
    eforms_test_assert( $linked_capacity_checks['managed-capacity']['observed'] === 'capacity_invalid', 'Broken symlinked capacity accounting should fail as invalid capacity.' );
    unlink( $capacity_path );
}

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['throttle']['enable'] = true;
        $config['challenge']['mode'] = 'auto';
        $config['challenge']['site_key'] = '';
        $config['challenge']['secret_key'] = '';
        return $config;
    }
);
Config::reset_snapshot();
$challenge_warning = RuntimeHealthDiagnostic::run( $observations );
$challenge_checks = array();
foreach ( $challenge_warning['checks'] as $check ) {
    $challenge_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $challenge_warning['ok'] === true, 'Challenge config warnings should not fail runtime health.' );
eforms_test_assert( $challenge_checks['challenge-config']['result'] === 'WARN', 'Challenge config should warn when auto mode lacks Turnstile keys.' );
eforms_test_assert( strpos( $challenge_checks['challenge-config']['observed'], 'missing keys' ) !== false, 'Challenge warning should explain the missing-key state.' );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_snapshot();

$retained_worker_uploads = eforms_test_setup_uploads( 'eforms-runtime-health-retained-worker' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $retained_worker_uploads ) {
        $config['uploads']['dir'] = $retained_worker_uploads;
        $config['throttle']['enable'] = true;
        return $config;
    }
);
Config::reset_snapshot();
$retained_now = time();
$retained_secret = rtrim(
    strtr( base64_encode( str_repeat( "\x6b", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ),
    '='
);
$retained_field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 1048576,
    'max_files' => 1,
    'max_total_bytes' => 1048576,
);
$retained_worker = UploadBatchStore::create_batch(
    array(
        'raw_token' => 'retained-worker-token',
        'form_id' => 'retained-worker-form',
        'instance_id' => 'retained-worker-instance',
        'field_key' => 'photos',
        'accept_until' => $retained_now + 3600,
    ),
    $retained_secret,
    $retained_field,
    $retained_worker_uploads,
    $retained_now,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    str_repeat( 'a', 64 )
);
eforms_test_assert( ! empty( $retained_worker['ok'] ), 'The rollout diagnostic fixture should retain one Worker-owned aggregate under a local new-write composition.' );
$retained_worker_failure = RuntimeHealthDiagnostic::run(
    array_merge( $observations, array( 'worker_health' => array( 'ok' => false, 'outcome' => 'transport_failed' ) ) )
);
$retained_worker_failure_checks = array();
foreach ( $retained_worker_failure['checks'] as $check ) {
    $retained_worker_failure_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $retained_worker_failure_checks['staged-artifact-readiness']['result'] === 'FAIL', 'A local rollout must still require Worker readiness while Worker-owned aggregates remain.' );
$retained_worker_wrong_identity = RuntimeHealthDiagnostic::run(
    array_merge( $observations, array( 'worker_health' => array( 'ok' => true, 'outcome' => 'ready' ) ) )
);
$retained_worker_wrong_identity_checks = array();
foreach ( $retained_worker_wrong_identity['checks'] as $check ) {
    $retained_worker_wrong_identity_checks[ $check['name'] ] = $check;
}
eforms_test_assert(
    $retained_worker_wrong_identity_checks['staged-artifact-readiness']['result'] === 'FAIL'
        && $retained_worker_wrong_identity_checks['staged-artifact-readiness']['observed'] === 'retained Worker identity mismatch',
    'A healthy but differently configured Worker must not satisfy a retained aggregate identity.'
);
eforms_test_remove_tree( $retained_worker_uploads );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_snapshot();

define( 'EFORMS_UPLOAD_COMPOSITION', 'worker_r2_cloudflare' );
define( 'EFORMS_WORKER_URL', 'https://media.example.test' );
define( 'EFORMS_WORKER_ENVIRONMENT_ID', 'health-test' );
define( 'EFORMS_WORKER_ACTIVE_KEY_ID', 'health-key' );
define( 'EFORMS_WORKER_ACTIVE_KEY_B64', 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8' );
$worker_uploads = eforms_test_setup_uploads( 'eforms-runtime-health-worker' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $worker_uploads ) {
        $config['uploads']['dir'] = $worker_uploads;
        $config['throttle']['enable'] = true;
        return $config;
    }
);
Config::reset_snapshot();
$matching_worker_secret = rtrim(
    strtr( base64_encode( str_repeat( "\x6d", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ),
    '='
);
$matching_worker = UploadBatchStore::create_batch(
    array(
        'raw_token' => 'matching-worker-token',
        'form_id' => 'matching-worker-form',
        'instance_id' => 'matching-worker-instance',
        'field_key' => 'photos',
        'accept_until' => time() + 3600,
    ),
    $matching_worker_secret,
    $retained_field,
    $worker_uploads,
    time(),
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    WorkerClient::composition_fingerprint()
);
eforms_test_assert( ! empty( $matching_worker['ok'] ), 'The Worker diagnostic fixture should retain the current deployment fingerprint.' );
$worker_ready = RuntimeHealthDiagnostic::run(
    array_merge(
        $observations,
        array(
            'worker_health' => array(
                'ok' => true,
                'storage_ready' => true,
                'inspection_ready' => true,
                'outcome' => 'ready',
            ),
        )
    )
);
$worker_checks = array();
foreach ( $worker_ready['checks'] as $check ) {
    $worker_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $worker_checks['staged-artifact-readiness']['result'] === 'PASS', 'Worker composition should require signed data-plane readiness and the exact retained deployment identity instead of local image decoding.' );
eforms_test_assert( strpos( $worker_checks['staged-artifact-readiness']['notes'], 'lifecycle configuration' ) !== false, 'Runtime health should keep provider lifecycle management outside WordPress.' );
eforms_test_assert( $worker_checks['staged-request-limits']['result'] === 'PASS' && strpos( $worker_checks['staged-request-limits']['observed'], 'bypasses PHP' ) !== false, 'Direct Worker uploads should not be gated by PHP multipart limits.' );
eforms_test_assert( ! is_dir( $worker_uploads . '/eforms-private/' . UploadBatchStore::ARTIFACTS_DIR ), 'Worker readiness should not provision a local authoritative-artifact root.' );
$retained_local_secret = rtrim(
    strtr( base64_encode( str_repeat( "\x6c", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ),
    '='
);
$retained_local = UploadBatchStore::create_batch(
    array(
        'raw_token' => 'retained-local-token',
        'form_id' => 'retained-local-form',
        'instance_id' => 'retained-local-instance',
        'field_key' => 'photos',
        'accept_until' => time() + 3600,
    ),
    $retained_local_secret,
    $retained_field,
    $worker_uploads,
    time(),
    FormProtocol::UPLOAD_TRANSPORT_LOCAL,
    UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY
);
eforms_test_assert( ! empty( $retained_local['ok'] ), 'The rollout diagnostic fixture should retain one local aggregate under a Worker new-write composition.' );
$worker_with_retained_local = RuntimeHealthDiagnostic::run(
    array_merge(
        $observations,
        array(
            'upload_max_filesize' => '1M',
            'post_max_size' => '1M',
            'worker_health' => array( 'ok' => true, 'outcome' => 'ready' ),
        )
    )
);
$worker_with_retained_local_checks = array();
foreach ( $worker_with_retained_local['checks'] as $check ) {
    $worker_with_retained_local_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $worker_with_retained_local_checks['staged-request-limits']['result'] === 'FAIL', 'A Worker rollout must still verify PHP multipart limits while local-owned aggregates remain.' );
eforms_test_assert( is_dir( $worker_uploads . '/eforms-private/' . UploadBatchStore::ARTIFACTS_DIR ), 'A Worker rollout must still probe local artifact storage while local-owned aggregates remain.' );
$worker_unavailable = RuntimeHealthDiagnostic::run(
    array_merge( $observations, array( 'worker_health' => array( 'ok' => false, 'outcome' => 'transport_failed' ) ) )
);
$worker_unavailable_checks = array();
foreach ( $worker_unavailable['checks'] as $check ) {
    $worker_unavailable_checks[ $check['name'] ] = $check;
}
eforms_test_assert( $worker_unavailable_checks['staged-artifact-readiness']['result'] === 'FAIL', 'Worker outage should fail readiness without enabling local fallback.' );
eforms_test_remove_tree( $worker_uploads );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_snapshot();

eforms_test_remove_tree( $uploads_dir );
Config::reset_snapshot();

$missing_uploads = eforms_test_tmp_root( 'eforms-runtime-health-missing' );
$GLOBALS['eforms_test_uploads_dir'] = $missing_uploads;
Config::reset_snapshot();

$failure = RuntimeHealthDiagnostic::run( $observations );
$failure_checks = array();
foreach ( $failure['checks'] as $check ) {
    $failure_checks[ $check['name'] ] = $check;
}

eforms_test_assert( $failure['ok'] === false, 'Runtime health should fail when uploads storage is unavailable.' );
eforms_test_assert( $failure['exit_code'] === 1, 'Failed runtime health should expose exit_code=1.' );
eforms_test_assert( $failure_checks['uploads-base']['result'] === 'FAIL', 'Missing uploads base should fail the uploads-base check.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_snapshot();
