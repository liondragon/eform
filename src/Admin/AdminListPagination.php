<?php
/**
 * Shared WordPress admin list-table pagination markup.
 */

class AdminListPagination {
    private static $control_meta = array(
        'first' => array( 'class' => 'first-page', 'label' => 'First page', 'glyph' => '&laquo;' ),
        'previous' => array( 'class' => 'prev-page', 'label' => 'Previous page', 'glyph' => '&lsaquo;' ),
        'next' => array( 'class' => 'next-page', 'label' => 'Next page', 'glyph' => '&rsaquo;' ),
        'last' => array( 'class' => 'last-page', 'label' => 'Last page', 'glyph' => '&raquo;' ),
    );

    /**
     * Render the wp-admin list-table pagination shell.
     *
     * A missing control is omitted. A null URL renders the matching disabled
     * control, and a non-empty string renders a link.
     */
    public static function render( $displaying_label, $controls, $current_page = null, $total_pages = null ) {
        $controls = is_array( $controls ) ? $controls : array();
        $current_page = is_numeric( $current_page ) ? max( 1, (int) $current_page ) : null;
        $total_pages = is_numeric( $total_pages ) ? max( 1, (int) $total_pages ) : null;
        $page_class = $total_pages === 1 ? ' one-page' : '';

        echo '<div class="tablenav-pages' . esc_attr( $page_class ) . '">';
        if ( is_string( $displaying_label ) && $displaying_label !== '' ) {
            echo '<span class="displaying-num">' . esc_html( $displaying_label ) . '</span>';
        }

        echo '<span class="pagination-links">';
        self::render_control( 'first', $controls );
        self::render_control( 'previous', $controls );

        if ( $current_page !== null && $total_pages !== null ) {
            echo '<span class="screen-reader-text">' . esc_html( 'Current Page' ) . '</span>';
            echo '<span class="paging-input"><span class="tablenav-paging-text">';
            echo esc_html( (string) $current_page ) . ' ' . esc_html( 'of' ) . ' <span class="total-pages">' . esc_html( (string) $total_pages ) . '</span>';
            echo '</span></span>';
        }

        self::render_control( 'next', $controls );
        self::render_control( 'last', $controls );
        echo '</span></div>';
    }

    private static function render_control( $name, $controls ) {
        if ( ! array_key_exists( $name, $controls ) || ! isset( self::$control_meta[ $name ] ) ) {
            return;
        }

        $meta = self::$control_meta[ $name ];
        $url = $controls[ $name ];
        if ( ! is_string( $url ) || $url === '' ) {
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . $meta['glyph'] . '</span>';
            return;
        }

        echo '<a class="' . esc_attr( $meta['class'] ) . ' button" href="' . esc_url( $url ) . '">';
        echo '<span class="screen-reader-text">' . esc_html( $meta['label'] ) . '</span>';
        echo '<span aria-hidden="true">' . $meta['glyph'] . '</span></a>';
    }
}
