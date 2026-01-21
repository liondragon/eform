<?php
/**
 * Structured error container for renderer + submit flows.
 * 
 * PHP 8.1+ version with constructor promotion.
 *
 * Spec: Error handling (docs/Canonical_Spec.md#sec-error-handling)
 */

require_once __DIR__ . '/Enums/ErrorCode.php';

class Errors
{
    /**
     * @param array<array{code: string, message?: string}> $global
     * @param array<string, array<array{code: string, message?: string}>> $fields
     */
    public function __construct(
        private array $global = [],
        private array $fields = [],
    ) {
    }

    /**
     * Add a global error (stored under _global).
     */
    public function add_global(string|ErrorCode $code, string $message = ''): void
    {
        $this->global[] = self::error_entry($code, $message);
    }

    /**
     * Add a field error (stored under that field key).
     */
    public function add_field(string $field_key, string|ErrorCode $code, string $message = ''): void
    {
        if ($field_key === '') {
            $this->add_global($code, $message);
            return;
        }

        $this->fields[$field_key] ??= [];
        $this->fields[$field_key][] = self::error_entry($code, $message);
    }

    /**
     * True when any errors exist.
     */
    public function any(): bool
    {
        if (!empty($this->global)) {
            return true;
        }

        foreach ($this->fields as $entries) {
            if (!empty($entries)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Export to the canonical shape: global under _global, fields by key.
     * 
     * @return array<string, array<array{code: string, message?: string}>>
     */
    public function to_array(): array
    {
        $out = [
            '_global' => $this->global,
        ];

        foreach ($this->fields as $key => $entries) {
            $out[$key] = $entries;
        }

        return $out;
    }

    /**
     * @return array{code: string, message?: string}
     */
    private static function error_entry(string|ErrorCode $code, string $message): array
    {
        $codeString = $code instanceof ErrorCode ? $code->value : $code;

        $entry = [
            'code' => $codeString,
        ];

        if ($message !== '') {
            $entry['message'] = $message;
        }

        // Warn in dev so typos are caught early
        if (defined('WP_DEBUG') && WP_DEBUG && $codeString !== '') {
            if (!ErrorCode::isKnown($codeString)) {
                trigger_error('Unknown error code: ' . $codeString, E_USER_WARNING);
            }
        }

        return $entry;
    }
}
