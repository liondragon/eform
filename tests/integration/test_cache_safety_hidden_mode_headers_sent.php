<?php
/**
 * Integration tests for cache-safety when headers are already sent.
 *
 * Contract: Cache-safety
 * Contract: Request lifecycle GET
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Security/StorageHealth.php';
require_once __DIR__ . '/../../src/Rendering/FormRenderer.php';

// Given headers are already sent...
// When FormRenderer attempts a hidden-mode render...
// Then it fails closed without minting tokens.
$uploads_dir = eforms_test_tmp_root( 'eforms-render-headers-sent' );
mkdir( $uploads_dir, 0700, true );
$GLOBALS['eforms_test_uploads_dir'] = $uploads_dir;

Config::reset_for_tests();
StorageHealth::reset_for_tests();
FormRenderer::reset_for_tests();
Logging::reset_for_tests();

FormRenderer::set_headers_sent_override( true );

$output = FormRenderer::render( 'contact', array() );
eforms_test_assert(
    strpos( $output, 'EFORMS_ERR_STORAGE_UNAVAILABLE' ) !== false,
    'Renderer should fail closed when headers are already sent.'
);
eforms_test_assert(
    ! is_dir( $uploads_dir . '/eforms-private' ),
    'Renderer should not mint tokens when cache headers cannot be set.'
);

FormRenderer::render( 'contact', array() );
eforms_test_assert(
    count( Logging::$events ) === 1,
    'Headers-sent warnings should log at most once per request.'
);

FormRenderer::set_headers_sent_override( null );
eforms_test_remove_tree( $uploads_dir );
