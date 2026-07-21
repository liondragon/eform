<?php
/**
 * HTTP adapter for managed staged upload batches.
 *
 * Contract: Managed Upload API
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../Rendering/TemplateContext.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Security/OriginPolicy.php';
require_once __DIR__ . '/../Security/PostSize.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Submission/Ledger.php';
require_once __DIR__ . '/UploadBatchStore.php';

class UploadBatchEndpoint {
    public static function create( $request ) {
        $method = self::method( $request );
        if ( $method !== 'POST' ) {
            return self::method_failure( 'POST' );
        }
        $gate = self::origin_and_throttle( $request, true );
        if ( $gate !== null ) {
            return $gate;
        }
        if ( ! self::content_type_is( $request, 'application/x-www-form-urlencoded' ) ) {
            return self::error( 400, 'EFORMS_ERR_TYPE' );
        }

        $form_id = self::body_param( $request, FormProtocol::FIELD_FORM_ID );
        $instance_id = self::body_param( $request, FormProtocol::FIELD_INSTANCE_ID );
        $token = self::body_param( $request, FormProtocol::FIELD_TOKEN );
        $field_key = self::body_param( $request, FormProtocol::UPLOAD_FIELD_PARAM );
        $secret = self::header( $request, FormProtocol::HEADER_BATCH_SECRET );
        $field = self::staged_field( $form_id, $field_key );
        if ( $field === null ) {
            return self::error( 400, 'EFORMS_ERR_TOKEN' );
        }

        $uploads_dir = self::uploads_dir();
        $validated = Security::validate_managed_token( $token, $instance_id, $form_id, $uploads_dir );
        if ( empty( $validated['ok'] ) ) {
            return self::store_error( $validated );
        }

        $guarded = Ledger::run_if_unused(
            $form_id,
            $token,
            $uploads_dir,
            function () use ( $token, $form_id, $instance_id, $field_key, $validated, $secret, $field, $uploads_dir ) {
                return UploadBatchStore::create_batch(
                    array(
                        'raw_token' => $token,
                        'form_id' => $form_id,
                        'instance_id' => $instance_id,
                        'field_key' => $field_key,
                        'accept_until' => $validated['expires'],
                    ),
                    $secret,
                    $field,
                    $uploads_dir
                );
            },
            $request
        );
        if ( empty( $guarded['ok'] ) ) {
            return ! empty( $guarded['duplicate'] )
                ? self::error( 410, 'EFORMS_ERR_TOKEN' )
                : self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }
        $created = $guarded['result'];
        if ( empty( $created['ok'] ) ) {
            return self::store_error( $created );
        }
        return self::json( 200, self::batch_response( $created['batch'] ) );
    }

    public static function status( $request ) {
        if ( self::method( $request ) !== 'GET' ) {
            return self::method_failure( 'GET' );
        }
        $gate = self::origin_and_throttle( $request, false, true );
        if ( $gate !== null ) {
            return $gate;
        }
        $result = UploadBatchStore::status(
            self::route_param( $request, FormProtocol::UPLOAD_BATCH_PARAM ),
            self::header( $request, FormProtocol::HEADER_BATCH_SECRET ),
            self::uploads_dir()
        );
        return empty( $result['ok'] ) ? self::store_error( $result ) : self::json( 200, self::batch_response( $result['batch'] ) );
    }

    public static function upload( $request ) {
        if ( self::method( $request ) !== 'POST' ) {
            return self::method_failure( 'POST' );
        }
        $gate = self::origin_and_throttle( $request, true );
        if ( $gate !== null ) {
            return $gate;
        }
        if ( ! self::content_type_is( $request, 'multipart/form-data' ) ) {
            return self::error( 400, 'EFORMS_ERR_TYPE' );
        }
        if ( self::request_exceeds_limit( $request ) ) {
            return self::error( 413, 'EFORMS_ERR_UPLOAD_TYPE' );
        }

        $batch_id = self::route_param( $request, FormProtocol::UPLOAD_BATCH_PARAM );
        $upload_id = self::route_param( $request, FormProtocol::UPLOAD_ITEM_PARAM );
        $secret = self::header( $request, FormProtocol::HEADER_BATCH_SECRET );
        $item = self::file_item( $request, FormProtocol::UPLOAD_FILE_PARAM );
        $ordinal = self::numeric_body_param( $request, FormProtocol::UPLOAD_ORDINAL_PARAM );
        if ( $item === null || $ordinal === null ) {
            return self::error( 400, 'EFORMS_ERR_UPLOAD_TYPE' );
        }

        $result = UploadBatchStore::put_item(
            $batch_id,
            $secret,
            $upload_id,
            $ordinal,
            $item,
            self::uploads_dir()
        );
        return empty( $result['ok'] ) ? self::store_error( $result ) : self::json( 200, self::item_response( $result['item'] ) );
    }

    public static function delete( $request ) {
        if ( self::method( $request ) !== 'DELETE' ) {
            return self::method_failure( 'DELETE' );
        }
        $gate = self::origin_and_throttle( $request, false );
        if ( $gate !== null ) {
            return $gate;
        }
        $upload_id = self::route_param( $request, FormProtocol::UPLOAD_ITEM_PARAM );
        $result = UploadBatchStore::delete_item(
            self::route_param( $request, FormProtocol::UPLOAD_BATCH_PARAM ),
            self::header( $request, FormProtocol::HEADER_BATCH_SECRET ),
            $upload_id,
            self::uploads_dir()
        );
        return empty( $result['ok'] )
            ? self::store_error( $result )
            : self::json( 200, array( FormProtocol::UPLOAD_RESPONSE_DELETED => true, FormProtocol::UPLOAD_RESPONSE_UPLOAD_ID => $upload_id ) );
    }

    public static function preview( $request ) {
        if ( self::method( $request ) !== 'GET' ) {
            return self::method_failure( 'GET' );
        }
        $gate = self::origin_and_throttle( $request, false, true );
        if ( $gate !== null ) {
            return $gate;
        }
        $result = UploadBatchStore::preview_bytes(
            self::route_param( $request, FormProtocol::UPLOAD_BATCH_PARAM ),
            self::header( $request, FormProtocol::HEADER_BATCH_SECRET ),
            self::route_param( $request, FormProtocol::UPLOAD_ITEM_PARAM ),
            self::uploads_dir()
        );
        if ( empty( $result['ok'] ) ) {
            return self::store_error( $result );
        }
        return self::result(
            200,
            array(
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Type' => 'image/jpeg',
                'Content-Length' => (string) $result['bytes'],
                'Content-Disposition' => 'inline; filename="preview.jpg"',
                'X-Content-Type-Options' => 'nosniff',
            ),
            $result['body']
        );
    }

    private static function origin_and_throttle( $request, $throttle, $allow_missing_origin = false ) {
        $config = Config::get();
        $origin = OriginPolicy::evaluate( $request, $config );
        $origin_state = is_array( $origin ) && isset( $origin['state'] ) ? $origin['state'] : 'unknown';
        if ( $origin_state !== 'same' && ( ! $allow_missing_origin || $origin_state !== 'missing' ) ) {
            return self::error( 403, 'EFORMS_ERR_ORIGIN_FORBIDDEN' );
        }
        if ( ! $throttle ) {
            return null;
        }
        if ( ! Config::bool( $config, array( 'uploads', 'enable' ), false ) ) {
            return self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }

        $result = Security::enforce_throttle( $request, self::uploads_dir(), $config );
        if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
            if ( is_array( $result ) && isset( $result['code'] ) && $result['code'] === 'throttled' ) {
                $retry_after = isset( $result['retry_after'] ) && is_numeric( $result['retry_after'] ) ? max( 1, (int) $result['retry_after'] ) : 1;
                return self::result(
                    429,
                    array(
                        'Cache-Control' => 'no-store, max-age=0',
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Retry-After' => (string) $retry_after,
                    ),
                    array( 'error' => 'EFORMS_ERR_THROTTLED' )
                );
            }
            return self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }
        if ( isset( $result['code'] ) && $result['code'] === 'disabled' ) {
            return self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }
        return null;
    }

    private static function staged_field( $form_id, $field_key ) {
        $loaded = TemplateLoader::load( $form_id );
        if ( ! is_array( $loaded ) || empty( $loaded['ok'] ) ) {
            return null;
        }
        $context = TemplateContext::build( $loaded['template'], $loaded['version'] );
        if ( ! is_array( $context ) || empty( $context['ok'] ) || ! isset( $context['context'] ) || ! is_array( $context['context'] ) ) {
            return null;
        }
        $field = isset( $context['context']['staged_field'] ) && is_array( $context['context']['staged_field'] )
            ? $context['context']['staged_field']
            : null;
        return is_array( $field ) && isset( $field['key'] ) && $field['key'] === $field_key ? $field : null;
    }

    private static function file_item( $request, $name ) {
        if ( is_object( $request ) && method_exists( $request, 'get_file_params' ) ) {
            $files = $request->get_file_params();
        } elseif ( is_array( $request ) && isset( $request['files'] ) && is_array( $request['files'] ) ) {
            $files = $request['files'];
        } else {
            $files = $_FILES;
        }
        return UploadValue::file_item_from_payload( $files, $name );
    }

    private static function batch_response( $batch ) {
        $batch = is_array( $batch ) ? $batch : array();
        $items = array();
        foreach ( isset( $batch['items'] ) && is_array( $batch['items'] ) ? $batch['items'] : array() as $item ) {
            $items[] = self::item_response( $item );
        }
        $limits = isset( $batch['limits'] ) && is_array( $batch['limits'] ) ? $batch['limits'] : array();
        return array(
            FormProtocol::UPLOAD_RESPONSE_BATCH_ID => isset( $batch['batch_id'] ) ? $batch['batch_id'] : '',
            FormProtocol::UPLOAD_RESPONSE_STATE => isset( $batch['state'] ) ? $batch['state'] : '',
            FormProtocol::UPLOAD_RESPONSE_ACCEPT_UNTIL => isset( $batch['accept_until'] ) ? (int) $batch['accept_until'] : 0,
            FormProtocol::UPLOAD_RESPONSE_DELETE_AFTER => isset( $batch['delete_after'] ) ? (int) $batch['delete_after'] : 0,
            FormProtocol::UPLOAD_RESPONSE_ITEMS => $items,
            FormProtocol::UPLOAD_RESPONSE_LIMITS => array(
                FormProtocol::UPLOAD_RESPONSE_MAX_FILE_BYTES => isset( $limits['max_file_bytes'] ) ? (int) $limits['max_file_bytes'] : 0,
                FormProtocol::UPLOAD_RESPONSE_MAX_FILES => isset( $limits['max_files'] ) ? (int) $limits['max_files'] : 0,
                FormProtocol::UPLOAD_RESPONSE_MAX_TOTAL_BYTES => isset( $limits['max_total_bytes'] ) ? (int) $limits['max_total_bytes'] : 0,
            ),
        );
    }

    private static function item_response( $item ) {
        $item = is_array( $item ) ? $item : array();
        return array(
            FormProtocol::UPLOAD_RESPONSE_UPLOAD_ID => isset( $item['upload_id'] ) ? $item['upload_id'] : '',
            FormProtocol::UPLOAD_RESPONSE_ORDINAL => isset( $item['ordinal'] ) ? (int) $item['ordinal'] : 0,
            FormProtocol::UPLOAD_RESPONSE_DISPLAY_NAME => isset( $item['display_name'] ) ? $item['display_name'] : '',
            FormProtocol::UPLOAD_RESPONSE_BYTES => isset( $item['bytes'] ) ? (int) $item['bytes'] : 0,
            FormProtocol::UPLOAD_RESPONSE_MIME => isset( $item['mime'] ) ? $item['mime'] : '',
            FormProtocol::UPLOAD_RESPONSE_WIDTH => isset( $item['width'] ) ? (int) $item['width'] : 0,
            FormProtocol::UPLOAD_RESPONSE_HEIGHT => isset( $item['height'] ) ? (int) $item['height'] : 0,
        );
    }

    private static function store_error( $result ) {
        $code = is_array( $result ) && isset( $result['code'] ) ? $result['code'] : 'EFORMS_ERR_STORAGE_UNAVAILABLE';
        $reason = is_array( $result ) && isset( $result['reason'] ) ? $result['reason'] : '';
        if ( is_array( $result ) && ! empty( $result['gone'] ) ) {
            return self::error( 410, 'EFORMS_ERR_TOKEN' );
        }
        if ( $code === 'EFORMS_ERR_TOKEN' && $reason === 'token_expired' ) {
            return self::error( 410, 'EFORMS_ERR_TOKEN' );
        }
        if ( $code === 'EFORMS_ERR_STORAGE_UNAVAILABLE' || $code === 'EFORMS_FINFO_UNAVAILABLE' ) {
            return self::error( 503, $code === 'EFORMS_FINFO_UNAVAILABLE' ? 'EFORMS_ERR_STORAGE_UNAVAILABLE' : $code );
        }
        if ( $code === 'EFORMS_ERR_UPLOAD_TYPE' ) {
            $status = in_array( $reason, array( 'request_size_exceeded', 'max_file_bytes_exceeded', 'max_files_exceeded', 'max_total_bytes_exceeded' ), true ) ? 413 : 400;
            return self::error( $status, $code );
        }
        return self::error( 409, 'EFORMS_ERR_TOKEN' );
    }

    private static function uploads_dir() {
        return Config::value( Config::get(), array( 'uploads', 'dir' ), '' );
    }

    private static function method( $request ) {
        if ( is_object( $request ) && method_exists( $request, 'get_method' ) ) {
            return strtoupper( (string) $request->get_method() );
        }
        if ( is_array( $request ) && isset( $request['method'] ) ) {
            return strtoupper( (string) $request['method'] );
        }
        return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
    }

    private static function header( $request, $name ) {
        if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
            return trim( (string) $request->get_header( $name ) );
        }
        if ( is_array( $request ) && isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
            foreach ( $request['headers'] as $key => $value ) {
                if ( is_string( $key ) && strcasecmp( $key, $name ) === 0 && is_string( $value ) ) {
                    return trim( $value );
                }
            }
            return '';
        }
        $server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        return isset( $_SERVER[ $server_key ] ) && is_string( $_SERVER[ $server_key ] ) ? trim( $_SERVER[ $server_key ] ) : '';
    }

    private static function route_param( $request, $name ) {
        $value = self::source_param( $request, $name, 'route' );
        return is_string( $value ) || is_numeric( $value ) ? (string) $value : '';
    }

    private static function body_param( $request, $name ) {
        $value = self::source_param( $request, $name, 'body' );
        return is_string( $value ) || is_numeric( $value ) ? (string) $value : '';
    }

    private static function numeric_body_param( $request, $name ) {
        $value = self::source_param( $request, $name, 'body' );
        return is_numeric( $value ) ? (int) $value : null;
    }

    private static function source_param( $request, $name, $source ) {
        $method = $source === 'route' ? 'get_url_params' : 'get_body_params';
        if ( is_object( $request ) && method_exists( $request, $method ) ) {
            $params = $request->{$method}();
            return is_array( $params ) && array_key_exists( $name, $params ) ? $params[ $name ] : null;
        }
        $key = $source === 'route' ? 'route_params' : 'body_params';
        if ( is_array( $request ) && isset( $request[ $key ] ) && is_array( $request[ $key ] ) ) {
            return array_key_exists( $name, $request[ $key ] ) ? $request[ $key ][ $name ] : null;
        }
        if ( is_array( $request ) && isset( $request['params'] ) && is_array( $request['params'] ) && array_key_exists( $name, $request['params'] ) ) {
            return $request['params'][ $name ];
        }
        return $source === 'body' && isset( $_POST[ $name ] ) ? $_POST[ $name ] : null;
    }

    private static function content_type_is( $request, $expected ) {
        $value = strtolower( self::header( $request, 'Content-Type' ) );
        $separator = strpos( $value, ';' );
        if ( $separator !== false ) {
            $value = trim( substr( $value, 0, $separator ) );
        }
        return $value === $expected;
    }

    private static function request_exceeds_limit( $request ) {
        $length = self::content_length( $request );
        if ( $length === null ) {
            return false;
        }
        $cap = PostSize::effective_cap( self::header( $request, 'Content-Type' ), Config::get() );
        return $length > $cap;
    }

    private static function content_length( $request ) {
        if ( is_array( $request ) && isset( $request['content_length'] ) && is_numeric( $request['content_length'] ) ) {
            return max( 0, (int) $request['content_length'] );
        }
        $header = self::header( $request, 'Content-Length' );
        if ( $header !== '' && is_numeric( $header ) ) {
            return max( 0, (int) $header );
        }
        if ( isset( $_SERVER['CONTENT_LENGTH'] ) && is_numeric( $_SERVER['CONTENT_LENGTH'] ) ) {
            return max( 0, (int) $_SERVER['CONTENT_LENGTH'] );
        }
        return null;
    }

    private static function method_failure( $allow ) {
        $result = self::error( 405, 'EFORMS_ERR_METHOD_NOT_ALLOWED' );
        $result['headers']['Allow'] = $allow;
        return $result;
    }

    private static function json( $status, $body ) {
        return self::result(
            $status,
            array(
                'Cache-Control' => 'no-store, max-age=0',
                'Content-Type' => 'application/json; charset=utf-8',
            ),
            $body
        );
    }

    private static function error( $status, $code ) {
        return self::json( $status, array( 'error' => $code ) );
    }

    private static function result( $status, $headers, $body ) {
        return array( 'status' => (int) $status, 'headers' => $headers, 'body' => $body );
    }
}
