<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

global $TEST_QUERY_VARS, $TEST_ARTIFACTS;

$TEST_QUERY_VARS['eforms_prime'] = 1;
$cookieName = 'eforms_eid_contact_us';
$headersPath = $TEST_ARTIFACTS['headers_file'];
$uploadsBase = dirname(__DIR__) . '/tmp/uploads/eforms-private';
$ttl = (int) \EForms\Config::get('security.token_ttl_seconds', 600);
$issuedAt = time() - 5;
$eid = 'i-00000000-0000-4000-8000-0000000cafe0';
set_eid_cookie('contact_us', $eid, $issuedAt, $ttl);
$hash = hash('sha256', $eid);
$mintPath = $uploadsBase . '/eid_minted/contact_us/' . substr($hash, 0, 2) . '/' . $eid . '.json';
$mintBefore = [];
if (is_file($mintPath)) {
    $raw = file_get_contents($mintPath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $mintBefore = $decoded;
        }
    }
}

register_shutdown_function(function () use ($mintBefore, $mintPath, $headersPath, $cookieName, $TEST_ARTIFACTS): void {
    $mintAfter = [];
    if (is_file($mintPath)) {
        $raw = file_get_contents($mintPath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $mintAfter = $decoded;
            }
        }
    }
    $issuedBefore = isset($mintBefore['issued_at']) ? (int) $mintBefore['issued_at'] : 0;
    $issuedAfter = isset($mintAfter['issued_at']) ? (int) $mintAfter['issued_at'] : 0;
    $expiresBefore = isset($mintBefore['expires']) ? (int) $mintBefore['expires'] : 0;
    $expiresAfter = isset($mintAfter['expires']) ? (int) $mintAfter['expires'] : 0;
    $hasMintedTimestamps = isset($mintBefore['issued_at'], $mintBefore['expires'], $mintAfter['issued_at'], $mintAfter['expires']);
    $timestampsEqual = ($hasMintedTimestamps && $issuedBefore === $issuedAfter && $expiresBefore === $expiresAfter) ? '1' : '0';
    $headersAfter = (string) file_get_contents($headersPath);
    $secondSetCookie = str_contains($headersAfter, 'Set-Cookie') ? '1' : '0';
    $resultLines = [
        'timestamps_equal=' . $timestampsEqual,
        'second_set_cookie=' . $secondSetCookie,
        'eid=' . ($mintAfter['eid'] ?? ''),
        'issued_at_before=' . $issuedBefore,
        'issued_at_after=' . $issuedAfter,
        'expires_before=' . $expiresBefore,
        'expires_after=' . $expiresAfter,
    ];
    file_put_contents($TEST_ARTIFACTS['dir'] . '/prime_remint.txt', implode("\n", $resultLines) . "\n");
    unset($_COOKIE[$cookieName]);
});

$getSnapshot = $_GET;
$_GET['f'] = 'contact_us';

do_action('template_redirect');

$_GET = $getSnapshot;
