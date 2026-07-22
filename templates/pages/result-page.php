<?php
/**
 * Shared internal result page scaffold.
 */

require_once dirname( __DIR__, 2 ) . '/src/EformsMarkup.php';

$eforms_result_type = isset( $eforms_result_type ) && is_string( $eforms_result_type ) ? $eforms_result_type : '';
$eforms_result_title = isset( $eforms_result_title ) && is_string( $eforms_result_title ) ? $eforms_result_title : '';
$eforms_result_message = isset( $eforms_result_message ) && is_string( $eforms_result_message ) ? $eforms_result_message : '';
$eforms_result_role = isset( $eforms_result_role ) && is_string( $eforms_result_role ) ? $eforms_result_role : '';
$eforms_result_aria_live = isset( $eforms_result_aria_live ) && is_string( $eforms_result_aria_live ) ? $eforms_result_aria_live : '';

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

$result_class = $eforms_result_type !== '' ? 'eforms-result-page-' . str_replace( '_', '-', $eforms_result_type ) : 'eforms-result-page-unknown';
$message_attrs = array(
    'class' => 'eforms-result-message',
);
if ( $eforms_result_role !== '' ) {
    $message_attrs['role'] = $eforms_result_role;
}
if ( $eforms_result_aria_live !== '' ) {
    $message_attrs['aria-live'] = $eforms_result_aria_live;
}

$message_attr_string = EformsMarkup::attributes( $message_attrs );
$escaped_message = EformsMarkup::escape_html( $eforms_result_message );
?>
<article id="page_content" class="page_content eforms-result-page <?php echo EformsMarkup::escape_attr( $result_class ); ?>" data-eforms-result="<?php echo EformsMarkup::escape_attr( $eforms_result_type ); ?>">
    <header id="page_header" class="pageline">
        <div class="inner">
            <h1 class="page-title"><?php echo EformsMarkup::escape_html( $eforms_result_title ); ?></h1>
        </div>
    </header>
    <div class="inner article-body-wrap">
        <div id="content" class="article-body">
            <div class="entry-content">
                <div <?php echo $message_attr_string; ?>>
                    <?php echo nl2br( $escaped_message ); ?>
                </div>
            </div>
        </div>
    </div>
</article>
<?php
if ( function_exists( 'get_footer' ) ) {
    get_footer();
}
