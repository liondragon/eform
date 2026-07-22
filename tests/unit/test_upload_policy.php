<?php
/**
 * Unit tests for upload MIME and accept-token policy.
 *
 * Contract: Uploads accept-token policy
 * Contract: Uploads
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/UploadPolicy.php';

$pdf_policy = UploadPolicy::policy_for_tokens( array( 'pdf' ) );
$image_policy = UploadPolicy::policy_for_tokens( array( 'image' ) );
$staged_image_policy = UploadPolicy::policy_for_tokens( array( 'image' ), 'staged' );

eforms_test_assert(
    UploadPolicy::mime_allowed( 'application/pdf', 'pdf', $pdf_policy ) === true,
    'PDF MIME and extension should pass under the pdf token.'
);

eforms_test_assert(
    UploadPolicy::mime_allowed( 'image/png', 'png', $image_policy ) === true,
    'PNG MIME and extension should pass under the image token.'
);

eforms_test_assert(
    UploadPolicy::mime_allowed( 'image/gif', 'gif', $image_policy ) === true,
    'Synchronous image policy should retain GIF support.'
);

eforms_test_assert(
    UploadPolicy::mime_allowed( 'image/webp', 'webp', $staged_image_policy ) === true
        && UploadPolicy::mime_allowed( 'image/heic', 'heic', $staged_image_policy ) === true
        && UploadPolicy::mime_allowed( 'image/heif', 'heif', $staged_image_policy ) === true
        && UploadPolicy::mime_allowed( 'image/gif', 'gif', $staged_image_policy ) === false,
    'The sole staged image token should accept JPEG, PNG, WebP, HEIC, and HEIF while excluding GIF.'
);
eforms_test_assert( UploadPolicy::staged_tokens_allowed( array( 'image' ) ) === true, 'Staged fields should accept the sole image token.' );
eforms_test_assert( UploadPolicy::staged_tokens_allowed( array( 'image', 'heic' ) ) === false, 'Staged fields should reject the removed HEIC opt-in token.' );
eforms_test_assert( UploadPolicy::resolve_tokens( array( 'heic' ), false, 'synchronous' ) === array(), 'HEIC should remain unavailable to synchronous upload fields.' );

eforms_test_assert(
    UploadPolicy::mime_allowed( 'application/octet-stream', 'pdf', $pdf_policy ) === false,
    'Octet-stream must not pass by PDF extension alone.'
);

eforms_test_assert(
    UploadPolicy::mime_allowed( 'image/png', 'pdf', $pdf_policy ) === false,
    'MIME/extension mismatch should fail.'
);

eforms_test_assert(
    UploadPolicy::mime_allowed( 'application/pdf', 'exe', $pdf_policy ) === false,
    'Unknown or disallowed extensions should fail.'
);

$attempts = UploadPolicy::preview_attempts();
eforms_test_assert(
    $attempts === array(
        array( 'edge' => 1600, 'quality' => 82 ),
        array( 'edge' => 1440, 'quality' => 78 ),
        array( 'edge' => 1280, 'quality' => 74 ),
        array( 'edge' => 1120, 'quality' => 70 ),
        array( 'edge' => 960, 'quality' => 66 ),
    ),
    'The staged preview ladder should use the exact fixed edge and quality attempts.'
);

eforms_test_assert(
    UploadPolicy::master_attempts() === array(
        array( 'edge' => 4096, 'quality' => 88 ),
        array( 'edge' => 3584, 'quality' => 84 ),
        array( 'edge' => 3072, 'quality' => 80 ),
        array( 'edge' => 2560, 'quality' => 76 ),
        array( 'edge' => 2048, 'quality' => 72 ),
    ),
    'The staged master ladder should use the exact fixed edge and quality attempts.'
);

eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 12000, 5000 ) === true, 'The exact staged edge and pixel boundaries should pass.' );
eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 12001, 1 ) === false, 'A source above the staged maximum edge should fail.' );
eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 10000, 6000 ) === true, 'The exact staged pixel boundary should pass.' );
eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 10001, 6000 ) === false, 'A source above the staged maximum pixels should fail.' );

$all_formats = function ( $mime ) {
    return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ), true );
};
$memory_failure = UploadPolicy::staged_host_readiness(
    array( 'memory_limit' => '767M', 'execution_limit' => 0, 'imagick_support' => $all_formats )
);
eforms_test_assert( $memory_failure['ok'] === false && $memory_failure['reason'] === 'memory_limit', 'Staged readiness should enforce the fixed memory floor.' );

$zero_memory_failure = UploadPolicy::staged_host_readiness(
    array( 'memory_limit' => '0', 'execution_limit' => 0, 'imagick_support' => $all_formats )
);
eforms_test_assert( $zero_memory_failure['ok'] === false && $zero_memory_failure['reason'] === 'memory_limit', 'Staged readiness should keep an explicit zero memory limit fail-closed.' );

$execution_failure = UploadPolicy::staged_host_readiness(
    array( 'memory_limit' => '768M', 'execution_limit' => 59, 'imagick_support' => $all_formats )
);
eforms_test_assert( $execution_failure['ok'] === false && $execution_failure['reason'] === 'execution_limit', 'Staged readiness should enforce the fixed execution-time floor.' );

$format_failure = UploadPolicy::staged_host_readiness(
    array(
        'memory_limit' => -1,
        'execution_limit' => 0,
        'imagick_support' => function ( $mime ) {
            return $mime !== 'image/heif';
        },
    )
);
eforms_test_assert(
    $format_failure['ok'] === false
        && $format_failure['reason'] === 'backend'
        && $format_failure['missing_mimes'] === array( 'image/heif' ),
    'Staged readiness should identify each accepted source format missing from Imagick.'
);
$ready = UploadPolicy::staged_host_readiness( array( 'memory_limit' => -1, 'execution_limit' => 0, 'imagick_support' => $all_formats ) );
eforms_test_assert( $ready['ok'] === true && $ready['backend'] === 'imagick', 'Staged readiness should expose Imagick as the sole backend.' );
$encode_failure = UploadPolicy::staged_host_readiness(
    array(
        'memory_limit' => -1,
        'execution_limit' => 0,
        'imagick_support' => $all_formats,
        'imagick_jpeg_encode' => false,
    )
);
eforms_test_assert(
    $encode_failure['ok'] === false
        && $encode_failure['reason'] === 'backend'
        && $encode_failure['missing_operations'] === array( 'jpeg_encode' ),
    'Staged readiness should fail when Imagick registers formats but cannot emit JPEG bytes.'
);

echo "All upload policy tests passed.\n";
