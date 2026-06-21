<?php
/**
 * WordPress option owner for sparse admin configuration overrides.
 *
 * Contract: Configuration
 */

require_once __DIR__ . '/../Config.php';

class AdminSettingsStore {
    const OPTION_NAME = 'eforms_admin_config';

    public static function read_overrides_result() {
        if ( ! function_exists( 'get_option' ) ) {
            return array( 'ok' => true, 'overrides' => array(), 'errors' => array() );
        }

        $raw = get_option( self::OPTION_NAME, array() );
        if ( $raw === false || $raw === null || $raw === '' ) {
            $raw = array();
        }

        if ( ! is_array( $raw ) ) {
            return array(
                'ok' => false,
                'overrides' => array(),
                'errors' => array( array( 'path' => '_root', 'reason' => 'type' ) ),
            );
        }

        return Config::validate_admin_overrides( $raw );
    }

    public static function read_overrides() {
        $result = self::read_overrides_result();
        if ( ! is_array( $result ) || empty( $result['ok'] ) || ! isset( $result['overrides'] ) || ! is_array( $result['overrides'] ) ) {
            return array();
        }

        return $result['overrides'];
    }

    public static function replace_overrides( $overrides ) {
        $result = Config::validate_admin_overrides( $overrides );
        if ( empty( $result['ok'] ) ) {
            return $result;
        }

        $clean = isset( $result['overrides'] ) && is_array( $result['overrides'] ) ? $result['overrides'] : array();
        if ( empty( $clean ) ) {
            self::delete_all();
            return array( 'ok' => true, 'overrides' => array(), 'errors' => array() );
        }

        if ( function_exists( 'add_option' ) ) {
            $added = add_option( self::OPTION_NAME, $clean, '', 'no' );
            if ( $added ) {
                return array( 'ok' => true, 'overrides' => $clean, 'errors' => array() );
            }
        }

        if ( function_exists( 'update_option' ) ) {
            update_option( self::OPTION_NAME, $clean, false );
            return array( 'ok' => true, 'overrides' => $clean, 'errors' => array() );
        }

        return array(
            'ok' => false,
            'overrides' => array(),
            'errors' => array( array( 'path' => '_root', 'reason' => 'option_api_missing' ) ),
        );
    }

    public static function delete_all() {
        if ( function_exists( 'delete_option' ) ) {
            delete_option( self::OPTION_NAME );
        }
    }
}
