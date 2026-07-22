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
require_once __DIR__ . '/../Security/Entropy.php';
require_once __DIR__ . '/../Security/PostSize.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Security/Throttle.php';
require_once __DIR__ . '/../Submission/Ledger.php';
require_once __DIR__ . '/../Uploads/UploadBatchStore.php';
require_once __DIR__ . '/../Uploads/UploadPolicy.php';
require_once __DIR__ . '/../Uploads/LocalPreviewProvider.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';
require_once __DIR__ . '/../Uploads/WorkerClient.php';

class RuntimeHealthDiagnostic {
    const PROBE_FILENAME = '.eforms-doctor-probe';

    public static function run( $observations = array() ) {
        $observations = is_array( $observations ) ? $observations : array();
        $templates = self::template_inventory();
        $staged_fields = $templates['staged_fields'];
        $composition = WorkerClient::composition();
        $uploads_base = self::check_uploads_base();
        $private_lease = $uploads_base['result'] === 'PASS'
            ? PrivateDir::acquire_write_lease( self::uploads_dir() )
            : false;
        $capacity_health = $private_lease instanceof PrivateDirLease
            ? UploadBatchStore::capacity_health( self::uploads_dir(), $private_lease )
            : array( 'ok' => false, 'reason' => 'upload_lifecycle_unavailable' );
        $artifact_stores = self::required_artifact_stores( $composition, $capacity_health );
        $artifact_store_identities = self::retained_artifact_store_identities( $capacity_health );
        $checks = array(
            $uploads_base,
            self::check_private_storage( $private_lease ),
            self::check_runtime_dirs( $private_lease ),
            self::check_staged_artifact_readiness( $observations, $composition, $artifact_stores, $artifact_store_identities ),
            self::check_review_preview_readiness( $observations ),
            self::check_managed_capacity( $observations, $capacity_health, $artifact_stores ),
            self::check_managed_upload_dirs( $private_lease, $artifact_stores ),
            self::check_staged_request_limits( $observations, $staged_fields, $artifact_stores ),
            self::check_staged_throttle( $staged_fields ),
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

    private static function check_managed_upload_dirs( $private_lease, $artifact_stores ) {
        $requires_local = is_array( $artifact_stores ) && in_array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, $artifact_stores, true );
        $expected = $requires_local
            ? 'aggregate/artifact storage protected and writable'
            : 'aggregate storage protected and writable';
        if ( ! $private_lease instanceof PrivateDirLease ) {
            return self::check( 'managed-upload-dirs', 'FAIL', self::private_storage_unavailable_reason(), $expected, 'private lifecycle lease unavailable' );
        }

        $failed = array();
        $names = array( UploadBatchStore::STAGED_DIR, UploadBatchStore::SUBMISSIONS_DIR );
        if ( $requires_local ) {
            $names[] = UploadBatchStore::ARTIFACTS_DIR;
        }
        foreach ( $names as $name ) {
            if ( ! self::dir_usable( $private_lease, $name, true ) ) {
                $failed[] = $name;
            }
        }
        if ( ! empty( $failed ) ) {
            return self::check( 'managed-upload-dirs', 'FAIL', implode( ',', $failed ), $expected, 'one or more managed dirs failed write/delete probe' );
        }
        return self::check( 'managed-upload-dirs', 'PASS', 'protected and writable', $expected, 'private deny rules active; temporary probes cleaned' );
    }

    private static function check_staged_artifact_readiness( $observations, $composition, $artifact_stores, $artifact_store_identities ) {
        if ( $composition === null ) {
            return self::check( 'staged-artifact-readiness', 'FAIL', 'composition unavailable', 'one valid upload composition', 'invalid explicit deployment wiring fails closed' );
        }
        if ( ! is_array( $artifact_stores ) ) {
            return self::check( 'staged-artifact-readiness', 'FAIL', 'retained ownership unavailable', 'new and retained artifact stores ready', 'repair managed-capacity or aggregate state before rollout' );
        }
        if ( ! is_array( $artifact_store_identities ) ) {
            return self::check( 'staged-artifact-readiness', 'FAIL', 'retained identity unavailable', 'new and retained artifact stores ready', 'repair managed-capacity or aggregate state before rollout' );
        }
        $requires_worker = in_array( FormProtocol::UPLOAD_TRANSPORT_WORKER, $artifact_stores, true );
        $requires_local = in_array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, $artifact_stores, true );
        if ( $requires_worker ) {
            $current_identity = WorkerClient::composition_fingerprint();
            foreach ( $artifact_store_identities as $identity ) {
                if ( $identity === UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY ) {
                    continue;
                }
                if ( $current_identity === '' || ! hash_equals( $identity, $current_identity ) ) {
                    return self::check( 'staged-artifact-readiness', 'FAIL', 'retained Worker identity mismatch', 'all new and retained artifact stores ready', 'restore the retained Worker origin/environment or drain it before rollout' );
                }
            }
            $worker = isset( $observations['worker_health'] ) && is_array( $observations['worker_health'] )
                ? $observations['worker_health']
                : WorkerClient::health(
                    isset( $observations['worker_now'] ) ? $observations['worker_now'] : null,
                    isset( $observations['worker_requester'] ) ? $observations['worker_requester'] : null,
                    'runtime_readiness'
                );
            if ( empty( $worker['ok'] ) ) {
                $outcome = isset( $worker['outcome'] ) && is_string( $worker['outcome'] ) ? $worker['outcome'] : 'unavailable';
                return self::check( 'staged-artifact-readiness', 'FAIL', $outcome, 'all new and retained artifact stores ready', 'repair deployment wiring or Worker dependencies; no local fallback' );
            }
        }
        if ( $requires_local ) {
            $fileinfo = array_key_exists( 'fileinfo', $observations )
                ? (bool) $observations['fileinfo']
                : extension_loaded( 'fileinfo' ) && function_exists( 'finfo_open' );
            if ( ! $fileinfo ) {
                return self::check( 'staged-artifact-readiness', 'FAIL', 'fileinfo unavailable', 'all new and retained artifact stores ready', 'enable the PHP fileinfo extension' );
            }
            $inspection = array_key_exists( 'image_inspection', $observations )
                ? (bool) $observations['image_inspection']
                : function_exists( 'getimagesize' );
            if ( ! $inspection ) {
                return self::check( 'staged-artifact-readiness', 'FAIL', 'image inspection unavailable', 'all new and retained artifact stores ready', 'enable PHP image-header inspection support' );
            }
        }
        $observed = $requires_worker && $requires_local
            ? 'local and remote data planes ready'
            : ( $requires_worker ? 'remote data plane ready' : 'fileinfo and bounded inspection ready' );
        $notes = $requires_worker
            ? 'non-customer fixture; lifecycle configuration is operator-verified separately'
            : 'Optional preview encoding is not an upload prerequisite';
        return self::check( 'staged-artifact-readiness', 'PASS', $observed, 'all new and retained artifact stores ready', $notes );
    }

