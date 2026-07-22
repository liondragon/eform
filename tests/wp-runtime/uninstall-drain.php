<?php
/**
 * Prove resumable uninstall-drain behavior against a disposable WordPress.
 *
 * The fixture models the future eForms uninstall adapter: it persists one
 * barrier record, aborts deletion while draining or when its provider fails,
 * and returns only after the purge owner reports ready.
 */

const EFORMS_UD_PLUGIN_SLUG = 'eforms-uninstall-drain-proof';
const EFORMS_UD_PLUGIN_FILE = 'eforms-uninstall-drain-proof/eforms-uninstall-drain-proof.php';
const EFORMS_UD_COMPANION_SLUG = 'eforms-uninstall-companion';
const EFORMS_UD_COMPANION_FILE = 'eforms-uninstall-companion/eforms-uninstall-companion.php';
const EFORMS_UD_OPTION = 'eforms_uninstall_drain_proof';
const EFORMS_UD_SENTINEL = '.eforms-uninstall-drain-disposable';

$wp_path = getenv( 'EFORMS_WP_PATH' );
$wp_cli = getenv( 'EFORMS_WP_CLI' );
$wp_cli = is_string( $wp_cli ) && $wp_cli !== '' ? $wp_cli : 'wp';

try {
    $context = eforms_ud_validate_environment( $wp_path, $wp_cli );
    eforms_ud_run_proof( $context );
    fwrite(
        STDOUT,
        "WordPress uninstall-drain proof passed.\n" .
        "Selected adapter: normal two-attempt WordPress deletion.\n" .
        "Bulk behavior: the AJAX queue continues; the server fallback stops at eForms; neither is all-or-nothing.\n"
    );
} catch ( Throwable $exception ) {
    fwrite( STDERR, 'WordPress uninstall-drain proof failed: ' . $exception->getMessage() . "\n" );
    exit( 1 );
}

/**
 * @return array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int}
 */
