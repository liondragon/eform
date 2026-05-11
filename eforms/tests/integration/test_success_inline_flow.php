<?php
/**
 * Integration test for plugin-owned success result URLs.
 *
 * Spec: Success behavior (docs/Canonical_Spec.md#sec-success)
 * Spec: Result page flow (docs/Canonical_Spec.md#sec-success-flow)
 * Spec: Cache-safety (docs/Canonical_Spec.md#sec-cache-safety)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';
require_once __DIR__ . '/../../src/Submission/Success.php';

if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        return array(
            'basedir' => isset( $GLOBALS['eforms_test_uploads_dir'] ) ? $GLOBALS['eforms_test_uploads_dir'] : '',
        );
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return isset( $GLOBALS['eforms_test_home_url'] ) && is_string( $GLOBALS['eforms_test_home_url'] )
            ? $GLOBALS['eforms_test_home_url']
            : 'https://example.com';
    }
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( $location, $status = 302 ) {
        $GLOBALS['eforms_test_redirects'][] = array(
            'location' => $location,
            'status' => (int) $status,
        );

        return array_key_exists( 'eforms_test_wp_safe_redirect_return', $GLOBALS )
            ? $GLOBALS['eforms_test_wp_safe_redirect_return']
            : true;
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

$context = array(
    'id' => 'contact',
    'success' => array(
        'mode' => 'inline',
        'message' => 'Thanks for your message!',
    ),
);

$options = array(
    'current_url' => 'https://example.com/contact/',
    'dry_run' => true,
);

$result = Success::redirect( $context, $options );

eforms_test_assert( $result['ok'] === true, 'Success redirect should return ok=true.' );
eforms_test_assert( $result['status'] === 303, 'Success redirect should use status 303.' );
eforms_test_assert( strpos( $result['location'], 'eforms_result=success' ) !== false, 'Success URL should contain result type.' );
eforms_test_assert( strpos( $result['location'], 'eforms_form=contact' ) !== false, 'Success URL should contain form id.' );
eforms_test_assert( strpos( $result['location'], 'eforms_success' ) === false, 'Success URL should not use the legacy eforms_success param.' );

$options_with_query = array(
    'current_url' => 'https://example.com/contact/?ref=nav&lang=en',
    'dry_run' => true,
);

$result_with_query = Success::redirect( $context, $options_with_query );

eforms_test_assert( $result_with_query['ok'] === true, 'Success with existing query should return ok=true.' );
eforms_test_assert( strpos( $result_with_query['location'], 'ref=nav' ) !== false, 'Success URL should preserve existing query params.' );
eforms_test_assert( strpos( $result_with_query['location'], 'lang=en' ) !== false, 'Success URL should preserve all existing query params.' );
eforms_test_assert( strpos( $result_with_query['location'], 'eforms_result=success' ) !== false, 'Success URL should add the result param.' );

$options_with_internal_args = array(
    'current_url' => 'https://example.com/contact/?eforms_email_retry=1&eforms_success=contact&eforms_result=email_failure&eforms_form=old',
    'dry_run' => true,
);

$clean_result = Success::redirect( $context, $options_with_internal_args );

eforms_test_assert( $clean_result['ok'] === true, 'Success redirect should clean old internal args.' );
eforms_test_assert( strpos( $clean_result['location'], 'eforms_email_retry' ) === false, 'Success URL should not contain retry marker.' );
eforms_test_assert( strpos( $clean_result['location'], 'eforms_success' ) === false, 'Success URL should not contain legacy success marker.' );
eforms_test_assert( substr_count( $clean_result['location'], 'eforms_result=' ) === 1, 'Success URL should contain one result marker.' );
eforms_test_assert( substr_count( $clean_result['location'], 'eforms_form=' ) === 1, 'Success URL should contain one form marker.' );

$GLOBALS['eforms_test_home_url'] = 'https://canonical.test/site-root';
$_SERVER['HTTP_HOST'] = 'evil.test';
$_SERVER['REQUEST_URI'] = '/contact/?ref=nav&eforms_email_retry=1&eforms_success=contact&eforms_result=email_failure&eforms_form=old';

$canonical_result = Success::redirect(
    $context,
    array(
        'dry_run' => true,
    )
);

eforms_test_assert( $canonical_result['ok'] === true, 'Success redirect should build from canonical site URL.' );
eforms_test_assert( strpos( $canonical_result['location'], 'https://canonical.test/contact/?' ) === 0, 'Success URL should use home_url origin.' );
eforms_test_assert( strpos( $canonical_result['location'], 'evil.test' ) === false, 'Success URL should not use request Host.' );
eforms_test_assert( strpos( $canonical_result['location'], 'ref=nav' ) !== false, 'Canonical success URL should preserve existing query params.' );
eforms_test_assert( strpos( $canonical_result['location'], 'eforms_email_retry' ) === false, 'Canonical success URL should clean retry marker.' );
eforms_test_assert( strpos( $canonical_result['location'], 'eforms_success' ) === false, 'Canonical success URL should clean legacy success marker.' );

$GLOBALS['eforms_test_wp_safe_redirect_return'] = false;
$GLOBALS['eforms_test_redirects'] = array();
$rejected_result = Success::redirect( $context );

eforms_test_assert( $rejected_result['ok'] === false, 'Rejected wp_safe_redirect should fail.' );
eforms_test_assert( $rejected_result['reason'] === 'redirect_rejected', 'Rejected wp_safe_redirect should use redirect_rejected reason.' );
eforms_test_assert( count( $GLOBALS['eforms_test_redirects'] ) === 1, 'Redirect rejection test should attempt one safe redirect.' );
unset( $GLOBALS['eforms_test_wp_safe_redirect_return'] );
unset( $GLOBALS['eforms_test_home_url'] );

$_GET = array(
    'eforms_result' => 'success',
    'eforms_form' => 'contact',
);
$parsed = Success::parse_result_request();
eforms_test_assert( is_array( $parsed ), 'Result request should parse.' );
eforms_test_assert( $parsed['result'] === 'success', 'Parsed result should be success.' );
eforms_test_assert( $parsed['form_id'] === 'contact', 'Parsed form id should match.' );

$_GET = array(
    'eforms_result' => 'unknown',
    'eforms_form' => 'contact',
);
eforms_test_assert( Success::parse_result_request() === null, 'Unknown result type should not parse.' );

$_GET = array( 'eforms_success' => 'contact' );
$uploads_dir = eforms_test_tmp_root( 'eforms-success-uploads' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

Config::reset_for_tests();
FormRenderer::reset_for_tests();

$html = FormRenderer::render( 'contact', array(
    'cacheable' => false,
) );

eforms_test_assert( strpos( $html, 'eforms-success-banner' ) === false, 'FormRenderer should not render legacy success banners.' );
eforms_test_assert( strpos( $html, '<form' ) !== false, 'Legacy eforms_success query should not suppress the form.' );

$_GET = array();
$cacheable_html = FormRenderer::render( 'contact', array(
    'cacheable' => true,
) );
eforms_test_assert(
    strpos( $cacheable_html, 'EFORMS_ERR_INLINE_SUCCESS_REQUIRES_NONCACHEABLE' ) === false,
    'Cacheable forms should no longer fail because success pages are virtual GET pages.'
);

eforms_test_remove_tree( $uploads_dir );

echo "All success result URL tests passed.\n";
