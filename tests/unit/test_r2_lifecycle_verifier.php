<?php
/**
 * Offline rule-shape tests for the operator-held R2 lifecycle verifier.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../worker/scripts/verify-r2-lifecycle.php';

$required = eforms_r2_lifecycle_required_days() * 86400;
$rule = function ( $prefix, $max_age, $enabled = true ) {
    return array(
        'id' => 'artifact-backstop',
        'conditions' => array( 'prefix' => $prefix ),
        'enabled' => $enabled,
        'deleteObjectsTransition' => array( 'condition' => array( 'type' => 'Age', 'maxAge' => $max_age ) ),
    );
};
$response = function ( $rules ) {
    return array( 'success' => true, 'result' => array( 'rules' => $rules ) );
};
$default_abort_rule = array(
    'id' => 'Default Multipart Abort Rule',
    'enabled' => true,
    'conditions' => array(),
    'abortMultipartUploadsTransition' => array( 'condition' => array( 'type' => 'Age', 'maxAge' => 604800 ) ),
);

$safe = eforms_r2_lifecycle_verify_rules( $response( array( $rule( 'artifacts/', $required ) ) ) );
eforms_test_assert( ! empty( $safe['ok'] ) && $safe['matching_rules'] === 1, 'The computed whole-day application lifetime should be accepted without a copied day constant.' );
$with_default_abort = eforms_r2_lifecycle_verify_rules( $response( array( $default_abort_rule, $rule( 'artifacts/', $required ) ) ) );
eforms_test_assert( ! empty( $with_default_abort['ok'] ) && $with_default_abort['matching_rules'] === 1, 'Cloudflare default multipart-abort rules must not invalidate the artifacts delete proof.' );
$short = eforms_r2_lifecycle_verify_rules( $response( array( $rule( 'artifacts/', $required - 1 ) ) ) );
eforms_test_assert( empty( $short['ok'] ) && $short['reason'] === 'artifacts_lifecycle_unsafe', 'A one-second-short artifacts rule must fail activation.' );
$global_short = eforms_r2_lifecycle_verify_rules( $response( array( $rule( '', $required - 1 ), $rule( 'artifacts/', $required + 86400 ) ) ) );
eforms_test_assert( empty( $global_short['ok'] ), 'Any broader matching rule that can delete earlier must fail closed.' );
$shard_short = eforms_r2_lifecycle_verify_rules( $response( array( $rule( 'artifacts/', $required ), $rule( 'artifacts/ab/', $required - 1 ) ) ) );
eforms_test_assert( empty( $shard_short['ok'] ) && $shard_short['reason'] === 'artifacts_lifecycle_unsafe', 'A narrower rule inside the authoritative namespace must not delete one shard early.' );
$shard_only = eforms_r2_lifecycle_verify_rules( $response( array( $rule( 'artifacts/ab/', $required ) ) ) );
eforms_test_assert( empty( $shard_only['ok'] ) && $shard_only['reason'] === 'artifacts_lifecycle_missing', 'A safe shard-only rule must not stand in for whole-namespace lifecycle coverage.' );
$unrelated = eforms_r2_lifecycle_verify_rules( $response( array( $rule( 'preview-cache/', $required ) ) ) );
eforms_test_assert( empty( $unrelated['ok'] ) && $unrelated['reason'] === 'artifacts_lifecycle_missing', 'A preview-only rule cannot satisfy authoritative artifact retention.' );
$dated = $rule( 'artifacts/', $required );
$dated['deleteObjectsTransition']['condition'] = array( 'type' => 'Date', 'date' => '2030-01-01T00:00:00Z' );
eforms_test_assert( empty( eforms_r2_lifecycle_verify_rules( $response( array( $dated ) ) )['ok'] ), 'A fixed-date deletion rule cannot prove per-object retention.' );
eforms_test_assert( empty( eforms_r2_lifecycle_verify_rules( array( 'success' => false ) )['ok'] ), 'Malformed provider responses must fail closed.' );

echo "R2 lifecycle verifier tests passed.\n";
