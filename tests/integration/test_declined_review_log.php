<?php
/**
 * Integration tests for the declined-submission review JSONL store.
 *
 * Contract: Declined Review
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Uploads/PrivateDir.php';
require_once __DIR__ . '/../../src/DeclinedReviewLog.php';

if ( ! function_exists( 'eforms_declined_test_context' ) ) {
    function eforms_declined_test_context() {
        return array(
            'descriptors' => array(
                array( 'key' => 'name', 'type' => 'text' ),
                array( 'key' => 'email', 'type' => 'email' ),
                array( 'key' => 'message', 'type' => 'textarea' ),
                array( 'key' => 'resume', 'type' => 'file' ),
            ),
        );
    }
}

if ( ! function_exists( 'eforms_declined_test_dir' ) ) {
    function eforms_declined_test_dir( $uploads_dir ) {
        return PrivateDir::path( $uploads_dir ) . '/' . DeclinedReviewLog::DIR;
    }
}

if ( ! function_exists( 'eforms_declined_test_capture_args' ) ) {
    function eforms_declined_test_capture_args( $overrides = array() ) {
        $args = array(
            'form_id' => 'contact',
            'context' => eforms_declined_test_context(),
            'request' => array(
                'request_id' => 'req-123',
                'remote_addr' => '203.0.113.10',
                'uri' => '/submit?eforms_public=1&secret=drop',
            ),
            'security' => array(
                'submission_id' => 'sub-123',
                'soft_reasons' => array( 'js_missing', 'origin_missing' ),
            ),
            'decision_code' => 'EFORMS_ERR_SPAM',
            'decision_phase' => 'spam_threshold',
            'value_stage' => 'raw_declared',
            'values' => array(
                'name' => 'Ada',
                'email' => 'ada@example.com',
                'message' => str_repeat( 'x', 6000 ) . "\0bad",
                'eforms_token' => 'must-not-leak',
                'timestamp' => 'must-not-leak',
            ),
            'uploads' => array(
                'name' => array( 'resume' => '../cv.pdf' ),
                'size' => array( 'resume' => 42 ),
                'error' => array( 'resume' => 0 ),
                'type' => array( 'resume' => 'application/pdf' ),
                'tmp_name' => array( 'resume' => '/tmp/private' ),
            ),
        );

        foreach ( $overrides as $key => $value ) {
            $args[ $key ] = $value;
        }

        return $args;
    }
}

if ( ! function_exists( 'eforms_declined_test_files' ) ) {
    function eforms_declined_test_files( $dir ) {
        $entries = is_dir( $dir ) ? scandir( $dir ) : array();
        $files = array();
        foreach ( $entries as $entry ) {
            if ( DeclinedReviewLog::is_declined_file( $entry ) ) {
                $files[] = $entry;
            }
        }
        sort( $files );
        return $files;
    }
}

if ( ! function_exists( 'eforms_declined_test_first_record' ) ) {
    function eforms_declined_test_first_record( $dir ) {
        $files = eforms_declined_test_files( $dir );
        eforms_test_assert( ! empty( $files ), 'Declined test expected at least one JSONL file.' );
        $line = trim( file_get_contents( rtrim( $dir, '/\\' ) . '/' . $files[0] ) );
        $lines = explode( "\n", $line );
        $record = json_decode( $lines[0], true );
        eforms_test_assert( is_array( $record ), 'Declined JSONL record should decode.' );
        return $record;
    }
}

$uploads_dir = eforms_test_tmp_root( 'eforms-declined-review' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

// Disabled mode writes nothing.
eforms_test_configure_declined_review( $uploads_dir, false );
$disabled_config = Config::get();
eforms_test_assert( DeclinedReviewLog::capture( eforms_declined_test_capture_args( array( 'config' => $disabled_config ) ) ) === false, 'Disabled declined review should not write.' );
eforms_test_assert( ! is_dir( eforms_declined_test_dir( $uploads_dir ) ), 'Disabled declined review should not create the declined directory.' );
eforms_test_assert( ! is_dir( $uploads_dir . '/eforms-private' ), 'Disabled/read-only declined review should not create private storage.' );
$missing_clear = DeclinedReviewLog::clear_older_than( 1, $disabled_config );
eforms_test_assert( $missing_clear['ok'] === true && $missing_clear['deleted'] === 0, 'Cleanup should be a no-op success when declined storage is missing.' );
eforms_test_assert( ! is_dir( $uploads_dir . '/eforms-private' ), 'Missing-storage cleanup should not create private storage.' );

// Enabled mode writes bounded review records and excludes protocol/security fields.
eforms_test_configure_declined_review( $uploads_dir, true );
$config = Config::get();
eforms_test_assert( DeclinedReviewLog::capture( eforms_declined_test_capture_args( array( 'config' => $config ) ) ) === true, 'Enabled declined review should write.' );
$dir = eforms_declined_test_dir( $uploads_dir );
$record = eforms_declined_test_first_record( $dir );

eforms_test_assert( $record['form_id'] === 'contact', 'Record should include form id.' );
eforms_test_assert( $record['submission_id'] === 'sub-123', 'Record should include submission id.' );
eforms_test_assert( $record['request_id'] === 'req-123', 'Record should include request id.' );
eforms_test_assert( $record['decision_code'] === 'EFORMS_ERR_SPAM', 'Record should include decision code.' );
eforms_test_assert( $record['decision_phase'] === 'spam_threshold', 'Record should include decision phase.' );
eforms_test_assert( $record['value_stage'] === 'raw_declared', 'Record should include value stage.' );
eforms_test_assert( ! isset( $record['soft_fail_count'] ), 'Record should not store computed soft_fail_count.' );
eforms_test_assert( ! isset( $record['threshold'] ), 'Record should not store computed threshold.' );
eforms_test_assert( $record['ip'] === '203.0.113.10', 'Record should respect privacy.ip_mode.' );
eforms_test_assert( $record['uri'] === '/submit?eforms_public=1', 'Record should keep only eforms query params.' );
eforms_test_assert( isset( $record['fields']['name'] ) && $record['fields']['name'] === 'Ada', 'Record should capture declared field content.' );
eforms_test_assert( strlen( $record['fields']['message'] ) <= Anchors::get( 'DECLINED_REVIEW_FIELD_MAX_BYTES' ), 'Record should cap long field values.' );
eforms_test_assert( strpos( $record['fields']['message'], "\0" ) === false, 'Record should strip control characters.' );
eforms_test_assert( ! isset( $record['fields']['eforms_token'] ), 'Record must not capture protocol tokens.' );
eforms_test_assert( ! isset( $record['fields']['timestamp'] ), 'Record must not capture protocol timestamps.' );
eforms_test_assert( $record['uploads']['resume'][0]['original_name_safe'] === 'cv.pdf', 'Record should capture safe upload name metadata.' );
eforms_test_assert( ! isset( $record['uploads']['resume'][0]['tmp_name'] ), 'Record must not capture upload temp paths.' );

// Metadata-only captures retain decision metadata without submitted values.
eforms_test_assert(
    DeclinedReviewLog::capture(
        eforms_declined_test_capture_args(
            array(
                'config' => $config,
                'decision_code' => 'EFORMS_ERR_HONEYPOT',
                'decision_phase' => 'honeypot',
                'value_stage' => 'metadata_only',
                'honeypot' => true,
            )
        )
    ) === true,
    'Metadata-only declined review should write.'
);
$records = DeclinedReviewLog::query( array( 'decision_code' => 'EFORMS_ERR_HONEYPOT' ), $config );
eforms_test_assert( $records['total'] === 1, 'Decision filter should find the honeypot record.' );
eforms_test_assert( $records['records'][0]['fields'] === array(), 'Metadata-only record should not include submitted values.' );
eforms_test_assert( ! empty( $records['records'][0]['honeypot'] ), 'Metadata-only record should preserve honeypot decision metadata.' );

// Rotated siblings, malformed-line tolerance, pagination, and deterministic detail lookup.
$today = gmdate( 'Ymd' );
$rotated_path = rtrim( $dir, '/\\' ) . '/declined-' . $today . '-1.jsonl';
$rotated = array( 'review_id' => 'rotated-record', 'ts' => gmdate( 'c' ), 'form_id' => 'contact', 'decision_code' => 'EFORMS_ERR_SPAM' );
file_put_contents( $rotated_path, json_encode( $rotated ) . "\n" );
$rotated_files = eforms_declined_test_files( $dir );
eforms_test_assert( in_array( 'declined-' . $today . '-1.jsonl', $rotated_files, true ), 'Reader should include rotated declined JSONL siblings.' );
file_put_contents( $rotated_path, "not-json\n", FILE_APPEND );

$page = DeclinedReviewLog::query( array( 'per_page' => 2, 'page' => 1 ), $config );
eforms_test_assert( count( $page['records'] ) === 2, 'Reader should honor page size.' );
eforms_test_assert( $page['total'] >= 3, 'Reader should tolerate malformed lines and keep valid records.' );

$same_id = 'same-review-id';
$same_id_path = rtrim( $dir, '/\\' ) . '/declined-' . $today . '-9999.jsonl';
$old = array( 'review_id' => $same_id, 'ts' => '2026-01-01T00:00:00+00:00', 'form_id' => 'contact', 'decision_code' => 'EFORMS_ERR_SPAM' );
$new = array( 'review_id' => $same_id, 'ts' => '2026-01-02T00:00:00+00:00', 'form_id' => 'contact', 'decision_code' => 'EFORMS_ERR_SPAM' );
file_put_contents( $same_id_path, json_encode( $old ) . "\n" . json_encode( $new ) . "\n" );
$found = DeclinedReviewLog::find( $same_id, array(), $config );
eforms_test_assert( $found['found'] === true, 'Detail lookup should find matching review_id.' );
eforms_test_assert( $found['multiple'] === true, 'Detail lookup should report duplicate review_id matches.' );
eforms_test_assert( $found['record']['ts'] === '2026-01-02T00:00:00+00:00', 'Detail lookup should return newest duplicate.' );
$missing = DeclinedReviewLog::find( 'missing-review-id', array(), $config );
eforms_test_assert( $missing['found'] === false, 'Detail lookup should report missing records normally.' );

// Scan limit reports truncation instead of reading an unbounded range.
$limit_path = rtrim( $dir, '/\\' ) . '/declined-' . $today . '-8888.jsonl';
$line = json_encode( array( 'review_id' => 'limit', 'ts' => gmdate( 'c' ), 'form_id' => 'contact', 'decision_code' => 'EFORMS_ERR_SPAM' ) ) . "\n";
$after_limit = json_encode( array( 'review_id' => 'after-limit', 'ts' => gmdate( 'c' ), 'form_id' => 'contact', 'decision_code' => 'EFORMS_ERR_SPAM' ) ) . "\n";
$payload = str_repeat( $line, Anchors::get( 'DECLINED_REVIEW_SCAN_MAX_RECORDS' ) + 1 ) . $after_limit;
file_put_contents( $limit_path, $payload );
$limited = DeclinedReviewLog::query( array(), $config );
eforms_test_assert( $limited['limited'] === true, 'Reader should report scan-limit truncation.' );
eforms_test_assert( $limited['scanned'] === Anchors::get( 'DECLINED_REVIEW_SCAN_MAX_RECORDS' ), 'Reader should not scan beyond the configured scan limit.' );
eforms_test_assert( DeclinedReviewLog::find( 'after-limit', array(), $config )['found'] === false, 'Detail lookup should also honor the scan limit.' );

// Cleanup deletes only eligible declined-review files.
$old_cleanup_path = rtrim( $dir, '/\\' ) . '/declined-20000101.jsonl';
$fresh_cleanup_path = rtrim( $dir, '/\\' ) . '/declined-' . $today . '-7777.jsonl';
$limit_control_path = rtrim( $dir, '/\\' ) . '/aaa-not-declined.jsonl';
$control_path = rtrim( $dir, '/\\' ) . '/not-declined.jsonl';
file_put_contents( $old_cleanup_path, '{"review_id":"old-cleanup"}' . "\n" );
file_put_contents( $fresh_cleanup_path, '{"review_id":"fresh-cleanup"}' . "\n" );
file_put_contents( $limit_control_path, '{"review_id":"limit-control-cleanup"}' . "\n" );
file_put_contents( $control_path, '{"review_id":"control-cleanup"}' . "\n" );
touch( $old_cleanup_path, time() - 172800 );
touch( $fresh_cleanup_path, time() );
touch( $limit_control_path, time() - 172800 );
touch( $control_path, time() - 172800 );
$zero_retention_config = $config;
$zero_retention_config['declined_review']['retention_days'] = 0;
$zero_retention = DeclinedReviewLog::prune_expired( $zero_retention_config, time() );
eforms_test_assert( $zero_retention['ok'] === false && $zero_retention['reason'] === 'invalid_days', 'Retention cleanup should reject zero-day retention.' );
eforms_test_assert( file_exists( $old_cleanup_path ) && file_exists( $fresh_cleanup_path ), 'Retention cleanup must not inherit manual zero-day clear-all semantics.' );

$invalid_clear = DeclinedReviewLog::clear_older_than( Anchors::get( 'RETENTION_DAYS_MAX' ) + 1, $config );
eforms_test_assert( $invalid_clear['ok'] === false && $invalid_clear['reason'] === 'invalid_days', 'Cleanup should reject invalid day cutoffs.' );
eforms_test_assert( file_exists( $old_cleanup_path ), 'Invalid cleanup should not delete declined files.' );

$limited_prune = DeclinedReviewLog::prune_expired( $config, time(), array( 'dry_run' => true, 'limit' => 1 ) );
eforms_test_assert( $limited_prune['scanned'] === 1 && $limited_prune['candidates'] === 0, 'Retention cleanup scan limits should count non-declined files.' );
eforms_test_assert( ! empty( $limited_prune['reached_limit'] ), 'Retention cleanup should report when the scan limit is reached.' );
eforms_test_assert( file_exists( $old_cleanup_path ), 'Limited dry-run cleanup should not delete old declined files.' );

$clear_old = DeclinedReviewLog::clear_older_than( 1, $config );
eforms_test_assert( $clear_old['deleted'] === 1 && $clear_old['failed'] === 0, 'Cleanup should delete eligible old declined files.' );
eforms_test_assert( ! file_exists( $old_cleanup_path ), 'Cleanup should delete old declined files.' );
eforms_test_assert( file_exists( $fresh_cleanup_path ), 'Cleanup should keep fresh declined files.' );
eforms_test_assert( file_exists( $limit_control_path ), 'Cleanup should keep non-declined files that consumed scan budget.' );
eforms_test_assert( file_exists( $control_path ), 'Cleanup should keep non-declined files.' );

$delete_all = DeclinedReviewLog::clear_older_than( 0, $config );
eforms_test_assert( $delete_all['deleted'] > 0, 'Zero-day cleanup should delete declined-review files.' );
eforms_test_assert( eforms_declined_test_files( $dir ) === array(), 'Zero-day cleanup should delete all declined-review files.' );
eforms_test_assert( file_exists( $control_path ), 'Zero-day cleanup should still keep non-declined files.' );

eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();
eforms_test_remove_tree( $uploads_dir );
