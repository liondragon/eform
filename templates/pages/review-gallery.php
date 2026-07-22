<?php
/**
 * Theme-integrated private managed-photo gallery.
 */

$page = class_exists( 'PublicRequestController' ) ? PublicRequestController::review_page_context() : array();
$title = isset( $page['title'] ) && is_string( $page['title'] ) ? $page['title'] : 'Submitted Photos';
$submission_id = isset( $page['submission_id'] ) && is_string( $page['submission_id'] ) ? $page['submission_id'] : '';
$items = isset( $page['items'] ) && is_array( $page['items'] ) ? $page['items'] : array();
$escape_html = static function ( $value ) {
    return function_exists( 'esc_html' ) ? esc_html( $value ) : htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
};
$escape_attr = static function ( $value ) {
    return function_exists( 'esc_attr' ) ? esc_attr( $value ) : htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
};
$escape_url = static function ( $value ) {
    return function_exists( 'esc_url' ) ? esc_url( $value ) : htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
};

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
<article id="page_content" class="page_content eforms-review-page" data-eforms-review="gallery">
    <header id="page_header" class="pageline">
        <div class="inner">
            <h1 class="page-title"><?php echo $escape_html( $title ); ?></h1>
        </div>
    </header>
    <div class="inner article-body-wrap">
        <div id="content" class="article-body">
            <div class="entry-content">
                <p class="eforms-review-summary">
                    Submission <strong><?php echo $escape_html( $submission_id ); ?></strong>
                    &middot; <?php echo $escape_html( count( $items ) ); ?> <?php echo count( $items ) === 1 ? 'photo' : 'photos'; ?>
                </p>
                <div class="eforms-review-grid" role="list">
                    <?php foreach ( $items as $item ) : ?>
                        <?php
                        $name = isset( $item['display_name'] ) ? (string) $item['display_name'] : 'Photo';
                        $preview_url = isset( $item['preview_url'] ) ? (string) $item['preview_url'] : '';
                        $master_url = isset( $item['master_url'] ) ? (string) $item['master_url'] : '';
                        $width = isset( $item['width'] ) ? max( 1, (int) $item['width'] ) : 1;
                        $height = isset( $item['height'] ) ? max( 1, (int) $item['height'] ) : 1;
                        ?>
                        <figure class="eforms-review-item" role="listitem">
                            <img class="eforms-review-preview" src="<?php echo $escape_url( $preview_url ); ?>" alt="<?php echo $escape_attr( $name ); ?>" width="<?php echo $escape_attr( $width ); ?>" height="<?php echo $escape_attr( $height ); ?>" loading="lazy" decoding="async">
                            <figcaption class="eforms-review-caption">
                                <span class="eforms-review-name"><?php echo $escape_html( $name ); ?></span>
                                <span class="eforms-review-actions">
                                    <a href="<?php echo $escape_url( $master_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo $escape_attr( 'Open high-resolution ' . $name ); ?>">High-resolution</a>
                                    <a href="<?php echo $escape_url( $master_url ); ?>" download aria-label="<?php echo $escape_attr( 'Download high-resolution ' . $name ); ?>">Download high-resolution</a>
                                </span>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</article>
<?php
if ( function_exists( 'get_footer' ) ) {
    get_footer();
}
