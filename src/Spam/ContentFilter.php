<?php
/**
 * Local spam content-filter policy.
 *
 * Contract: Spam content filter policy.
 */

require_once __DIR__ . '/../Anchors.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Validation/FieldTypes/TextLike.php';

class ContentFilter {
    const MODE_OFF = 'off';
    const MODE_SUSPECT = 'suspect';
    const MODE_REJECT = 'reject';
    const DECISION_NONE = 'none';
    const REASON_BLOCKED_TERM = 'content_blocked_term';

    public static function evaluate( $context, $values, $config ) {
        $mode = self::mode( $config );
        $result = self::empty_result( $mode );

        if ( $mode === self::MODE_OFF ) {
            return $result;
        }

        $raw_terms = Config::value( $config, array( 'spam', 'content_filter', 'blocked_terms' ), '' );
        $parsed = self::parse_terms_text( $raw_terms );
        if ( empty( $parsed['ok'] ) || empty( $parsed['terms'] ) ) {
            return $result;
        }

        $candidates = self::candidate_values( $context, $values );
        if ( empty( $candidates ) ) {
            return $result;
        }

        $match_ids = array();
        $field_keys = array();
        foreach ( $parsed['terms'] as $term ) {
            foreach ( $candidates as $field_key => $candidate ) {
                if ( self::term_matches( $term, $candidate ) ) {
                    $match_ids[ self::match_id( $term ) ] = true;
                    $field_keys[ $field_key ] = true;
                }
            }
        }

        if ( empty( $match_ids ) ) {
            return $result;
        }

        $result['matched'] = true;
        $result['decision'] = $mode === self::MODE_REJECT ? self::MODE_REJECT : self::MODE_SUSPECT;
        $result['reason'] = self::REASON_BLOCKED_TERM;
        $result['match_ids'] = array_keys( $match_ids );
        sort( $result['match_ids'] );
        $result['field_keys'] = array_keys( $field_keys );
        sort( $result['field_keys'] );

        return $result;
    }

    public static function parse_terms_text( $text ) {
        if ( ! is_string( $text ) ) {
            return self::parse_result( false, array(), array( array( 'reason' => 'type' ) ) );
        }

        $lines = preg_split( '/\\r\\n|\\r|\\n/', $text );
        if ( ! is_array( $lines ) ) {
            $lines = array( $text );
        }

        $terms = array();
        $seen = array();
        $errors = array();
        $max_terms = self::anchor( 'CONTENT_FILTER_MAX_TERMS', 100 );
        $max_chars = self::anchor( 'CONTENT_FILTER_MAX_TERM_CHARS', 80 );

        foreach ( $lines as $line ) {
            $term = self::normalize_text( (string) $line );
            if ( $term === '' ) {
                continue;
            }

            if ( self::char_length( $term ) > $max_chars ) {
                $errors[] = array( 'reason' => 'max_chars' );
                continue;
            }

            if ( isset( $seen[ $term ] ) ) {
                $errors[] = array( 'reason' => 'duplicate' );
                continue;
            }

            $seen[ $term ] = true;
            $terms[] = $term;
        }

        if ( count( $terms ) > $max_terms ) {
            $errors[] = array( 'reason' => 'max_terms' );
        }

        if ( ! empty( $errors ) ) {
            return self::parse_result( false, array(), $errors );
        }

        return self::parse_result( true, $terms, array() );
    }

    public static function mode_values() {
        return array( self::MODE_OFF, self::MODE_SUSPECT, self::MODE_REJECT );
    }

    public static function is_enabled( $config ) {
        return self::mode( $config ) !== self::MODE_OFF;
    }

    public static function is_matched( $value ) {
        return ! empty( self::safe_metadata( $value ) );
    }

    public static function is_suspect( $value ) {
        $metadata = self::safe_metadata( $value );
        return ! empty( $metadata ) && $metadata['decision'] === self::MODE_SUSPECT;
    }

    public static function is_reject( $value ) {
        $metadata = self::safe_metadata( $value );
        return ! empty( $metadata ) && $metadata['decision'] === self::MODE_REJECT;
    }

    public static function safe_metadata( $value ) {
        if ( ! is_array( $value ) || empty( $value['matched'] ) ) {
            return array();
        }

        $reason = isset( $value['reason'] ) && $value['reason'] === self::REASON_BLOCKED_TERM
            ? self::REASON_BLOCKED_TERM
            : '';
        $decision = isset( $value['decision'] ) && in_array( $value['decision'], array( self::MODE_SUSPECT, self::MODE_REJECT ), true )
            ? $value['decision']
            : '';
        if ( $reason === '' || $decision === '' ) {
            return array();
        }

        return array(
            'matched' => true,
            'decision' => $decision,
            'reason' => $reason,
            'match_ids' => self::safe_hash_list( isset( $value['match_ids'] ) ? $value['match_ids'] : array() ),
            'field_keys' => self::safe_token_list( isset( $value['field_keys'] ) ? $value['field_keys'] : array() ),
        );
    }

    private static function empty_result( $mode ) {
        return array(
            'matched' => false,
            'mode' => $mode,
            'decision' => self::DECISION_NONE,
            'reason' => '',
            'match_ids' => array(),
            'field_keys' => array(),
        );
    }