    private static function check_review_preview_readiness( $observations ) {
        $provider = WorkerClient::review_provider();
        if ( $provider === 'none' ) {
            return self::check( 'review-preview-readiness', 'PASS', 'disabled', 'optional provider disabled or ready', 'submitted-image download remains available' );
        }
        if ( $provider === 'worker' ) {
            return self::check( 'review-preview-readiness', 'PASS', 'Cloudflare provider bound', 'optional provider disabled or ready', 'signed Worker health and genuine-provider preview tests carry dependency proof' );
        }
        if ( $provider !== 'local' ) {
            return self::check( 'review-preview-readiness', 'WARN', 'provider configuration invalid', 'optional provider disabled or ready', 'artifact upload remains available; repair the optional preview composition' );
        }
        $concurrency = WorkerClient::local_preview_concurrency();
        $readiness = LocalPreviewProvider::readiness(
            $concurrency,
            array_key_exists( 'local_preview_imagick', $observations )
                ? array( 'imagick' => (bool) $observations['local_preview_imagick'] )
                : array()
        );
        if ( ! empty( $readiness['ok'] ) ) {
            return self::check( 'review-preview-readiness', 'PASS', 'local provider ready at concurrency ' . $concurrency, 'optional provider disabled or ready', 'preview work is presentation-only and globally bounded' );
        }
        return self::check( 'review-preview-readiness', 'WARN', isset( $readiness['reason'] ) ? $readiness['reason'] : 'provider unavailable', 'optional provider disabled or ready', 'artifact upload remains available; submitted-image download is the fallback' );
    }

