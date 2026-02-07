<?php
/**
 * WP-CLI adapter for eForms garbage collection.
 */

require_once __DIR__ . '/../Gc/GcRunner.php';

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
        $limit = self::positive_int( $assoc_args, 'limit', GcRunner::DEFAULT_BATCH_LIMIT );

        $result = GcRunner::run(
            array(
                'dry_run' => $dry_run,
                'limit' => $limit,
            )
        );

        self::emit_cli_output( $result );
        return $result;
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

    private static function positive_int( $assoc_args, $key, $default ) {
        if ( ! is_array( $assoc_args ) || ! array_key_exists( $key, $assoc_args ) ) {
            return (int) $default;
        }

        $value = $assoc_args[ $key ];
        if ( is_numeric( $value ) ) {
            $value = (int) $value;
            if ( $value > 0 ) {
                return $value;
            }
        }

        return (int) $default;
    }
}
