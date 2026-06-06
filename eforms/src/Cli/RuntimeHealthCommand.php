<?php
/**
 * WP-CLI adapter for the eForms runtime health diagnostic.
 */

require_once __DIR__ . '/../Diagnostics/RuntimeHealthDiagnostic.php';

class RuntimeHealthCommand {
    public static function invoke( $args = array(), $assoc_args = array() ) {
        $result = self::run();
        self::emit_cli_output( $result );

        $exit_code = isset( $result['exit_code'] ) ? (int) $result['exit_code'] : 1;
        if ( $exit_code !== 0 && class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'halt' ) ) {
            WP_CLI::halt( $exit_code );
        }

        return $result;
    }

    public static function run() {
        return RuntimeHealthDiagnostic::run();
    }

    private static function emit_cli_output( $result ) {
        if ( ! class_exists( 'WP_CLI' ) ) {
            return;
        }

        self::cli_call( 'log', 'Check              Result  Observed                       Expected                       Notes' );
        self::cli_call( 'log', '-----------------  ------  -----------------------------  -----------------------------  ------------------------------' );

        foreach ( RuntimeHealthDiagnostic::rows( $result ) as $row ) {
            self::cli_call( 'log', sprintf( '%-17s  %-6s  %-29s  %-29s  %s', $row['name'], $row['result'], $row['observed'], $row['expected'], $row['notes'] ) );
        }

        $summary = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();
        $failed = isset( $summary['failed'] ) ? (int) $summary['failed'] : 0;
        $warnings = isset( $summary['warnings'] ) ? (int) $summary['warnings'] : 0;
        $line = 'eForms doctor: ' . RuntimeHealthDiagnostic::summary_line( $result );

        if ( $failed === 0 && $warnings === 0 && method_exists( 'WP_CLI', 'success' ) ) {
            WP_CLI::success( $line );
            return;
        }

        self::cli_call( 'warning', $line );
    }

    private static function cli_call( $method, $message ) {
        if ( ! class_exists( 'WP_CLI' ) || ! method_exists( 'WP_CLI', $method ) ) {
            return;
        }

        call_user_func( array( 'WP_CLI', $method ), $message );
    }
}
