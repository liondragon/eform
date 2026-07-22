<?php
/**
 * Integration tests for signed finalized-gallery and member access.
 *
 * Contract: Managed review access
 * Contract: Signed gallery and file routes
 */

require_once __DIR__ . '/../bootstrap.php';
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
        );
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path = '', $plugin = null ) {
        return $path;
    }
}

function eforms_test_review_request( $url, $uploads_dir, $salt, $now ) {
    return ReviewController::dispatch_current_request(
        array( 'method' => 'GET', 'uri' => $url ),
        array(
            'uploads_dir' => $uploads_dir,
            'salt' => $salt,
            'now' => $now,
            'base_url' => 'https://example.test',
        )
    );
}

function eforms_test_review_query( $url ) {
    $query = array();
    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
    return $query;
}

function eforms_test_review_unavailable_shape( $response ) {
    return array(
        'status' => isset( $response['status'] ) ? $response['status'] : null,
        'body' => isset( $response['body'] ) ? $response['body'] : null,
        'headers' => isset( $response['headers'] ) ? $response['headers'] : null,
    );
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
$secret = rtrim( strtr( base64_encode( str_repeat( "\x61", Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ) ) ), '+/', '-_' ), '=' );
$binding = array(
    'raw_token' => 'review-form-token',
    'form_id' => 'review-demo',
    'instance_id' => 'review-instance',
    'field_key' => 'photos',
    'accept_until' => $now + 3600,
);
$created = UploadBatchStore::create_batch( $binding, $secret, $field, $uploads_dir, $now );
eforms_test_assert( ! empty( $created['ok'] ), 'The review fixture should create a staged aggregate.' );

$png = eforms_test_fixture_bytes( 'staged-landscape.png' );
$source = eforms_test_write_file( $uploads_dir, 'review-source.png', $png );
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
eforms_test_assert( ! empty( $put['ok'] ), 'The review fixture should commit a real image and preview: ' . json_encode( $put ) );
$submission_id = '123e4567-e89b-12d3-a456-426614174000';
$resolved = UploadBatchStore::resolve_open( $created['batch']['batch_id'], $secret, $binding, $field, $uploads_dir, $now + 10 );
eforms_test_assert( ! empty( UploadBatchStore::claim_finalization( $created['batch']['batch_id'], $secret, $binding, $field, $resolved['items'], $submission_id, $uploads_dir, $now + 10 )['ok'] ), 'The review fixture should freeze its aggregate.' );
$finalized = UploadBatchStore::finalize( $created['batch']['batch_id'], $submission_id, $uploads_dir, $now + 20 );
eforms_test_assert( ! empty( $finalized['ok'] ), 'The review fixture should finalize its aggregate.' );
$expires = $finalized['submission']['gallery_expires_at'];
$private_dir = PrivateDir::path( $uploads_dir );
file_put_contents( $private_dir . '/' . PrivateDir::PURGE_MARKER_FILENAME, "purged\n" );
$blocked_submission = UploadBatchStore::submission( $submission_id, $uploads_dir, $now + 20 );
eforms_test_assert( empty( $blocked_submission['ok'] ) && $blocked_submission['reason'] === 'managed_purged', 'Finalized aggregate reads should stop at the uninstall purge barrier.' );
eforms_test_assert( PrivateDir::resume_after_install( $uploads_dir ) === true, 'The review fixture should model activation before serving the gallery.' );

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
        && $email_reference['expires_at'] === $expires
        && strpos( $email_reference['url'], 'https://example.test/?eforms_review=' . rawurlencode( $submission_id ) ) === 0,
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
$gallery_url = ReviewController::gallery_url( $submission_id, $expires, 'https://example.test', $salt );
eforms_test_assert( strpos( $gallery_url, 'https://example.test/?eforms_review=' . rawurlencode( $submission_id ) ) === 0, 'Gallery URL generation should use the controlled home query route.' );
$GLOBALS['eforms_test_styles'] = array();
$gallery = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30 );
eforms_test_assert( $gallery['status'] === 200 && $gallery['render'] === 'review_gallery', 'A valid gallery bearer grant should render the review page.' );
eforms_test_assert( ! empty( $GLOBALS['eforms_test_styles'] ), 'A valid gallery bearer grant should enqueue the shared plugin CSS.' );
eforms_test_assert( $gallery['headers']['Cache-Control'] === 'private, no-store, max-age=0', 'Gallery responses should be private and non-cacheable.' );
eforms_test_assert( $gallery['headers']['X-Robots-Tag'] === 'noindex, nofollow' && $gallery['headers']['X-Content-Type-Options'] === 'nosniff', 'Gallery responses should block indexing and sniffing.' );
eforms_test_assert( $gallery['headers']['Referrer-Policy'] === 'no-referrer', 'Gallery responses should not leak bearer URLs through referrers.' );
eforms_test_assert( $gallery['review_page']['submission_id'] === $submission_id && $gallery['review_page']['count'] === 1, 'Gallery context should expose only its stable id and item count.' );
$review_item = $gallery['review_page']['items'][0];
eforms_test_assert( strpos( $review_item['preview_url'], 'eforms_review_variant=preview' ) !== false && strpos( $review_item['master_url'], 'eforms_review_variant=master' ) !== false, 'Preview and master grants should be separately purpose-bound.' );
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

