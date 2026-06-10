<?php
/**
 * Unit tests for upload MIME and accept-token policy.
 *
 * Spec: Uploads accept-token policy (docs/Canonical_Spec.md#sec-uploads-accept-tokens)
 * Spec: Uploads (docs/Canonical_Spec.md#sec-uploads)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/UploadPolicy.php';

$pdf_policy = UploadPolicy::policy_for_tokens( array( 'pdf' ) );
$image_policy = UploadPolicy::policy_for_tokens( array( 'image' ) );

eforms_test_assert(
    UploadPolicy::mime_allowed( 'application/pdf', 'pdf', $pdf_policy ) === true,
    'PDF MIME and extension should pass under the pdf token.'
);

eforms_test_assert(
    UploadPolicy::mime_allowed( 'image/png', 'png', $image_policy ) === true,
    'PNG MIME and extension should pass under the image token.'
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

echo "All upload policy tests passed.\n";