    private static function parse_result( $ok, $terms, $errors ) {
        return array(
            'ok' => (bool) $ok,
            'terms' => $terms,
            'normalized_text' => implode( "\n", $terms ),
            'errors' => $errors,
        );
    }

    private static function mode( $config ) {
        $mode = Config::value( $config, array( 'spam', 'content_filter', 'mode' ), self::MODE_OFF );
        return in_array( $mode, self::mode_values(), true ) ? $mode : self::MODE_OFF;
    }

    private static function candidate_values( $context, $values ) {
        $values = self::extract_values( $values );
        $descriptors = is_array( $context ) && isset( $context['descriptors'] ) && is_array( $context['descriptors'] )
            ? $context['descriptors']
            : array();

        $out = array();
        foreach ( $descriptors as $descriptor ) {
            if ( ! is_array( $descriptor ) || ! self::is_text_scalar_descriptor( $descriptor ) ) {
                continue;
            }

            $key = isset( $descriptor['key'] ) && is_string( $descriptor['key'] ) ? $descriptor['key'] : '';
            if ( $key === '' || ! array_key_exists( $key, $values ) || ! is_string( $values[ $key ] ) ) {
                continue;
            }

            $candidate = self::normalize_text( $values[ $key ] );
            if ( $candidate !== '' ) {
                $out[ $key ] = $candidate;
            }
        }

        return $out;
    }

    private static function extract_values( $values ) {
        if ( is_array( $values ) && isset( $values['values'] ) && is_array( $values['values'] ) ) {
            return $values['values'];
        }

        return is_array( $values ) ? $values : array();
    }

    private static function is_text_scalar_descriptor( $descriptor ) {
        if ( ! empty( $descriptor['is_multivalue'] ) ) {
            return false;
        }

        $type = isset( $descriptor['type'] ) && is_string( $descriptor['type'] ) ? $descriptor['type'] : '';
        if ( $type === 'textarea' ) {
            return true;
        }

        if ( ! FieldTypes_TextLike::supports( $type ) ) {
            return false;
        }

        return ! in_array( $type, array( 'zip', 'zip_us', 'number', 'range', 'date' ), true );
    }

    private static function term_matches( $term, $candidate ) {
        if ( $term === '' || $candidate === '' ) {
            return false;
        }

        if ( strpos( $term, ' ' ) !== false ) {
            return strpos( $candidate, $term ) !== false;
        }

        $pattern = '/(^|[^\\p{L}\\p{N}])' . preg_quote( $term, '/' ) . '($|[^\\p{L}\\p{N}])/u';
        $matched = preg_match( $pattern, $candidate );
        if ( $matched !== false ) {
            return $matched === 1;
        }

        return preg_match( '/(^|[^A-Za-z0-9])' . preg_quote( $term, '/' ) . '($|[^A-Za-z0-9])/', $candidate ) === 1;
    }

    private static function match_id( $term ) {
        return sha1( $term );
    }

    private static function safe_hash_list( $values ) {
        if ( ! is_array( $values ) ) {
            return array();
        }

        $out = array();
        foreach ( $values as $value ) {
            if ( is_string( $value ) && preg_match( '/^[0-9a-f]{40}$/', $value ) === 1 ) {
                $out[ $value ] = true;
            }
        }

        $out = array_keys( $out );
        sort( $out );
        return $out;
    }

    private static function safe_token_list( $values ) {
        if ( ! is_array( $values ) ) {
            return array();
        }

        $out = array();
        foreach ( $values as $value ) {
            if ( ! is_string( $value ) ) {
                continue;
            }

            $value = preg_replace( '/[^A-Za-z0-9_.:-]/', '', $value );
            if ( is_string( $value ) && $value !== '' ) {
                $out[ $value ] = true;
            }
        }

        $out = array_keys( $out );
        sort( $out );
        return $out;
    }

    private static function normalize_text( $value ) {
        $value = self::lowercase( (string) $value );
        $value = self::collapse_whitespace( $value );
        $value = self::trim_punctuation( $value );
        return self::collapse_whitespace( $value );
    }

    private static function lowercase( $value ) {
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $value, 'UTF-8' );
        }

        return strtolower( $value );
    }

    private static function collapse_whitespace( $value ) {
        $value = trim( $value );
        $collapsed = preg_replace( '/\\s+/u', ' ', $value );
        if ( is_string( $collapsed ) ) {
            return $collapsed;
        }

        return preg_replace( '/\\s+/', ' ', $value );
    }

    private static function trim_punctuation( $value ) {
        $trimmed = preg_replace( '/^[\\p{P}\\p{S}\\s]+|[\\p{P}\\p{S}\\s]+$/u', '', $value );
        if ( is_string( $trimmed ) ) {
            return $trimmed;
        }

        return trim( $value, " \t\n\r\0\x0B.,;:!?\"'()[]{}<>" );
    }

    private static function char_length( $value ) {
        if ( function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $value, 'UTF-8' );
        }

        return strlen( $value );
    }

    private static function anchor( $name, $fallback ) {
        $value = Anchors::get( $name );
        return is_int( $value ) ? $value : $fallback;
    }
}
