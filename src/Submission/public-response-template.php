<?php
/**
 * Internal response template for handled public POST requests.
 */

if ( function_exists( 'get_header' ) ) {
    get_header();
}

if ( class_exists( 'PublicRequestController' ) ) {
    PublicRequestController::render_captured_response();
}

if ( function_exists( 'get_footer' ) ) {
    get_footer();
}
