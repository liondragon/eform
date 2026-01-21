<?php
/**
 * Upload field type descriptors (file/files).
 *
 * Educational note: descriptors are pure data; upload handlers are stubbed
 * until the uploads subsystem lands.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 */

class FieldTypes_Upload {
    const SUPPORTED = array(
        'file',
        'files',
    );

    public static function supports( $type ) {
        return is_string( $type ) && in_array( $type, self::SUPPORTED, true );
    }

    public static function descriptor( $type ) {
        $descriptor = array(
            'type' => $type,
            'alias_of' => null,
            'is_multivalue' => $type === 'files',
            'html' => array(
                'tag' => 'input',
                'type' => 'file',
                'attrs_mirror' => array( 'accept', 'multiple' ),
            ),
            'validate' => array(),
            'handlers' => array(
                'validator_id' => $type,
                'normalizer_id' => $type,
                'renderer_id' => $type,
            ),
            'constants' => array(),
            'defaults' => array(),
        );

        if ( $type === 'files' ) {
            $descriptor['html']['multiple'] = true;
        }

        return $descriptor;
    }
}
