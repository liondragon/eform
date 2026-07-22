<?php
/**
 * Fixed runtime invariants.
 *
 * Fixed runtime bounds and constants for eForms.
 * Code MUST NOT parse documentation at runtime.
 *
 * If a fixed value changes, update the corresponding tests and docs in the same task.
 */

class Anchors
{
    /**
     * All fixed runtime bounds and constants.
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
        'DECLINED_REVIEW_ADMIN_DEFAULT_DAYS' => 7,
        'DECLINED_REVIEW_ADMIN_MAX_DAYS' => 31,
        'DECLINED_REVIEW_SCAN_MAX_RECORDS' => 5000,
        'DECLINED_REVIEW_PAGE_SIZE' => 50,
        'DECLINED_REVIEW_MAX_FIELDS' => 100,
        'DECLINED_REVIEW_FIELD_MAX_BYTES' => 4096,
        'DECLINED_REVIEW_RECORD_FIELDS_MAX_BYTES' => 65536,
        'CONTENT_FILTER_MAX_TERMS' => 100,
        'CONTENT_FILTER_MAX_TERM_CHARS' => 80,
        'MANAGED_UPLOAD_MAX_BYTES' => 53687091200,
        'MANAGED_UPLOAD_MIN_FREE_BYTES' => 10737418240,
        'MANAGED_RESERVATION_STALE_SECONDS' => 3600,
        'MANAGED_STAGED_DELETE_GRACE_SECONDS' => 86400,
        'MANAGED_FINALIZED_TTL_SECONDS' => 2592000,
        'MANAGED_BATCH_ID_CHARS' => 43,
        'MANAGED_ID_MAX_CHARS' => 128,
        'MANAGED_BATCH_SECRET_BYTES' => 32,
        'MANAGED_UPLOAD_ID_BYTES' => 16,
        'MANAGED_UPLOAD_CONCURRENCY' => 3,
        'MANAGED_DISPLAY_NAME_MAX_CHARS' => 128,
        'STAGED_IMAGE_MAX_PIXELS' => 60000000,
        'STAGED_IMAGE_MAX_EDGE' => 12000,
        'STAGED_MULTIPART_OVERHEAD_BYTES' => 1048576,
        'STAGED_MASTER_MAX_EDGE' => 4096,
        'STAGED_MASTER_MAX_BYTES' => 8388608,
        'STAGED_MASTER_JPEG_QUALITY_INITIAL' => 88,
        'STAGED_MASTER_JPEG_QUALITY_STEP' => 4,
        'STAGED_MASTER_EDGE_STEP' => 512,
        'STAGED_MASTER_MAX_ATTEMPTS' => 5,
        'STAGED_PREVIEW_MAX_EDGE' => 1600,
        'STAGED_PREVIEW_MAX_BYTES' => 2097152,
        'STAGED_PREVIEW_JPEG_QUALITY_INITIAL' => 82,
        'STAGED_PREVIEW_JPEG_QUALITY_STEP' => 4,
        'STAGED_PREVIEW_EDGE_STEP' => 160,
        'STAGED_PREVIEW_MAX_ATTEMPTS' => 5,
        'STAGED_IMAGE_MIN_MEMORY_BYTES' => 805306368,
        'STAGED_IMAGE_MIN_EXECUTION_SECONDS' => 60,
    );

    /**
     * Get a fixed runtime value by name.
     *
     * @param string $name Value name (e.g., 'TOKEN_TTL_MAX').
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
