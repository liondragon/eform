<?php
/**
 * Origin policy evaluation.
 *
 * Contract: Origin policy
 */

require_once __DIR__ . '/../Config.php';

class OriginPolicy
{
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
                $same_origin = self::same_origin();
                if ($same_origin === null) {
                    $state = 'unknown';
                } elseif (self::origins_match($origin_norm, $same_origin)) {
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
                $soft_reasons[] = 'origin_soft';
            }
        } elseif ($mode === 'hard') {
            if ($state === 'cross' || $state === 'unknown') {
                $hard_fail = true;
            } elseif ($state === 'missing') {
                if ($missing_hard) {
                    $hard_fail = true;
                } else {
                    $soft_reasons[] = 'origin_soft';
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
        if (is_object($request)) {
            if (method_exists($request, 'get_header')) {
                $value = $request->get_header($name);
                if (is_string($value)) {
                    return trim($value);
                }
            }

            return '';
        }

        if (is_array($request)) {
            if (isset($request['headers']) && is_array($request['headers'])) {
                foreach ($request['headers'] as $key => $value) {
                    if (is_string($key) && strcasecmp($key, $name) === 0 && is_string($value)) {
                        return trim($value);
                    }
                }
            }

            return '';
        }

        $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$server_key]) && is_string($_SERVER[$server_key])) {
            return trim($_SERVER[$server_key]);
        }

        return '';
    }

    private static function same_origin()
    {
        return self::site_origin();
    }

    private static function site_origin()
    {
        if (!function_exists('home_url')) {
            return null;
        }

        $home = home_url();
        if (!is_string($home) || trim($home) === '') {
            return null;
        }

        return self::normalize_site_origin($home);
    }

    private static function normalize_origin($origin)
    {
        return self::normalize_origin_value($origin, false);
    }

    private static function normalize_site_origin($origin)
    {
        return self::normalize_origin_value($origin, true);
    }

    private static function normalize_origin_value($origin, $allow_path)
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
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (!$allow_path && isset($parts['path']) && $parts['path'] !== '') {
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

        $port = isset($parts['port']) ? (int) $parts['port'] : self::default_port($scheme);
        if ($port <= 0) {
            return null;
        }

        return array(
            'scheme' => $scheme,
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
        $value = Config::value($config, $path);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $default;
    }

    private static function config_bool($config, $path, $default)
    {
        return Config::bool($config, $path, $default);
    }
}
