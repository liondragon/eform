<?php
/**
 * Internal response template for handled public POST requests.
 */

if ( class_exists( 'PublicRequestController' ) ) {
    PublicRequestController::render_captured_response();
}
