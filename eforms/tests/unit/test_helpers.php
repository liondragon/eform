<?php
/**
 * Unit tests for shared Helpers.
 *
 * Spec: Central registries (internal only), Shared lifecycle and storage contract,
 * Security invariants (Anchors: [TOKEN_TTL_MAX]).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Helpers.php';

// Given a stable input id...
// When Helpers::h2 is called...
// Then it returns the two-character sha256 shard.
$expected_h2 = substr( hash( 'sha256', 'abc' ), 0, Helpers::H2_LENGTH );
eforms_test_assert( Helpers::h2( 'abc' ) === $expected_h2, 'Helpers::h2 should match sha256 shard.' );

// Given a string that does not need NFC changes...
// When Helpers::nfc is called...
// Then it returns a string and respects Normalizer when available.
$nfc_input  = "simple-ascii";
$nfc_output = Helpers::nfc( $nfc_input );
eforms_test_assert( is_string( $nfc_output ), 'Helpers::nfc should return a string.' );
if ( class_exists( 'Normalizer' ) ) {
    $expected = Normalizer::normalize( $nfc_input, Normalizer::FORM_C );
    if ( $expected !== false && $expected !== null ) {
        eforms_test_assert( $nfc_output === $expected, 'Helpers::nfc should match Normalizer output.' );
    }
}

// Given a short id...
// When Helpers::cap_id is called...
// Then it is returned as-is.
$short_id = 'short-id';
eforms_test_assert( Helpers::cap_id( $short_id ) === $short_id, 'Helpers::cap_id should preserve short ids.' );

// Given an id longer than the cap...
// When Helpers::cap_id is called...
// Then it is truncated with a stable suffix.
$long_id = str_repeat( 'a', Helpers::CAP_ID_DEFAULT_MAX + 5 );
$capped  = Helpers::cap_id( $long_id );
eforms_test_assert( strlen( $capped ) <= Helpers::CAP_ID_DEFAULT_MAX, 'Helpers::cap_id should enforce max length.' );
$pattern = '/[a-z2-7]{' . Helpers::CAP_ID_SUFFIX_LENGTH . '}$/';
eforms_test_assert( preg_match( $pattern, $capped ) === 1, 'Helpers::cap_id should include a base32 suffix.' );

eforms_test_assert( Helpers::cap_id( $long_id ) === $capped, 'Helpers::cap_id should be stable.' );

// Given PHP INI size strings...
// When Helpers::bytes_from_ini is called...
// Then it returns byte counts with correct units.
eforms_test_assert( Helpers::bytes_from_ini( '0' ) === PHP_INT_MAX, 'Helpers::bytes_from_ini should treat 0 as unlimited.' );
eforms_test_assert( Helpers::bytes_from_ini( '1k' ) === Helpers::BYTES_IN_KIB, 'Helpers::bytes_from_ini should parse KiB.' );
eforms_test_assert( Helpers::bytes_from_ini( '2M' ) === 2 * Helpers::BYTES_IN_MIB, 'Helpers::bytes_from_ini should parse MiB.' );
eforms_test_assert( Helpers::bytes_from_ini( '1G' ) === Helpers::BYTES_IN_GIB, 'Helpers::bytes_from_ini should parse GiB.' );

// Given a resolved client IP...
// When Helpers::throttle_key is called...
// Then it returns the sha256 hash of that IP.
$ip       = '203.0.113.10';
$expected = hash( 'sha256', $ip );
eforms_test_assert( Helpers::throttle_key( $ip ) === $expected, 'Helpers::throttle_key should hash the IP.' );

$threw = false;
try {
    Helpers::throttle_key( array() );
} catch ( InvalidArgumentException $e ) {
    $threw = true;
}
eforms_test_assert( $threw, 'Helpers::throttle_key should reject missing IPs.' );
