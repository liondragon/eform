<?php
/**
 * Canonical registration and enqueueing for plugin-owned browser assets.
 */

class EformsAssets {
    const CORE_STYLE = 'eforms';
    const UPLOAD_STYLE = 'eforms-upload';
    const REVIEW_STYLE = 'eforms-review-gallery';
    const FORMS_SCRIPT = 'eforms';
    const REVIEW_SCRIPT = 'eforms-review-gallery';
    const ADMIN_SETTINGS_STYLE = 'eforms-admin-settings';
    const ADMIN_SETTINGS_SCRIPT = 'eforms-admin-settings';

    public static function enqueue_form( $config, $with_upload = false ) {
        if ( self::css_enabled( $config ) ) {
            self::enqueue_style( self::CORE_STYLE, 'assets/forms.css' );
            if ( $with_upload ) {
                self::enqueue_style( self::UPLOAD_STYLE, 'assets/upload.css', array( self::CORE_STYLE ) );
            }
        }

        self::enqueue_script( self::FORMS_SCRIPT, 'assets/forms.js' );
    }

    public static function enqueue_review( $config ) {
        if ( self::css_enabled( $config ) ) {
            self::enqueue_style( self::CORE_STYLE, 'assets/forms.css' );
            self::enqueue_style( self::REVIEW_STYLE, 'assets/review-gallery.css', array( self::CORE_STYLE ) );
        }

        self::enqueue_script( self::REVIEW_SCRIPT, 'assets/review-gallery.js' );
    }

    public static function enqueue_admin_settings() {
        self::enqueue_style( self::ADMIN_SETTINGS_STYLE, 'assets/admin-settings.css' );
        self::enqueue_script( self::ADMIN_SETTINGS_SCRIPT, 'assets/admin-settings.js' );
    }

    public static function same_origin_versioned_url( $relative ) {
        $path = self::path( $relative );
        if ( ! is_file( $path ) ) {
            return '';
        }

        $url_path = parse_url( self::url( $relative ), PHP_URL_PATH );
        if ( ! is_string( $url_path ) || $url_path === '' ) {
            return '';
        }

        return '/' . ltrim( $url_path, '/' ) . '?ver=' . rawurlencode( (string) filemtime( $path ) );
    }

    private static function css_enabled( $config ) {
        return ! class_exists( 'Config' ) || ! Config::bool( $config, array( 'assets', 'css_disable' ), false );
    }

    private static function enqueue_style( $handle, $relative, $dependencies = array() ) {
        $path = self::path( $relative );
        if ( ! function_exists( 'wp_enqueue_style' ) || ! is_file( $path ) ) {
            return;
        }

        wp_enqueue_style( $handle, self::url( $relative ), $dependencies, filemtime( $path ) );
    }

    private static function enqueue_script( $handle, $relative ) {
        $path = self::path( $relative );
        if ( ! function_exists( 'wp_enqueue_script' ) || ! is_file( $path ) ) {
            return;
        }

        wp_enqueue_script( $handle, self::url( $relative ), array(), filemtime( $path ), true );
    }

    private static function path( $relative ) {
        return dirname( __DIR__ ) . '/' . ltrim( $relative, '/' );
    }

    private static function url( $relative ) {
        if ( function_exists( 'plugins_url' ) ) {
            return plugins_url( ltrim( $relative, '/' ), dirname( __DIR__ ) . '/eforms.php' );
        }

        return ltrim( $relative, '/' );
    }
}
