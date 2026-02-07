<?php
/**
 * Unit tests for privacy client-IP resolution and presentation.
 *
 * Spec: Privacy and IP handling (docs/Canonical_Spec.md#sec-privacy)
 * Spec: Throttling (docs/Canonical_Spec.md#sec-throttling)
 * Spec: Logging (docs/Canonical_Spec.md#sec-logging)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Helpers.php';
require_once __DIR__ . '/../../src/Privacy/ClientIp.php';

if ( ! function_exists( 'eforms_test_merge_assoc' ) ) {
    function eforms_test_merge_assoc( $base, $overrides ) {
        $result = is_array( $base ) ? $base : array();
        if ( ! is_array( $overrides ) ) {
            return $result;
        }

        foreach ( $overrides as $key => $value ) {
            if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) && is_array( $value ) ) {
                $result[ $key ] = eforms_test_merge_assoc( $result[ $key ], $value );
            } else {
                $result[ $key ] = $value;
            }
        }

        return $result;
    }
}

$defaults = Config::defaults();
$base_config = eforms_test_merge_assoc(
    $defaults,
    array(
        'privacy' => array(
            'client_ip_header' => 'X-Forwarded-For',
            'trusted_proxies' => array( '10.0.0.0/8' ),
        ),
    )
);

// Given an untrusted remote source with a forged forwarded header...
// When client IP resolution runs...
// Then REMOTE_ADDR wins and forwarded data is ignored.
$untrusted_request = array(
    'remote_addr' => '198.51.100.20',
    'headers' => array(
        'X-Forwarded-For' => '8.8.8.8',
    ),
);
$resolved = ClientIp::resolve( $untrusted_request, $base_config );
eforms_test_assert( $resolved === '198.51.100.20', 'Untrusted proxy headers must be ignored.' );

// Given a trusted proxy and a forwarded chain...
// When client IP resolution runs...
// Then the left-most public IP is selected.
$trusted_request = array(
    'remote_addr' => '10.1.2.3',
    'headers' => array(
        'X-Forwarded-For' => '8.8.8.8, 10.2.3.4',
    ),
);
$resolved = ClientIp::resolve( $trusted_request, $base_config );
eforms_test_assert( $resolved === '8.8.8.8', 'Trusted proxy resolution should select the left-most public IP.' );

// Given a trusted proxy with only private forwarded candidates...
// When client IP resolution runs...
// Then resolution falls back to REMOTE_ADDR.
$private_only_request = array(
    'remote_addr' => '10.1.2.3',
    'headers' => array(
        'X-Forwarded-For' => '10.4.5.6, 192.168.1.9',
    ),
);
$resolved = ClientIp::resolve( $private_only_request, $base_config );
eforms_test_assert( $resolved === '10.1.2.3', 'Private-only forwarded chains must fall back to REMOTE_ADDR.' );

// Given case-insensitive headers with ports/brackets...
// When client IP resolution runs...
// Then literals are parsed safely and the first public literal is chosen.
$formatted_header_request = array(
    'remote_addr' => '10.2.3.4',
    'headers' => array(
        'x-forwarded-for' => ' [2001:4860:4860::8888]:443, 8.8.8.8:1234 ',
    ),
);
$resolved = ClientIp::resolve( $formatted_header_request, $base_config );
eforms_test_assert( $resolved === '2001:4860:4860::8888', 'Forwarded header parsing should support case-insensitive names and bracket/port forms.' );

// Given privacy presentation modes...
// When an IPv4 value is presented...
// Then presentation follows the mode contract.
$none_config = eforms_test_merge_assoc( $base_config, array( 'privacy' => array( 'ip_mode' => 'none' ) ) );
$masked_config = eforms_test_merge_assoc( $base_config, array( 'privacy' => array( 'ip_mode' => 'masked' ) ) );
$hash_config = eforms_test_merge_assoc( $base_config, array( 'privacy' => array( 'ip_mode' => 'hash' ) ) );
$full_config = eforms_test_merge_assoc( $base_config, array( 'privacy' => array( 'ip_mode' => 'full' ) ) );

eforms_test_assert( ClientIp::present( '8.8.8.8', $none_config ) === '', 'ip_mode=none should omit the IP.' );
eforms_test_assert( ClientIp::present( '8.8.8.8', $masked_config ) === '8.8.8.0', 'ip_mode=masked should redact the IPv4 last octet.' );
eforms_test_assert( ClientIp::present( '8.8.8.8', $hash_config ) === hash( 'sha256', '8.8.8.8' ), 'ip_mode=hash should emit a stable sha256 hash.' );
eforms_test_assert( ClientIp::present( '8.8.8.8', $full_config ) === '8.8.8.8', 'ip_mode=full should emit the literal IP.' );
eforms_test_assert( ClientIp::present( '2001:4860:4860::8888', $masked_config ) === '2001:4860:4860::', 'IPv6 masking should zero the last 80 bits.' );
eforms_test_assert( ClientIp::should_include_email_ip( $none_config ) === false, 'Email IP include should be disabled when ip_mode=none.' );
eforms_test_assert( ClientIp::should_include_email_ip( $masked_config ) === true, 'Email IP include should be enabled when ip_mode!=none.' );

// Given throttle key derivation and varying presentation modes...
// When throttle keys are generated...
// Then key derivation uses resolved IP independent of privacy.ip_mode.
$throttle_request = array(
    'remote_addr' => '10.9.8.7',
    'headers' => array(
        'X-Forwarded-For' => '8.8.4.4',
    ),
);
$key_none = Helpers::throttle_key( $throttle_request, $none_config );
$key_full = Helpers::throttle_key( $throttle_request, $full_config );
$expected_key = hash( 'sha256', '8.8.4.4' );
eforms_test_assert( $key_none === $expected_key, 'Throttle key must hash the resolved client IP.' );
eforms_test_assert( $key_full === $expected_key, 'Throttle key must ignore privacy.ip_mode presentation settings.' );

// Given full mode and the minimal sink...
// When a logging IP is presented...
// Then minimal mode redacts while structured sinks can honor full mode.
eforms_test_assert( ClientIp::present_for_logging( '8.8.8.8', $full_config, 'minimal' ) === '8.8.8.0', 'Minimal logging must redact full IP mode.' );
eforms_test_assert( ClientIp::present_for_logging( '8.8.8.8', $full_config, 'jsonl' ) === '8.8.8.8', 'Non-minimal sinks should preserve full mode presentation.' );
