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
        && UploadPolicy::mime_allowed( 'image/gif', 'gif', $staged_image_policy ) === false,
    'Staged image hints should accept WebP and exclude GIF through UploadPolicy.'
);

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

eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 12000, 5000 ) === true, 'The exact staged edge and pixel boundaries should pass.' );
eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 12001, 1 ) === false, 'A source above the staged maximum edge should fail.' );
eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 10000, 6000 ) === true, 'The exact staged pixel boundary should pass.' );
eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 10001, 6000 ) === false, 'A source above the staged maximum pixels should fail.' );

$editor_support = function () {
    return true;
};
$memory_failure = UploadPolicy::staged_host_readiness(
    'image/jpeg',
    array( 'memory_limit' => '767M', 'execution_limit' => 0, 'editor_support' => $editor_support )
);
eforms_test_assert( $memory_failure['ok'] === false && $memory_failure['reason'] === 'memory_limit', 'Staged readiness should enforce the fixed memory floor.' );

$zero_memory_failure = UploadPolicy::staged_host_readiness(
    'image/jpeg',
    array( 'memory_limit' => '0', 'execution_limit' => 0, 'editor_support' => $editor_support )
);
eforms_test_assert( $zero_memory_failure['ok'] === false && $zero_memory_failure['reason'] === 'memory_limit', 'Staged readiness should keep an explicit zero memory limit fail-closed.' );

$execution_failure = UploadPolicy::staged_host_readiness(
    'image/jpeg',
    array( 'memory_limit' => '768M', 'execution_limit' => 59, 'editor_support' => $editor_support )
);
eforms_test_assert( $execution_failure['ok'] === false && $execution_failure['reason'] === 'execution_limit', 'Staged readiness should enforce the fixed execution-time floor.' );

$editor_failure = UploadPolicy::staged_host_readiness(
    'image/jpeg',
    array(
        'memory_limit' => -1,
        'execution_limit' => 0,
        'editor_support' => function () {
            return false;
        },
    )
);
eforms_test_assert( $editor_failure['ok'] === false && $editor_failure['reason'] === 'editor_support', 'Staged readiness should fail when the WordPress editor reports no support.' );

$gd_functions = array( 'imagecreatefromjpeg', 'imagejpeg', 'imagecreatetruecolor', 'imagecopyresampled', 'imagecopy', 'imagedestroy' );
if ( count( array_filter( $gd_functions, 'function_exists' ) ) === count( $gd_functions ) ) {
    $gd_options = array(
        'memory_limit' => -1,
        'execution_limit' => 0,
        'editor_support' => $editor_support,
        'imagick_support' => function () {
            return false;
        },
    );
    $gd_without_exif = UploadPolicy::staged_host_readiness( 'image/jpeg', array_merge( $gd_options, array( 'exif_support' => false ) ) );
    eforms_test_assert( empty( $gd_without_exif['ok'] ) && $gd_without_exif['reason'] === 'backend', 'JPEG readiness should reject GD when EXIF orientation support is unavailable.' );

    $gd_with_exif = UploadPolicy::staged_host_readiness( 'image/jpeg', array_merge( $gd_options, array( 'exif_support' => true ) ) );
    eforms_test_assert( ! empty( $gd_with_exif['ok'] ) && $gd_with_exif['backend'] === 'gd', 'JPEG readiness may select GD when EXIF orientation support is available.' );
} else {
    echo "GD readiness checks skipped: GD is unavailable.\n";
}

echo "All upload policy tests passed.\n";
