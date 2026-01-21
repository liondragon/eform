<?php
/**
 * Per-request template context with resolved descriptors.
 *
 * Educational note: This class resolves descriptors once per request so the
 * renderer and validator can reuse the same descriptor objects.
 *
 * Spec: Template model (docs/Canonical_Spec.md#sec-template-model)
 * Spec: Template context (docs/Canonical_Spec.md#sec-template-context)
 * Spec: Request lifecycle GET (docs/Canonical_Spec.md#sec-request-lifecycle-get)
 */

require_once __DIR__ . '/../Errors.php';
require_once __DIR__ . '/../Validation/TemplateValidator.php';
require_once __DIR__ . '/../Validation/FieldTypeRegistry.php';
require_once __DIR__ . '/../Validation/ValidatorRegistry.php';
require_once __DIR__ . '/../Validation/NormalizerRegistry.php';
require_once __DIR__ . '/../Rendering/RendererRegistry.php';

class TemplateContext {
    private static $cache = array();

    /**
     * Build a TemplateContext from a decoded template array.
     *
     * @param array $template Template data.
     * @param string|null $version_override Optional normalized version.
     * @return array { ok, context, errors }
     */
    public static function build( $template, $version_override = null ) {
        $errors = new Errors();

        $schema_errors = TemplateValidator::validate_template_envelope( $template );
        if ( $schema_errors->any() ) {
            return self::result( false, null, $schema_errors );
        }

        if ( ! is_array( $template ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
            return self::result( false, null, $errors );
        }

        $form_id = isset( $template['id'] ) && is_string( $template['id'] ) ? $template['id'] : '';
        $version = self::normalize_version( $template, $version_override );
        $cache_key = $form_id . '::' . $version;

        if ( isset( self::$cache[ $cache_key ] ) ) {
            return self::result( true, self::$cache[ $cache_key ], $errors );
        }

        $fields = isset( $template['fields'] ) && is_array( $template['fields'] ) ? $template['fields'] : array();
        $fields = TemplateValidator::sanitize_fields( $fields );

        $descriptors = array();
        $has_uploads = false;

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            if ( isset( $field['type'] ) && $field['type'] === 'row_group' ) {
                continue;
            }

            $type = isset( $field['type'] ) ? $field['type'] : '';
            if ( $type === 'file' || $type === 'files' ) {
                $has_uploads = true;
            }

            try {
                $descriptor = FieldTypeRegistry::resolve( $type );
            } catch ( RuntimeException $exception ) {
                $errors->add_global( 'EFORMS_ERR_TYPE' );
                continue;
            }

            if ( isset( $descriptor['alias_of'] ) && $descriptor['alias_of'] ) {
                try {
                    $alias = FieldTypeRegistry::resolve( $descriptor['alias_of'] );
                } catch ( RuntimeException $exception ) {
                    $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
                    continue;
                }

                if ( isset( $descriptor['handlers'], $alias['handlers'] ) && $descriptor['handlers'] !== $alias['handlers'] ) {
                    $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
                }
            }

            $handlers = self::resolve_handlers( $descriptor, $errors );
            if ( $handlers === null ) {
                return self::result( false, null, $errors );
            }

            $descriptors[] = self::resolve_descriptor( $descriptor, $field, $form_id, $handlers );
        }

        if ( $errors->any() ) {
            return self::result( false, null, $errors );
        }

        $context = array(
            'id' => $form_id,
            'version' => $version,
            'title' => isset( $template['title'] ) ? $template['title'] : '',
            'email' => isset( $template['email'] ) ? $template['email'] : array(),
            'success' => isset( $template['success'] ) ? $template['success'] : array(),
            'rules' => isset( $template['rules'] ) && is_array( $template['rules'] ) ? $template['rules'] : array(),
            'fields' => $fields,
            'descriptors' => $descriptors,
            'has_uploads' => $has_uploads,
            'max_input_vars_estimate' => self::estimate_input_vars( $fields ),
        );

        self::$cache[ $cache_key ] = $context;

        return self::result( true, $context, $errors );
    }

    private static function normalize_version( $template, $version_override ) {
        if ( is_string( $version_override ) && $version_override !== '' ) {
            return $version_override;
        }

        if ( is_array( $template ) && isset( $template['version'] ) && is_string( $template['version'] ) ) {
            return $template['version'];
        }

        return '0';
    }

    private static function resolve_descriptor( $descriptor, $field, $form_id, $handlers ) {
        $html = isset( $descriptor['html'] ) && is_array( $descriptor['html'] ) ? $descriptor['html'] : array();
        $attr_mirror = isset( $html['attrs_mirror'] ) && is_array( $html['attrs_mirror'] ) ? $html['attrs_mirror'] : array();

        return array(
            'key' => isset( $field['key'] ) ? $field['key'] : '',
            'type' => isset( $descriptor['type'] ) ? $descriptor['type'] : '',
            'is_multivalue' => isset( $descriptor['is_multivalue'] ) ? $descriptor['is_multivalue'] : false,
            'name_tpl' => $form_id . '[{key}]',
            'id_prefix' => $form_id,
            'html' => $html,
            'validate' => isset( $descriptor['validate'] ) ? $descriptor['validate'] : array(),
            'constants' => isset( $descriptor['constants'] ) ? $descriptor['constants'] : array(),
            'attr_mirror' => $attr_mirror,
            'handlers' => $handlers,
        );
    }

    /**
     * Resolve handler IDs to callables for Validator/Normalizer/Renderer.
     */
    private static function resolve_handlers( $descriptor, $errors ) {
        if ( ! is_array( $descriptor ) || ! isset( $descriptor['handlers'] ) || ! is_array( $descriptor['handlers'] ) ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
            return null;
        }

        $handlers = $descriptor['handlers'];
        $required = array( 'validator_id', 'normalizer_id', 'renderer_id' );

        foreach ( $required as $key ) {
            if ( ! isset( $handlers[ $key ] ) || ! is_string( $handlers[ $key ] ) ) {
                $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
                return null;
            }
        }

        try {
            return array(
                'v' => ValidatorRegistry::resolve( $handlers['validator_id'] ),
                'n' => NormalizerRegistry::resolve( $handlers['normalizer_id'] ),
                'r' => RendererRegistry::resolve( $handlers['renderer_id'] ),
            );
        } catch ( RuntimeException $exception ) {
            $errors->add_global( 'EFORMS_ERR_SCHEMA_OBJECT' );
            return null;
        }
    }

    private static function estimate_input_vars( $fields ) {
        $count = 0;

        if ( ! is_array( $fields ) ) {
            return $count;
        }

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            if ( isset( $field['type'] ) && $field['type'] === 'row_group' ) {
                continue;
            }

            $count += 1;
        }

        return $count;
    }

    private static function result( $ok, $context, $errors ) {
        return array(
            'ok' => (bool) $ok,
            'context' => $context,
            'errors' => $errors,
        );
    }
}
