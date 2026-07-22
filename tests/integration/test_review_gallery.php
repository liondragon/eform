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

function eforms_test_review_query( $url ) {
    $query = array();
    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
    return $query;
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
        && strpos( $email_reference['url'], 'https://example.test/?eforms_review=' . rawurlencode( $submission_id ) ) === 0
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
$gallery_url = ReviewController::gallery_url( $submission_id, 'https://example.test', $salt );
eforms_test_assert( strpos( $gallery_url, 'https://example.test/?eforms_review=' . rawurlencode( $submission_id ) ) === 0, 'Gallery URL generation should use the controlled home query route.' );
$GLOBALS['eforms_test_styles'] = array();
$GLOBALS['eforms_test_scripts'] = array();
$gallery = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $now + 30 );
eforms_test_assert( $gallery['status'] === 200 && $gallery['render'] === 'review_gallery', 'A valid gallery bearer grant should render the review page.' );
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
eforms_test_assert( strpos( $review_item['download_url'], 'eforms_review_upload=review_photo' ) !== false, 'The gallery should expose one signed authoritative-artifact download.' );
eforms_test_assert( array_keys( $review_item ) === array( 'download_url', 'preview_url' ) && $review_item['preview_url'] === '', 'The no-preview gallery item should expose only the current artifact and optional-preview contract.' );
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
eforms_test_assert( strpos( $local_preview_url, 'eforms_review_preview=review_photo' ) !== false, 'The optional local composition should mint a distinct signed preview URL after gallery authorization.' );
$local_preview_html = eforms_test_render_review_template( $local_preview_gallery['review_page'] );
eforms_test_assert( strpos( $local_preview_html, 'hidden data-eforms-review-src="' ) !== false, 'The gallery template should defer preview source assignment to its serialized browser runtime.' );
eforms_test_assert( preg_match( '/<img[^>]+\ssrc=/', $local_preview_html ) !== 1, 'The gallery template must not start preview requests before the browser admission owner runs.' );
eforms_test_assert( preg_match( '/<a[^>]+class="eforms-review-preview-link ta-gallery__link"[^>]+\shref=/', $local_preview_html ) !== 1 && strpos( $local_preview_html, 'data-lbwps-srcsmall=' ) === false, 'The gallery template must keep lightbox preview URLs inert until the browser admission owner has loaded the preview.' );
eforms_test_assert( strpos( $local_preview_html, 'class="eforms-review-preview-link ta-gallery__link"' ) !== false && strpos( $local_preview_html, 'data-lbwps-width="' ) !== false && strpos( $local_preview_html, 'data-eforms-review-lightbox' ) === false && strpos( $local_preview_html, 'data-eforms-review-download' ) === false && strpos( $local_preview_html, 'eforms-review-download-overlay' ) !== false, 'Preview-capable gallery items should expose a PhotoSwipe-compatible trigger without retaining the removed eForms modal contract.' );
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

foreach ( array( '', '/index.php/%postname%/', '/%postname%/' ) as $permalink_structure ) {
    update_option( 'permalink_structure', $permalink_structure );
    $fallback_url = ReviewController::gallery_url( $submission_id, 'https://example.test', $salt );
    eforms_test_assert( $fallback_url === $gallery_url, 'Every permalink mode should use the same WordPress home query route.' );
    $fallback_gallery = eforms_test_review_request( $fallback_url, $uploads_dir, $salt, $now + 30 );
    eforms_test_assert( $fallback_gallery['status'] === 200 && $fallback_gallery['render'] === 'review_gallery', 'The permalink-independent gallery URL should dispatch through the review controller.' );
    $fallback_download_url = $fallback_gallery['review_page']['items'][0]['download_url'];
    eforms_test_assert( strpos( $fallback_download_url, 'eforms_review_upload=review_photo' ) !== false, 'Artifact downloads should use the same permalink-independent query route.' );
    $fallback_download = eforms_test_review_request( $fallback_download_url, $uploads_dir, $salt, $now + 30 );
    eforms_test_assert( $fallback_download['status'] === 200 && $fallback_download['headers']['Content-Type'] === 'image/png', 'The permalink-independent file URL should stream its signed manifest member.' );
    if ( isset( $fallback_download['stream'] ) && is_resource( $fallback_download['stream'] ) ) {
        fclose( $fallback_download['stream'] );
    }
}

