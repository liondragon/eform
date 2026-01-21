<?php
/**
 * Email template registry (internal only).
 *
 * Educational note: this keeps template validation deterministic without
 * binding TemplateValidator to filesystem lookups.
 *
 * Spec: Email templates (docs/Canonical_Spec.md#sec-email-templates)
 */

class EmailTemplateRegistry {
    const TEMPLATES = array(
        'default',
    );

    public static function exists( $name ) {
        return is_string( $name ) && in_array( $name, self::TEMPLATES, true );
    }
}
