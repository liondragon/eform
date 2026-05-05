<?php
/**
 * Internal form protocol names shared across PHP and forms.js.
 *
 * Spec: Template model (docs/Canonical_Spec.md#sec-template-model)
 * Spec: Assets (docs/Canonical_Spec.md#sec-assets)
 * Spec: JS-minted mode contract (docs/Canonical_Spec.md#sec-js-mint-mode)
 */

class FormProtocol {
    const FIELD_FORM_ID = 'form_id';
    const FIELD_INSTANCE_ID = 'instance_id';
    const FIELD_SUBMISSION_ID = 'submission_id';
    const FIELD_TOKEN = 'eforms_token';
    const FIELD_HONEYPOT = 'eforms_hp';
    const FIELD_MODE = 'eforms_mode';
    const FIELD_TIMESTAMP = 'timestamp';
    const FIELD_JS_OK = 'js_ok';
    const FIELD_IP = 'ip';
    const FIELD_SUBMITTED_AT = 'submitted_at';

    const MINT_FORM_PARAM = 'f';
    const MINT_RESPONSE_TOKEN = 'token';
    const MINT_RESPONSE_INSTANCE_ID = 'instance_id';
    const MINT_RESPONSE_TIMESTAMP = 'timestamp';
    const MINT_RESPONSE_EXPIRES = 'expires';

    const DATA_MODE = 'data-eforms-mode';
    const DATA_TOKEN_TTL_MAX = 'data-eforms-token-ttl-max';

    const STORAGE_TOKEN_PREFIX = 'eforms:token:';

    public static function hidden_field_names() {
        return array(
            'mode' => self::FIELD_MODE,
            'token' => self::FIELD_TOKEN,
            'instance_id' => self::FIELD_INSTANCE_ID,
            'timestamp' => self::FIELD_TIMESTAMP,
            'js_ok' => self::FIELD_JS_OK,
            'honeypot' => self::FIELD_HONEYPOT,
        );
    }

    public static function reserved_field_keys() {
        return array(
            self::FIELD_FORM_ID,
            self::FIELD_INSTANCE_ID,
            self::FIELD_SUBMISSION_ID,
            self::FIELD_TOKEN,
            self::FIELD_HONEYPOT,
            self::FIELD_MODE,
            self::FIELD_TIMESTAMP,
            self::FIELD_JS_OK,
            self::FIELD_IP,
            self::FIELD_SUBMITTED_AT,
        );
    }

    public static function reserved_field_key_map() {
        $out = array();
        foreach ( self::reserved_field_keys() as $key ) {
            $out[ $key ] = true;
        }
        return $out;
    }

    public static function post_detection_keys() {
        return array(
            self::FIELD_TOKEN,
            self::FIELD_INSTANCE_ID,
            self::FIELD_MODE,
            self::FIELD_HONEYPOT,
        );
    }

    public static function data_attributes() {
        return array(
            'mode' => self::DATA_MODE,
            'token_ttl_max' => self::DATA_TOKEN_TTL_MAX,
        );
    }

    public static function mint_response_keys() {
        return array(
            'token' => self::MINT_RESPONSE_TOKEN,
            'instance_id' => self::MINT_RESPONSE_INSTANCE_ID,
            'timestamp' => self::MINT_RESPONSE_TIMESTAMP,
            'expires' => self::MINT_RESPONSE_EXPIRES,
        );
    }

    public static function browser_settings() {
        return array(
            'hiddenFields' => self::hidden_field_names(),
            'dataAttributes' => self::data_attributes(),
            'mint' => array(
                'formParam' => self::MINT_FORM_PARAM,
                'response' => self::mint_response_keys(),
            ),
            'storageTokenPrefix' => self::STORAGE_TOKEN_PREFIX,
        );
    }
}
