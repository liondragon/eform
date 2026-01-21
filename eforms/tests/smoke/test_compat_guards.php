<?php
/**
 * Smoke test for compatibility guards (pure-PHP).
 *
 * Spec: Compatibility and updates; Shared lifecycle and storage contract.
 */

require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/../../src/Compat.php';

$requirements = Compat::requirements();
eforms_test_assert(
    is_array( $requirements )
        && isset( $requirements['min_php_version'], $requirements['min_wp_version'] )
        && is_string( $requirements['min_php_version'] )
        && is_string( $requirements['min_wp_version'] ),
    'Compat::requirements() should return min versions.'
);

eforms_test_assert(
    Compat::version_meets_min( $requirements['min_php_version'], $requirements['min_php_version'] ),
    'Minimum PHP version should satisfy itself.'
);
eforms_test_assert(
    Compat::version_meets_min( $requirements['min_wp_version'], $requirements['min_wp_version'] ),
    'Minimum WP version should satisfy itself.'
);

eforms_test_assert(
    ! Compat::version_meets_min( '0.0', $requirements['min_php_version'] ),
    'Lower PHP version should not satisfy the minimum.'
);

$base = rtrim( sys_get_temp_dir(), '/\\' ) . '/eforms-compat-' . getmypid();
if ( is_dir( $base ) ) {
    throw new RuntimeException( 'Temp directory collision.' );
}

mkdir( $base, 0700, true );

$probe = Compat::probe_uploads_semantics( $base );
eforms_test_assert( $probe === null, 'Writable temp dir should pass uploads semantics probe.' );

$file_path = $base . '/not-a-dir';
file_put_contents( $file_path, 'x' );
eforms_test_assert(
    Compat::probe_uploads_semantics( $file_path ) !== null,
    'Non-directory uploads path should fail uploads semantics probe.'
);

@unlink( $file_path );
@rmdir( $base );