    private static function check_managed_capacity( $observations, $health, $artifact_stores ) {
        $integer_bytes = array_key_exists( 'php_int_size', $observations ) ? $observations['php_int_size'] : PHP_INT_SIZE;
        if ( ! UploadBatchStore::capacity_platform_supported( $integer_bytes ) ) {
            return self::check( 'managed-capacity', 'FAIL', '32-bit PHP integers', '64-bit PHP integers with consistent accounting and provisioned storage', 'managed upload capacity cannot represent its fixed 50 GiB ceiling on this runtime' );
        }
        $remote = is_array( $artifact_stores ) && in_array( FormProtocol::UPLOAD_TRANSPORT_WORKER, $artifact_stores, true );
        $local = is_array( $artifact_stores ) && in_array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, $artifact_stores, true );
        if ( empty( $health['ok'] ) ) {
            $reason = isset( $health['reason'] ) ? (string) $health['reason'] : 'capacity_unavailable';
            return self::check( 'managed-capacity', 'FAIL', $reason, 'accounting consistent and filesystem provisioned', 'capacity record could not be read safely' );
        }
        $capacity = $health['capacity'];
        if ( empty( $capacity['consistent'] ) ) {
            $notes = $remote
                ? 'restore or repair manifest/capacity authority before accepting more remote uploads'
                : 'investigate interrupted writes, then run wp eforms gc --reconcile-capacity';
            return self::check( 'managed-capacity', 'FAIL', 'accounting inconsistent', 'accounting consistent and storage provisioned', $notes );
        }
        if ( $remote && ! $local ) {
            if ( (int) $capacity['committing_bytes'] > 0 ) {
                return self::check( 'managed-capacity', 'WARN', 'unsettled remote reservation detected', 'manifest and capacity authority settled', 'retry exact completion before activation or restore sign-off' );
            }
            return self::check( 'managed-capacity', 'PASS', self::format_bytes( $capacity['total_bytes'] ) . ' manifest-accounted', 'manifest and capacity authority consistent', 'physical R2 readiness is verified by the signed Worker health operation' );
        }
        $uploads_dir = self::uploads_dir();
        $total = array_key_exists( 'disk_total_bytes', $observations ) ? $observations['disk_total_bytes'] : @disk_total_space( $uploads_dir );
        $free = array_key_exists( 'disk_free_bytes', $observations ) ? $observations['disk_free_bytes'] : @disk_free_space( $uploads_dir );
        $required_total = Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ) + Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' );
        if ( ! is_numeric( $total ) || ! is_numeric( $free ) ) {
            return self::check( 'managed-capacity', 'FAIL', 'filesystem capacity unavailable', 'accounting consistent and filesystem provisioned', 'disk total/free-space observations are required' );
        }
        if ( (int) $total < $required_total || (int) $free < Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) ) {
            $provision = 'provision ' . self::format_bytes( Anchors::get( 'MANAGED_OBJECT_MAX_BYTES' ) )
                . ' managed capacity plus ' . self::format_bytes( Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) ) . ' operational free-space reserve';
            return self::check( 'managed-capacity', 'FAIL', 'filesystem below ceiling + reserve', 'accounting consistent and filesystem provisioned', $provision );
        }
        if ( (int) $capacity['committing_bytes'] > 0 || (int) $capacity['orphaned_bytes'] > 0 ) {
            return self::check( 'managed-capacity', 'WARN', 'unsettled reservations detected', 'accounting consistent and filesystem provisioned', 'run wp eforms gc --reconcile-capacity to settle committed or orphaned reservations' );
        }
        return self::check( 'managed-capacity', 'PASS', self::format_bytes( $capacity['total_bytes'] ) . ' accounted; filesystem ready', 'accounting consistent and filesystem provisioned', 'runtime reservations also preserve the ' . self::format_bytes( Anchors::get( 'MANAGED_UPLOAD_MIN_FREE_BYTES' ) ) . ' free-space reserve' );
    }

    private static function check_staged_request_limits( $observations, $staged_fields, $artifact_stores ) {
        if ( is_array( $artifact_stores ) && ! in_array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, $artifact_stores, true ) ) {
            return self::check( 'staged-request-limits', 'PASS', 'artifact body bypasses PHP', 'WordPress receives bounded intent and receipt payloads only', 'verify edge and CDN request limits in the genuine-provider lane' );
        }
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

    private static function check_staged_throttle( $staged_fields ) {
        $config = Config::get();
        $enabled = Config::bool( $config, array( 'throttle', 'enable' ), false );
        $limit = Config::value( $config, array( 'throttle', 'per_ip', 'max_per_minute' ), 0 );
        if ( ! $enabled || ! is_numeric( $limit ) || (int) $limit < 1 ) {
            return self::check( 'staged-throttle', 'FAIL', 'disabled', 'per-IP throttle enabled for staged endpoints', 'image decoding is intentionally unavailable for production until throttling is enabled' );
        }
        $required = self::required_staged_throttle_requests( $staged_fields );
        if ( $required > 0 && (int) $limit < $required ) {
            return self::check(
                'staged-throttle',
                'FAIL',
                'enabled at ' . (int) $limit . '/minute; largest staged field needs ' . $required,
                'per-IP throttle covers one complete staged batch',
                'raise max_per_minute to at least ' . $required . ' and add headroom for retries or shared-IP traffic'
            );
        }
        return self::check( 'staged-throttle', 'PASS', 'enabled at ' . (int) $limit . '/minute', 'per-IP throttle covers one complete staged batch', 'add headroom for retries and shared-IP traffic' );
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
        $config = Config::get();
        $preparation_mode = Config::value( $config, array( 'media', 'client_preparation' ), Config::CLIENT_PREPARATION_OFF );
        $recipe_version = Anchors::get( 'CLIENT_PREPARATION_RECIPE_VERSION' );
        return self::check(
            'config-sources',
            'PASS',
            'provenance available; client preparation ' . $preparation_mode . ' recipe v' . $recipe_version,
            'effective config provenance and browser preparation recipe available',
            'uploads.dir source=' . $source
        );
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

        $suffix = Entropy::hex( Anchors::get( 'RUNTIME_HEALTH_PROBE_ENTROPY_BYTES' ) );
        if ( $suffix === '' ) {
            return false;
        }
        $probe = rtrim( $dir, '/\\' ) . '/' . self::PROBE_FILENAME . '-' . $suffix;
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
            $limits = UploadPolicy::effective_staged_limits( $field );
            if ( isset( $limits['max_file_bytes'] ) && is_int( $limits['max_file_bytes'] ) ) {
                $largest = max( $largest, $limits['max_file_bytes'] );
            }
        }
        return $largest;
    }

    private static function required_staged_throttle_requests( $staged_fields ) {
        $largest = 0;
        foreach ( $staged_fields as $field ) {
            $limits = UploadPolicy::effective_staged_limits( $field );
            if ( isset( $limits['max_files'] ) && is_int( $limits['max_files'] ) ) {
                $largest = max( $largest, $limits['max_files'] );
            }
        }
        if ( $largest < 1 ) {
            return 0;
        }
        $per_batch = Anchors::get( 'STAGED_THROTTLED_REQUESTS_PER_BATCH' );
        $per_item = Anchors::get( 'STAGED_THROTTLED_REQUESTS_PER_ITEM' );
        return $per_batch + ( $per_item * $largest );
    }

    private static function required_artifact_stores( $composition, $capacity_health ) {
        if ( ! in_array( $composition, array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ), true )
            || empty( $capacity_health['ok'] )
            || ! isset( $capacity_health['capacity']['artifact_stores'] )
            || ! is_array( $capacity_health['capacity']['artifact_stores'] )
        ) {
            return null;
        }
        $stores = array( $composition => true );
        foreach ( $capacity_health['capacity']['artifact_stores'] as $store ) {
            if ( ! in_array( $store, array( FormProtocol::UPLOAD_TRANSPORT_LOCAL, FormProtocol::UPLOAD_TRANSPORT_WORKER ), true ) ) {
                return null;
            }
            $stores[ $store ] = true;
        }
        return array_keys( $stores );
    }

    private static function retained_artifact_store_identities( $capacity_health ) {
        if ( empty( $capacity_health['ok'] )
            || ! isset( $capacity_health['capacity']['artifact_store_identities'] )
            || ! is_array( $capacity_health['capacity']['artifact_store_identities'] )
        ) {
            return null;
        }
        $identities = array();
        foreach ( $capacity_health['capacity']['artifact_store_identities'] as $identity ) {
            if ( ! is_string( $identity )
                || ( $identity !== UploadBatchStore::LOCAL_ARTIFACT_STORE_IDENTITY
                    && preg_match( '/^[a-f0-9]{64}$/D', $identity ) !== 1 )
            ) {
                return null;
            }
            $identities[ $identity ] = true;
        }
        return array_keys( $identities );
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
