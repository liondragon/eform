<?php
/**
 * Theme-integrated private managed-photo gallery.
 */

require_once dirname( __DIR__, 2 ) . '/src/EformsMarkup.php';

$page = class_exists( 'PublicRequestController' ) ? PublicRequestController::review_page_context() : array();
$title = isset( $page['title'] ) && is_string( $page['title'] ) ? $page['title'] : 'Submitted Photos';
$submission_id = isset( $page['submission_id'] ) && is_string( $page['submission_id'] ) ? $page['submission_id'] : '';
$items = isset( $page['items'] ) && is_array( $page['items'] ) ? $page['items'] : array();
$item_count = count( $items );
$item_label = $item_count === 1 ? 'photo' : 'photos';
$deleted = ! empty( $page['deleted'] );
$expired = ! $deleted && ! empty( $page['expired'] );
$attribution_name = ! $deleted && ! $expired && isset( $page['attribution_name'] ) && is_string( $page['attribution_name'] ) ? $page['attribution_name'] : '';
$review_facts = ! $deleted && ! $expired && isset( $page['review_facts'] ) && is_array( $page['review_facts'] ) ? $page['review_facts'] : array();
$fact_groups = isset( $review_facts['groups'] ) && is_array( $review_facts['groups'] ) ? $review_facts['groups'] : array();
$facts_available = ! empty( $fact_groups );
$facts_aria_label = isset( $review_facts['aria_label'] ) && is_string( $review_facts['aria_label'] ) ? $review_facts['aria_label'] : '';
$availability_label = isset( $page['availability_label'] ) && is_string( $page['availability_label'] ) ? $page['availability_label'] : '';
$submitted_label = isset( $page['submitted_label'] ) && is_string( $page['submitted_label'] ) ? $page['submitted_label'] : '';
$can_delete = ! $deleted && ! empty( $page['can_delete'] );
$operator_action_url = $can_delete && isset( $page['operator_action_url'] ) && is_string( $page['operator_action_url'] ) ? $page['operator_action_url'] : '';
$operator_action_field = $can_delete && isset( $page['operator_action_field'] ) && is_string( $page['operator_action_field'] ) ? $page['operator_action_field'] : '';
$delete_action = $can_delete && isset( $page['delete_action'] ) && is_string( $page['delete_action'] ) ? $page['delete_action'] : '';
$delete_nonce_action = $can_delete && isset( $page['delete_nonce_action'] ) && is_string( $page['delete_nonce_action'] ) ? $page['delete_nonce_action'] : '';
$delete_nonce_field = $can_delete && isset( $page['delete_nonce_field'] ) && is_string( $page['delete_nonce_field'] ) ? $page['delete_nonce_field'] : '';
$availability_action = ! $expired && isset( $page['availability_action'] ) && is_string( $page['availability_action'] ) ? $page['availability_action'] : '';
$availability_nonce_action = ! $expired && isset( $page['availability_nonce_action'] ) && is_string( $page['availability_nonce_action'] ) ? $page['availability_nonce_action'] : '';
$availability_nonce_field = ! $expired && isset( $page['availability_nonce_field'] ) && is_string( $page['availability_nonce_field'] ) ? $page['availability_nonce_field'] : '';
$availability_choice_field = ! $expired && isset( $page['availability_choice_field'] ) && is_string( $page['availability_choice_field'] ) ? $page['availability_choice_field'] : '';
$availability_options = ! $expired && isset( $page['availability_options'] ) && is_array( $page['availability_options'] ) ? $page['availability_options'] : array();
$preview_timeout_ms = (int) Anchors::get( 'REVIEW_PREVIEW_LOAD_TIMEOUT_MS' );
$operator_post_available = $can_delete && $operator_action_url !== '' && $operator_action_field !== '' && function_exists( 'wp_nonce_field' );
$delete_available = $operator_post_available && $delete_action !== '' && $delete_nonce_action !== '' && $delete_nonce_field !== '';
$availability_available = $operator_post_available && ! $expired && $availability_action !== '' && $availability_nonce_action !== '' && $availability_nonce_field !== '' && $availability_choice_field !== '' && ! empty( $availability_options );
$actions_available = ! $deleted && ( $availability_available || $delete_available );
$summary_count_label = $expired ? 'No photos shown' : ( (string) $item_count . ' ' . $item_label );
$escape_html = static function ( $value ) {
    return EformsMarkup::escape_html( $value );
};
$escape_attr = static function ( $value ) {
    return EformsMarkup::escape_attr( $value );
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
<article id="page_content" class="page_content eforms-review-page" data-eforms-review="<?php echo $deleted ? 'deleted' : ( $expired ? 'expired' : 'gallery' ); ?>" data-eforms-review-preview-timeout-ms="<?php echo $escape_attr( $preview_timeout_ms ); ?>">
    <header id="page_header" class="pageline">
        <div class="inner">
            <div class="eforms-review-heading">
                <h1 class="page-title"><?php echo $escape_html( $title ); ?></h1>
                <?php if ( $attribution_name !== '' ) : ?>
                    <p class="eforms-review-attribution">
                        <span class="eforms-review-attribution-by">by</span>
                        <span class="eforms-review-attribution-name"><?php echo $escape_html( $attribution_name ); ?></span>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <div class="inner article-body-wrap">
        <div id="content" class="article-body">
            <div class="entry-content">
                <?php if ( $deleted ) : ?>
                    <p class="eforms-review-status">Submission <strong><?php echo $escape_html( $submission_id ); ?></strong> was deleted.</p>
                <?php elseif ( $expired ) : ?>
                    <p class="eforms-review-status">This photo submission is no longer available. Available until <?php echo $escape_html( $availability_label ); ?>.</p>
                <?php else : ?>
                    <?php if ( $facts_available ) : ?>
                        <section class="eforms-review-facts"<?php echo $facts_aria_label !== '' ? ' aria-label="' . $escape_attr( $facts_aria_label ) . '"' : ''; ?>>
                            <?php foreach ( $fact_groups as $group ) : ?>
                                <?php
                                $layout = is_array( $group ) && isset( $group['layout'] ) && $group['layout'] === 'project' ? ' eforms-review-facts-list--project' : '';
                                $rows = is_array( $group ) && isset( $group['rows'] ) && is_array( $group['rows'] ) ? $group['rows'] : array();
                                if ( empty( $rows ) ) {
                                    continue;
                                }
                                ?>
                                <dl class="eforms-review-facts-list<?php echo $layout; ?>">
                                    <?php foreach ( $rows as $row ) : ?>
                                        <?php
                                        $label = isset( $row['label'] ) && is_string( $row['label'] ) ? $row['label'] : '';
                                        $value = isset( $row['value'] ) && is_string( $row['value'] ) ? $row['value'] : '';
                                        $href = isset( $row['href'] ) && is_string( $row['href'] ) ? $row['href'] : '';
                                        $wide_class = ! empty( $row['wide'] ) ? ' eforms-review-fact--wide' : '';
                                        if ( $label === '' || $value === '' ) {
                                            continue;
                                        }
                                        ?>
                                        <div class="eforms-review-fact<?php echo $wide_class; ?>">
                                            <dt><?php echo $escape_html( $label ); ?></dt>
                                            <dd>
                                                <?php if ( $href !== '' ) : ?>
                                                    <a href="<?php echo $escape_url( $href ); ?>" rel="noreferrer noopener"><?php echo $escape_html( $value ); ?></a>
                                                <?php else : ?>
                                                    <?php echo $escape_html( $value ); ?>
                                                <?php endif; ?>
                                            </dd>
                                        </div>
                                    <?php endforeach; ?>
                                </dl>
                            <?php endforeach; ?>
                        </section>
                    <?php endif; ?>
                    <div class="eforms-review-grid" role="list">
                        <?php foreach ( $items as $index => $item ) : ?>
                            <?php
                            $photo_label = 'Photo ' . ( (int) $index + 1 );
                            $download_url = isset( $item['download_url'] ) ? (string) $item['download_url'] : '';
                            $preview_url = isset( $item['preview_url'] ) ? (string) $item['preview_url'] : '';
                            $original_inline_available = ! empty( $item['original_inline_available'] );
                            $preview_width = isset( $item['preview_width'] ) ? (int) $item['preview_width'] : 0;
                            $preview_height = isset( $item['preview_height'] ) ? (int) $item['preview_height'] : 0;
                            ?>
                            <figure class="eforms-review-item" role="listitem">
                                <div class="eforms-review-preview eforms-review-preview-with-image<?php echo $preview_url === '' ? ' eforms-review-preview-unavailable' : ''; ?>">
                                    <span<?php echo $preview_url !== '' ? ' hidden aria-hidden="true"' : ''; ?> aria-live="polite" data-eforms-review-fallback>
                                        <span data-eforms-review-fallback-status>Preview unavailable</span>
                                        <?php if ( $preview_url !== '' ) : ?>
                                            <button type="button" class="eforms-review-button eforms-review-button--compact" data-eforms-review-retry>Retry preview</button>
                                        <?php endif; ?>
                                        <?php if ( $original_inline_available ) : ?>
                                            <button type="button" class="eforms-review-button eforms-review-button--compact" data-eforms-review-original data-eforms-review-original-src="<?php echo $escape_url( $download_url ); ?>">Load original</button>
                                        <?php endif; ?>
                                    </span>
                                    <a class="eforms-review-preview-link ta-gallery__link"<?php echo $preview_width > 0 && $preview_height > 0 ? ' data-lbwps-width="' . $escape_attr( $preview_width ) . '" data-lbwps-height="' . $escape_attr( $preview_height ) . '"' : ''; ?> aria-label="<?php echo $escape_attr( 'Open ' . $photo_label ); ?>">
                                        <img hidden data-eforms-review-src="<?php echo $escape_url( $preview_url ); ?>" alt="<?php echo $escape_attr( $photo_label . ' preview' ); ?>" decoding="async" data-eforms-review-preview>
                                    </a>
                                    <a class="eforms-review-download-overlay" href="<?php echo $escape_url( $download_url ); ?>" aria-label="<?php echo $escape_attr( 'Download ' . $photo_label ); ?>">
                                        <span class="screen-reader-text">Download photo</span>
                                    </a>
                                </div>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ( $actions_available ) : ?>
                    <div class="eforms-review-actions">
                        <p class="eforms-review-summary">
                            <span>ID: <strong><?php echo $escape_html( $submission_id ); ?></strong></span>
                            <span class="eforms-review-summary-count"><?php echo $escape_html( $summary_count_label ); ?></span>
                            <?php if ( $submitted_label !== '' ) : ?>
                                <span class="eforms-review-submitted">Submitted <?php echo $escape_html( $submitted_label ); ?></span>
                            <?php endif; ?>
                            <?php if ( $availability_label !== '' ) : ?>
                                <span class="eforms-review-availability">Available until <?php echo $escape_html( $availability_label ); ?></span>
                            <?php endif; ?>
                        </p>
                        <div class="eforms-review-action-buttons">
                            <?php if ( $availability_available ) : ?>
                                <button type="button" class="eforms-review-button eforms-review-availability-open" data-eforms-review-availability-open>Update availability</button>
                            <?php endif; ?>
                            <?php if ( $delete_available ) : ?>
                                <button type="button" class="eforms-review-button eforms-review-button--danger-outline eforms-review-delete-open" data-eforms-review-delete-open>Delete submission</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ( $availability_available ) : ?>
                    <dialog class="eforms-review-delete-dialog eforms-review-availability-dialog" data-eforms-review-availability-dialog>
                        <form method="post" action="<?php echo $escape_url( $operator_action_url ); ?>">
                            <h2>Update availability</h2>
                            <p>Choose how long this submitted photo gallery should remain available.</p>
                            <input type="hidden" name="<?php echo $escape_attr( $operator_action_field ); ?>" value="<?php echo $escape_attr( $availability_action ); ?>" />
                            <?php wp_nonce_field( $availability_nonce_action, $availability_nonce_field ); ?>
                            <div class="eforms-review-availability-options">
                                <?php foreach ( $availability_options as $option ) : ?>
                                    <?php
                                    $choice = isset( $option['key'] ) && is_string( $option['key'] ) ? $option['key'] : '';
                                    $label = isset( $option['label'] ) && is_string( $option['label'] ) ? $option['label'] : '';
                                    if ( $choice === '' || $label === '' ) {
                                        continue;
                                    }
                                    ?>
                                    <label><input type="radio" name="<?php echo $escape_attr( $availability_choice_field ); ?>" value="<?php echo $escape_attr( $choice ); ?>" <?php echo ! empty( $option['checked'] ) ? 'checked' : ''; ?> /> <?php echo $escape_html( $label ); ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="eforms-review-delete-actions">
                                <button type="button" class="eforms-review-button" data-eforms-review-availability-close>Cancel</button>
                                <button type="submit" class="eforms-review-button">Update availability</button>
                            </div>
                        </form>
                    </dialog>
                <?php endif; ?>
                <?php if ( $delete_available ) : ?>
                    <dialog class="eforms-review-delete-dialog" data-eforms-review-delete-dialog>
                        <form method="post" action="<?php echo $escape_url( $operator_action_url ); ?>">
                            <h2>Delete submission?</h2>
                            <p>This deletes the review submission and its photos.</p>
                            <input type="hidden" name="<?php echo $escape_attr( $operator_action_field ); ?>" value="<?php echo $escape_attr( $delete_action ); ?>" />
                            <?php wp_nonce_field( $delete_nonce_action, $delete_nonce_field ); ?>
                            <div class="eforms-review-delete-actions">
                                <button type="button" class="eforms-review-button" data-eforms-review-delete-close>Cancel</button>
                                <button type="submit" class="eforms-review-button eforms-review-button--danger">Delete</button>
                            </div>
                        </form>
                    </dialog>
                <?php endif; ?>
            </div>
        </div>
    </div>
</article>
<?php
if ( function_exists( 'get_footer' ) ) {
    get_footer();
}
