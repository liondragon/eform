<?php
/**
 * Integration test for upload move-after-ledger and retention behavior.
 *
 * Contract: Uploads filename policy
 * Contract: Ledger reservation contract
 * Contract: Uploads
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
require_once __DIR__ . '/../../src/Uploads/UploadPolicy.php';
require_once __DIR__ . '/../../src/Uploads/UploadStore.php';

if ( ! function_exists( 'eforms_upload_move_test_write_template' ) ) {
    function eforms_upload_move_test_write_template( $dir, $form_id ) {
        return eforms_test_write_form_template(
            $dir,
            $form_id,
            'Demo',
            array(
                array(
                    'key' => 'name',
                    'type' => 'text',
                    'label' => 'Name',
                    'required' => true,
                ),
                array(
                    'key' => 'upload',
                    'type' => 'file',
                    'label' => 'Upload',
                    'accept' => array( 'image' ),
                ),
            ),
            array( 'name', 'upload' )
        );
    }
}

if ( ! function_exists( 'eforms_test_make_request' ) ) {
    function eforms_test_make_request( $form_id, $token_data, $tmp_path, $filename ) {
        return array(
            'post' => array(
                'eforms_token' => $token_data['token'],
                'instance_id' => $token_data['instance_id'],
                'timestamp' => (string) $token_data['issued_at'],
                'js_ok' => '1',
                $form_id => array(
                    'name' => 'Ada',
                ),
            ),
            'files' => array(
                $form_id => array(
                    'name' => array(
                        'upload' => $filename,
                    ),
                    'tmp_name' => array(
                        'upload' => $tmp_path,
                    ),
                    'error' => array(
                        'upload' => 0,
                    ),
                    'size' => array(
                        'upload' => filesize( $tmp_path ),
                    ),
                ),
            ),
            'headers' => array(
                'Content-Type' => 'multipart/form-data',
            ),
        );
    }
}

if ( ! UploadPolicy::finfo_available() ) {
    echo "Skipped upload move-after-ledger test: fileinfo extension unavailable.\n";
    return;
}

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

eforms_test_reset_mail();
$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2Z6G0AAAAASUVORK5CYII=' );
$form_id = 'demo';

$no_file_uploads_dir = eforms_test_setup_uploads( 'eforms-upload-move-no-file' );
$no_file_move = UploadStore::move_after_ledger(
    array( 'descriptors' => array( array( 'key' => 'name', 'type' => 'text' ) ) ),
    array( 'name' => 'Ada' ),
    '12345678-1234-4234-9234-1234567890ac',
    $no_file_uploads_dir
);
eforms_test_assert( ! empty( $no_file_move['ok'] ) && ! is_dir( $no_file_uploads_dir . '/eforms-private/uploads' ), 'A submission without synchronous file items should not create the synchronous upload tree.' );
eforms_test_remove_tree( $no_file_uploads_dir );

// Scenario 1: retention_seconds=0 removes stored uploads after successful send.
$uploads_dir = eforms_test_tmp_root( 'eforms-upload-move-retain0-uploads' );
$template_dir = eforms_test_tmp_root( 'eforms-upload-move-retain0-templates' );
$tmp_dir = eforms_test_tmp_root( 'eforms-upload-move-retain0-tmp' );
mkdir( $uploads_dir, 0700, true );
mkdir( $template_dir, 0700, true );
mkdir( $tmp_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
eforms_upload_move_test_write_template( $template_dir, $form_id );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['security']['origin_mode'] = 'off';
        $config['uploads']['enable'] = true;
        $config['uploads']['dir'] = $uploads_dir;
        $config['uploads']['retention_seconds'] = 0;
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();

$tmp_upload = eforms_test_write_file( $tmp_dir, 'pixel.png', $png_bytes );
$mint = Security::mint_hidden_record( $form_id, $uploads_dir );
$request = eforms_test_make_request( $form_id, $mint, $tmp_upload, 'pixel.png' );

$ledger_checks = array(
    'tmp_exists_before_ledger' => false,
    'uploads_present_before_ledger' => false,
);

$result = SubmitHandler::handle(
    $form_id,
    $request,
    array(
        'template_base_dir' => $template_dir,
        'ledger_reserve' => function () use ( &$ledger_checks, $tmp_upload, $uploads_dir ) {
            $ledger_checks['tmp_exists_before_ledger'] = is_file( $tmp_upload );
            $upload_files = glob( $uploads_dir . '/eforms-private/uploads/*/*' );
            $ledger_checks['uploads_present_before_ledger'] = is_array( $upload_files ) && ! empty( $upload_files );

            return array(
                'ok' => true,
                'duplicate' => false,
            );
        },
    )
);

