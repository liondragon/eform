<?php
declare(strict_types=1);

namespace EForms;

class Helpers
{
    public static function esc_html(string $v): string
    {
        return \esc_html($v);
    }

    public static function esc_attr(string $v): string
    {
        return \esc_attr($v);
    }

    public static function esc_url(string $v): string
    {
        return \esc_url($v);
    }

    public static function esc_url_raw(string $v): string
    {
        return \esc_url_raw($v);
    }

    public static function sanitize_id(string $v): string
    {
        return \sanitize_key($v);
    }

    public static function bytes_from_ini(?string $v): int
    {
        // TODO: implement K/M/G suffix parsing
        return 0;
    }
}
