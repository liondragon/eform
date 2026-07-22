<?php
/**
 * Operator review snapshot for finalized managed-photo submissions.
 *
 * Contract: Operator review snapshot
 * Contract: Managed Aggregate Contract
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Uploads/UploadValue.php';
require_once __DIR__ . '/../Validation/FieldTypes/TextLike.php';

class SubmissionReviewSnapshot {
    const SCHEMA_VERSION = 1;

    private static $header_keys = array( 'name', 'zip_us' );
    private static $operator_row_keys = array( 'email', 'tel_us', 'project_description', 'square_footage', 'listing_url' );
    private static $public_row_keys_by_form = array(
        'virtual-estimate' => array( 'project_description', 'square_footage' ),
    );

    public static function build( $context, $values, $submission_id, $submitted_at ) {
        if ( ! is_array( $context ) || ! is_array( $values ) || ! is_string( $submission_id ) || $submission_id === '' || ! is_string( $submitted_at ) || $submitted_at === '' ) {
            return array( 'ok' => false, 'reason' => 'invalid_input' );
        }

        $form_id = isset( $context['id'] ) && is_string( $context['id'] ) ? $context['id'] : '';
        $template_version = isset( $context['version'] ) && is_string( $context['version'] ) ? $context['version'] : '';
        if ( $form_id === '' || $template_version === '' ) {
            return array( 'ok' => false, 'reason' => 'context_missing_identity' );
        }

        $display_format = self::display_format( $context );
        $display_values = self::display_values( $context, $values, $display_format );
        $fields = isset( $display_values['fields'] ) && is_array( $display_values['fields'] ) ? $display_values['fields'] : array();

        $snapshot = array(
            'schema_version' => self::SCHEMA_VERSION,
            'form_id' => $form_id,
            'template_version' => $template_version,
            'submission_id' => $submission_id,
            'submitted_at' => $submitted_at,
            'title' => self::title( $form_id ),
            'header' => self::bounded_sidecar_rows( self::display_rows( $context, $fields, self::$header_keys, array(), array(), true, true ) ),
            'operator_rows' => self::bounded_sidecar_rows( self::display_rows( $context, $fields, self::$operator_row_keys, array(), array(), true, true ) ),
        );

        return array( 'ok' => true, 'snapshot' => $snapshot );
    }

    public static function validate( $snapshot ) {
        if ( ! is_array( $snapshot ) ) {
            return array( 'ok' => false, 'reason' => 'not_object' );
        }

        $allowed = array( 'schema_version', 'form_id', 'template_version', 'submission_id', 'submitted_at', 'title', 'header', 'operator_rows' );
        if ( array_diff( array_keys( $snapshot ), $allowed ) !== array() ) {
            return array( 'ok' => false, 'reason' => 'unknown_field' );
        }

        $required_strings = array( 'form_id', 'template_version', 'submission_id', 'submitted_at', 'title' );
        if ( ! isset( $snapshot['schema_version'] ) || $snapshot['schema_version'] !== self::SCHEMA_VERSION ) {
            return array( 'ok' => false, 'reason' => 'schema_version' );
        }
        foreach ( $required_strings as $key ) {
            if ( ! isset( $snapshot[ $key ] ) || ! is_string( $snapshot[ $key ] ) || $snapshot[ $key ] === '' ) {
                return array( 'ok' => false, 'reason' => 'field_' . $key );
            }
        }
        if ( ! isset( $snapshot['header'] ) || ! is_array( $snapshot['header'] ) || ! self::valid_sidecar_rows( $snapshot['header'], self::$header_keys ) ) {
            return array( 'ok' => false, 'reason' => 'rows_header' );
        }
        if ( ! isset( $snapshot['operator_rows'] ) || ! is_array( $snapshot['operator_rows'] ) || ! self::valid_sidecar_rows( $snapshot['operator_rows'], self::$operator_row_keys ) ) {
            return array( 'ok' => false, 'reason' => 'rows_operator_rows' );
        }

        return array( 'ok' => true, 'snapshot' => $snapshot );
    }

    public static function summary( $snapshot ) {
        $validated = self::validate( $snapshot );
        if ( empty( $validated['ok'] ) ) {
            return $validated;
        }

        $snapshot = $validated['snapshot'];
        return array(
            'ok' => true,
            'summary' => array(
                'title' => $snapshot['title'],
                'name' => self::row_value( $snapshot['header'], 'name' ),
                'zip_us' => self::row_value( $snapshot['header'], 'zip_us' ),
                'project_summary' => self::row_value( $snapshot['operator_rows'], 'project_description' ),
            ),
        );
    }

    public static function public_summary( $snapshot ) {
        $validated = self::validate( $snapshot );
        if ( empty( $validated['ok'] ) ) {
            return $validated;
        }

        $snapshot = $validated['snapshot'];
        $public_row_keys = isset( self::$public_row_keys_by_form[ $snapshot['form_id'] ] )
            ? self::$public_row_keys_by_form[ $snapshot['form_id'] ]
            : array();
        return array(
            'ok' => true,
            'summary' => array(
                'details' => self::rows_for_keys( $snapshot['operator_rows'], $public_row_keys ),
            ),
        );
    }

    public static function operator_review( $snapshot ) {
        $validated = self::validate( $snapshot );
        if ( empty( $validated['ok'] ) ) {
            return $validated;
        }

        $snapshot = $validated['snapshot'];
        return array(
            'ok' => true,
            'review' => array(
                'title' => $snapshot['title'],
                'header' => $snapshot['header'],
                'details' => $snapshot['operator_rows'],
            ),
        );
    }

    public static function display_values( $context, $values, $display_format = '' ) {
        $fields = array();
        $uploads = array();
        $descriptors = is_array( $context ) && isset( $context['descriptors'] ) && is_array( $context['descriptors'] )
            ? $context['descriptors']
            : array();
        $values = is_array( $values ) ? $values : array();

        foreach ( $descriptors as $descriptor ) {
            if ( ! is_array( $descriptor ) ) {
                continue;
            }

            $key = isset( $descriptor['key'] ) && is_string( $descriptor['key'] ) ? $descriptor['key'] : '';
            if ( $key === '' ) {
                continue;
            }

            $type = isset( $descriptor['type'] ) && is_string( $descriptor['type'] ) ? $descriptor['type'] : '';
            $value = array_key_exists( $key, $values ) ? $values[ $key ] : null;

            if ( $type === 'file' || $type === 'files' ) {
                $staged_items = UploadValue::staged_items( $value );
                if ( ! empty( $staged_items ) ) {
                    $count = count( $staged_items );
                    $fields[ $key ] = $count . ( $count === 1 ? ' photo' : ' photos' );
                    $uploads[ $key ] = array();
                    continue;
                }
                $names = self::upload_names( $value );
                $fields[ $key ] = implode( ', ', $names );
                $uploads[ $key ] = self::upload_entries( $names );
                continue;
            }

            $fields[ $key ] = self::stringify_value( $value, $type, $display_format );
        }

        $fields['_uploads'] = $uploads;

        return array(
            'fields' => $fields,
            'uploads' => $uploads,
        );
    }

    public static function display_rows( $context, $canonical, $include_fields, $meta = array(), $galleries = array(), $omit_empty = false, $rich_types = false ) {
        $rows = array();
        $labels = self::field_labels( $context );
        $canonical = is_array( $canonical ) ? $canonical : array();
        $include_fields = is_array( $include_fields ) ? $include_fields : array();
        $meta = is_array( $meta ) ? $meta : array();
        $galleries = is_array( $galleries ) ? $galleries : array();

        foreach ( $include_fields as $key ) {
            if ( ! is_string( $key ) || $key === '' ) {
                continue;
            }

            if ( isset( $canonical['_uploads'][ $key ] ) ) {
                $names = array_column( $canonical['_uploads'][ $key ], 'original_name_safe' );
                $value = implode( ', ', $names );
            } else {
                $value = isset( $canonical[ $key ] ) ? $canonical[ $key ] : ( isset( $meta[ $key ] ) ? $meta[ $key ] : '' );
            }

            if ( isset( $galleries[ $key ] ) && is_array( $galleries[ $key ] ) ) {
                $gallery = $galleries[ $key ];
                $count = isset( $gallery['count'] ) ? (int) $gallery['count'] : 0;
                $rows[] = array(
                    'key' => $key,
                    'label' => self::display_label( $key, $labels ),
                    'value' => $count . ( $count === 1 ? ' photo' : ' photos' ),
                    'type' => 'gallery',
                    'url' => isset( $gallery['url'] ) ? (string) $gallery['url'] : '',
                    'available_label' => isset( $gallery['available_label'] ) ? (string) $gallery['available_label'] : '',
                );
                continue;
            }

            $string_value = is_scalar( $value ) ? (string) $value : '';
            if ( $omit_empty && $string_value === '' ) {
                continue;
            }

            $rows[] = array(
                'key' => $key,
                'label' => self::display_label( $key, $labels ),
                'value' => $string_value,
                'type' => $rich_types ? self::row_type( $key ) : self::email_row_type( $key ),
            );
        }

        return $rows;
    }

    private static function display_format( $context ) {
        $email = isset( $context['email'] ) && is_array( $context['email'] ) ? $context['email'] : array();
        return isset( $email['display_format_tel'] ) && is_string( $email['display_format_tel'] ) ? $email['display_format_tel'] : '';
    }

    private static function title( $form_id ) {
        if ( $form_id === 'virtual-estimate' ) {
            return 'Virtual Estimate Request';
        }
        return 'Submission Request';
    }

    private static function stringify_value( $value, $type, $display_format ) {
        if ( $value === null ) {
            return '';
        }

        if ( is_array( $value ) ) {
            $parts = array();
            foreach ( $value as $entry ) {
                $parts[] = self::stringify_value( $entry, $type, $display_format );
            }
            return implode( ', ', array_filter( $parts, 'strlen' ) );
        }

        if ( ! is_string( $value ) ) {
            return is_scalar( $value ) ? (string) $value : '';
        }

        if ( $type === 'tel' || $type === 'tel_us' ) {
            return FieldTypes_TextLike::format_tel_us( $value, $display_format );
        }

        return $value;
    }

    private static function upload_names( $value ) {
        $names = array();
        foreach ( UploadValue::items( $value, true ) as $item ) {
            $name = UploadValue::display_name( $item );
            if ( $name !== '' ) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function upload_entries( $names ) {
        $entries = array();
        foreach ( $names as $name ) {
            $entries[] = array( 'original_name_safe' => $name );
        }
        return $entries;
    }

    private static function field_labels( $context ) {
        $labels = array();
        $fields = is_array( $context ) && isset( $context['fields'] ) && is_array( $context['fields'] ) ? $context['fields'] : array();

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) || ! isset( $field['key'] ) || ! is_string( $field['key'] ) ) {
                continue;
            }

            if ( isset( $field['label'] ) && is_string( $field['label'] ) && trim( $field['label'] ) !== '' ) {
                $labels[ $field['key'] ] = trim( $field['label'] );
            }
        }

        return $labels;
    }

    private static function display_label( $key, $labels ) {
        $known = array(
            'name' => 'Name',
            'email' => 'Email',
            'tel_us' => 'Phone',
            'phone' => 'Phone',
            'zip_us' => 'Zip Code',
            'zip' => 'Zip Code',
            'message' => 'Message',
            'ip' => 'Sent from',
            'submitted_at' => 'Submitted',
            'form_id' => 'Form',
            'submission_id' => 'Submission',
        );

        if ( isset( $known[ $key ] ) ) {
            return $known[ $key ];
        }

        if ( is_array( $labels ) && isset( $labels[ $key ] ) && $labels[ $key ] !== '' ) {
            return $labels[ $key ];
        }

        return ucwords( str_replace( '_', ' ', $key ) );
    }

    private static function row_type( $key ) {
        if ( $key === 'email' ) {
            return 'email';
        }
        if ( $key === 'tel_us' || $key === 'phone' ) {
            return 'tel';
        }
        if ( $key === 'listing_url' ) {
            return 'url';
        }
        return 'text';
    }

    private static function email_row_type( $key ) {
        return $key === 'email' ? 'email' : 'text';
    }

    private static function row_value( $rows, $key ) {
        if ( ! is_array( $rows ) || ! is_string( $key ) || $key === '' ) {
            return '';
        }
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && isset( $row['key'], $row['value'] ) && $row['key'] === $key && is_string( $row['value'] ) ) {
                return $row['value'];
            }
        }
        return '';
    }

    private static function rows_for_keys( $rows, $keys ) {
        $out = array();
        if ( ! is_array( $rows ) || ! is_array( $keys ) ) {
            return $out;
        }
        foreach ( $keys as $key ) {
            if ( ! is_string( $key ) || $key === '' ) {
                continue;
            }
            foreach ( $rows as $row ) {
                if ( is_array( $row ) && isset( $row['key'] ) && $row['key'] === $key ) {
                    $out[] = $row;
                    break;
                }
            }
        }
        return $out;
    }

    private static function bounded_sidecar_rows( $rows ) {
        $out = array();
        if ( ! is_array( $rows ) ) {
            return $out;
        }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            if ( isset( $row['label'] ) && is_string( $row['label'] ) ) {
                $row['label'] = self::truncate_bytes( $row['label'], Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_LABEL_MAX_BYTES' ) );
            }
            if ( isset( $row['value'] ) && is_string( $row['value'] ) ) {
                $row['value'] = self::truncate_bytes( $row['value'], Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_VALUE_MAX_BYTES' ) );
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function truncate_bytes( $value, $max_bytes ) {
        if ( ! is_string( $value ) || ! is_int( $max_bytes ) || $max_bytes <= 0 || strlen( $value ) <= $max_bytes ) {
            return is_string( $value ) ? $value : '';
        }
        if ( function_exists( 'mb_strcut' ) ) {
            return mb_strcut( $value, 0, $max_bytes, 'UTF-8' );
        }
        $out = substr( $value, 0, $max_bytes );
        while ( $out !== '' && @preg_match( '//u', $out ) !== 1 ) {
            $out = substr( $out, 0, -1 );
        }
        return $out;
    }

    private static function valid_sidecar_rows( $rows, $allowed_keys ) {
        if ( ! self::sequential_list( $rows ) || ! is_array( $allowed_keys ) ) {
            return false;
        }
        $seen = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                return false;
            }
            if ( ! self::exact_row_keys( $row ) ) {
                return false;
            }
            foreach ( array( 'key', 'label', 'value', 'type' ) as $key ) {
                if ( ! isset( $row[ $key ] ) || ! is_string( $row[ $key ] ) || $row[ $key ] === '' ) {
                    return false;
                }
            }
            if ( strlen( $row['label'] ) > Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_LABEL_MAX_BYTES' )
                || strlen( $row['value'] ) > Anchors::get( 'SUBMISSION_REVIEW_SNAPSHOT_VALUE_MAX_BYTES' )
            ) {
                return false;
            }
            if ( ! in_array( $row['key'], $allowed_keys, true ) || isset( $seen[ $row['key'] ] ) ) {
                return false;
            }
            if ( $row['type'] !== self::row_type( $row['key'] ) ) {
                return false;
            }
            $seen[ $row['key'] ] = true;
        }
        return true;
    }

    private static function sequential_list( $rows ) {
        if ( ! is_array( $rows ) ) {
            return false;
        }
        $index = 0;
        foreach ( array_keys( $rows ) as $key ) {
            if ( $key !== $index ) {
                return false;
            }
            $index++;
        }
        return true;
    }

    private static function exact_row_keys( $row ) {
        if ( ! is_array( $row ) ) {
            return false;
        }
        $keys = array_keys( $row );
        sort( $keys, SORT_STRING );
        return $keys === array( 'key', 'label', 'type', 'value' );
    }
}
