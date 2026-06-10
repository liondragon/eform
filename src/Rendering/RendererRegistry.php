<?php
/**
 * Renderer registry (internal only).
 *
 * Educational note: this registry maps handler IDs to callables and throws a
 * deterministic error payload when an unknown ID is requested.
 *
 * Spec: Central registries (docs/Canonical_Spec.md#sec-central-registries)
 */

require_once __DIR__ . '/FieldRenderers/TextLike.php';
require_once __DIR__ . '/FieldRenderers/Textarea.php';
require_once __DIR__ . '/FieldRenderers/Choice.php';
require_once __DIR__ . '/FieldRenderers/Upload.php';

class RendererRegistry {
    const HANDLERS = array(
        'text' => array( 'FieldRenderers_TextLike', 'render' ),
        'zip' => array( 'FieldRenderers_TextLike', 'render' ),
        'zip_us' => array( 'FieldRenderers_TextLike', 'render' ),
        'number' => array( 'FieldRenderers_TextLike', 'render' ),
        'range' => array( 'FieldRenderers_TextLike', 'render' ),
        'date' => array( 'FieldRenderers_TextLike', 'render' ),
        'file' => array( 'FieldRenderers_Upload', 'render' ),
        'files' => array( 'FieldRenderers_Upload', 'render' ),
        'textarea' => array( 'FieldRenderers_Textarea', 'render' ),
        'choice' => array( 'FieldRenderers_Choice', 'render' ),
    );

    /**
     * Resolve a renderer handler by id.
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

    private static function unknown_handler( $id ) {
        $payload = array(
            'type' => 'handler_resolution',
            'id' => $id,
            'registry' => 'RendererRegistry',
            'spec_path' => 'docs/Canonical_Spec.md#sec-central-registries',
        );

        return new RuntimeException( json_encode( $payload ) );
    }
}
