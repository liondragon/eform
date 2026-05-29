<?php
/**
 * Internal success result page template.
 */

$page = class_exists( 'PublicRequestController' ) ? PublicRequestController::result_page_context() : array();
$context = isset( $page['context'] ) && is_array( $page['context'] ) ? $page['context'] : array();
$eforms_result_type = 'success';
$eforms_result_title = class_exists( 'Success' ) ? Success::get_result_title( 'success', $context ) : 'Thank You';
$eforms_result_message = class_exists( 'Success' ) ? Success::get_result_message( 'success', $context ) : 'Thank you for your submission.';
$eforms_result_role = 'status';
$eforms_result_aria_live = 'polite';

require __DIR__ . '/result-page.php';