$plain_url = ReviewController::gallery_url( $submission_id, 'https://example.test', $salt );
$plain_query = eforms_test_review_query( $plain_url );
$query_alias = eforms_test_review_request(
    str_replace( 'https://example.test/?', 'https://example.test/unrelated?', $plain_url ),
    $uploads_dir,
    $salt,
    $now + 30
);
eforms_test_assert( ! empty( $query_alias['handled'] ) && $query_alias['status'] === 404, 'A bearer query on an unrelated path should be privately rejected instead of becoming a route alias.' );
eforms_test_assert( $query_alias['headers']['Cache-Control'] === 'private, no-store, max-age=0', 'Rejected bearer-query aliases should remain non-cacheable.' );
foreach ( array( 'HEAD', 'POST', 'PUT' ) as $review_method ) {
    $_SERVER['REQUEST_METHOD'] = $review_method;
    $_SERVER['REQUEST_URI'] = (string) parse_url( $plain_url, PHP_URL_PATH ) . '?' . (string) parse_url( $plain_url, PHP_URL_QUERY );
    $_GET = $plain_query;
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
        && strpos( $expired_operator_html, 'eforms_review_upload' ) === false,
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
    $v2_delete_get['status'] === 404
        && ! empty( UploadBatchStore::submission( $delete_submission_id, $uploads_dir, $now + 60 )['ok'] ),
    'Version-2 expiry-shaped review URLs should not view the submission after the v3 cutover.'
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
$worker_capacity_baseline = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert( is_array( $worker_capacity_baseline ), 'The Worker operator-delete fixture should start with readable capacity accounting.' );
$worker_binding = $binding;
$worker_binding['raw_token'] = 'delete-review-worker-token';
$worker_binding['instance_id'] = 'delete-review-worker-instance';
$worker_store_identity = str_repeat( 'e', 64 );
$worker_created = UploadBatchStore::create_batch(
    $worker_binding,
    $secret,
    $field,
    $uploads_dir,
    $now + 70,
    FormProtocol::UPLOAD_TRANSPORT_WORKER,
    $worker_store_identity
);
eforms_test_assert( ! empty( $worker_created['ok'] ), 'The Worker operator-delete fixture should create a staged aggregate.' );
$worker_authorized = UploadBatchStore::authorize_intent(
    $worker_created['batch']['batch_id'],
    $secret,
    'delete_worker_photo',
    0,
    'Delete Worker.png',
    strlen( $artifact ),
    'image/png',
    0,
    $uploads_dir,
    array(
        'now' => $now + 75,
        'artifact_store' => FormProtocol::UPLOAD_TRANSPORT_WORKER,
        'free_bytes' => 0,
    )
);
eforms_test_assert( ! empty( $worker_authorized['ok'] ), 'The Worker operator-delete fixture should reserve exact remote capacity.' );
$worker_intent = $worker_authorized['intent'];
$worker_completed = UploadBatchStore::complete_receipt(
    $worker_created['batch']['batch_id'],
    $secret,
    'delete_worker_photo',
    array(
        'intent_id' => $worker_intent['intent_id'],
        'batch_id' => $worker_created['batch']['batch_id'],
        'upload_id' => 'delete_worker_photo',
        'ordinal' => 0,
        'object_key' => $worker_intent['object_key'],
        'object_version' => 'delete-worker-version-1',
        'etag' => 'delete-worker-etag-1',
        'bytes' => strlen( $artifact ),
        'mime' => 'image/png',
        'width' => 32,
        'height' => 24,
        'policy_fingerprint' => $worker_intent['policy_fingerprint'],
        'expires_at' => $worker_intent['expires_at'] + Anchors::get( 'WORKER_RECEIPT_TTL_SECONDS' ),
    ),
    $uploads_dir,
    $now + 80
);
eforms_test_assert( ! empty( $worker_completed['ok'] ), 'The Worker operator-delete fixture should commit immutable remote artifact facts.' );
$worker_submission_id = '123e4567-e89b-12d3-a456-426614174003';
$worker_resolved = UploadBatchStore::resolve_open( $worker_created['batch']['batch_id'], $secret, $worker_binding, $field, $uploads_dir, $now + 85 );
eforms_test_assert( ! empty( UploadBatchStore::claim_finalization( $worker_created['batch']['batch_id'], $secret, $worker_binding, $field, $worker_resolved['items'], $worker_submission_id, $uploads_dir, $now + 85 )['ok'] ), 'The Worker operator-delete fixture should freeze its aggregate.' );
$worker_finalized = UploadBatchStore::finalize( $worker_created['batch']['batch_id'], $worker_submission_id, $uploads_dir, $now + 90 );
eforms_test_assert( ! empty( $worker_finalized['ok'] ), 'The Worker operator-delete fixture should finalize.' );
$worker_delete_url = ReviewController::gallery_url( $worker_submission_id, 'https://example.test', $salt );
$worker_submission_path = $uploads_dir . '/eforms-private/submissions/' . Helpers::h2( $worker_submission_id ) . '/' . $worker_submission_id;
$worker_failed_deletes = array();
$worker_failed_delete = eforms_test_review_request(
    $worker_delete_url,
    $uploads_dir,
    $salt,
    $now + 95,
    array(
        'method' => 'POST',
        'post' => array(
            $operator_action_field => $delete_action,
            $delete_nonce_field => 'valid-nonce',
        ),
        'remote_delete' => function ( $object_key, $object_version, $artifact_store_identity ) use ( &$worker_failed_deletes ) {
            $worker_failed_deletes[] = array( $object_key, $object_version, $artifact_store_identity );
            return array( 'ok' => false, 'absent' => false );
        },
    )
);
$worker_capacity_after_failure = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert(
    $worker_failed_delete['status'] === 404
        && $worker_failed_deletes === array( array( $worker_intent['object_key'], 'delete-worker-version-1', $worker_store_identity ) )
        && is_dir( $worker_submission_path )
        && is_array( $worker_capacity_after_failure )
        && $worker_capacity_after_failure['total_bytes'] === $worker_capacity_baseline['total_bytes'] + strlen( $artifact )
        && ! empty( UploadBatchStore::submission( $worker_submission_id, $uploads_dir, $now + 95 )['ok'] ),
    'A failed Worker operator delete should leave the finalized aggregate and capacity accounting retryable.'
);
$worker_deleted = array();
$worker_deleted_response = eforms_test_review_request(
    $worker_delete_url,
    $uploads_dir,
    $salt,
    $now + 100,
    array(
        'method' => 'POST',
        'post' => array(
            $operator_action_field => $delete_action,
            $delete_nonce_field => 'valid-nonce',
        ),
        'remote_delete' => function ( $object_key, $object_version, $artifact_store_identity ) use ( &$worker_deleted ) {
            $worker_deleted[] = array( $object_key, $object_version, $artifact_store_identity );
            return array( 'ok' => true, 'absent' => true );
        },
    )
);
$worker_capacity_after_delete = eforms_test_managed_capacity_record( $uploads_dir );
eforms_test_assert(
    $worker_deleted_response['status'] === 200
        && $worker_deleted === array( array( $worker_intent['object_key'], 'delete-worker-version-1', $worker_store_identity ) )
        && ! is_dir( $worker_submission_path )
        && is_array( $worker_capacity_after_delete )
        && $worker_capacity_after_delete['total_bytes'] === $worker_capacity_baseline['total_bytes']
        && empty( UploadBatchStore::submission( $worker_submission_id, $uploads_dir, $now + 100 )['ok'] ),
    'A valid Worker operator delete should remove the finalized aggregate and release exact remote capacity.'
);
unset( $GLOBALS['eforms_test_can_manage'] );

$gallery_query = eforms_test_review_query( $gallery_url );
$generic = eforms_test_review_unavailable_shape(
    eforms_test_review_request(
        'https://example.test/?' . http_build_query(
            array(
                'eforms_review' => $submission_id,
                'expires' => $delete_after,
                'signature' => str_repeat( 'A', 43 ),
            ),
            '',
            '&',
            PHP_QUERY_RFC3986
        ),
        $uploads_dir,
        $salt,
        $now + 30
    )
);
eforms_test_assert( $generic['status'] === 404, 'Invalid review requests should return a generic not-found response.' );

$unknown_query = $review_item['download_url'] . '&unexpected=1';
$expired_anonymous = eforms_test_review_request( $gallery_url, $uploads_dir, $salt, $delete_after );
$foreign_upload_url = ReviewController::file_url( $submission_id, 'foreign_photo', 'https://example.test', $salt );
$foreign = eforms_test_review_request( $foreign_upload_url, $uploads_dir, $salt, $now + 30 );
$traversal = eforms_test_review_request(
    'https://example.test/?eforms_review=' . rawurlencode( $submission_id ) . '&eforms_review_upload=..&signature=' . $gallery_query['signature'],
    $uploads_dir,
    $salt,
    $now + 30
);
foreach ( array( $unknown_query, $expired_anonymous, $foreign, $traversal ) as $denied ) {
    $response = is_string( $denied ) ? eforms_test_review_request( $denied, $uploads_dir, $salt, $now + 30 ) : $denied;
    eforms_test_assert( eforms_test_review_unavailable_shape( $response ) === $generic, 'Unknown-query, expired, foreign, and path-like grants should be indistinguishable.' );
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
