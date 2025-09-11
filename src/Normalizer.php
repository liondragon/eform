<?php
declare(strict_types=1);

namespace EForms;

class Normalizer
{
    /**
     * Registry mapping normalizer IDs to callable handlers.
     */
    private const HANDLERS = [
        '' => [self::class, 'identity'],
        'text' => [self::class, 'identity'],
        'email' => [self::class, 'normalizeEmail'],
        'url' => [self::class, 'identity'],
        'tel' => [self::class, 'identity'],
        'tel_us' => [self::class, 'normalizeTelUs'],
        'number' => [self::class, 'identity'],
        'range' => [self::class, 'identity'],
        'date' => [self::class, 'identity'],
        'textarea' => [self::class, 'identity'],
        'textarea_html' => [self::class, 'identity'],
        'zip' => [self::class, 'identity'],
        'zip_us' => [self::class, 'identity'],
        'select' => [self::class, 'identity'],
        'radio' => [self::class, 'identity'],
        'checkbox' => [self::class, 'identity'],
        'file' => [self::class, 'identity'],
        'files' => [self::class, 'identity'],
    ];

    /**
     * Resolve a normalizer handler by identifier.
     *
     * @throws \RuntimeException when the identifier is unknown
     */
    public static function resolve(string $id): callable
    {
        if (!isset(self::HANDLERS[$id])) {
            throw new \RuntimeException('Unknown normalizer ID: ' . $id);
        }
        return self::HANDLERS[$id];
    }

    /**
     * Default passthrough handler used for types that do not require
     * additional normalization.
     *
     * @param mixed $v
     * @return mixed
     */
    public static function identity($v)
    {
        return $v;
    }

    /**
     * Normalize email addresses by lowercasing the domain component.
     */
    public static function normalizeEmail(string $v): string
    {
        if ($v !== '' && strpos($v, '@') !== false) {
            [$local, $domain] = explode('@', $v, 2);
            return $local . '@' . strtolower($domain);
        }
        return $v;
    }

    /**
     * Normalize US telephone numbers by stripping non-digits and
     * removing a leading country code of 1.
     */
    public static function normalizeTelUs(string $v): string
    {
        $digits = preg_replace('/\D+/', '', $v);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        return $digits;
    }
}
