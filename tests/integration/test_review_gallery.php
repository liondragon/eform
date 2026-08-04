<?php
/**
 * Integration tests for signed finalized-gallery and member access.
 *
 * Contract: Managed review access
 * Contract: Signed gallery and file routes
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/managed_upload_fixtures.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Submission/PublicRequestController.php';
require_once __DIR__ . '/../../src/Uploads/ReviewController.php';
require_once __DIR__ . '/../../src/Uploads/UploadBatchStore.php';

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src, $deps = array(), $ver = false ) {
        if ( ! isset( $GLOBALS['eforms_test_styles'] ) ) {
            $GLOBALS['eforms_test_styles'] = array();
        }
        $GLOBALS['eforms_test_styles'][] = array(
            'handle' => $handle,
            'src' => $src,
            'deps' => $deps,
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
            'in_footer' => $in_footer,
        );
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return $path;
    }
}

function eforms_test_review_request( $url, $uploads_dir, $salt, $now, $overrides = array() ) {
    return ReviewController::dispatch_current_request(
        array(
            'method' => isset( $overrides['method'] ) && is_string( $overrides['method'] ) ? $overrides['method'] : 'GET',
            'uri' => $url,
        ),
        array_merge(
            array(
                'uploads_dir' => $uploads_dir,
                'salt' => $salt,
                'now' => $now,
                'base_url' => 'https://example.test',
            ),
            is_array( $overrides ) ? $overrides : array()
        )
    );
}

function eforms_test_review_snapshot( $submission_id, $submitted_at, $listing_url = '' ) {
    $operator_rows = array(
        array( 'key' => 'email', 'label' => 'Email', 'value' => 'ada@example.test', 'type' => 'email' ),
        array( 'key' => 'tel_us', 'label' => 'Phone', 'value' => '720-900-5278', 'type' => 'tel' ),
        array( 'key' => 'project_description', 'label' => 'Project Description', 'value' => 'Refinish the main floor.', 'type' => 'text' ),
        array( 'key' => 'square_footage', 'label' => 'Square Footage', 'value' => '1145', 'type' => 'text' ),
    );
    if ( $listing_url !== '' ) {
        $operator_rows[] = array( 'key' => 'listing_url', 'label' => 'Listing URL', 'value' => $listing_url, 'type' => 'url' );
    }
    return array(
        'schema_version' => SubmissionReviewSnapshot::SCHEMA_VERSION,
        'form_id' => 'virtual-estimate',
        'template_version' => 'review-test',
        'submission_id' => $submission_id,
        'submitted_at' => gmdate( 'c', $submitted_at ),
        'title' => 'Virtual Estimate Request',
        'header' => array(
            array( 'key' => 'name', 'label' => 'Name', 'value' => 'Ada Lovelace', 'type' => 'text' ),
            array( 'key' => 'zip_us', 'label' => 'Zip Code', 'value' => '80231', 'type' => 'text' ),
        ),
        'operator_rows' => $operator_rows,
    );
}

function eforms_test_review_unavailable_shape( $response ) {
    return array(
        'status' => isset( $response['status'] ) ? $response['status'] : null,
        'body' => isset( $response['body'] ) ? $response['body'] : null,
        'headers' => isset( $response['headers'] ) ? $response['headers'] : null,
    );
}

function eforms_test_render_review_template( $page ) {
    $review_page_property = new ReflectionProperty( 'PublicRequestController', 'review_page' );
    $review_page_property->setAccessible( true );
    $review_page_property->setValue( null, $page );
    ob_start();
    require __DIR__ . '/../../templates/pages/review-gallery.php';
    $html = ob_get_clean();
    PublicRequestController::reset_for_tests();
    return $html;
}

function eforms_test_review_worker_submission( $uploads_dir, $secret, $field, $binding, $submission_id, $artifact_store_identity, $now, $item_count = 3 ) {
    $created = UploadBatchStore::create_batch(
        $binding,
        $secret,
        $field,
        $uploads_dir,
        $now,
        FormProtocol::UPLOAD_TRANSPORT_WORKER,
        $artifact_store_identity
    );
    eforms_test_assert( ! empty( $created['ok'] ), 'The candidate review fixture should create a schema-7 Worker batch.' );

    $batch_id = $created['batch']['batch_id'];
    $manifest_path = $uploads_dir . '/eforms-private/' . UploadBatchStore::STAGED_DIR . '/' . Helpers::h2( $batch_id ) . '/' . $batch_id . '/' . UploadBatchStore::MANIFEST_FILENAME;
    $items = array();
    $accept_until = $binding['accept_until'];
    $staged_delete_after = $accept_until + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' );
    $item_count = is_int( $item_count ) ? $item_count : 3;
    for ( $index = 0; $index < $item_count; $index++ ) {
        $upload_id = 'worker_photo_' . $index;
        $authorized = UploadBatchStore::worker_authorize_intent(
            $batch_id,
            $secret,
            $upload_id,
            $index,
            'Customer Worker ' . $index . '.png',
            1000 + $index,
            'image/png',
            $uploads_dir,
            array(
                'now' => $now + 1 + $index,
                'storage_identity' => $artifact_store_identity,
                'validation_contract_version' => 'validation-v1',
                'upload_until' => $now + 120,
                'accept_until' => $accept_until,
                'validation_until' => $accept_until + 120,
                'staged_delete_after' => $staged_delete_after,
            )
        );
        eforms_test_assert( ! empty( $authorized['ok'] ), 'The candidate review fixture should authorize item ' . $upload_id . '.' );
        $manifest = json_decode( file_get_contents( $manifest_path ), true );
        $receipt = eforms_test_worker_stored_receipt( $manifest, $upload_id, 'worker-version-' . $index, 'worker-etag-' . $index );
        $completed = UploadBatchStore::worker_complete_stored_receipt(
            $batch_id,
            $secret,
            $upload_id,
            $receipt,
            $uploads_dir,
            $now + 10 + $index
        );
        eforms_test_assert( ! empty( $completed['ok'] ), 'The candidate review fixture should complete item ' . $upload_id . '.' );
        $items[] = UploadValue::review_staged_item( $completed['item'] );
    }

    $claimed = UploadBatchStore::worker_claim_finalization(
        $batch_id,
        $secret,
        $binding,
        $field,
        $items,
        $submission_id,
        $uploads_dir,
        $now + 20
    );
    eforms_test_assert( ! empty( $claimed['ok'] ), 'The candidate review fixture should claim finalization.' );
    $finalized = UploadBatchStore::worker_finalize( $batch_id, $submission_id, $uploads_dir, $now + 21 );
    eforms_test_assert( ! empty( $finalized['ok'] ), 'The candidate review fixture should finalize schema-7 Worker submission.' );
    return $finalized['submission'];
}

$now = 1700000000;
$salt = 'review-test-auth-salt';
$uploads_dir = eforms_test_setup_uploads( 'eforms-review-gallery' );
$field = array(
    'key' => 'photos',
    'type' => 'files',
    'accept' => array( 'image' ),
    'upload_mode' => 'staged',
    'max_file_bytes' => 1048576,
    'max_files' => 3,
    'max_total_bytes' => 3145728,
);
$secret = eforms_test_managed_batch_secret( "\x61" );
$binding = array(
    'raw_token' => 'review-form-token',
    'form_id' => 'virtual-estimate',
    'instance_id' => 'review-instance',
    'field_key' => 'photos',
    'accept_until' => $now + 3600,
);
$created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert( ! empty( $created['ok'] ), 'The review fixture should create a staged aggregate.' );

$artifact = eforms_test_fixture_bytes( 'staged-landscape.png' );
$source = eforms_test_write_file( $uploads_dir, 'review-source.png', $artifact );
$put = UploadBatchStore::put_item(
    $created['batch']['batch_id'],
    $secret,
    'review_photo',
    0,
    array(
        'tmp_name' => $source,
        'original_name' => 'Customer <Photo>.png',
        'size' => filesize( $source ),
        'error' => UPLOAD_ERR_OK,
    ),
    $uploads_dir,
    array( 'now' => $now + 5 )
);
eforms_test_assert( ! empty( $put['ok'] ), 'The review fixture should commit a real authoritative image: ' . json_encode( $put ) );
$submission_id = '123e4567-e89b-12d3-a456-426614174000';
$resolved = UploadBatchStore::resolve_open( $created['batch']['batch_id'], $secret, $binding, $field, $uploads_dir, $now + 10 );
eforms_test_assert( ! empty( UploadBatchStore::claim_finalization( $created['batch']['batch_id'], $secret, $binding, $field, $resolved['items'], $submission_id, $uploads_dir, $now + 10 )['ok'] ), 'The review fixture should freeze its aggregate.' );
$finalized = UploadBatchStore::finalize( $created['batch']['batch_id'], $submission_id, $uploads_dir, $now + 20 );
eforms_test_assert( ! empty( $finalized['ok'] ), 'The review fixture should finalize its aggregate.' );
$delete_after = $finalized['submission']['delete_after'];
$private_dir = PrivateDir::path( $uploads_dir );
file_put_contents( $private_dir . '/' . PrivateDir::PURGE_MARKER_FILENAME, "purged\n" );
$blocked_submission = UploadBatchStore::submission( $submission_id, $uploads_dir, $now + 20 );
eforms_test_assert( empty( $blocked_submission['ok'] ) && $blocked_submission['reason'] === 'managed_purged', 'Finalized aggregate reads should stop at the uninstall purge barrier.' );
eforms_test_assert( PrivateDir::resume_after_install( $uploads_dir ) === true, 'The review fixture should model activation before serving the gallery.' );
$missing_snapshot = UploadBatchStore::review_snapshot( $submission_id, $uploads_dir );
$available_without_snapshot = UploadBatchStore::submission( $submission_id, $uploads_dir, $now + 20 );
eforms_test_assert( empty( $missing_snapshot['ok'] ) && ! empty( $available_without_snapshot['ok'] ), 'Missing review snapshots should fail closed for lead details while preserving manifest-backed gallery reads.' );
$stored_snapshot = UploadBatchStore::store_review_snapshot( $submission_id, $uploads_dir, eforms_test_review_snapshot( $submission_id, $now + 20, 'https://example.test/listing' ) );
eforms_test_assert( ! empty( $stored_snapshot['ok'] ), 'The review fixture should attach a valid operator snapshot sidecar after proving the missing-sidecar path.' );
$snapshot_path = $private_dir . '/' . UploadBatchStore::SUBMISSIONS_DIR . '/' . Helpers::h2( $submission_id ) . '/' . $submission_id . '/' . UploadBatchStore::REVIEW_SNAPSHOT_FILENAME;
$mismatched_snapshot = eforms_test_review_snapshot( '123e4567-e89b-12d3-a456-426614174099', $now + 20, 'https://example.test/listing' );
file_put_contents( $snapshot_path, json_encode( $mismatched_snapshot ) );
chmod( $snapshot_path, 0600 );
$mismatched_read = UploadBatchStore::review_snapshot( $submission_id, $uploads_dir );
$GLOBALS['eforms_test_can_manage'] = true;
$mismatched_gallery = eforms_test_review_request( ReviewController::gallery_url( $submission_id, 'https://example.test', $salt ), $uploads_dir, $salt, $now + 25 );
$GLOBALS['eforms_test_can_manage'] = false;
eforms_test_assert(
    empty( $mismatched_read['ok'] )
        && $mismatched_gallery['status'] === 200
        && ! isset( $mismatched_gallery['review_page']['review_facts'] ),
    'Mismatched review snapshot sidecars should fail closed for direct reads and operator gallery lead details.'
);
$stored_snapshot = UploadBatchStore::store_review_snapshot( $submission_id, $uploads_dir, eforms_test_review_snapshot( $submission_id, $now + 20, 'https://example.test/listing' ) );
eforms_test_assert( ! empty( $stored_snapshot['ok'] ), 'The review fixture should restore the valid operator snapshot sidecar.' );
$wrong_form_snapshot = eforms_test_review_snapshot( $submission_id, $now + 20, 'https://example.test/listing' );
$wrong_form_snapshot['form_id'] = 'wrong-form';
$wrong_form_write = UploadBatchStore::store_review_snapshot( $submission_id, $uploads_dir, $wrong_form_snapshot );
$still_valid_snapshot = UploadBatchStore::review_snapshot( $submission_id, $uploads_dir );
eforms_test_assert(
    empty( $wrong_form_write['ok'] )
        && ! empty( $still_valid_snapshot['ok'] )
        && $still_valid_snapshot['snapshot']['form_id'] === 'virtual-estimate',
    'Review snapshot writes should reject sidecars whose form identity does not match the finalized manifest.'
);

$email_reference = ReviewController::email_gallery_reference(
    $submission_id,
    array( 'review_photo' ),
    $uploads_dir,
    'https://example.test',
    $salt,
    $now + 30
);
eforms_test_assert(
    ! empty( $email_reference['ok'] )
        && $email_reference['count'] === 1
        && $email_reference['available_label'] === gmdate( 'F j, Y \a\t g:i a', $delete_after )
        && preg_match( '#^https://example\.test/review/[A-Za-z0-9_-]{44}$#', $email_reference['url'] ) === 1
        && strpos( $email_reference['url'], 'expires' . '=' ) === false,
    'The review owner should return a validated, signed email gallery reference.'
);
$mismatched_email_reference = ReviewController::email_gallery_reference(
    $submission_id,
    array( 'different_photo' ),
    $uploads_dir,
    'https://example.test',
    $salt,
    $now + 30
);
eforms_test_assert( empty( $mismatched_email_reference['ok'] ), 'Email gallery references should fail closed when staged item identities differ.' );
$legacy_submission_path = $private_dir . '/' . UploadBatchStore::SUBMISSIONS_DIR . '/' . Helpers::h2( $submission_id ) . '/' . $submission_id;
$legacy_manifest_path = $legacy_submission_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
$legacy_manifest = json_decode( file_get_contents( $legacy_manifest_path ), true );
$legacy_item = $legacy_manifest['items']['review_photo'];
$legacy_artifact_path = LocalArtifactStore::locate( $uploads_dir, $legacy_item['object_key'], $legacy_item['object_version'] );
$direct_locate_purge_lease = PrivateDir::acquire_purge_lease( $uploads_dir );
eforms_test_assert( $direct_locate_purge_lease instanceof PrivateDirLease, 'Direct artifact lookup should release its internally owned shared lifecycle lease.' );
$direct_locate_purge_lease->release();
$legacy_submission_directories = array( dirname( $legacy_submission_path ), $legacy_submission_path );
$legacy_submission_control_files = array( $legacy_manifest_path, $legacy_submission_path . '/' . UploadBatchStore::LOCK_FILENAME );
$legacy_submission_review_files = array( $legacy_submission_path . '/' . UploadBatchStore::REVIEW_SNAPSHOT_FILENAME );
$legacy_artifact_root = $private_dir . '/' . LocalArtifactStore::ROOT_DIR;
$legacy_artifact_directories = array();
$legacy_artifact_cursor = dirname( $legacy_artifact_path );
while ( $legacy_artifact_cursor !== $legacy_artifact_root ) {
    array_unshift( $legacy_artifact_directories, $legacy_artifact_cursor );
    $legacy_artifact_cursor = dirname( $legacy_artifact_cursor );
}
foreach ( array_merge( $legacy_submission_control_files, $legacy_submission_review_files ) as $legacy_file ) {
    chmod( $legacy_file, PrivateDir::FILE_MODE );
}
foreach ( array_merge( $legacy_submission_directories, $legacy_artifact_directories ) as $legacy_directory ) {
    chmod( $legacy_directory, PrivateDir::DIRECTORY_MODE );
}
chmod( $private_dir, PrivateDir::DIRECTORY_MODE );
chmod( dirname( $legacy_artifact_path ) . '/' . LocalArtifactStore::LOCK_FILENAME, PrivateDir::FILE_MODE );
chmod( $legacy_artifact_path, PrivateDir::FILE_MODE );
$gallery_url = ReviewController::gallery_url( $submission_id, 'https://example.test', $salt );
eforms_test_assert( preg_match( '#^https://example\.test/review/[A-Za-z0-9_-]{44}$#', $gallery_url ) === 1, 'Gallery URL generation should use one compact opaque bearer segment.' );
$GLOBALS['eforms_test_styles'] = array();
$GLOBALS['eforms_test_scripts'] = array();
$gallery = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30 );
eforms_test_assert( $gallery['status'] === 200 && $gallery['render'] === 'review_gallery', 'A valid gallery bearer grant should render the review page.' );
$gallery_purge_lease = PrivateDir::acquire_purge_lease( $uploads_dir );
eforms_test_assert( $gallery_purge_lease instanceof PrivateDirLease, 'Review reads should release their shared lifecycle lease after permission migration.' );
$gallery_purge_lease->release();
eforms_test_assert( ( fileperms( $private_dir ) & 0777 ) === PrivateDir::REVIEW_DIRECTORY_MODE, 'An existing private root should migrate to trusted-group traversal when resolved for review.' );
foreach ( $legacy_submission_directories as $legacy_directory ) {
    eforms_test_assert( ( fileperms( $legacy_directory ) & 0777 ) === PrivateDir::REVIEW_DIRECTORY_MODE, 'An existing managed submission path should migrate to group traversal when locked for review.' );
}
foreach ( $legacy_submission_control_files as $legacy_file ) {
    eforms_test_assert( ( fileperms( $legacy_file ) & 0777 ) === PrivateDir::FILE_MODE, 'Managed submission control files should remain owner-private during review.' );
}
foreach ( $legacy_submission_review_files as $legacy_file ) {
    eforms_test_assert( ( fileperms( $legacy_file ) & 0777 ) === PrivateDir::REVIEW_FILE_MODE, 'The operator review snapshot should migrate to trusted-group readability.' );
}
eforms_test_assert(
    array_column( $GLOBALS['eforms_test_styles'], 'handle' ) === array( 'eforms', 'eforms-review-gallery' )
        && $GLOBALS['eforms_test_styles'][1]['deps'] === array( 'eforms' ),
    'A valid gallery bearer grant should enqueue canonical core and review styles.'
);
eforms_test_assert( count( $GLOBALS['eforms_test_scripts'] ) === 1 && $GLOBALS['eforms_test_scripts'][0]['handle'] === 'eforms-review-gallery' && $GLOBALS['eforms_test_scripts'][0]['in_footer'] === true, 'A valid gallery should enqueue only its review-gallery browser runtime in the footer.' );
eforms_test_assert( $gallery['headers']['Cache-Control'] === 'private, no-store, max-age=0', 'Gallery responses should be private and non-cacheable.' );
eforms_test_assert( $gallery['headers']['X-Robots-Tag'] === 'noindex, nofollow' && $gallery['headers']['X-Content-Type-Options'] === 'nosniff', 'Gallery responses should block indexing and sniffing.' );
eforms_test_assert( $gallery['headers']['Referrer-Policy'] === 'no-referrer', 'Gallery responses should not leak bearer URLs through referrers.' );
eforms_test_assert(
    $gallery['review_page']['submission_id'] === $submission_id
        && ! isset( $gallery['review_page']['count'] )
        && empty( $gallery['review_page']['can_delete'] )
        && ! isset( $gallery['review_page']['operator_action_url'] )
        && isset( $gallery['review_page']['review_facts']['groups'][0]['rows'] )
        && array_column( $gallery['review_page']['review_facts']['groups'][0]['rows'], 'label' ) === array( 'Project Description', 'Square Footage' )
        && $gallery['review_page']['title'] === 'Submitted Photos',
    'Gallery context should expose stable id and approved public project details without duplicating template-derived counts, lead details, or operator-only controls for anonymous viewers.'
);
$public_review_json = json_encode( $gallery['review_page']['review_facts'] );
eforms_test_assert(
    is_string( $public_review_json )
        && strpos( $public_review_json, 'Refinish the main floor.' ) !== false
        && strpos( $public_review_json, '1145' ) !== false
        && strpos( $public_review_json, 'Ada Lovelace' ) === false
        && strpos( $public_review_json, '80231' ) === false
        && strpos( $public_review_json, 'ada@example.test' ) === false
        && strpos( $public_review_json, '720-900-5278' ) === false
        && strpos( $public_review_json, 'listing_url' ) === false
        && strpos( $public_review_json, 'signature' ) === false
        && strpos( $public_review_json, 'eforms-private' ) === false,
    'Anonymous public review context should contain only approved non-contact project rows.'
);
$review_item = $gallery['review_page']['items'][0];
eforms_test_assert( preg_match( '#^https://example\.test/review/file/[A-Za-z0-9_-]+$#', $review_item['download_url'] ) === 1, 'The gallery should expose one opaque authoritative-artifact download bearer.' );
eforms_test_assert( array_keys( $review_item ) === array( 'download_url', 'preview_url', 'original_inline_available' ) && $review_item['preview_url'] === '' && $review_item['original_inline_available'] === true, 'The no-preview gallery item should expose the current artifact, optional preview, and browser-fallback capability.' );
$no_preview_html = eforms_test_render_review_template( $gallery['review_page'] );
eforms_test_assert( strpos( $no_preview_html, 'data-eforms-review-original-src="' ) !== false && strpos( $no_preview_html, '>Load original</button>' ) !== false, 'A download-only gallery should offer an explicit browser-side original-image fallback without starting the request in markup.' );
eforms_test_assert( preg_match( '/<img[^>]+\ssrc=/', $no_preview_html ) !== 1, 'The original fallback must not load full submitted files until the viewer explicitly requests one.' );
$non_browser_review_page = $gallery['review_page'];
$non_browser_review_page['items'][0]['original_inline_available'] = false;
$non_browser_html = eforms_test_render_review_template( $non_browser_review_page );
eforms_test_assert( strpos( $non_browser_html, '>Load original</button>' ) === false && strpos( $non_browser_html, 'eforms-review-download-overlay' ) !== false, 'A non-browser-renderable or remotely owned original should remain downloadable without advertising an unsupported inline fallback.' );
eforms_test_assert( strpos( json_encode( $gallery['review_page'] ), $uploads_dir ) === false, 'Gallery context must not disclose private paths.' );

eforms_test_set_filter( 'eforms_config', function ( $config ) {
    $config['assets']['css_disable'] = true;
    return $config;
} );
Config::reset_for_tests();
$GLOBALS['eforms_test_styles'] = array();
$disabled_assets_gallery = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30 );
eforms_test_assert( $disabled_assets_gallery['status'] === 200 && empty( $GLOBALS['eforms_test_styles'] ), 'Review galleries should honor assets.css_disable for shared CSS.' );
eforms_test_set_filter( 'eforms_config', null );
Config::reset_for_tests();

$download = eforms_test_review_request( $review_item['download_url'], $uploads_dir, $salt, $now + 30 );
eforms_test_assert( $download['status'] === 200 && $download['headers']['Content-Type'] === 'image/png', 'The signed download should preserve the authoritative artifact MIME.' );
$download_bytes = is_resource( $download['stream'] ) ? stream_get_contents( $download['stream'] ) : false;
if ( is_resource( $download['stream'] ) ) {
    fclose( $download['stream'] );
}
eforms_test_assert( is_string( $download_bytes ) && hash_equals( hash( 'sha256', $artifact ), hash( 'sha256', $download_bytes ) ), 'The signed download should stream the exact authoritative artifact.' );
eforms_test_assert( strpos( $download['headers']['Content-Disposition'], 'attachment; filename="submitted-image.png";' ) === 0 && strpos( $download['headers']['Content-Disposition'], "filename*=UTF-8''Customer%20%3CPhoto%3E.png" ) !== false, 'Submitted-image downloads should derive both fallback and encoded extensions from the detected MIME.' );
$content_disposition = new ReflectionMethod( 'ReviewController', 'content_disposition' );
$content_disposition->setAccessible( true );
$heif_disposition = $content_disposition->invoke( null, 'Camera.heic', 'image/heif' );
eforms_test_assert( strpos( $heif_disposition, 'filename="submitted-image.heif"' ) !== false && strpos( $heif_disposition, "filename*=UTF-8''Camera.heif" ) !== false, 'HEIC/HEIF alias mismatches should use the detected authoritative MIME for both download filenames.' );

$local_preview_overrides = array(
    'review_provider' => 'local',
    'local_preview_concurrency' => 1,
    'local_preview_encoder' => function ( $source_path, $mime, $destination ) {
        return $mime === 'image/png' && is_file( $source_path ) && file_put_contents( $destination, "\xff\xd8\xff\xd9" ) === 4;
    },
);
$local_preview_gallery = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30, $local_preview_overrides );
$local_preview_url = $local_preview_gallery['review_page']['items'][0]['preview_url'];
eforms_test_assert( preg_match( '#^https://example\.test/review/preview/[A-Za-z0-9_-]+$#', $local_preview_url ) === 1, 'The optional local composition should mint a distinct opaque preview bearer after gallery authorization.' );
$local_preview_html = eforms_test_render_review_template( $local_preview_gallery['review_page'] );
eforms_test_assert( strpos( $local_preview_html, 'hidden data-eforms-review-src="' ) !== false, 'The gallery template should defer preview source assignment to its serialized browser runtime.' );
eforms_test_assert( preg_match( '/<img[^>]+\ssrc=/', $local_preview_html ) !== 1, 'The gallery template must not start preview requests before the browser admission owner runs.' );
eforms_test_assert( preg_match( '/<a[^>]+class="eforms-review-preview-link ta-gallery__link"[^>]+\shref=/', $local_preview_html ) !== 1 && strpos( $local_preview_html, 'data-lbwps-srcsmall=' ) === false, 'The gallery template must keep lightbox preview URLs inert until the browser admission owner has loaded the preview.' );
eforms_test_assert( strpos( $local_preview_html, 'class="eforms-review-preview-link ta-gallery__link"' ) !== false && strpos( $local_preview_html, 'data-lbwps-width="' ) !== false && strpos( $local_preview_html, 'data-eforms-review-lightbox' ) === false && strpos( $local_preview_html, 'data-eforms-review-download' ) === false && strpos( $local_preview_html, 'eforms-review-download-overlay' ) !== false, 'Preview-capable gallery items should expose a PhotoSwipe-compatible trigger without retaining the removed eForms modal contract.' );
eforms_test_assert( strpos( $local_preview_html, 'data-eforms-review-original-src="' ) !== false && strpos( $local_preview_html, '>Load original</button>' ) !== false, 'Preview-capable cards should retain an explicit signed-original fallback after preview failure.' );
eforms_test_assert( strpos( $local_preview_html, 'Customer &lt;Photo&gt;' ) === false && strpos( $local_preview_html, 'Open Photo 1' ) !== false && strpos( $local_preview_html, 'Download Photo 1' ) !== false, 'Review gallery UI should use ordinal photo labels instead of exposing upload filenames.' );
eforms_test_assert( strpos( $local_preview_html, 'data-eforms-review-delete-open' ) === false && strpos( $local_preview_html, 'data-eforms-review-availability-open' ) === false && strpos( $local_preview_html, 'eforms_review_availability' ) === false, 'Anonymous gallery renders should not expose operator-only deletion or availability controls.' );
eforms_test_assert( strpos( $local_preview_html, 'eforms-review-actions' ) === false && strpos( $local_preview_html, 'Available until ' ) === false && strpos( $local_preview_html, 'eforms-review-submitted' ) === false && strpos( $local_preview_html, 'ID: <strong>' ) === false, 'Anonymous gallery renders should omit operator management metadata.' );
eforms_test_assert(
    strpos( $local_preview_html, 'eforms-review-facts' ) !== false
        && strpos( $local_preview_html, 'Refinish the main floor.' ) !== false
        && strpos( $local_preview_html, '1145' ) !== false
        && strpos( $local_preview_html, 'eforms-review-facts' ) < strpos( $local_preview_html, 'eforms-review-grid' )
        && strpos( $local_preview_html, 'Ada Lovelace' ) === false
        && strpos( $local_preview_html, 'ada@example.test' ) === false
        && strpos( $local_preview_html, '720-900-5278' ) === false
        && strpos( $local_preview_html, '80231' ) === false
        && strpos( $local_preview_html, 'https://example.test/listing' ) === false,
    'Anonymous gallery renders should show only approved project facts before photos when a retained sidecar exists.'
);
eforms_test_assert( strpos( $local_preview_html, 'data-eforms-review-preview-timeout-ms="' . Anchors::get( 'REVIEW_PREVIEW_LOAD_TIMEOUT_MS' ) . '"' ) !== false, 'The gallery template should pass the code-owned preview deadline to its serialized browser runtime.' );
eforms_test_assert( ReviewController::enable_lightbox_for_current_review( false, 0 ) === true, 'The review gallery should override theme lightbox suppression for its signed gallery route.' );
$member_request = eforms_test_review_request( $review_item['download_url'], $uploads_dir, $salt, $now + 30 );
foreach ( $legacy_artifact_directories as $legacy_directory ) {
    eforms_test_assert( ( fileperms( $legacy_directory ) & 0777 ) === PrivateDir::REVIEW_DIRECTORY_MODE, 'An existing local artifact path should migrate to group traversal when resolved for review.' );
}
eforms_test_assert(
    ( fileperms( dirname( $legacy_artifact_path ) . '/' . LocalArtifactStore::LOCK_FILENAME ) & 0777 ) === PrivateDir::FILE_MODE
        && ( fileperms( $legacy_artifact_path ) & 0777 ) === PrivateDir::REVIEW_FILE_MODE,
    'Resolving a local artifact should migrate its content while keeping the object lock owner-private.'
);
if ( isset( $member_request['stream'] ) && is_resource( $member_request['stream'] ) ) {
    fclose( $member_request['stream'] );
}
eforms_test_assert( ReviewController::enable_lightbox_for_current_review( false, 0 ) === false, 'The review member routes should not opt into page lightbox assets.' );
$local_preview = eforms_test_review_request( $local_preview_url, $uploads_dir, $salt, $now + 30, $local_preview_overrides );
$local_preview_bytes = isset( $local_preview['stream'] ) && is_resource( $local_preview['stream'] ) ? stream_get_contents( $local_preview['stream'] ) : false;
if ( isset( $local_preview['stream'] ) && is_resource( $local_preview['stream'] ) ) {
    fclose( $local_preview['stream'] );
}
eforms_test_assert( $local_preview['status'] === 200 && $local_preview['headers']['Content-Type'] === 'image/jpeg' && $local_preview_bytes === "\xff\xd8\xff\xd9", 'The signed local preview route should return only the optional browser-compatible representation.' );
eforms_test_assert( $local_preview['headers']['Cache-Control'] === 'private, no-store, max-age=0' && $local_preview['headers']['Referrer-Policy'] === 'no-referrer', 'Local previews should preserve private bearer-link response headers.' );

foreach ( array( '', '/index.php/%postname%/' ) as $unsupported_permalink_structure ) {
    update_option( 'permalink_structure', $unsupported_permalink_structure );
    eforms_test_assert( ReviewController::gallery_url( $submission_id, 'https://example.test', $salt ) === '', 'Permalink modes without rewrite-based clean routing should not mint a review URL.' );
    $unsupported_direct_request = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30 );
    eforms_test_assert( $unsupported_direct_request['status'] === 404, 'Direct review requests should fail closed when the configured permalink mode cannot route the compact URL.' );
    $unsupported_email_reference = ReviewController::email_gallery_reference(
        $submission_id,
        array( 'review_photo' ),
        $uploads_dir,
        'https://example.test',
        $salt,
        $now + 30
    );
    eforms_test_assert( empty( $unsupported_email_reference['ok'] ), 'Email review references should fail closed when the configured permalink mode cannot route the compact URL.' );
}

foreach ( array( '/%postname%/', '/%year%/%postname%/' ) as $permalink_structure ) {
    update_option( 'permalink_structure', $permalink_structure );
    $fallback_url = ReviewController::gallery_url( $submission_id, 'https://example.test', $salt );
    eforms_test_assert( $fallback_url === $gallery_url, 'Front-controller permalink modes should use the same clean review route.' );
    $fallback_gallery = eforms_test_review_request( $fallback_url, $uploads_dir, $salt, $now + 30 );
    eforms_test_assert( $fallback_gallery['status'] === 200 && $fallback_gallery['render'] === 'review_gallery', 'The clean gallery URL should dispatch through the review controller.' );
    $fallback_download_url = $fallback_gallery['review_page']['items'][0]['download_url'];
    eforms_test_assert( strpos( $fallback_download_url, '/review/file/' ) !== false, 'Artifact downloads should use the same clean route.' );
    $fallback_download = eforms_test_review_request( $fallback_download_url, $uploads_dir, $salt, $now + 30 );
    eforms_test_assert( $fallback_download['status'] === 200 && $fallback_download['headers']['Content-Type'] === 'image/png', 'The clean file URL should stream its signed manifest member.' );
    if ( isset( $fallback_download['stream'] ) && is_resource( $fallback_download['stream'] ) ) {
        fclose( $fallback_download['stream'] );
    }
}

eforms_test_assert( ReviewController::prevent_canonical_redirect( 'https://example.test/review/example/', $gallery_url ) === false, 'Canonical redirects should not append a slash to an opaque review URL.' );
eforms_test_assert( ReviewController::prevent_canonical_redirect( 'https://example.test/contact/', 'https://example.test/contact' ) === 'https://example.test/contact/', 'Canonical redirects outside the review route should remain unchanged.' );

$plain_url = ReviewController::gallery_url( $submission_id, 'https://example.test', $salt );
$query_alias = eforms_test_review_request(
    str_replace( 'https://example.test/review/', 'https://example.test/unrelated/review/', $plain_url ),
    $uploads_dir,
    $salt,
    $now + 30
);
eforms_test_assert( empty( $query_alias['handled'] ), 'A bearer-like segment outside the canonical review path should not become a route alias.' );
foreach ( array( 'HEAD', 'POST', 'PUT' ) as $review_method ) {
    $_SERVER['REQUEST_METHOD'] = $review_method;
    $_SERVER['REQUEST_URI'] = (string) parse_url( $plain_url, PHP_URL_PATH );
    $_GET = array();
    $method_denied = PublicRequestController::dispatch_current_request();
    eforms_test_assert( ! empty( $method_denied['handled'] ) && $method_denied['status'] === 404, 'Unsupported or unauthorized review methods should be handled by the private rejection path.' );
    eforms_test_assert( $method_denied['headers']['Cache-Control'] === 'private, no-store, max-age=0' && $method_denied['headers']['X-Robots-Tag'] === 'noindex, nofollow', 'Rejected review methods should retain private no-store/noindex headers.' );
}
unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
$_GET = array();

$delete_binding = $binding;
$delete_binding['raw_token'] = 'delete-review-form-token';
$delete_binding['instance_id'] = 'delete-review-instance';
$delete_created = UploadBatchStore::create_batch( $delete_binding, $secret, $field, $uploads_dir, $now + 40 );
eforms_test_assert( ! empty( $delete_created['ok'] ), 'The operator-delete fixture should create a staged aggregate.' );
$delete_source = eforms_test_write_file( $uploads_dir, 'delete-review-source.png', $artifact );
$delete_put = UploadBatchStore::put_item(
    $delete_created['batch']['batch_id'],
    $secret,
    'delete_photo',
    0,
    array(
        'tmp_name' => $delete_source,
        'original_name' => 'Delete Me.png',
        'size' => filesize( $delete_source ),
        'error' => UPLOAD_ERR_OK,
    ),
    $uploads_dir,
    array( 'now' => $now + 45 )
);
eforms_test_assert( ! empty( $delete_put['ok'] ), 'The operator-delete fixture should commit an authoritative image.' );
$delete_submission_id = '123e4567-e89b-12d3-a456-426614174002';
$delete_resolved = UploadBatchStore::resolve_open( $delete_created['batch']['batch_id'], $secret, $delete_binding, $field, $uploads_dir, $now + 50 );
eforms_test_assert( ! empty( UploadBatchStore::claim_finalization( $delete_created['batch']['batch_id'], $secret, $delete_binding, $field, $delete_resolved['items'], $delete_submission_id, $uploads_dir, $now + 50 )['ok'] ), 'The operator-delete fixture should freeze its aggregate.' );
$delete_finalized = UploadBatchStore::finalize( $delete_created['batch']['batch_id'], $delete_submission_id, $uploads_dir, $now + 55, eforms_test_review_snapshot( $delete_submission_id, $now + 55, 'https://example.test/listing' ) );
eforms_test_assert( ! empty( $delete_finalized['ok'] ), 'The operator-delete fixture should finalize.' );
$delete_url = ReviewController::gallery_url( $delete_submission_id, 'https://example.test', $salt );
$GLOBALS['eforms_test_can_manage'] = true;
$operator_gallery = eforms_test_review_request( $delete_url, $uploads_dir, $salt, $now + 60 );
$operator_action_field = isset( $operator_gallery['review_page']['operator_action_field'] ) ? $operator_gallery['review_page']['operator_action_field'] : '';
$delete_action = isset( $operator_gallery['review_page']['delete_action'] ) ? $operator_gallery['review_page']['delete_action'] : '';
$delete_nonce_action = isset( $operator_gallery['review_page']['delete_nonce_action'] ) ? $operator_gallery['review_page']['delete_nonce_action'] : '';
$delete_nonce_field = isset( $operator_gallery['review_page']['delete_nonce_field'] ) ? $operator_gallery['review_page']['delete_nonce_field'] : '';
$GLOBALS['eforms_test_nonce_actions'] = array(
    $delete_nonce_action => 'delete-valid-nonce',
    $delete_nonce_action . '-other' => 'other-delete-nonce',
);
eforms_test_assert( ! empty( $operator_gallery['review_page']['can_delete'] ) && $operator_action_field !== '' && $delete_action !== '' && $delete_nonce_action !== '' && $delete_nonce_field !== '', 'Logged-in operators should receive the delete action contract only after gallery authorization.' );
$operator_facts = isset( $operator_gallery['review_page']['review_facts'] ) && is_array( $operator_gallery['review_page']['review_facts'] )
    ? $operator_gallery['review_page']['review_facts']
    : array();
$operator_rows = array();
foreach ( isset( $operator_facts['groups'] ) && is_array( $operator_facts['groups'] ) ? $operator_facts['groups'] : array() as $group ) {
    $operator_rows = array_merge( $operator_rows, isset( $group['rows'] ) && is_array( $group['rows'] ) ? $group['rows'] : array() );
}
eforms_test_assert(
    $operator_gallery['review_page']['title'] === 'Virtual Estimate Request'
        && $operator_gallery['review_page']['attribution_name'] === 'Ada Lovelace'
        && array_column( $operator_rows, 'label' ) === array( 'Zip Code', 'Email', 'Phone', 'Project Description', 'Square Footage', 'Listing URL' )
        && count( $operator_gallery['review_page']['items'] ) === 1
        && isset( $operator_gallery['review_page']['items'][0]['download_url'] ),
    'Logged-in operators should receive snapshot lead context, photos, and existing action context after gallery authorization.'
);
$GLOBALS['eforms_test_can_manage'] = false;
$anonymous_snapshot_gallery = eforms_test_review_request( $delete_url, $uploads_dir, $salt, $now + 60 );
$GLOBALS['eforms_test_can_manage'] = true;
eforms_test_assert(
    $anonymous_snapshot_gallery['status'] === 200
        && ! isset( $anonymous_snapshot_gallery['review_page']['attribution_name'] )
        && isset( $anonymous_snapshot_gallery['review_page']['review_facts']['groups'][0]['rows'] )
        && array_column( $anonymous_snapshot_gallery['review_page']['review_facts']['groups'][0]['rows'], 'label' ) === array( 'Project Description', 'Square Footage' )
        && $anonymous_snapshot_gallery['review_page']['title'] === 'Submitted Photos',
    'Anonymous bearer gallery context should expose only approved public project rows when the retained submission has a valid lead snapshot.'
);
$availability_nonce_action = 'eforms_review_availability_' . $delete_submission_id;
$GLOBALS['eforms_test_nonce_actions'][ $availability_nonce_action ] = 'availability-valid-nonce';
$availability_expected = array(
    '30_days' => Anchors::get( 'MANAGED_AVAILABILITY_30_DAYS_SECONDS' ),
    '90_days' => Anchors::get( 'MANAGED_AVAILABILITY_90_DAYS_SECONDS' ),
    '1_year' => Anchors::get( 'MANAGED_AVAILABILITY_1_YEAR_SECONDS' ),
);
$availability_now = $now + 61;
foreach ( $availability_expected as $choice => $duration ) {
    $updated = eforms_test_review_request(
        $delete_url,
        $uploads_dir,
        $salt,
        $availability_now,
        array(
            'method' => 'POST',
            'post' => array(
                'eforms_review_action' => 'update_availability',
                '_eforms_review_availability_nonce' => 'availability-valid-nonce',
                'eforms_review_availability' => $choice,
            ),
        )
    );
    $updated_submission = UploadBatchStore::submission( $delete_submission_id, $uploads_dir, $availability_now );
    $updated_html = eforms_test_render_review_template( $updated['review_page'] );
    eforms_test_assert(
        $updated['status'] === 200
            && ! empty( $updated_submission['ok'] )
            && $updated_submission['submission']['delete_after'] === $availability_now + $duration
            && preg_match( '/<input[^>]+value="' . preg_quote( $choice, '/' ) . '"[^>]+checked/', $updated_html ) === 1,
        'Operator availability update should persist the fixed ' . $choice . ' choice through UploadBatchStore and return it selected.'
    );
    $availability_now += 1;
}
$reloaded_numeric_gallery = eforms_test_review_request( $delete_url, $uploads_dir, $salt, $availability_now );
$reloaded_numeric_html = eforms_test_render_review_template( $reloaded_numeric_gallery['review_page'] );
eforms_test_assert(
    $reloaded_numeric_gallery['status'] === 200
        && preg_match( '/<input[^>]+value="30_days"[^>]+checked/', $reloaded_numeric_html ) === 0
        && preg_match( '/<input[^>]+value="90_days"[^>]+checked/', $reloaded_numeric_html ) === 0
        && preg_match( '/<input[^>]+value="1_year"[^>]+checked/', $reloaded_numeric_html ) === 0,
    'Reloaded numeric availability should not falsely present a fixed-duration preset as the current persisted state.'
);
$before_invalid_availability = UploadBatchStore::submission( $delete_submission_id, $uploads_dir, $availability_now )['submission']['delete_after'];
$GLOBALS['eforms_test_can_manage'] = false;
$anonymous_availability = eforms_test_review_request(
    $delete_url,
    $uploads_dir,
    $salt,
    $availability_now,
    array(
        'method' => 'POST',
        'post' => array(
            'eforms_review_action' => 'update_availability',
            '_eforms_review_availability_nonce' => 'availability-valid-nonce',
            'eforms_review_availability' => 'manual',
        ),
    )
);
$GLOBALS['eforms_test_can_manage'] = true;
$invalid_availability = eforms_test_review_request(
    $delete_url,
    $uploads_dir,
    $salt,
    $availability_now,
    array(
        'method' => 'POST',
        'post' => array(
            'eforms_review_action' => 'update_availability',
            '_eforms_review_availability_nonce' => 'availability-valid-nonce',
            'eforms_review_availability' => 'custom_days',
        ),
    )
);
$after_invalid_availability = UploadBatchStore::submission( $delete_submission_id, $uploads_dir, $availability_now );
eforms_test_assert(
    $anonymous_availability['status'] === 404
        && $invalid_availability['status'] === 404
        && ! empty( $after_invalid_availability['ok'] )
        && $after_invalid_availability['submission']['delete_after'] === $before_invalid_availability,
    'Invalid or anonymous availability update attempts should leave delete_after unchanged.'
);
$manual_availability = eforms_test_review_request(
    $delete_url,
    $uploads_dir,
    $salt,
    $availability_now,
    array(
        'method' => 'POST',
        'post' => array(
            'eforms_review_action' => 'update_availability',
            '_eforms_review_availability_nonce' => 'availability-valid-nonce',
            'eforms_review_availability' => 'manual',
        ),
    )
);
$manual_submission = UploadBatchStore::submission( $delete_submission_id, $uploads_dir, $availability_now );
eforms_test_assert( $manual_availability['status'] === 200 && ! empty( $manual_submission['ok'] ) && $manual_submission['submission']['delete_after'] === null, 'Operator availability update should support until manually deleted.' );
$operator_html = eforms_test_render_review_template( $operator_gallery['review_page'] );
eforms_test_assert(
    strpos( $operator_html, 'data-eforms-review-delete-open' ) !== false
        && strpos( $operator_html, 'method="post"' ) !== false
        && strpos( $operator_html, '<h1 class="page-title">Virtual Estimate Request</h1>' ) !== false
        && strpos( $operator_html, 'eforms-review-heading' ) !== false
        && strpos( $operator_html, 'eforms-review-attribution' ) !== false
        && strpos( $operator_html, 'eforms-review-attribution-by">by</span>' ) !== false
        && strpos( $operator_html, 'eforms-review-attribution-name">Ada Lovelace</span>' ) !== false
        && strpos( $operator_html, '<dt>Name</dt>' ) === false
        && strpos( $operator_html, 'eforms-review-facts' ) !== false
        && strpos( $operator_html, 'Zip Code' ) !== false
        && strpos( $operator_html, '80231' ) !== false
        && strpos( $operator_html, 'ada@example.test' ) !== false
        && strpos( $operator_html, '720-900-5278' ) !== false
        && strpos( $operator_html, 'Refinish the main floor.' ) !== false
        && strpos( $operator_html, '1145' ) !== false
        && strpos( $operator_html, 'https://example.test/listing' ) !== false
        && strpos( $operator_html, 'eforms-review-facts' ) < strpos( $operator_html, 'eforms-review-grid' )
        && strpos( $operator_html, 'eforms-review-grid' ) < strpos( $operator_html, 'eforms-review-actions' )
        && strpos( $operator_html, 'data-eforms-review-availability-open' ) !== false
        && strpos( $operator_html, 'ID: <strong>' . $delete_submission_id . '</strong>' ) !== false
        && strpos( $operator_html, 'Submitted ' . gmdate( 'F j, Y \a\t g:i a', $delete_finalized['submission']['finalized_at'] ) ) !== false
        && strpos( $operator_html, 'Available until ' ) !== false
        && strpos( $operator_html, 'Submitted ' ) < strpos( $operator_html, 'Available until ' )
        && strpos( $operator_html, '30 days' ) !== false
        && strpos( $operator_html, '90 days' ) !== false
        && strpos( $operator_html, '1 year' ) !== false
        && strpos( $operator_html, 'Until manually deleted' ) !== false
        && strpos( $operator_html, 'value="30_days"' ) !== false
        && strpos( $operator_html, 'value="90_days"' ) !== false
        && strpos( $operator_html, 'value="1_year"' ) !== false
        && strpos( $operator_html, 'value="manual"' ) !== false
        && strpos( $operator_html, 'Archive' ) === false
        && stripos( $operator_html, 'link expires' ) === false
        && stripos( $operator_html, 'storage' ) === false
        && stripos( $operator_html, 'provider' ) === false,
    'Operator gallery renders should expose POST-only controls and fixed photo-submission availability choices without storage, provider, archive, or link-expiry language.'
);
$expired_seed_now = $availability_now + 10;
eforms_test_assert( ! empty( UploadBatchStore::update_finalized_availability( $delete_submission_id, $uploads_dir, $expired_seed_now + 1, $expired_seed_now )['ok'] ), 'The expired management fixture should set a near-term numeric availability.' );
$GLOBALS['eforms_test_can_manage'] = false;
$expired_anonymous = eforms_test_review_request( $delete_url, $uploads_dir, $salt, $expired_seed_now + 2 );
$GLOBALS['eforms_test_can_manage'] = true;
$expired_operator = eforms_test_review_request( $delete_url, $uploads_dir, $salt, $expired_seed_now + 2 );
$expired_operator_html = eforms_test_render_review_template( $expired_operator['review_page'] );
eforms_test_assert( $expired_anonymous['status'] === 404, 'Expired galleries should remain unavailable to anonymous bearer requests.' );
eforms_test_assert(
    $expired_operator['status'] === 200
        && ! empty( $expired_operator['review_page']['expired'] )
        && empty( $expired_operator['review_page']['items'] )
        && ! isset( $expired_operator['review_page']['review_facts'] )
        && ! isset( $expired_operator['review_page']['availability_action'] )
        && strpos( $expired_operator_html, 'data-eforms-review="expired"' ) !== false
        && strpos( $expired_operator_html, 'This photo submission is no longer available.' ) !== false
        && strpos( $expired_operator_html, 'eforms-review-facts' ) === false
        && strpos( $expired_operator_html, 'Ada Lovelace' ) === false
        && strpos( $expired_operator_html, 'ada@example.test' ) === false
        && strpos( $expired_operator_html, 'ID: <strong>' . $delete_submission_id . '</strong>' ) !== false
        && strpos( $expired_operator_html, 'Submitted ' . gmdate( 'F j, Y \a\t g:i a', $delete_finalized['submission']['finalized_at'] ) ) !== false
        && strpos( $expired_operator_html, 'data-eforms-review-delete-open' ) !== false
        && strpos( $expired_operator_html, 'data-eforms-review-availability-open' ) === false
        && strpos( $expired_operator_html, 'eforms-review-grid' ) === false
        && strpos( $expired_operator_html, 'eforms-review-download-overlay' ) === false
        && strpos( $expired_operator_html, '/review/file/' ) === false,
    'Expired operator management renders status and whole-submission deletion without photos, member links, or availability updates.'
);
$expired_delete_time = $expired_seed_now + 2;
$expired_download = eforms_test_review_request(
    ReviewController::file_url( $delete_submission_id, 'delete_photo', 'https://example.test', $salt ),
    $uploads_dir,
    $salt,
    $expired_delete_time
);
$expired_preview = eforms_test_review_request(
    ReviewController::preview_url( $delete_submission_id, 'delete_photo', 'https://example.test', $salt ),
    $uploads_dir,
    $salt,
    $expired_delete_time,
    $local_preview_overrides
);
eforms_test_assert(
    $expired_download['status'] === 404
        && $expired_preview['status'] === 404
        && ! isset( $expired_download['stream'] )
        && ! isset( $expired_preview['stream'] ),
    'Expired member routes should deny download and preview access before any artifact stream is exposed.'
);
$manual_html = eforms_test_render_review_template( $manual_availability['review_page'] );
eforms_test_assert(
    strpos( $manual_html, 'Available until manually deleted' ) !== false
        && preg_match( '/<input[^>]+value="manual"[^>]+checked/', $manual_html ) === 1,
    'Availability updates should return the gallery with the updated manual availability state selected.'
);
$v2_expiry = $delete_finalized['submission']['delete_after'] + 30;
$v2_message = UploadBatchStore::encode_parts( array( ReviewController::DOMAIN, '2', 'gallery', $delete_submission_id, '', (string) $v2_expiry ) );
$v2_signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $v2_message, $salt, true ) ), '+/', '-_' ), '=' );
$v2_delete_url = 'https://example.test/?' . http_build_query(
    array(
        'eforms_review' => $delete_submission_id,
        'expires' => $v2_expiry,
        'signature' => $v2_signature,
    ),
    '',
    '&',
    PHP_QUERY_RFC3986
);
$v2_delete_get = eforms_test_review_request( $v2_delete_url, $uploads_dir, $salt, $now + 60 );
eforms_test_assert(
    empty( $v2_delete_get['handled'] )
        && ! empty( UploadBatchStore::submission( $delete_submission_id, $uploads_dir, $now + 60 )['ok'] ),
    'Superseded query-shaped review URLs should not be review routes after the compact-token cutover.'
);
$GLOBALS['eforms_test_can_manage'] = false;
$anonymous_delete = eforms_test_review_request(
    $delete_url,
    $uploads_dir,
    $salt,
    $expired_delete_time,
    array(
        'method' => 'POST',
        'post' => array(
            $operator_action_field => $delete_action,
            $delete_nonce_field => 'delete-valid-nonce',
        ),
    )
);
eforms_test_assert( $anonymous_delete['status'] === 404 && ! empty( UploadBatchStore::submission_management_status( $delete_submission_id, $uploads_dir, $expired_delete_time )['ok'] ), 'Forwarded anonymous gallery links should not mutate even with valid-looking delete POST fields after availability expiry.' );
$GLOBALS['eforms_test_can_manage'] = true;
$wrong_action_nonce = eforms_test_review_request(
    $delete_url,
    $uploads_dir,
    $salt,
    $expired_delete_time,
    array(
        'method' => 'POST',
        'post' => array(
            $operator_action_field => $delete_action,
            $delete_nonce_field => 'other-delete-nonce',
        ),
    )
);
eforms_test_assert( $wrong_action_nonce['status'] === 404 && ! empty( UploadBatchStore::submission_management_status( $delete_submission_id, $uploads_dir, $expired_delete_time )['ok'] ), 'Operator delete POST should bind the nonce to the current submission delete action after availability expiry.' );
$bad_delete = eforms_test_review_request(
    $delete_url,
    $uploads_dir,
    $salt,
    $expired_delete_time,
    array(
        'method' => 'POST',
        'post' => array(
            $operator_action_field => $delete_action,
            $delete_nonce_field => 'wrong-nonce',
        ),
    )
);
eforms_test_assert( $bad_delete['status'] === 404, 'Operator delete POST should fail closed when the nonce is invalid.' );
$deleted_response = eforms_test_review_request(
    $delete_url,
    $uploads_dir,
    $salt,
    $expired_delete_time,
    array(
        'method' => 'POST',
        'post' => array(
            $operator_action_field => $delete_action,
            $delete_nonce_field => 'delete-valid-nonce',
        ),
    )
);
$delete_submission_path = $uploads_dir . '/eforms-private/submissions/' . Helpers::h2( $delete_submission_id ) . '/' . $delete_submission_id;
eforms_test_assert( $deleted_response['status'] === 200 && ! empty( $deleted_response['review_page']['deleted'] ) && $deleted_response['result']['deleted'] === true, 'A nonce-valid operator delete POST should render a private deletion result for an expired-but-present aggregate through UploadBatchStore.' );
eforms_test_assert( ! is_dir( $delete_submission_path ) && empty( UploadBatchStore::submission_management_status( $delete_submission_id, $uploads_dir, $expired_delete_time )['ok'] ), 'Operator deletion should remove the expired finalized aggregate through UploadBatchStore.' );
$post_delete_gallery = eforms_test_review_request( $delete_url, $uploads_dir, $salt, $expired_delete_time );
eforms_test_assert( $post_delete_gallery['status'] === 404, 'After operator deletion, the signed gallery URL should no longer serve the submission.' );
unset( $GLOBALS['eforms_test_nonce_actions'] );
unset( $GLOBALS['eforms_test_can_manage'] );

if ( ! defined( 'EFORMS_UPLOAD_COMPOSITION' ) ) {
    define( 'EFORMS_UPLOAD_COMPOSITION', WorkerClient::COMPOSITION_WORKER );
}
if ( ! defined( 'EFORMS_WORKER_URL' ) ) {
    define( 'EFORMS_WORKER_URL', 'https://media.example.test' );
}
if ( ! defined( 'EFORMS_WORKER_ENVIRONMENT_ID' ) ) {
    define( 'EFORMS_WORKER_ENVIRONMENT_ID', 'review-test-env' );
}
if ( ! defined( 'EFORMS_WORKER_ACTIVE_KEY_ID' ) ) {
    define( 'EFORMS_WORKER_ACTIVE_KEY_ID', 'review-test-key' );
}
if ( ! defined( 'EFORMS_WORKER_ACTIVE_KEY_B64' ) ) {
    define( 'EFORMS_WORKER_ACTIVE_KEY_B64', $secret );
}
$worker_store_identity = WorkerClient::composition_fingerprint();
eforms_test_assert( preg_match( '/^[0-9a-f]{64}$/', $worker_store_identity ) === 1, 'The candidate gallery fixture should bind the current Worker artifact-store identity.' );
$worker_uploads_dir = eforms_test_setup_uploads( 'eforms-review-worker-gallery' );
$worker_field = $field;
$worker_field['max_files'] = 3;
$worker_binding = $binding;
$worker_binding['raw_token'] = 'worker-review-gallery-token';
$worker_binding['instance_id'] = 'worker-review-gallery-instance';
$worker_binding['accept_until'] = $now + 600;
$worker_submission_id = '123e4567-e89b-12d3-a456-426614174004';
$worker_submission = eforms_test_review_worker_submission(
    $worker_uploads_dir,
    $secret,
    $worker_field,
    $worker_binding,
    $worker_submission_id,
    $worker_store_identity,
    $now + 110
);
eforms_test_assert(
    ! empty( UploadBatchStore::store_review_snapshot( $worker_submission_id, $worker_uploads_dir, eforms_test_review_snapshot( $worker_submission_id, $now + 131, 'https://example.test/worker-listing' ) )['ok'] )
        && ! empty( UploadBatchStore::review_snapshot( $worker_submission_id, $worker_uploads_dir )['ok'] ),
    'Worker-owned finalized submissions should retain and read the same review snapshot sidecar as local submissions.'
);
$worker_gallery_url = ReviewController::gallery_url( $worker_submission_id, 'https://example.test', $salt );
$worker_status_width = 3200;
$worker_status_height = 1600;
$worker_status_calls = array();
$worker_mixed_gallery = ReviewController::worker_gallery_response(
    $worker_submission_id,
    $worker_uploads_dir,
    $now + 140,
    $salt,
    array(
        'base_url' => 'https://example.test',
        'worker_gallery_status' => function ( $submission_id, $storage_identity, $items, $expected_identity, $called_now ) use ( &$worker_status_calls, $worker_submission_id, $worker_store_identity, $now, $worker_status_width, $worker_status_height ) {
            $worker_status_calls[] = array(
                'submission_id' => $submission_id,
                'storage_identity' => $storage_identity,
                'items' => $items,
                'expected_identity' => $expected_identity,
                'now' => $called_now,
            );
            return array(
                'ok' => true,
                'statuses' => array(
                    array( 'upload_id' => 'worker_photo_0', 'status' => 'accepted', 'mime' => 'image/png', 'width' => $worker_status_width, 'height' => $worker_status_height ),
                    array( 'upload_id' => 'worker_photo_1', 'status' => 'pending' ),
                    array( 'upload_id' => 'worker_photo_2', 'status' => 'unavailable' ),
                ),
            );
        },
    )
);
$worker_call = $worker_status_calls[0];
$worker_gallery_items = $worker_call['items'];
$worker_expected_item_fields = array_keys( WorkerProtocol::WORKER_GALLERY_ITEM_FIELDS );
$worker_edge = Anchors::get( 'REVIEW_PREVIEW_MAX_EDGE' );
$worker_scale = min( 1, $worker_edge / max( $worker_status_width, $worker_status_height ) );
$worker_expected_width = max( 1, (int) round( $worker_status_width * $worker_scale ) );
$worker_expected_height = max( 1, (int) round( $worker_status_height * $worker_scale ) );
$worker_context_items = $worker_mixed_gallery['review_page']['items'];
eforms_test_assert(
    $worker_mixed_gallery['status'] === 200
        && count( $worker_status_calls ) === 1
        && $worker_call['submission_id'] === $worker_submission_id
        && $worker_call['storage_identity'] === $worker_store_identity
        && $worker_call['expected_identity'] === $worker_store_identity
        && $worker_call['now'] === $now + 140
        && count( $worker_gallery_items ) === 3
        && array_column( $worker_gallery_items, 'upload_id' ) === array( 'worker_photo_0', 'worker_photo_1', 'worker_photo_2' )
        && array_column( $worker_gallery_items, 'ordinal' ) === array( 0, 1, 2 )
        && array_keys( $worker_gallery_items[0] ) === $worker_expected_item_fields
        && array_keys( $worker_gallery_items[1] ) === $worker_expected_item_fields
        && array_keys( $worker_gallery_items[2] ) === $worker_expected_item_fields
        && ! isset( $worker_gallery_items[0]['storage_identity'] )
        && $worker_gallery_items === WorkerProtocol::normalize_worker_gallery_items( $worker_gallery_items )
        && array_column( $worker_context_items, 'status' ) === array( 'accepted', 'pending', 'unavailable' )
        && preg_match( '#^https://example\.test/review/file/[A-Za-z0-9_-]+$#', $worker_context_items[0]['download_url'] ) === 1
        && preg_match( '#^https://example\.test/review/preview/[A-Za-z0-9_-]+$#', $worker_context_items[0]['preview_url'] ) === 1
        && $worker_context_items[0]['preview_width'] === $worker_expected_width
        && $worker_context_items[0]['preview_height'] === $worker_expected_height
        && $worker_context_items[0]['original_inline_available'] === false
        && array_keys( $worker_context_items[1] ) === array( 'status' )
        && array_keys( $worker_context_items[2] ) === array( 'status' )
        && $worker_mixed_gallery['review_page']['refresh_url'] === $worker_gallery_url,
    'Dormant candidate gallery should perform one full-manifest status read and build ordered accepted, pending, and unavailable card contexts.'
);
eforms_test_assert(
    isset( $worker_mixed_gallery['review_page']['review_facts']['groups'][0]['rows'] )
        && array_column( $worker_mixed_gallery['review_page']['review_facts']['groups'][0]['rows'], 'label' ) === array( 'Project Description', 'Square Footage' )
        && $worker_mixed_gallery['review_page']['title'] === 'Submitted Photos',
    'Anonymous Worker-owned galleries should render snapshot-backed public project details.'
);
$worker_context_json = json_encode( $worker_mixed_gallery['review_page'] );
eforms_test_assert(
    is_string( $worker_context_json )
        && strpos( $worker_context_json, 'Customer Worker' ) === false
        && strpos( $worker_context_json, $worker_store_identity ) === false
        && strpos( $worker_context_json, 'worker-version-' ) === false
        && strpos( $worker_context_json, 'worker-etag-' ) === false
        && strpos( $worker_context_json, 'storage_identity' ) === false
        && strpos( $worker_context_json, 'mime' ) === false,
    'Dormant candidate gallery context should not expose filenames, storage identity, provider locators, object versions, etags, or MIME facts.'
);
$GLOBALS['eforms_test_can_manage'] = true;
$worker_operator_gallery = ReviewController::worker_gallery_response(
    $worker_submission_id,
    $worker_uploads_dir,
    $now + 140,
    $salt,
    array(
        'base_url' => 'https://example.test',
        'worker_gallery_status' => function ( $submission_id, $storage_identity, $items ) use ( $worker_status_width, $worker_status_height ) {
            $statuses = array();
            foreach ( $items as $item ) {
                $statuses[] = array(
                    'upload_id' => $item['upload_id'],
                    'status' => 'accepted',
                    'mime' => 'image/png',
                    'width' => $worker_status_width,
                    'height' => $worker_status_height,
                );
            }
            return array( 'ok' => true, 'statuses' => $statuses );
        },
    )
);
$worker_operator_action_field = isset( $worker_operator_gallery['review_page']['operator_action_field'] ) ? $worker_operator_gallery['review_page']['operator_action_field'] : '';
$worker_delete_action = isset( $worker_operator_gallery['review_page']['delete_action'] ) ? $worker_operator_gallery['review_page']['delete_action'] : '';
$worker_delete_nonce_action = isset( $worker_operator_gallery['review_page']['delete_nonce_action'] ) ? $worker_operator_gallery['review_page']['delete_nonce_action'] : '';
$worker_delete_nonce_field = isset( $worker_operator_gallery['review_page']['delete_nonce_field'] ) ? $worker_operator_gallery['review_page']['delete_nonce_field'] : '';
$worker_availability_action = isset( $worker_operator_gallery['review_page']['availability_action'] ) ? $worker_operator_gallery['review_page']['availability_action'] : '';
$worker_operator_facts = isset( $worker_operator_gallery['review_page']['review_facts'] ) && is_array( $worker_operator_gallery['review_page']['review_facts'] )
    ? $worker_operator_gallery['review_page']['review_facts']
    : array();
$worker_operator_rows = array();
foreach ( isset( $worker_operator_facts['groups'] ) && is_array( $worker_operator_facts['groups'] ) ? $worker_operator_facts['groups'] : array() as $group ) {
    $worker_operator_rows = array_merge( $worker_operator_rows, isset( $group['rows'] ) && is_array( $group['rows'] ) ? $group['rows'] : array() );
}
eforms_test_assert(
    ! empty( $worker_operator_gallery['review_page']['can_delete'] )
        && $worker_operator_action_field !== ''
        && $worker_delete_action !== ''
        && $worker_delete_nonce_action !== ''
        && $worker_delete_nonce_field !== ''
        && $worker_availability_action !== ''
        && $worker_operator_gallery['review_page']['title'] === 'Virtual Estimate Request'
        && $worker_operator_gallery['review_page']['attribution_name'] === 'Ada Lovelace'
        && array_column( $worker_operator_rows, 'label' ) === array( 'Zip Code', 'Email', 'Phone', 'Project Description', 'Square Footage', 'Listing URL' ),
    'Logged-in operators should receive snapshot-backed lead details and the same management action contract for Worker-owned finalized galleries.'
);
$worker_availability_nonce_action = isset( $worker_operator_gallery['review_page']['availability_nonce_action'] ) ? $worker_operator_gallery['review_page']['availability_nonce_action'] : '';
$worker_availability_nonce_field = isset( $worker_operator_gallery['review_page']['availability_nonce_field'] ) ? $worker_operator_gallery['review_page']['availability_nonce_field'] : '';
$GLOBALS['eforms_test_nonce_actions'] = array(
    $worker_availability_nonce_action => 'worker-availability-valid-nonce',
);
$worker_availability_update = eforms_test_review_request(
    $worker_gallery_url,
    $worker_uploads_dir,
    $salt,
    $now + 140,
    array(
        'method' => 'POST',
        'post' => array(
            $worker_operator_action_field => $worker_availability_action,
            $worker_availability_nonce_field => 'worker-availability-valid-nonce',
            'eforms_review_availability' => '90_days',
        ),
        'worker_gallery_status' => function ( $submission_id, $storage_identity, $items ) use ( $worker_status_width, $worker_status_height ) {
            $statuses = array();
            foreach ( $items as $item ) {
                $statuses[] = array(
                    'upload_id' => $item['upload_id'],
                    'status' => 'accepted',
                    'mime' => 'image/png',
                    'width' => $worker_status_width,
                    'height' => $worker_status_height,
                );
            }
            return array( 'ok' => true, 'statuses' => $statuses );
        },
    )
);
$worker_after_availability = UploadBatchStore::worker_submission( $worker_submission_id, $worker_uploads_dir, $now + 140 );
eforms_test_assert(
    $worker_availability_update['status'] === 200
        && ! empty( $worker_after_availability['ok'] )
        && $worker_after_availability['submission']['delete_after'] === $now + 140 + Anchors::get( 'MANAGED_AVAILABILITY_90_DAYS_SECONDS' )
        && count( $worker_availability_update['review_page']['items'] ) === 3,
    'Worker operator availability updates should persist through the schema-7 manifest and return the Worker gallery renderer.'
);
unset( $GLOBALS['eforms_test_nonce_actions'] );
$GLOBALS['eforms_test_can_manage'] = false;
eforms_test_assert( ReviewController::enable_lightbox_for_current_review( false, 0 ) === true, 'Dormant candidate gallery should enable lightbox only after an accepted preview-capable card exists.' );
$worker_mixed_html = eforms_test_render_review_template( $worker_mixed_gallery['review_page'] );
eforms_test_assert(
    strpos( $worker_mixed_html, 'Open Photo 1' ) !== false
        && strpos( $worker_mixed_html, 'Download Photo 1' ) !== false
        && strpos( $worker_mixed_html, 'data-eforms-review-src="' . $worker_context_items[0]['preview_url'] . '"' ) !== false
        && strpos( $worker_mixed_html, 'data-lbwps-width="' . $worker_expected_width . '"' ) !== false
        && strpos( $worker_mixed_html, 'data-lbwps-height="' . $worker_expected_height . '"' ) !== false
        && strpos( $worker_mixed_html, '>Processing</span>' ) !== false
        && strpos( $worker_mixed_html, '>Photo unavailable</span>' ) !== false
        && strpos( $worker_mixed_html, 'Open Photo 2' ) === false
        && strpos( $worker_mixed_html, 'Download Photo 2' ) === false
        && strpos( $worker_mixed_html, 'Photo 2 preview' ) === false
        && strpos( $worker_mixed_html, 'Open Photo 3' ) === false
        && strpos( $worker_mixed_html, 'Download Photo 3' ) === false
        && strpos( $worker_mixed_html, 'Photo 3 preview' ) === false
        && substr_count( $worker_mixed_html, ' data-eforms-review-preview>' ) === 1
        && substr_count( $worker_mixed_html, 'data-eforms-review-retry' ) === 1
        && substr_count( $worker_mixed_html, 'data-eforms-review-original' ) === 0
        && substr_count( $worker_mixed_html, '>Refresh gallery</a>' ) === 1
        && strpos( $worker_mixed_html, 'href="' . $worker_gallery_url . '">Refresh gallery</a>' ) !== false,
    'Dormant candidate gallery template should render accepted controls, pending/unavailable copy, and one manual refresh link.'
);
eforms_test_assert(
    strpos( $worker_mixed_html, 'Customer Worker' ) === false
        && strpos( $worker_mixed_html, $worker_store_identity ) === false
        && strpos( $worker_mixed_html, 'worker-version-' ) === false
        && strpos( $worker_mixed_html, 'worker-etag-' ) === false
        && stripos( $worker_mixed_html, 'storage' ) === false
        && stripos( $worker_mixed_html, 'provider' ) === false,
    'Dormant candidate gallery template should not render filenames, storage identities, provider language, object versions, or etags.'
);
$worker_failure_calls = 0;
$worker_failure_gallery = ReviewController::worker_gallery_response(
    $worker_submission_id,
    $worker_uploads_dir,
    $now + 141,
    $salt,
    array(
        'base_url' => 'https://example.test',
        'worker_gallery_status' => function () use ( &$worker_failure_calls ) {
            $worker_failure_calls++;
            return array( 'ok' => false, 'reason' => 'status_unavailable' );
        },
    )
);
eforms_test_assert(
    $worker_failure_calls === 1
        && $worker_failure_gallery['status'] === 503
        && $worker_failure_gallery['headers']['Cache-Control'] === 'private, no-store, max-age=0'
        && $worker_failure_gallery['headers']['X-Robots-Tag'] === 'noindex, nofollow'
        && ! empty( $worker_failure_gallery['review_page']['status_unavailable'] )
        && $worker_failure_gallery['review_page']['items'] === array()
        && $worker_failure_gallery['review_page']['refresh_url'] === $worker_gallery_url
        && empty( $worker_failure_gallery['review_page']['can_delete'] )
        && ! isset( $worker_failure_gallery['review_page']['operator_action_url'] )
        && ! isset( $worker_failure_gallery['review_page']['download_url'] )
        && ! isset( $worker_failure_gallery['review_page']['preview_url'] )
        && ReviewController::enable_lightbox_for_current_review( false, 0 ) === false,
    'Dormant candidate gallery should fail whole status reads as private 503 without partial items, controls, or lightbox state.'
);
$GLOBALS['eforms_test_can_manage'] = true;
$worker_operator_failure_gallery = ReviewController::worker_gallery_response(
    $worker_submission_id,
    $worker_uploads_dir,
    $now + 141,
    $salt,
    array(
        'base_url' => 'https://example.test',
        'worker_gallery_status' => function () {
            return array( 'ok' => false, 'reason' => 'status_unavailable' );
        },
    )
);
eforms_test_assert(
    $worker_operator_failure_gallery['status'] === 503
        && ! empty( $worker_operator_failure_gallery['review_page']['status_unavailable'] )
        && ! empty( $worker_operator_failure_gallery['review_page']['can_delete'] )
        && isset( $worker_operator_failure_gallery['review_page']['operator_action_url'] )
        && isset( $worker_operator_failure_gallery['review_page']['delete_action'] )
        && isset( $worker_operator_failure_gallery['review_page']['availability_action'] ),
    'Logged-in operators should retain whole-submission management controls when a Worker gallery status read is unavailable.'
);
$GLOBALS['eforms_test_can_manage'] = false;
$worker_failure_html = eforms_test_render_review_template( $worker_failure_gallery['review_page'] );
eforms_test_assert(
    strpos( $worker_failure_html, 'Gallery unavailable.' ) !== false
        && substr_count( $worker_failure_html, '>Refresh gallery</a>' ) === 1
        && strpos( $worker_failure_html, 'href="' . $worker_gallery_url . '">Refresh gallery</a>' ) !== false
        && strpos( $worker_failure_html, 'eforms-review-grid' ) === false
        && strpos( $worker_failure_html, '<figure' ) === false
        && strpos( $worker_failure_html, '/review/file/' ) === false
        && strpos( $worker_failure_html, '/review/preview/' ) === false
        && strpos( $worker_failure_html, 'Open Photo ' ) === false
        && strpos( $worker_failure_html, 'Download Photo ' ) === false
        && strpos( $worker_failure_html, ' data-eforms-review-preview>' ) === false
        && strpos( $worker_failure_html, 'data-eforms-review-retry' ) === false
        && strpos( $worker_failure_html, 'data-eforms-review-original' ) === false
        && strpos( $worker_failure_html, 'eforms-review-facts' ) === false
        && strpos( $worker_failure_html, 'Customer Worker' ) === false
        && strpos( $worker_failure_html, $worker_store_identity ) === false
        && strpos( $worker_failure_html, 'worker-version-' ) === false
        && strpos( $worker_failure_html, 'worker-etag-' ) === false,
    'Dormant candidate gallery failure template should render only unavailable copy and a manual refresh link.'
);
$worker_live_status_called = false;
$worker_live_dispatch = eforms_test_review_request(
    $worker_gallery_url,
    $worker_uploads_dir,
    $salt,
    $now + 142,
    array(
        'worker_gallery_status' => function ( $submission_id, $storage_identity, $items ) use ( &$worker_live_status_called, $worker_status_width, $worker_status_height ) {
            $worker_live_status_called = true;
            $statuses = array();
            foreach ( $items as $item ) {
                $statuses[] = array(
                    'upload_id' => $item['upload_id'],
                    'status' => 'accepted',
                    'mime' => 'image/png',
                    'width' => $worker_status_width,
                    'height' => $worker_status_height,
                );
            }
            return array( 'ok' => true, 'statuses' => $statuses );
        },
    )
);
eforms_test_assert(
    $worker_live_dispatch['status'] === 200
        && $worker_live_status_called
        && ReviewController::enable_lightbox_for_current_review( false, 0 ) === true,
    'Live review dispatch should select the schema-7 candidate status path.'
);
$worker_expire_seed = $now + 144;
eforms_test_assert(
    ! empty( UploadBatchStore::update_finalized_availability( $worker_submission_id, $worker_uploads_dir, $worker_expire_seed + 1, $worker_expire_seed )['ok'] ),
    'The expired Worker management fixture should set a near-term numeric availability through the schema-7 manifest.'
);
$GLOBALS['eforms_test_can_manage'] = false;
$worker_expired_anonymous = eforms_test_review_request( $worker_gallery_url, $worker_uploads_dir, $salt, $worker_expire_seed + 2 );
$GLOBALS['eforms_test_can_manage'] = true;
$worker_expired_operator = eforms_test_review_request( $worker_gallery_url, $worker_uploads_dir, $salt, $worker_expire_seed + 2 );
eforms_test_assert(
    $worker_expired_anonymous['status'] === 404
        && $worker_expired_operator['status'] === 200
        && ! empty( $worker_expired_operator['review_page']['expired'] )
        && ! empty( $worker_expired_operator['review_page']['can_delete'] )
        && isset( $worker_expired_operator['review_page']['operator_action_url'] )
        && ! isset( $worker_expired_operator['review_page']['availability_action'] )
        && empty( $worker_expired_operator['review_page']['items'] ),
    'Expired Worker-owned galleries should remain private to anonymous viewers while preserving operator deletion management before GC.'
);
$GLOBALS['eforms_test_can_manage'] = true;
$GLOBALS['eforms_test_nonce_actions'] = array(
    $worker_delete_nonce_action => 'worker-delete-valid-nonce',
);
$worker_deleted_authorities = array();
$worker_delete_response = eforms_test_review_request(
    $worker_gallery_url,
    $worker_uploads_dir,
    $salt,
    $worker_expire_seed + 2,
    array(
        'method' => 'POST',
        'post' => array(
            $worker_operator_action_field => $worker_delete_action,
            $worker_delete_nonce_field => 'worker-delete-valid-nonce',
        ),
        'remote_delete' => function ( $authority ) use ( &$worker_deleted_authorities ) {
            $worker_deleted_authorities[] = $authority;
            return array( 'ok' => true, 'absent' => true );
        },
    )
);
$worker_submission_path = $worker_uploads_dir . '/eforms-private/' . UploadBatchStore::SUBMISSIONS_DIR . '/' . Helpers::h2( $worker_submission_id ) . '/' . $worker_submission_id;
$worker_manifest_after_delete = json_decode( file_get_contents( $worker_submission_path . '/' . UploadBatchStore::MANIFEST_FILENAME ), true );
eforms_test_assert(
    $worker_delete_response['status'] === 200
        && ! empty( $worker_delete_response['result']['deleted'] )
        && ! empty( $worker_delete_response['result']['physical_delete_pending'] )
        && is_dir( $worker_submission_path )
        && count( $worker_deleted_authorities ) === 0
        && is_array( $worker_manifest_after_delete )
        && $worker_manifest_after_delete['state'] === 'operator_deleted'
        && $worker_manifest_after_delete['items'] === array()
        && count( $worker_manifest_after_delete['tombstones'] ) === 3
        && $worker_manifest_after_delete['delete_after'] > $worker_expire_seed + 2
        && empty( UploadBatchStore::worker_submission( $worker_submission_id, $worker_uploads_dir, $worker_expire_seed + 2 )['ok'] )
        && eforms_test_review_request( $worker_gallery_url, $worker_uploads_dir, $salt, $worker_expire_seed + 2 )['status'] === 404,
    'Worker operator deletion should immediately hide the finalized gallery while retaining exact tombstones for deferred drain-safe cleanup.'
);
$worker_gc_deleted_authorities = array();
$worker_gc_first = UploadBatchStore::gc_aggregates(
    'finalized',
    $worker_uploads_dir,
    $worker_manifest_after_delete['delete_after'],
    10,
    false,
    array(),
    function ( $authority ) use ( &$worker_gc_deleted_authorities ) {
        $worker_gc_deleted_authorities[] = $authority;
        return array( 'ok' => true, 'absent' => true );
    }
);
$worker_gc_second = UploadBatchStore::gc_aggregates(
    'finalized',
    $worker_uploads_dir,
    $worker_manifest_after_delete['delete_after'],
    10,
    false,
    array(),
    function () {
        return array( 'ok' => false, 'absent' => false );
    }
);
eforms_test_assert(
    ! empty( $worker_gc_first['ok'] )
        && ! empty( $worker_gc_second['ok'] )
        && $worker_gc_first['deleted'] === 0
        && $worker_gc_second['deleted'] === 1
        && ! is_dir( $worker_submission_path )
        && count( $worker_gc_deleted_authorities ) === 3
        && array_column( $worker_gc_deleted_authorities, 'upload_id' ) === array( 'worker_photo_0', 'worker_photo_1', 'worker_photo_2' )
        && array_unique( array_column( $worker_gc_deleted_authorities, 'storage_identity' ) ) === array( $worker_store_identity )
        && array_unique( array_column( $worker_gc_deleted_authorities, 'validation_contract_version' ) ) === array( 'validation-v1' ),
    'Deferred Worker cleanup should drain exact remote authorities after the operator-delete safety point and remove the retained aggregate on the follow-up pass.'
);
unset( $GLOBALS['eforms_test_nonce_actions'] );
$GLOBALS['eforms_test_can_manage'] = false;
eforms_test_remove_tree( $worker_uploads_dir );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$worker_max_uploads_dir = eforms_test_setup_uploads( 'eforms-review-worker-gallery-max' );
$worker_max_count = Anchors::get( 'MANAGED_STAGED_MAX_FILES' );
$worker_max_field = $field;
$worker_max_field['max_files'] = $worker_max_count;
$worker_max_binding = $binding;
$worker_max_binding['raw_token'] = 'worker-review-gallery-max-token';
$worker_max_binding['instance_id'] = 'worker-review-gallery-max-instance';
$worker_max_binding['accept_until'] = $now + 900;
$worker_max_submission_id = '123e4567-e89b-12d3-a456-426614174005';
eforms_test_review_worker_submission(
    $worker_max_uploads_dir,
    $secret,
    $worker_max_field,
    $worker_max_binding,
    $worker_max_submission_id,
    $worker_store_identity,
    $now + 150,
    $worker_max_count
);
$worker_max_gallery_url = ReviewController::gallery_url( $worker_max_submission_id, 'https://example.test', $salt );
$worker_max_calls = array();
$worker_max_gallery = ReviewController::worker_gallery_response(
    $worker_max_submission_id,
    $worker_max_uploads_dir,
    $now + 190,
    $salt,
    array(
        'base_url' => 'https://example.test',
        'worker_gallery_status' => function ( $submission_id, $storage_identity, $items, $expected_identity, $called_now ) use ( &$worker_max_calls ) {
            $statuses = array();
            foreach ( $items as $item ) {
                $statuses[] = array( 'upload_id' => $item['upload_id'], 'status' => 'pending' );
            }
            $worker_max_calls[] = array(
                'submission_id' => $submission_id,
                'storage_identity' => $storage_identity,
                'items' => $items,
                'expected_identity' => $expected_identity,
                'now' => $called_now,
                'statuses' => $statuses,
            );
            return array( 'ok' => true, 'statuses' => $statuses );
        },
    )
);
$worker_max_call = $worker_max_calls[0];
eforms_test_assert(
    $worker_max_gallery['status'] === 200
        && count( $worker_max_calls ) === 1
        && $worker_max_call['submission_id'] === $worker_max_submission_id
        && $worker_max_call['storage_identity'] === $worker_store_identity
        && $worker_max_call['expected_identity'] === $worker_store_identity
        && count( $worker_max_call['items'] ) === $worker_max_count
        && array_column( $worker_max_call['items'], 'ordinal' ) === range( 0, $worker_max_count - 1 )
        && array_column( $worker_max_call['items'], 'upload_id' ) === array_column( $worker_max_call['statuses'], 'upload_id' )
        && count( $worker_max_gallery['review_page']['items'] ) === $worker_max_count
        && array_unique( array_column( $worker_max_gallery['review_page']['items'], 'status' ) ) === array( 'pending' )
        && $worker_max_gallery['review_page']['refresh_url'] === $worker_max_gallery_url
        && ReviewController::enable_lightbox_for_current_review( false, 0 ) === false,
    'Dormant candidate gallery should handle the max item count with one ordered full-manifest status call.'
);
eforms_test_remove_tree( $worker_max_uploads_dir );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$worker_member_uploads_dir = eforms_test_setup_uploads( 'eforms-review-worker-member' );
$worker_member_binding = $binding;
$worker_member_binding['raw_token'] = 'worker-review-member-token';
$worker_member_binding['instance_id'] = 'worker-review-member-instance';
$worker_member_binding['accept_until'] = $now + 1200;
$worker_member_submission_id = '123e4567-e89b-12d3-a456-426614174006';
$worker_member_submission = eforms_test_review_worker_submission(
    $worker_member_uploads_dir,
    $secret,
    $worker_field,
    $worker_member_binding,
    $worker_member_submission_id,
    $worker_store_identity,
    $now + 200
);
$worker_member_download_item = $worker_member_submission['items'][0];
$worker_member_preview_item = $worker_member_submission['items'][1];
$worker_member_now = $worker_member_submission['delete_after'] - 10;
$worker_member_claims = array();
$worker_member_gallery_status_called = false;
$worker_member_signer = function ( $claims, $expected_identity, $called_now ) use ( &$worker_member_claims, $worker_store_identity, $worker_member_now ) {
    $worker_member_claims[] = array(
        'claims' => $claims,
        'expected_identity' => $expected_identity,
        'now' => $called_now,
    );
    return 'https://media.example.test/v1/review?grant=' . rawurlencode( $claims['action'] . '-worker-member' );
};
$worker_member_overrides = array(
    'worker_review_url' => $worker_member_signer,
    'worker_gallery_status' => function () use ( &$worker_member_gallery_status_called ) {
        $worker_member_gallery_status_called = true;
        return array( 'ok' => false );
    },
);
$worker_file_response = ReviewController::worker_file_response(
    $worker_member_submission_id,
    $worker_member_download_item['upload_id'],
    $worker_member_uploads_dir,
    $worker_member_now,
    $worker_member_overrides
);
$worker_preview_response = ReviewController::worker_preview_response(
    $worker_member_submission_id,
    $worker_member_preview_item['upload_id'],
    $worker_member_uploads_dir,
    $worker_member_now,
    $worker_member_overrides
);
$worker_expected_review_fields = array(
    'submission_id',
    'upload_id',
    'storage_identity',
    'validation_contract_version',
    'object_key',
    'object_version',
    'etag',
    'bytes',
    'policy_fingerprint',
    'validation_until',
    'action',
    'recipe_version',
    'expires_at',
);
$worker_download_claims = $worker_member_claims[0]['claims'];
$worker_preview_claims = $worker_member_claims[1]['claims'];
$worker_expected_expiry = min(
    $worker_member_now + Anchors::get( 'WORKER_REVIEW_GRANT_TTL_SECONDS' ),
    $worker_member_submission['delete_after']
);
eforms_test_assert(
    $worker_member_now > $worker_member_download_item['validation_until']
        && $worker_member_now > $worker_member_preview_item['validation_until']
        && count( $worker_member_claims ) === 2
        && ! $worker_member_gallery_status_called
        && $worker_file_response['handled'] === true
        && $worker_file_response['render'] === 'review_file'
        && $worker_file_response['status'] === 302
        && $worker_file_response['location'] === 'https://media.example.test/v1/review?grant=download-worker-member'
        && $worker_file_response['redirect_origin'] === 'https://media.example.test'
        && $worker_file_response['body'] === ''
        && $worker_file_response['headers']['Cache-Control'] === 'private, no-store, max-age=0'
        && $worker_file_response['headers']['X-Robots-Tag'] === 'noindex, nofollow'
        && ! empty( $worker_file_response['result']['ok'] )
        && $worker_preview_response['handled'] === true
        && $worker_preview_response['render'] === 'review_file'
        && $worker_preview_response['status'] === 302
        && $worker_preview_response['location'] === 'https://media.example.test/v1/review?grant=preview-worker-member'
        && $worker_preview_response['redirect_origin'] === 'https://media.example.test'
        && $worker_preview_response['body'] === ''
        && $worker_preview_response['headers']['Cache-Control'] === 'private, no-store, max-age=0'
        && ! empty( $worker_preview_response['result']['ok'] ),
    'Dormant candidate member routes should mint private redirects after validation_until without consulting gallery status.'
);
eforms_test_assert(
    $worker_member_claims[0]['expected_identity'] === $worker_store_identity
        && $worker_member_claims[1]['expected_identity'] === $worker_store_identity
        && $worker_member_claims[0]['now'] === $worker_member_now
        && $worker_member_claims[1]['now'] === $worker_member_now
        && array_keys( $worker_download_claims ) === $worker_expected_review_fields
        && array_keys( $worker_preview_claims ) === $worker_expected_review_fields
        && $worker_download_claims['submission_id'] === $worker_member_submission_id
        && $worker_download_claims['upload_id'] === $worker_member_download_item['upload_id']
        && $worker_download_claims['storage_identity'] === $worker_store_identity
        && $worker_download_claims['validation_contract_version'] === $worker_member_download_item['validation_contract_version']
        && $worker_download_claims['object_key'] === $worker_member_download_item['object_key']
        && $worker_download_claims['object_version'] === $worker_member_download_item['object_version']
        && $worker_download_claims['etag'] === $worker_member_download_item['etag']
        && $worker_download_claims['bytes'] === $worker_member_download_item['bytes']
        && $worker_download_claims['policy_fingerprint'] === $worker_member_download_item['policy_fingerprint']
        && $worker_download_claims['validation_until'] === $worker_member_download_item['validation_until']
        && $worker_download_claims['action'] === 'download'
        && $worker_download_claims['recipe_version'] === WorkerProtocol::REVIEW_RECIPE_VERSION
        && $worker_download_claims['expires_at'] === $worker_expected_expiry
        && $worker_preview_claims['submission_id'] === $worker_member_submission_id
        && $worker_preview_claims['upload_id'] === $worker_member_preview_item['upload_id']
        && $worker_preview_claims['storage_identity'] === $worker_store_identity
        && $worker_preview_claims['validation_contract_version'] === $worker_member_preview_item['validation_contract_version']
        && $worker_preview_claims['object_key'] === $worker_member_preview_item['object_key']
        && $worker_preview_claims['object_version'] === $worker_member_preview_item['object_version']
        && $worker_preview_claims['etag'] === $worker_member_preview_item['etag']
        && $worker_preview_claims['bytes'] === $worker_member_preview_item['bytes']
        && $worker_preview_claims['policy_fingerprint'] === $worker_member_preview_item['policy_fingerprint']
        && $worker_preview_claims['validation_until'] === $worker_member_preview_item['validation_until']
        && $worker_preview_claims['action'] === 'preview'
        && $worker_preview_claims['recipe_version'] === WorkerProtocol::REVIEW_RECIPE_VERSION
        && $worker_preview_claims['expires_at'] === $worker_expected_expiry
        && $worker_expected_expiry === $worker_member_submission['delete_after']
        && $worker_expected_expiry <= $worker_member_now + Anchors::get( 'WORKER_REVIEW_GRANT_TTL_SECONDS' )
        && ! isset(
            $worker_download_claims['mime'],
            $worker_download_claims['width'],
            $worker_download_claims['height'],
            $worker_download_claims['display_name'],
            $worker_preview_claims['mime'],
            $worker_preview_claims['width'],
            $worker_preview_claims['height'],
            $worker_preview_claims['display_name']
        ),
    'Dormant candidate member grants should bind exact schema-7 item authority and validation deadline without media or filename facts.'
);
$worker_negative_signer_calls = 0;
$worker_negative_overrides = array(
    'worker_review_url' => function () use ( &$worker_negative_signer_calls ) {
        $worker_negative_signer_calls++;
        return 'https://media.example.test/v1/review?grant=unexpected';
    },
);
$worker_unknown_file = ReviewController::worker_file_response(
    $worker_member_submission_id,
    'unknown_worker_photo',
    $worker_member_uploads_dir,
    $worker_member_now,
    $worker_negative_overrides
);
$worker_unknown_preview = ReviewController::worker_preview_response(
    $worker_member_submission_id,
    'unknown_worker_photo',
    $worker_member_uploads_dir,
    $worker_member_now,
    $worker_negative_overrides
);
$worker_expired_file = ReviewController::worker_file_response(
    $worker_member_submission_id,
    $worker_member_download_item['upload_id'],
    $worker_member_uploads_dir,
    $worker_member_submission['delete_after'],
    $worker_negative_overrides
);
$worker_expired_preview = ReviewController::worker_preview_response(
    $worker_member_submission_id,
    $worker_member_preview_item['upload_id'],
    $worker_member_uploads_dir,
    $worker_member_submission['delete_after'],
    $worker_negative_overrides
);
$worker_live_member_signer_called = false;
$worker_live_file_url = ReviewController::file_url( $worker_member_submission_id, $worker_member_download_item['upload_id'], 'https://example.test', $salt );
$worker_live_preview_url = ReviewController::preview_url( $worker_member_submission_id, $worker_member_preview_item['upload_id'], 'https://example.test', $salt );
$worker_live_file_dispatch = eforms_test_review_request(
    $worker_live_file_url,
    $worker_member_uploads_dir,
    $salt,
    $worker_member_now,
    array(
        'worker_review_url' => function () use ( &$worker_live_member_signer_called ) {
            $worker_live_member_signer_called = true;
            return 'https://media.example.test/v1/review?grant=unexpected';
        },
    )
);
$worker_live_preview_dispatch = eforms_test_review_request(
    $worker_live_preview_url,
    $worker_member_uploads_dir,
    $salt,
    $worker_member_now,
    array(
        'worker_review_url' => function () use ( &$worker_live_member_signer_called ) {
            $worker_live_member_signer_called = true;
            return 'https://media.example.test/v1/review?grant=unexpected';
        },
    )
);
eforms_test_assert(
    $worker_negative_signer_calls === 0
        && $worker_unknown_file['status'] === 404
        && $worker_unknown_preview['status'] === 404
        && $worker_expired_file['status'] === 404
        && $worker_expired_preview['status'] === 404
        && $worker_unknown_file['location'] === ''
        && $worker_unknown_preview['location'] === ''
	        && $worker_expired_file['location'] === ''
	        && $worker_expired_preview['location'] === ''
	        && $worker_live_file_dispatch['status'] === 302
	        && $worker_live_preview_dispatch['status'] === 302
	        && $worker_live_member_signer_called,
	    'Worker member requests should deny absent items and delete_after equality, then select candidate URL signing for live dispatch.'
	);
eforms_test_remove_tree( $worker_member_uploads_dir );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$worker_foreign_uploads_dir = eforms_test_setup_uploads( 'eforms-review-worker-member-foreign' );
$worker_foreign_identity = str_repeat( 'f', 64 );
$worker_foreign_binding = $binding;
$worker_foreign_binding['raw_token'] = 'worker-review-member-foreign-token';
$worker_foreign_binding['instance_id'] = 'worker-review-member-foreign-instance';
$worker_foreign_binding['accept_until'] = $now + 1300;
$worker_foreign_submission_id = '123e4567-e89b-12d3-a456-426614174007';
$worker_foreign_submission = eforms_test_review_worker_submission(
    $worker_foreign_uploads_dir,
    $secret,
    $worker_field,
    $worker_foreign_binding,
    $worker_foreign_submission_id,
    $worker_foreign_identity,
    $now + 230
);
$worker_foreign_signer_calls = 0;
$worker_foreign_file = ReviewController::worker_file_response(
    $worker_foreign_submission_id,
    $worker_foreign_submission['items'][0]['upload_id'],
    $worker_foreign_uploads_dir,
    $now + 260,
    array(
        'worker_review_url' => function () use ( &$worker_foreign_signer_calls ) {
            $worker_foreign_signer_calls++;
            return 'https://media.example.test/v1/review?grant=unexpected';
        },
    )
);
$worker_foreign_preview = ReviewController::worker_preview_response(
    $worker_foreign_submission_id,
    $worker_foreign_submission['items'][1]['upload_id'],
    $worker_foreign_uploads_dir,
    $now + 260,
    array(
        'worker_review_url' => function () use ( &$worker_foreign_signer_calls ) {
            $worker_foreign_signer_calls++;
            return 'https://media.example.test/v1/review?grant=unexpected';
        },
    )
);
$worker_foreign_before_availability = UploadBatchStore::worker_submission( $worker_foreign_submission_id, $worker_foreign_uploads_dir, $now + 260 );
$worker_foreign_availability_action = 'eforms_review_availability_' . $worker_foreign_submission_id;
$GLOBALS['eforms_test_can_manage'] = true;
$GLOBALS['eforms_test_nonce_actions'] = array(
    $worker_foreign_availability_action => 'worker-foreign-availability-nonce',
);
$worker_foreign_availability = eforms_test_review_request(
    ReviewController::gallery_url( $worker_foreign_submission_id, 'https://example.test', $salt ),
    $worker_foreign_uploads_dir,
    $salt,
    $now + 260,
    array(
        'method' => 'POST',
        'post' => array(
            'eforms_review_action' => 'update_availability',
            '_eforms_review_availability_nonce' => 'worker-foreign-availability-nonce',
            'eforms_review_availability' => '90_days',
        ),
    )
);
$worker_foreign_after_availability = UploadBatchStore::worker_submission( $worker_foreign_submission_id, $worker_foreign_uploads_dir, $now + 260 );
$worker_foreign_path = $worker_foreign_uploads_dir . '/eforms-private/' . UploadBatchStore::SUBMISSIONS_DIR . '/' . Helpers::h2( $worker_foreign_submission_id ) . '/' . $worker_foreign_submission_id;
$worker_foreign_before_delete_manifest = file_get_contents( $worker_foreign_path . '/' . UploadBatchStore::MANIFEST_FILENAME );
$GLOBALS['eforms_test_nonce_actions'] = array(
    'eforms_review_delete_' . $worker_foreign_submission_id => 'worker-foreign-delete-nonce',
);
$worker_foreign_delete = eforms_test_review_request(
    ReviewController::gallery_url( $worker_foreign_submission_id, 'https://example.test', $salt ),
    $worker_foreign_uploads_dir,
    $salt,
    $now + 260,
    array(
        'method' => 'POST',
        'post' => array(
            'eforms_review_action' => 'delete_submission',
            '_eforms_review_delete_nonce' => 'worker-foreign-delete-nonce',
        ),
        'remote_delete' => function () {
            return array( 'ok' => false, 'reason' => 'unexpected_remote_delete' );
        },
    )
);
$worker_foreign_after_delete_manifest = file_get_contents( $worker_foreign_path . '/' . UploadBatchStore::MANIFEST_FILENAME );
$GLOBALS['eforms_test_can_manage'] = false;
unset( $GLOBALS['eforms_test_nonce_actions'] );
eforms_test_assert(
    $worker_foreign_identity !== $worker_store_identity
        && $worker_foreign_signer_calls === 0
        && $worker_foreign_file['status'] === 404
        && $worker_foreign_preview['status'] === 404
        && $worker_foreign_availability['status'] === 404
        && $worker_foreign_delete['status'] === 404
        && ! empty( $worker_foreign_before_availability['ok'] )
        && ! empty( $worker_foreign_after_availability['ok'] )
        && $worker_foreign_after_availability['submission']['delete_after'] === $worker_foreign_before_availability['submission']['delete_after']
        && $worker_foreign_after_delete_manifest === $worker_foreign_before_delete_manifest
        && $worker_foreign_file['location'] === ''
        && $worker_foreign_preview['location'] === '',
    'Dormant candidate review actions should deny schema-7 submissions whose artifact-store identity differs from the current Worker composition before signing or availability mutation.'
);
eforms_test_remove_tree( $worker_foreign_uploads_dir );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

$tampered_gallery_url = substr( $gallery_url, 0, -1 ) . ( substr( $gallery_url, -1 ) === 'A' ? 'B' : 'A' );
$generic = eforms_test_review_unavailable_shape(
    eforms_test_review_request( $tampered_gallery_url, $uploads_dir, $salt, $now + 30 )
);
eforms_test_assert( $generic['status'] === 404, 'Invalid review requests should return a generic not-found response.' );

$unknown_query = $review_item['download_url'] . '?unexpected=1';
$gallery_token = basename( (string) parse_url( $gallery_url, PHP_URL_PATH ) );
$action_confusion = eforms_test_review_request( 'https://example.test/review/file/' . $gallery_token, $uploads_dir, $salt, $now + 30 );
$wrong_salt = eforms_test_review_request( $gallery_url, $uploads_dir, 'rotated-review-auth-salt', $now + 30 );
$noncanonical_token = eforms_test_review_request( $gallery_url . '=', $uploads_dir, $salt, $now + 30 );
$trailing_slash_alias = eforms_test_review_request( $gallery_url . '/', $uploads_dir, $salt, $now + 30 );
$maximum_review_token_chars = intdiv( ( 1 + Anchors::get( 'MANAGED_SUBMISSION_UUID_BYTES' ) + 1 + Anchors::get( 'MANAGED_ID_MAX_CHARS' ) + Anchors::get( 'MANAGED_REVIEW_TAG_BYTES' ) ) * 8 + 5, 6 );
$oversized_token = eforms_test_review_request( 'https://example.test/review/' . str_repeat( 'A', $maximum_review_token_chars + 1 ), $uploads_dir, $salt, $now + 30 );
$expired_anonymous = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $delete_after );
$foreign_upload_url = ReviewController::file_url( $submission_id, 'foreign_photo', 'https://example.test', $salt );
$foreign = eforms_test_review_request( $foreign_upload_url, $uploads_dir, $salt, $now + 30 );
$traversal = eforms_test_review_request(
    'https://example.test/review/file/..',
    $uploads_dir,
    $salt,
    $now + 30
);
foreach ( array( $unknown_query, $action_confusion, $wrong_salt, $noncanonical_token, $trailing_slash_alias, $oversized_token, $expired_anonymous, $foreign, $traversal ) as $denied ) {
    $response = is_string( $denied ) ? eforms_test_review_request( $denied, $uploads_dir, $salt, $now + 30 ) : $denied;
    eforms_test_assert( eforms_test_review_unavailable_shape( $response ) === $generic, 'Malformed, confused, expired, foreign, and path-like grants should be indistinguishable.' );
}

$submission_path = $uploads_dir . '/eforms-private/submissions/' . Helpers::h2( $submission_id ) . '/' . $submission_id;
$submission_manifest_path = $submission_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
$submission_manifest = json_decode( file_get_contents( $submission_manifest_path ), true );
$unknown_claim_manifest = $submission_manifest;
$unknown_claim_manifest['claim']['provider_url'] = 'https://objects.example.invalid/private';
file_put_contents( $submission_manifest_path, json_encode( $unknown_claim_manifest ) );
$unknown_claim_gallery = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30 );
eforms_test_assert( eforms_test_review_unavailable_shape( $unknown_claim_gallery ) === $generic, 'Review should reject a finalized manifest with an unknown claim field.' );
file_put_contents( $submission_manifest_path, json_encode( $submission_manifest ) );
if ( function_exists( 'symlink' ) ) {
    $linked_submission_id = '123e4567-e89b-12d3-a456-426614174001';
    $linked_submission_path = $uploads_dir . '/eforms-private/submissions/' . Helpers::h2( $linked_submission_id ) . '/' . $linked_submission_id;
    mkdir( dirname( $linked_submission_path ), 0700, true );
    symlink( $submission_path, $linked_submission_path );
    $linked_submission = UploadBatchStore::submission( $linked_submission_id, $uploads_dir, $now + 30 );
    eforms_test_assert( empty( $linked_submission['ok'] ) && $linked_submission['reason'] === 'submission_unavailable', 'Finalized aggregate reads should reject symlinked submission directories.' );
    @unlink( $linked_submission_path );
}
$mismatched_manifest = $submission_manifest;
$mismatched_manifest['claim']['submission_id'] = '123e4567-e89b-12d3-a456-426614174099';
file_put_contents( $submission_manifest_path, json_encode( $mismatched_manifest ) );
$mismatched_gallery = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30 );
eforms_test_assert( eforms_test_review_unavailable_shape( $mismatched_gallery ) === $generic, 'A manifest that does not belong to the requested submission directory must fail closed.' );
file_put_contents( $submission_manifest_path, json_encode( $submission_manifest ) );

$artifact_item = $submission_manifest['items']['review_photo'];
$artifact_path = LocalArtifactStore::locate( $uploads_dir, $artifact_item['object_key'], $artifact_item['object_version'] );
eforms_test_assert( is_file( $artifact_path ), 'The finalized manifest should resolve its authoritative local artifact.' );
$saved_artifact = file_get_contents( $artifact_path );
$manifest_before_failed_review = file_get_contents( $submission_manifest_path );
eforms_test_assert( unlink( $artifact_path ), 'The missing-artifact review fixture should remove only the physical artifact.' );
$missing_artifact = eforms_test_review_request( $review_item['download_url'], $uploads_dir, $salt, $now + 30 );
eforms_test_assert( eforms_test_review_unavailable_shape( $missing_artifact ) === $generic, 'A missing artifact should use the generic unavailable response.' );
eforms_test_assert( file_get_contents( $submission_manifest_path ) === $manifest_before_failed_review, 'Review delivery failure must not mutate authoritative submission state.' );
file_put_contents( $artifact_path, $saved_artifact );
chmod( $artifact_path, 0600 );

if ( function_exists( 'symlink' ) ) {
    $outside_artifact = eforms_test_write_file( $uploads_dir, 'outside-artifact.png', 'outside-artifact' );
    eforms_test_assert( is_file( $artifact_path ) && unlink( $artifact_path ) && symlink( $outside_artifact, $artifact_path ), 'The finalized artifact symlink fixture should replace only the manifest member.' );
    $linked_artifact = UploadBatchStore::submission_file( $submission_id, 'review_photo', $uploads_dir, $now + 30 );
    eforms_test_assert( empty( $linked_artifact['ok'] ) && $linked_artifact['reason'] === 'file_missing', 'Finalized reads should reject a symlinked authoritative artifact.' );
    @unlink( $artifact_path );
    file_put_contents( $artifact_path, $saved_artifact );
    chmod( $artifact_path, 0600 );
}

$opened_artifact = UploadBatchStore::submission_file( $submission_id, 'review_photo', $uploads_dir, $now + 30 );
eforms_test_assert( ! empty( $opened_artifact['ok'] ) && isset( $opened_artifact['artifact']['stream'] ) && is_resource( $opened_artifact['artifact']['stream'] ), 'The store should return an opened manifest-owned member rather than a filesystem path.' );
$gc_while_open = UploadBatchStore::gc_aggregates( 'finalized', $uploads_dir, $delete_after, 1 );
$opened_artifact_bytes = isset( $opened_artifact['artifact']['stream'] ) && is_resource( $opened_artifact['artifact']['stream'] ) ? stream_get_contents( $opened_artifact['artifact']['stream'] ) : false;
if ( isset( $opened_artifact['artifact']['stream'] ) && is_resource( $opened_artifact['artifact']['stream'] ) ) {
    fclose( $opened_artifact['artifact']['stream'] );
}
eforms_test_assert( is_string( $opened_artifact_bytes ) && hash_equals( hash( 'sha256', $artifact ), hash( 'sha256', $opened_artifact_bytes ) ), 'A store-opened authoritative artifact should remain readable when GC attempts aggregate deletion.' );
eforms_test_assert( $gc_while_open['deleted'] === 1 || is_dir( $submission_path ), 'GC may delete an opened aggregate on Unix or retain it for retry on filesystems that deny open-file deletion.' );
if ( is_dir( $submission_path ) ) {
    $gc_after_close = UploadBatchStore::gc_aggregates( 'finalized', $uploads_dir, $delete_after, 1 );
    eforms_test_assert( $gc_after_close['deleted'] === 1, 'GC should delete an expired aggregate after its served member is closed.' );
}

eforms_test_remove_tree( $uploads_dir );
$missing_manifest = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30 );
eforms_test_assert( eforms_test_review_unavailable_shape( $missing_manifest ) === $generic, 'A missing aggregate manifest should use the generic unavailable response.' );

echo "All review gallery tests passed.\n";
