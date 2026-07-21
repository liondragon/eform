<?php
/**
 * Structured error container for renderer + submit flows.
 * 
 * Contract: Error handling
 */

require_once __DIR__ . '/ErrorCodes.php';

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
    public function add_global(string $code, string $message = ''): void
    {
        $this->global[] = self::error_entry($code, $message);
    }

    /**
     * Add a field error (stored under that field key).
     */
    public function add_field(string $field_key, string $code, string $message = ''): void
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
     * Return the first global or field error code from the canonical shape.
     */
    public static function first_code(mixed $errors, string $fallback = 'EFORMS_ERR_SCHEMA_OBJECT'): string
    {
        $data = $errors instanceof self ? $errors->to_array() : $errors;
        if (!is_array($data)) {
            return $fallback;
        }

        $groups = [];
        if (isset($data['_global']) && is_array($data['_global'])) {
            $groups[] = $data['_global'];
        }
        foreach ($data as $field_key => $entries) {
            if ($field_key !== '_global' && is_array($entries)) {
                $groups[] = $entries;
            }
        }

        foreach ($groups as $entries) {
            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry['code']) && is_string($entry['code'])) {
                    return $entry['code'];
                }
            }
        }

        return $fallback;
    }

    /**
     * @return array{code: string, message?: string}
     */
    private static function error_entry(string $code, string $message): array
    {
        $entry = [
            'code' => $code,
        ];

        if ($message !== '') {
            $entry['message'] = $message;
        }

        // Warn in dev so typos are caught early
        if (defined('WP_DEBUG') && WP_DEBUG && $code !== '') {
            if (!ErrorCodes::is_known($code)) {
                trigger_error('Unknown error code: ' . $code, E_USER_WARNING);
            }
        }

        return $entry;
    }
}
