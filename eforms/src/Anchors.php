<?php
/**
 * Spec-defined invariants (anchors).
 *
 * These values are copied from docs/Canonical_Spec.md §sec-anchors at implementation time.
 * They define min/max bounds and fixed constants. Code MUST NOT parse the spec at runtime.
 *
 * If a spec anchor changes, update the corresponding value here.
 */

class Anchors
{
    /**
     * All anchor values from docs/Canonical_Spec.md §sec-anchors.
     */
    const VALUES = array(
        'MIN_FILL_SECONDS_MIN' => 0,
        'MIN_FILL_SECONDS_MAX' => 60,
        'TOKEN_TTL_MIN' => 1,
        'TOKEN_TTL_MAX' => 86400,
        'MAX_FORM_AGE_MIN' => 1,
        'MAX_FORM_AGE_MAX' => 86400,
        'LEDGER_GC_GRACE_SECONDS' => 3600,
        'CHALLENGE_TIMEOUT_MIN' => 1,
        'CHALLENGE_TIMEOUT_MAX' => 5,
        'THROTTLE_MAX_PER_MIN_MIN' => 1,
        'THROTTLE_MAX_PER_MIN_MAX' => 120,
        'THROTTLE_COOLDOWN_MIN' => 0,
        'THROTTLE_COOLDOWN_MAX' => 600,
        'LOGGING_LEVEL_MIN' => 0,
        'LOGGING_LEVEL_MAX' => 2,
        'RETENTION_DAYS_MIN' => 1,
        'RETENTION_DAYS_MAX' => 365,
        'MAX_FIELDS_MIN' => 1,
        'MAX_FIELDS_MAX' => 1000,
        'MAX_OPTIONS_MIN' => 1,
        'MAX_OPTIONS_MAX' => 1000,
        'MAX_MULTIVALUE_MIN' => 1,
        'MAX_MULTIVALUE_MAX' => 1000,
    );

    /**
     * Get an anchor value by name.
     *
     * @param string $name Anchor name (e.g., 'TOKEN_TTL_MAX').
     * @return int|null The value, or null if not found.
     */
    public static function get($name)
    {
        if (isset(self::VALUES[$name])) {
            return self::VALUES[$name];
        }

        // Warn in dev mode so typos are caught early.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            trigger_error("Unknown anchor: $name", E_USER_WARNING);
        }

        return null;
    }
}
