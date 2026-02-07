<?php
/**
 * Integration test for upload move-after-ledger and retention behavior.
 *
 * Spec: Uploads filename policy (docs/Canonical_Spec.md#sec-uploads-filenames)
 * Spec: Ledger reservation contract (docs/Canonical_Spec.md#sec-ledger-contract)
 * Spec: Uploads (docs/Canonical_Spec.md#sec-uploads)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
require_once __DIR__ . '/../../src/Uploads/UploadPolicy.php';

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => isset( $GLOBALS['eforms_test_uploads_dir'] ) ? $GLOBALS['eforms_test_uploads_dir'] : '',
        );
    }
}

if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers, $attachments = array() ) {
        $GLOBALS['eforms_test_mail_calls'][] = array(
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'attachments' => $attachments,
        );

        return true;
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

if ( ! function_exists( 'eforms_test_write_template' ) ) {
    function eforms_test_write_template( $dir, $form_id ) {
        $template = array(
            'id' => $form_id,
            'version' => '1',
            'title' => 'Demo',
            'success' => array(
                'mode' => 'inline',
                'message' => 'Thanks.',
            ),
            'email' => array(
                'to' => 'demo@example.com',
                'subject' => 'Demo',
                'email_template' => 'default',
                'include_fields' => array( 'name', 'upload' ),
            ),
            'fields' => array(
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
            'submit_button_text' => 'Send',
        );

        $path = rtrim( $dir, '/\\' ) . '/' . $form_id . '.json';
        file_put_contents( $path, json_encode( $template ) );
        return $path;
    }
}

if ( ! function_exists( 'eforms_test_write_file' ) ) {
    function eforms_test_write_file( $dir, $name, $bytes ) {
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0700, true );
        }

        $path = rtrim( $dir, '/\\' ) . '/' . $name;
        file_put_contents( $path, $bytes );
        return $path;
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

$GLOBALS['eforms_test_mail_calls'] = array();
$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2Z6G0AAAAASUVORK5CYII=' );
$form_id = 'demo';

// Scenario 1: retention_seconds=0 removes stored uploads after successful send.
$uploads_dir = eforms_test_tmp_root( 'eforms-upload-move-retain0-uploads' );
$template_dir = eforms_test_tmp_root( 'eforms-upload-move-retain0-templates' );
$tmp_dir = eforms_test_tmp_root( 'eforms-upload-move-retain0-tmp' );
mkdir( $uploads_dir, 0700, true );
mkdir( $template_dir, 0700, true );
mkdir( $tmp_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
eforms_test_write_template( $template_dir, $form_id );

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
eforms_test_write_template( $template_dir, $form_id );

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
eforms_test_write_template( $template_dir, $form_id );

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
$collision_path = $collision_dir . '/' . $submission_id . '-1-' . $sha16 . '.png';
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