$preview = eforms_test_review_request( $review_item['preview_url'], $uploads_dir, $salt, $now + 30 );
eforms_test_assert( $preview['status'] === 200 && $preview['headers']['Content-Type'] === 'image/jpeg', 'The signed preview grant should return only the generated JPEG.' );
$preview_bytes = is_resource( $preview['stream'] ) ? stream_get_contents( $preview['stream'] ) : false;
if ( is_resource( $preview['stream'] ) ) {
    fclose( $preview['stream'] );
}
$preview_copy = is_string( $preview_bytes ) ? eforms_test_write_file( $uploads_dir, 'served-review-preview.jpg', $preview_bytes ) : '';
eforms_test_assert( $preview_copy !== '' && UploadPolicy::detect_mime( $preview_copy ) === 'image/jpeg', 'The controlled preview stream should agree with its MIME header.' );
eforms_test_assert( strpos( $preview['headers']['Content-Disposition'], "filename*=UTF-8''Customer%20%3CPhoto%3E-preview.jpg" ) !== false, 'Preview names should use an encoded JPEG disposition.' );

$master = eforms_test_review_request( $review_item['master_url'], $uploads_dir, $salt, $now + 30 );
eforms_test_assert( $master['status'] === 200 && $master['headers']['Content-Type'] === 'image/jpeg', 'The signed master grant should return only the normalized JPEG.' );
$master_bytes = is_resource( $master['stream'] ) ? stream_get_contents( $master['stream'] ) : false;
if ( is_resource( $master['stream'] ) ) {
    fclose( $master['stream'] );
}
eforms_test_assert( is_string( $master_bytes ) && UploadPolicy::detect_mime( eforms_test_write_file( $uploads_dir, 'served-review-master.jpg', $master_bytes ) ) === 'image/jpeg', 'The master grant should stream a normalized JPEG member.' );
eforms_test_assert( strpos( $master['headers']['Content-Disposition'], "filename*=UTF-8''Customer%20%3CPhoto%3E-high-resolution.jpg" ) !== false, 'Master names should use an encoded JPEG disposition.' );

