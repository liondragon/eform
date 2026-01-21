<?php
/**
 * Field type registry (internal only).
 *
 * Educational note: This registry is intentionally static and deterministic.
 * Unknown types fail fast with a structured RuntimeException payload.
 *
 * Spec: Central registries (docs/Canonical_Spec.md#sec-central-registries)
 */

require_once __DIR__ . '/FieldTypes/TextLike.php';
require_once __DIR__ . '/FieldTypes/Textarea.php';
require_once __DIR__ . '/FieldTypes/Choice.php';
require_once __DIR__ . '/FieldTypes/Upload.php';

class FieldTypeRegistry {
    /**
     * Resolve a field type descriptor.
     *
     * @param string $type Field type identifier.
     * @return array
     */
    public static function resolve( $type ) {
        if ( ! is_string( $type ) || $type === '' ) {
            throw self::unknown_handler( $type );
        }

        if ( FieldTypes_TextLike::supports( $type ) ) {
            return FieldTypes_TextLike::descriptor( $type );
        }

        if ( FieldTypes_Textarea::supports( $type ) ) {
            return FieldTypes_Textarea::descriptor();
        }

        if ( FieldTypes_Choice::supports( $type ) ) {
            return FieldTypes_Choice::descriptor( $type );
        }

        if ( FieldTypes_Upload::supports( $type ) ) {
            return FieldTypes_Upload::descriptor( $type );
        }

        throw self::unknown_handler( $type );
    }

    private static function unknown_handler( $id ) {
        $payload = array(
            'type' => 'handler_resolution',
            'id' => $id,
            'registry' => 'FieldTypeRegistry',
            'spec_path' => 'docs/Canonical_Spec.md#sec-central-registries',
        );

        return new RuntimeException( json_encode( $payload ) );
    }
}
