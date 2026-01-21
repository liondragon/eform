<?php
/**
 * Security helpers for token minting and validation.
 *
 * Spec: Hidden-mode contract (docs/Canonical_Spec.md#sec-hidden-mode)
 * Spec: Security invariants (docs/Canonical_Spec.md#sec-security-invariants)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Enums/SoftReason.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';
require_once __DIR__ . '/OriginPolicy.php';
require_once __DIR__ . '/TimingSignals.php';

class Security
{
    const TOKENS_DIR = 'tokens';
    const TOKEN_SUFFIX = '.json';
    const TOKEN_REGEX = '/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i';
    const INSTANCE_ID_REGEX = '/^[A-Za-z0-9_-]{22,32}$/';

    /**
     * Mint and persist a hidden-mode token record.
     *
     * @param string $form_id Form identifier (slug).
     * @param string|null $uploads_dir Optional override for tests.
     * @return array { ok, token, instance_id, issued_at, expires } on success; { ok:false, code, reason } on failure.
     */
    public static function mint_hidden_record($form_id, $uploads_dir = null)
    {
        return self::mint_record($form_id, 'hidden', $uploads_dir);
    }

    /**
     * Mint and persist a JS-minted token record.
     *
     * @param string $form_id Form identifier (slug).
     * @param string|null $uploads_dir Optional override for tests.
     * @return array { ok, token, instance_id, issued_at, expires } on success; { ok:false, code, reason } on failure.
     */
    public static function mint_js_record($form_id, $uploads_dir = null)
    {
        return self::mint_record($form_id, 'js', $uploads_dir);
    }

    private static function mint_record($form_id, $mode, $uploads_dir)
    {
        $config = Config::get();

        if ($mode !== 'hidden' && $mode !== 'js') {
            return self::failure('EFORMS_ERR_STORAGE_UNAVAILABLE', 'mode_invalid');
        }

        if (!is_string($form_id) || $form_id === '') {
            return self::failure('EFORMS_ERR_INVALID_FORM_ID', 'form_id_missing');
        }

        $uploads_dir = self::resolve_uploads_dir($uploads_dir, $config);
        if ($uploads_dir === '') {
            return self::failure('EFORMS_ERR_STORAGE_UNAVAILABLE', 'uploads_dir_missing');
        }

        if (!is_dir($uploads_dir)) {
            return self::failure('EFORMS_ERR_STORAGE_UNAVAILABLE', 'uploads_dir_missing');
        }

        if (!is_writable($uploads_dir)) {
            return self::failure('EFORMS_ERR_STORAGE_UNAVAILABLE', 'uploads_dir_unwritable');
        }

        $private = PrivateDir::ensure($uploads_dir);
        if (!is_array($private) || empty($private['ok'])) {
            $reason = is_array($private) && isset($private['error']) ? $private['error'] : 'private_dir_unavailable';
            return self::failure('EFORMS_ERR_STORAGE_UNAVAILABLE', $reason);
        }

        $token = self::generate_uuid_v4();
        if ($token === '') {
            return self::failure('EFORMS_ERR_STORAGE_UNAVAILABLE', 'token_generation_failed');
        }

        $instance_id = self::generate_instance_id();
        if ($instance_id === '') {
            return self::failure('EFORMS_ERR_STORAGE_UNAVAILABLE', 'instance_id_generation_failed');
        }

        $issued_at = time();
        $ttl = self::token_ttl_seconds($config);
        if ($ttl <= 0) {
            return self::failure('EFORMS_ERR_STORAGE_UNAVAILABLE', 'token_ttl_invalid');
        }
        $expires = $issued_at + $ttl;

        $record = array(
            'mode' => $mode,
            'form_id' => $form_id,
            'instance_id' => $instance_id,
            'issued_at' => $issued_at,
            'expires' => $expires,
        );

        $write = self::write_token_record($private['path'], $token, $record);
        if (!$write['ok']) {
            return self::failure('EFORMS_ERR_STORAGE_UNAVAILABLE', $write['reason']);
        }

        return array(
            'ok' => true,
            'token' => $token,
            'instance_id' => $instance_id,
            'issued_at' => $issued_at,
            'expires' => $expires,
        );
    }

    /**
     * Validate posted security metadata against the persisted token record.
     *
     * @param array $post POST payload (e.g., $_POST).
     * @param string $form_id Expected form identifier.
     * @param mixed $request Optional request object/array for header evaluation.
     * @param string|null $uploads_dir Optional override for tests.
     * @return array { mode, submission_id, token_ok, hard_fail, require_challenge, soft_reasons, error_code }
     */
    public static function token_validate($post, $form_id, $request = null, $uploads_dir = null)
    {
        $config = Config::get();
        $post = is_array($post) ? $post : array();
        $form_id = is_string($form_id) ? $form_id : '';

        if ($form_id === '') {
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        $token = self::post_string($post, 'eforms_token');
        $instance_id = self::post_string($post, 'instance_id');
        $posted_mode = self::post_string($post, 'eforms_mode');

        // Educational note: regex guards run before any disk access to reduce probing.
        if (!self::is_valid_token($token)) {
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        if (!self::is_valid_instance_id($instance_id)) {
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        $uploads_dir = self::resolve_uploads_dir($uploads_dir, $config);
        if ($uploads_dir === '') {
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        $record = self::read_token_record($uploads_dir, $token);
        if (!$record['ok']) {
            self::log_token_failure($record['reason'], array('form_id' => $form_id));
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        $validated = self::validate_token_record($record['record'], $form_id);
        if (!$validated['ok']) {
            self::log_token_failure($validated['reason'], array('form_id' => $form_id));
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        if ($instance_id !== $validated['instance_id']) {
            self::log_token_failure('instance_id_mismatch', array('form_id' => $form_id));
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        if ($posted_mode !== '' && $posted_mode !== $validated['mode']) {
            self::log_token_failure('mode_mismatch', array('form_id' => $form_id));
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        $now = time();
        if ($validated['expires'] <= $now) {
            self::log_token_failure('token_expired', array('form_id' => $form_id));
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        $origin_eval = OriginPolicy::evaluate($request, $config);
        if (!empty($origin_eval['hard_fail'])) {
            return self::hard_fail_result('EFORMS_ERR_ORIGIN_FORBIDDEN');
        }

        $timing_eval = TimingSignals::evaluate($post, $validated, $config, $now);
        if (!empty($timing_eval['hard_fail'])) {
            return self::hard_fail_result('EFORMS_ERR_TOKEN');
        }

        $soft_reasons = array();
        if (isset($origin_eval['soft_reasons']) && is_array($origin_eval['soft_reasons'])) {
            $soft_reasons = array_merge($soft_reasons, $origin_eval['soft_reasons']);
        }
        if (isset($timing_eval['soft_reasons']) && is_array($timing_eval['soft_reasons'])) {
            $soft_reasons = array_merge($soft_reasons, $timing_eval['soft_reasons']);
        }

        $soft_reasons = self::normalize_soft_reasons($soft_reasons);
        $require_challenge = self::challenge_required($config, $soft_reasons);

        return array(
            'mode' => $validated['mode'],
            'submission_id' => $token,
            'token_ok' => true,
            'hard_fail' => false,
            'require_challenge' => $require_challenge,
            'soft_reasons' => SoftReason::toStrings($soft_reasons),
            'error_code' => '',
        );
    }

    private static function resolve_uploads_dir($uploads_dir, $config)
    {
        if (is_string($uploads_dir) && $uploads_dir !== '') {
            return rtrim($uploads_dir, '/\\');
        }

        if (is_array($config) && isset($config['uploads']) && is_array($config['uploads'])) {
            if (isset($config['uploads']['dir']) && is_string($config['uploads']['dir']) && $config['uploads']['dir'] !== '') {
                return rtrim($config['uploads']['dir'], '/\\');
            }
        }

        return '';
    }

    private static function token_ttl_seconds($config)
    {
        $ttl = 0;
        if (is_array($config) && isset($config['security']) && is_array($config['security'])) {
            if (isset($config['security']['token_ttl_seconds']) && is_numeric($config['security']['token_ttl_seconds'])) {
                $ttl = (int) $config['security']['token_ttl_seconds'];
            }
        }

        if ($ttl <= 0 && class_exists('Anchors')) {
            $anchor = Anchors::get('TOKEN_TTL_MIN');
            if (is_int($anchor) && $anchor > 0) {
                $ttl = $anchor;
            }
        }

        return $ttl;
    }

    private static function write_token_record($private_dir, $token, $record)
    {
        if (!is_string($private_dir) || $private_dir === '') {
            return self::write_failure('private_dir_missing');
        }

        $tokens_dir = rtrim($private_dir, '/\\') . '/' . self::TOKENS_DIR;
        if (!self::ensure_dir($tokens_dir, 0700)) {
            return self::write_failure('tokens_dir_unavailable');
        }

        $shard = Helpers::h2($token);
        $shard_dir = $tokens_dir . '/' . $shard;
        if (!self::ensure_dir($shard_dir, 0700)) {
            return self::write_failure('shard_dir_unavailable');
        }

        $hash = hash('sha256', (string) $token);
        if (!is_string($hash) || $hash === '') {
            return self::write_failure('token_hash_failed');
        }

        $final = $shard_dir . '/' . $hash . self::TOKEN_SUFFIX;
        if (file_exists($final)) {
            return self::write_failure('token_record_exists');
        }

        $tmp = $shard_dir . '/.' . $hash . '.' . self::temp_suffix();

        $payload = self::encode_json($record);
        if ($payload === '') {
            return self::write_failure('encode_failed');
        }

        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            return self::write_failure('temp_create_failed');
        }

        // Educational note: we write to a temp file then rename to keep the record atomic.
        $written = @fwrite($handle, $payload);
        if (function_exists('fflush')) {
            @fflush($handle);
        }
        fclose($handle);

        if ($written === false) {
            @unlink($tmp);
            return self::write_failure('temp_write_failed');
        }

        if (!self::ensure_permissions($tmp, 0600)) {
            @unlink($tmp);
            return self::write_failure('temp_chmod_failed');
        }

        if (file_exists($final)) {
            @unlink($tmp);
            return self::write_failure('token_record_exists');
        }

        $renamed = @rename($tmp, $final);
        if (!$renamed || !file_exists($final)) {
            @unlink($tmp);
            return self::write_failure('rename_failed');
        }

        if (!self::ensure_permissions($final, 0600)) {
            return self::write_failure('final_chmod_failed');
        }

        return array(
            'ok' => true,
            'path' => $final,
        );
    }

    private static function ensure_dir($path, $mode)
    {
        if (is_dir($path)) {
            return self::ensure_permissions($path, $mode);
        }

        $created = @mkdir($path, $mode, true);
        if (!$created && !is_dir($path)) {
            return false;
        }

        return self::ensure_permissions($path, $mode);
    }

    private static function ensure_permissions($path, $mode)
    {
        if (@chmod($path, $mode)) {
            return true;
        }

        return false;
    }

    private static function failure($code, $reason)
    {
        return array(
            'ok' => false,
            'code' => $code,
            'reason' => $reason,
        );
    }

    private static function write_failure($reason)
    {
        return array(
            'ok' => false,
            'reason' => $reason,
        );
    }

    private static function hard_fail_result($code)
    {
        return array(
            'mode' => '',
            'submission_id' => '',
            'token_ok' => false,
            'hard_fail' => true,
            'require_challenge' => false,
            'soft_reasons' => array(),
            'error_code' => $code,
        );
    }

    private static function post_string($post, $key)
    {
        if (!is_array($post) || !isset($post[$key])) {
            return '';
        }

        $value = $post[$key];
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function is_valid_token($token)
    {
        return is_string($token) && preg_match(self::TOKEN_REGEX, $token) === 1;
    }

    private static function is_valid_instance_id($instance_id)
    {
        return is_string($instance_id) && preg_match(self::INSTANCE_ID_REGEX, $instance_id) === 1;
    }

    private static function read_token_record($uploads_dir, $token)
    {
        $private_dir = PrivateDir::path($uploads_dir);
        if (!is_string($private_dir) || $private_dir === '') {
            return self::read_failure('private_dir_missing');
        }

        $tokens_dir = rtrim($private_dir, '/\\') . '/' . self::TOKENS_DIR;
        $shard = Helpers::h2($token);
        $record_path = $tokens_dir . '/' . $shard . '/' . hash('sha256', $token) . self::TOKEN_SUFFIX;

        if (!is_file($record_path)) {
            return self::read_failure('token_missing');
        }

        if (!is_readable($record_path)) {
            return self::read_failure('token_unreadable');
        }

        $raw = file_get_contents($record_path);
        if ($raw === false) {
            return self::read_failure('token_read_failed');
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return self::read_failure('token_decode_failed');
        }

        return array(
            'ok' => true,
            'record' => $decoded,
            'path' => $record_path,
        );
    }

    private static function read_failure($reason)
    {
        return array(
            'ok' => false,
            'reason' => $reason,
        );
    }

    private static function validate_token_record($record, $form_id)
    {
        if (!is_array($record)) {
            return self::read_failure('record_not_array');
        }

        $required = array('mode', 'form_id', 'instance_id', 'issued_at', 'expires');
        foreach ($required as $key) {
            if (!array_key_exists($key, $record)) {
                return self::read_failure('record_missing_' . $key);
            }
        }

        if (!is_string($record['mode'])) {
            return self::read_failure('record_mode_invalid');
        }

        $mode = $record['mode'];
        if ($mode !== 'hidden' && $mode !== 'js') {
            return self::read_failure('record_mode_unknown');
        }

        if (!is_string($record['form_id']) || $record['form_id'] === '') {
            return self::read_failure('record_form_id_invalid');
        }

        if ($record['form_id'] !== $form_id) {
            return self::read_failure('record_form_id_mismatch');
        }

        if (!self::is_valid_instance_id($record['instance_id'])) {
            return self::read_failure('record_instance_invalid');
        }

        if (!is_numeric($record['issued_at']) || !is_numeric($record['expires'])) {
            return self::read_failure('record_time_invalid');
        }

        $issued_at = (int) $record['issued_at'];
        $expires = (int) $record['expires'];
        if ($expires <= $issued_at) {
            return self::read_failure('record_time_order_invalid');
        }

        return array(
            'ok' => true,
            'mode' => $mode,
            'form_id' => $record['form_id'],
            'instance_id' => $record['instance_id'],
            'issued_at' => $issued_at,
            'expires' => $expires,
        );
    }

    private static function normalize_soft_reasons($reasons)
    {
        if (!is_array($reasons)) {
            return array();
        }
        return SoftReason::normalize($reasons);
    }

    private static function challenge_required($config, $soft_reasons)
    {
        $mode = 'off';
        if (is_array($config) && isset($config['challenge']) && is_array($config['challenge'])) {
            if (isset($config['challenge']['mode']) && is_string($config['challenge']['mode'])) {
                $mode = $config['challenge']['mode'];
            }
        }

        if ($mode === 'always') {
            $mode = 'always_post';
        }

        if ($mode === 'always_post') {
            return true;
        }

        if ($mode === 'auto' && is_array($soft_reasons) && !empty($soft_reasons)) {
            return true;
        }

        return false;
    }

    private static function log_token_failure($reason, $meta)
    {
        if (!class_exists('Logging')) {
            return;
        }

        $payload = array(
            'reason' => is_string($reason) ? $reason : 'unknown',
        );

        if (is_array($meta)) {
            foreach ($meta as $key => $value) {
                if (is_string($key) && $key !== '' && is_scalar($value)) {
                    $payload[$key] = (string) $value;
                }
            }
        }

        Logging::event('warning', 'EFORMS_ERR_TOKEN', $payload);
    }

    private static function encode_json($payload)
    {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($payload);
        } else {
            $encoded = json_encode($payload);
        }

        return is_string($encoded) ? $encoded : '';
    }

    private static function generate_uuid_v4()
    {
        $bytes = self::random_bytes(16);
        if ($bytes === '') {
            return '';
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private static function generate_instance_id()
    {
        $bytes = self::random_bytes(16);
        if ($bytes === '') {
            return '';
        }

        return self::base64url_encode($bytes);
    }

    private static function base64url_encode($bytes)
    {
        $encoded = base64_encode($bytes);
        $encoded = strtr($encoded, '+/', '-_');
        return rtrim($encoded, '=');
    }

    private static function random_bytes($length)
    {
        $bytes = '';
        if (function_exists('random_bytes')) {
            try {
                $bytes = random_bytes($length);
            } catch (Exception $e) {
                $bytes = '';
            }
        }

        if ($bytes === '' && function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length);
        }

        if (!is_string($bytes) || strlen($bytes) !== $length) {
            $bytes = '';
            for ($i = 0; $i < $length; $i++) {
                $bytes .= chr(mt_rand(0, 255));
            }
        }

        return $bytes;
    }

    private static function temp_suffix()
    {
        $bytes = self::random_bytes(4);
        if ($bytes === '') {
            return (string) getmypid();
        }

        return bin2hex($bytes);
    }
}
