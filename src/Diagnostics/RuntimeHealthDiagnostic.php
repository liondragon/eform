<?php
/**
 * Shared runtime health diagnostic for operator-facing surfaces.
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Gc/GcRunner.php';
require_once __DIR__ . '/../Logging/JsonlLogger.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Security/Challenge.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Security/Throttle.php';
require_once __DIR__ . '/../Submission/Ledger.php';
require_once __DIR__ . '/../Uploads/PrivateDir.php';

class RuntimeHealthDiagnostic {
    const PROBE_FILENAME = '.eforms-doctor-probe';

    public static function run() {
        $checks = array(
            self::check_uploads_base(),
            self::check_private_storage(),
            self::check_runtime_dirs(),
            self::check_templates(),
            self::check_gc_readiness(),
            self::check_cli_bootstrap(),
            self::check_config_sources(),
            self::check_challenge_config(),
        );

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

    private static function check_private_storage() {
        $private = PrivateDir::ensure( self::uploads_dir() );
        if ( ! is_array( $private ) || empty( $private['ok'] ) ) {
            $reason = is_array( $private ) && isset( $private['error'] ) ? (string) $private['error'] : 'private_dir_unavailable';
            return self::check( 'private-storage', 'FAIL', $reason, 'private dir protected', 'could not create/protect eforms-private' );
        }

        $path = isset( $private['path'] ) ? (string) $private['path'] : '';
        foreach ( array( PrivateDir::INDEX_FILENAME, PrivateDir::HTACCESS_FILENAME, PrivateDir::WEBCONFIG_FILENAME ) as $file ) {
            if ( ! is_file( rtrim( $path, '/\\' ) . '/' . $file ) ) {
                return self::check( 'private-storage', 'FAIL', $file . ' missing', 'private dir protected', 'deny-rule file missing' );
            }
        }

        return self::check( 'private-storage', 'PASS', 'created/protected', 'private dir protected', 'raw path hidden' );
    }

    private static function check_runtime_dirs() {
        $uploads_dir = self::uploads_dir();
        $names = array( Security::TOKENS_DIR, Ledger::LEDGER_DIR, JsonlLogger::LOG_DIR, Throttle::THROTTLE_DIR );
        $failed = array();
        foreach ( $names as $name ) {
            if ( ! self::dir_usable( $uploads_dir, $name ) ) {
                $failed[] = $name;
            }
        }

        if ( ! empty( $failed ) ) {
            return self::check( 'runtime-dirs', 'FAIL', implode( ',', $failed ), 'token/ledger/log/throttle usable', 'one or more runtime dirs failed write/delete probe' );
        }

        return self::check( 'runtime-dirs', 'PASS', 'usable', 'token/ledger/log/throttle usable', 'temporary probes cleaned' );
    }

    private static function check_templates() {
        $dir = dirname( __DIR__, 2 ) . '/templates/forms';
        if ( ! is_dir( $dir ) ) {
            return self::check( 'templates', 'FAIL', 'missing directory', 'all shipped templates valid', 'templates/forms is unavailable' );
        }

        $files = glob( rtrim( $dir, '/\\' ) . '/*.json' );
        if ( ! is_array( $files ) || empty( $files ) ) {
            return self::check( 'templates', 'WARN', 'no json templates', 'all shipped templates valid', 'no shipped form templates found' );
        }

        $invalid = array();
        foreach ( $files as $path ) {
            $form_id = basename( $path, '.json' );
            $loaded = TemplateLoader::load( $form_id, $dir );
            if ( empty( $loaded['ok'] ) ) {
                $invalid[] = $form_id;
            }
        }

        if ( ! empty( $invalid ) ) {
            return self::check( 'templates', 'FAIL', implode( ',', $invalid ), 'all shipped templates valid', 'invalid shipped templates' );
        }

        return self::check( 'templates', 'PASS', count( $files ) . ' valid', 'all shipped templates valid', '' );
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

    private static function dir_usable( $uploads_dir, $name ) {
        $dir = PrivateDir::subdir( $uploads_dir, $name, true );
        if ( $dir === '' ) {
            return false;
        }

        $probe = rtrim( $dir, '/\\' ) . '/' . self::PROBE_FILENAME;
        $written = @file_put_contents( $probe, 'ok', LOCK_EX );
        if ( $written === false ) {
            @unlink( $probe );
            return false;
        }

        $read = @file_get_contents( $probe );
        @unlink( $probe );
        return $read === 'ok' && ! file_exists( $probe );
    }

    private static function uploads_dir() {
        $config = Config::get();
        return Config::value( $config, array( 'uploads', 'dir' ), '' );
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
