<?php
/**
 * Integration tests for the declined-review wp-admin surface.
 *
 * Spec: Declined Review (docs/Canonical_Spec.md#sec-declined-review)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin/DeclinedReviewAdmin.php';

if ( ! function_exists( 'eforms_declined_admin_context' ) ) {
    function eforms_declined_admin_context() {
        return array(
            'descriptors' => array(
                array( 'key' => 'name', 'type' => 'text' ),
                array( 'key' => 'message', 'type' => 'textarea' ),
            ),
        );
    }
}

$uploads_dir = eforms_test_tmp_root( 'eforms-declined-admin' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;
$GLOBALS['eforms_test_can_manage'] = true;
$GLOBALS['eforms_test_management_pages'] = array();
$GLOBALS['eforms_test_options_pages'] = array();

// Bootstrap always registers Settings -> eForms; the Tools page remains gated.
eforms_test_configure_declined_review( $uploads_dir, false );
$GLOBALS['eforms_test_hooks']['action']['admin_menu'] = array();
eforms_register_admin();
eforms_test_assert( isset( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) && count( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) === 1, 'Disabled declined review should register only the Settings hook.' );
SettingsAdmin::register_menu();
eforms_test_assert( count( $GLOBALS['eforms_test_options_pages'] ) === 1, 'Disabled declined review should register the Settings page.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'] === array(), 'Disabled declined review should not register the Tools page.' );

eforms_test_configure_declined_review( $uploads_dir, true );
$GLOBALS['eforms_test_options_pages'] = array();
$GLOBALS['eforms_test_management_pages'] = array();
$GLOBALS['eforms_test_hooks']['action']['admin_menu'] = array();
eforms_register_admin();
eforms_test_assert( isset( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) && count( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) === 2, 'Enabled declined review should register Settings and Tools hooks.' );
SettingsAdmin::register_menu();
DeclinedReviewAdmin::register_menu();
eforms_test_assert( count( $GLOBALS['eforms_test_options_pages'] ) === 1, 'Enabled declined review should register one Settings page.' );
eforms_test_assert( count( $GLOBALS['eforms_test_management_pages'] ) === 1, 'Admin menu should register one Tools page.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][0]['capability'] === 'manage_options', 'Admin page should require manage_options.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][0]['menu_slug'] === DeclinedReviewAdmin::SLUG, 'Admin page should use the expected slug.' );

// Capability guard prevents rendering.
$GLOBALS['eforms_test_can_manage'] = false;
eforms_test_configure_declined_review( $uploads_dir, true );
eforms_test_assert( DeclinedReviewAdmin::render_html( array(), Config::get() ) === '', 'Unauthorized admin render should return no HTML.' );

$GLOBALS['eforms_test_can_manage'] = true;
eforms_test_configure_declined_review( $uploads_dir, true );
$config = Config::get();
DeclinedReviewLog::capture(
    array(
        'config' => $config,
        'form_id' => 'contact',
        'context' => eforms_declined_admin_context(),
        'request' => array(
            'request_id' => 'req-admin',
            'remote_addr' => '203.0.113.30',
            'uri' => '/submit?secret=drop',
        ),
        'security' => array(
            'submission_id' => 'sub-admin',
            'soft_reasons' => array( 'js_missing' ),
        ),
        'decision_code' => 'EFORMS_ERR_SPAM',
        'decision_phase' => 'spam_threshold',
        'value_stage' => 'raw_declared',
        'values' => array(
            'name' => '<script>alert(1)</script>',
            'message' => 'Hello & goodbye',
        ),
    )
);

$query = DeclinedReviewLog::query( array(), $config );
$review_id = $query['records'][0]['review_id'];
$html_filter = DeclinedReviewAdmin::render_html(
    array(
        'from' => gmdate( 'Y-m-d' ),
        'to' => gmdate( 'Y-m-d' ),
        'form_id' => 'contact<script>',
        'decision_code' => 'EFORMS_ERR_SPAM',
    ),
    $config
);
eforms_test_assert( strpos( $html_filter, 'contact&lt;script&gt;' ) !== false, 'Filter values should be escaped.' );

$html = DeclinedReviewAdmin::render_html(
    array(
        'from' => gmdate( 'Y-m-d' ),
        'to' => gmdate( 'Y-m-d' ),
        'form_id' => 'contact',
        'decision_code' => 'EFORMS_ERR_SPAM',
    ),
    $config
);
eforms_test_assert( strpos( $html, 'widefat striped eforms-declined-table' ) !== false, 'Admin list should render a WP-admin table.' );
eforms_test_assert( strpos( $html, '<script>alert(1)</script>' ) === false, 'List table must not render raw submitted HTML.' );
eforms_test_assert( strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) !== false, 'List table should escape submitted HTML in previews.' );
eforms_test_assert( strpos( $html, $uploads_dir ) === false, 'Admin list must not expose storage paths.' );
eforms_test_assert( strpos( $html, 'declined-' . gmdate( 'Ymd' ) ) === false, 'Admin list must not expose JSONL filenames.' );
eforms_test_assert( strpos( $html, 'review_id=' . rawurlencode( $review_id ) ) !== false, 'Detail link should use review_id.' );

$detail = DeclinedReviewAdmin::render_html(
    array(
        'review_id' => $review_id,
        'from' => gmdate( 'Y-m-d' ),
        'to' => gmdate( 'Y-m-d' ),
    ),
    $config
);
eforms_test_assert( strpos( $detail, 'Declined submission detail' ) !== false, 'Detail view should render a detail heading.' );
eforms_test_assert( strpos( $detail, 'Submitted fields' ) !== false, 'Detail view should include submitted fields.' );
eforms_test_assert( strpos( $detail, '<script>alert(1)</script>' ) === false, 'Detail view must not render raw submitted HTML.' );
eforms_test_assert( strpos( $detail, '&lt;script&gt;alert(1)&lt;/script&gt;' ) !== false, 'Detail view should escape submitted values.' );
eforms_test_assert( strpos( $detail, $uploads_dir ) === false, 'Detail view must not expose storage paths.' );

$missing = DeclinedReviewAdmin::render_html(
    array(
        'review_id' => 'missing-review-id',
        'from' => gmdate( 'Y-m-d' ),
        'to' => gmdate( 'Y-m-d' ),
    ),
    $config
);
eforms_test_assert( strpos( $missing, 'record not found' ) !== false, 'Missing detail should show a normal not-found notice.' );

// Scan-limit notices surface as admin notices.
$dir = PrivateDir::path( $uploads_dir ) . '/' . DeclinedReviewLog::DIR;
$limit_line = json_encode( array( 'review_id' => 'limit', 'ts' => gmdate( 'c' ), 'form_id' => 'contact', 'decision_code' => 'EFORMS_ERR_SPAM' ) ) . "\n";
file_put_contents( rtrim( $dir, '/\\' ) . '/declined-' . gmdate( 'Ymd' ) . '-9999.jsonl', str_repeat( $limit_line, Anchors::get( 'DECLINED_REVIEW_SCAN_MAX_RECORDS' ) + 1 ) );
$limited = DeclinedReviewAdmin::render_html( array(), $config );
eforms_test_assert( strpos( $limited, 'scan limit was reached' ) !== false, 'Scan-limit result should show an admin notice.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
