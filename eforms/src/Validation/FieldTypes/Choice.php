<?php
/**
 * Choice field type descriptors (select/radio/checkbox).
 *
 * Educational note: descriptors are pure data; renderers/validators interpret them.
 *
 * Spec: Field types (docs/Canonical_Spec.md#sec-field-types)
 */

class FieldTypes_Choice {
    const SUPPORTED = array(
        'select',
        'radio',
        'checkbox',
    );

    public static function supports( $type ) {
        return is_string( $type ) && in_array( $type, self::SUPPORTED, true );
    }

    public static function descriptor( $type ) {
        $descriptor = array(
            'type' => $type,
            'alias_of' => null,
            'is_multivalue' => false,
            'html' => array(
                'tag' => 'input',
            ),
            'validate' => array(),
            'handlers' => array(
                'validator_id' => 'choice',
                'normalizer_id' => 'choice',
                'renderer_id' => 'choice',
            ),
            'constants' => array(),
            'defaults' => array(),
        );

        if ( $type === 'select' ) {
            $descriptor['html']['tag'] = 'select';
            return $descriptor;
        }

        if ( $type === 'radio' ) {
            $descriptor['html']['type'] = 'radio';
            return $descriptor;
        }

        $descriptor['html']['type'] = 'checkbox';
        $descriptor['is_multivalue'] = true;

        return $descriptor;
    }
}

