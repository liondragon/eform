<?php
/**
 * Integration tests for the declined-review wp-admin surface.
 *
 * Contract: Declined Review
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin/SubmissionsAdmin.php';
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

// Bootstrap always registers Settings -> eForms and retained submissions; declined review remains gated.
eforms_test_configure_declined_review( $uploads_dir, false );
$GLOBALS['eforms_test_hooks']['action']['admin_menu'] = array();
eforms_register_admin();
eforms_test_assert( isset( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) && count( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) === 2, 'Disabled declined review should register Settings and retained submissions hooks.' );
SettingsAdmin::register_menu();
SubmissionsAdmin::register_menu();
eforms_test_assert( count( $GLOBALS['eforms_test_options_pages'] ) === 1, 'Disabled declined review should register the Settings page.' );
eforms_test_assert( count( $GLOBALS['eforms_test_management_pages'] ) === 1, 'Disabled declined review should still register retained submissions.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][0]['menu_slug'] === SubmissionsAdmin::SLUG, 'Retained submissions should use the expected slug.' );

eforms_test_configure_declined_review( $uploads_dir, true );
$GLOBALS['eforms_test_options_pages'] = array();
$GLOBALS['eforms_test_management_pages'] = array();
$GLOBALS['eforms_test_hooks']['action']['admin_menu'] = array();
eforms_register_admin();
eforms_test_assert( isset( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) && count( $GLOBALS['eforms_test_hooks']['action']['admin_menu'] ) === 3, 'Enabled declined review should register Settings and Tools hooks.' );
SettingsAdmin::register_menu();
SubmissionsAdmin::register_menu();
DeclinedReviewAdmin::register_menu();
eforms_test_assert( count( $GLOBALS['eforms_test_options_pages'] ) === 1, 'Enabled declined review should register one Settings page.' );
eforms_test_assert( count( $GLOBALS['eforms_test_management_pages'] ) === 2, 'Admin menu should register both Tools pages.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][0]['menu_slug'] === SubmissionsAdmin::SLUG, 'Retained submissions should use the expected slug.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][1]['capability'] === 'manage_options', 'Declined review page should require manage_options.' );
eforms_test_assert( $GLOBALS['eforms_test_management_pages'][1]['menu_slug'] === DeclinedReviewAdmin::SLUG, 'Declined review page should use the expected slug.' );

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

$declined_page_size = Anchors::get( 'DECLINED_REVIEW_PAGE_SIZE' );
$declined_total = $declined_page_size + 1;
for ( $index = 0; $index < $declined_page_size; $index++ ) {
    DeclinedReviewLog::capture(
        array(
            'config' => $config,
            'form_id' => 'contact',
            'context' => eforms_declined_admin_context(),
            'request' => array(
                'request_id' => 'req-page-' . $index,
                'remote_addr' => '203.0.113.30',
                'uri' => '/submit',
            ),
            'security' => array(
                'submission_id' => 'sub-page-' . $index,
                'soft_reasons' => array( 'js_missing' ),
            ),
            'decision_code' => 'EFORMS_ERR_SPAM',
            'decision_phase' => 'spam_threshold',
            'value_stage' => 'raw_declared',
            'values' => array(
                'name' => 'Paged ' . $index,
                'message' => 'Pagination fixture',
            ),
        )
    );
}

$page_one = DeclinedReviewAdmin::render_html(
    array(
        'from' => gmdate( 'Y-m-d' ),
        'to' => gmdate( 'Y-m-d' ),
        'form_id' => 'contact',
        'decision_code' => 'EFORMS_ERR_SPAM',
    ),
    $config
);
eforms_test_assert( strpos( $page_one, $declined_total . ' items' ) !== false, 'Declined admin should show the WordPress-style item count.' );
eforms_test_assert( strpos( $page_one, 'Next page' ) !== false, 'Declined admin should render a next-page control when more records exist.' );
eforms_test_assert( strpos( $page_one, 'Previous page' ) === false, 'Declined admin first page should not render a previous-page control.' );
eforms_test_assert( strpos( $page_one, 'paged=2' ) !== false, 'Declined admin next-page URL should include the next page.' );
eforms_test_assert( strpos( $page_one, 'next-page button' ) !== false && strpos( $page_one, 'last-page button' ) !== false, 'Declined admin pagination should use the WordPress admin list-table control shape.' );
eforms_test_assert( strpos( $page_one, 'page-numbers' ) === false, 'Declined admin pagination should not use front-end page-number markup.' );
eforms_test_assert( strpos( $page_one, 'form_id=contact' ) !== false && strpos( $page_one, 'decision_code=EFORMS_ERR_SPAM' ) !== false, 'Declined admin pagination should preserve active filters.' );

$page_two = DeclinedReviewAdmin::render_html(
    array(
        'from' => gmdate( 'Y-m-d' ),
        'to' => gmdate( 'Y-m-d' ),
        'form_id' => 'contact',
        'decision_code' => 'EFORMS_ERR_SPAM',
        'paged' => '2',
    ),
    $config
);
eforms_test_assert( strpos( $page_two, $declined_total . ' items' ) !== false, 'Declined admin should preserve the WordPress-style item count after paging.' );
eforms_test_assert( strpos( $page_two, 'Previous page' ) !== false, 'Declined admin should render a previous-page control after page one.' );
eforms_test_assert( strpos( $page_two, 'Next page' ) === false, 'Declined admin last page should not render a next-page control.' );
preg_match( '/<a class="prev-page button" href="([^"]+)">/', $page_two, $previous_match );
eforms_test_assert( ! empty( $previous_match[1] ) && strpos( $previous_match[1], 'paged=' ) === false, 'Declined admin previous-page URL should return to the canonical first-page URL.' );

$clamped = DeclinedReviewLog::query( array( 'page' => 999 ), $config );
eforms_test_assert( $clamped['page'] === 2 && count( $clamped['records'] ) === 1, 'Declined-review query should clamp over-large pages to the last populated page.' );

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
eforms_test_assert( strpos( $limited, 'Clear declined review data' ) !== false, 'Admin list should render declined-review maintenance action.' );
eforms_test_assert( strpos( $limited, 'declined_review.retention_days' ) !== false, 'Maintenance copy should explain retention-driven cleanup.' );

// Declined-review maintenance clears only confirmed eligible declined JSONL files.
$cleanup_path = rtrim( $dir, '/\\' ) . '/declined-20000101.jsonl';
file_put_contents( $cleanup_path, '{"review_id":"old-cleanup"}' . "\n" );
touch( $cleanup_path, time() - 172800 );

$clear_post = function ( $days, $nonce = 'valid-nonce', $checked = false ) {
    $post = array(
        DeclinedReviewAdmin::ACTION_FIELD => DeclinedReviewAdmin::CLEAR_ACTION,
        DeclinedReviewAdmin::CLEAR_NONCE_FIELD => $nonce,
        'older_than_days' => (string) $days,
    );
    if ( $checked ) {
        $post['confirm_clear'] = '1';
    }
    return $post;
};

$GLOBALS['eforms_test_can_manage'] = false;
eforms_test_assert( DeclinedReviewAdmin::render_html( array(), $config, $clear_post( 1, 'valid-nonce', true ) ) === '', 'Unauthorized cleanup render should return no HTML.' );
eforms_test_assert( file_exists( $cleanup_path ), 'Unauthorized cleanup should not delete declined files.' );
$GLOBALS['eforms_test_can_manage'] = true;

$bad_nonce = DeclinedReviewAdmin::render_html( array(), $config, $clear_post( 1, 'bad-nonce', true ) );
eforms_test_assert( strpos( $bad_nonce, 'security check failed' ) !== false, 'Bad nonce cleanup should show an error.' );
eforms_test_assert( file_exists( $cleanup_path ), 'Bad nonce cleanup should not delete declined files.' );

$prepare = DeclinedReviewAdmin::render_html( array(), $config, $clear_post( 1 ) );
eforms_test_assert( strpos( $prepare, 'Confirm declined review cleanup' ) !== false, 'Prepare cleanup should render a confirmation screen.' );
eforms_test_assert( file_exists( $cleanup_path ), 'Prepare cleanup should not delete declined files.' );

$missing_confirmation = DeclinedReviewAdmin::render_html( array(), $config, $clear_post( 1 ) );
eforms_test_assert( strpos( $missing_confirmation, 'Confirm declined review cleanup' ) !== false, 'Missing cleanup confirmation should return to the confirmation screen.' );
eforms_test_assert( file_exists( $cleanup_path ), 'Missing cleanup confirmation should not delete declined files.' );

$invalid_days = DeclinedReviewAdmin::render_html( array(), $config, $clear_post( Anchors::get( 'RETENTION_DAYS_MAX' ) + 1 ) );
eforms_test_assert( strpos( $invalid_days, 'between 0 and ' . Anchors::get( 'RETENTION_DAYS_MAX' ) ) !== false, 'Invalid cleanup days should be rejected.' );
eforms_test_assert( file_exists( $cleanup_path ), 'Invalid cleanup days should not delete declined files.' );

$cleanup = DeclinedReviewAdmin::render_html( array(), $config, $clear_post( 1, 'valid-nonce', true ) );
eforms_test_assert( strpos( $cleanup, '1 deleted, 0 failed' ) !== false, 'Cleanup should report deleted eligible files.' );
eforms_test_assert( ! file_exists( $cleanup_path ), 'Cleanup should delete old declined files.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