foreach ( array( '', '/index.php/%postname%/', '/%postname%/' ) as $permalink_structure ) {
    update_option( 'permalink_structure', $permalink_structure );
    $fallback_url = ReviewController::gallery_url( $submission_id, $expires, 'https://example.test', $salt );
    eforms_test_assert( $fallback_url === $gallery_url, 'Every permalink mode should use the same WordPress home query route.' );
    $fallback_gallery = eforms_test_review_request( $fallback_url, $uploads_dir, $salt, $now + 30 );
    eforms_test_assert( $fallback_gallery['status'] === 200 && $fallback_gallery['render'] === 'review_gallery', 'The permalink-independent gallery URL should dispatch through the review controller.' );
    $fallback_preview_url = $fallback_gallery['review_page']['items'][0]['preview_url'];
    eforms_test_assert( strpos( $fallback_preview_url, 'eforms_review_upload=review_photo' ) !== false, 'Derived file links should use the same permalink-independent query route.' );
    $fallback_preview = eforms_test_review_request( $fallback_preview_url, $uploads_dir, $salt, $now + 30 );
    eforms_test_assert( $fallback_preview['status'] === 200 && $fallback_preview['headers']['Content-Type'] === 'image/jpeg', 'The permalink-independent file URL should stream its signed manifest member.' );
    if ( isset( $fallback_preview['stream'] ) && is_resource( $fallback_preview['stream'] ) ) {
        fclose( $fallback_preview['stream'] );
    }
}

$plain_url = ReviewController::gallery_url( $submission_id, $expires, 'https://example.test', $salt );
$plain_query = eforms_test_review_query( $plain_url );
$query_alias = eforms_test_review_request(
    str_replace( 'https://example.test/?', 'https://example.test/unrelated?', $plain_url ),
    $uploads_dir,
    $salt,
    $now + 30
);
eforms_test_assert( ! empty( $query_alias['handled'] ) && $query_alias['status'] === 404, 'A bearer query on an unrelated path should be privately rejected instead of becoming a route alias.' );
eforms_test_assert( $query_alias['headers']['Cache-Control'] === 'private, no-store, max-age=0', 'Rejected bearer-query aliases should remain non-cacheable.' );
foreach ( array( 'HEAD', 'POST' ) as $review_method ) {
    $_SERVER['REQUEST_METHOD'] = $review_method;
    $_SERVER['REQUEST_URI'] = (string) parse_url( $plain_url, PHP_URL_PATH ) . '?' . (string) parse_url( $plain_url, PHP_URL_QUERY );
    $_GET = $plain_query;
    $method_denied = PublicRequestController::dispatch_current_request();
    eforms_test_assert( ! empty( $method_denied['handled'] ) && $method_denied['status'] === 404, 'Every non-GET review request should be handled by the private rejection path.' );
    eforms_test_assert( $method_denied['headers']['Cache-Control'] === 'private, no-store, max-age=0' && $method_denied['headers']['X-Robots-Tag'] === 'noindex, nofollow', 'Rejected review methods should retain private no-store/noindex headers.' );
}
unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
$_GET = array();

$gallery_query = eforms_test_review_query( $gallery_url );
$generic = eforms_test_review_unavailable_shape(
    eforms_test_review_request(
        'https://example.test/?eforms_review=' . rawurlencode( $submission_id ) . '&expires=' . $expires . '&signature=' . str_repeat( 'A', 43 ),
        $uploads_dir,
        $salt,
        $now + 30
    )
);
eforms_test_assert( $generic['status'] === 404, 'Invalid review requests should return a generic not-found response.' );

$modified_variant = str_replace( 'eforms_review_variant=preview', 'eforms_review_variant=master', $review_item['preview_url'] );
$expired = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $expires );
$foreign_upload_url = ReviewController::file_url( $submission_id, 'foreign_photo', 'preview', $expires, 'https://example.test', $salt );
$foreign = eforms_test_review_request( $foreign_upload_url, $uploads_dir, $salt, $now + 30 );
$traversal = eforms_test_review_request(
    'https://example.test/?eforms_review=' . rawurlencode( $submission_id ) . '&eforms_review_upload=..&eforms_review_variant=preview&expires=' . $expires . '&signature=' . $gallery_query['signature'],
    $uploads_dir,
    $salt,
    $now + 30
);
foreach ( array( $modified_variant, $expired, $foreign, $traversal ) as $denied ) {
    $response = is_string( $denied ) ? eforms_test_review_request( $denied, $uploads_dir, $salt, $now + 30 ) : $denied;
    eforms_test_assert( eforms_test_review_unavailable_shape( $response ) === $generic, 'Modified, expired, foreign, and path-like grants should be indistinguishable.' );
}

