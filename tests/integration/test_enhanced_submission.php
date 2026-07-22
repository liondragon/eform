<?php
/**
 * Integration test for safe same-document submission recovery inputs.
 *
 * Contract: Final Form Credential Transport
 * Contract: Enhanced Final Submission Response
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';
require_once __DIR__ . '/../../src/Rendering/TemplateContext.php';
require_once __DIR__ . '/../../src/Rendering/TemplateLoader.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/PublicRequestController.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return $path;
    }
}

$uploads_dir = eforms_test_setup_uploads( 'eforms-enhanced-submission' );
$template_dir = eforms_test_tmp_root( 'eforms-enhanced-submission-template' );
mkdir( $template_dir, 0700, true );
eforms_test_write_form_template(
    $template_dir,
    'recovery',
    'Recovery',
    array(
        array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
        array( 'key' => 'contact_phone', 'type' => 'tel_us', 'label' => 'Mobile number', 'placeholder' => 'Mobile number (10 digits)' ),
        array( 'key' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
        array(
            'key' => 'project',
            'type' => 'select',
            'label' => 'Project',
            'options' => array(
                array( 'key' => 'kitchen', 'label' => 'Kitchen' ),
                array( 'key' => 'bath', 'label' => 'Bath' ),
            ),
        ),
        array(
            'key' => 'contact',
            'type' => 'radio',
            'label' => 'Contact',
            'options' => array(
                array( 'key' => 'email', 'label' => 'Email' ),
                array( 'key' => 'phone', 'label' => 'Phone' ),
            ),
        ),
        array(
            'key' => 'consent',
            'type' => 'checkbox',
            'label' => 'Consent',
            'options' => array(
                array( 'key' => 'yes', 'label' => 'Yes' ),
            ),
        ),
        array( 'key' => 'resume', 'type' => 'file', 'label' => 'Resume', 'accept' => array( 'pdf' ) ),
    ),
    array( 'name' )
);

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['security']['origin_mode'] = 'off';
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();

$values = array(
    'name' => '',
    'contact_phone' => 'abc',
    'message' => "Keep this\nmessage",
    'project' => 'kitchen',
    'contact' => 'email',
    'consent' => array( 'yes' ),
);
$mint = Security::mint_hidden_record( 'recovery', $uploads_dir );
$result = SubmitHandler::handle(
    'recovery',
    array(
        'post' => array(
            'eforms_token' => $mint['token'],
            'instance_id' => $mint['instance_id'],
            'timestamp' => (string) $mint['issued_at'],
            'js_ok' => '1',
            'eforms_hp' => '',
            'recovery' => $values,
        ),
        'files' => array(),
        'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
    ),
    array( 'template_base_dir' => $template_dir )
);

// Given a correctable validation error...
// When the public handler and controller prepare the HTML fallback...
// Then every descriptor-valid non-file value reaches the renderer unchanged.
eforms_test_assert( empty( $result['ok'] ) && $result['status'] === 200, 'Validation failure should remain a local rerender.' );
eforms_test_assert( $result['retry_allowed'] === false, 'Correctable validation errors should not derive a failure retry disposition.' );
eforms_test_assert( $result['values'] === $values, 'Correctable validation results should retain safe descriptor values.' );
eforms_test_assert( ! isset( $result['values']['resume'], $result['values']['eforms_token'], $result['values']['eforms_hp'] ), 'Rerender values must exclude file and protocol/security fields.' );

$failure_response = new ReflectionMethod( 'PublicRequestController', 'failure_response' );
$response = $failure_response->invoke( null, 'recovery', $result );
eforms_test_assert( $response['render'] === 'local', 'Correctable errors should route through the local renderer.' );
eforms_test_assert( $response['options']['values'] === $values, 'The controller should pass the handler redisplay projection to the renderer.' );

$enhanced_correctable = $failure_response->invoke( null, 'recovery', $result, true );
$enhanced_correctable_body = json_decode( $enhanced_correctable['body'], true );
eforms_test_assert( is_array( $enhanced_correctable_body ), 'Correctable JSON should encode safely: ' . json_last_error_msg() );
eforms_test_assert( $enhanced_correctable['status'] === 422 && $enhanced_correctable['render'] === 'json', 'Negotiated correctable responses should use the JSON adapter with HTTP 422.' );
eforms_test_assert(
    array_keys( $enhanced_correctable_body ) === array( 'ok', 'errors', 'upload_recovery', 'challenge' )
        && $enhanced_correctable_body['ok'] === false
        && is_array( $enhanced_correctable_body['errors']['global'] )
        && is_array( $enhanced_correctable_body['errors']['fields'] )
        && $enhanced_correctable_body['errors']['fields']['name'][0]['message'] === 'Please complete Name.'
        && $enhanced_correctable_body['errors']['fields']['contact_phone'][0]['message'] === 'Mobile number (10 digits) must be a valid phone number.'
        && $enhanced_correctable_body['upload_recovery'] === null
        && $enhanced_correctable_body['challenge'] === null,
    'Correctable JSON should use the narrow P2.T2 envelope with resolved field messages and without challenge metadata.'
);
eforms_test_assert( ! str_contains( $enhanced_correctable['body'], 'Keep this' ) && ! str_contains( $enhanced_correctable['body'], 'kitchen' ), 'Correctable JSON must not disclose submitted values.' );
eforms_test_assert( $enhanced_correctable['headers']['Content-Type'] === 'application/json; charset=UTF-8' && $enhanced_correctable['headers']['Cache-Control'] === 'private, no-store, max-age=0', 'Enhanced JSON should be UTF-8 and private no-store.' );

$staged_correctable = $result;
$staged_correctable[ FormProtocol::RESPONSE_UPLOAD_RECOVERY ] = FormProtocol::UPLOAD_RECOVERY_FINALIZING;
$enhanced_staged_correctable = $failure_response->invoke( null, 'recovery', $staged_correctable, true );
$enhanced_staged_body = json_decode( $enhanced_staged_correctable['body'], true );
eforms_test_assert( $enhanced_staged_body['upload_recovery'] === array( 'state' => 'finalizing_recovery' ), 'Correctable JSON should expose only the non-secret finalizing recovery lifecycle.' );

$custom_field_errors = new Errors();
$custom_field_errors->add_field( 'resume', 'EFORMS_ERR_UPLOAD_TYPE', 'Staged photos must finish uploading before submission.' );
$custom_field_result = $result;
$custom_field_result['errors'] = $custom_field_errors;
$custom_field_result['error_field_context'] = array( 'resume' => array( 'label' => 'Resume', 'type' => 'file' ) );
$enhanced_custom_field = $failure_response->invoke( null, 'recovery', $custom_field_result, true );
$enhanced_custom_field_body = json_decode( $enhanced_custom_field['body'], true );
eforms_test_assert(
    $enhanced_custom_field_body['errors']['fields']['resume'][0]['message'] === 'Staged photos must finish uploading before submission.',
    'Enhanced field JSON should preserve server-owned custom field messages for non-generic field errors.'
);

$custom_global_errors = new Errors();
$custom_global_errors->add_global( 'EFORMS_ERR_ONE_OF_REQUIRED', 'Please provide a listing URL or upload at least one photo.' );
$custom_global_result = $result;
$custom_global_result['errors'] = $custom_global_errors;
$enhanced_custom_global = $failure_response->invoke( null, 'recovery', $custom_global_result, true );
$enhanced_custom_global_body = json_decode( $enhanced_custom_global['body'], true );
eforms_test_assert(
    $enhanced_custom_global_body['errors']['global'][0]['message'] === 'Please provide a listing URL or upload at least one photo.',
    'Enhanced global JSON should preserve server-owned custom messages for known public errors.'
);

$unsafe_custom_field_errors = new Errors();
$unsafe_custom_field_errors->add_field( 'resume', 'EFORMS_FAIL2BAN_IO', 'Internal path /tmp/private leaked.' );
$unsafe_custom_field_result = $result;
$unsafe_custom_field_result['errors'] = $unsafe_custom_field_errors;
$unsafe_custom_field_result['error_field_context'] = array( 'resume' => array( 'label' => 'Resume', 'type' => 'file' ) );
$enhanced_unsafe_custom_field = $failure_response->invoke( null, 'recovery', $unsafe_custom_field_result, true );
$enhanced_unsafe_custom_field_body = json_decode( $enhanced_unsafe_custom_field['body'], true );
eforms_test_assert(
    $enhanced_unsafe_custom_field_body['errors']['fields']['resume'][0]['code'] === 'EFORMS_ERR_STORAGE_UNAVAILABLE'
        && $enhanced_unsafe_custom_field_body['errors']['fields']['resume'][0]['message'] === ErrorMessages::message( 'EFORMS_ERR_STORAGE_UNAVAILABLE' )
        && ! str_contains( $enhanced_unsafe_custom_field['body'], 'Internal path' ),
    'Enhanced field JSON should replace unsafe custom messages when remapping private error codes.'
);

$template = TemplateLoader::load( 'recovery', $template_dir );
$context = TemplateContext::build( $template['template'], $template['version'] );
$render_fields = new ReflectionMethod( 'FormRenderer', 'render_fields' );
$fields_html = $render_fields->invoke( null, $context['context'], $result['errors'], $response['options']['values'] );
eforms_test_assert( strpos( $fields_html, 'Keep this' ) !== false && strpos( $fields_html, 'message</textarea>' ) !== false, 'Renderer should retain textarea content.' );
eforms_test_assert( strpos( $fields_html, 'value="kitchen" selected="selected"' ) !== false, 'Renderer should retain the selected option.' );
eforms_test_assert( preg_match( '/value="email"[^>]+checked="checked"/', $fields_html ) === 1, 'Renderer should retain the selected radio option.' );
eforms_test_assert( preg_match( '/value="yes"[^>]+checked="checked"/', $fields_html ) === 1, 'Renderer should retain the checked checkbox option.' );
eforms_test_assert( strpos( $fields_html, 'value="resume"' ) === false, 'Renderer must not redisplay a file value.' );
$blank_fields_html = $render_fields->invoke( null, $context['context'], $result['errors'], array() );
eforms_test_assert( strpos( $blank_fields_html, 'name="recovery[name]"' ) !== false, 'Renderer should keep a blank control when a correctable result omits its value.' );

$malformed_values = $values;
$malformed_values['message'] = array( 'unexpected' );
$mint = Security::mint_hidden_record( 'recovery', $uploads_dir );
$malformed_result = SubmitHandler::handle(
    'recovery',
    array(
        'post' => array(
            'eforms_token' => $mint['token'],
            'instance_id' => $mint['instance_id'],
            'timestamp' => (string) $mint['issued_at'],
            'js_ok' => '1',
            'eforms_hp' => '',
            'recovery' => $malformed_values,
        ),
        'files' => array(),
        'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
    ),
    array( 'template_base_dir' => $template_dir )
);
eforms_test_assert( ! isset( $malformed_result['values']['message'] ), 'Malformed scalar shapes should be blanked on rerender.' );
eforms_test_assert( $malformed_result['values']['project'] === 'kitchen' && $malformed_result['values']['contact'] === 'email', 'Malformed fields must not discard safe sibling values.' );

// Given a challenge correction after all field validation succeeds...
// Then the same safe projection survives without exposing protocol fields.
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['security']['origin_mode'] = 'off';
        $config['challenge']['mode'] = 'always_post';
        $config['challenge']['provider'] = 'turnstile';
        $config['challenge']['site_key'] = 'site-key';
        $config['challenge']['secret_key'] = 'secret-key';
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();

$challenge_values = $values;
$challenge_values['name'] = 'Ada';
$challenge_values['contact_phone'] = '7209005278';
$mint = Security::mint_hidden_record( 'recovery', $uploads_dir );
$challenge_result = SubmitHandler::handle(
    'recovery',
    array(
        'post' => array(
            'eforms_token' => $mint['token'],
            'instance_id' => $mint['instance_id'],
            'timestamp' => (string) $mint['issued_at'],
            'js_ok' => '1',
            'eforms_hp' => '',
            'recovery' => $challenge_values,
        ),
        'files' => array(),
        'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
    ),
    array( 'template_base_dir' => $template_dir )
);
eforms_test_assert( ! empty( $challenge_result['require_challenge'] ), 'Missing required challenge should remain a correctable result.' );
eforms_test_assert( $challenge_result['values'] === $challenge_values, 'Challenge correction should retain the same safe descriptor values.' );
eforms_test_assert( ! isset( $challenge_result['values']['eforms_token'], $challenge_result['values']['eforms_hp'] ), 'Challenge correction must not retain protocol or honeypot values.' );
$enhanced_challenge = $failure_response->invoke( null, 'recovery', $challenge_result, true );
$enhanced_challenge_body = json_decode( $enhanced_challenge['body'], true );
eforms_test_assert(
    $enhanced_challenge['status'] === 422
        && $enhanced_challenge_body['challenge'] === array( 'provider' => 'turnstile', 'site_key' => 'site-key' ),
    'Correctable challenge JSON should expose only Challenge-owned public metadata.'
);

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['security']['origin_mode'] = 'off';
        $config['challenge']['mode'] = 'always_post';
        $config['challenge']['provider'] = 'turnstile';
        $config['challenge']['site_key'] = '';
        $config['challenge']['secret_key'] = 'secret-key';
        return $config;
    }
);
Config::reset_for_tests();
$enhanced_missing_challenge = $failure_response->invoke( null, 'recovery', $challenge_result, true );
$enhanced_missing_challenge_body = json_decode( $enhanced_missing_challenge['body'], true );
eforms_test_assert(
    $enhanced_missing_challenge['status'] === 500
        && $enhanced_missing_challenge_body['ok'] === false
        && $enhanced_missing_challenge_body['can_retry'] === false
        && ! isset( $enhanced_missing_challenge_body['challenge'] ),
    'Enhanced challenge response must fail closed instead of emitting challenge:null when required metadata is unavailable.'
);

eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['security']['origin_mode'] = 'off';
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();

$pre_ledger_retry = SubmitHandler::handle(
    'recovery',
    array(
        'post' => array( 'recovery' => array() ),
        'files' => array(),
        'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
    ),
    array(
        'template_base_dir' => $template_dir,
        'security' => function () {
            return array(
                'token_ok' => false,
                'error_code' => 'EFORMS_ERR_STORAGE_UNAVAILABLE',
            );
        },
    )
);
eforms_test_assert( $pre_ledger_retry['retry_allowed'] === true, 'A pre-ledger storage failure should explicitly allow a meaningful retry.' );
$enhanced_pre_ledger = $failure_response->invoke( null, 'recovery', $pre_ledger_retry, true );
$enhanced_pre_ledger_body = json_decode( $enhanced_pre_ledger['body'], true );
eforms_test_assert(
    $enhanced_pre_ledger['status'] === 500
        && array_keys( $enhanced_pre_ledger_body ) === array( 'ok', 'error', 'can_retry', 'location' )
        && $enhanced_pre_ledger_body['can_retry'] === true
        && $enhanced_pre_ledger_body['location'] === null,
    'Failure JSON should retain its status and copy only the handler retry disposition.'
);

$mint = Security::mint_hidden_record( 'recovery', $uploads_dir );
$post_ledger_retry = SubmitHandler::handle(
    'recovery',
    array(
        'post' => array(
            'eforms_token' => $mint['token'],
            'instance_id' => $mint['instance_id'],
            'timestamp' => (string) $mint['issued_at'],
            'js_ok' => '1',
            'eforms_hp' => '',
            'recovery' => $challenge_values,
        ),
        'files' => array(),
        'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
    ),
    array(
        'template_base_dir' => $template_dir,
        'challenge' => function () {
            return array( 'ok' => true, 'required' => false, 'soft_reasons' => array() );
        },
        'ledger_reserve' => function () {
            return array( 'ok' => true );
        },
        'commit' => function () {
            return array( 'ok' => false, 'status' => 500, 'error_code' => 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        },
    )
);
eforms_test_assert( $post_ledger_retry['retry_allowed'] === false, 'A post-ledger failure must not offer a retry.' );
$enhanced_post_ledger = $failure_response->invoke( null, 'recovery', $post_ledger_retry, true );
$enhanced_post_ledger_body = json_decode( $enhanced_post_ledger['body'], true );
eforms_test_assert( $enhanced_post_ledger_body['can_retry'] === false, 'Failure JSON must not infer retry safety from the post-ledger HTTP 500 status.' );

$hard_fail_errors = new Errors();
$hard_fail_errors->add_global( 'EFORMS_ERR_HONEYPOT' );
$hard_fail_result = array(
    'ok' => false,
    'status' => 200,
    'errors' => $hard_fail_errors,
    'retry_allowed' => false,
);
$enhanced_hard_fail = $failure_response->invoke( null, 'recovery', $hard_fail_result, true );
$enhanced_hard_fail_body = json_decode( $enhanced_hard_fail['body'], true );
eforms_test_assert(
    $enhanced_hard_fail['status'] === 200
        && $enhanced_hard_fail_body['error']['code'] === 'EFORMS_ERR_HONEYPOT'
        && $enhanced_hard_fail_body['error']['message'] === ErrorMessages::message( 'EFORMS_ERR_HONEYPOT' ),
    'Enhanced terminal failures should preserve the first public error code when error_code is absent.'
);

$encode_failure = new ReflectionMethod( 'PublicRequestController', 'enhanced_json_response' );
$encode_response = $encode_failure->invoke( null, 200, array( 'invalid' => INF ), array() );
eforms_test_assert( $encode_response['status'] === 500, 'JSON encoding failure must replace the original response status.' );
$encode_payload = json_decode( $encode_response['body'], true );
eforms_test_assert( is_array( $encode_payload ) && $encode_payload[ FormProtocol::RESPONSE_OK ] === false, 'JSON encoding failure must return the safe failure envelope.' );

eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
StorageHealth::reset_for_tests();
