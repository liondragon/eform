<?php
/**
 * Smoke test for the plugin bootstrap wiring.
 *
 * Spec: Architecture and file layout; Public surfaces index; Lazy-load lifecycle.
 */

require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/../../eforms.php';

// Given a loaded plugin...
// When the public entry points are registered...
// Then the template tag and shortcode exist and fail closed.
eforms_test_assert( function_exists( 'eform_render' ), 'eform_render should be defined.' );
$output = eform_render( 'demo', array() );
eforms_test_assert( is_string( $output ), 'eform_render should return a string.' );
eforms_test_assert(
    strpos( $output, 'EFORMS_ERR_SCHEMA_REQUIRED' ) !== false,
    'eform_render should surface the deterministic error code.'
);

eforms_test_assert(
    isset( $GLOBALS['eforms_test_hooks']['shortcode']['eform'] ),
    'Shortcode [eform] should be registered.'
);

eforms_test_assert(
    isset( $GLOBALS['eforms_test_hooks']['action']['rest_api_init'] ),
    'REST init hook should be registered.'
);

eforms_test_assert(
    isset( $GLOBALS['eforms_test_hooks']['action']['init'] ),
    'Init hook should be registered.'
);

foreach ( $GLOBALS['eforms_test_hooks']['action']['rest_api_init'] as $callback ) {
    call_user_func( $callback );
}

eforms_test_assert(
    ! empty( $GLOBALS['eforms_test_hooks']['rest'] ),
    'REST routes should be registered during rest_api_init.'
);

foreach ( $GLOBALS['eforms_test_hooks']['action']['init'] as $callback ) {
    call_user_func( $callback );
}

eforms_test_assert(
    ! empty( $GLOBALS['eforms_test_hooks']['rewrite'] ),
    'Rewrite rules should include /eforms/mint.'
);
