<?php
/**
 * Unit tests for local spam content filter policy.
 *
 * Contract: Spam content filter policy.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Spam/ContentFilter.php';

$parsed = ContentFilter::parse_terms_text( "\n Casino \n...SEO   Services!!\n" );
eforms_test_assert( $parsed['ok'] === true, 'Content terms should parse.' );
eforms_test_assert( $parsed['terms'] === array( 'casino', 'seo services' ), 'Terms should normalize case, whitespace, and edge punctuation.' );
eforms_test_assert( $parsed['normalized_text'] === "casino\nseo services", 'Normalized text should be one term per line.' );

$duplicate = ContentFilter::parse_terms_text( "Casino\n casino \nCASINO\n" );
eforms_test_assert( $duplicate['ok'] === false, 'Normalized duplicate terms should be rejected.' );
eforms_test_assert( $duplicate['errors'][0]['reason'] === 'duplicate', 'Duplicate failures should use reason=duplicate.' );

$long = ContentFilter::parse_terms_text( str_repeat( 'a', Anchors::get( 'CONTENT_FILTER_MAX_TERM_CHARS' ) + 1 ) );
eforms_test_assert( $long['ok'] === false, 'Oversized terms should be rejected.' );
eforms_test_assert( $long['errors'][0]['reason'] === 'max_chars', 'Oversized terms should use reason=max_chars.' );

$lines = array();
for ( $i = 0; $i < Anchors::get( 'CONTENT_FILTER_MAX_TERMS' ) + 1; $i++ ) {
    $lines[] = 'term' . $i;
}
$too_many = ContentFilter::parse_terms_text( implode( "\n", $lines ) );
eforms_test_assert( $too_many['ok'] === false, 'Oversized term lists should be rejected.' );
eforms_test_assert( $too_many['errors'][0]['reason'] === 'max_terms', 'Oversized term lists should use reason=max_terms.' );

$context = array(
    'descriptors' => array(
        array(
            'key' => 'alpha',
            'type' => 'textarea',
            'is_multivalue' => false,
            'handlers' => array( 'normalizer_id' => 'text' ),
        ),
        array(
            'key' => 'beta',
            'type' => 'name',
            'is_multivalue' => false,
            'handlers' => array( 'normalizer_id' => 'text' ),
        ),
        array(
            'key' => 'gamma',
            'type' => 'file',
            'is_multivalue' => false,
            'handlers' => array( 'normalizer_id' => 'file' ),
        ),
        array(
            'key' => 'delta',
            'type' => 'select',
            'is_multivalue' => false,
            'handlers' => array( 'normalizer_id' => 'choice' ),
        ),
        array(
            'key' => 'epsilon',
            'type' => 'email',
            'is_multivalue' => false,
            'handlers' => array( 'normalizer_id' => 'text' ),
        ),
        array(
            'key' => 'zeta',
            'type' => 'text',
            'is_multivalue' => true,
            'handlers' => array( 'normalizer_id' => 'text' ),
        ),
    ),
);

$values = array(
    'alpha' => 'Buy SEO    services today.',
    'beta' => 'Sloan',
    'gamma' => 'casino.pdf',
    'delta' => 'casino',
    'epsilon' => 'seller@example.com',
    'zeta' => array( 'casino' ),
    'protocol_extra' => 'casino',
);

$config = Config::defaults();
$config['spam']['content_filter']['mode'] = 'suspect';
$config['spam']['content_filter']['blocked_terms'] = "seo services\ncasino\nloan";

$result = ContentFilter::evaluate( $context, array( 'values' => $values ), $config );
eforms_test_assert( $result['matched'] === true, 'Content filter should match configured terms.' );
eforms_test_assert( $result['mode'] === 'suspect', 'Result should carry configured mode.' );
eforms_test_assert( $result['decision'] === 'suspect', 'Suspect mode should return suspect decision.' );
eforms_test_assert( $result['reason'] === 'content_blocked_term', 'Result should expose the stable content reason.' );
eforms_test_assert( $result['match_ids'] === array( sha1( 'seo services' ) ), 'Match IDs should be stable hashes of normalized terms.' );
eforms_test_assert( $result['field_keys'] === array( 'alpha' ), 'Field keys should come from matched scalar text descriptors only.' );
$encoded = json_encode( $result );
eforms_test_assert( strpos( $encoded, 'seo services' ) === false, 'Result should not expose raw matched term text.' );
eforms_test_assert( strpos( $encoded, 'casino' ) === false, 'Result should not expose unmatched raw term text.' );
$metadata = ContentFilter::safe_metadata( array_merge( $result, array( 'match_ids' => array( sha1( 'casino' ), 'casino' ) ) ) );
eforms_test_assert( $metadata['match_ids'] === array( sha1( 'casino' ) ), 'Safe metadata should retain only stable match hashes.' );
eforms_test_assert( strpos( json_encode( $metadata ), 'casino' ) === false, 'Safe metadata should not expose raw term text.' );
eforms_test_assert( ContentFilter::is_matched( $metadata ) === true, 'ContentFilter should own matched metadata detection.' );
eforms_test_assert( ContentFilter::is_suspect( $metadata ) === true, 'ContentFilter should own suspect decision detection.' );
eforms_test_assert( ContentFilter::is_reject( $metadata ) === false, 'ContentFilter should reject mismatched decision predicates.' );
eforms_test_assert( ContentFilter::safe_metadata( array( 'matched' => true, 'decision' => 'suspect', 'reason' => 'raw-term' ) ) === array(), 'Invalid matched metadata should be discarded.' );

$config['spam']['content_filter']['blocked_terms'] = 'loan';
$result = ContentFilter::evaluate( $context, array( 'values' => $values ), $config );
eforms_test_assert( $result['matched'] === false, 'Single words should not match inside larger words.' );

$values['beta'] = 'loan approved';
$result = ContentFilter::evaluate( $context, array( 'values' => $values ), $config );
eforms_test_assert( $result['matched'] === true, 'Single words should match at string boundaries.' );
eforms_test_assert( $result['field_keys'] === array( 'beta' ), 'Boundary word matches should report the matched field key.' );

$config['spam']['content_filter']['mode'] = 'reject';
$result = ContentFilter::evaluate( $context, array( 'values' => $values ), $config );
eforms_test_assert( $result['decision'] === 'reject', 'Reject mode should return reject decision.' );
eforms_test_assert( ContentFilter::is_reject( $result ) === true, 'ContentFilter should own reject decision detection.' );

$config['spam']['content_filter']['mode'] = 'off';
$result = ContentFilter::evaluate( $context, array( 'values' => $values ), $config );
eforms_test_assert( $result['matched'] === false && $result['decision'] === 'none', 'Off mode should not match.' );
