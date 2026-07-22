<?php
/**
 * Shared runtime health diagnostic for operator-facing surfaces.
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Email/Templates.php';
require_once __DIR__ . '/../Gc/GcRunner.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Logging/JsonlLogger.php';
require_once __DIR__ . '/../Rendering/TemplateContext.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Security/Challenge.php';
require_once __DIR__ . '/../Security/PostSize.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Security/Throttle.php';
require_once __DIR__ . '/../Submission/Ledger.php';
require_once __DIR__ . '/../Uploads/UploadBatchStore.php';
require_once __DIR__ . '/../Uploads/UploadPolicy.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';

class RuntimeHealthDiagnostic {
    const PROBE_FILENAME = '.eforms-doctor-probe';

    public static function run( $observations = array() ) {
        $observations = is_array( $observations ) ? $observations : array();
        $templates = self::template_inventory();
        $staged_fields = $templates['staged_fields'];
        $uploads_base = self::check_uploads_base();
        $private_lease = $uploads_base['result'] === 'PASS'
            ? PrivateDir::acquire_write_lease( self::uploads_dir() )
            : false;
        $checks = array(
            $uploads_base,
            self::check_private_storage( $private_lease ),
            self::check_runtime_dirs( $private_lease ),
            self::check_staged_image_processing( $observations ),
            self::check_managed_capacity( $observations, $private_lease ),
            self::check_managed_upload_dirs( $private_lease ),
            self::check_staged_request_limits( $observations, $staged_fields ),
            self::check_staged_throttle(),
            self::check_templates( $templates ),
            self::check_mail_format(),
            self::check_gc_readiness(),
            self::check_cli_bootstrap(),
            self::check_config_sources(),
            self::check_challenge_config(),
        );
        if ( $private_lease instanceof PrivateDirLease ) {
            $private_lease->release();
        }

        return self::result( $checks );
    }

    public static function summary_line( $result ) {
        $summary = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();
        $passed = isset( $summary['passed'] ) ? (int) $summary['passed'] : 0;
        $warnings = isset( $summary['warnings'] ) ? (int) $summary['warnings'] : 0;
        $failed = isset( $summary['failed'] ) ? (int) $summary['failed'] : 0;
        return sprintf(
            '%d passed, %d %s, %d failed',
            $passed,
            $warnings,
            $warnings === 1 ? 'warning' : 'warnings',
            $failed
        );
    }

    public static function rows( $result ) {
        $checks = isset( $result['checks'] ) && is_array( $result['checks'] ) ? $result['checks'] : array();
        $rows = array();
        foreach ( $checks as $check ) {
            $rows[] = array(
                'name' => isset( $check['name'] ) ? (string) $check['name'] : '',
                'result' => isset( $check['result'] ) ? (string) $check['result'] : 'FAIL',
                'observed' => isset( $check['observed'] ) ? (string) $check['observed'] : '',
                'expected' => isset( $check['expected'] ) ? (string) $check['expected'] : '',
                'notes' => isset( $check['notes'] ) ? (string) $check['notes'] : '',
            );
        }

        return $rows;
    }

    private static function check_uploads_base() {
        $uploads_dir = self::uploads_dir();
        if ( $uploads_dir === '' ) {
            return self::check( 'uploads-base', 'FAIL', 'missing', 'writable uploads base', 'uploads.dir is empty' );
        }
        if ( ! is_dir( $uploads_dir ) ) {
            return self::check( 'uploads-base', 'FAIL', 'not a directory', 'writable uploads base', 'uploads.dir is not a directory' );
        }
        if ( ! is_writable( $uploads_dir ) ) {
            return self::check( 'uploads-base', 'FAIL', 'not writable', 'writable uploads base', 'uploads.dir is not writable' );
        }

        return self::check( 'uploads-base', 'PASS', 'writable', 'writable uploads base', 'raw path hidden' );
    }

    private static function check_private_storage( $private_lease ) {
        if ( ! $private_lease instanceof PrivateDirLease ) {
            return self::check( 'private-storage', 'FAIL', self::private_storage_unavailable_reason(), 'private dir protected', 'could not create/protect eforms-private' );
        }

        $path = $private_lease->private_dir();
        foreach ( array( PrivateDir::INDEX_FILENAME, PrivateDir::HTACCESS_FILENAME, PrivateDir::WEBCONFIG_FILENAME ) as $file ) {
            if ( ! is_file( rtrim( $path, '/\\' ) . '/' . $file ) ) {
                return self::check( 'private-storage', 'FAIL', $file . ' missing', 'private dir protected', 'deny-rule file missing' );
            }
        }

        return self::check( 'private-storage', 'PASS', 'created/protected', 'private dir protected', 'raw path hidden' );
    }

    private static function check_runtime_dirs( $private_lease ) {
        if ( ! $private_lease instanceof PrivateDirLease ) {
            return self::check( 'runtime-dirs', 'FAIL', self::private_storage_unavailable_reason(), 'token/ledger/log/throttle usable', 'private lifecycle lease unavailable' );
        }

        $names = array( Security::TOKENS_DIR, Ledger::LEDGER_DIR, JsonlLogger::LOG_DIR, Throttle::THROTTLE_DIR );
        $failed = array();
        foreach ( $names as $name ) {
            if ( ! self::dir_usable( $private_lease, $name ) ) {
                $failed[] = $name;
            }
        }

        if ( ! empty( $failed ) ) {
            return self::check( 'runtime-dirs', 'FAIL', implode( ',', $failed ), 'token/ledger/log/throttle usable', 'one or more runtime dirs failed write/delete probe' );
        }

        return self::check( 'runtime-dirs', 'PASS', 'usable', 'token/ledger/log/throttle usable', 'temporary probes cleaned' );
    }

    private static function check_managed_upload_dirs( $private_lease ) {
        if ( ! $private_lease instanceof PrivateDirLease ) {
            return self::check( 'managed-upload-dirs', 'FAIL', self::private_storage_unavailable_reason(), 'staged/finalized storage protected and writable', 'private lifecycle lease unavailable' );
        }

        $failed = array();
        foreach ( array( UploadBatchStore::STAGED_DIR, UploadBatchStore::SUBMISSIONS_DIR ) as $name ) {
            if ( ! self::dir_usable( $private_lease, $name, true ) ) {
                $failed[] = $name;
            }
        }
        if ( ! empty( $failed ) ) {
            return self::check( 'managed-upload-dirs', 'FAIL', implode( ',', $failed ), 'staged/finalized storage protected and writable', 'one or more managed dirs failed write/delete probe' );
        }
        return self::check( 'managed-upload-dirs', 'PASS', 'protected and writable', 'staged/finalized storage protected and writable', 'private deny rules active; temporary probes cleaned' );
    }

    private static function check_staged_image_processing( $observations ) {
        $mimes = UploadPolicy::staged_mimes();
        $formats = array_map(
            function ( $mime ) {
                return strtoupper( substr( $mime, 6 ) );
            },
            $mimes
        );
        $expected = 'fileinfo + ' . implode( '/', $formats ) . ' decode + JPEG encode + resource allowance';
        $fileinfo = array_key_exists( 'fileinfo', $observations )
            ? (bool) $observations['fileinfo']
            : extension_loaded( 'fileinfo' ) && function_exists( 'finfo_open' );
        if ( ! $fileinfo ) {
            return self::check( 'staged-image-processing', 'FAIL', 'fileinfo unavailable', $expected, 'enable the PHP fileinfo extension' );
        }

        $options = array();
        foreach ( array( 'memory_limit', 'execution_limit', 'imagick_support', 'imagick_jpeg_encode' ) as $key ) {
            if ( array_key_exists( $key, $observations ) ) {
                $options[ $key ] = $observations[ $key ];
            }
        }
        $failures = array();
        $ready = UploadPolicy::staged_host_readiness( $options );
        if ( empty( $ready['ok'] ) ) {
            $reason = isset( $ready['reason'] ) ? $ready['reason'] : 'unavailable';
            $missing_mimes = isset( $ready['missing_mimes'] ) && is_array( $ready['missing_mimes'] )
                ? $ready['missing_mimes']
                : array();
            $missing_operations = isset( $ready['missing_operations'] ) && is_array( $ready['missing_operations'] )
                ? $ready['missing_operations']
                : array();
            if ( $reason === 'backend' && ! empty( $missing_mimes ) ) {
                foreach ( $missing_mimes as $mime ) {
                    $failures[] = substr( $mime, 6 ) . ':backend';
                }
            }
            if ( $reason === 'backend' && in_array( 'jpeg_encode', $missing_operations, true ) ) {
                $failures[] = 'jpeg-encode:backend';
            }
            if ( empty( $failures ) ) {
                $failures[] = $reason;
            }
        }
        if ( ! empty( $failures ) ) {
            $requirements = 'requires at least ' . self::format_bytes( Anchors::get( 'STAGED_IMAGE_MIN_MEMORY_BYTES' ) )
                . ' memory and ' . Anchors::get( 'STAGED_IMAGE_MIN_EXECUTION_SECONDS' ) . ' seconds execution time, or unlimited values';
            return self::check( 'staged-image-processing', 'FAIL', implode( ',', $failures ), $expected, $requirements );
        }
        return self::check( 'staged-image-processing', 'PASS', 'fileinfo and Imagick ready', $expected, 'JPEG masters and previews normalize orientation and color, strip metadata, and flatten alpha' );
    }

    private static function check_managed_capacity( $observations, $private_lease ) {
        $integer_bytes = array_key_exists( 'php_int_size', $observations ) ? $observations['php_int_size'] : PHP_INT_SIZE;
        if ( ! UploadBatchStore::capacity_platform_supported( $integer_bytes ) ) {
            return self::check( 'managed-capacity', 'FAIL', '32-bit PHP integers', '64-bit PHP integers with consistent accounting and provisioned storage', 'managed upload capacity cannot represent its fixed 50 GiB ceiling on this runtime' );
        }
        $health = UploadBatchStore::capacity_health( self::uploads_dir(), $private_lease );
        if ( empty( $health['ok'] ) ) {
            $reason = isset( $health['reason'] ) ? (string) $health['reason'] : 'capacity_unavailable';
            return self::check( 'managed-capacity', 'FAIL', $reason, 'accounting consistent and filesystem provisioned', 'capacity record could not be read safely' );
        }
        $capacity = $health['capacity'];
        if ( empty( $capacity['consistent'] ) ) {
            return self::check( 'managed-capacity', 'FAIL', 'accounting inconsistent', 'accounting consistent and filesystem provisioned', 'investigate interrupted writes, then run wp eforms gc --reconcile-capacity' );
        }
        $uploads_dir = self::uploads_dir();
        $total = array_key_exists( 'disk_total_bytes', $observations ) ? $observations['disk_total_bytes'] : @disk_total_space( $uploads_dir );
        $free = array_key_exists( 'disk_free_bytes', $observations ) ? $observations['disk_free_bytes'] : @disk_free_space( $uploads_dir );
        $required_total = Anchors::get( 'MANAGED_UPLOAD_MAX_BYTES' ) + Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' );
        if ( ! is_numeric( $total ) || ! is_numeric( $free ) ) {
            return self::check( 'managed-capacity', 'FAIL', 'filesystem capacity unavailable', 'accounting consistent and filesystem provisioned', 'disk total/free-space observations are required' );
        }
        if ( (int) $total < $required_total || (int) $free < Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) ) {
            $provision = 'provision ' . self::format_bytes( Anchors::get( 'MANAGED_UPLOAD_MAX_BYTES' ) )
                . ' managed capacity plus ' . self::format_bytes( Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) ) . ' operational free-space reserve';
            return self::check( 'managed-capacity', 'FAIL', 'filesystem below ceiling + reserve', 'accounting consistent and filesystem provisioned', $provision );
        }
        if ( (int) $capacity['committing_bytes'] > 0 || (int) $capacity['orphaned_bytes'] > 0 ) {
            return self::check( 'managed-capacity', 'WARN', 'unsettled reservations detected', 'accounting consistent and filesystem provisioned', 'run wp eforms gc --reconcile-capacity to settle committed or orphaned reservations' );
        }
        return self::check( 'managed-capacity', 'PASS', self::format_bytes( $capacity['total_bytes'] ) . ' accounted; filesystem ready', 'accounting consistent and filesystem provisioned', 'runtime reservations also preserve the ' . self::format_bytes( Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) ) . ' free-space reserve' );
    }

    private static function check_staged_request_limits( $observations, $staged_fields ) {
        $required_file = self::largest_staged_file_bytes( $staged_fields );
        if ( $required_file <= 0 ) {
            return self::check( 'staged-request-limits', 'PASS', 'no active staged fields', 'PHP limits cover largest staged item', 'recheck after enabling a staged field' );
        }
        $overhead = Anchors::get( 'STAGED_MULTIPART_OVERHEAD_BYTES' );
        if ( $required_file > PHP_INT_MAX - $overhead ) {
            return self::check( 'staged-request-limits', 'FAIL', 'largest staged item cannot fit within a PHP request cap', 'PHP limits cover largest staged item plus multipart overhead', 'lower the staged item limit to leave room for multipart overhead' );
        }
        $required_request = $required_file + $overhead;
        $upload_raw = array_key_exists( 'upload_max_filesize', $observations ) ? $observations['upload_max_filesize'] : ini_get( 'upload_max_filesize' );
        $post_raw = array_key_exists( 'post_max_size', $observations ) ? $observations['post_max_size'] : ini_get( 'post_max_size' );
        $upload_bytes = Helpers::bytes_from_ini( is_scalar( $upload_raw ) ? (string) $upload_raw : '' );
        $request_bytes = PostSize::effective_cap( PostSize::CT_MULTIPART, Config::get(), $post_raw, $upload_raw );
        if ( $upload_bytes < $required_file || $request_bytes < $required_request ) {
            return self::check( 'staged-request-limits', 'FAIL', 'effective request limit below ' . self::format_bytes( $required_request ), 'PHP limits cover largest staged item plus multipart overhead', 'raise upload_max_filesize and post_max_size above the largest staged item plus multipart overhead' );
        }
        return self::check( 'staged-request-limits', 'PASS', 'effective request limit covers ' . self::format_bytes( $required_request ), 'PHP limits cover largest staged item plus multipart overhead', 'web-server request limits must be checked separately' );
    }

    private static function check_staged_throttle() {
        $config = Config::get();
        $enabled = Config::bool( $config, array( 'throttle', 'enable' ), false );
        $limit = Config::value( $config, array( 'throttle', 'per_ip', 'max_per_minute' ), 0 );
        if ( ! $enabled || ! is_numeric( $limit ) || (int) $limit < 1 ) {
            return self::check( 'staged-throttle', 'FAIL', 'disabled', 'per-IP throttle enabled for staged endpoints', 'image decoding is intentionally unavailable for production until throttling is enabled' );
        }
        return self::check( 'staged-throttle', 'PASS', 'enabled at ' . (int) $limit . '/minute', 'per-IP throttle enabled for staged endpoints', 'tune for multi-file uploads and shared-IP traffic' );
    }

    private static function check_templates( $templates ) {
        if ( empty( $templates['directory'] ) ) {
            return self::check( 'templates', 'FAIL', 'missing directory', 'all shipped templates valid', 'templates/forms is unavailable' );
        }
        if ( empty( $templates['files'] ) ) {
            return self::check( 'templates', 'WARN', 'no json templates', 'all shipped templates valid', 'no shipped form templates found' );
        }
        if ( ! empty( $templates['invalid'] ) ) {
            return self::check( 'templates', 'FAIL', implode( ',', $templates['invalid'] ), 'all shipped templates valid', 'invalid shipped templates' );
        }
        return self::check( 'templates', 'PASS', $templates['files'] . ' valid', 'all shipped templates valid', '' );
    }

    private static function check_mail_format() {
        $html = Templates::render( 'default', true, array() );
        $text = Templates::render( 'default', false, array() );
        if ( ! is_array( $html ) || empty( $html['ok'] ) || ! is_array( $text ) || empty( $text['ok'] ) ) {
            return self::check( 'mail-format', 'WARN', 'template pair incomplete', 'HTML template with text alternative', 'default email template should ship both HTML and text bodies' );
        }

        $html_body = isset( $html['body'] ) ? $html['body'] : '';
        if ( ! is_string( $html_body ) ) {
            return self::check( 'mail-format', 'WARN', 'html unreadable', 'HTML template with text alternative', 'default HTML email template could not be inspected' );
        }

        $has_document = stripos( $html_body, '<html' ) !== false && stripos( $html_body, '<body' ) !== false;
        if ( ! $has_document ) {
            return self::check( 'mail-format', 'WARN', 'html fragment', 'HTML template with text alternative', 'wrap default HTML email in a full document' );
        }

        return self::check( 'mail-format', 'PASS', 'full html + text alternative', 'HTML template with text alternative', 'SpamAssassin headers are added by the mail stack, not eForms' );
    }

    private static function check_gc_readiness() {
        $result = GcRunner::run( array( 'dry_run' => true, 'limit' => 1 ) );
        if ( empty( $result['ok'] ) ) {
            $reason = isset( $result['reason'] ) ? (string) $result['reason'] : 'gc_unavailable';
            return self::check( 'gc-readiness', 'FAIL', $reason, 'dry-run can scan runtime storage', 'GC scheduling cannot be proven from PHP' );
        }

        if ( ! empty( $result['candidates'] ) || ! empty( $result['reached_limit'] ) ) {
            return self::check( 'gc-readiness', 'WARN', 'stale candidates found', 'dry-run can scan runtime storage', 'schedule wp eforms gc externally; cron itself is not provable' );
        }

        return self::check( 'gc-readiness', 'PASS', 'dry-run ok', 'dry-run can scan runtime storage', 'cron itself is not provable' );
    }

    private static function check_cli_bootstrap() {
        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
            return self::check( 'cli-bootstrap', 'PASS', 'WP-CLI active', 'doctor can run under WP-CLI', '' );
        }

        return self::check( 'cli-bootstrap', 'WARN', 'not running under WP-CLI', 'doctor can run under WP-CLI', 'run wp eforms doctor from the WordPress root to prove CLI bootstrap' );
    }

    private static function check_config_sources() {
        $report = Config::effective_report();
        if ( ! is_array( $report ) || empty( $report ) ) {
            return self::check( 'config-sources', 'FAIL', 'empty report', 'effective config provenance available', 'Config did not expose effective report' );
        }

        $uploads = isset( $report['uploads.dir'] ) && is_array( $report['uploads.dir'] ) ? $report['uploads.dir'] : array();
        $source = isset( $uploads['source'] ) ? (string) $uploads['source'] : 'unknown';
        return self::check( 'config-sources', 'PASS', 'provenance available', 'effective config provenance available', 'uploads.dir source=' . $source );
    }

    private static function check_challenge_config() {
        $config = Config::get();
        $mode = Config::value( $config, array( 'challenge', 'mode' ), 'off' );
        $mode = is_string( $mode ) && $mode !== '' ? $mode : 'off';
        if ( $mode === 'off' ) {
            return self::check( 'challenge-config', 'PASS', 'mode off', 'keys required only when challenge enabled', 'challenge disabled' );
        }

        $status = Challenge::configuration_status( $config );
        if ( ! empty( $status['configured'] ) ) {
            return self::check( 'challenge-config', 'PASS', 'mode ' . $mode . ' configured', 'Turnstile site and secret keys configured', 'provider=turnstile' );
        }

        return self::check( 'challenge-config', 'WARN', 'mode ' . $mode . ' missing keys', 'Turnstile site and secret keys configured', 'set challenge.site_key and challenge.secret_key' );
    }

    private static function dir_usable( $private_lease, $name, $with_protection = false ) {
        $dir = PrivateDir::leased_subdir( $private_lease, $name, true, $with_protection );
        if ( $dir === '' ) {
            return false;
        }

        $probe = rtrim( $dir, '/\\' ) . '/' . self::PROBE_FILENAME;
        if ( is_link( $probe ) || file_exists( $probe ) ) {
            return false;
        }
        $handle = @fopen( $probe, 'xb' );
        if ( $handle === false ) {
            @unlink( $probe );
            return false;
        }
        $written = @fwrite( $handle, 'ok' );
        $flushed = ! function_exists( 'fflush' ) || @fflush( $handle );
        fclose( $handle );
        if ( $written !== 2 || ! $flushed || ! @chmod( $probe, 0600 ) || is_link( $probe ) ) {
            @unlink( $probe );
            return false;
        }

        $read = @file_get_contents( $probe );
        @unlink( $probe );
        return $read === 'ok' && ! file_exists( $probe );
    }

    private static function private_storage_unavailable_reason() {
        return PrivateDir::is_purged( self::uploads_dir() ) ? 'managed_purged' : 'upload_lifecycle_unavailable';
    }

    private static function uploads_dir() {
        $config = Config::get();
        return Config::value( $config, array( 'uploads', 'dir' ), '' );
    }

    private static function largest_staged_file_bytes( $staged_fields ) {
        $largest = 0;
        foreach ( $staged_fields as $field ) {
            if ( isset( $field['max_file_bytes'] ) && is_int( $field['max_file_bytes'] ) ) {
                $largest = max( $largest, $field['max_file_bytes'] );
            }
        }
        return $largest;
    }

    private static function template_inventory() {
        $dir = dirname( __DIR__, 2 ) . '/templates/forms';
        if ( ! is_dir( $dir ) ) {
            return array( 'directory' => false, 'files' => 0, 'invalid' => array(), 'staged_fields' => array() );
        }
        $files = glob( rtrim( $dir, '/\\' ) . '/*.json' );
        $files = is_array( $files ) ? $files : array();
        $invalid = array();
        $staged = array();
        foreach ( $files as $path ) {
            $form_id = basename( $path, '.json' );
            $loaded = TemplateLoader::load( $form_id, $dir );
            $built = ! empty( $loaded['ok'] )
                ? TemplateContext::build( $loaded['template'], $loaded['version'] )
                : array( 'ok' => false );
            if ( empty( $built['ok'] ) || ! isset( $built['context'] ) || ! is_array( $built['context'] ) ) {
                $invalid[] = $form_id;
                continue;
            }
            if ( isset( $built['context']['staged_field'] ) && is_array( $built['context']['staged_field'] ) ) {
                $staged[] = $built['context']['staged_field'];
            }
        }
        return array(
            'directory' => true,
            'files' => count( $files ),
            'invalid' => $invalid,
            'staged_fields' => $staged,
        );
    }

    private static function format_bytes( $bytes ) {
        $bytes = is_numeric( $bytes ) ? max( 0, (int) $bytes ) : 0;
        if ( $bytes >= 1073741824 && $bytes % 1073741824 === 0 ) {
            return ( $bytes / 1073741824 ) . ' GiB';
        }
        if ( $bytes >= 1048576 && $bytes % 1048576 === 0 ) {
            return ( $bytes / 1048576 ) . ' MiB';
        }
        return $bytes . ' bytes';
    }

    private static function result( $checks ) {
        $summary = array( 'passed' => 0, 'warnings' => 0, 'failed' => 0 );
        foreach ( $checks as $check ) {
            $result = isset( $check['result'] ) ? (string) $check['result'] : 'FAIL';
            if ( $result === 'PASS' ) {
                $summary['passed']++;
            } elseif ( $result === 'WARN' ) {
                $summary['warnings']++;
            } else {
                $summary['failed']++;
            }
        }

        return array(
            'ok' => $summary['failed'] === 0,
            'exit_code' => $summary['failed'] === 0 ? 0 : 1,
            'summary' => $summary,
            'checks' => $checks,
        );
    }

    private static function check( $name, $result, $observed, $expected, $notes ) {
        return array(
            'name' => $name,
            'result' => $result,
            'observed' => $observed,
            'expected' => $expected,
            'notes' => $notes,
        );
    }
}
