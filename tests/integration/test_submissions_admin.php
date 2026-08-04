<?php
/**
 * Integration tests for the retained photo submissions admin page.
 *
 * Contract: Public surfaces index
 * Contract: Managed Aggregate Contract
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/managed_upload_fixtures.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Admin/SubmissionsAdmin.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';

if ( ! function_exists( 'home_url' ) ) {
    function home_url() {
        return 'https://example.test';
    }
}

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) {
        return 'eforms-submissions-admin-' . (string) $scheme . '-salt';
    }
}

function eforms_test_submissions_admin_snapshot( $submission_id, $submitted_at ) {
    return array(
        'schema_version' => SubmissionReviewSnapshot::SCHEMA_VERSION,
        'form_id' => 'virtual-quote',
        'template_version' => 'admin-list-test',
        'submission_id' => $submission_id,
        'submitted_at' => gmdate( 'c', $submitted_at ),
        'title' => 'Submission Request',
        'header' => array(
            array(
                'key' => 'name',
                'label' => 'Name',
                'value' => 'Ada Lovelace',
                'type' => 'text',
            ),
            array(
                'key' => 'zip_us',
                'label' => 'Zip Code',
                'value' => '80202',
                'type' => 'text',
            ),
        ),
        'operator_rows' => array(
            array(
                'key' => 'email',
                'label' => 'Email',
                'value' => 'ada@example.test',
                'type' => 'email',
            ),
            array(
                'key' => 'tel_us',
                'label' => 'Phone',
                'value' => '303-555-1212',
                'type' => 'tel',
            ),
            array(
                'key' => 'project_description',
                'label' => 'Project Description',
                'value' => 'Kitchen remodel photos',
                'type' => 'text',
            ),
            array(
                'key' => 'listing_url',
                'label' => 'Listing URL',
                'value' => 'https://listing.example.test/home',
                'type' => 'url',
            ),
        ),
    );
}

function eforms_test_submissions_admin_fixture( $uploads_dir, $name, $now ) {
    $field = array(
        'type' => 'files',
        'upload_mode' => 'staged',
        'accept' => array( 'image' ),
        'max_file_bytes' => 1048576,
        'max_files' => 3,
        'max_total_bytes' => 3145728,
    );
    $binding = array(
        'raw_token' => 'admin-list-token-' . $name,
        'form_id' => 'virtual-quote',
        'instance_id' => 'admin-list-instance-' . $name,
        'field_key' => 'project_photos',
        'accept_until' => $now + 3600,
    );
    $secret = eforms_test_managed_batch_secret( substr( hash( 'sha256', $name, true ), 0, 1 ) );
    $created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
    eforms_test_assert( ! empty( $created['ok'] ), 'Submissions admin fixture should create a batch.' );

    $upload_id = 'photo_' . substr( hash( 'sha256', $name ), 0, 12 );
    $artifact = eforms_test_fixture_bytes( 'staged-landscape.png' );
    $source = eforms_test_write_file( $uploads_dir, $upload_id . '.png', $artifact );
    $put = UploadBatchStore::put_item(
        $created['batch']['batch_id'],
        $secret,
        $upload_id,
        0,
        array( 'tmp_name' => $source, 'original_name' => $name . '.png', 'size' => strlen( $artifact ), 'error' => UPLOAD_ERR_OK ),
        $uploads_dir,
        array(
            'now' => $now,
            'free_bytes' => Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) + 1073741824,
        )
    );
    eforms_test_assert( ! empty( $put['ok'] ), 'Submissions admin fixture should commit an image.' );

    $submission_id = eforms_test_uuid( 'submission-' . $name );
    $resolved = UploadBatchStore::resolve_open( $created['batch']['batch_id'], $secret, $binding, $field, $uploads_dir, $now + 1 );
    $claimed = UploadBatchStore::claim_finalization( $created['batch']['batch_id'], $secret, $binding, $field, $resolved['items'], $submission_id, $uploads_dir, $now + 2 );
    eforms_test_assert( ! empty( $claimed['ok'] ), 'Submissions admin fixture should claim finalization.' );
    $finalized = UploadBatchStore::finalize( $created['batch']['batch_id'], $submission_id, $uploads_dir, $now + 3, eforms_test_submissions_admin_snapshot( $submission_id, $now + 3 ) );
    eforms_test_assert( ! empty( $finalized['ok'] ), 'Submissions admin fixture should finalize.' );

    $path = $uploads_dir . '/eforms-private/submissions/' . Helpers::h2( $submission_id ) . '/' . $submission_id;
    return array(
        'submission_id' => $submission_id,
        'root_path' => $uploads_dir . '/eforms-private/submissions',
        'shard_path' => dirname( $path ),
        'submission_path' => $path,
        'lock_path' => $path . '/' . UploadBatchStore::LOCK_FILENAME,
        'manifest_path' => $path . '/' . UploadBatchStore::MANIFEST_FILENAME,
        'review_snapshot_path' => $path . '/' . UploadBatchStore::REVIEW_SNAPSHOT_FILENAME,
    );
}

function eforms_test_submissions_admin_worker_fixture( $uploads_dir, $name, $now ) {
    $field = array(
        'type' => 'files',
        'upload_mode' => 'staged',
        'accept' => array( 'image' ),
        'max_file_bytes' => 1048576,
        'max_files' => 3,
        'max_total_bytes' => 3145728,
    );
    $binding = array(
        'raw_token' => 'admin-list-worker-token-' . $name,
        'form_id' => 'virtual-quote',
        'instance_id' => 'admin-list-worker-instance-' . $name,
        'field_key' => 'project_photos',
        'accept_until' => $now + 3600,
    );
    $secret = eforms_test_managed_batch_secret( substr( hash( 'sha256', 'worker-' . $name, true ), 0, 1 ) );
    $identity = str_repeat( 'b', 64 );
    $created = UploadBatchStore::create_batch(
        $binding,
        $secret,
        $field,
        $uploads_dir,
        $now,
        FormProtocol::UPLOAD_TRANSPORT_WORKER,
        $identity
    );
    eforms_test_assert( ! empty( $created['ok'] ), 'Submissions admin Worker fixture should create a schema-7 batch.' );

    $batch_id = $created['batch']['batch_id'];
    $upload_id = 'worker_photo_' . substr( hash( 'sha256', $name ), 0, 12 );
    $manifest_path = $uploads_dir . '/eforms-private/' . UploadBatchStore::STAGED_DIR . '/' . Helpers::h2( $batch_id ) . '/' . $batch_id . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $authorized = UploadBatchStore::worker_authorize_intent(
        $batch_id,
        $secret,
        $upload_id,
        0,
        $name . '.png',
        1000,
        'image/png',
        $uploads_dir,
        array(
            'now' => $now + 1,
            'storage_identity' => $identity,
            'validation_contract_version' => WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION,
            'upload_until' => $now + 120,
            'accept_until' => $binding['accept_until'],
            'validation_until' => $binding['accept_until'] + 120,
            'staged_delete_after' => $binding['accept_until'] + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' ),
        )
    );
    eforms_test_assert( ! empty( $authorized['ok'] ), 'Submissions admin Worker fixture should authorize an item.' );
    $manifest = json_decode( file_get_contents( $manifest_path ), true );
    $completed = UploadBatchStore::worker_complete_stored_receipt(
        $batch_id,
        $secret,
        $upload_id,
        eforms_test_worker_stored_receipt( $manifest, $upload_id, 'admin-worker-version', 'admin-worker-etag' ),
        $uploads_dir,
        $now + 2
    );
    eforms_test_assert( ! empty( $completed['ok'] ), 'Submissions admin Worker fixture should complete a stored receipt.' );

    $submission_id = eforms_test_uuid( 'submission-worker-' . $name );
    $claimed = UploadBatchStore::worker_claim_finalization(
        $batch_id,
        $secret,
        $binding,
        $field,
        array( UploadValue::review_staged_item( $completed['item'] ) ),
        $submission_id,
        $uploads_dir,
        $now + 3
    );
    eforms_test_assert( ! empty( $claimed['ok'] ), 'Submissions admin Worker fixture should claim finalization.' );
    $finalized = UploadBatchStore::worker_finalize( $batch_id, $submission_id, $uploads_dir, $now + 4 );
    eforms_test_assert( ! empty( $finalized['ok'] ), 'Submissions admin Worker fixture should finalize schema-7 submission storage.' );
    $snapshot = UploadBatchStore::store_review_snapshot(
        $submission_id,
        $uploads_dir,
        eforms_test_submissions_admin_snapshot( $submission_id, $now + 4 ),
        FormProtocol::UPLOAD_TRANSPORT_WORKER
    );
    eforms_test_assert( ! empty( $snapshot['ok'] ), 'Submissions admin Worker fixture should persist the schema-7 review sidecar.' );

    return $finalized['submission'];
}

function eforms_test_submissions_admin_empty_fixture( $uploads_dir, $name, $now ) {
    $field = array(
        'type' => 'files',
        'upload_mode' => 'staged',
        'accept' => array( 'image' ),
        'max_file_bytes' => 1048576,
        'max_files' => 3,
        'max_total_bytes' => 3145728,
    );
    $binding = array(
        'raw_token' => 'admin-list-token-' . $name,
        'form_id' => 'virtual-quote',
        'instance_id' => 'admin-list-instance-' . $name,
        'field_key' => 'project_photos',
        'accept_until' => $now + 3600,
    );
    $secret = eforms_test_managed_batch_secret( substr( hash( 'sha256', $name, true ), 0, 1 ) );
    $created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
    eforms_test_assert( ! empty( $created['ok'] ), 'Submissions admin empty fixture should create a batch.' );

    $submission_id = eforms_test_uuid( 'submission-' . $name );
    $resolved = UploadBatchStore::resolve_open( $created['batch']['batch_id'], $secret, $binding, $field, $uploads_dir, $now + 1 );
    eforms_test_assert( ! empty( $resolved['ok'] ) && $resolved['items'] === array(), 'Submissions admin empty fixture should resolve no retained photos.' );
    $claimed = UploadBatchStore::claim_finalization( $created['batch']['batch_id'], $secret, $binding, $field, $resolved['items'], $submission_id, $uploads_dir, $now + 2 );
    eforms_test_assert( ! empty( $claimed['ok'] ), 'Submissions admin empty fixture should claim finalization.' );
    $finalized = UploadBatchStore::finalize( $created['batch']['batch_id'], $submission_id, $uploads_dir, $now + 3, eforms_test_submissions_admin_snapshot( $submission_id, $now + 3 ) );
    eforms_test_assert( ! empty( $finalized['ok'] ), 'Submissions admin empty fixture should finalize.' );

    return array( 'submission_id' => $submission_id );
}

function eforms_test_submissions_admin_order_key( $name ) {
    $submission_id = eforms_test_uuid( 'submission-' . $name );
    return Helpers::h2( $submission_id ) . '/' . $submission_id;
}

function eforms_test_submissions_admin_ordered_names() {
    for ( $i = 0; $i < 256; ++$i ) {
        for ( $j = 0; $j < 256; ++$j ) {
            $first = 'invalid-first-' . $i;
            $second = 'retained-second-' . $j;
            if ( strcmp( eforms_test_submissions_admin_order_key( $first ), eforms_test_submissions_admin_order_key( $second ) ) < 0 ) {
                return array( 'first' => $first, 'second' => $second );
            }
        }
    }
    return array( 'first' => 'invalid-first', 'second' => 'retained-second' );
}

function eforms_test_submissions_admin_scan_cap_names( $count ) {
    $names = array();
    for ( $i = 0; $i <= $count; ++$i ) {
        $name = 'scan-cap-' . $i;
        $names[] = array(
            'name' => $name,
            'key' => eforms_test_submissions_admin_order_key( $name ),
        );
    }
    usort(
        $names,
        function ( $left, $right ) {
            return strcmp( $left['key'], $right['key'] );
        }
    );
    return array(
        'invalid' => array_column( array_slice( $names, 0, $count ), 'name' ),
        'valid' => $names[ $count ]['name'],
    );
}

$empty_uploads_dir = eforms_test_setup_uploads( 'eforms-submissions-admin-empty' );
$GLOBALS['eforms_test_can_manage'] = true;
$empty_html = SubmissionsAdmin::render_html( array(), array( 'uploads' => array( 'dir' => $empty_uploads_dir ) ), 1700000000 );
eforms_test_assert( strpos( $empty_html, 'No retained photo submissions found.' ) !== false, 'Submissions admin should render its empty state.' );

$uploads_dir = eforms_test_setup_uploads( 'eforms-submissions-admin' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $uploads_dir ) {
        $config['uploads']['dir'] = $uploads_dir;
        return $config;
    }
);
Config::reset_for_tests();
Logging::reset_for_tests();
$now = 1700000000;
$ordered_names = eforms_test_submissions_admin_ordered_names();
$corrupt = eforms_test_submissions_admin_fixture( $uploads_dir, $ordered_names['first'], $now + 20 );
$retained = eforms_test_submissions_admin_fixture( $uploads_dir, $ordered_names['second'], $now );
$empty_submission = eforms_test_submissions_admin_empty_fixture( $uploads_dir, 'empty-listing-only', $now + 30 );
file_put_contents( $corrupt['review_snapshot_path'], json_encode( eforms_test_submissions_admin_snapshot( $retained['submission_id'], $now ) ) );
chmod( $corrupt['review_snapshot_path'], 0600 );

$GLOBALS['eforms_test_can_manage'] = false;
$denied_html = SubmissionsAdmin::render_html( array(), Config::get(), $now + 40 );
eforms_test_assert( $denied_html === '', 'Submissions admin should not render rows for non-capable users.' );

$GLOBALS['eforms_test_can_manage'] = true;
foreach ( array( $retained['root_path'], $retained['shard_path'], $retained['submission_path'] ) as $legacy_directory ) {
    chmod( $legacy_directory, PrivateDir::DIRECTORY_MODE );
}
foreach ( array( $retained['lock_path'], $retained['manifest_path'], $retained['review_snapshot_path'] ) as $legacy_file ) {
    chmod( $legacy_file, PrivateDir::FILE_MODE );
}
$limited = UploadBatchStore::retained_photo_submissions( $uploads_dir, $now + 40, 1 );
eforms_test_assert(
    ! empty( $limited['ok'] )
        && count( $limited['submissions'] ) === 1
        && $limited['submissions'][0]['submission_id'] === $retained['submission_id']
        && $limited['scanned'] > 1
        && $limited['scanned'] <= Anchors::get( 'RETAINED_SUBMISSIONS_SCAN_PAGE_SIZE' )
        && $limited['reached_limit'] === false
        && $limited['cursor'] === array(),
    'Retained listing should scan past an earlier mismatched sidecar without advertising an empty next page.'
);
clearstatcache();
foreach ( array( $retained['root_path'], $retained['shard_path'], $retained['submission_path'] ) as $migrated_directory ) {
    eforms_test_assert( ( fileperms( $migrated_directory ) & 0777 ) === PrivateDir::REVIEW_DIRECTORY_MODE, 'Retained listing should migrate known submission directories to trusted-operator readability.' );
}
eforms_test_assert(
    ( fileperms( $retained['lock_path'] ) & 0777 ) === PrivateDir::FILE_MODE
        && ( fileperms( $retained['manifest_path'] ) & 0777 ) === PrivateDir::FILE_MODE,
    'Retained listing should keep aggregate control files owner-private.'
);
eforms_test_assert( ( fileperms( $retained['review_snapshot_path'] ) & 0777 ) === PrivateDir::REVIEW_FILE_MODE, 'Retained listing should migrate the operator review snapshot to trusted-group readability.' );
$html = SubmissionsAdmin::render_html( array(), Config::get(), $now + 40 );
eforms_test_assert( strpos( $html, '<h1>eForms Submissions</h1>' ) !== false, 'Submissions admin should render its page title.' );
eforms_test_assert( strpos( $html, 'Ada Lovelace / 80202' ) !== false, 'Submissions admin should show name and ZIP summary.' );
eforms_test_assert( strpos( $html, 'Kitchen remodel photos' ) !== false, 'Submissions admin should show the compact project summary.' );
eforms_test_assert( strpos( $html, '1 photo' ) !== false, 'Submissions admin should show the photo count.' );
eforms_test_assert( strpos( $html, 'View / Manage' ) !== false && preg_match( '#href="https://example\.test/review/[A-Za-z0-9_-]{44}"#', $html ) === 1, 'Submissions admin should link to the compact opaque review detail URL.' );
eforms_test_assert( strpos( $html, $corrupt['submission_id'] ) === false, 'Submissions admin should omit mismatched review sidecars.' );
eforms_test_assert( strpos( $html, $empty_submission['submission_id'] ) === false, 'Submissions admin should omit finalized aggregates with no retained photos.' );
eforms_test_assert( strpos( $html, 'ada@example.test' ) === false, 'Submissions admin list should keep email on the detail page.' );
eforms_test_assert( strpos( $html, '303-555-1212' ) === false, 'Submissions admin list should keep phone on the detail page.' );
eforms_test_assert( strpos( $html, 'listing.example.test' ) === false, 'Submissions admin list should keep listing URL off the list.' );
eforms_test_assert( strpos( $html, 'manifest.json' ) === false && strpos( $html, 'review.json' ) === false && strpos( $html, 'eforms-private' ) === false, 'Submissions admin list should not expose storage paths.' );
eforms_test_assert( strpos( $html, 'artifact_store' ) === false && strpos( $html, 'object_key' ) === false, 'Submissions admin list should not expose provider or object identity fields.' );

$worker_listing_uploads_dir = eforms_test_setup_uploads( 'eforms-submissions-admin-worker' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $worker_listing_uploads_dir ) {
        $config['uploads']['dir'] = $worker_listing_uploads_dir;
        return $config;
    }
);
Config::reset_for_tests();
$worker_retained = eforms_test_submissions_admin_worker_fixture( $worker_listing_uploads_dir, 'worker-retained', $now + 80 );
$worker_limited = UploadBatchStore::retained_photo_submissions( $worker_listing_uploads_dir, $now + 100, 5 );
eforms_test_assert(
    ! empty( $worker_limited['ok'] )
        && count( $worker_limited['submissions'] ) === 1
        && $worker_limited['submissions'][0]['submission_id'] === $worker_retained['submission_id']
        && $worker_limited['submissions'][0]['photo_count'] === 1,
    'Retained listing should include finalized schema-7 Worker submissions.'
);
$worker_html = SubmissionsAdmin::render_html( array(), Config::get(), $now + 100 );
eforms_test_assert( strpos( $worker_html, 'Kitchen remodel photos' ) !== false && strpos( $worker_html, '1 photo' ) !== false, 'Submissions admin should render schema-7 Worker rows through the normal listing.' );
eforms_test_assert( strpos( $worker_html, 'object_key' ) === false && strpos( $worker_html, 'admin-worker-version' ) === false, 'Submissions admin Worker rows should not expose provider locators or versions.' );

$scan_cap_uploads_dir = eforms_test_setup_uploads( 'eforms-submissions-admin-scan-cap' );
eforms_test_set_filter(
    'eforms_config',
    function ( $config ) use ( $scan_cap_uploads_dir ) {
        $config['uploads']['dir'] = $scan_cap_uploads_dir;
        return $config;
    }
);
Config::reset_for_tests();
Logging::reset_for_tests();
$scan_cap = Anchors::get( 'RETAINED_SUBMISSIONS_SCAN_PAGE_SIZE' );
$scan_names = eforms_test_submissions_admin_scan_cap_names( $scan_cap );
foreach ( $scan_names['invalid'] as $index => $name ) {
    $invalid = eforms_test_submissions_admin_fixture( $scan_cap_uploads_dir, $name, $now + 100 + $index );
    file_put_contents( $invalid['review_snapshot_path'], '{"schema_version":999}' );
    chmod( $invalid['review_snapshot_path'], 0600 );
}
$scan_valid = eforms_test_submissions_admin_fixture( $scan_cap_uploads_dir, $scan_names['valid'], $now + 300 );
$scan_limited = UploadBatchStore::retained_photo_submissions( $scan_cap_uploads_dir, $now + 400, 1 );
eforms_test_assert(
    ! empty( $scan_limited['ok'] )
        && $scan_limited['submissions'] === array()
        && $scan_limited['scanned'] === $scan_cap
        && $scan_limited['reached_limit'] === true
        && isset( $scan_limited['cursor']['shard'], $scan_limited['cursor']['aggregate'] ),
    'Retained listing should expose a cursor when invalid sidecars exhaust the scan cap before a valid row.'
);
$scan_next = UploadBatchStore::retained_photo_submissions( $scan_cap_uploads_dir, $now + 400, 1, $scan_limited['cursor'] );
eforms_test_assert(
    ! empty( $scan_next['ok'] )
        && count( $scan_next['submissions'] ) === 1
        && $scan_next['submissions'][0]['submission_id'] === $scan_valid['submission_id'],
    'Retained listing should continue after a scan-cap cursor and reach later valid rows.'
);

$scan_page_one = SubmissionsAdmin::render_html( array(), Config::get(), $now + 400 );
eforms_test_assert( strpos( $scan_page_one, 'next-page button' ) !== false, 'Submissions admin should render its forward cursor with the WordPress admin list-table control shape.' );
eforms_test_assert( strpos( $scan_page_one, 'page-numbers' ) === false, 'Submissions admin should not use front-end page-number markup.' );
preg_match( '/<a class="next-page button" href="([^"]+)">/', $scan_page_one, $scan_next_match );
eforms_test_assert( ! empty( $scan_next_match[1] ), 'Submissions admin should expose a next-page URL when its bounded scan has more work.' );
$scan_next_query = array();
parse_str( (string) parse_url( html_entity_decode( $scan_next_match[1], ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ), $scan_next_query );
$scan_page_two = SubmissionsAdmin::render_html( $scan_next_query, Config::get(), $now + 400 );
eforms_test_assert( strpos( $scan_page_two, 'View / Manage' ) !== false && strpos( $scan_page_two, '/review/' ) !== false, 'Submissions admin next-page URL should render the later retained submission without exposing its identifier.' );
eforms_test_assert( strpos( $scan_page_two, 'first-page button' ) !== false, 'Submissions admin should keep a WordPress-styled first-page control on the final cursor page.' );
eforms_test_assert( strpos( $scan_page_two, 'next-page button' ) === false, 'Submissions admin should not render an active next-page link after the cursor is exhausted.' );

echo "Submissions admin tests passed.\n";
