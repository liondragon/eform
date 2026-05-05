<?php
/**
 * Internal email-failure result page template.
 */

$page = class_exists( 'PublicRequestController' ) ? PublicRequestController::result_page_context() : array();
$context = isset( $page['context'] ) && is_array( $page['context'] ) ? $page['context'] : array();
$message = class_exists( 'Success' ) ? Success::get_result_message( 'email_failure', $context ) : 'We couldn\'t send your request right now, so it may not have reached us. Please try again in a few minutes. If the issue keeps happening, call 720.900.5278 or message us directly.';

if ( function_exists( 'add_filter' ) ) {
    add_filter(
        'body_class',
        static function ( $classes ) {
            return array_values( array_diff( $classes, array( 'home', 'front-page' ) ) );
        },
        20
    );
}

if ( function_exists( 'get_header' ) ) {
    get_header();
}
?>
<article id="page_content" class="page_content eforms-result-page eforms-result-page-email-failure" data-eforms-result="email_failure">
    <header id="page_header" class="pageline">
        <div class="inner">
            <h1 class="page-title"><?php echo function_exists( 'esc_html' ) ? esc_html( 'Request Not Sent' ) : 'Request Not Sent'; ?></h1>
        </div>
    </header>
    <div class="inner article-body-wrap">
        <div id="content" class="article-body">
            <div class="entry-content">
                <div class="eforms-result-message" role="alert">
                    <?php echo function_exists( 'esc_html' ) ? esc_html( $message ) : htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ); ?>
                </div>
            </div>
        </div>
    </div>
</article>
<?php
if ( function_exists( 'get_footer' ) ) {
    get_footer();
}
