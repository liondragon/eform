<?php
/**
 * Textarea field type descriptor.
 *
 * Educational note: descriptors are pure data; renderers/validators interpret them.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 */

class FieldTypes_Textarea {
    public static function supports( $type ) {
        return $type === 'textarea';
    }

    public static function descriptor() {
        return array(
            'type' => 'textarea',
            'alias_of' => null,
            'is_multivalue' => false,
            'html' => array(
                'tag' => 'textarea',
                'attrs_mirror' => array( 'maxlength' ),
            ),
            'validate' => array(),
            'handlers' => array(
                'validator_id' => 'text',
                'normalizer_id' => 'text',
                'renderer_id' => 'textarea',
            ),
            'constants' => array(),
            'defaults' => array(),
        );
    }
}

