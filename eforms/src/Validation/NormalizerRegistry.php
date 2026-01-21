<?php
/**
 * Normalizer/coercer registry (internal only).
 *
 * Educational note: this registry maps handler IDs to callables and throws a
 * deterministic error payload when an unknown ID is requested.
 *
 * Spec: Central registries (docs/Canonical_Spec.md#sec-central-registries)
 */

class NormalizerRegistry {
    const HANDLERS = array(
        'text' => array( self::class, 'normalize_text' ),
        'zip' => array( self::class, 'normalize_text' ),
        'zip_us' => array( self::class, 'normalize_text' ),
        'number' => array( self::class, 'normalize_text' ),
        'range' => array( self::class, 'normalize_text' ),
        'date' => array( self::class, 'normalize_text' ),
        'file' => array( self::class, 'normalize_upload' ),
        'files' => array( self::class, 'normalize_upload' ),
        'choice' => array( self::class, 'normalize_choice' ),
    );

    /**
     * Resolve a normalizer handler by id.
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

    public static function normalize_text( $value, $descriptor ) {
        return $value;
    }

    public static function normalize_choice( $value, $descriptor ) {
        return $value;
    }

    public static function normalize_upload( $value, $descriptor ) {
        // Educational note: upload normalization is implemented with the uploads subsystem.
        throw new RuntimeException( 'Upload normalization not implemented.' );
    }

    private static function unknown_handler( $id ) {
        $payload = array(
            'type' => 'handler_resolution',
            'id' => $id,
            'registry' => 'NormalizerRegistry',
            'spec_path' => 'docs/Canonical_Spec.md#sec-central-registries',
        );

        return new RuntimeException( json_encode( $payload ) );
    }
}
