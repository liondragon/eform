<?php
/**
 * Integration tests for hidden-mode GET render.
 *
 * Spec: Request lifecycle GET (docs/Canonical_Spec.md#sec-request-lifecycle-get)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 * Spec: Security invariants (docs/Canonical_Spec.md#sec-security-invariants)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Helpers.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => isset( $GLOBALS['eforms_test_uploads_dir'] ) ? $GLOBALS['eforms_test_uploads_dir'] : '',
        );
    }
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src, $deps = array(), $ver = false ) {
        if ( ! isset( $GLOBALS['eforms_test_styles'] ) ) {
            $GLOBALS['eforms_test_styles'] = array();
        }
        $GLOBALS['eforms_test_styles'][] = array(
            'handle' => $handle,
            'src' => $src,
        );
    }
}

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

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return $path;
    }
}

if ( ! function_exists( 'nocache_headers' ) ) {
    function nocache_headers() {
        $GLOBALS['eforms_test_nocache_called'] = true;
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

// Given a writable uploads dir...
// When FormRenderer renders a hidden-mode form...
// Then it mints and embeds the token metadata with cache-safety headers.
$uploads_dir = eforms_test_tmp_root( 'eforms-render-get' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
$GLOBALS['eforms_test_styles'] = array();
$GLOBALS['eforms_test_scripts'] = array();
$GLOBALS['eforms_test_nocache_called'] = false;

Config::reset_for_tests();
StorageHealth::reset_for_tests();
FormRenderer::reset_for_tests();
Logging::reset();

if ( function_exists( 'header_remove' ) ) {
    header_remove();
}

$output = FormRenderer::render( 'contact', array() );
eforms_test_assert( is_string( $output ), 'Renderer should return HTML.' );
eforms_test_assert(
    strpos( $output, 'eforms-form-contact_us' ) !== false,
    'Renderer should emit the form class with template id.'
);
eforms_test_assert(
    strpos( $output, 'name="eforms_mode" value="hidden"' ) !== false,
    'Renderer should emit hidden-mode metadata.'
);

eforms_test_assert(
    preg_match( '/name="eforms_token" value="([^"]+)"/', $output, $token_match ) === 1,
    'Renderer should embed a hidden token.'
);
eforms_test_assert(
    preg_match( '/name="instance_id" value="([^"]+)"/', $output, $instance_match ) === 1,
    'Renderer should embed instance_id.'
);
eforms_test_assert(
    preg_match( '/name="timestamp" value="([^"]+)"/', $output, $timestamp_match ) === 1,
    'Renderer should embed timestamp.'
);

$token = $token_match[1];
$instance_id = $instance_match[1];
$timestamp = $timestamp_match[1];

eforms_test_assert(
    preg_match( '/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $token ) === 1,
    'Embedded token should be UUIDv4.'
);
eforms_test_assert(
    preg_match( '/^[A-Za-z0-9_-]{22,32}$/', $instance_id ) === 1,
    'Embedded instance_id should be base64url.'
);
eforms_test_assert( ctype_digit( $timestamp ), 'Embedded timestamp should be numeric.' );

$record_path = $uploads_dir . '/eforms-private/tokens/' . Helpers::h2( $token ) . '/' . hash( 'sha256', $token ) . '.json';
eforms_test_assert( is_file( $record_path ), 'Token record should be written to disk.' );

eforms_test_assert(
    strpos( $output, 'novalidate' ) === false,
    'html5.client_validation defaults to true (no novalidate attribute).'
);

eforms_test_assert(
    ! empty( $GLOBALS['eforms_test_styles'] ),
    'Renderer should enqueue CSS when a form is rendered.'
);
eforms_test_assert(
    ! empty( $GLOBALS['eforms_test_nocache_called'] ),
    'Renderer should call nocache_headers for hidden-mode renders.'
);

$headers = function_exists( 'headers_list' ) ? headers_list() : array();
if ( ! empty( $headers ) ) {
    $has_cache = false;
    foreach ( $headers as $header ) {
        if ( stripos( $header, 'Cache-Control:' ) === 0 && stripos( $header, 'private, no-store, max-age=0' ) !== false ) {
            $has_cache = true;
            break;
        }
    }
    eforms_test_assert( $has_cache, 'Renderer should set Cache-Control for hidden-mode.' );
}

// Given a second render of the same form id...
// When FormRenderer is invoked again...
// Then it returns a duplicate form id configuration error.
$dup = FormRenderer::render( 'contact', array() );
eforms_test_assert(
    strpos( $dup, 'EFORMS_ERR_DUPLICATE_FORM_ID' ) !== false,
    'Renderer should reject duplicate form ids on the same page.'
);

eforms_test_remove_tree( $uploads_dir );
