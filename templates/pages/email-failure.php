<?php
/**
 * Internal email-failure result page template.
 */

$page = class_exists( 'PublicRequestController' ) ? PublicRequestController::result_page_context() : array();
$context = isset( $page['context'] ) && is_array( $page['context'] ) ? $page['context'] : array();
$eforms_result_type = 'email_failure';
$eforms_result_title = class_exists( 'Success' ) ? Success::get_result_title( 'email_failure', $context ) : 'Request Not Sent';
if ( class_exists( 'Success' ) ) {
    $eforms_result_message = Success::get_result_message( 'email_failure', $context );
} elseif ( function_exists( 'eforms_error_message' ) ) {
    $eforms_result_message = eforms_error_message( 'EFORMS_ERR_EMAIL_SEND' );
} else {
    require_once dirname( __DIR__, 2 ) . '/src/ErrorMessages.php';
    $eforms_result_message = ErrorMessages::message( 'EFORMS_ERR_EMAIL_SEND' );
}
$eforms_result_role = 'alert';
$eforms_result_aria_live = '';

require __DIR__ . '/result-page.php';
