<?php
/**
 * Internal success result page template.
 */

$page = class_exists( 'PublicRequestController' ) ? PublicRequestController::result_page_context() : array();
$context = isset( $page['context'] ) && is_array( $page['context'] ) ? $page['context'] : array();
$message = class_exists( 'Success' ) ? Success::get_result_message( 'success', $context ) : 'Thank you for your submission.';

if ( function_exists( 'get_header' ) ) {
    get_header();
}
?>
<main class="eforms-result-page eforms-result-page-success" data-eforms-result="success">
    <div class="eforms-result-message" role="status" aria-live="polite">
        <?php echo function_exists( 'esc_html' ) ? esc_html( $message ) : htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ); ?>
    </div>
</main>
<?php
if ( function_exists( 'get_footer' ) ) {
    get_footer();
}