$submission_path = $uploads_dir . '/eforms-private/submissions/' . Helpers::h2( $submission_id ) . '/' . $submission_id;
$submission_manifest_path = $submission_path . '/' . UploadBatchStore::MANIFEST_FILENAME;
$submission_manifest = json_decode( file_get_contents( $submission_manifest_path ), true );
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

$preview_path = $submission_path . '/' . $submission_manifest['items']['review_photo']['preview_relpath'];
eforms_test_assert( is_file( $preview_path ) && unlink( $preview_path ), 'The missing-preview fixture should remove only the declared preview member.' );
$missing_preview = eforms_test_review_request( $review_item['preview_url'], $uploads_dir, $salt, $now + 30 );
eforms_test_assert( eforms_test_review_unavailable_shape( $missing_preview ) === $generic, 'A missing declared preview should use the generic unavailable response.' );

if ( function_exists( 'symlink' ) ) {
    $master_path = $submission_path . '/' . $submission_manifest['items']['review_photo']['master_relpath'];
    $saved_master = file_get_contents( $master_path );
    $outside_master = eforms_test_write_file( $uploads_dir, 'outside-master.jpg', 'outside-master' );
    eforms_test_assert( is_file( $master_path ) && unlink( $master_path ) && symlink( $outside_master, $master_path ), 'The finalized master symlink fixture should replace only the manifest member.' );
    $linked_master = UploadBatchStore::submission_file( $submission_id, 'review_photo', 'master', $uploads_dir, $now + 30 );
    eforms_test_assert( empty( $linked_master['ok'] ) && $linked_master['reason'] === 'file_missing', 'Finalized member reads should reject symlinked manifest members.' );
    @unlink( $master_path );
    file_put_contents( $master_path, $saved_master );
}

$opened_master = UploadBatchStore::submission_file( $submission_id, 'review_photo', 'master', $uploads_dir, $now + 30 );
eforms_test_assert( ! empty( $opened_master['ok'] ) && is_resource( $opened_master['stream'] ), 'The store should return an opened manifest-owned member rather than a filesystem path.' );
$gc_while_open = UploadBatchStore::gc_aggregates( 'finalized', $uploads_dir, $expires, 1 );
$opened_master_bytes = is_resource( $opened_master['stream'] ) ? stream_get_contents( $opened_master['stream'] ) : false;
if ( is_resource( $opened_master['stream'] ) ) {
    fclose( $opened_master['stream'] );
}
eforms_test_assert( is_string( $opened_master_bytes ) && hash( 'sha256', $opened_master_bytes ) === $submission_manifest['items']['review_photo']['master_sha256'], 'A store-opened master should remain readable when GC attempts aggregate deletion.' );
eforms_test_assert( $gc_while_open['deleted'] === 1 || is_dir( $submission_path ), 'GC may delete an opened aggregate on Unix or retain it for retry on filesystems that deny open-file deletion.' );
if ( is_dir( $submission_path ) ) {
    $gc_after_close = UploadBatchStore::gc_aggregates( 'finalized', $uploads_dir, $expires, 1 );
    eforms_test_assert( $gc_after_close['deleted'] === 1, 'GC should delete an expired aggregate after its served member is closed.' );
}

eforms_test_remove_tree( $uploads_dir );
$missing_manifest = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30 );
eforms_test_assert( eforms_test_review_unavailable_shape( $missing_manifest ) === $generic, 'A missing aggregate manifest should use the generic unavailable response.' );

echo "All review gallery tests passed.\n";
