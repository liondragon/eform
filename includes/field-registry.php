<?php
// includes/field-registry.php

/**
 * Derive the logical field key from a posted field name.
 */
function eform_field_key_from_post( string $post_key ): string {
    $key = sanitize_key( preg_replace( '/_input$/', '', $post_key ) );
    if ( 'tel' === $key ) {
        return 'phone';
    }
    return $key;
}

/**
 * Load field rules for a template from its configuration file.
 *
 * @param string $template Template slug.
 * @return array<string,array> Field rules keyed by logical field key.
 */
function eform_get_field_rules( string $template ): array {
    $config = eform_get_template_config( $template );
    $fields = [];
    foreach ( $config['fields'] ?? [] as $post_key => $field ) {
        $key           = eform_field_key_from_post( $post_key );
        $fields[ $key ] = array_merge( $field, [ 'post_key' => $post_key ] );
    }
    return $fields;
}
