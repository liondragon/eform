<?php
/**
 * Integration test for adaptive challenge render + verify flow.
 *
 * Contract: Adaptive challenge
 * Contract: Validation pipeline
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/DeclinedReviewLog.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';
require_once __DIR__ . '/../../src/Security/Security.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Submission/SubmitHandler.php';
require_once __DIR__ . '/../../src/Validation/Coercer.php';
require_once __DIR__ . '/../../src/Validation/Normalizer.php';
require_once __DIR__ . '/../../src/Validation/Validator.php';

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return $path;
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
        if ( ! isset( $GLOBALS['eforms_test_scripts'] ) || ! is_array( $GLOBALS['eforms_test_scripts'] ) ) {
            $GLOBALS['eforms_test_scripts'] = array();
        }

        $GLOBALS['eforms_test_scripts'][] = array(
            'handle' => $handle,
            'src' => $src,
        );
    }
}

if ( ! function_exists( 'wp_script_add_data' ) ) {
    function wp_script_add_data( $handle, $key, $value ) {
        return true;
    }
}

if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        if ( ! isset( $GLOBALS['eforms_test_remote_posts'] ) || ! is_array( $GLOBALS['eforms_test_remote_posts'] ) ) {
            $GLOBALS['eforms_test_remote_posts'] = array();
        }
        $GLOBALS['eforms_test_remote_posts'][] = array( 'url' => $url, 'args' => $args );

        if ( isset( $GLOBALS['eforms_test_trace'] ) && is_array( $GLOBALS['eforms_test_trace'] ) ) {
            $GLOBALS['eforms_test_trace'][] = 'challenge';
        }

        return array(
            'response' => array( 'code' => 200 ),
            'body' => json_encode( array( 'success' => true ) ),
        );
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ) {
        return false;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        if ( is_array( $response )
            && isset( $response['response'] )
            && is_array( $response['response'] )
            && isset( $response['response']['code'] ) ) {
            return $response['response']['code'];
        }

        return 0;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        if ( is_array( $response ) && isset( $response['body'] ) && is_string( $response['body'] ) ) {
            return $response['body'];
        }

        return '';
    }
}

$uploads_dir = eforms_test_setup_uploads( 'eforms-challenge-uploads' );

$template_dir = eforms_test_tmp_root( 'eforms-challenge-templates' );
mkdir( $template_dir, 0700, true );
eforms_test_write_basic_template( $template_dir, 'demo', 'Challenge Demo' );

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

// Given challenge always_post is configured...
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['security']['origin_mode'] = 'off';
        $config['challenge']['mode'] = 'always_post';
        $config['challenge']['provider'] = 'turnstile';
        $config['challenge']['site_key'] = 'site-key-123';
        $config['challenge']['secret_key'] = 'secret-key-123';
        $config['challenge']['http_timeout_seconds'] = 2;
        $config['declined_review']['enable'] = true;
        $config['declined_review']['retention_days'] = 30;
        return $config;
    }
);

Config::reset_for_tests();
StorageHealth::reset_for_tests();
FormRenderer::reset_for_tests();
$GLOBALS['eforms_test_scripts'] = array();

// When rendering initial GET...
$initial = FormRenderer::render( 'contact', array() );

// Then challenge is never rendered on initial GET.
eforms_test_assert( strpos( $initial, 'cf-turnstile' ) === false, 'Initial GET must not render Turnstile widget.' );
$initial_scripts = isset( $GLOBALS['eforms_test_scripts'] ) && is_array( $GLOBALS['eforms_test_scripts'] ) ? $GLOBALS['eforms_test_scripts'] : array();
$initial_has_turnstile_script = false;
foreach ( $initial_scripts as $script ) {
    if ( isset( $script['src'] ) && is_string( $script['src'] ) && strpos( $script['src'], 'challenges.cloudflare.com/turnstile' ) !== false ) {
        $initial_has_turnstile_script = true;
        break;
    }
}
eforms_test_assert( $initial_has_turnstile_script === false, 'Initial GET must not enqueue Turnstile script.' );

// Given a valid POST with a provider response...
Config::reset_for_tests();
StorageHealth::reset_for_tests();
$mint = Security::mint_hidden_record( 'demo' );
$post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
    'cf-turnstile-response' => 'turnstile-ok-token',
    'demo' => array(
        'name' => 'Ada',
    ),
);
$request = array(
    'post' => $post,
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Origin' => 'https://example.com',
    ),
    'client_ip' => '203.0.113.50',
);

$trace = array();
$GLOBALS['eforms_test_trace'] = &$trace;
$GLOBALS['eforms_test_remote_posts'] = array();

$ordering_overrides = array(
    'template_base_dir' => $template_dir,
    'security' => function ( $post_data, $form_id, $request_data, $uploads_override ) use ( &$trace ) {
        $trace[] = 'security';
        return Security::token_validate( $post_data, $form_id, $request_data, $uploads_override );
    },
    'normalize' => function ( $context, $post_data, $files_data ) use ( &$trace ) {
        $trace[] = 'normalize';
        return NormalizerStage::normalize( $context, $post_data, $files_data );
    },
    'validate' => function ( $context, $normalized ) use ( &$trace ) {
        $trace[] = 'validate';
        return Validator::validate( $context, $normalized );
    },
    'coerce' => function ( $context, $validated ) use ( &$trace ) {
        $trace[] = 'coerce';
        return Coercer::coerce( $context, $validated );
    },
    'ledger_reserve' => function () use ( &$trace ) {
        $trace[] = 'ledger';
        return array( 'ok' => true );
    },
    'commit' => function () use ( &$trace ) {
        $trace[] = 'commit';
        return array( 'ok' => true, 'status' => 200, 'committed' => true );
    },
);

$ok_result = SubmitHandler::handle( 'demo', $request, $ordering_overrides );

// When submit pipeline runs...
// Then challenge verification occurs before ledger reservation.
eforms_test_assert( $ok_result['ok'] === true, 'Challenge success path should return ok=true.' );
eforms_test_assert(
    $trace === array( 'security', 'normalize', 'validate', 'coerce', 'challenge', 'ledger', 'commit' ),
    'Challenge verification must happen before ledger reservation.'
);
eforms_test_assert(
    count( $GLOBALS['eforms_test_remote_posts'] ) === 1,
    'Turnstile verify endpoint should be called exactly once.'
);
$declined = DeclinedReviewLog::query( array(), Config::get() );
eforms_test_assert( $declined['total'] === 0, 'Successful challenge verification should not create a declined-review record.' );

// Given required challenge but missing provider response...
Config::reset_for_tests();
StorageHealth::reset_for_tests();
$mint = Security::mint_hidden_record( 'demo' );
$missing_post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
    'demo' => array(
        'name' => 'Ada',
    ),
);
$missing_request = array(
    'post' => $missing_post,
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Origin' => 'https://example.com',
    ),
);

$ledger_called = false;
$missing_result = SubmitHandler::handle(
    'demo',
    $missing_request,
    array(
        'template_base_dir' => $template_dir,
        'ledger_reserve' => function () use ( &$ledger_called ) {
            $ledger_called = true;
            return array( 'ok' => true );
        },
    )
);

// When verification token is missing...
// Then SubmitHandler rerenders with challenge_failed and no ledger write.
eforms_test_assert( $missing_result['ok'] === false, 'Missing challenge token should fail submission.' );
eforms_test_assert( $missing_result['status'] === 200, 'Missing challenge token should rerender with HTTP 200.' );
eforms_test_assert( $missing_result['error_code'] === 'EFORMS_ERR_CHALLENGE_FAILED', 'Missing challenge token should return challenge failure code.' );
eforms_test_assert( ! empty( $missing_result['require_challenge'] ), 'Missing challenge token should require challenge on rerender.' );
eforms_test_assert( $ledger_called === false, 'Challenge failure must occur before ledger reservation.' );
$declined = DeclinedReviewLog::query( array(), Config::get() );
eforms_test_assert( $declined['total'] === 0, 'Missing challenge response should not create a declined-review record.' );

FormRenderer::reset_for_tests();
$GLOBALS['eforms_test_scripts'] = array();
$rerender_html = FormRenderer::render(
    'contact',
    array(
        'errors' => $missing_result['errors'],
        'security' => $missing_result['security'],
        'values' => array(
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
        ),
        'require_challenge' => $missing_result['require_challenge'],
        'force_cache_headers' => true,
    )
);

// Then rerender includes challenge widget + provider script.
eforms_test_assert( strpos( $rerender_html, 'cf-turnstile' ) !== false, 'Challenge rerender should include Turnstile widget.' );
$rerender_scripts = isset( $GLOBALS['eforms_test_scripts'] ) && is_array( $GLOBALS['eforms_test_scripts'] ) ? $GLOBALS['eforms_test_scripts'] : array();
$rerender_has_turnstile_script = false;
foreach ( $rerender_scripts as $script ) {
    if ( isset( $script['src'] ) && is_string( $script['src'] ) && strpos( $script['src'], 'challenges.cloudflare.com/turnstile' ) !== false ) {
        $rerender_has_turnstile_script = true;
        break;
    }
}
eforms_test_assert( $rerender_has_turnstile_script, 'Challenge rerender should enqueue Turnstile script.' );

// Given a submitted provider response that verification rejects...
// Then declined review captures the canonical field values before rerender.
Config::reset_for_tests();
StorageHealth::reset_for_tests();
$mint = Security::mint_hidden_record( 'demo' );
$provider_post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
    'cf-turnstile-response' => 'turnstile-bad-token',
    'demo' => array(
        'name' => '  Ada  ',
    ),
);
$provider_request = array(
    'post' => $provider_post,
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Origin' => 'https://example.com',
    ),
);
$provider_ledger_called = false;
$provider_result = SubmitHandler::handle(
    'demo',
    $provider_request,
    array(
        'template_base_dir' => $template_dir,
        'challenge' => function () {
            return array(
                'ok' => false,
                'required' => true,
                'error_code' => 'EFORMS_ERR_CHALLENGE_FAILED',
                'soft_reasons' => array(),
            );
        },
        'ledger_reserve' => function () use ( &$provider_ledger_called ) {
            $provider_ledger_called = true;
            return array( 'ok' => true );
        },
    )
);
eforms_test_assert( $provider_result['ok'] === false, 'Rejected provider challenge should fail submission.' );
eforms_test_assert( $provider_result['error_code'] === 'EFORMS_ERR_CHALLENGE_FAILED', 'Rejected provider challenge should return challenge failure code.' );
eforms_test_assert( $provider_ledger_called === false, 'Rejected provider challenge must not reserve the ledger.' );
$declined = DeclinedReviewLog::query( array( 'decision_code' => 'EFORMS_ERR_CHALLENGE_FAILED' ), Config::get() );
eforms_test_assert( $declined['total'] === 1, 'Rejected provider challenge should create one declined-review record.' );
$declined_record = $declined['records'][0];
eforms_test_assert( $declined_record['decision_phase'] === 'challenge_verify', 'Challenge declined record should identify the verify phase.' );
eforms_test_assert( $declined_record['value_stage'] === 'canonical', 'Challenge declined record should capture canonical values.' );
eforms_test_assert( $declined_record['fields']['name'] === 'Ada', 'Challenge declined record should include canonical field content.' );
eforms_test_assert( $declined_record['challenge']['error_code'] === 'EFORMS_ERR_CHALLENGE_FAILED', 'Challenge declined record should include challenge failure metadata.' );

// Given always_post challenge is unconfigured...
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        $config['security']['origin_mode'] = 'off';
        $config['challenge']['mode'] = 'always_post';
        $config['challenge']['provider'] = 'turnstile';
        $config['challenge']['site_key'] = '';
        $config['challenge']['secret_key'] = '';
        return $config;
    }
);
Config::reset_for_tests();
StorageHealth::reset_for_tests();
$mint = Security::mint_hidden_record( 'demo' );
$unconfigured_post = array(
    'eforms_token' => $mint['token'],
    'instance_id' => $mint['instance_id'],
    'timestamp' => (string) $mint['issued_at'],
    'js_ok' => '1',
    'demo' => array(
        'name' => 'Ada',
    ),
);
$unconfigured_request = array(
    'post' => $unconfigured_post,
    'files' => array(),
    'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
    ),
);

$unconfigured_result = SubmitHandler::handle(
    'demo',
    $unconfigured_request,
    array(
        'template_base_dir' => $template_dir,
    )
);

// Then deterministic unconfigured error is returned.
eforms_test_assert( $unconfigured_result['ok'] === false, 'Unconfigured challenge should fail.' );
eforms_test_assert( $unconfigured_result['status'] === 500, 'Unconfigured challenge should hard-fail with HTTP 500.' );
eforms_test_assert(
    $unconfigured_result['error_code'] === 'EFORMS_CHALLENGE_UNCONFIGURED',
    'Unconfigured challenge should return deterministic error code.'
);

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
StorageHealth::reset_for_tests();
FormRenderer::reset_for_tests();
unset( $GLOBALS['eforms_test_trace'] );
eforms_test_remove_tree( $uploads_dir );
eforms_test_remove_tree( $template_dir );
