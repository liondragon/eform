<?php
/**
 * Integration test for email attachments policy.
 *
 * Spec: Email delivery (docs/Canonical_Spec.md#sec-email)
 * Spec: Uploads (docs/Canonical_Spec.md#sec-uploads)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';
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

if ( ! function_exists( 'eforms_test_write_template' ) ) {
    function eforms_test_write_template( $dir, $form_id ) {
        $template = array(
            'id' => $form_id,
            'version' => '1',
            'title' => 'Attachments',
            'success' => array(
                'mode' => 'inline',
                'message' => 'Thanks.',
            ),
            'email' => array(
                'to' => 'demo@example.com',
                'subject' => 'Attachments {{field.name}}',
                'email_template' => 'default',
                'include_fields' => array( 'name', 'attach_a', 'attach_b', 'not_attach' ),
            ),
            'fields' => array(
                array(
                    'key' => 'name',
                    'type' => 'text',
                    'label' => 'Name',
                    'required' => true,
                ),
                array(
                    'key' => 'attach_a',
                    'type' => 'file',
                    'label' => 'Attachment A',
                    'accept' => array( 'image' ),
                    'email_attach' => true,
                ),
                array(
                    'key' => 'attach_b',
                    'type' => 'file',
                    'label' => 'Attachment B',
                    'accept' => array( 'image' ),
                    'email_attach' => true,
                ),
                array(
                    'key' => 'not_attach',
                    'type' => 'file',
                    'label' => 'Not Attachment',
                    'accept' => array( 'image' ),
                    'email_attach' => false,
                ),
            ),
            'submit_button_text' => 'Send',
        );

        $path = rtrim( $dir, '/\\' ) . '/' . $form_id . '.json';
        file_put_contents( $path, json_encode( $template ) );
        return $path;
    }
}

if ( ! function_exists( 'eforms_test_request_with_files' ) ) {
    function eforms_test_request_with_files( $form_id, $mint, $name, $files ) {
        $file_name = array();
        $file_tmp_name = array();
        $file_error = array();
        $file_size = array();

        foreach ( $files as $key => $file ) {
            $file_name[ $key ] = $file['name'];
            $file_tmp_name[ $key ] = $file['tmp_name'];
            $file_error[ $key ] = 0;
            $file_size[ $key ] = filesize( $file['tmp_name'] );
        }

        return array(
            'post' => array(
                'eforms_token' => $mint['token'],
                'instance_id' => $mint['instance_id'],
                'timestamp' => (string) $mint['issued_at'],
                'js_ok' => '1',
                $form_id => array(
                    'name' => $name,
                ),
            ),
            'files' => array(
                $form_id => array(
                    'name' => $file_name,
                    'tmp_name' => $file_tmp_name,
                    'error' => $file_error,
                    'size' => $file_size,
                ),
            ),
            'headers' => array(
                'Content-Type' => 'multipart/form-data',
            ),
        );
    }
}

if ( ! UploadPolicy::finfo_available() ) {
    echo "Skipped email attachments policy test: fileinfo extension unavailable.\n";
    return;
}

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2Z6G0AAAAASUVORK5CYII=' );
$form_id = 'attachments-demo';

// Scenario 1: count cap enforces email.upload_max_attachments.
$uploads_dir = eforms_test_tmp_root( 'eforms-email-attachments-count-uploads' );
$template_dir = eforms_test_tmp_root( 'eforms-email-attachments-count-templates' );
$tmp_dir = eforms_test_tmp_root( 'eforms-email-attachments-count-tmp' );
mkdir( $uploads_dir, 0700, true );
mkdir( $template_dir, 0700, true );
mkdir( $tmp_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
$GLOBALS['eforms_test_mail_calls'] = array();
eforms_test_write_template( $template_dir, $form_id );

$tmp_a = eforms_test_write_file( $tmp_dir, 'a.png', $png );
$tmp_b = eforms_test_write_file( $tmp_dir, 'b.png', $png );
$tmp_c = eforms_test_write_file( $tmp_dir, 'c.png', $png );

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['security']['origin_mode'] = 'off';
        $config['uploads']['enable'] = true;
        $config['uploads']['dir'] = $uploads_dir;
        $config['uploads']['retention_seconds'] = 600;
        $config['uploads']['max_email_bytes'] = 1024 * 1024;
        $config['email']['upload_max_attachments'] = 1;
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();

$mint = Security::mint_hidden_record( $form_id, $uploads_dir );
$request = eforms_test_request_with_files(
    $form_id,
    $mint,
    'Ada',
    array(
        'attach_a' => array( 'name' => 'a.png', 'tmp_name' => $tmp_a ),
        'attach_b' => array( 'name' => 'b.png', 'tmp_name' => $tmp_b ),
        'not_attach' => array( 'name' => 'c.png', 'tmp_name' => $tmp_c ),
    )
);
$result = SubmitHandler::handle( $form_id, $request, array( 'template_base_dir' => $template_dir ) );

eforms_test_assert( $result['ok'] === true, 'Count-cap scenario should succeed.' );
eforms_test_assert( count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'Count-cap scenario should send exactly one email.' );

$mail = $GLOBALS['eforms_test_mail_calls'][0];
eforms_test_assert( count( $mail['attachments'] ) === 1, 'Count-cap scenario should attach only one file.' );
eforms_test_assert( strpos( $mail['message'], 'Attachments omitted due to limits: b.png' ) !== false, 'Overflow summary should include omitted attach-enabled filename.' );
eforms_test_assert( strpos( $mail['message'], 'not_attach: c.png' ) !== false, 'include_fields should still list non-attached upload names in body.' );
eforms_test_assert( strpos( $mail['message'], $tmp_a ) === false, 'Email body must not include tmp upload paths.' );
eforms_test_assert( strpos( $mail['message'], $tmp_b ) === false, 'Email body must not include tmp upload paths.' );
eforms_test_assert( strpos( $mail['message'], $tmp_c ) === false, 'Email body must not include tmp upload paths.' );
eforms_test_assert( is_string( $mail['attachments'][0] ) && strpos( $mail['attachments'][0], '/eforms-private/uploads/' ) !== false, 'Attachment path should point to private stored upload path.' );
eforms_test_assert( $mail['attachments'][0] !== $tmp_a, 'Attachment path must not use tmp_name.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_remove_tree( $tmp_dir );
eforms_test_set_filter( 'eforms_config', null );

// Scenario 2: byte cap enforces uploads.max_email_bytes.
$uploads_dir = eforms_test_tmp_root( 'eforms-email-attachments-bytes-uploads' );
$template_dir = eforms_test_tmp_root( 'eforms-email-attachments-bytes-templates' );
$tmp_dir = eforms_test_tmp_root( 'eforms-email-attachments-bytes-tmp' );
mkdir( $uploads_dir, 0700, true );
mkdir( $template_dir, 0700, true );
mkdir( $tmp_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
$GLOBALS['eforms_test_mail_calls'] = array();
eforms_test_write_template( $template_dir, $form_id );

$tmp_a = eforms_test_write_file( $tmp_dir, 'a.png', $png );
$tmp_b = eforms_test_write_file( $tmp_dir, 'b.png', $png );
$limit = filesize( $tmp_a ) + 1;

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir, $limit ) {
        $config['security']['origin_mode'] = 'off';
        $config['uploads']['enable'] = true;
        $config['uploads']['dir'] = $uploads_dir;
        $config['uploads']['retention_seconds'] = 600;
        $config['uploads']['max_email_bytes'] = $limit;
        $config['email']['upload_max_attachments'] = 5;
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();

$mint = Security::mint_hidden_record( $form_id, $uploads_dir );
$request = eforms_test_request_with_files(
    $form_id,
    $mint,
    'Lin',
    array(
        'attach_a' => array( 'name' => 'a.png', 'tmp_name' => $tmp_a ),
        'attach_b' => array( 'name' => 'b.png', 'tmp_name' => $tmp_b ),
        'not_attach' => array( 'name' => 'c.png', 'tmp_name' => eforms_test_write_file( $tmp_dir, 'c.png', $png ) ),
    )
);
$result = SubmitHandler::handle( $form_id, $request, array( 'template_base_dir' => $template_dir ) );

eforms_test_assert( $result['ok'] === true, 'Byte-cap scenario should succeed.' );
eforms_test_assert( count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'Byte-cap scenario should send exactly one email.' );

$mail = $GLOBALS['eforms_test_mail_calls'][0];
eforms_test_assert( count( $mail['attachments'] ) === 1, 'Byte-cap scenario should attach only one file.' );
eforms_test_assert( strpos( $mail['message'], 'Attachments omitted due to limits: b.png' ) !== false, 'Byte-cap overflow summary should include omitted filename.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_remove_tree( $tmp_dir );
eforms_test_set_filter( 'eforms_config', null );
