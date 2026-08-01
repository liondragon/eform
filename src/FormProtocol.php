<?php
/**
 * Internal form protocol names shared across PHP and forms.js.
 *
 * Contract: Template model
 * Contract: Assets
 * Contract: JS-minted mode contract
 */

require_once __DIR__ . '/Anchors.php';
require_once __DIR__ . '/Uploads/UploadPolicy.php';

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
    const FIELD_UPLOAD_BATCHES = 'eforms_upload_batches';
    const UPLOAD_BATCH_ID = 'batch_id';
    const UPLOAD_BATCH_SECRET = 'batch_secret';

    const HEADER_BATCH_SECRET = 'X-EForms-Batch-Secret';
    const HEADER_ENHANCED_RESPONSE = 'X-EForms-Response';
    const ENHANCED_RESPONSE_JSON = 'json';
    const RESPONSE_OK = 'ok';
    const RESPONSE_LOCATION = 'location';
    const RESPONSE_ERRORS = 'errors';
    const RESPONSE_ERRORS_GLOBAL = 'global';
    const RESPONSE_ERRORS_FIELDS = 'fields';
    const RESPONSE_ERROR = 'error';
    const RESPONSE_CODE = 'code';
    const RESPONSE_MESSAGE = 'message';
    const RESPONSE_CAN_RETRY = 'can_retry';
    const RESPONSE_UPLOAD_RECOVERY = 'upload_recovery';
    const RESPONSE_STATE = 'state';
    const RESPONSE_CHALLENGE = 'challenge';
    const RESPONSE_CHALLENGE_PROVIDER = 'provider';
    const RESPONSE_CHALLENGE_SITE_KEY = 'site_key';
    const CHALLENGE_SCRIPT_URL = 'script_url';
    const UPLOAD_RECOVERY_OPEN = 'open';
    const UPLOAD_RECOVERY_FINALIZING = 'finalizing_recovery';
    const UPLOAD_BATCH_PARAM = 'batch_id';
    const UPLOAD_ITEM_PARAM = 'upload_id';
    const UPLOAD_FIELD_PARAM = 'field_key';
    const UPLOAD_FILE_PARAM = 'file';
    const UPLOAD_ORDINAL_PARAM = 'ordinal';
    const UPLOAD_DISPLAY_NAME_PARAM = 'display_name';
    const UPLOAD_BYTES_PARAM = 'bytes';
    const UPLOAD_MIME_PARAM = 'mime';
    const UPLOAD_RECEIPT_PARAM = 'receipt';
    const UPLOAD_RESPONSE_BATCH_ID = 'batch_id';
    const UPLOAD_RESPONSE_STATE = 'state';
    const UPLOAD_RESPONSE_ACCEPT_UNTIL = 'accept_until';
    const UPLOAD_RESPONSE_DELETE_AFTER = 'delete_after';
    const UPLOAD_RESPONSE_ITEMS = 'items';
    const UPLOAD_RESPONSE_INTENTS = 'intents';
    const UPLOAD_RESPONSE_LIMITS = 'limits';
    const UPLOAD_RESPONSE_MAX_FILE_BYTES = 'max_file_bytes';
    const UPLOAD_RESPONSE_MAX_FILES = 'max_files';
    const UPLOAD_RESPONSE_MAX_TOTAL_BYTES = 'max_total_bytes';
    const UPLOAD_RESPONSE_UPLOAD_ID = 'upload_id';
    const UPLOAD_RESPONSE_ORDINAL = 'ordinal';
    const UPLOAD_RESPONSE_DISPLAY_NAME = 'display_name';
    const UPLOAD_RESPONSE_BYTES = 'bytes';
    const UPLOAD_RESPONSE_MIME = 'mime';
    const UPLOAD_RESPONSE_WIDTH = 'width';
    const UPLOAD_RESPONSE_HEIGHT = 'height';
    const UPLOAD_RESPONSE_DELETED = 'deleted';
    const UPLOAD_RESPONSE_AUTHORIZED = 'authorized';
    const UPLOAD_RESPONSE_COMMITTED = 'committed';
    const UPLOAD_RESPONSE_TRANSPORT = 'transport';
    const UPLOAD_RESPONSE_TRANSPORT_KIND = 'kind';
    const UPLOAD_RESPONSE_TRANSPORT_URL = 'url';
    const UPLOAD_RESPONSE_TRANSPORT_GRANT = 'grant';
    const UPLOAD_RESPONSE_TRANSPORT_MIME = 'mime';
    const UPLOAD_TRANSPORT_LOCAL = 'local';
    const UPLOAD_TRANSPORT_WORKER = 'worker';
    const HEADER_WORKER_GRANT = 'X-EForms-Worker-Grant';

    const MINT_FORM_PARAM = 'f';
    const MINT_RESPONSE_TOKEN = 'token';
    const MINT_RESPONSE_INSTANCE_ID = 'instance_id';
    const MINT_RESPONSE_TIMESTAMP = 'timestamp';
    const MINT_RESPONSE_EXPIRES = 'expires';

    const DATA_MODE = 'data-eforms-mode';
    const DATA_TOKEN_TTL_MAX = 'data-eforms-token-ttl-max';
    const DATA_UPLOAD_MOUNT = 'data-eforms-upload';
    const DATA_UPLOAD_PICKER = 'data-eforms-upload-picker';
    const DATA_UPLOAD_PICKER_ID = 'data-eforms-upload-picker-id';
    const DATA_UPLOAD_FIELD = 'data-eforms-upload-field';
    const DATA_UPLOAD_ACCEPT = 'data-eforms-upload-accept';
    const DATA_UPLOAD_MAX_FILES = 'data-eforms-upload-max-files';
    const DATA_UPLOAD_MAX_FILE_BYTES = 'data-eforms-upload-max-file-bytes';
    const DATA_UPLOAD_MAX_TOTAL_BYTES = 'data-eforms-upload-max-total-bytes';
    const DATA_FIELD_KEY = 'data-eforms-field-key';
    const DATA_FIELD_CONTROL = 'data-eforms-field-control';
    const DATA_PHONE_FORMAT = 'data-eforms-phone-format';
    const DATA_ZIP_FORMAT = 'data-eforms-zip-format';
    const DATA_INTEGER_FORMAT = 'data-eforms-integer-format';
    const DATA_URL_NORMALIZE = 'data-eforms-url-normalize';
    const DATA_INPUT_UNIT = 'data-eforms-input-unit';
    const DATA_FIELD_ERROR_MOUNT = 'data-eforms-field-error-mount';
    const DATA_CHALLENGE_MOUNT = 'data-eforms-challenge-mount';

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
            self::FIELD_UPLOAD_BATCHES,
            self::UPLOAD_BATCH_ID,
            self::UPLOAD_BATCH_SECRET,
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
            'field_key' => self::DATA_FIELD_KEY,
            'field_control' => self::DATA_FIELD_CONTROL,
            'phone_format' => self::DATA_PHONE_FORMAT,
            'zip_format' => self::DATA_ZIP_FORMAT,
            'integer_format' => self::DATA_INTEGER_FORMAT,
            'url_normalize' => self::DATA_URL_NORMALIZE,
            'input_unit' => self::DATA_INPUT_UNIT,
            'field_error_mount' => self::DATA_FIELD_ERROR_MOUNT,
            'challenge_mount' => self::DATA_CHALLENGE_MOUNT,
        );
    }

    public static function upload_batch_fields() {
        return array(
            'root' => self::FIELD_UPLOAD_BATCHES,
            'batch_id' => self::UPLOAD_BATCH_ID,
            'batch_secret' => self::UPLOAD_BATCH_SECRET,
        );
    }

    public static function upload_data_attributes() {
        return array(
            'mount' => self::DATA_UPLOAD_MOUNT,
            'picker' => self::DATA_UPLOAD_PICKER,
            'pickerId' => self::DATA_UPLOAD_PICKER_ID,
            'field' => self::DATA_UPLOAD_FIELD,
            'accept' => self::DATA_UPLOAD_ACCEPT,
            'maxFiles' => self::DATA_UPLOAD_MAX_FILES,
            'maxFileBytes' => self::DATA_UPLOAD_MAX_FILE_BYTES,
            'maxTotalBytes' => self::DATA_UPLOAD_MAX_TOTAL_BYTES,
        );
    }

    public static function upload_response_names() {
        return array(
            'batchId' => self::UPLOAD_RESPONSE_BATCH_ID,
            'state' => self::UPLOAD_RESPONSE_STATE,
            'acceptUntil' => self::UPLOAD_RESPONSE_ACCEPT_UNTIL,
            'deleteAfter' => self::UPLOAD_RESPONSE_DELETE_AFTER,
            'items' => self::UPLOAD_RESPONSE_ITEMS,
            'intents' => self::UPLOAD_RESPONSE_INTENTS,
            'limits' => self::UPLOAD_RESPONSE_LIMITS,
            'maxFileBytes' => self::UPLOAD_RESPONSE_MAX_FILE_BYTES,
            'maxFiles' => self::UPLOAD_RESPONSE_MAX_FILES,
            'maxTotalBytes' => self::UPLOAD_RESPONSE_MAX_TOTAL_BYTES,
            'uploadId' => self::UPLOAD_RESPONSE_UPLOAD_ID,
            'ordinal' => self::UPLOAD_RESPONSE_ORDINAL,
            'displayName' => self::UPLOAD_RESPONSE_DISPLAY_NAME,
            'bytes' => self::UPLOAD_RESPONSE_BYTES,
            'mime' => self::UPLOAD_RESPONSE_MIME,
            'width' => self::UPLOAD_RESPONSE_WIDTH,
            'height' => self::UPLOAD_RESPONSE_HEIGHT,
            'authorized' => self::UPLOAD_RESPONSE_AUTHORIZED,
            'committed' => self::UPLOAD_RESPONSE_COMMITTED,
            'transport' => self::UPLOAD_RESPONSE_TRANSPORT,
            'transportKind' => self::UPLOAD_RESPONSE_TRANSPORT_KIND,
            'transportUrl' => self::UPLOAD_RESPONSE_TRANSPORT_URL,
            'transportGrant' => self::UPLOAD_RESPONSE_TRANSPORT_GRANT,
            'transportMime' => self::UPLOAD_RESPONSE_TRANSPORT_MIME,
        );
    }

    public static function upload_batch_id_pattern( $anchored = true ) {
        return self::protocol_pattern( Anchors::get( 'MANAGED_BATCH_ID_CHARS' ), Anchors::get( 'MANAGED_BATCH_ID_CHARS' ), $anchored );
    }

    public static function upload_batch_secret_pattern( $anchored = true ) {
        $bytes = Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' );
        $chars = intdiv( (int) $bytes * 8 + 5, 6 );
        return self::protocol_pattern( $chars, $chars, $anchored );
    }

    public static function managed_id_pattern( $anchored = true ) {
        return self::protocol_pattern( 1, Anchors::get( 'MANAGED_ID_MAX_CHARS' ), $anchored );
    }

    public static function upload_runtime() {
        return array(
            'batchIdChars' => Anchors::get( 'MANAGED_BATCH_ID_CHARS' ),
            'batchSecretBytes' => Anchors::get( 'MANAGED_BATCH_SECRET_BYTES' ),
            'uploadIdBytes' => Anchors::get( 'MANAGED_UPLOAD_ID_BYTES' ),
            'uploadIdMaxChars' => Anchors::get( 'MANAGED_ID_MAX_CHARS' ),
            'concurrency' => Anchors::get( 'MANAGED_UPLOAD_CONCURRENCY' ),
            'displayNameMaxChars' => Anchors::get( 'MANAGED_DISPLAY_NAME_MAX_CHARS' ),
        );
    }

    public static function client_preparation_recipe() {
        return array(
            'version' => Anchors::get( 'CLIENT_PREPARATION_RECIPE_VERSION' ),
            'slots' => Anchors::get( 'CLIENT_PREPARATION_SLOTS' ),
            'jpegTriggerBytes' => Anchors::get( 'CLIENT_PREPARATION_JPEG_TRIGGER_BYTES' ),
            'jpegTriggerEdge' => Anchors::get( 'CLIENT_PREPARATION_JPEG_TRIGGER_EDGE' ),
            'inputMaxBytes' => Anchors::get( 'CLIENT_PREPARATION_INPUT_MAX_BYTES' ),
            'inputMaxPixels' => Anchors::get( 'CLIENT_PREPARATION_INPUT_MAX_PIXELS' ),
            'inputMaxEdge' => Anchors::get( 'CLIENT_PREPARATION_INPUT_MAX_EDGE' ),
            'outputMaxEdge' => Anchors::get( 'CLIENT_PREPARATION_OUTPUT_MAX_EDGE' ),
            'jpegQuality' => Anchors::get( 'CLIENT_PREPARATION_JPEG_QUALITY' ),
            'minimumSavingsPercent' => Anchors::get( 'CLIENT_PREPARATION_MIN_SAVINGS_PERCENT' ),
            'timeoutMs' => Anchors::get( 'CLIENT_PREPARATION_TIMEOUT_MS' ),
            'headerScanBytes' => Anchors::get( 'CLIENT_PREPARATION_HEADER_SCAN_BYTES' ),
            'exifMaxEntries' => Anchors::get( 'CLIENT_PREPARATION_EXIF_MAX_ENTRIES' ),
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
            'enhancedResponse' => array(
                'header' => self::HEADER_ENHANCED_RESPONSE,
                'value' => self::ENHANCED_RESPONSE_JSON,
                'response' => array(
                    'ok' => self::RESPONSE_OK,
                    'location' => self::RESPONSE_LOCATION,
                    'errors' => self::RESPONSE_ERRORS,
                    'global' => self::RESPONSE_ERRORS_GLOBAL,
                    'fields' => self::RESPONSE_ERRORS_FIELDS,
                    'error' => self::RESPONSE_ERROR,
                    'code' => self::RESPONSE_CODE,
                    'message' => self::RESPONSE_MESSAGE,
                    'canRetry' => self::RESPONSE_CAN_RETRY,
                    'uploadRecovery' => self::RESPONSE_UPLOAD_RECOVERY,
                    'state' => self::RESPONSE_STATE,
                    'open' => self::UPLOAD_RECOVERY_OPEN,
                    'finalizingRecovery' => self::UPLOAD_RECOVERY_FINALIZING,
                    'challenge' => self::RESPONSE_CHALLENGE,
                    'provider' => self::RESPONSE_CHALLENGE_PROVIDER,
                    'siteKey' => self::RESPONSE_CHALLENGE_SITE_KEY,
                ),
            ),
            'upload' => array(
                'batchSecretHeader' => self::HEADER_BATCH_SECRET,
                'formParam' => self::FIELD_FORM_ID,
                'fieldParam' => self::UPLOAD_FIELD_PARAM,
                'fileParam' => self::UPLOAD_FILE_PARAM,
                'ordinalParam' => self::UPLOAD_ORDINAL_PARAM,
                'displayNameParam' => self::UPLOAD_DISPLAY_NAME_PARAM,
                'bytesParam' => self::UPLOAD_BYTES_PARAM,
                'mimeParam' => self::UPLOAD_MIME_PARAM,
                'receiptParam' => self::UPLOAD_RECEIPT_PARAM,
                'workerGrantHeader' => self::HEADER_WORKER_GRANT,
                'localTransport' => self::UPLOAD_TRANSPORT_LOCAL,
                'workerTransport' => self::UPLOAD_TRANSPORT_WORKER,
                'batchFields' => self::upload_batch_fields(),
                'dataAttributes' => self::upload_data_attributes(),
                'response' => self::upload_response_names(),
                'runtime' => self::upload_runtime(),
                'mimeByExtension' => UploadPolicy::staged_browser_mime_by_extension(),
            ),
            'storageTokenPrefix' => self::STORAGE_TOKEN_PREFIX,
        );
    }

    private static function protocol_pattern( $min, $max, $anchored ) {
        $min = max( 1, (int) $min );
        $max = max( $min, (int) $max );
        $quantifier = $min === $max ? (string) $max : $min . ',' . $max;
        $body = '[A-Za-z0-9_-]{' . $quantifier . '}';
        return $anchored ? '/^' . $body . '$/' : $body;
    }
}