function eforms_ud_validate_environment( $wp_path, $wp_cli ) {
    if ( ! is_string( $wp_path ) || $wp_path === '' ) {
        throw new RuntimeException( 'Set EFORMS_WP_PATH to a disposable WordPress installation.' );
    }

    $resolved = realpath( $wp_path );
    if ( $resolved === false || ! is_file( $resolved . '/wp-load.php' ) ) {
        throw new RuntimeException( 'EFORMS_WP_PATH is not a WordPress installation.' );
    }
    if ( ! is_file( $resolved . '/' . EFORMS_UD_SENTINEL ) ) {
        throw new RuntimeException(
            'Refusing to run without the disposable-install sentinel: ' . $resolved . '/' . EFORMS_UD_SENTINEL
        );
    }

    $context = array(
        'wp_path' => $resolved,
        'wp_cli' => $wp_cli,
        'plugin_dir' => '',
        'admin_id' => 0,
    );

    $installed = eforms_ud_wp( $context, array( 'core', 'is-installed' ) );
    eforms_ud_expect_status( $installed, 0, 'WordPress must be installed before the proof runs.' );

    $environment = eforms_ud_wp( $context, array( 'eval', 'echo wp_get_environment_type();' ) );
    eforms_ud_expect_status( $environment, 0, 'Unable to read the WordPress environment type.' );
    $environment_type = trim( $environment['stdout'] );
    if ( ! in_array( $environment_type, array( 'local', 'development' ), true ) ) {
        throw new RuntimeException(
            'Refusing to run outside a local/development WordPress environment; got ' . $environment_type . '.'
        );
    }

    $plugin_dir_result = eforms_ud_wp( $context, array( 'eval', 'echo WP_PLUGIN_DIR;' ) );
    eforms_ud_expect_status( $plugin_dir_result, 0, 'Unable to resolve WP_PLUGIN_DIR.' );
    $plugin_dir = realpath( trim( $plugin_dir_result['stdout'] ) );
    if ( $plugin_dir === false || ! is_dir( $plugin_dir ) ) {
        throw new RuntimeException( 'WP_PLUGIN_DIR does not exist.' );
    }
    if ( ! eforms_ud_path_is_within( $plugin_dir, $resolved ) ) {
        throw new RuntimeException( 'WP_PLUGIN_DIR must be inside the disposable WordPress path.' );
    }
    $context['plugin_dir'] = $plugin_dir;

    $admin = eforms_ud_wp(
        $context,
        array( 'user', 'list', '--role=administrator', '--field=ID', '--number=1' )
    );
    eforms_ud_expect_status( $admin, 0, 'Unable to locate a WordPress administrator.' );
    $context['admin_id'] = (int) trim( $admin['stdout'] );
    if ( $context['admin_id'] < 1 ) {
        throw new RuntimeException( 'The disposable WordPress installation needs an administrator.' );
    }

    return $context;
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_run_proof( $context ) {
    $server = eforms_ud_start_http_server( $context );
    $context['http_base'] = $server['base'];

    try {
        eforms_ud_ajax_retry_proof( $context );
        eforms_ud_bulk_order_proof( $context, true );
        eforms_ud_bulk_order_proof( $context, false );
        eforms_ud_server_bulk_order_proof( $context, true );
        eforms_ud_server_bulk_order_proof( $context, false );
        eforms_ud_rest_retry_proof( $context );
        eforms_ud_cli_retry_proof( $context, false );
        eforms_ud_cli_retry_proof( $context, true );

        eforms_ud_wp( $context, array( 'option', 'delete', EFORMS_UD_OPTION ) );
    } finally {
        eforms_ud_stop_http_server( $server );
    }
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_ajax_retry_proof( $context ) {
    $token = 'ajax-resume';
    eforms_ud_prepare_fixture( $context, 'draining', $token );

    $first = eforms_ud_ajax_delete( $context, EFORMS_UD_PLUGIN_FILE, EFORMS_UD_PLUGIN_SLUG );
    eforms_ud_assert_blocked( $context, $first, $token, 1, 'wp-admin AJAX initial drain' );

    $early_retry = eforms_ud_ajax_delete( $context, EFORMS_UD_PLUGIN_FILE, EFORMS_UD_PLUGIN_SLUG );
    eforms_ud_assert_blocked( $context, $early_retry, $token, 2, 'wp-admin AJAX early retry' );

    eforms_ud_set_phase( $context, 'provider_failure' );
    $provider_failure = eforms_ud_ajax_delete( $context, EFORMS_UD_PLUGIN_FILE, EFORMS_UD_PLUGIN_SLUG );
    eforms_ud_assert_blocked( $context, $provider_failure, $token, 3, 'wp-admin AJAX provider failure' );

    eforms_ud_set_phase( $context, 'ready' );
    $ready = eforms_ud_ajax_delete( $context, EFORMS_UD_PLUGIN_FILE, EFORMS_UD_PLUGIN_SLUG );
    eforms_ud_assert_deleted( $context, $ready, 'wp-admin AJAX ready retry' );
    eforms_ud_expect_contains( $ready['output'], '"success":true', 'AJAX deletion did not return success JSON.' );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_bulk_order_proof( $context, $eforms_first ) {
    $label = $eforms_first ? 'eForms first' : 'eForms second';
    $token = $eforms_first ? 'bulk-first' : 'bulk-second';
    eforms_ud_prepare_fixture( $context, 'draining', $token );
    eforms_ud_write_companion( $context );

    $ordered = $eforms_first
        ? array(
            array( EFORMS_UD_PLUGIN_FILE, EFORMS_UD_PLUGIN_SLUG, true ),
            array( EFORMS_UD_COMPANION_FILE, EFORMS_UD_COMPANION_SLUG, false ),
        )
        : array(
            array( EFORMS_UD_COMPANION_FILE, EFORMS_UD_COMPANION_SLUG, false ),
            array( EFORMS_UD_PLUGIN_FILE, EFORMS_UD_PLUGIN_SLUG, true ),
        );

    foreach ( $ordered as $item ) {
        $result = eforms_ud_ajax_delete( $context, $item[0], $item[1] );
        if ( $item[2] ) {
            eforms_ud_assert_blocked( $context, $result, $token, 1, 'wp-admin bulk queue (' . $label . ')' );
        } else {
            eforms_ud_expect_status( $result, 0, 'Companion deletion failed in bulk queue (' . $label . ').' );
            eforms_ud_expect_contains(
                $result['output'],
                '"success":true',
                'Companion deletion did not return success JSON in bulk queue (' . $label . ').'
            );
            if ( is_dir( $context['plugin_dir'] . '/' . EFORMS_UD_COMPANION_SLUG ) ) {
                throw new RuntimeException( 'Companion files remained after bulk queue deletion (' . $label . ').' );
            }
        }
    }

    eforms_ud_set_phase( $context, 'ready' );
    $cleanup = eforms_ud_ajax_delete( $context, EFORMS_UD_PLUGIN_FILE, EFORMS_UD_PLUGIN_SLUG );
    eforms_ud_assert_deleted( $context, $cleanup, 'wp-admin bulk queue cleanup (' . $label . ')' );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_server_bulk_order_proof( $context, $eforms_first ) {
    $label = $eforms_first ? 'eForms first' : 'eForms second';
    $token = $eforms_first ? 'server-bulk-first' : 'server-bulk-second';
    eforms_ud_prepare_fixture( $context, 'draining', $token );
    eforms_ud_write_companion( $context );

    $plugins = $eforms_first
        ? array( EFORMS_UD_PLUGIN_FILE, EFORMS_UD_COMPANION_FILE )
        : array( EFORMS_UD_COMPANION_FILE, EFORMS_UD_PLUGIN_FILE );
    $blocked = eforms_ud_admin_bulk_delete( $context, $plugins );
    eforms_ud_assert_blocked( $context, $blocked, $token, 1, 'wp-admin server bulk (' . $label . ')' );

    $companion_exists = is_dir( $context['plugin_dir'] . '/' . EFORMS_UD_COMPANION_SLUG );
    if ( $companion_exists !== $eforms_first ) {
        throw new RuntimeException( 'wp-admin server bulk did not preserve sequential ordering (' . $label . ').' );
    }

    eforms_ud_set_phase( $context, 'ready' );
    $cleanup = eforms_ud_ajax_delete( $context, EFORMS_UD_PLUGIN_FILE, EFORMS_UD_PLUGIN_SLUG );
    eforms_ud_assert_deleted( $context, $cleanup, 'wp-admin server bulk cleanup (' . $label . ')' );
    if ( $companion_exists ) {
        $companion_cleanup = eforms_ud_ajax_delete(
            $context,
            EFORMS_UD_COMPANION_FILE,
            EFORMS_UD_COMPANION_SLUG
        );
        eforms_ud_expect_status( $companion_cleanup, 0, 'Companion cleanup failed after server bulk proof.' );
    }
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_rest_retry_proof( $context ) {
    $token = 'rest-resume';
    eforms_ud_prepare_fixture( $context, 'draining', $token );

    $blocked = eforms_ud_rest_delete( $context );
    eforms_ud_assert_blocked( $context, $blocked, $token, 1, 'REST deletion' );

    eforms_ud_set_phase( $context, 'ready' );
    $ready = eforms_ud_rest_delete( $context );
    eforms_ud_assert_deleted( $context, $ready, 'REST ready retry' );
    eforms_ud_expect_contains( $ready['output'], '"deleted":true', 'REST deletion did not report deleted=true.' );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_cli_retry_proof( $context, $skip_delete ) {
    $suffix = $skip_delete ? ' --skip-delete' : '';
    $token = $skip_delete ? 'cli-skip-resume' : 'cli-resume';
    eforms_ud_prepare_fixture( $context, 'draining', $token );

    $arguments = array( 'plugin', 'uninstall', EFORMS_UD_PLUGIN_SLUG );
    if ( $skip_delete ) {
        $arguments[] = '--skip-delete';
    }

    $blocked = eforms_ud_wp( $context, $arguments );
    eforms_ud_assert_blocked( $context, $blocked, $token, 1, 'WP-CLI' . $suffix );

    eforms_ud_set_phase( $context, 'ready' );
    $ready = eforms_ud_wp( $context, $arguments );
    eforms_ud_expect_status( $ready, 0, 'WP-CLI ready retry failed' . $suffix . '.' );

    $plugin_path = $context['plugin_dir'] . '/' . EFORMS_UD_PLUGIN_SLUG;
    if ( $skip_delete ) {
        if ( ! is_file( $plugin_path . '/eforms-uninstall-drain-proof.php' ) ) {
            throw new RuntimeException( 'WP-CLI --skip-delete removed plugin files.' );
        }
        $cleanup = eforms_ud_wp(
            $context,
            array( 'plugin', 'uninstall', EFORMS_UD_PLUGIN_SLUG )
        );
        eforms_ud_assert_deleted( $context, $cleanup, 'WP-CLI cleanup after --skip-delete' );
    } elseif ( is_dir( $plugin_path ) ) {
        throw new RuntimeException( 'WP-CLI normal deletion left plugin files behind.' );
    }
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_prepare_fixture( $context, $phase, $token ) {
    eforms_ud_write_fixture( $context );
    eforms_ud_set_state(
        $context,
        array(
            'version' => 1,
            'phase' => $phase,
            'attempts' => 0,
            'safe_after' => '2030-01-02T03:04:05Z',
            'barrier_token' => $token,
            'completed' => false,
        )
    );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_write_fixture( $context ) {
    $directory = $context['plugin_dir'] . '/' . EFORMS_UD_PLUGIN_SLUG;
    if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
        throw new RuntimeException( 'Unable to create the uninstall-drain fixture plugin.' );
    }

    $main = <<<'PHP'
<?php
/**
 * Plugin Name: eForms Uninstall Drain Proof
 * Description: Disposable fixture for real WordPress deletion semantics.
 * Version: 1.0.0
 */
PHP;

    $uninstall = <<<'PHP'
<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$option = 'eforms_uninstall_drain_proof';
$state = get_option( $option, array() );
$state = is_array( $state ) ? $state : array();
$state['attempts'] = isset( $state['attempts'] ) ? (int) $state['attempts'] + 1 : 1;
$state['last_attempt_phase'] = isset( $state['phase'] ) ? (string) $state['phase'] : 'missing';
update_option( $option, $state, false );

if ( isset( $state['phase'] ) && $state['phase'] === 'ready' ) {
    $state['completed'] = true;
    update_option( $option, $state, false );
    return;
}

$safe_after = isset( $state['safe_after'] ) ? (string) $state['safe_after'] : 'unknown';
$phase = isset( $state['phase'] ) ? (string) $state['phase'] : 'missing';
$detail = $phase === 'provider_failure'
    ? 'provider unavailable; retry after provider recovery'
    : 'grant drain incomplete; retry after the safe timestamp';

wp_die(
    'EFORMS_UNINSTALL_RETRY phase=' . $phase . ' safe_after=' . $safe_after . '; ' . $detail . '.',
    'eForms uninstall is not complete',
    array( 'response' => 503 )
);
PHP;

    eforms_ud_write_file( $directory . '/eforms-uninstall-drain-proof.php', $main . "\n" );
    eforms_ud_write_file( $directory . '/uninstall.php', $uninstall . "\n" );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_write_companion( $context ) {
    $directory = $context['plugin_dir'] . '/' . EFORMS_UD_COMPANION_SLUG;
    if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
        throw new RuntimeException( 'Unable to create the companion fixture plugin.' );
    }

    $plugin = <<<'PHP'
<?php
/**
 * Plugin Name: eForms Uninstall Companion
 * Description: Disposable bulk-deletion ordering fixture.
 * Version: 1.0.0
 */
PHP;
    eforms_ud_write_file( $directory . '/eforms-uninstall-companion.php', $plugin . "\n" );
}

function eforms_ud_write_file( $path, $contents ) {
    if ( file_put_contents( $path, $contents, LOCK_EX ) === false ) {
        throw new RuntimeException( 'Unable to write fixture file: ' . $path );
    }
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_set_phase( $context, $phase ) {
    $state = eforms_ud_get_state( $context );
    $state['phase'] = $phase;
    $state['completed'] = false;
    eforms_ud_set_state( $context, $state );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_set_state( $context, $state ) {
    $encoded = json_encode( $state, JSON_UNESCAPED_SLASHES );
    if ( ! is_string( $encoded ) ) {
        throw new RuntimeException( 'Unable to encode fixture state.' );
    }
    $result = eforms_ud_wp(
        $context,
        array( 'option', 'update', EFORMS_UD_OPTION, $encoded, '--format=json', '--autoload=no' )
    );
    eforms_ud_expect_status( $result, 0, 'Unable to persist fixture drain state.' );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 * @return array<string,mixed>
 */
function eforms_ud_get_state( $context ) {
    $result = eforms_ud_wp( $context, array( 'option', 'get', EFORMS_UD_OPTION, '--format=json' ) );
    eforms_ud_expect_status( $result, 0, 'Unable to read fixture drain state.' );
    $state = json_decode( trim( $result['stdout'] ), true );
    if ( ! is_array( $state ) ) {
        throw new RuntimeException( 'Fixture drain state is not valid JSON.' );
    }
    return $state;
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_ajax_delete( $context, $plugin_file, $slug ) {
    $auth = eforms_ud_auth( $context, 'updates' );
    return eforms_ud_http(
        $context['http_base'] . '/wp-admin/admin-ajax.php',
        'POST',
        array(
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: ' . $auth['cookie_header'],
        ),
        http_build_query(
            array(
                'action' => 'delete-plugin',
                '_ajax_nonce' => $auth['nonce'],
                'plugin' => $plugin_file,
                'slug' => $slug,
            ),
            '',
            '&',
            PHP_QUERY_RFC3986
        )
    );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_admin_bulk_delete( $context, $plugin_files ) {
    $auth = eforms_ud_auth( $context, 'bulk-plugins' );
    return eforms_ud_http(
        $context['http_base'] . '/wp-admin/plugins.php?action=delete-selected&verify-delete=1',
        'POST',
        array(
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: ' . $auth['cookie_header'],
        ),
        http_build_query(
            array(
                'action' => 'delete-selected',
                'verify-delete' => '1',
                '_wpnonce' => $auth['nonce'],
                'checked' => $plugin_files,
            ),
            '',
            '&',
            PHP_QUERY_RFC3986
        )
    );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_rest_delete( $context ) {
    $auth = eforms_ud_auth( $context, 'wp_rest' );
    $route = '/wp/v2/plugins/' . substr( EFORMS_UD_PLUGIN_FILE, 0, -4 );
    return eforms_ud_http(
        $context['http_base'] . '/index.php?rest_route=' . rawurlencode( $route ),
        'DELETE',
        array(
            'Cookie: ' . $auth['cookie_header'],
            'X-WP-Nonce: ' . $auth['nonce'],
        ),
        ''
    );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 * @return array{cookie_header:string,nonce:string}
 */
function eforms_ud_auth( $context, $nonce_action ) {
    $source = "<?php\n"
        . '$user_id = ' . (int) $context['admin_id'] . ";\n"
        . "\$expiration = time() + 600;\n"
        . "\$manager = WP_Session_Tokens::get_instance( \$user_id );\n"
        . "\$token = \$manager->create( \$expiration );\n"
        . "\$auth_cookie = wp_generate_auth_cookie( \$user_id, \$expiration, 'auth', \$token );\n"
        . "\$logged_in_cookie = wp_generate_auth_cookie( \$user_id, \$expiration, 'logged_in', \$token );\n"
        . "\$_COOKIE[ AUTH_COOKIE ] = \$auth_cookie;\n"
        . "\$_COOKIE[ LOGGED_IN_COOKIE ] = \$logged_in_cookie;\n"
        . "wp_set_current_user( \$user_id );\n"
        . "echo wp_json_encode( array(\n"
        . "    'cookie_header' => AUTH_COOKIE . '=' . \$auth_cookie . '; ' . LOGGED_IN_COOKIE . '=' . \$logged_in_cookie,\n"
        . '    \'nonce\' => wp_create_nonce( ' . var_export( $nonce_action, true ) . " ),\n"
        . ") );\n";

    $result = eforms_ud_eval_file( $context, $source );
    eforms_ud_expect_status( $result, 0, 'Unable to mint disposable WordPress authentication.' );
    $auth = json_decode( trim( $result['stdout'] ), true );
    if (
        ! is_array( $auth )
        || empty( $auth['cookie_header'] )
        || empty( $auth['nonce'] )
    ) {
        throw new RuntimeException( 'Disposable WordPress authentication was malformed.' );
    }
    return $auth;
}

/**
 * @return array{status:int,stdout:string,stderr:string,output:string,http_status:int}
 */
function eforms_ud_http( $url, $method, $headers, $body ) {
    $context = stream_context_create(
        array(
            'http' => array(
                'method' => $method,
                'header' => implode( "\r\n", $headers ),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 15,
            ),
        )
    );
    $response = @file_get_contents( $url, false, $context );
    $response_headers = isset( $http_response_header ) && is_array( $http_response_header )
        ? $http_response_header
        : array();
    $status = 0;
    if ( isset( $response_headers[0] ) && preg_match( '/\s(\d{3})\s/', $response_headers[0], $matches ) ) {
        $status = (int) $matches[1];
    }
    if ( $status === 0 ) {
        throw new RuntimeException( 'No HTTP response received from disposable WordPress: ' . $url );
    }
    $response = is_string( $response ) ? $response : '';

    return array(
        'status' => $status >= 200 && $status < 300 ? 0 : $status,
        'stdout' => $response,
        'stderr' => '',
        'output' => trim( 'HTTP ' . $status . "\n" . implode( "\n", $response_headers ) . "\n" . $response ),
        'http_status' => $status,
    );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 * @return array{process:resource,pipes:array<int,resource>,base:string}
 */
function eforms_ud_start_http_server( $context ) {
    $socket = stream_socket_server( 'tcp://127.0.0.1:0', $error_number, $error_message );
    if ( ! is_resource( $socket ) ) {
        throw new RuntimeException( 'Unable to reserve a local HTTP port: ' . $error_message );
    }
    $address = stream_socket_get_name( $socket, false );
    fclose( $socket );
    if ( ! is_string( $address ) || strpos( $address, ':' ) === false ) {
        throw new RuntimeException( 'Unable to determine the local HTTP port.' );
    }

    $descriptor_spec = array(
        0 => array( 'pipe', 'r' ),
        1 => array( 'pipe', 'w' ),
        2 => array( 'pipe', 'w' ),
    );
    $process = proc_open(
        array( PHP_BINARY, '-S', $address, '-t', $context['wp_path'] ),
        $descriptor_spec,
        $pipes,
        $context['wp_path']
    );
    if ( ! is_resource( $process ) ) {
        throw new RuntimeException( 'Unable to start the disposable WordPress HTTP server.' );
    }

    list( $host, $port ) = explode( ':', $address, 2 );
    $ready = false;
    for ( $attempt = 0; $attempt < 50; $attempt++ ) {
        $connection = @fsockopen( $host, (int) $port, $error_number, $error_message, 0.1 );
        if ( is_resource( $connection ) ) {
            fclose( $connection );
            $ready = true;
            break;
        }
        usleep( 100000 );
    }
    if ( ! $ready ) {
        eforms_ud_stop_http_server( array( 'process' => $process, 'pipes' => $pipes, 'base' => '' ) );
        throw new RuntimeException( 'Disposable WordPress HTTP server did not become ready.' );
    }

    return array(
        'process' => $process,
        'pipes' => $pipes,
        'base' => 'http://' . $address,
    );
}

function eforms_ud_stop_http_server( $server ) {
    if ( isset( $server['process'] ) && is_resource( $server['process'] ) ) {
        proc_terminate( $server['process'] );
    }
    if ( isset( $server['pipes'] ) && is_array( $server['pipes'] ) ) {
        foreach ( $server['pipes'] as $pipe ) {
            if ( is_resource( $pipe ) ) {
                fclose( $pipe );
            }
        }
    }
    if ( isset( $server['process'] ) && is_resource( $server['process'] ) ) {
        proc_close( $server['process'] );
    }
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 */
function eforms_ud_eval_file( $context, $source ) {
    $path = tempnam( sys_get_temp_dir(), 'eforms-ud-' );
    if ( $path === false ) {
        throw new RuntimeException( 'Unable to allocate a temporary WordPress entrypoint.' );
    }

    try {
        eforms_ud_write_file( $path, $source );
        return eforms_ud_wp( $context, array( 'eval-file', $path ) );
    } finally {
        @unlink( $path );
    }
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 * @return array{status:int,stdout:string,stderr:string,output:string}
 */
function eforms_ud_wp( $context, $arguments ) {
    $command = array_merge(
        array( $context['wp_cli'], '--path=' . $context['wp_path'], '--no-color' ),
        $arguments
    );
    return eforms_ud_command( $command );
}

/**
 * @return array{status:int,stdout:string,stderr:string,output:string}
 */
function eforms_ud_command( $command ) {
    $descriptor_spec = array(
        0 => array( 'pipe', 'r' ),
        1 => array( 'pipe', 'w' ),
        2 => array( 'pipe', 'w' ),
    );
    $process = proc_open( $command, $descriptor_spec, $pipes );
    if ( ! is_resource( $process ) ) {
        throw new RuntimeException( 'Unable to start command: ' . implode( ' ', $command ) );
    }

    fclose( $pipes[0] );
    $stdout = stream_get_contents( $pipes[1] );
    $stderr = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    $status = proc_close( $process );
    $stdout = is_string( $stdout ) ? $stdout : '';
    $stderr = is_string( $stderr ) ? $stderr : '';

    return array(
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'output' => trim( $stdout . "\n" . $stderr ),
    );
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 * @param array{status:int,stdout:string,stderr:string,output:string} $result
 */
function eforms_ud_assert_blocked( $context, $result, $token, $expected_attempts, $label ) {
    if ( $result['status'] === 0 ) {
        throw new RuntimeException( $label . ' falsely reported success.' );
    }
    eforms_ud_expect_contains( $result['output'], 'EFORMS_UNINSTALL_RETRY', $label . ' lost retry instructions.' );
    eforms_ud_expect_contains( $result['output'], 'safe_after=', $label . ' lost the retry timestamp.' );

    $plugin_file = $context['plugin_dir'] . '/' . EFORMS_UD_PLUGIN_FILE;
    if ( ! is_file( $plugin_file ) ) {
        throw new RuntimeException( $label . ' removed plugin files before purge completion.' );
    }

    $state = eforms_ud_get_state( $context );
    if ( ! isset( $state['barrier_token'] ) || $state['barrier_token'] !== $token ) {
        throw new RuntimeException( $label . ' did not preserve the authoritative barrier.' );
    }
    if ( ! isset( $state['attempts'] ) || (int) $state['attempts'] !== $expected_attempts ) {
        throw new RuntimeException( $label . ' did not resume the expected attempt count.' );
    }
    if ( ! empty( $state['completed'] ) ) {
        throw new RuntimeException( $label . ' marked an incomplete purge as completed.' );
    }
}

/**
 * @param array{wp_path:string,wp_cli:string,plugin_dir:string,admin_id:int} $context
 * @param array{status:int,stdout:string,stderr:string,output:string} $result
 */
function eforms_ud_assert_deleted( $context, $result, $label ) {
    eforms_ud_expect_status( $result, 0, $label . ' failed.' );
    if ( is_dir( $context['plugin_dir'] . '/' . EFORMS_UD_PLUGIN_SLUG ) ) {
        throw new RuntimeException( $label . ' returned success but left plugin files behind.' );
    }

    $state = eforms_ud_get_state( $context );
    if ( empty( $state['completed'] ) ) {
        throw new RuntimeException( $label . ' removed files without completing the persisted purge state.' );
    }
}

function eforms_ud_expect_status( $result, $expected, $message ) {
    if ( $result['status'] !== $expected ) {
        throw new RuntimeException( $message . ' Output: ' . $result['output'] );
    }
}

function eforms_ud_expect_contains( $haystack, $needle, $message ) {
    if ( strpos( $haystack, $needle ) === false ) {
        throw new RuntimeException( $message . ' Output: ' . $haystack );
    }
}

function eforms_ud_path_is_within( $path, $parent ) {
    $path = rtrim( str_replace( '\\', '/', $path ), '/' ) . '/';
    $parent = rtrim( str_replace( '\\', '/', $parent ), '/' ) . '/';
    return strpos( $path, $parent ) === 0;
}
