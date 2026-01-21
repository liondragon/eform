<?php
/**
 * Shared helpers for sharding, canonicalization, and size parsing.
 * 
 * PHP 8.1+ version with match expressions.
 */

class Helpers
{
    const H2_LENGTH = 2;
    const CAP_ID_DEFAULT_MAX = 128;
    const CAP_ID_SUFFIX_LENGTH = 8;

    const BYTES_IN_KIB = 1024;
    const BYTES_IN_MIB = 1048576;    // 1024 * 1024.
    const BYTES_IN_GIB = 1073741824; // 1024 * 1024 * 1024.

    const BASE32_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    private static bool $config_bootstrapped = false;

    /**
     * Normalize to Unicode NFC; no-op when intl is unavailable.
     */
    public static function nfc(mixed $value): mixed
    {
        self::ensure_config();

        if (!is_string($value)) {
            return $value;
        }

        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if ($normalized !== false && $normalized !== null) {
                return $normalized;
            }
        }

        return $value;
    }

    /**
     * Derive the two-character shard prefix from the SHA-256 of the id.
     */
    public static function h2(string $id): string
    {
        self::ensure_config();

        $hash = hash('sha256', $id);

        return substr($hash, 0, self::H2_LENGTH);
    }

    /**
     * Cap identifier length with a stable suffix to preserve uniqueness.
     */
    public static function cap_id(string $id, int $max = self::CAP_ID_DEFAULT_MAX): string
    {
        self::ensure_config();

        if ($max <= 0) {
            return '';
        }

        if (strlen($id) <= $max) {
            return $id;
        }

        $suffix = self::stable_suffix($id);
        $available = $max - self::CAP_ID_SUFFIX_LENGTH - 1;
        if ($available <= 0) {
            return substr($suffix, 0, $max);
        }

        $head = (int) floor($available / 2);
        $tail = $available - $head;

        return substr($id, 0, $head) . '-' . $suffix . substr($id, -$tail);
    }

    /**
     * Parse PHP INI sizes (K/M/G). "0" or empty means unlimited.
     */
    public static function bytes_from_ini(?string $raw): int
    {
        self::ensure_config();

        if ($raw === null || $raw === '') {
            return PHP_INT_MAX;
        }

        $value = trim($raw);
        if ($value === '' || $value === '0') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $multiplier = match ($unit) {
            'k' => self::BYTES_IN_KIB,
            'm' => self::BYTES_IN_MIB,
            'g' => self::BYTES_IN_GIB,
            default => 1,
        };

        if ($multiplier > 1) {
            $value = substr($value, 0, -1);
        }

        $number = (int) $value;
        if ($number < 0) {
            return 0;
        }

        if ($multiplier > 1) {
            $limit = intdiv(PHP_INT_MAX, $multiplier);
            if ($number > $limit) {
                return PHP_INT_MAX;
            }
        }

        return $number * $multiplier;
    }

    /**
     * Derive the throttle key from a resolved client IP.
     */
    public static function throttle_key(mixed $request): string
    {
        self::ensure_config();

        $ip = match (true) {
            is_string($request) => $request,
            is_array($request) && isset($request['client_ip']) => $request['client_ip'],
            is_object($request) && isset($request->client_ip) => $request->client_ip,
            is_object($request) && method_exists($request, 'get_client_ip') => $request->get_client_ip(),
            default => '',
        };

        if (!is_string($ip) || $ip === '') {
            throw new InvalidArgumentException('Resolved client IP is required.');
        }

        return hash('sha256', $ip);
    }

    /**
     * Ensure Config::get() is invoked at least once when available.
     */
    private static function ensure_config(): void
    {
        if (self::$config_bootstrapped) {
            return;
        }

        self::$config_bootstrapped = true;

        if (class_exists('Config') && method_exists('Config', 'get')) {
            Config::get();
        }
    }

    /**
     * Build a stable 8-character base32 suffix for long IDs.
     */
    private static function stable_suffix(string $value): string
    {
        $hash = hash('sha256', $value, true);

        return substr(self::base32_encode($hash), 0, self::CAP_ID_SUFFIX_LENGTH);
    }

    /**
     * Basic base32 encoder (RFC 4648 alphabet, no padding).
     */
    private static function base32_encode(string $bytes): string
    {
        $bits = '';
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        $total = strlen($bits);
        for ($i = 0; $i < $total; $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }
}