eforms_test_assert( $result['ok'] === true, 'Submission should succeed for upload move-after-ledger path.' );
eforms_test_assert( $ledger_checks['tmp_exists_before_ledger'] === true, 'Upload tmp file must exist before ledger reservation.' );
eforms_test_assert( $ledger_checks['uploads_present_before_ledger'] === false, 'No private upload file should exist before ledger reservation.' );
eforms_test_assert( ! file_exists( $tmp_upload ), 'Tmp upload should be removed after move.' );
eforms_test_assert( isset( $result['commit']['stored'][0]['path'] ), 'Commit metadata should include stored path.' );
eforms_test_assert( ! file_exists( $result['commit']['stored'][0]['path'] ), 'Stored upload should be removed when retention_seconds=0.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_remove_tree( $tmp_dir );
eforms_test_set_filter( 'eforms_config', null );

// Scenario 2: retention_seconds>0 keeps stored uploads.
$uploads_dir = eforms_test_tmp_root( 'eforms-upload-move-retain1-uploads' );
$template_dir = eforms_test_tmp_root( 'eforms-upload-move-retain1-templates' );
$tmp_dir = eforms_test_tmp_root( 'eforms-upload-move-retain1-tmp' );
mkdir( $uploads_dir, 0700, true );
mkdir( $template_dir, 0700, true );
mkdir( $tmp_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
eforms_upload_move_test_write_template( $template_dir, $form_id );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['security']['origin_mode'] = 'off';
        $config['uploads']['enable'] = true;
        $config['uploads']['dir'] = $uploads_dir;
        $config['uploads']['retention_seconds'] = 600;
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();

$tmp_upload = eforms_test_write_file( $tmp_dir, 'pixel.png', $png_bytes );
$mint = Security::mint_hidden_record( $form_id, $uploads_dir );
$request = eforms_test_make_request( $form_id, $mint, $tmp_upload, 'pixel.png' );
$result = SubmitHandler::handle( $form_id, $request, array( 'template_base_dir' => $template_dir ) );

eforms_test_assert( $result['ok'] === true, 'Submission should succeed when retention keeps uploads.' );
eforms_test_assert( isset( $result['commit']['stored'][0]['path'] ), 'Commit metadata should include stored path.' );
eforms_test_assert( file_exists( $result['commit']['stored'][0]['path'] ), 'Stored upload should remain when retention_seconds>0.' );
$retained_path = $result['commit']['stored'][0]['path'];
$retention_config = array( 'uploads' => array( 'dir' => $uploads_dir, 'retention_seconds' => 0 ) );
if ( function_exists( 'pcntl_fork' ) && function_exists( 'pcntl_waitpid' ) ) {
    $signal_root = eforms_test_tmp_root( 'eforms-retention-lease-signals' );
    mkdir( $signal_root, 0700, true );
    $ready_path = $signal_root . '/ready';
    $go_path = $signal_root . '/go';
    $result_path = $signal_root . '/result.json';
    $pid = pcntl_fork();
    eforms_test_assert( $pid >= 0, 'The retention contention proof should fork one cleanup process.' );
    if ( $pid === 0 ) {
        file_put_contents( $ready_path, 'ready' );
        $deadline = microtime( true ) + 5;
        while ( ! is_file( $go_path ) && microtime( true ) < $deadline ) {
            usleep( 10000 );
        }
        $completed = UploadStore::apply_retention( $result['commit']['stored'], $retention_config );
        file_put_contents( $result_path, json_encode( array( 'completed' => $completed ) ) );
        exit( 0 );
    }

    $deadline = microtime( true ) + 5;
    while ( ! is_file( $ready_path ) && microtime( true ) < $deadline ) {
        usleep( 10000 );
    }
    eforms_test_assert( is_file( $ready_path ), 'The retention cleanup process should become ready.' );
    $purge_lease = PrivateDir::acquire_purge_lease( $uploads_dir );
    eforms_test_assert( $purge_lease instanceof PrivateDirLease, 'The retention contention fixture should acquire the exclusive purge lease.' );
    file_put_contents( $go_path, 'go' );
    usleep( 200000 );
    eforms_test_assert( is_file( $retained_path ) && ! is_file( $result_path ), 'Retention cleanup should wait without unlinking while an exclusive purge lease is held.' );
    $purge_lease->release();
    pcntl_waitpid( $pid, $status );
    $cleanup_result = is_file( $result_path ) ? json_decode( file_get_contents( $result_path ), true ) : null;
    eforms_test_assert( is_array( $cleanup_result ) && $cleanup_result['completed'] === true && ! file_exists( $retained_path ), 'Retention cleanup should complete after the exclusive purge lease is released.' );
    eforms_test_remove_tree( $signal_root );
} else {
    $completed_retention = UploadStore::apply_retention( $result['commit']['stored'], $retention_config );
    eforms_test_assert( $completed_retention === true && ! file_exists( $retained_path ), 'Retention cleanup should run under its shared lifecycle lease.' );
}

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_remove_tree( $tmp_dir );
eforms_test_set_filter( 'eforms_config', null );

// Scenario 3: collisions fail closed and never overwrite existing files.
$uploads_dir = eforms_test_tmp_root( 'eforms-upload-move-collision-uploads' );
$template_dir = eforms_test_tmp_root( 'eforms-upload-move-collision-templates' );
$tmp_dir = eforms_test_tmp_root( 'eforms-upload-move-collision-tmp' );
mkdir( $uploads_dir, 0700, true );
mkdir( $template_dir, 0700, true );
mkdir( $tmp_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
eforms_upload_move_test_write_template( $template_dir, $form_id );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['security']['origin_mode'] = 'off';
        $config['uploads']['enable'] = true;
        $config['uploads']['dir'] = $uploads_dir;
        $config['uploads']['retention_seconds'] = 600;
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();

$tmp_upload = eforms_test_write_file( $tmp_dir, 'pixel.png', $png_bytes );
$submission_id = '12345678-1234-4234-9234-1234567890ab';
$sha256 = hash_file( 'sha256', $tmp_upload );
$sha16 = substr( $sha256, 0, 16 );
$date_dir = gmdate( 'Ymd' );
$collision_dir = $uploads_dir . '/eforms-private/uploads/' . $date_dir;
PrivateDir::ensure( $uploads_dir );
mkdir( $uploads_dir . '/eforms-private/uploads', 0700, true );
mkdir( $collision_dir, 0700, true );
$collision_path = $collision_dir . '/' . $submission_id . '-2-0-' . $sha16 . '.png';
file_put_contents( $collision_path, 'existing-data' );

$request = array(
    'post' => array(
        'js_ok' => '1',
        $form_id => array(
            'name' => 'Ada',
        ),
    ),
    'files' => array(
        $form_id => array(
            'name' => array( 'upload' => 'pixel.png' ),
            'tmp_name' => array( 'upload' => $tmp_upload ),
            'error' => array( 'upload' => 0 ),
            'size' => array( 'upload' => filesize( $tmp_upload ) ),
        ),
    ),
    'headers' => array(
        'Content-Type' => 'multipart/form-data',
    ),
);

$result = SubmitHandler::handle(
    $form_id,
    $request,
    array(
        'template_base_dir' => $template_dir,
        'security' => function () use ( $submission_id ) {
            return array(
                'mode' => 'hidden',
                'submission_id' => $submission_id,
                'token_ok' => true,
                'hard_fail' => false,
                'require_challenge' => false,
                'soft_reasons' => array(),
            );
        },
        'ledger_reserve' => function () {
            return array(
                'ok' => true,
                'duplicate' => false,
            );
        },
    )
);

eforms_test_assert( $result['ok'] === false, 'Collision should fail the submission.' );
eforms_test_assert( $result['status'] === 500, 'Collision failure should be HTTP 500.' );
eforms_test_assert( $result['error_code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'Collision should surface storage unavailable.' );
eforms_test_assert( file_get_contents( $collision_path ) === 'existing-data', 'Collision path must not be overwritten.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_remove_tree( $tmp_dir );
eforms_test_set_filter( 'eforms_config', null );
