<?php
declare(strict_types=1);

namespace EForms;

class Config
{
    protected static array $data = [];

    public static function bootstrap(): void
    {
        // Placeholder bootstrap; in real plugin this would load configuration.
        self::$data = [];
    }

    public static function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $value = self::$data;
        foreach ($segments as $seg) {
            if (is_array($value) && array_key_exists($seg, $value)) {
                $value = $value[$seg];
            } else {
                return $default;
            }
        }
        return $value;
    }
}
