<?php
/**
 * Tools -> eForms Submissions retained-photo list.
 *
 * Contract: Public surfaces index
 * Contract: Managed Aggregate Contract
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Uploads/ReviewController.php';
require_once __DIR__ . '/../Uploads/UploadBatchStore.php';
require_once __DIR__ . '/AdminListPagination.php';

class SubmissionsAdmin {
    const SLUG = 'eforms-submissions';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
    }

    public static function register_menu() {
        add_management_page(
            'eForms Submissions',
            'eForms Submissions',
            'manage_options',
            self::SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! self::can_manage() ) {
            wp_die( esc_html( 'Sorry, you are not allowed to access this page.' ) );
        }

        echo self::render_html( $_GET, Config::get() );
    }

    public static function render_html( $request = null, $config = null, $now = null ) {
        if ( ! self::can_manage() ) {
            return '';
        }

        $request = is_array( $request ) ? self::unslash( $request ) : array();
        $config = is_array( $config ) ? $config : Config::get();
        $uploads_dir = Config::value( $config, array( 'uploads', 'dir' ), '' );
        $uploads_dir = is_string( $uploads_dir ) ? rtrim( $uploads_dir, '/\\' ) : '';
        $request_cursor = self::cursor_from_request( $request );
        $page = $uploads_dir === ''
            ? array( 'ok' => false, 'reason' => 'uploads_dir_unavailable', 'submissions' => array(), 'cursor' => array(), 'reached_limit' => false )
            : UploadBatchStore::retained_photo_submissions( $uploads_dir, $now, Anchors::get( 'RETAINED_SUBMISSIONS_PAGE_SIZE' ), $request_cursor );

        ob_start();
        echo '<div class="wrap eforms-submissions-admin">';
        echo '<h1>' . esc_html( 'eForms Submissions' ) . '</h1>';
        echo '<p class="description">' . esc_html( 'Currently retained photo-backed submissions. Listing-only requests and deleted history are not shown.' ) . '</p>';

        if ( empty( $page['ok'] ) ) {
            self::notice( 'error', 'Retained photo submissions could not be loaded.' );
        }

        self::render_table( $page );
        self::render_pagination( $page, ! empty( $request_cursor ) );

        echo '</div>';
        return (string) ob_get_clean();
    }

    private static function render_table( $page ) {
        $rows = isset( $page['submissions'] ) && is_array( $page['submissions'] ) ? $page['submissions'] : array();
        $columns = array( 'Submitted', 'Name / ZIP', 'Project summary', 'Photos', 'Availability', 'Actions' );

        echo '<table class="widefat striped eforms-submissions-table">';
        echo '<thead><tr>';
        foreach ( $columns as $heading ) {
            echo '<th scope="col">' . esc_html( $heading ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr class="no-items"><td colspan="' . esc_attr( (string) count( $columns ) ) . '">' . esc_html( 'No retained photo submissions found.' ) . '</td></tr>';
        }

        foreach ( $rows as $row ) {
            self::render_row( $row );
        }

        echo '</tbody></table>';
    }

    private static function render_row( $row ) {
        $summary = isset( $row['summary'] ) && is_array( $row['summary'] ) ? $row['summary'] : array();
        $availability = isset( $row['availability'] ) && is_array( $row['availability'] ) ? $row['availability'] : array();
        $view = isset( $row['view'] ) && is_array( $row['view'] ) ? $row['view'] : array();
        $submission_id = isset( $view['submission_id'] ) && is_string( $view['submission_id'] ) ? $view['submission_id'] : '';
        $view_url = $submission_id === '' ? '' : ReviewController::gallery_url( $submission_id );

        echo '<tr>';
        echo '<td>' . esc_html( self::row_string( $row, 'submitted_label' ) ) . '</td>';
        echo '<td>' . esc_html( self::name_zip_label( $summary ) ) . '</td>';
        echo '<td>' . esc_html( self::row_string( $summary, 'project_summary' ) ) . '</td>';
        echo '<td>' . esc_html( self::photo_count_label( isset( $row['photo_count'] ) ? (int) $row['photo_count'] : 0 ) ) . '</td>';
        echo '<td>' . esc_html( self::availability_label( $availability ) ) . '</td>';
        echo '<td>';
        if ( $view_url !== '' ) {
            echo '<a class="button" href="' . esc_url( $view_url ) . '">' . esc_html( 'View / Manage' ) . '</a>';
        }
        echo '</td>';
        echo '</tr>';
    }

    private static function render_pagination( $page, $has_request_cursor ) {
        if ( empty( $page['ok'] ) ) {
            return;
        }

        $cursor = isset( $page['cursor'] ) && is_array( $page['cursor'] ) ? $page['cursor'] : array();
        $shard = isset( $cursor['shard'] ) && is_string( $cursor['shard'] ) ? $cursor['shard'] : '';
        $aggregate = isset( $cursor['aggregate'] ) && is_string( $cursor['aggregate'] ) ? $cursor['aggregate'] : '';
        $has_next = ! empty( $page['reached_limit'] ) && $shard !== '' && $aggregate !== '';
        if ( ! $has_request_cursor && ! $has_next ) {
            return;
        }

        $rows = isset( $page['submissions'] ) && is_array( $page['submissions'] ) ? count( $page['submissions'] ) : 0;
        echo '<div class="tablenav bottom">';
        AdminListPagination::render(
            $rows . ( $rows === 1 ? ' item on this page' : ' items on this page' ),
            array(
                'first' => $has_request_cursor ? self::url() : null,
                'next' => $has_next ? self::url( array( 'cursor_shard' => $shard, 'cursor_submission' => $aggregate ) ) : null,
            )
        );
        echo '<br class="clear" /></div>';
    }

    private static function notice( $type, $message ) {
        $type = $type === 'error' ? 'error' : 'info';
        echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
    }

    private static function cursor_from_request( $request ) {
        $shard = self::request_token( $request, 'cursor_shard' );
        $aggregate = self::request_token( $request, 'cursor_submission' );
        if ( preg_match( '/^[0-9a-f]{' . Helpers::H2_LENGTH . '}$/', $shard ) !== 1 || preg_match( FormProtocol::managed_id_pattern(), $aggregate ) !== 1 ) {
            return array();
        }
        return array( 'shard' => $shard, 'aggregate' => $aggregate );
    }

    private static function name_zip_label( $summary ) {
        $name = self::row_string( $summary, 'name' );
        $zip = self::row_string( $summary, 'zip_us' );
        if ( $name !== '' && $zip !== '' ) {
            return $name . ' / ' . $zip;
        }
        return $name !== '' ? $name : $zip;
    }

    private static function availability_label( $availability ) {
        $label = self::row_string( $availability, 'label' );
        if ( ! empty( $availability['expired'] ) ) {
            return 'Expired';
        }
        if ( $label === '' ) {
            return '';
        }
        if ( array_key_exists( 'delete_after', $availability ) && $availability['delete_after'] === null ) {
            return 'Available until deleted';
        }
        return 'Available until ' . $label;
    }

    private static function photo_count_label( $count ) {
        $count = max( 0, (int) $count );
        return $count . ( $count === 1 ? ' photo' : ' photos' );
    }

    private static function row_string( $row, $key ) {
        return is_array( $row ) && isset( $row[ $key ] ) && is_string( $row[ $key ] ) ? $row[ $key ] : '';
    }

    private static function request_token( $request, $key ) {
        if ( ! is_array( $request ) || ! isset( $request[ $key ] ) ) {
            return '';
        }
        $value = $request[ $key ];
        if ( is_array( $value ) ) {
            return '';
        }
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }

    private static function url( $args = array() ) {
        $query = array_merge( array( 'page' => self::SLUG ), is_array( $args ) ? $args : array() );
        $base = function_exists( 'admin_url' ) ? admin_url( 'tools.php' ) : 'tools.php';
        return $base . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
    }

    private static function unslash( $value ) {
        return function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
    }

    private static function can_manage() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }
}
