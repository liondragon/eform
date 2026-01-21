<?php
/**
 * Stable code surface (append-only) - PHP 8.1+ version.
 *
 * This file provides backward-compatible wrappers around the ErrorCode enum.
 *
 * @deprecated Use ErrorCode enum directly for new code.
 * Spec: Error handling (docs/Canonical_Spec.md#sec-error-handling)
 */

require_once __DIR__ . '/Enums/ErrorCode.php';

class ErrorCodes
{
    /**
     * All known codes (append-only).
     *
     * @deprecated Use ErrorCode::cases() instead.
     */
    const ALL = [
        'EFORMS_CHALLENGE_UNCONFIGURED',
        'EFORMS_CONFIG_CLAMPED',
        'EFORMS_CONFIG_DROPIN_INVALID',
        'EFORMS_CONFIG_DROPIN_IO',
        'EFORMS_ERR_ACCEPT_EMPTY',
        'EFORMS_ERR_CHALLENGE_FAILED',
        'EFORMS_ERR_DUPLICATE_FORM_ID',
        'EFORMS_ERR_EMAIL_SEND',
        'EFORMS_ERR_HONEYPOT',
        'EFORMS_ERR_INLINE_SUCCESS_REQUIRES_NONCACHEABLE',
        'EFORMS_ERR_INVALID_FORM_ID',
        'EFORMS_ERR_LEDGER_IO',
        'EFORMS_ERR_METHOD_NOT_ALLOWED',
        'EFORMS_ERR_MINT_FAILED',
        'EFORMS_ERR_ORIGIN_FORBIDDEN',
        'EFORMS_ERR_ROW_GROUP_UNBALANCED',
        'EFORMS_ERR_SCHEMA_DUP_KEY',
        'EFORMS_ERR_SCHEMA_ENUM',
        'EFORMS_ERR_SCHEMA_KEY',
        'EFORMS_ERR_SCHEMA_OBJECT',
        'EFORMS_ERR_SCHEMA_REQUIRED',
        'EFORMS_ERR_SCHEMA_TYPE',
        'EFORMS_ERR_SCHEMA_UNKNOWN_KEY',
        'EFORMS_ERR_STORAGE_UNAVAILABLE',
        'EFORMS_ERR_THROTTLED',
        'EFORMS_ERR_TOKEN',
        'EFORMS_ERR_TYPE',
        'EFORMS_ERR_UPLOAD_TYPE',
        'EFORMS_FAIL2BAN_IO',
        'EFORMS_FINFO_UNAVAILABLE',
        'EFORMS_LEDGER_IO',
        'EFORMS_RESERVE',
    ];

    /**
     * Return true when $code is in the stable surface.
     *
     * @deprecated Use ErrorCode::tryFrom($code) !== null
     */
    public static function is_known(string $code): bool
    {
        return ErrorCode::isKnown($code);
    }

    /**
     * Return true when $code is a user-facing error code.
     *
     * @deprecated Use ErrorCode::from($code)->isPublicError()
     */
    public static function is_public_error(string $code): bool
    {
        $enum = ErrorCode::tryFrom($code);
        return $enum?->isPublicError() ?? false;
    }
}
