<?php
/**
 * WP-CLI adapter for eForms garbage collection.
 */

require_once __DIR__ . '/../Gc/GcRunner.php';
require_once __DIR__ . '/../Uploads/WorkerClient.php';

class GcCommand {
    /**
     * Run `wp eforms gc`.
     *
     * @param array $args
     * @param array $assoc_args
     * @return array
     */
    public static function invoke( $args = array(), $assoc_args = array() ) {
        $assoc_args = is_array( $assoc_args ) ? $assoc_args : array();

        $dry_run = self::flag( $assoc_args, 'dry-run' ) || self::flag( $assoc_args, 'dry_run' );
        $reconcile_capacity = self::flag( $assoc_args, 'reconcile-capacity' ) || self::flag( $assoc_args, 'reconcile_capacity' );
        $begin_retirement_version = self::option_string( $assoc_args, 'begin-validation-retirement', 'begin_validation_retirement' );
        $retirement_version = self::option_string( $assoc_args, 'verify-validation-retirement', 'verify_validation_retirement' );
        $complete_retirement_version = self::option_string( $assoc_args, 'complete-validation-retirement', 'complete_validation_retirement' );
        $retirement_modes = 0;
        $retirement_modes += $begin_retirement_version === '' ? 0 : 1;
        $retirement_modes += $retirement_version === '' ? 0 : 1;
        $retirement_modes += $complete_retirement_version === '' ? 0 : 1;
        if ( $retirement_modes > 1 ) {
            $result = array( 'ok' => false, 'blocked' => false, 'reason' => 'validation_retirement_mode_conflict' );
            self::emit_validation_retirement_output( $result );
            if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'halt' ) ) {
                WP_CLI::halt( 1 );
            }

