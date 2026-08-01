<?php
/**
 * Integration tests for FormProtocol-backed PHP/browser contract names.
 *
 * Contract: Template model
 * Contract: Assets
 * Contract: JS-minted mode contract
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/FormProtocol.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';
require_once __DIR__ . '/../../src/Security/MintEndpoint.php';

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
        if ( ! isset( $GLOBALS['eforms_test_scripts'] ) ) {
            $GLOBALS['eforms_test_scripts'] = array();
        }
        $GLOBALS['eforms_test_scripts'][] = array(
            'handle' => $handle,
            'src' => $src,
        );
    }
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
    function wp_add_inline_script( $handle, $data, $position = 'after' ) {
        if ( ! isset( $GLOBALS['eforms_test_inline_scripts'] ) ) {
            $GLOBALS['eforms_test_inline_scripts'] = array();
        }
        $GLOBALS['eforms_test_inline_scripts'][] = array(
            'handle' => $handle,
            'data' => $data,
            'position' => $position,
        );
        return true;
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return '/wp-content/plugins/eforms/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $path = '' ) {
        return 'https://example.com/wp-json/' . ltrim( $path, '/' );
    }
}

function eforms_protocol_test_extract_protocol_settings( $script ) {
    $prefix = 'window.eformsSettings.protocol = ';
    $start = strpos( $script, $prefix );
    eforms_test_assert( $start !== false, 'Inline settings should include protocol settings.' );
    $start += strlen( $prefix );
    $end = strpos( $script, ';', $start );
    eforms_test_assert( $end !== false, 'Protocol settings should end with a semicolon.' );

    $json = substr( $script, $start, $end - $start );
    $decoded = json_decode( $json, true );
    eforms_test_assert( is_array( $decoded ), 'Protocol settings JSON should decode.' );
    return $decoded;
}

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

$uploads_dir = eforms_test_tmp_root( 'eforms-protocol' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['throttle']['enable'] = false;
        return $config;
    }
);

Config::reset_for_tests();
FormRenderer::reset_for_tests();
$GLOBALS['eforms_test_scripts'] = array();
$GLOBALS['eforms_test_inline_scripts'] = array();

// Given a JS-minted render...
// When FormRenderer emits markup/settings...
// Then hidden controls and JS-readable settings come from FormProtocol.
$html = FormRenderer::render(
    'quote-request',
    array(
        'cacheable' => true,
        'security' => array(
            'mode' => 'js',
            'token' => '',
            'instance_id' => '',
            'timestamp' => '',
        ),
    )
);

eforms_test_assert( strpos( $html, FormProtocol::DATA_MODE . '="js"' ) !== false, 'Renderer should emit protocol mode data attribute.' );
eforms_test_assert( strpos( $html, 'name="' . FormProtocol::FIELD_MODE . '"' ) !== false, 'Renderer should emit mode control.' );
eforms_test_assert( strpos( $html, 'name="' . FormProtocol::FIELD_TOKEN . '"' ) !== false, 'Renderer should emit token control.' );
eforms_test_assert( strpos( $html, 'name="' . FormProtocol::FIELD_INSTANCE_ID . '"' ) !== false, 'Renderer should emit instance control.' );
eforms_test_assert( strpos( $html, 'name="' . FormProtocol::FIELD_TIMESTAMP . '"' ) !== false, 'Renderer should emit timestamp control.' );
eforms_test_assert( strpos( $html, 'name="' . FormProtocol::FIELD_JS_OK . '"' ) !== false, 'Renderer should emit js_ok control.' );
eforms_test_assert( strpos( $html, 'name="' . FormProtocol::FIELD_HONEYPOT . '"' ) !== false, 'Renderer should emit honeypot control.' );

eforms_test_assert( count( $GLOBALS['eforms_test_inline_scripts'] ) === 1, 'Renderer should add one inline settings block.' );
$protocol = eforms_protocol_test_extract_protocol_settings( $GLOBALS['eforms_test_inline_scripts'][0]['data'] );
eforms_test_assert( $protocol === FormProtocol::browser_settings(), 'Browser protocol settings should match FormProtocol.' );
eforms_test_assert( FormProtocol::UPLOAD_BATCH_PARAM === 'batch_id', 'FormProtocol should own the managed batch route parameter.' );
eforms_test_assert( FormProtocol::UPLOAD_ITEM_PARAM === 'upload_id', 'FormProtocol should own the managed item route parameter.' );
eforms_test_assert( FormProtocol::HEADER_ENHANCED_RESPONSE === 'X-EForms-Response' && FormProtocol::ENHANCED_RESPONSE_JSON === 'json', 'FormProtocol should own exact enhanced-response negotiation.' );
eforms_test_assert(
    FormProtocol::RESPONSE_OK === 'ok'
        && FormProtocol::RESPONSE_LOCATION === 'location'
        && FormProtocol::RESPONSE_ERRORS === 'errors'
        && FormProtocol::RESPONSE_ERROR === 'error'
        && FormProtocol::RESPONSE_CAN_RETRY === 'can_retry'
        && FormProtocol::RESPONSE_UPLOAD_RECOVERY === 'upload_recovery'
        && FormProtocol::RESPONSE_CHALLENGE === 'challenge'
        && FormProtocol::RESPONSE_CHALLENGE_PROVIDER === 'provider'
        && FormProtocol::RESPONSE_CHALLENGE_SITE_KEY === 'site_key',
    'FormProtocol should own enhanced response envelope names.'
);
eforms_test_assert(
    $protocol['dataAttributes']['field_key'] === FormProtocol::DATA_FIELD_KEY
        && $protocol['dataAttributes']['field_control'] === FormProtocol::DATA_FIELD_CONTROL
        && $protocol['dataAttributes']['field_error_mount'] === FormProtocol::DATA_FIELD_ERROR_MOUNT
        && $protocol['dataAttributes']['phone_format'] === FormProtocol::DATA_PHONE_FORMAT
        && $protocol['dataAttributes']['zip_format'] === FormProtocol::DATA_ZIP_FORMAT
        && $protocol['dataAttributes']['integer_format'] === FormProtocol::DATA_INTEGER_FORMAT
        && $protocol['dataAttributes']['url_normalize'] === FormProtocol::DATA_URL_NORMALIZE
        && $protocol['dataAttributes']['challenge_mount'] === FormProtocol::DATA_CHALLENGE_MOUNT,
    'Browser protocol settings should expose renderer-owned control markers.'
);
$challenge_metadata = Challenge::public_metadata(
    array(
        'challenge' => array(
            'provider' => 'turnstile',
            'site_key' => 'site-key-123',
            'secret_key' => 'secret-key-123',
        ),
    )
);
eforms_test_assert(
    $challenge_metadata === array(
        FormProtocol::RESPONSE_CHALLENGE_PROVIDER => 'turnstile',
        FormProtocol::RESPONSE_CHALLENGE_SITE_KEY => 'site-key-123',
        FormProtocol::CHALLENGE_SCRIPT_URL => Challenge::TURNSTILE_SCRIPT_URL,
    ),
    'Challenge should own the complete public provider metadata without exposing its secret.'
);
eforms_test_assert(
    $protocol['upload']['runtime'] === FormProtocol::upload_runtime()
        && $protocol['upload']['runtime']['batchSecretBytes'] === Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' )
        && $protocol['upload']['runtime']['uploadIdBytes'] === Anchors::get( 'MANAGED_UPLOAD_ID_BYTES' )
        && $protocol['upload']['runtime']['concurrency'] === Anchors::get( 'MANAGED_UPLOAD_CONCURRENCY' ),
    'Browser upload runtime bounds should come from Anchors through FormProtocol.'
);
eforms_test_assert(
    ! isset( $protocol['upload']['clientPreparationModes'] )
        && ! isset( $protocol['upload']['clientPreparationRecipe'] )
        && FormProtocol::client_preparation_recipe()['slots'] === Anchors::get( 'CLIENT_PREPARATION_SLOTS' )
        && FormProtocol::client_preparation_recipe()['exifMaxEntries'] === Anchors::get( 'CLIENT_PREPARATION_EXIF_MAX_ENTRIES' ),
    'Optional preparation settings should not participate in the mandatory browser upload protocol.'
);
eforms_test_assert(
    UploadPolicy::staged_extension_for_mime( 'image/jpeg' ) === 'jpg'
        && UploadPolicy::staged_extension_for_mime( 'image/heif' ) === 'heif'
        && UploadPolicy::staged_extension_for_mime( 'image/gif' ) === '',
    'PHP review filenames should use the staged policy owner for canonical extensions.'
);
eforms_test_assert(
    $protocol['upload']['mimeByExtension'] === UploadPolicy::staged_browser_mime_by_extension()
        && $protocol['upload']['mimeByExtension']['heic'] === 'image/heic'
        && $protocol['upload']['mimeByExtension']['heif'] === 'image/heif',
    'Browser MIME declarations should be projected from the authoritative staged upload policy.'
);

// Given the mint endpoint receives the protocol-owned form param...
// When it returns a successful response...
// Then response keys match the protocol-owned JSON names.
$request = array(
    'method' => 'POST',
    'headers' => array(
        'Origin' => 'https://example.com',
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
    'params' => array( FormProtocol::MINT_FORM_PARAM => 'contact' ),
    'client_ip' => '203.0.113.30',
);
$response = MintEndpoint::handle( $request );
eforms_test_assert( $response['status'] === 200, 'Mint should accept protocol-owned form param.' );
$body = $response['body'];
eforms_test_assert( isset( $body[ FormProtocol::MINT_RESPONSE_TOKEN ] ), 'Mint should emit protocol token key.' );
eforms_test_assert( isset( $body[ FormProtocol::MINT_RESPONSE_INSTANCE_ID ] ), 'Mint should emit protocol instance key.' );
eforms_test_assert( isset( $body[ FormProtocol::MINT_RESPONSE_TIMESTAMP ] ), 'Mint should emit protocol timestamp key.' );
eforms_test_assert( isset( $body[ FormProtocol::MINT_RESPONSE_EXPIRES ] ), 'Mint should emit protocol expires key.' );

eforms_test_remove_tree( $uploads_dir );
