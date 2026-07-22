<?php
/**
 * Integration tests for staged aggregate submission and recovery ordering.
 *
 * Contract: Managed Aggregate Contract
 * Contract: Final Form Credential Transport
 * Contract: Ledger reservation contract
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/Ledger.php';
require_once __DIR__ . '/../../src/Submission/PublicRequestController.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) {
        return 'staged-submission-' . (string) $scheme . '-salt';
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return isset( $GLOBALS['eforms_test_home_url'] ) ? $GLOBALS['eforms_test_home_url'] : 'https://example.test';
    }
}

function eforms_test_staged_template( $dir, $form_id ) {
    return eforms_test_write_form_template(
        $dir,
        $form_id,
        'Staged Demo',
        array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
            array(
                'key' => 'photos',
                'type' => 'files',
                'label' => 'Photos',
                'required' => true,
                'accept' => array( 'image' ),
                'upload_mode' => 'staged',
                'max_file_bytes' => 1048576,
                'max_files' => 3,
                'max_total_bytes' => 3145728,
                'email_attach' => false,
            ),
        ),
        array( 'name', 'photos' )
    );
}

function eforms_test_staged_attachment_template( $dir, $form_id ) {
    return eforms_test_write_form_template(
        $dir,
        $form_id,
        'Staged Attachment Demo',
        array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
            array( 'key' => 'upload', 'type' => 'file', 'label' => 'Upload', 'accept' => array( 'image' ), 'email_attach' => true ),
            eforms_test_staged_field(),
        ),
        array( 'name', 'upload', 'photos' )
    );
}

function eforms_test_staged_attachment_files( $form_id, $path ) {
    return array(
        $form_id => array(
            'name' => array( 'upload' => 'Attachment.png' ),
            'tmp_name' => array( 'upload' => $path ),
            'error' => array( 'upload' => UPLOAD_ERR_OK ),
            'size' => array( 'upload' => filesize( $path ) ),
        ),
    );
}

function eforms_test_staged_field() {
    return array(
        'key' => 'photos',
        'type' => 'files',
        'label' => 'Photos',
        'required' => true,
        'accept' => array( 'image' ),
        'upload_mode' => 'staged',
        'max_file_bytes' => 1048576,
        'max_files' => 3,
        'max_total_bytes' => 3145728,
        'email_attach' => false,
    );
}

function eforms_test_staged_secret( $byte ) {
    return rtrim( strtr( base64_encode( str_repeat( $byte, Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
}

function eforms_test_staged_batch( $form_id, $mint, $secret, $field, $uploads_dir, $source, $upload_id ) {
    $binding = array(
        'raw_token' => $mint['token'],
        'form_id' => $form_id,
        'instance_id' => $mint['instance_id'],
        'field_key' => 'photos',
        'accept_until' => $mint['expires'],
    );
    $created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir );
    eforms_test_assert( $created['ok'] === true, 'Staged submission setup should create the batch.' );
    $put = UploadBatchStore::put_item(
        $created['batch']['batch_id'],
        $secret,
        $upload_id,
        0,
        array(
            'tmp_name' => $source,
            'original_name' => 'Customer Photo.png',
            'size' => filesize( $source ),
            'error' => UPLOAD_ERR_OK,
        ),
        $uploads_dir
    );
    eforms_test_assert( $put['ok'] === true, 'Staged submission setup should commit one photo.' );
    $resolved = UploadBatchStore::resolve_open( $created['batch']['batch_id'], $secret, $binding, $field, $uploads_dir );
    return array( 'binding' => $binding, 'batch_id' => $created['batch']['batch_id'], 'items' => $resolved['items'] );
}

function eforms_test_staged_request( $form_id, $mint, $batch_id, $secret, $name = 'Ada', $files = array() ) {
    return array(
        'post' => array(
            FormProtocol::FIELD_TOKEN => $mint['token'],
            FormProtocol::FIELD_INSTANCE_ID => $mint['instance_id'],
            FormProtocol::FIELD_TIMESTAMP => (string) $mint['issued_at'],
            FormProtocol::FIELD_JS_OK => '1',
            FormProtocol::FIELD_HONEYPOT => '',
            FormProtocol::FIELD_UPLOAD_BATCHES => array(
                'photos' => array(
                    FormProtocol::UPLOAD_BATCH_ID => $batch_id,
                    FormProtocol::UPLOAD_BATCH_SECRET => $secret,
                ),
            ),
            $form_id => array( 'name' => $name ),
        ),
        'files' => $files,
        'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
    );
}

function eforms_test_staged_config( $uploads_dir ) {
    eforms_test_set_filter(
        'eforms_config',
        function ( $config ) use ( $uploads_dir ) {
            $config['security']['origin_mode'] = 'off';
            $config['uploads']['dir'] = $uploads_dir;
            return $config;
        }
    );
    Config::reset_for_tests();
    StorageHealth::reset_for_tests();
    Logging::reset_for_tests();
}

function eforms_test_expire_staged_token_record( $uploads_dir, $token ) {
    $record_path = $uploads_dir . '/eforms-private/tokens/' . Helpers::h2( $token ) . '/' . hash( 'sha256', $token ) . '.json';
    $record = json_decode( file_get_contents( $record_path ), true );
    eforms_test_assert( is_array( $record ), 'Token expiry fixture should read the token record.' );
    $record['issued_at'] = time() - 120;
    $record['expires'] = time() - 60;
    eforms_test_assert( file_put_contents( $record_path, json_encode( $record ) ) !== false, 'Token expiry fixture should rewrite the token record.' );
    chmod( $record_path, 0600 );
}

function eforms_test_expire_staged_claim_manifest( $uploads_dir, $batch_id ) {
    $manifest_path = $uploads_dir . '/eforms-private/staged/' . Helpers::h2( $batch_id ) . '/' . $batch_id . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $manifest = json_decode( file_get_contents( $manifest_path ), true );
    eforms_test_assert( is_array( $manifest ) && $manifest['state'] === 'finalizing', 'Expired-claim fixture should read a finalizing manifest.' );
    $now = time();
    $manifest['created_at'] = $now - 180;
    $manifest['accept_until'] = $now - 60;
    $manifest['delete_after'] = $now + 600;
    foreach ( $manifest['items'] as &$item ) {
        $item['created_at'] = $now - 150;
    }
    unset( $item );
    foreach ( $manifest['tombstones'] as &$tombstone ) {
        $tombstone['deleted_at'] = $now - 140;
    }
    unset( $tombstone );
    $manifest['claim']['claimed_at'] = $now - 120;
    eforms_test_assert( file_put_contents( $manifest_path, json_encode( $manifest ) ) !== false, 'Expired-claim fixture should rewrite the manifest deadlines.' );
    chmod( $manifest_path, 0600 );
}

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

$png = eforms_test_fixture_bytes( 'staged-landscape.png' );
$field = eforms_test_staged_field();

if ( ! UploadPolicy::staged_host_readiness()['ok'] ) {
    echo "Skipped staged submission integration: no supported local image backend.\n";
    return;
}

// Happy path: validate the open aggregate, freeze before ledger, rename after
// ledger, persist the attempt marker, and send once without credential leakage.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-submit' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-submit-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x71" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_one' );
$request = eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret );
$ledger_saw_finalizing = false;
$result = SubmitHandler::handle(
    'staged-demo',
    $request,
    array(
        'template_base_dir' => $template_dir,
        'ledger_reserve' => function ( $form_id, $submission_id, $ledger_uploads_dir, $ledger_request ) use ( &$ledger_saw_finalizing, $batch, $secret ) {
            $status = UploadBatchStore::status( $batch['batch_id'], $secret, $ledger_uploads_dir );
            $ledger_saw_finalizing = ! empty( $status['ok'] ) && $status['batch']['state'] === 'finalizing';
            return Ledger::reserve( $form_id, $submission_id, $ledger_uploads_dir, $ledger_request );
        },
    )
);
eforms_test_assert( $result['ok'] === true, 'A complete authenticated staged batch should submit successfully.' );
eforms_test_assert( $ledger_saw_finalizing === true, 'The aggregate should be durably finalizing before ledger reservation.' );
eforms_test_assert( count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'The staged happy path should invoke mail once.' );
eforms_test_assert( UploadValue::is_staged_item( $result['values']['photos'][0] ), 'Submission values should contain only the resolved staged value shape.' );
$serialized_result = json_encode( $result['values'] );
eforms_test_assert( strpos( $serialized_result, $secret ) === false && strpos( $serialized_result, $uploads_dir ) === false, 'Customer values should contain neither staged credentials nor private paths.' );
$submission = UploadBatchStore::submission( $mint['token'], $uploads_dir );
eforms_test_assert( $submission['ok'] === true && $submission['submission']['email_attempted_at'] !== null, 'Finalized state should durably precede the email attempt.' );
$former = UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir );
eforms_test_assert( $former['ok'] === false && ! empty( $former['gone'] ), 'The renamed aggregate should be unavailable through its former batch path.' );

$mail_json = json_encode( $GLOBALS['eforms_test_mail_calls'][0] );
eforms_test_assert( strpos( $mail_json, $secret ) === false && strpos( $mail_json, 'eforms_upload_batches' ) === false, 'Email payloads should not contain staged credentials.' );
eforms_test_assert( strpos( json_encode( Logging::$events ), $secret ) === false, 'Normal logs should not contain staged credentials.' );
$mail = $GLOBALS['eforms_test_mail_calls'][0];
$gallery_path = 'eforms_review=' . $mint['token'] . '&expires=' . $submission['submission']['gallery_expires_at'] . '&signature=';
eforms_test_assert( substr_count( $mail['message'], $gallery_path ) === 1, 'A staged field should contribute exactly one signed gallery URL to text email.' );
eforms_test_assert( strpos( $mail['message'], gmdate( 'Y-m-d H:i \U\T\C', $submission['submission']['gallery_expires_at'] ) ) !== false, 'The staged email row should display the manifest gallery expiry.' );
eforms_test_assert( strpos( $mail['message'], 'eforms_review_upload=' ) === false && $mail['attachments'] === array(), 'Staged photos should produce neither individual email links nor attachments.' );
$replay = SubmitHandler::handle( 'staged-demo', $request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( $replay['ok'] === false && $replay['error_code'] === 'EFORMS_ERR_TOKEN', 'Replay after the durable email-attempt marker should fail closed.' );
eforms_test_assert( count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'Replay after the marker must not invoke mail again.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// A transport failure occurs only after the durable attempt marker. The
// finalized gallery remains retained, and replay cannot invoke mail again.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-mail-failure' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-mail-failure-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['security']['origin_mode'] = 'off';
        $config['uploads']['dir'] = $uploads_dir;
        $config['email']['html'] = true;
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();
Logging::reset_for_tests();
eforms_test_reset_mail( false );
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x77" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_mail_failure' );
$mail_failure_request = eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret );
$mail_failure = SubmitHandler::handle( 'staged-demo', $mail_failure_request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( $mail_failure['ok'] === false && ! empty( $mail_failure['email_failed'] ), 'A staged wp_mail=false result should use the existing email-failure path.' );
eforms_test_assert( count( $GLOBALS['eforms_test_mail_calls'] ) === 2, 'Email failure should attempt the staged submission once plus the existing metadata-only admin notice.' );
$failed_submission = UploadBatchStore::submission( $mint['token'], $uploads_dir );
eforms_test_assert( ! empty( $failed_submission['ok'] ) && $failed_submission['submission']['email_attempted_at'] !== null, 'The finalized gallery and attempt marker should survive transport failure.' );
$failed_mail = $GLOBALS['eforms_test_mail_calls'][0];
eforms_test_assert( substr_count( $failed_mail['message'], 'Review photos' ) === 1 && substr_count( $failed_mail['alt_body'], 'eforms_review=' ) === 1, 'HTML and text alternatives should each contain one gallery summary.' );
eforms_test_assert( $failed_mail['attachments'] === array(), 'Staged HTML email should not attach managed masters or previews.' );
$post_failure_replay = SubmitHandler::handle( 'staged-demo', $mail_failure_request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( $post_failure_replay['ok'] === false && count( $GLOBALS['eforms_test_mail_calls'] ) === 2, 'A replay after failed transport should not attempt a submission resend or another admin notice.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Validation rerender: exact open credentials are retained internally, while
// the batch remains mutable and no ledger marker is created.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-rerender' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-rerender-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x72" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_rerender' );
$invalid_request = eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret, '' );
$invalid = SubmitHandler::handle( 'staged-demo', $invalid_request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( $invalid['ok'] === false && $invalid['status'] === 200, 'Ordinary validation failure should remain a local rerender path.' );
eforms_test_assert(
    $invalid['validated_upload_batches']['photos'] === array( 'batch_id' => $batch['batch_id'], 'batch_secret' => $secret ),
    'A validated open batch should retain exact opaque credentials for local rerender.'
);
$failure_response = new ReflectionMethod( 'PublicRequestController', 'failure_response' );
$controller_response = $failure_response->invoke( null, 'staged-demo', $invalid );
eforms_test_assert(
    $controller_response['options']['validated_upload_batches'] === $invalid['validated_upload_batches'],
    'The public controller should pass only validated open credentials into renderer options.'
);
eforms_test_assert( UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir )['batch']['state'] === 'open', 'Validation failure should not freeze the batch.' );

$foreign = $invalid_request;
$foreign['post'][ FormProtocol::FIELD_UPLOAD_BATCHES ]['photos'][ FormProtocol::UPLOAD_BATCH_SECRET ] = eforms_test_staged_secret( "\x73" );
$foreign_result = SubmitHandler::handle( 'staged-demo', $foreign, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( $foreign_result['ok'] === false && empty( $foreign_result['validated_upload_batches'] ), 'Invalid batch credentials should never be reflected into rerender state.' );

$body_request = $invalid_request;
$body_request['post']['staged-demo']['name'] = 'Ada';
$body_source = eforms_test_write_file( $uploads_dir, 'body-source.png', $png );
$body_request['files'] = array(
    'staged-demo' => array(
        'name' => array( 'photos' => array( 'body.png' ) ),
        'tmp_name' => array( 'photos' => array( $body_source ) ),
        'error' => array( 'photos' => array( 0 ) ),
        'size' => array( 'photos' => array( filesize( $body_source ) ) ),
    ),
);
$body_result = SubmitHandler::handle( 'staged-demo', $body_request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( $body_result['ok'] === false && $body_result['error_code'] === 'EFORMS_ERR_UPLOAD_TYPE', 'A staged file body on final POST should be rejected.' );

$race_result = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret ),
    array(
        'template_base_dir' => $template_dir,
        'coerce' => function ( $context, $validated ) use ( $batch, $secret, $uploads_dir ) {
            UploadBatchStore::delete_item( $batch['batch_id'], $secret, 'photo_rerender', $uploads_dir );
            return Coercer::coerce( $context, $validated );
        },
    )
);
$race_status = UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir );
eforms_test_assert( $race_result['ok'] === false && $race_result['error_code'] === 'EFORMS_ERR_TOKEN', 'A resolved-then-mutated staged batch should preserve the store token failure.' );
eforms_test_assert( $race_result['status'] === 400, 'A staged freeze token race should not be reported as a storage failure.' );
eforms_test_assert( ! empty( $race_status['ok'] ) && $race_status['batch']['state'] === 'open', 'A stale freeze snapshot should leave the batch open for browser repair.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// A claim recovered before ledger reservation must become open again when
// ordinary validation prevents the retry from reaching the ledger boundary.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-preledger-validation' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-preledger-validation-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x72" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_preledger_validation' );
eforms_test_assert( UploadBatchStore::claim_finalization( $batch['batch_id'], $secret, $batch['binding'], $field, $batch['items'], $mint['token'], $uploads_dir )['ok'] === true, 'Pre-ledger validation fixture should persist the claim.' );
$invalid_recovery = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret, '' ),
    array( 'template_base_dir' => $template_dir )
);
$invalid_credentials = isset( $invalid_recovery['validated_upload_batches']['photos'] ) ? $invalid_recovery['validated_upload_batches']['photos'] : array();
$reopened_status = UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir );
eforms_test_assert( empty( $invalid_recovery['ok'] ) && $invalid_recovery['status'] === 200, 'Pre-ledger recovery should retain the ordinary validation result.' );
eforms_test_assert( isset( $invalid_credentials[ FormProtocol::UPLOAD_BATCH_ID ], $invalid_credentials[ FormProtocol::UPLOAD_BATCH_SECRET ] ), 'Validation rerender should restore the recovered batch credentials.' );
eforms_test_assert( ! empty( $reopened_status['ok'] ) && $reopened_status['batch']['state'] === 'open', 'Validation failure should reopen the recovered claim for browser repair.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Challenge failure has the same pre-ledger restoration boundary.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-preledger-challenge' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-preledger-challenge-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x73" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_preledger_challenge' );
eforms_test_assert( UploadBatchStore::claim_finalization( $batch['batch_id'], $secret, $batch['binding'], $field, $batch['items'], $mint['token'], $uploads_dir )['ok'] === true, 'Pre-ledger challenge fixture should persist the claim.' );
$challenge_request = eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret );
$challenge_request['post'][ Challenge::TURNSTILE_RESPONSE_FIELD ] = 'rejected-token';
$challenge_recovery = SubmitHandler::handle(
    'staged-demo',
    $challenge_request,
    array(
        'template_base_dir' => $template_dir,
        'challenge' => function () {
            return array( 'ok' => false, 'required' => true, 'error_code' => 'EFORMS_ERR_CHALLENGE_FAILED', 'soft_reasons' => array() );
        },
    )
);
$challenge_credentials = isset( $challenge_recovery['validated_upload_batches']['photos'] ) ? $challenge_recovery['validated_upload_batches']['photos'] : array();
$challenge_status = UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir );
eforms_test_assert( empty( $challenge_recovery['ok'] ) && $challenge_recovery['error_code'] === 'EFORMS_ERR_CHALLENGE_FAILED', 'Challenge rejection should retain its public failure result.' );
eforms_test_assert( isset( $challenge_credentials[ FormProtocol::UPLOAD_BATCH_ID ], $challenge_credentials[ FormProtocol::UPLOAD_BATCH_SECRET ] ), 'Challenge rerender should restore the recovered batch credentials.' );
eforms_test_assert( ! empty( $challenge_status['ok'] ) && $challenge_status['batch']['state'] === 'open', 'Challenge failure should reopen the recovered claim for resubmission.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Once accept_until has passed, an exact pre-ledger claim cannot be reopened.
// A validation rerender keeps it finalizing and its corrected retry resumes it.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-expired-preledger-validation' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-expired-preledger-validation-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x66" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_expired_preledger_validation' );
eforms_test_assert( UploadBatchStore::claim_finalization( $batch['batch_id'], $secret, $batch['binding'], $field, $batch['items'], $mint['token'], $uploads_dir )['ok'] === true, 'Expired pre-ledger validation fixture should persist the claim.' );
eforms_test_expire_staged_claim_manifest( $uploads_dir, $batch['batch_id'] );
eforms_test_expire_staged_token_record( $uploads_dir, $mint['token'] );
$expired_invalid = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret, '' ),
    array( 'template_base_dir' => $template_dir )
);
$expired_invalid_credentials = isset( $expired_invalid['validated_upload_batches']['photos'] ) ? $expired_invalid['validated_upload_batches']['photos'] : array();
$expired_invalid_status = UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir );
eforms_test_assert( empty( $expired_invalid['ok'] ) && $expired_invalid['status'] === 200, 'Expired exact recovery should retain the ordinary validation result.' );
eforms_test_assert( isset( $expired_invalid_credentials[ FormProtocol::UPLOAD_BATCH_ID ], $expired_invalid_credentials[ FormProtocol::UPLOAD_BATCH_SECRET ] ), 'Expired exact validation recovery should re-emit its credentials.' );
eforms_test_assert( ! empty( $expired_invalid_status['ok'] ) && $expired_invalid_status['batch']['state'] === 'finalizing', 'Expired exact validation recovery must preserve its finalizing claim.' );
$expired_validation_retry = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret, 'Ada' ),
    array( 'template_base_dir' => $template_dir )
);
eforms_test_assert( ! empty( $expired_validation_retry['ok'] ) && count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'A corrected retry should resume the expired exact validation claim.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Challenge rejection uses the same expired exact-recovery boundary.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-expired-preledger-challenge' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-expired-preledger-challenge-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x67" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_expired_preledger_challenge' );
eforms_test_assert( UploadBatchStore::claim_finalization( $batch['batch_id'], $secret, $batch['binding'], $field, $batch['items'], $mint['token'], $uploads_dir )['ok'] === true, 'Expired pre-ledger challenge fixture should persist the claim.' );
eforms_test_expire_staged_claim_manifest( $uploads_dir, $batch['batch_id'] );
eforms_test_expire_staged_token_record( $uploads_dir, $mint['token'] );
$expired_challenge_request = eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret );
$expired_challenge_request['post'][ Challenge::TURNSTILE_RESPONSE_FIELD ] = 'rejected-token';
$expired_challenge = SubmitHandler::handle(
    'staged-demo',
    $expired_challenge_request,
    array(
        'template_base_dir' => $template_dir,
        'challenge' => function () {
            return array( 'ok' => false, 'required' => true, 'error_code' => 'EFORMS_ERR_CHALLENGE_FAILED', 'soft_reasons' => array() );
        },
    )
);
$expired_challenge_credentials = isset( $expired_challenge['validated_upload_batches']['photos'] ) ? $expired_challenge['validated_upload_batches']['photos'] : array();
$expired_challenge_status = UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir );
eforms_test_assert( empty( $expired_challenge['ok'] ) && $expired_challenge['error_code'] === 'EFORMS_ERR_CHALLENGE_FAILED', 'Expired exact recovery should retain the challenge failure.' );
eforms_test_assert( isset( $expired_challenge_credentials[ FormProtocol::UPLOAD_BATCH_ID ], $expired_challenge_credentials[ FormProtocol::UPLOAD_BATCH_SECRET ] ), 'Expired exact challenge recovery should re-emit its credentials.' );
eforms_test_assert( ! empty( $expired_challenge_status['ok'] ) && $expired_challenge_status['batch']['state'] === 'finalizing', 'Expired exact challenge recovery must preserve its finalizing claim.' );
$expired_challenge_retry = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret, 'Ada' ),
    array( 'template_base_dir' => $template_dir )
);
eforms_test_assert( ! empty( $expired_challenge_retry['ok'] ) && count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'A corrected retry should resume the expired exact challenge claim.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Once the ledger marker exists, the same validation failure must not reopen
// mutation authority or disclose credentials for the terminal claim.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-used-ledger-validation' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-used-ledger-validation-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x70" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_used_ledger_validation' );
eforms_test_assert( UploadBatchStore::claim_finalization( $batch['batch_id'], $secret, $batch['binding'], $field, $batch['items'], $mint['token'], $uploads_dir )['ok'] === true, 'Used-ledger fixture should persist the claim.' );
eforms_test_assert( Ledger::reserve( 'staged-demo', $mint['token'], $uploads_dir )['ok'] === true, 'Used-ledger fixture should persist the terminal marker.' );
$terminal_validation = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret, '' ),
    array( 'template_base_dir' => $template_dir )
);
$terminal_status = UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir );
eforms_test_assert( empty( $terminal_validation['validated_upload_batches'] ), 'A used ledger must not re-emit recovered batch credentials.' );
eforms_test_assert( ! empty( $terminal_status['ok'] ) && $terminal_status['batch']['state'] === 'finalizing', 'A used ledger must keep the recovered claim terminal.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Crash recovery after the ledger marker but before aggregate rename.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-recovery' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-recovery-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x74" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_recovery' );
$claim = UploadBatchStore::claim_finalization( $batch['batch_id'], $secret, $batch['binding'], $field, $batch['items'], $mint['token'], $uploads_dir );
eforms_test_assert( $claim['ok'] === true, 'Crash fixture should persist the matching finalizing claim.' );
$ledger = Ledger::reserve( 'staged-demo', $mint['token'], $uploads_dir );
eforms_test_assert( $ledger['ok'] === true, 'Crash fixture should persist the ledger before rename.' );
$recovery_request = eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret );
$recovered = SubmitHandler::handle( 'staged-demo', $recovery_request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( $recovered['ok'] === true, 'The exact same pre-email claim should resume after a duplicate ledger marker.' );
eforms_test_assert( count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'Matching recovery should send mail once.' );
eforms_test_assert( UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir )['gone'] === true, 'Recovery should complete the pending aggregate rename.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Token expiry must not govern exact pre-email recovery once the ledger marker
// and finalized claim own at-most-once transport eligibility.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-expired-token-recovery' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-expired-token-recovery-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x64" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_expired_recovery' );
eforms_test_assert( UploadBatchStore::claim_finalization( $batch['batch_id'], $secret, $batch['binding'], $field, $batch['items'], $mint['token'], $uploads_dir )['ok'] === true, 'Expired-token recovery fixture should persist the matching claim.' );
eforms_test_assert( Ledger::reserve( 'staged-demo', $mint['token'], $uploads_dir )['ok'] === true, 'Expired-token recovery fixture should persist the ledger marker.' );
eforms_test_expire_staged_token_record( $uploads_dir, $mint['token'] );
$expired_recovery = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret ),
    array( 'template_base_dir' => $template_dir )
);
eforms_test_assert( ! empty( $expired_recovery['ok'] ) && count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'An expired token should not block exact pre-email staged recovery.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Expired open batches remain terminal for ordinary submission; only exact
// pre-email recovery gets the expired-token exception.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-expired-open-denied' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-expired-open-denied-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x65" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_expired_open' );
eforms_test_expire_staged_token_record( $uploads_dir, $mint['token'] );
$expired_open = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret ),
    array( 'template_base_dir' => $template_dir )
);
eforms_test_assert( empty( $expired_open['ok'] ) && $expired_open['error_code'] === 'EFORMS_ERR_TOKEN', 'Expired open staged submissions should still fail closed.' );
eforms_test_assert( count( $GLOBALS['eforms_test_mail_calls'] ) === 0, 'Expired open staged submissions must not invoke mail.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Crash recovery after rename but before the durable email-attempt marker.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-renamed-recovery' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-renamed-recovery-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x75" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_renamed' );
eforms_test_assert( UploadBatchStore::claim_finalization( $batch['batch_id'], $secret, $batch['binding'], $field, $batch['items'], $mint['token'], $uploads_dir )['ok'] === true, 'Rename recovery fixture should persist the claim.' );
eforms_test_assert( Ledger::reserve( 'staged-demo', $mint['token'], $uploads_dir )['ok'] === true, 'Rename recovery fixture should persist the ledger.' );
$private_dir = $uploads_dir . '/eforms-private';
$staged_path = $private_dir . '/staged/' . Helpers::h2( $batch['batch_id'] ) . '/' . $batch['batch_id'];
$submission_shard = $private_dir . '/submissions/' . Helpers::h2( $mint['token'] );
$submission_path = $submission_shard . '/' . $mint['token'];
eforms_test_assert( is_dir( $submission_shard ) || mkdir( $submission_shard, 0700, true ), 'Rename recovery fixture should create the destination shard.' );
eforms_test_assert( rename( $staged_path, $submission_path ), 'Rename recovery fixture should simulate the atomic move before the finalized manifest write.' );
$interrupted_manifest = json_decode( file_get_contents( $submission_path . '/' . UploadBatchStore::MANIFEST_FILENAME ), true );
eforms_test_assert( $interrupted_manifest['state'] === 'finalizing', 'The crash fixture should retain the pre-finalized manifest state after rename.' );
$renamed_request = eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret );
$renamed_recovery = SubmitHandler::handle( 'staged-demo', $renamed_request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( $renamed_recovery['ok'] === true && count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'Matching post-rename recovery should finish the manifest and resume only before the attempt marker.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// Local email preparation failure remains recoverable because transport never
// becomes eligible and therefore does not receive the durable attempt marker.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-email-prepare-failure' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-email-prepare-failure-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_attachment_template( $template_dir, 'staged-prepare-attachment' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-prepare-attachment', $uploads_dir );
$secret = eforms_test_staged_secret( "\x77" );
$batch = eforms_test_staged_batch( 'staged-prepare-attachment', $mint, $secret, $field, $uploads_dir, $source, 'photo_prepare_failure' );
$prepare_attachment = eforms_test_write_file( $uploads_dir, 'prepare-attachment.png', $png );
$prepare_request = eforms_test_staged_request(
    'staged-prepare-attachment',
    $mint,
    $batch['batch_id'],
    $secret,
    'Ada',
    eforms_test_staged_attachment_files( 'staged-prepare-attachment', $prepare_attachment )
);
$GLOBALS['eforms_test_home_url'] = '';
$prepare_failure = SubmitHandler::handle( 'staged-prepare-attachment', $prepare_request, array( 'template_base_dir' => $template_dir ) );
unset( $GLOBALS['eforms_test_home_url'] );
eforms_test_assert( empty( $prepare_failure['ok'] ) && ! empty( $prepare_failure['email_failed'] ), 'A local gallery preparation failure should use the existing email-failure path.' );
eforms_test_assert( count( $GLOBALS['eforms_test_mail_calls'] ) === 0, 'Local email preparation failure should not invoke transport.' );
$prepare_submission = UploadBatchStore::submission( $mint['token'], $uploads_dir );
eforms_test_assert( ! empty( $prepare_submission['ok'] ) && $prepare_submission['submission']['email_attempted_at'] === null, 'Local email preparation failure should not persist an attempt marker.' );
$prepare_stored = array_values( array_filter( glob( $uploads_dir . '/eforms-private/uploads/*/*/*' ) ?: array(), 'is_file' ) );
eforms_test_assert( count( $prepare_stored ) === 1, 'Pre-marker email preparation failure should preserve its synchronous recovery file.' );
$prepare_retry_attachment = eforms_test_write_file( $uploads_dir, 'prepare-attachment-retry.png', $png );
$prepare_retry_request = eforms_test_staged_request(
    'staged-prepare-attachment',
    $mint,
    $batch['batch_id'],
    $secret,
    'Ada',
    eforms_test_staged_attachment_files( 'staged-prepare-attachment', $prepare_retry_attachment )
);
$prepare_recovery = SubmitHandler::handle( 'staged-prepare-attachment', $prepare_retry_request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( ! empty( $prepare_recovery['ok'] ) && count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'The exact claim should recover after local email preparation becomes available.' );
eforms_test_assert( $GLOBALS['eforms_test_mail_calls'][0]['attachments'] === array( $prepare_stored[0] ), 'The recovered transport should use the preserved exact synchronous attachment.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// A crash immediately before the attempt marker is recoverable by the exact
// same finalized claim because transport has not become eligible yet.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-pre-marker-crash' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-pre-marker-crash-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x78" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_pre_marker' );
$pre_marker_request = eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret );
$pre_marker_crashed = false;
try {
    SubmitHandler::handle(
        'staged-demo',
        $pre_marker_request,
        array(
            'template_base_dir' => $template_dir,
            'before_email_attempt_marker' => function () {
                throw new RuntimeException( 'fixture_pre_marker_crash' );
            },
        )
    );
} catch ( RuntimeException $exception ) {
    $pre_marker_crashed = $exception->getMessage() === 'fixture_pre_marker_crash';
}
eforms_test_assert( $pre_marker_crashed && count( $GLOBALS['eforms_test_mail_calls'] ) === 0, 'A pre-marker crash should occur before transport.' );
$pre_marker_submission = UploadBatchStore::submission( $mint['token'], $uploads_dir );
eforms_test_assert( ! empty( $pre_marker_submission['ok'] ) && $pre_marker_submission['submission']['email_attempted_at'] === null, 'A pre-marker crash should retain a recoverable finalized aggregate without an attempt marker.' );
$pre_marker_recovery = SubmitHandler::handle( 'staged-demo', $pre_marker_request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( ! empty( $pre_marker_recovery['ok'] ) && count( $GLOBALS['eforms_test_mail_calls'] ) === 1, 'The exact pre-marker claim should resume and send once.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// A request denied by the durable email-attempt marker must not apply zero
// retention to synchronous attachment paths shared with the winning attempt.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-marker-loser-attachments' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-marker-loser-attachments-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_attachment_template( $template_dir, 'staged-attachment-demo' );
$configure_retention = function ( $seconds ) use ( $uploads_dir ) {
    eforms_test_set_filter(
        'eforms_config',
        function ( $config ) use ( $uploads_dir, $seconds ) {
            $config['security']['origin_mode'] = 'off';
            $config['uploads']['enable'] = true;
            $config['uploads']['dir'] = $uploads_dir;
            $config['uploads']['retention_seconds'] = $seconds;
            return $config;
        }
    );
    Config::reset_for_tests();
    StorageHealth::reset_for_tests();
    Logging::reset_for_tests();
};
$configure_retention( 0 );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'marker-loser-staged.png', $png );
$mint = Security::mint_hidden_record( 'staged-attachment-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x7a" );
$batch = eforms_test_staged_batch( 'staged-attachment-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_marker_loser' );
$crash_attachment = eforms_test_write_file( $uploads_dir, 'marker-loser-crash.png', $png );
$crash_request = eforms_test_staged_request(
    'staged-attachment-demo',
    $mint,
    $batch['batch_id'],
    $secret,
    'Ada',
    eforms_test_staged_attachment_files( 'staged-attachment-demo', $crash_attachment )
);
$attachment_crashed = false;
try {
    SubmitHandler::handle(
        'staged-attachment-demo',
        $crash_request,
        array(
            'template_base_dir' => $template_dir,
            'before_email_attempt_marker' => function () {
                throw new RuntimeException( 'fixture_attachment_pre_marker_crash' );
            },
        )
    );
} catch ( RuntimeException $exception ) {
    $attachment_crashed = $exception->getMessage() === 'fixture_attachment_pre_marker_crash';
}
$stored_attachments = array_values( array_filter( glob( $uploads_dir . '/eforms-private/uploads/*/*/*' ) ?: array(), 'is_file' ) );
eforms_test_assert( $attachment_crashed && count( $stored_attachments ) === 1, 'The attachment race fixture should stop before the marker with one recoverable synchronous file.' );
$shared_attachment = $stored_attachments[0];

$loser_attachment = eforms_test_write_file( $uploads_dir, 'marker-loser-retry.png', $png );
$winner_attachment = eforms_test_write_file( $uploads_dir, 'marker-winner-retry.png', $png );
$loser_request = eforms_test_staged_request(
    'staged-attachment-demo',
    $mint,
    $batch['batch_id'],
    $secret,
    'Ada',
    eforms_test_staged_attachment_files( 'staged-attachment-demo', $loser_attachment )
);
$winner_request = eforms_test_staged_request(
    'staged-attachment-demo',
    $mint,
    $batch['batch_id'],
    $secret,
    'Ada',
    eforms_test_staged_attachment_files( 'staged-attachment-demo', $winner_attachment )
);
$winner_result = null;
$loser_result = SubmitHandler::handle(
    'staged-attachment-demo',
    $loser_request,
    array(
        'template_base_dir' => $template_dir,
        'before_email_attempt_marker' => function () use ( &$winner_result, $winner_request, $template_dir, $configure_retention ) {
            $configure_retention( 600 );
            $winner_result = SubmitHandler::handle( 'staged-attachment-demo', $winner_request, array( 'template_base_dir' => $template_dir ) );
            $configure_retention( 0 );
        },
    )
);
eforms_test_assert( ! empty( $winner_result['ok'] ) && empty( $loser_result['ok'] ) && $loser_result['error_code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'Exactly one recovered request should win the durable email-attempt marker.' );
eforms_test_assert( count( $GLOBALS['eforms_test_mail_calls'] ) === 1 && $GLOBALS['eforms_test_mail_calls'][0]['attachments'] === array( $shared_attachment ), 'The sole transport attempt should use the recovered synchronous attachment.' );
eforms_test_assert( is_file( $shared_attachment ), 'The marker-denied request must not delete an attachment retained by the winning attempt.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// A crash immediately after the marker accepts possible email loss in return
// for at-most-once transport: every replay is terminal.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-post-marker-crash' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-post-marker-crash-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
eforms_test_reset_mail();
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x79" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_post_marker' );
$post_marker_request = eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret );
$post_marker_crashed = false;
try {
    SubmitHandler::handle(
        'staged-demo',
        $post_marker_request,
        array(
            'template_base_dir' => $template_dir,
            'after_email_attempt_marker' => function () {
                throw new RuntimeException( 'fixture_post_marker_crash' );
            },
        )
    );
} catch ( RuntimeException $exception ) {
    $post_marker_crashed = $exception->getMessage() === 'fixture_post_marker_crash';
}
eforms_test_assert( $post_marker_crashed && count( $GLOBALS['eforms_test_mail_calls'] ) === 0, 'A post-marker crash should occur before transport and may lose the email.' );
$post_marker_submission = UploadBatchStore::submission( $mint['token'], $uploads_dir );
eforms_test_assert( ! empty( $post_marker_submission['ok'] ) && $post_marker_submission['submission']['email_attempted_at'] !== null, 'A post-marker crash should retain its terminal attempt marker.' );
$post_marker_replay = SubmitHandler::handle( 'staged-demo', $post_marker_request, array( 'template_base_dir' => $template_dir ) );
eforms_test_assert( empty( $post_marker_replay['ok'] ) && count( $GLOBALS['eforms_test_mail_calls'] ) === 0, 'Replay after a post-marker crash must not invoke transport.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// A newly-created claim must reopen if ledger reservation fails; it is not a
// recovery discriminator and may not leave the browser permanently frozen.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-ledger-failure' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-ledger-failure-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x76" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_ledger_fail' );
$ledger_failure = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret ),
    array(
        'template_base_dir' => $template_dir,
        'ledger_reserve' => function () {
            return array( 'ok' => false, 'duplicate' => false, 'reason' => 'fixture_failure' );
        },
    )
);
eforms_test_assert( $ledger_failure['ok'] === false && $ledger_failure['error_code'] === 'EFORMS_ERR_LEDGER_IO', 'A new staged claim should surface ledger IO failure.' );
$reopened = UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir );
eforms_test_assert( $reopened['ok'] === true && $reopened['batch']['state'] === 'open', 'A pre-ledger failure should return a newly frozen batch to open.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );

// A reservation failure reported after the marker was created must keep the
// claim terminal. The marker, not the reserve result shape, owns replay state.
$uploads_dir = eforms_test_setup_uploads( 'eforms-staged-ledger-marker-failure' );
$template_dir = eforms_test_tmp_root( 'eforms-staged-ledger-marker-failure-template' );
mkdir( $template_dir, 0700, true );
eforms_test_staged_template( $template_dir, 'staged-demo' );
eforms_test_staged_config( $uploads_dir );
$source = eforms_test_write_file( $uploads_dir, 'source.png', $png );
$mint = Security::mint_hidden_record( 'staged-demo', $uploads_dir );
$secret = eforms_test_staged_secret( "\x77" );
$batch = eforms_test_staged_batch( 'staged-demo', $mint, $secret, $field, $uploads_dir, $source, 'photo_ledger_marker_fail' );
$marker_created = false;
$marker_failure = SubmitHandler::handle(
    'staged-demo',
    eforms_test_staged_request( 'staged-demo', $mint, $batch['batch_id'], $secret ),
    array(
        'template_base_dir' => $template_dir,
        'ledger_reserve' => function ( $form_id, $submission_id, $ledger_uploads_dir ) use ( &$marker_created ) {
            $reserved = Ledger::reserve( $form_id, $submission_id, $ledger_uploads_dir );
            $marker_created = ! empty( $reserved['ok'] );
            return array( 'ok' => false, 'duplicate' => false, 'reason' => 'fixture_post_create_failure' );
        },
    )
);
eforms_test_assert( $marker_created === true, 'The ledger failure fixture should persist the terminal marker first.' );
eforms_test_assert( $marker_failure['ok'] === false && $marker_failure['error_code'] === 'EFORMS_ERR_LEDGER_IO', 'A post-create ledger failure should retain its public IO error.' );
$terminal = UploadBatchStore::status( $batch['batch_id'], $secret, $uploads_dir );
eforms_test_assert( $terminal['ok'] === true && $terminal['batch']['state'] === 'finalizing', 'A durable marker must prevent a failed reservation from reopening mutation authority.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );
echo "All staged submission tests passed.\n";
