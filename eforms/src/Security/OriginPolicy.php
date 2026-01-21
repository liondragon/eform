<?php
/**
 * Origin policy evaluation.
 *
 * Spec: Origin policy (docs/Canonical_Spec.md#sec-origin-policy)
 */

require_once __DIR__ . '/../Enums/SoftReason.php';

class OriginPolicy
{
    const DEFAULT_SCHEME = 'http';

    /**
     * Evaluate origin policy for the current request.
     *
     * @param mixed $request Optional request object/array.
     * @param array $config Frozen config snapshot.
     * @return array { state, hard_fail, soft_reasons }
     */
    public static function evaluate($request, $config)
    {
        $origin = self::header_value($request, 'Origin');
        if ($origin === '') {
            $state = 'missing';
        } else {
            $origin_norm = self::normalize_origin($origin);
            if ($origin_norm === null) {
                $state = 'unknown';
            } else {
                $server_norm = self::server_origin();
                if ($server_norm === null) {
                    $state = 'unknown';
                } elseif (self::origins_match($origin_norm, $server_norm)) {
                    $state = 'same';
                } else {
                    $state = 'cross';
                }
            }
        }

        $mode = self::config_string($config, array('security', 'origin_mode'), 'soft');
        $missing_hard = self::config_bool($config, array('security', 'origin_missing_hard'), false);

        $hard_fail = false;
        $soft_reasons = array();

        if ($mode === 'soft') {
            if ($state !== 'same') {
                $soft_reasons[] = SoftReason::OriginSoft;
            }
        } elseif ($mode === 'hard') {
            if ($state === 'cross' || $state === 'unknown') {
                $hard_fail = true;
            } elseif ($state === 'missing') {
                if ($missing_hard) {
                    $hard_fail = true;
                } else {
                    $soft_reasons[] = SoftReason::OriginSoft;
                }
            }
        }

        return array(
            'state' => $state,
            'hard_fail' => $hard_fail,
            'soft_reasons' => $soft_reasons,
        );
    }

    private static function header_value($request, $name)
    {
        if (is_object($request) && method_exists($request, 'get_header')) {
            $value = $request->get_header($name);
            if (is_string($value)) {
                return trim($value);
            }
        }

        if (is_array($request) && isset($request['headers']) && is_array($request['headers'])) {
            foreach ($request['headers'] as $key => $value) {
                if (is_string($key) && strcasecmp($key, $name) === 0 && is_string($value)) {
                    return trim($value);
                }
            }
        }

        $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$server_key]) && is_string($_SERVER[$server_key])) {
            return trim($_SERVER[$server_key]);
        }

        return '';
    }

    private static function server_origin()
    {
        $scheme = self::server_scheme();
        if ($scheme === '') {
            return null;
        }

        $host_raw = self::header_value(null, 'Host');
        if ($host_raw === '' && isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])) {
            $host_raw = $_SERVER['SERVER_NAME'];
        }

        $host = self::parse_host_port($host_raw);
        if ($host === null) {
            return null;
        }

        $port = $host['port'];
        if ($port === null) {
            $port = self::default_port($scheme);
        }

        return array(
            'scheme' => $scheme,
            'host' => $host['host'],
            'port' => $port,
        );
    }

    private static function server_scheme()
    {
        if (isset($_SERVER['HTTPS']) && is_string($_SERVER['HTTPS'])) {
            $value = strtolower($_SERVER['HTTPS']);
            if ($value !== '' && $value !== 'off' && $value !== '0') {
                return 'https';
            }
        }

        if (isset($_SERVER['REQUEST_SCHEME']) && is_string($_SERVER['REQUEST_SCHEME'])) {
            $scheme = strtolower($_SERVER['REQUEST_SCHEME']);
            if ($scheme === 'http' || $scheme === 'https') {
                return $scheme;
            }
        }

        if (isset($_SERVER['SERVER_PORT'])) {
            $port = (int) $_SERVER['SERVER_PORT'];
            if ($port === 443) {
                return 'https';
            }
            if ($port === 80) {
                return 'http';
            }
        }

        return self::DEFAULT_SCHEME;
    }

    private static function normalize_origin($origin)
    {
        if (!is_string($origin)) {
            return null;
        }

        $origin = trim($origin);
        if ($origin === '') {
            return null;
        }

        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host === '') {
            return null;
        }

        $port = null;
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port <= 0) {
                return null;
            }
        }

        if ($port === null) {
            $port = self::default_port($scheme);
        }

        return array(
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
        );
    }

    private static function parse_host_port($host_raw)
    {
        if (!is_string($host_raw)) {
            return null;
        }

        $host_raw = trim($host_raw);
        if ($host_raw === '') {
            return null;
        }

        $host_raw = preg_replace('/\\s+/', '', $host_raw);

        $parts = parse_url(self::DEFAULT_SCHEME . '://' . $host_raw);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host === '') {
            return null;
        }

        $port = null;
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port <= 0) {
                $port = null;
            }
        }

        return array(
            'host' => $host,
            'port' => $port,
        );
    }

    private static function origins_match($origin, $server)
    {
        return $origin['scheme'] === $server['scheme']
            && $origin['host'] === $server['host']
            && (int) $origin['port'] === (int) $server['port'];
    }

    private static function default_port($scheme)
    {
        return $scheme === 'https' ? 443 : 80;
    }

    private static function config_string($config, $path, $default)
    {
        $value = self::config_value($config, $path);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $default;
    }

    private static function config_bool($config, $path, $default)
    {
        $value = self::config_value($config, $path);
        if (is_bool($value)) {
            return $value;
        }

        return $default;
    }

    private static function config_value($config, $path)
    {
        if (!is_array($path)) {
            return null;
        }

        $cursor = $config;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !isset($cursor[$segment])) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