            return $result;
        }
        if ( $begin_retirement_version !== '' ) {
            $result = self::begin_validation_retirement( $begin_retirement_version );
            self::emit_validation_retirement_output( $result );
            if ( empty( $result['ok'] ) && class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'halt' ) ) {
                WP_CLI::halt( 1 );
            }

            return $result;
        }
        if ( $retirement_version !== '' ) {
            $result = self::verify_validation_retirement( $retirement_version );
            self::emit_validation_retirement_output( $result );
            if ( ( empty( $result['ok'] ) || ! empty( $result['blocked'] ) ) && class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'halt' ) ) {
                WP_CLI::halt( 1 );
            }

            return $result;
        }
        if ( $complete_retirement_version !== '' ) {
            $result = self::complete_validation_retirement( $complete_retirement_version, $assoc_args );
            self::emit_validation_retirement_output( $result );
            if ( empty( $result['ok'] ) && class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'halt' ) ) {
                WP_CLI::halt( 1 );
            }

            return $result;
        }
        $options = array(
            'dry_run' => $dry_run,
            'reconcile_capacity' => $reconcile_capacity,
        );
        if ( array_key_exists( 'limit', $assoc_args ) ) {
            $options['limit'] = $assoc_args['limit'];
        }

        $result = GcRunner::run( $options );

        self::emit_cli_output( $result );
        if ( empty( $result['ok'] ) && empty( $result['locked'] ) && class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'halt' ) ) {
            WP_CLI::halt( 1 );
        }

        return $result;
    }

    private static function verify_validation_retirement( $validation_contract_version ) {
        $uploads_dir = self::configured_uploads_dir();
        if ( $uploads_dir === '' ) {
            return array(
                'ok' => false,
                'blocked' => false,
                'reason' => 'configuration_unavailable',
                'validation_contract_version' => $validation_contract_version,
            );
        }
        return UploadBatchStore::validation_contract_retirement_status( $uploads_dir, $validation_contract_version );
    }

    private static function begin_validation_retirement( $validation_contract_version ) {
        $uploads_dir = self::configured_uploads_dir();
        if ( $uploads_dir === '' ) {
            return array(
                'ok' => false,
                'blocked' => false,
                'reason' => 'configuration_unavailable',
                'validation_contract_version' => $validation_contract_version,
            );
        }
        $result = UploadBatchStore::begin_validation_contract_retirement( $uploads_dir, $validation_contract_version );
        if ( is_array( $result ) && ! isset( $result['blocked'] ) ) {
            $result['blocked'] = false;
        }
        return $result;
    }

    private static function complete_validation_retirement( $validation_contract_version, $assoc_args ) {
        $uploads_dir = self::configured_uploads_dir();
        if ( $uploads_dir === '' ) {
            return array(
                'ok' => false,
                'blocked' => false,
                'reason' => 'configuration_unavailable',
                'validation_contract_version' => $validation_contract_version,
            );
        }
        if ( ! class_exists( 'WorkerProtocol' ) || ! WorkerProtocol::valid_validation_contract_version( $validation_contract_version ) ) {
            return array(
                'ok' => false,
                'blocked' => false,
                'reason' => 'validation_contract_version_invalid',
                'validation_contract_version' => $validation_contract_version,
            );
        }
        if ( hash_equals( WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION, $validation_contract_version ) ) {
            return array(
                'ok' => false,
                'blocked' => false,
                'reason' => 'wordpress_validation_contract_not_switched',
                'validation_contract_version' => $validation_contract_version,
            );
        }
        $health = self::worker_health( $assoc_args );
        if ( empty( $health['ok'] )
            || empty( $health['worker_ready'] )
            || empty( $health['validation_contract_ready'] )
            || ! isset( $health['validation_contract_version'] )
            || ! hash_equals( WorkerProtocol::WORKER_VALIDATION_CONTRACT_VERSION, (string) $health['validation_contract_version'] )
        ) {
            return array(
                'ok' => false,
                'blocked' => false,
                'reason' => 'worker_validation_contract_unconfirmed',
                'validation_contract_version' => $validation_contract_version,
            );
        }
        $result = UploadBatchStore::complete_validation_contract_retirement( $uploads_dir, $validation_contract_version );
        if ( is_array( $result ) && ! isset( $result['blocked'] ) ) {
            $result['blocked'] = false;
        }
        return $result;
    }

    private static function configured_uploads_dir() {
        if ( ! class_exists( 'Config' ) ) {
            return '';
        }
        $config = Config::get();
        return isset( $config['uploads']['dir'] ) && is_string( $config['uploads']['dir'] )
            ? $config['uploads']['dir']
            : '';
    }

    private static function worker_health( $assoc_args ) {
        if ( is_array( $assoc_args ) && isset( $assoc_args['_worker_health_result'] ) && is_array( $assoc_args['_worker_health_result'] ) ) {
            return $assoc_args['_worker_health_result'];
        }
        return WorkerClient::health( null, null, 'validation_retirement_complete' );
    }

    private static function emit_cli_output( $result ) {
        if ( ! class_exists( 'WP_CLI' ) ) {
            return;
        }

        $dry_run = ! empty( $result['dry_run'] );
        $prefix = $dry_run ? 'eForms GC dry-run' : 'eForms GC';

        if ( empty( $result['ok'] ) ) {
            if ( ! empty( $result['locked'] ) ) {
                self::cli_call( 'warning', $prefix . ' skipped: another run is in progress.' );
            } else {
                $reason = isset( $result['reason'] ) ? (string) $result['reason'] : 'unknown';
                self::cli_call( 'warning', $prefix . ' failed: ' . $reason );
            }
            return;
        }

        $summary = sprintf(
            '%s scanned=%d candidates=%d deleted=%d candidate_bytes=%d deleted_bytes=%d%s',
            $prefix,
            isset( $result['scanned'] ) ? (int) $result['scanned'] : 0,
            isset( $result['candidates'] ) ? (int) $result['candidates'] : 0,
            isset( $result['deleted'] ) ? (int) $result['deleted'] : 0,
            isset( $result['candidate_bytes'] ) ? (int) $result['candidate_bytes'] : 0,
            isset( $result['deleted_bytes'] ) ? (int) $result['deleted_bytes'] : 0,
            ! empty( $result['reached_limit'] ) ? ' (batch limit reached)' : ''
        );

        if ( method_exists( 'WP_CLI', 'success' ) ) {
            WP_CLI::success( $summary );
            return;
        }

        self::cli_call( 'log', $summary );
    }

    private static function emit_validation_retirement_output( $result ) {
        if ( ! class_exists( 'WP_CLI' ) ) {
            return;
        }

        $version = isset( $result['validation_contract_version'] ) ? (string) $result['validation_contract_version'] : '';
        $scanned = isset( $result['scanned'] ) ? (int) $result['scanned'] : 0;
        $references = isset( $result['references'] ) ? (int) $result['references'] : 0;
        $accepted = isset( $result['accepted_artifacts'] ) ? (int) $result['accepted_artifacts'] : 0;
        $pending = isset( $result['pending'] ) ? (int) $result['pending'] : 0;
        $tombstones = isset( $result['tombstones'] ) ? (int) $result['tombstones'] : 0;

        if ( ! empty( $result['ok'] ) && empty( $result['blocked'] ) && isset( $result['state'] ) && $result['state'] === 'active' ) {
            $message = sprintf( 'Validation contract retirement begun: version=%s', $version );
            if ( method_exists( 'WP_CLI', 'success' ) ) {
                WP_CLI::success( $message );
                return;
            }
            self::cli_call( 'log', $message );
            return;
        }

        if ( ! empty( $result['ok'] ) && empty( $result['blocked'] ) && isset( $result['state'] ) && $result['state'] === 'complete' ) {
            $message = sprintf( 'Validation contract retirement complete: version=%s', $version );
            if ( method_exists( 'WP_CLI', 'success' ) ) {
                WP_CLI::success( $message );
                return;
            }
            self::cli_call( 'log', $message );
            return;
        }

        if ( ! empty( $result['ok'] ) && empty( $result['blocked'] ) ) {
            $message = sprintf(
                'Validation contract retirement ready: version=%s scanned=%d references=0',
                $version,
                $scanned
            );
            if ( method_exists( 'WP_CLI', 'success' ) ) {
                WP_CLI::success( $message );
                return;
            }
            self::cli_call( 'log', $message );
            return;
        }

        if ( ! empty( $result['blocked'] ) ) {
            self::cli_call(
                'warning',
                sprintf(
                    'Validation contract retirement blocked: version=%s scanned=%d references=%d accepted=%d pending=%d tombstones=%d',
                    $version,
                    $scanned,
                    $references,
                    $accepted,
                    $pending,
                    $tombstones
                )
            );
            return;
        }

        $reason = isset( $result['reason'] ) ? (string) $result['reason'] : 'unknown';
        self::cli_call( 'warning', 'Validation contract retirement check failed: ' . $reason );
    }

    private static function cli_call( $method, $message ) {
        if ( ! class_exists( 'WP_CLI' ) || ! method_exists( 'WP_CLI', $method ) ) {
            return;
        }

        call_user_func( array( 'WP_CLI', $method ), $message );
    }

    private static function flag( $assoc_args, $key ) {
        if ( ! is_array( $assoc_args ) || ! array_key_exists( $key, $assoc_args ) ) {
            return false;
        }

        $value = $assoc_args[ $key ];
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value !== 0;
        }
        if ( is_string( $value ) ) {
            $value = strtolower( trim( $value ) );
            if ( $value === '' ) {
                return true;
            }

            return ! in_array( $value, array( '0', 'false', 'no', 'off' ), true );
        }

        return true;
    }

    private static function option_string( $assoc_args, $primary, $alternate ) {
        if ( is_array( $assoc_args ) ) {
            foreach ( array( $primary, $alternate ) as $key ) {
                if ( isset( $assoc_args[ $key ] ) && is_scalar( $assoc_args[ $key ] ) ) {
                    return trim( (string) $assoc_args[ $key ] );
                }
            }
        }
        return '';
    }

}
