<?php
/**
 * Signed bearer access for finalized managed-upload galleries.
 *
 * Contract: Managed review access
 * Contract: Signed gallery and file routes
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/UploadBatchStore.php';

class ReviewController {
    const DOMAIN = 'eforms-managed-review';
    const VERSION = '1';
    const QUERY_SUBMISSION = 'eforms_review';
    const QUERY_UPLOAD = 'eforms_review_upload';
    const QUERY_VARIANT = 'eforms_review_variant';

    public static function dispatch_current_request( $request = null, $overrides = array() ) {
        $request = is_array( $request ) ? $request : array();
        $overrides = is_array( $overrides ) ? $overrides : array();
        $method = isset( $request['method'] ) ? strtoupper( (string) $request['method'] ) : self::server_method();
        $parsed = self::parse_request( $request );
        if ( empty( $parsed['matched'] ) ) {
            return self::not_handled();
        }
        if ( $method !== 'GET' ) {
            return self::unavailable();
        }

        $query = $parsed['query'];
        $expires = self::query_expiry( $query );
        $signature = isset( $query['signature'] ) && is_string( $query['signature'] ) ? $query['signature'] : '';
        $salt = self::salt( $overrides );
        $now = isset( $overrides['now'] ) && is_numeric( $overrides['now'] ) ? (int) $overrides['now'] : time();
        $uploads_dir = self::uploads_dir( $overrides );
        if ( $expires <= $now || $salt === '' || $uploads_dir === '' ) {
            return self::unavailable();
        }

        if ( $parsed['action'] === 'gallery' ) {
            if ( ! self::verify( 'gallery', $parsed['submission_id'], '', '', $expires, $signature, $salt ) ) {
                return self::unavailable();
            }
            return self::gallery_response( $parsed['submission_id'], $expires, $uploads_dir, $now, $salt, $overrides );
        }

        if ( $parsed['action'] === 'file' ) {
            if ( ! self::verify( 'file', $parsed['submission_id'], $parsed['upload_id'], $parsed['variant'], $expires, $signature, $salt ) ) {
                return self::unavailable();
            }
            return self::file_response(
                $parsed['submission_id'],
                $parsed['upload_id'],
                $parsed['variant'],
                $expires,
                $uploads_dir,
                $now
            );
        }

        return self::unavailable();
    }

    public static function gallery_url( $submission_id, $expires, $base_url = null, $salt = null ) {
        if ( ! self::valid_id( $submission_id, FormProtocol::managed_id_pattern() ) || ! is_numeric( $expires ) || (int) $expires <= 0 ) {
            return '';
        }
        $salt = is_string( $salt ) ? $salt : self::wordpress_salt();
        if ( $salt === '' ) {
            return '';
        }
        $expires = (int) $expires;
        $signature = self::signature( 'gallery', $submission_id, '', '', $expires, $salt );
        if ( $signature === '' ) {
            return '';
        }
        return self::review_url( 'gallery', $submission_id, '', '', $expires, $signature, $base_url );
    }

    public static function email_gallery_reference( $submission_id, $expected_upload_ids, $uploads_dir, $base_url = null, $salt = null, $now = null ) {
        if ( ! self::valid_id( $submission_id, FormProtocol::managed_id_pattern() ) || ! is_array( $expected_upload_ids ) ) {
            return array( 'ok' => false );
        }
        $expected_ids = self::upload_ids( $expected_upload_ids );
        if ( empty( $expected_ids ) || count( $expected_ids ) !== count( $expected_upload_ids ) ) {
            return array( 'ok' => false );
        }

        $now = is_numeric( $now ) ? (int) $now : time();
        $loaded = UploadBatchStore::submission( $submission_id, $uploads_dir, $now );
        if ( empty( $loaded['ok'] ) || ! isset( $loaded['submission']['items'], $loaded['submission']['gallery_expires_at'] ) ) {
            return array( 'ok' => false );
        }
        $items = is_array( $loaded['submission']['items'] ) ? $loaded['submission']['items'] : array();
        $actual_ids = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['upload_id'] ) ) {
                return array( 'ok' => false );
            }
            $actual_ids[] = $item['upload_id'];
        }
        $actual_ids = self::upload_ids( $actual_ids );
        if ( $actual_ids !== $expected_ids || count( $actual_ids ) !== count( $items ) ) {
            return array( 'ok' => false );
        }

        $expires = (int) $loaded['submission']['gallery_expires_at'];
        $url = self::gallery_url( $submission_id, $expires, $base_url, $salt );
        if ( $url === '' ) {
            return array( 'ok' => false );
        }
        return array(
            'ok' => true,
            'count' => count( $items ),
            'url' => $url,
            'expires_at' => $expires,
            'expires_label' => gmdate( 'Y-m-d H:i \U\T\C', $expires ),
        );
    }

    public static function file_url( $submission_id, $upload_id, $variant, $expires, $base_url = null, $salt = null ) {
        if ( ! self::valid_id( $submission_id, FormProtocol::managed_id_pattern() )
            || ! self::valid_id( $upload_id, FormProtocol::managed_id_pattern() )
            || ! in_array( $variant, array( 'preview', 'master' ), true )
            || ! is_numeric( $expires )
            || (int) $expires <= 0
        ) {
            return '';
        }
        $salt = is_string( $salt ) ? $salt : self::wordpress_salt();
        if ( $salt === '' ) {
            return '';
        }
        $expires = (int) $expires;
        $signature = self::signature( 'file', $submission_id, $upload_id, $variant, $expires, $salt );
        if ( $signature === '' ) {
            return '';
        }
        return self::review_url( 'file', $submission_id, $upload_id, $variant, $expires, $signature, $base_url );
    }

    public static function signature( $action, $submission_id, $upload_id, $variant, $expires, $salt ) {
        if ( ! in_array( $action, array( 'gallery', 'file' ), true )
            || ! self::valid_id( $submission_id, FormProtocol::managed_id_pattern() )
            || ! is_string( $upload_id )
            || ! is_string( $variant )
            || ! is_numeric( $expires )
            || (int) $expires <= 0
            || ! is_string( $salt )
            || $salt === ''
        ) {
            return '';
        }
        if ( $action === 'gallery' && ( $upload_id !== '' || $variant !== '' ) ) {
            return '';
        }
        if ( $action === 'file' && ( ! self::valid_id( $upload_id, FormProtocol::managed_id_pattern() ) || ! in_array( $variant, array( 'preview', 'master' ), true ) ) ) {
            return '';
        }

        $message = UploadBatchStore::encode_parts(
            array(
                self::DOMAIN,
                self::VERSION,
                $action,
                $submission_id,
                $upload_id,
                $variant,
                (string) (int) $expires,
            )
        );
        return $message === '' ? '' : self::base64url( hash_hmac( 'sha256', $message, $salt, true ) );
    }

    public static function emit_headers( $response ) {
        if ( ! is_array( $response ) ) {
            return;
        }
        $status = isset( $response['status'] ) ? (int) $response['status'] : 404;
        if ( function_exists( 'status_header' ) ) {
            status_header( $status );
        } elseif ( function_exists( 'http_response_code' ) ) {
            http_response_code( $status );
        }
        $headers = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
        foreach ( $headers as $name => $value ) {
            if ( is_string( $name ) && $name !== '' && is_string( $value ) && $value !== '' && ! headers_sent() ) {
                header( $name . ': ' . $value, true );
            }
        }
    }

    private static function gallery_response( $submission_id, $expires, $uploads_dir, $now, $salt, $overrides ) {
        $loaded = UploadBatchStore::submission( $submission_id, $uploads_dir, $now );
        if ( empty( $loaded['ok'] ) || ! isset( $loaded['submission'] ) || ! is_array( $loaded['submission'] ) ) {
            return self::unavailable();
        }
        $submission = $loaded['submission'];
        $manifest_expiry = isset( $submission['gallery_expires_at'] ) ? (int) $submission['gallery_expires_at'] : 0;
        if ( $manifest_expiry <= $now || $expires > $manifest_expiry ) {
            return self::unavailable();
        }

        $base_url = isset( $overrides['base_url'] ) && is_string( $overrides['base_url'] ) ? $overrides['base_url'] : null;
        $items = array();
        foreach ( isset( $submission['items'] ) && is_array( $submission['items'] ) ? $submission['items'] : array() as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['upload_id'] ) || ! is_string( $item['upload_id'] ) ) {
                return self::unavailable();
            }
            $preview_url = self::file_url( $submission_id, $item['upload_id'], 'preview', $expires, $base_url, $salt );
            $master_url = self::file_url( $submission_id, $item['upload_id'], 'master', $expires, $base_url, $salt );
            if ( $preview_url === '' || $master_url === '' ) {
                return self::unavailable();
            }
            $items[] = array(
                'upload_id' => $item['upload_id'],
                'display_name' => isset( $item['display_name'] ) ? (string) $item['display_name'] : 'Photo',
                'width' => isset( $item['width'] ) ? (int) $item['width'] : 0,
                'height' => isset( $item['height'] ) ? (int) $item['height'] : 0,
                'preview_url' => $preview_url,
                'master_url' => $master_url,
            );
        }

        self::enqueue_styles();

        return array(
            'handled' => true,
            'render' => 'review_gallery',
            'status' => 200,
            'location' => '',
            'body' => '',
            'headers' => self::private_headers( 'text/html; charset=UTF-8' ),
            'review_page' => array(
                'title' => 'Submitted Photos',
                'submission_id' => $submission_id,
                'count' => count( $items ),
                'items' => $items,
                'template' => dirname( __DIR__, 2 ) . '/templates/pages/review-gallery.php',
            ),
            'result' => array( 'ok' => true ),
        );
    }

    private static function file_response( $submission_id, $upload_id, $variant, $expires, $uploads_dir, $now ) {
        $file = UploadBatchStore::submission_file( $submission_id, $upload_id, $variant, $uploads_dir, $now );
        if ( empty( $file['ok'] )
            || ! isset( $file['stream'], $file['mime'], $file['bytes'], $file['gallery_expires_at'] )
            || ! is_resource( $file['stream'] )
            || $expires > (int) $file['gallery_expires_at']
        ) {
            if ( isset( $file['stream'] ) && is_resource( $file['stream'] ) ) {
                fclose( $file['stream'] );
            }
            return self::unavailable();
        }
        $stream = $file['stream'];
        $actual_bytes = (int) $file['bytes'];
        $headers = self::private_headers( (string) $file['mime'] );
        $headers['Content-Length'] = (string) $actual_bytes;
        $headers['Content-Disposition'] = self::content_disposition( $variant, isset( $file['display_name'] ) ? $file['display_name'] : '' );
        return array(
            'handled' => true,
            'render' => 'review_file',
            'status' => 200,
            'location' => '',
            'body' => '',
            'stream' => $stream,
            'headers' => $headers,
            'result' => array( 'ok' => true ),
        );
    }

    private static function parse_request( $request ) {
        $uri = isset( $request['uri'] ) && is_string( $request['uri'] )
            ? $request['uri']
            : ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
        $path = parse_url( $uri, PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';
        $home_path = function_exists( 'home_url' ) ? parse_url( (string) home_url(), PHP_URL_PATH ) : '';
        $home_path = is_string( $home_path ) ? rtrim( $home_path, '/' ) : '';
        $query = isset( $request['query'] ) && is_array( $request['query'] ) ? $request['query'] : array();
        if ( empty( $query ) ) {
            $query_string = parse_url( $uri, PHP_URL_QUERY );
            if ( is_string( $query_string ) ) {
                parse_str( $query_string, $query );
            } elseif ( isset( $_GET ) && is_array( $_GET ) ) {
                $query = $_GET;
            }
        }
        if ( array_key_exists( self::QUERY_SUBMISSION, $query ) ) {
            if ( rtrim( $path, '/' ) !== $home_path ) {
                return array( 'matched' => true, 'action' => 'invalid', 'query' => $query );
            }
            $submission_id = $query[ self::QUERY_SUBMISSION ];
            $has_upload = array_key_exists( self::QUERY_UPLOAD, $query );
            $has_variant = array_key_exists( self::QUERY_VARIANT, $query );
            if ( ! $has_upload && ! $has_variant && self::valid_id( $submission_id, FormProtocol::managed_id_pattern() ) ) {
                return array( 'matched' => true, 'action' => 'gallery', 'submission_id' => $submission_id, 'query' => $query );
            }
            if ( $has_upload
                && $has_variant
                && self::valid_id( $submission_id, FormProtocol::managed_id_pattern() )
                && self::valid_id( $query[ self::QUERY_UPLOAD ], FormProtocol::managed_id_pattern() )
                && in_array( $query[ self::QUERY_VARIANT ], array( 'preview', 'master' ), true )
            ) {
                return array(
                    'matched' => true,
                    'action' => 'file',
                    'submission_id' => $submission_id,
                    'upload_id' => $query[ self::QUERY_UPLOAD ],
                    'variant' => $query[ self::QUERY_VARIANT ],
                    'query' => $query,
                );
            }
            return array( 'matched' => true, 'action' => 'invalid', 'query' => $query );
        }

        return array( 'matched' => false );
    }

    private static function verify( $action, $submission_id, $upload_id, $variant, $expires, $provided, $salt ) {
        if ( ! is_string( $provided ) ) {
            return false;
        }
        $expected = self::signature( $action, $submission_id, $upload_id, $variant, $expires, $salt );
        return $expected !== '' && hash_equals( $expected, $provided );
    }

    private static function private_headers( $content_type ) {
        return array(
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Content-Type' => $content_type,
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        );
    }

    private static function unavailable() {
        return array(
            'handled' => true,
            'render' => 'review_file',
            'status' => 404,
            'location' => '',
            'body' => '<div class="eforms-error">Review unavailable.</div>',
            'headers' => self::private_headers( 'text/html; charset=UTF-8' ),
            'result' => array( 'ok' => false ),
        );
    }

    private static function not_handled() {
        return array( 'handled' => false, 'status' => 0, 'location' => '', 'body' => '', 'result' => null );
    }

    private static function query_expiry( $query ) {
        if ( ! is_array( $query ) || ! isset( $query['expires'] ) || ! is_string( $query['expires'] ) || preg_match( '/^[1-9][0-9]{0,10}$/', $query['expires'] ) !== 1 ) {
            return 0;
        }
        return (int) $query['expires'];
    }

    private static function uploads_dir( $overrides ) {
        if ( isset( $overrides['uploads_dir'] ) && is_string( $overrides['uploads_dir'] ) && $overrides['uploads_dir'] !== '' ) {
            return rtrim( $overrides['uploads_dir'], '/\\' );
        }
        $value = Config::value( Config::get(), array( 'uploads', 'dir' ), '' );
        return is_string( $value ) && $value !== '' ? rtrim( $value, '/\\' ) : '';
    }

    private static function salt( $overrides ) {
        if ( isset( $overrides['salt'] ) && is_string( $overrides['salt'] ) ) {
            return $overrides['salt'];
        }
        return self::wordpress_salt();
    }

    private static function wordpress_salt() {
        return function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
    }

    private static function base_url( $base_url ) {
        if ( is_string( $base_url ) && $base_url !== '' ) {
            return rtrim( $base_url, '/' );
        }
        return function_exists( 'home_url' ) ? rtrim( (string) home_url(), '/' ) : '';
    }

    private static function review_url( $action, $submission_id, $upload_id, $variant, $expires, $signature, $base_url ) {
        $base = self::base_url( $base_url );
        if ( $base === '' ) {
            return '';
        }
        $query = array_merge(
            array( self::QUERY_SUBMISSION => $submission_id ),
            $action === 'file'
                ? array( self::QUERY_UPLOAD => $upload_id, self::QUERY_VARIANT => $variant )
                : array(),
            array(
            'expires' => $expires,
            'signature' => $signature,
            )
        );
        return $base . '/?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
    }

    private static function server_method() {
        return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( $_SERVER['REQUEST_METHOD'] )
            : 'GET';
    }

    private static function valid_id( $value, $pattern ) {
        return is_string( $value ) && preg_match( $pattern, $value ) === 1;
    }

    private static function upload_ids( $values ) {
        $ids = array();
        foreach ( $values as $value ) {
            if ( ! self::valid_id( $value, FormProtocol::managed_id_pattern() ) ) {
                return array();
            }
            $ids[] = $value;
        }
        sort( $ids, SORT_STRING );
        return $ids;
    }

    private static function content_disposition( $variant, $display_name ) {
        $name = is_string( $display_name ) && $display_name !== '' ? $display_name : 'photo';
        $stem = pathinfo( $name, PATHINFO_FILENAME );
        $stem = is_string( $stem ) && $stem !== '' ? $stem : 'photo';
        $suffix = $variant === 'preview' ? '-preview.jpg' : '-high-resolution.jpg';
        $fallback = $variant === 'preview' ? 'preview.jpg' : 'high-resolution.jpg';
        return 'inline; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $stem . $suffix );
    }

    private static function enqueue_styles() {
        $config = Config::get();
        if ( Config::bool( $config, array( 'assets', 'css_disable' ), false ) ) {
            return;
        }
        $path = dirname( __DIR__, 2 ) . '/assets/forms.css';
        if ( function_exists( 'wp_enqueue_style' ) && function_exists( 'plugins_url' ) && is_file( $path ) ) {
            wp_enqueue_style( 'eforms', plugins_url( 'assets/forms.css', dirname( __DIR__, 2 ) . '/eforms.php' ), array(), filemtime( $path ) );
        }
    }

    private static function base64url( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }
}
