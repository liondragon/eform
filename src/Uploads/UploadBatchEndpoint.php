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
require_once __DIR__ . '/WorkerClient.php';
require_once __DIR__ . '/WorkerProtocol.php';

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
        if ( self::request_exceeds_limit( $request ) ) {
            return self::error( 413, 'EFORMS_ERR_UPLOAD_TYPE' );
        }
        $composition = WorkerClient::composition();
        if ( $composition === null ) {
            return self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }
        $composition_identity = $composition === FormProtocol::UPLOAD_TRANSPORT_WORKER
            ? WorkerClient::composition_fingerprint()
            : UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY;
        if ( $composition_identity === '' ) {
            return self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
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
            function () use ( $token, $form_id, $instance_id, $field_key, $validated, $secret, $field, $uploads_dir, $composition, $composition_identity ) {
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
                    $uploads_dir,
                    null,
                    $composition,
                    $composition_identity
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
        if ( self::content_type_is( $request, 'application/x-www-form-urlencoded' ) ) {
            if ( self::request_exceeds_limit( $request ) ) {
                return self::error( 413, 'EFORMS_ERR_UPLOAD_TYPE' );
            }
            if ( self::body_param( $request, FormProtocol::UPLOAD_RECEIPT_PARAM ) !== '' ) {
                return self::complete_worker_upload( $request );
            }
            return self::authorize_upload( $request );
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
        $owner = self::batch_owner( $batch_id, $secret );
        if ( empty( $owner['ok'] ) ) {
            return self::store_error( $owner );
        }
        if ( $owner['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_LOCAL ) {
            return self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }

        $result = UploadBatchStore::put_item(
            $batch_id,
            $secret,
            $upload_id,
            $ordinal,
            $item,
            self::uploads_dir(),
            array( 'require_preauthorized_intent' => true )
        );
        return empty( $result['ok'] ) ? self::store_error( $result ) : self::json( 200, self::item_response( $result['item'] ) );
    }

    private static function authorize_upload( $request ) {
        $batch_id = self::route_param( $request, FormProtocol::UPLOAD_BATCH_PARAM );
        $upload_id = self::route_param( $request, FormProtocol::UPLOAD_ITEM_PARAM );
        $secret = self::header( $request, FormProtocol::HEADER_BATCH_SECRET );
        $owner = self::batch_owner( $batch_id, $secret );
        if ( empty( $owner['ok'] ) ) {
            return self::store_error( $owner );
        }
        $artifact_store = $owner['artifact_store'];
        $worker = $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER ? WorkerClient::configuration() : null;
        if ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER && $worker === null ) {
            return self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }
        $ordinal = self::numeric_body_param( $request, FormProtocol::UPLOAD_ORDINAL_PARAM );
        $display_name = self::body_param( $request, FormProtocol::UPLOAD_DISPLAY_NAME_PARAM );
        $declared_bytes = self::numeric_body_param( $request, FormProtocol::UPLOAD_BYTES_PARAM );
        $declared_mime = self::body_param( $request, FormProtocol::UPLOAD_MIME_PARAM );
        if ( $ordinal === null || $declared_bytes === null ) {
            return self::error( 400, 'EFORMS_ERR_UPLOAD_TYPE' );
        }

        if ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
            $grants_ready = self::worker_grants_ready( $request );
            $options = self::worker_authorization_options( $owner['batch'], $owner['artifact_store_identity'], ! $grants_ready );
            if ( $options === null ) {
                return self::error( 409, 'EFORMS_ERR_TOKEN' );
            }
            $worker_options = $grants_ready ? $options : array_replace( $options, array( 'committed_only' => true ) );
            $result = UploadBatchStore::worker_authorize_intent(
                $batch_id,
                $secret,
                $upload_id,
                $ordinal,
                $display_name,
                $declared_bytes,
                $declared_mime,
                self::uploads_dir(),
                $worker_options
            );
            if ( ! $grants_ready ) {
                return ! empty( $result['ok'] ) && ! empty( $result['committed'] )
                    ? self::json( 200, self::authorization_response( $result ) )
                    : self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
            }
        } else {
            $result = UploadBatchStore::authorize_intent(
                $batch_id,
                $secret,
                $upload_id,
                $ordinal,
                $display_name,
                $declared_bytes,
                $declared_mime,
                $declared_bytes,
                self::uploads_dir()
            );
        }
        if ( empty( $result['ok'] ) ) {
            return self::store_error( $result );
        }
        $response = self::authorization_response( $result, $artifact_store, $batch_id, $worker );
        if ( $response === null ) {
            return self::error( 503, 'EFORMS_ERR_STORAGE_UNAVAILABLE' );
        }
        return self::json( 200, $response );
    }

    private static function authorization_response( $result, $artifact_store = '', $batch_id = '', $worker = null ) {
        $response = array(
            FormProtocol::UPLOAD_RESPONSE_AUTHORIZED => true,
            FormProtocol::UPLOAD_RESPONSE_COMMITTED => ! empty( $result['committed'] ),
        );
        if ( ! empty( $result['committed'] ) && isset( $result['item'] ) ) {
            return array_merge( $response, self::item_response( $result['item'] ) );
        } elseif ( isset( $result['intent'] ) && is_array( $result['intent'] ) ) {
            $response[ FormProtocol::UPLOAD_RESPONSE_TRANSPORT ] = $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL
                ? array( FormProtocol::UPLOAD_RESPONSE_TRANSPORT_KIND => FormProtocol::UPLOAD_TRANSPORT_LOCAL )
                : self::worker_transport( $result['intent'], $batch_id, $worker );
            if ( $response[ FormProtocol::UPLOAD_RESPONSE_TRANSPORT ] === null ) {
                return null;
            }
            return $response;
        }
        return null;
    }

    private static function complete_worker_upload( $request ) {
        $batch_id = self::route_param( $request, FormProtocol::UPLOAD_BATCH_PARAM );
        $secret = self::header( $request, FormProtocol::HEADER_BATCH_SECRET );
        $owner = self::batch_owner( $batch_id, $secret );
        if ( empty( $owner['ok'] ) ) {
            return self::store_error( $owner );
        }
        if ( $owner['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER ) {
            return self::error( 409, 'EFORMS_ERR_TOKEN' );
        }
        $worker = WorkerClient::configuration();
        $token = self::body_param( $request, FormProtocol::UPLOAD_RECEIPT_PARAM );
        if ( $worker === null || $token === '' || strlen( $token ) > Anchors::get( 'WORKER_ENVELOPE_MAX_CHARS' ) ) {
            return self::error( $worker === null ? 503 : 409, $worker === null ? 'EFORMS_ERR_STORAGE_UNAVAILABLE' : 'EFORMS_ERR_TOKEN' );
        }
        $verified = WorkerProtocol::verify_worker_stored_receipt( $token, $worker['keys'], $worker['environment'] );
        if ( empty( $verified['ok'] ) || ! isset( $verified['claims'] ) ) {
            return self::error( 409, 'EFORMS_ERR_TOKEN' );
        }
        $result = UploadBatchStore::worker_complete_stored_receipt(
            $batch_id,
            $secret,
            self::route_param( $request, FormProtocol::UPLOAD_ITEM_PARAM ),
            $verified['claims'],
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
        $batch_id = self::route_param( $request, FormProtocol::UPLOAD_BATCH_PARAM );
        $secret = self::header( $request, FormProtocol::HEADER_BATCH_SECRET );
        $owner = self::batch_owner( $batch_id, $secret );
        if ( empty( $owner['ok'] ) ) {
            return self::store_error( $owner );
        }
        $result = $owner['artifact_store'] === FormProtocol::UPLOAD_TRANSPORT_WORKER
            ? UploadBatchStore::worker_delete_item(
                $batch_id,
                $secret,
                $upload_id,
                self::uploads_dir()
            )
            : UploadBatchStore::delete_item(
                $batch_id,
                $secret,
                $upload_id,
                self::uploads_dir()
            );
        return empty( $result['ok'] )
            ? self::store_error( $result )
            : self::json( 200, array( FormProtocol::UPLOAD_RESPONSE_DELETED => true, FormProtocol::UPLOAD_RESPONSE_UPLOAD_ID => $upload_id ) );
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
        $intents = array();
        foreach ( isset( $batch['intents'] ) && is_array( $batch['intents'] ) ? $batch['intents'] : array() as $intent ) {
            $intent_bytes = isset( $intent['bytes'] ) ? (int) $intent['bytes'] : ( isset( $intent['declared_bytes'] ) ? (int) $intent['declared_bytes'] : 0 );
            $intents[] = array(
                FormProtocol::UPLOAD_RESPONSE_UPLOAD_ID => isset( $intent['upload_id'] ) ? $intent['upload_id'] : '',
                FormProtocol::UPLOAD_RESPONSE_ORDINAL => isset( $intent['ordinal'] ) ? (int) $intent['ordinal'] : 0,
                FormProtocol::UPLOAD_RESPONSE_DISPLAY_NAME => isset( $intent['display_name'] ) ? $intent['display_name'] : '',
                FormProtocol::UPLOAD_RESPONSE_BYTES => $intent_bytes,
            );
        }
        $limits = isset( $batch['limits'] ) && is_array( $batch['limits'] ) ? $batch['limits'] : array();
        return array(
            FormProtocol::UPLOAD_RESPONSE_BATCH_ID => isset( $batch['batch_id'] ) ? $batch['batch_id'] : '',
            FormProtocol::UPLOAD_RESPONSE_STATE => isset( $batch['state'] ) ? $batch['state'] : '',
            FormProtocol::UPLOAD_RESPONSE_ACCEPT_UNTIL => isset( $batch['accept_until'] ) ? (int) $batch['accept_until'] : 0,
            FormProtocol::UPLOAD_RESPONSE_DELETE_AFTER => isset( $batch['delete_after'] ) ? (int) $batch['delete_after'] : 0,
            FormProtocol::UPLOAD_RESPONSE_ITEMS => $items,
            FormProtocol::UPLOAD_RESPONSE_INTENTS => $intents,
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

    private static function batch_owner( $batch_id, $batch_secret ) {
        $status = UploadBatchStore::status( $batch_id, $batch_secret, self::uploads_dir() );
        if ( empty( $status['ok'] ) ) {
            return $status;
        }
        $artifact_store = isset( $status['batch']['artifact_store'] ) ? $status['batch']['artifact_store'] : '';
        $artifact_store_identity = isset( $status['batch']['artifact_store_identity'] ) ? $status['batch']['artifact_store_identity'] : '';
        if ( ! in_array( $artifact_store, array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ), true )
            || ! is_string( $artifact_store_identity )
            || ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER
                && ! WorkerClient::composition_matches( $artifact_store_identity ) )
        ) {
            return array( 'ok' => false, 'code' => 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'reason' => 'artifact_store_invalid' );
        }
        return array( 'ok' => true, 'artifact_store' => $artifact_store, 'artifact_store_identity' => $artifact_store_identity, 'batch' => $status['batch'] );
    }

    private static function worker_transport( $intent, $batch_id, $worker ) {
        $upload_until = isset( $intent['upload_until'] ) ? (int) $intent['upload_until'] : 0;
        if ( $upload_until <= time() ) {
            return null;
        }
        $grant = WorkerProtocol::sign_worker_upload_grant(
            array(
                'intent_id' => $intent['intent_id'],
                'batch_id' => $batch_id,
                'upload_id' => $intent['upload_id'],
                'ordinal' => $intent['ordinal'],
                'storage_identity' => $intent['storage_identity'],
                'validation_contract_version' => $intent['validation_contract_version'],
                'object_key' => $intent['object_key'],
                'declared_bytes' => $intent['declared_bytes'],
                'declared_mime' => $intent['declared_mime'],
                'policy_fingerprint' => $intent['policy_fingerprint'],
                'max_bytes' => $intent['declared_bytes'],
                'max_edge' => Anchors::get( 'MANAGED_ARTIFACT_MAX_EDGE' ),
                'max_pixels' => Anchors::get( 'MANAGED_ARTIFACT_MAX_PIXELS' ),
                'container_entry_limit' => Anchors::get( 'MANAGED_HEIF_MAX_ASSOCIATION_ENTRIES' ),
                'upload_until' => $upload_until,
                'accept_until' => $intent['accept_until'],
                'validation_until' => $intent['validation_until'],
                'staged_delete_after' => $intent['staged_delete_after'],
                'grant_expires_at' => $upload_until,
            ),
            $worker['active_id'],
            $worker['active'],
            $worker['environment']
        );
        return $grant === '' ? null : array(
            FormProtocol::UPLOAD_RESPONSE_TRANSPORT_KIND => FormProtocol::UPLOAD_TRANSPORT_WORKER,
            FormProtocol::UPLOAD_RESPONSE_TRANSPORT_URL => $worker['origin'] . '/v1/upload',
            FormProtocol::UPLOAD_RESPONSE_TRANSPORT_GRANT => $grant,
            FormProtocol::UPLOAD_RESPONSE_TRANSPORT_MIME => $intent['declared_mime'],
        );
    }

    private static function worker_grants_ready( $request ) {
        $health = WorkerClient::health( null, self::worker_requester( $request ), 'upload_grant_readiness' );
        return ! empty( $health['ok'] ) && ! empty( $health['worker_ready'] );
    }

    private static function worker_requester( $request ) {
        return is_array( $request ) && isset( $request['worker_requester'] ) && is_callable( $request['worker_requester'] )
            ? $request['worker_requester']
            : null;
    }

    private static function worker_authorization_options( $batch, $artifact_store_identity, $committed_only = false ) {
        if ( ! is_array( $batch ) ) {
            return null;
        }
        $now = time();
        $accept_until = isset( $batch['accept_until'] ) ? (int) $batch['accept_until'] : 0;
        $staged_delete_after = isset( $batch['delete_after'] ) ? (int) $batch['delete_after'] : 0;
        $upload_until = min( $accept_until - 1, $now + Anchors::get( 'WORKER_UPLOAD_GRANT_TTL_SECONDS' ) );
        $validation_until = min( $staged_delete_after - 1, $accept_until + Anchors::get( 'MANAGED_ORPHAN_CLEANUP_GRACE_SECONDS' ) );
        if ( ( empty( $committed_only ) && $upload_until <= $now )
            || ( ! empty( $committed_only ) && $now >= $accept_until )
            || $upload_until >= $accept_until
            || $upload_until >= $validation_until
            || $validation_until >= $staged_delete_after
        ) {
            return null;
        }
        return array(
            'storage_identity' => $artifact_store_identity,
            'validation_contract_version' => WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION,
            'upload_until' => $upload_until,
            'accept_until' => $accept_until,
            'validation_until' => $validation_until,
            'staged_delete_after' => $staged_delete_after,
        );
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
        return PostSize::request_exceeds_cap( $request, self::header( $request, 'Content-Type' ), Config::get() );
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
