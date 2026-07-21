<?php
/**
 * Internal raw response template for signed managed-review files and errors.
 */

if ( class_exists( 'PublicRequestController' ) ) {
    PublicRequestController::render_captured_response();
}
