<?php
/**
 * Renderer helper for upload fields.
 *
 * Educational note: upload rendering is deferred until the uploads subsystem
 * is implemented; this stub prevents silent misuse.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 */

class FieldRenderers_Upload {
    public static function render( $descriptor, $field, $value = null, $context = array() ) {
        throw new RuntimeException( 'Upload rendering not implemented.' );
    }
}
