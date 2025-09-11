<?php
declare(strict_types=1);

namespace EForms;

/**
 * Internal registries mapping field types to handlers.
 */
class Registry
{
    /**
     * Map field types to validator callbacks.
     * Each callback signature: function(array $tpl, array $f, string $v, string $k, array &$errors): string
     * Should return canonical value (may be unchanged).
     */
    private const VALIDATORS = [
        'email' => [Validator::class, 'validateEmail'],
        'url' => [Validator::class, 'validateUrl'],
        'zip_us' => [Validator::class, 'validateZipUs'],
        'tel_us' => [Validator::class, 'validateTelUs'],
        'number' => [Validator::class, 'validateNumber'],
        'range' => [Validator::class, 'validateNumber'],
        'date' => [Validator::class, 'validateDate'],
        'textarea_html' => [Validator::class, 'validateTextareaHtml'],
        'select' => [Validator::class, 'validateChoice'],
        'radio' => [Validator::class, 'validateChoice'],
        'checkbox' => [Validator::class, 'validateChoice'],
    ];

    /**
     * Map field types to coercer callbacks.
     * Each callback signature: function($v): string|array
     */
    private const COERCERS = [
        'email' => [Validator::class, 'coerceEmail'],
        'tel_us' => [Validator::class, 'coerceTelUs'],
    ];

    /**
     * Map field types to renderer callbacks.
     * Renderer signature: function(array $ctx): string
     */
    private const RENDERERS = [
        'textarea' => [Renderer::class, 'renderTextarea'],
        'textarea_html' => [Renderer::class, 'renderTextarea'],
        'select' => [Renderer::class, 'renderSelect'],
        'radio' => [Renderer::class, 'renderFieldset'],
        'checkbox' => [Renderer::class, 'renderFieldset'],
        'file' => [Renderer::class, 'renderInput'],
        'files' => [Renderer::class, 'renderInput'],
    ];

    public static function validator(string $type): ?callable
    {
        return self::VALIDATORS[$type] ?? null;
    }

    public static function coercer(string $type): ?callable
    {
        return self::COERCERS[$type] ?? null;
    }

    public static function renderer(string $type): callable
    {
        return self::RENDERERS[$type] ?? [Renderer::class, 'renderInput'];
    }
}
