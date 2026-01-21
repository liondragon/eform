<?php
/**
 * Validator registry (internal only).
 *
 * Educational note: this registry maps handler IDs to callables and throws a
 * deterministic error payload when an unknown ID is requested.
 *
 * Spec: Central registries (docs/Canonical_Spec.md#sec-central-registries)
 */

class ValidatorRegistry {
    const HANDLERS = array(
        'text' => array( self::class, 'validate_text' ),
        'zip' => array( self::class, 'validate_text' ),
        'zip_us' => array( self::class, 'validate_text' ),
        'number' => array( self::class, 'validate_text' ),
        'range' => array( self::class, 'validate_text' ),
        'date' => array( self::class, 'validate_text' ),
        'file' => array( self::class, 'validate_upload' ),
        'files' => array( self::class, 'validate_upload' ),
        'choice' => array( self::class, 'validate_choice' ),
    );

    /**
     * Resolve a validator handler by id.
     *
     * @param string $id Handler id.
     * @return callable
     */
    public static function resolve( $id ) {
        if ( ! is_string( $id ) || $id === '' ) {
            throw self::unknown_handler( $id );
        }

        if ( ! isset( self::HANDLERS[ $id ] ) ) {
            throw self::unknown_handler( $id );
        }

        return self::HANDLERS[ $id ];
    }

    public static function validate_text( $value, $descriptor, $errors ) {
        return $value;
    }

    public static function validate_choice( $value, $descriptor, $errors ) {
        return $value;
    }

    public static function validate_upload( $value, $descriptor, $errors ) {
        // Educational note: upload validation is implemented with the uploads subsystem.
        throw new RuntimeException( 'Upload validation not implemented.' );
    }

    private static function unknown_handler( $id ) {
        $payload = array(
            'type' => 'handler_resolution',
            'id' => $id,
            'registry' => 'ValidatorRegistry',
            'spec_path' => 'docs/Canonical_Spec.md#sec-central-registries',
        );

        return new RuntimeException( json_encode( $payload ) );
    }
}
