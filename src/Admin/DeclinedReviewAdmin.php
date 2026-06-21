<?php
/**
 * WordPress admin viewer for declined-submission review records.
 *
 * Contract: Declined Review
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../DeclinedReviewLog.php';

class DeclinedReviewAdmin {
    const SLUG = 'eforms-declined';
    const ACTION_FIELD = 'eforms_declined_action';
    const CLEAR_ACTION = 'eforms_declined_clear';
    const CLEAR_NONCE_FIELD = '_eforms_declined_clear_nonce';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
    }

    public static function register_menu() {
        add_management_page(
            'eForms Declined',
            'eForms Declined',
            'manage_options',
            self::SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! self::can_manage() ) {
            wp_die( esc_html( 'Sorry, you are not allowed to access this page.' ) );
        }

        $post = null;
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) === 'POST' ) {
            $post = $_POST;
        }

        echo self::render_html( $_GET, Config::get(), $post );
    }

    public static function render_html( $request = null, $config = null, $post = null ) {
        if ( ! self::can_manage() ) {
            return '';
        }

        $request = is_array( $request ) ? $request : array();
        $config = is_array( $config ) ? $config : Config::get();
        $filters = self::filters_from_request( $request );
        $review_id = self::request_token( $request, 'review_id' );
        $clear = self::handle_post( $post, $config );
        if ( $clear['handled'] ) {
            $review_id = '';
        }

        ob_start();
        echo '<div class="wrap eforms-declined-admin">';
        echo '<h1>eForms Declined</h1>';
        if ( is_array( $clear['notice'] ) ) {
            self::notice( $clear['notice']['type'], $clear['notice']['message'] );
        }

        if ( $clear['confirm_days'] !== null ) {
            self::render_clear_confirmation( $clear['confirm_days'], $filters );
        } elseif ( $review_id !== '' ) {
            self::render_detail( $review_id, $filters, $config );
        } else {
            self::render_list( $filters, $config );
        }

        echo '</div>';
        return (string) ob_get_clean();
    }

    private static function render_list( $filters, $config ) {
        $query = DeclinedReviewLog::query( $filters, $config );
        $filters['from'] = isset( $query['from'] ) ? (string) $query['from'] : '';
        $filters['to'] = isset( $query['to'] ) ? (string) $query['to'] : '';
        self::render_filters( $filters );

        if ( ! empty( $query['limited'] ) ) {
            self::notice( 'warning', 'The scan limit was reached. Narrow the date range or filters for complete results.' );
        }

        $records = isset( $query['records'] ) && is_array( $query['records'] ) ? $query['records'] : array();
        $total = isset( $query['total'] ) ? (int) $query['total'] : 0;
        $page = isset( $query['page'] ) ? (int) $query['page'] : 1;
        $per_page = isset( $query['per_page'] ) ? (int) $query['per_page'] : 50;

        echo '<div class="tablenav top">';
        echo '<div class="tablenav-pages">' . esc_html( self::pagination_label( $total, $page, $per_page ) ) . '</div>';
        echo '<br class="clear" />';
        echo '</div>';

        $columns = array_keys( self::list_row( array(), '' ) );
        echo '<table class="widefat striped eforms-declined-table">';
        echo '<thead><tr>';
        foreach ( $columns as $heading ) {
            echo '<th scope="col">' . esc_html( $heading ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ( empty( $records ) ) {
            echo '<tr class="no-items"><td colspan="' . esc_attr( (string) count( $columns ) ) . '">' . esc_html( 'No declined submissions found for this range.' ) . '</td></tr>';
        }

        foreach ( $records as $record ) {
            self::render_row( $record, $filters );
        }

        echo '</tbody></table>';

        self::render_maintenance( $config );
    }

    private static function render_filters( $filters ) {
        echo '<form method="get" class="eforms-declined-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
        echo '<p class="search-box">';
        echo '<label for="eforms-declined-from">' . esc_html( 'From' ) . '</label> ';
        echo '<input id="eforms-declined-from" type="date" name="from" value="' . esc_attr( self::html_date( $filters['from'] ) ) . '" /> ';
        echo '<label for="eforms-declined-to">' . esc_html( 'To' ) . '</label> ';
        echo '<input id="eforms-declined-to" type="date" name="to" value="' . esc_attr( self::html_date( $filters['to'] ) ) . '" /> ';
        echo '<label for="eforms-declined-form">' . esc_html( 'Form' ) . '</label> ';
        echo '<input id="eforms-declined-form" type="search" name="form_id" value="' . esc_attr( $filters['form_id'] ) . '" /> ';
        echo '<label for="eforms-declined-decision">' . esc_html( 'Decision' ) . '</label> ';
        echo '<select id="eforms-declined-decision" name="decision_code">';
        foreach ( self::decision_options() as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '"' . ( $filters['decision_code'] === $value ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select> ';
        echo '<button type="submit" class="button">' . esc_html( 'Filter' ) . '</button>';
        echo '</p></form>';
    }

    private static function render_row( $record, $filters ) {
        $detail_url = self::url(
            array_merge(
                self::filter_query_args( $filters ),
                array(
                    'review_id' => isset( $record['review_id'] ) ? (string) $record['review_id'] : '',
                )
            )
        );

        echo '<tr>';
        foreach ( self::list_row( $record, $detail_url ) as $cell ) {
            echo '<td>' . $cell . '</td>';
        }
        echo '</tr>';
    }

    private static function render_detail( $review_id, $filters, $config ) {
        $result = DeclinedReviewLog::find( $review_id, $filters, $config );
        $back = self::url( self::filter_query_args( $filters ) );

        if ( empty( $result['found'] ) || empty( $result['record'] ) || ! is_array( $result['record'] ) ) {
            self::notice( 'warning', 'Declined review record not found in the active date range.' );
            echo '<p><a class="button" href="' . esc_url( $back ) . '">' . esc_html( 'Back to declined list' ) . '</a></p>';
            return;
        }

        $record = $result['record'];
        if ( ! empty( $result['multiple'] ) ) {
            self::notice( 'info', 'Multiple records matched this review ID; the newest one is shown.' );
        }

        echo '<p><a class="button" href="' . esc_url( $back ) . '">' . esc_html( 'Back to declined list' ) . '</a></p>';
        echo '<h2>' . esc_html( 'Declined submission detail' ) . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ( self::summary_values( $record ) as $label => $value ) {
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
        }
        echo '</tbody></table>';

        self::render_value_table( 'Submitted fields', isset( $record['fields'] ) && is_array( $record['fields'] ) ? $record['fields'] : array() );
        self::render_value_table( 'Upload metadata', isset( $record['uploads'] ) && is_array( $record['uploads'] ) ? $record['uploads'] : array() );
    }

    private static function render_value_table( $title, $values ) {
        echo '<h2>' . esc_html( $title ) . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html( 'Field' ) . '</th><th scope="col">' . esc_html( 'Value' ) . '</th></tr></thead><tbody>';
        if ( empty( $values ) ) {
            echo '<tr class="no-items"><td colspan="2">' . esc_html( 'No values captured.' ) . '</td></tr>';
        }
        foreach ( $values as $key => $value ) {
            echo '<tr><th scope="row">' . esc_html( (string) $key ) . '</th><td><pre>' . esc_html( self::value_text( $value ) ) . '</pre></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_maintenance( $config ) {
        $default_days = Config::value( $config, array( 'declined_review', 'retention_days' ), 1 );
        $default_days = DeclinedReviewLog::normalize_clear_days( $default_days );
        $default_days = $default_days === null ? 1 : $default_days;

        echo '<h2>' . esc_html( 'Maintenance' ) . '</h2>';
        echo '<p class="description">' . esc_html( 'Declined review stores bounded submitted content. Normal cleanup is controlled by declined_review.retention_days and wp eforms gc; this action immediately clears declined-review files older than the chosen cutoff.' ) . '</p>';
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="' . esc_attr( self::ACTION_FIELD ) . '" value="' . esc_attr( self::CLEAR_ACTION ) . '" />';
        self::nonce_field( self::CLEAR_ACTION, self::CLEAR_NONCE_FIELD );
        echo '<p>';
        echo '<label for="eforms-declined-clear-days">' . esc_html( 'Clear files older than days' ) . '</label> ';
        echo '<input id="eforms-declined-clear-days" type="number" min="0" max="' . esc_attr( (string) DeclinedReviewLog::max_clear_days() ) . '" name="older_than_days" value="' . esc_attr( (string) $default_days ) . '" /> ';
        echo '<span class="description">' . esc_html( 'Use 0 to clear all declined-review files.' ) . '</span>';
        echo '</p>';
        echo '<p class="submit"><button type="submit" class="button">' . esc_html( 'Clear declined review data' ) . '</button></p>';
        echo '</form>';
    }

    private static function render_clear_confirmation( $days, $filters ) {
        $back = self::url( self::filter_query_args( $filters ) );
        $summary = (int) $days === 0
            ? 'This will permanently clear all declined-review files.'
            : 'This will permanently clear declined-review files older than ' . (int) $days . ' days.';

        echo '<h2>' . esc_html( 'Confirm declined review cleanup' ) . '</h2>';
        self::notice( 'warning', $summary . ' This cannot be undone.' );
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="' . esc_attr( self::ACTION_FIELD ) . '" value="' . esc_attr( self::CLEAR_ACTION ) . '" />';
        echo '<input type="hidden" name="older_than_days" value="' . esc_attr( (string) (int) $days ) . '" />';
        self::nonce_field( self::CLEAR_ACTION, self::CLEAR_NONCE_FIELD );
        echo '<p><label><input type="checkbox" name="confirm_clear" value="1" /> ' . esc_html( 'I understand this permanently deletes declined-review files.' ) . '</label></p>';
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html( 'Permanently clear declined review data' ) . '</button> ';
        echo '<a class="button" href="' . esc_url( $back ) . '">' . esc_html( 'Cancel' ) . '</a></p>';
        echo '</form>';
    }

    private static function handle_post( $post, $config ) {
        $result = array( 'handled' => false, 'notice' => null, 'confirm_days' => null );
        if ( ! is_array( $post ) ) {
            return $result;
        }

        $post = self::unslash( $post );
        $action = self::post_token( $post, self::ACTION_FIELD );
        if ( $action !== self::CLEAR_ACTION ) {
            return $result;
        }

        $result['handled'] = true;
        if ( ! self::verify_nonce( self::post_token( $post, self::CLEAR_NONCE_FIELD ), self::CLEAR_ACTION ) ) {
            $result['notice'] = array( 'type' => 'error', 'message' => 'Declined review data was not cleared because the security check failed.' );
            return $result;
        }

        $days = self::days_from_post( $post );
        if ( $days === null ) {
            $result['notice'] = array(
                'type' => 'error',
                'message' => 'Enter a whole number of days between 0 and ' . DeclinedReviewLog::max_clear_days() . '.',
            );
            return $result;
        }

        if ( self::post_token( $post, 'confirm_clear' ) !== '1' ) {
            $result['confirm_days'] = $days;
            return $result;
        }

        $clear = DeclinedReviewLog::clear_older_than( $days, $config );
        $type = ! empty( $clear['ok'] ) ? 'success' : 'error';
        $result['notice'] = array(
            'type' => $type,
            'message' => 'Declined review cleanup complete: ' . (int) $clear['deleted'] . ' deleted, ' . (int) $clear['failed'] . ' failed, ' . (int) $clear['scanned'] . ' scanned.',
        );

        return $result;
    }

    private static function filters_from_request( $request ) {
        return array(
            'from' => self::request_token( $request, 'from' ),
            'to' => self::request_token( $request, 'to' ),
            'form_id' => self::request_token( $request, 'form_id' ),
            'decision_code' => self::request_token( $request, 'decision_code' ),
            'page' => self::request_int( $request, 'paged', 1 ),
        );
    }

    private static function list_row( $record, $detail_url ) {
        $values = self::summary_values( $record );
        return array(
            'Time' => '<a href="' . esc_url( $detail_url ) . '">' . esc_html( $values['Time'] ) . '</a>',
            'Form' => esc_html( $values['Form'] ),
            'Decision' => esc_html( $values['Decision'] ),
            'Reasons' => esc_html( $values['Reasons'] ),
            'IP' => esc_html( $values['IP'] ),
            'Field preview' => esc_html( self::field_preview( $record ) ),
            'Request ID' => esc_html( $values['Request ID'] ),
        );
    }

    private static function summary_values( $record ) {
        return array(
            'Time' => self::record_value( $record, 'ts' ),
            'Form' => self::record_value( $record, 'form_id' ),
            'Decision' => self::record_value( $record, 'decision_code' ),
            'Phase' => self::record_value( $record, 'decision_phase' ),
            'Value stage' => self::record_value( $record, 'value_stage' ),
            'Submission ID' => self::record_value( $record, 'submission_id' ),
            'Request ID' => self::record_value( $record, 'request_id' ),
            'IP' => self::record_value( $record, 'ip' ),
            'URI' => self::record_value( $record, 'uri' ),
            'Reasons' => self::reason_summary( $record ),
        );
    }

    private static function reason_summary( $record ) {
        if ( isset( $record['soft_reasons'] ) && is_array( $record['soft_reasons'] ) && ! empty( $record['soft_reasons'] ) ) {
            return implode( ', ', array_map( 'strval', $record['soft_reasons'] ) );
        }
        if ( ! empty( $record['honeypot'] ) ) {
            return 'honeypot';
        }
        if ( isset( $record['challenge'] ) && is_array( $record['challenge'] ) && ! empty( $record['challenge']['error_code'] ) ) {
            return (string) $record['challenge']['error_code'];
        }
        return '';
    }

    private static function field_preview( $record ) {
        $fields = isset( $record['fields'] ) && is_array( $record['fields'] ) ? $record['fields'] : array();
        $parts = array();
        foreach ( $fields as $key => $value ) {
            $parts[] = (string) $key . ': ' . self::excerpt( self::value_text( $value ), 80 );
            if ( count( $parts ) >= 2 ) {
                break;
            }
        }
        return implode( '; ', $parts );
    }

    private static function value_text( $value ) {
        if ( is_scalar( $value ) || $value === null ) {
            return (string) $value;
        }

        $encoded = wp_json_encode( $value );
        return is_string( $encoded ) ? $encoded : '';
    }

    private static function excerpt( $value, $max ) {
        $value = is_string( $value ) ? preg_replace( '/\\s+/', ' ', trim( $value ) ) : '';
        $max = max( 1, (int) $max );
        if ( strlen( $value ) <= $max ) {
            return $value;
        }
        return substr( $value, 0, max( 0, $max - 3 ) ) . '...';
    }

    private static function record_value( $record, $key ) {
        if ( is_array( $record ) && isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ) {
            return (string) $record[ $key ];
        }
        return '';
    }

    private static function filter_query_args( $filters ) {
        $args = array();
        foreach ( array( 'from', 'to', 'form_id', 'decision_code' ) as $key ) {
            if ( isset( $filters[ $key ] ) && is_string( $filters[ $key ] ) && $filters[ $key ] !== '' ) {
                $args[ $key ] = $key === 'from' || $key === 'to' ? self::html_date( $filters[ $key ] ) : $filters[ $key ];
            }
        }
        if ( isset( $filters['page'] ) && (int) $filters['page'] > 1 ) {
            $args['paged'] = (int) $filters['page'];
        }
        return $args;
    }

    private static function url( $args ) {
        $args = is_array( $args ) ? $args : array();
        $args = array_merge( array( 'page' => self::SLUG ), $args );
        $base = admin_url( 'tools.php' );
        return $base . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
    }

    private static function pagination_label( $total, $page, $per_page ) {
        $total = max( 0, (int) $total );
        if ( $total === 0 ) {
            return '0 items';
        }
        $start = ( ( max( 1, (int) $page ) - 1 ) * max( 1, (int) $per_page ) ) + 1;
        $end = min( $total, $start + max( 1, (int) $per_page ) - 1 );
        return $start . '-' . $end . ' of ' . $total . ' items';
    }

    private static function decision_options() {
        return array(
            '' => 'Any',
            'EFORMS_ERR_HONEYPOT' => 'Honeypot',
            'EFORMS_ERR_SPAM' => 'Spam threshold',
            'EFORMS_ERR_CHALLENGE_FAILED' => 'Challenge failed',
        );
    }

    private static function html_date( $ymd ) {
        $ymd = is_string( $ymd ) ? $ymd : '';
        if ( preg_match( '/^[0-9]{8}$/', $ymd ) === 1 ) {
            return substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 );
        }
        return '';
    }

    private static function notice( $type, $message ) {
        $type = in_array( $type, array( 'info', 'warning', 'error', 'success' ), true ) ? $type : 'info';
        echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
    }

    private static function request_token( $request, $key ) {
        if ( ! is_array( $request ) || ! isset( $request[ $key ] ) ) {
            return '';
        }

        return self::scalar_token( $request[ $key ], true );
    }

    private static function request_int( $request, $key, $fallback ) {
        $value = self::request_token( $request, $key );
        return is_numeric( $value ) ? max( 1, (int) $value ) : (int) $fallback;
    }

    private static function post_token( $post, $key ) {
        if ( ! is_array( $post ) || ! isset( $post[ $key ] ) ) {
            return '';
        }

        return self::scalar_token( $post[ $key ], false );
    }

    private static function scalar_token( $value, $unslash ) {
        if ( $unslash ) {
            $value = wp_unslash( $value );
        }
        if ( is_array( $value ) ) {
            return '';
        }
        $value = is_scalar( $value ) ? trim( (string) $value ) : '';
        $value = sanitize_text_field( $value );
        return is_string( $value ) ? substr( $value, 0, 128 ) : '';
    }

    private static function days_from_post( $post ) {
        $value = self::post_token( $post, 'older_than_days' );
        return DeclinedReviewLog::normalize_clear_days( $value );
    }

    private static function nonce_field( $action, $field ) {
        if ( function_exists( 'wp_nonce_field' ) ) {
            wp_nonce_field( $action, $field );
            return;
        }
        echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="" />';
    }

    private static function verify_nonce( $nonce, $action ) {
        return function_exists( 'wp_verify_nonce' ) && wp_verify_nonce( $nonce, $action );
    }

    private static function unslash( $value ) {
        return function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
    }

    private static function can_manage() {
        return current_user_can( 'manage_options' );
    }
}
