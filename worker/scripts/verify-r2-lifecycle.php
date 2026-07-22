<?php
/**
 * Read-only deployment verifier for the R2 artifacts lifecycle backstop.
 *
 * Cloudflare management credentials belong only in this operator process.
 * WordPress and the upload Worker never load this file or receive the token.
 */

require_once __DIR__ . '/../../src/Anchors.php';

function eforms_r2_lifecycle_required_seconds() {
    return Anchors::get( 'TOKEN_TTL_MAX' )
        + Anchors::get( 'MANAGED_STAGED_DELETE_GRACE_SECONDS' )
        + Anchors::get( 'MANAGED_FINALIZED_TTL_SECONDS' )
        + Anchors::get( 'MANAGED_LIFECYCLE_SAFETY_MARGIN_SECONDS' );
}

function eforms_r2_lifecycle_required_days() {
    $seconds_per_day = 86400;
    return (int) ceil( eforms_r2_lifecycle_required_seconds() / $seconds_per_day );
}

function eforms_r2_lifecycle_verify_rules( $response ) {
    $required_seconds = eforms_r2_lifecycle_required_days() * 86400;
    $rules = is_array( $response )
        && isset( $response['success'], $response['result']['rules'] )
        && $response['success'] === true
        && is_array( $response['result']['rules'] )
            ? $response['result']['rules']
            : null;
    if ( $rules === null ) {
        return array( 'ok' => false, 'reason' => 'lifecycle_response_invalid', 'required_seconds' => $required_seconds );
    }

    $matching = 0;
    foreach ( $rules as $rule ) {
        if ( ! is_array( $rule )
            || ! array_key_exists( 'enabled', $rule )
            || ! is_bool( $rule['enabled'] )
        ) {
            return array( 'ok' => false, 'reason' => 'lifecycle_response_invalid', 'required_seconds' => $required_seconds );
        }
        if ( ! $rule['enabled'] || ! isset( $rule['deleteObjectsTransition']['condition'] ) ) {
            continue;
        }
        if ( ! isset( $rule['conditions']['prefix'] ) || ! is_string( $rule['conditions']['prefix'] ) ) {
            return array( 'ok' => false, 'reason' => 'lifecycle_response_invalid', 'required_seconds' => $required_seconds );
        }
        $prefix = $rule['conditions']['prefix'];
        $covers_artifacts = strncmp( 'artifacts/', $prefix, strlen( $prefix ) ) === 0;
        $inside_artifacts = strncmp( $prefix, 'artifacts/', strlen( 'artifacts/' ) ) === 0;
        if ( ! $covers_artifacts && ! $inside_artifacts ) {
            continue;
        }
        $condition = $rule['deleteObjectsTransition']['condition'];
        if ( ! is_array( $condition )
            || ! isset( $condition['type'], $condition['maxAge'] )
            || $condition['type'] !== 'Age'
            || ! is_int( $condition['maxAge'] )
            || $condition['maxAge'] < $required_seconds
        ) {
            return array( 'ok' => false, 'reason' => 'artifacts_lifecycle_unsafe', 'required_seconds' => $required_seconds );
        }
        if ( $covers_artifacts ) {
            $matching++;
        }
    }
    return $matching > 0
        ? array( 'ok' => true, 'reason' => '', 'required_seconds' => $required_seconds, 'matching_rules' => $matching )
        : array( 'ok' => false, 'reason' => 'artifacts_lifecycle_missing', 'required_seconds' => $required_seconds );
}

function eforms_r2_lifecycle_fetch( $account_id, $bucket_name, $api_token, $jurisdiction = '' ) {
    if ( ! function_exists( 'curl_init' )
        || ! is_string( $account_id ) || preg_match( '/^[a-f0-9]{32}$/D', $account_id ) !== 1
        || ! is_string( $bucket_name ) || strlen( $bucket_name ) < 3 || strlen( $bucket_name ) > 64
        || ! is_string( $api_token ) || $api_token === ''
        || ( $jurisdiction !== '' && ! in_array( $jurisdiction, array( 'default', 'eu', 'fedramp' ), true ) )
    ) {
        return array( 'ok' => false, 'reason' => 'operator_configuration_invalid' );
    }
    $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $account_id )
        . '/r2/buckets/' . rawurlencode( $bucket_name ) . '/lifecycle';
    $headers = array( 'Authorization: Bearer ' . $api_token, 'Accept: application/json' );
    if ( $jurisdiction !== '' ) {
        $headers[] = 'cf-r2-jurisdiction: ' . $jurisdiction;
    }
    $body = '';
    $handle = curl_init( $url );
    curl_setopt_array(
        $handle,
        array(
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => Anchors::get( 'R2_LIFECYCLE_REQUEST_TIMEOUT_SECONDS' ),
            CURLOPT_TIMEOUT => Anchors::get( 'R2_LIFECYCLE_REQUEST_TIMEOUT_SECONDS' ),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ( $curl, $chunk ) use ( &$body ) {
                if ( strlen( $body ) > Anchors::get( 'R2_LIFECYCLE_RESPONSE_MAX_BYTES' ) - strlen( $chunk ) ) {
                    return 0;
                }
                $body .= $chunk;
                return strlen( $chunk );
            },
        )
    );
    $executed = curl_exec( $handle );
    $status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
    curl_close( $handle );
    if ( $executed === false || $status !== 200 ) {
        return array( 'ok' => false, 'reason' => 'lifecycle_request_failed' );
    }
    $decoded = json_decode( $body, true );
    return is_array( $decoded )
        ? array( 'ok' => true, 'response' => $decoded )
        : array( 'ok' => false, 'reason' => 'lifecycle_response_invalid' );
}

function eforms_r2_lifecycle_main() {
    $fetched = eforms_r2_lifecycle_fetch(
        getenv( 'EFORMS_CF_ACCOUNT_ID' ),
        getenv( 'EFORMS_CF_BUCKET_NAME' ),
        getenv( 'EFORMS_CF_API_TOKEN' ),
        getenv( 'EFORMS_CF_JURISDICTION' ) ?: ''
    );
    $verified = ! empty( $fetched['ok'] )
        ? eforms_r2_lifecycle_verify_rules( $fetched['response'] )
        : $fetched;
    if ( empty( $verified['ok'] ) ) {
        fwrite( STDERR, 'R2 lifecycle verification failed: ' . $verified['reason'] . "\n" );
        return 1;
    }
    fwrite(
        STDOUT,
        'R2 artifacts lifecycle verified at or beyond '
        . eforms_r2_lifecycle_required_days()
        . " days; management credentials remained operator-local.\n"
    );
    return 0;
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
    exit( eforms_r2_lifecycle_main() );
}
