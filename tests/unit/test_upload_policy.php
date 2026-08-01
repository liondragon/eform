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
    UploadPolicy::staged_extension_supported( 'heif' )
        && ! UploadPolicy::staged_extension_supported( 'gif' ),
    'Canonical staged extension membership should come from the MIME projection owner.'
);
eforms_test_assert(
    UploadPolicy::staged_mime_has_browser_fallback( 'image/jpeg' )
        && UploadPolicy::staged_mime_has_browser_fallback( 'image/png' )
        && UploadPolicy::staged_mime_has_browser_fallback( 'image/webp' )
        && ! UploadPolicy::staged_mime_has_browser_fallback( 'image/heic' )
        && ! UploadPolicy::staged_mime_has_browser_fallback( 'image/heif' )
        && ! UploadPolicy::staged_mime_has_browser_fallback( 'image/gif' ),
    'Only staged formats covered by the browser-native image policy should offer an inline original fallback.'
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


eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 12000, 5000 ) === true, 'The exact staged edge and pixel boundaries should pass.' );
eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 12001, 1 ) === false, 'A source above the staged maximum edge should fail.' );
eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 10000, 6000 ) === true, 'The exact staged pixel boundary should pass.' );
eforms_test_assert( UploadPolicy::staged_dimensions_allowed( 10001, 6000 ) === false, 'A source above the staged maximum pixels should fail.' );

echo "All upload policy tests passed.\n";
