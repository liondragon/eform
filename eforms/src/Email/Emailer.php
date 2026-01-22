<?php
/**
 * Email delivery core.
 *
 * Educational note: this assembles headers and bodies deterministically and
 * delegates transport to wp_mail() without retries or SMTP customization.
 *
 * Spec: Email delivery (docs/Canonical_Spec.md#sec-email)
 * Spec: Email templates (docs/Canonical_Spec.md#sec-email-templates)
 * Spec: Email-failure recovery (docs/Canonical_Spec.md#sec-email-failure-recovery)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Helpers.php';
require_once __DIR__ . '/../Validation/FieldTypes/TextLike.php';
require_once __DIR__ . '/Templates.php';
if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}

class Emailer {
    const HEADER_MAX_BYTES = 255;

    private static $hooked = false;
    private static $last_context = array();

    /**
     * Send an email for the given submission.
     *
     * @param array $context TemplateContext array.
     * @param array $values Coerced canonical values.
     * @param array $security Security result with submission_id/mode.
     * @param mixed $request Optional request object/array.
     * @param array $config Frozen config snapshot.
     * @return array { ok, reason?, transport?, error_class?, error_message?, subject?, body?, headers?, to? }
     */
    public static function send( $context, $values, $security, $request, $config ) {
        $config = is_array( $config ) ? $config : Config::get();
        $email = is_array( $context ) && isset( $context['email'] ) && is_array( $context['email'] )
            ? $context['email']
            : array();

        $form_id = is_array( $context ) && isset( $context['id'] ) ? $context['id'] : '';
        $submission_id = is_array( $security ) && isset( $security['submission_id'] ) ? $security['submission_id'] : '';

        $to_list = self::normalize_to( isset( $email['to'] ) ? $email['to'] : null );
        if ( empty( $to_list ) ) {
            return self::failure( 'to_missing' );
        }

        $subject_tpl = self::sanitize_scalar( isset( $email['subject'] ) ? $email['subject'] : '' );
        if ( $subject_tpl === '' ) {
            return self::failure( 'subject_missing' );
        }

        $template_name = self::sanitize_scalar( isset( $email['email_template'] ) ? $email['email_template'] : '' );
        if ( $template_name === '' ) {
            return self::failure( 'template_missing' );
        }

        $display_format = '';
        if ( isset( $email['display_format_tel'] ) && is_string( $email['display_format_tel'] ) ) {
            $display_format = $email['display_format_tel'];
        }

        $meta = self::build_meta( $form_id, $submission_id, $security, $request, $config );
        $values = is_array( $values ) ? $values : array();
        $canonical = self::build_email_values( $context, $values, $display_format );
        $include_fields = self::include_fields_list( $email );

        $tokens = self::token_map( $canonical['fields'], $meta );
        $subject = self::expand_tokens( $subject_tpl, $tokens );
        $subject = self::sanitize_header_text( $subject );
        $subject = self::truncate_header_text( $subject, self::HEADER_MAX_BYTES );
        if ( $subject === '' ) {
            return self::failure( 'subject_empty' );
        }

        $soft_fail_count = self::soft_fail_count( $security );
        if ( self::is_suspect( $soft_fail_count, $config ) ) {
            $subject = self::apply_suspect_subject_tag( $subject, $config );
            if ( $subject === '' ) {
                return self::failure( 'subject_empty' );
            }
        }

        $is_html = self::config_bool( $config, array( 'email', 'html' ), false );
        $template_data = array(
            'canonical' => $canonical['fields'],
            'include_fields' => $include_fields,
            'meta' => $meta,
            'uploads' => $canonical['uploads'],
        );

        $rendered = Templates::render( $template_name, $is_html, $template_data );
        if ( ! is_array( $rendered ) || empty( $rendered['ok'] ) ) {
            return self::failure( 'template_render_failed', $rendered );
        }

        $body = self::expand_tokens( $rendered['body'], $tokens );
        $body = self::normalize_body( $body, $is_html );

        $headers = self::build_headers( $config, $values, $canonical['fields'], $email, $request );
        if ( $headers === null ) {
            return self::failure( 'headers_invalid' );
        }

        self::register_failed_hook();
        self::$last_context = array(
            'form_id' => is_string( $form_id ) ? $form_id : '',
            'submission_id' => is_string( $submission_id ) ? $submission_id : '',
            'request' => $request,
        );

        $ok = false;
        $error_class = '';
        $error_message = '';

        try {
            if ( function_exists( 'wp_mail' ) ) {
                $ok = (bool) wp_mail( $to_list, $subject, $body, $headers, array() );
            }
        } catch ( Throwable $exception ) {
            $error_class = get_class( $exception );
            $error_message = $exception->getMessage();
            $ok = false;
        }

        if ( ! $ok ) {
            return self::failure( 'wp_mail_failed', array(
                'transport' => 'wp_mail',
                'error_class' => $error_class,
                'error_message' => $error_message,
                'subject' => $subject,
                'to' => $to_list,
                'headers' => $headers,
                'body' => $body,
            ) );
        }

        return array(
            'ok' => true,
            'transport' => 'wp_mail',
            'subject' => $subject,
            'body' => $body,
            'headers' => $headers,
            'to' => $to_list,
        );
    }

    /**
     * Build a plain-text summary for the email-failure copy textarea.
     */
    public static function build_copy_summary( $context, $values, $security, $request, $config ) {
        $email = is_array( $context ) && isset( $context['email'] ) && is_array( $context['email'] )
            ? $context['email']
            : array();

        $form_id = is_array( $context ) && isset( $context['id'] ) ? $context['id'] : '';
        $submission_id = is_array( $security ) && isset( $security['submission_id'] ) ? $security['submission_id'] : '';

        $display_format = '';
        if ( isset( $email['display_format_tel'] ) && is_string( $email['display_format_tel'] ) ) {
            $display_format = $email['display_format_tel'];
        }

        $meta = self::build_meta( $form_id, $submission_id, $security, $request, $config );
        $values = is_array( $values ) ? $values : array();
        $canonical = self::build_email_values( $context, $values, $display_format );
        $include_fields = self::include_fields_list( $email );

        $lines = array();
        $lines[] = 'Form: ' . $meta['form_id'];
        $lines[] = 'Submission: ' . $meta['submission_id'];
        $lines[] = 'Submitted: ' . $meta['submitted_at'];
        $lines[] = '';

        foreach ( $include_fields as $key ) {
            if ( isset( $canonical['uploads'][ $key ] ) ) {
                $names = array();
                foreach ( $canonical['uploads'][ $key ] as $entry ) {
                    if ( is_array( $entry ) && isset( $entry['original_name_safe'] ) ) {
                        $names[] = $entry['original_name_safe'];
                    }
                }
                $value = implode( ', ', $names );
            } else {
                $value = isset( $canonical['fields'][ $key ] ) ? $canonical['fields'][ $key ] : '';
                if ( $value === '' && isset( $meta[ $key ] ) ) {
                    $value = $meta[ $key ];
                }
            }

            $lines[] = $key . ': ' . $value;
        }

        return implode( "\n", $lines );
    }

    public static function handle_wp_mail_failed( $wp_error ) {
        if ( ! is_object( $wp_error ) || ! method_exists( $wp_error, 'get_error_message' ) ) {
            return;
        }

        $meta = array(
            'form_id' => isset( self::$last_context['form_id'] ) ? self::$last_context['form_id'] : '',
            'submission_id' => isset( self::$last_context['submission_id'] ) ? self::$last_context['submission_id'] : '',
            'transport' => 'wp_mail_failed',
            'error_class' => get_class( $wp_error ),
            'error_message' => $wp_error->get_error_message(),
        );

        Logging::event( 'error', 'EFORMS_ERR_EMAIL_SEND', $meta, isset( self::$last_context['request'] ) ? self::$last_context['request'] : null );
    }

    private static function register_failed_hook() {
        if ( self::$hooked ) {
            return;
        }

        self::$hooked = true;

        if ( function_exists( 'add_action' ) ) {
            add_action( 'wp_mail_failed', array( 'Emailer', 'handle_wp_mail_failed' ), 10, 1 );
        }
    }

    private static function build_meta( $form_id, $submission_id, $security, $request, $config ) {
        $submitted_at = gmdate( 'c' );
        $ip = self::present_ip( self::resolve_client_ip( $request ), $config );
        if ( $ip === '' ) {
            $ip = '';
        }

        return array(
            'submitted_at' => $submitted_at,
            'ip' => $ip,
            'form_id' => is_string( $form_id ) ? $form_id : '',
            'submission_id' => is_string( $submission_id ) ? $submission_id : '',
        );
    }

    private static function build_email_values( $context, $values, $display_format ) {
        $fields = array();
        $uploads = array();
        $descriptors = array();

        if ( is_array( $context ) && isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ) {
            $descriptors = $context['descriptors'];
        }

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
            if ( is_scalar( $value ) ) {
                return (string) $value;
            }
            return '';
        }

        if ( $type === 'tel' || $type === 'tel_us' ) {
            return FieldTypes_TextLike::format_tel_us( $value, $display_format );
        }

        return $value;
    }

    private static function upload_names( $value ) {
        $names = array();
        if ( is_array( $value ) ) {
            foreach ( $value as $entry ) {
                if ( is_array( $entry ) && isset( $entry['original_name_safe'] ) ) {
                    $names[] = $entry['original_name_safe'];
                }
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

    private static function include_fields_list( $email ) {
        if ( ! is_array( $email ) || ! isset( $email['include_fields'] ) || ! is_array( $email['include_fields'] ) ) {
            return array();
        }

        $out = array();
        foreach ( $email['include_fields'] as $entry ) {
            if ( is_string( $entry ) ) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private static function token_map( $fields, $meta ) {
        $tokens = array();
        if ( is_array( $fields ) ) {
            foreach ( $fields as $key => $value ) {
                if ( $key === '_uploads' ) {
                    continue;
                }
                $tokens[ 'field.' . $key ] = is_string( $value ) ? $value : '';
            }
        }

        if ( is_array( $meta ) ) {
            foreach ( $meta as $key => $value ) {
                if ( is_string( $value ) ) {
                    $tokens[ $key ] = $value;
                }
            }
        }

        return $tokens;
    }

    private static function expand_tokens( $text, $tokens ) {
        if ( ! is_string( $text ) || $text === '' ) {
            return '';
        }

        if ( ! is_array( $tokens ) || empty( $tokens ) ) {
            return $text;
        }

        return preg_replace_callback(
            '/\{\{\s*([^}]+)\s*\}\}/',
            function ( $matches ) use ( $tokens ) {
                $key = isset( $matches[1] ) ? trim( $matches[1] ) : '';
                if ( $key === '' ) {
                    return '';
                }
                return isset( $tokens[ $key ] ) ? $tokens[ $key ] : '';
            },
            $text
        );
    }

    private static function normalize_body( $body, $is_html ) {
        if ( ! is_string( $body ) ) {
            return '';
        }

        if ( $is_html ) {
            return $body;
        }

        $body = str_replace( array( "\r\n", "\r" ), "\n", $body );
        return $body;
    }

    private static function build_headers( $config, $values, $canonical_fields, $email, $request ) {
        $headers = array();

        $from = self::resolve_from_address( $config, $request );
        if ( $from === '' ) {
            return null;
        }

        $from_name = self::resolve_from_name();
        $from_name = self::sanitize_header_text( $from_name );
        $from_name = self::truncate_header_text( $from_name, self::HEADER_MAX_BYTES );

        if ( $from_name !== '' ) {
            $headers[] = 'From: ' . $from_name . ' <' . $from . '>';
        } else {
            $headers[] = 'From: ' . $from;
        }

        $reply_to = self::resolve_reply_to( $config, $values, $canonical_fields, $email, $request );
        if ( $reply_to !== '' ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        $content_type = self::config_bool( $config, array( 'email', 'html' ), false )
            ? 'text/html'
            : 'text/plain';
        $headers[] = 'Content-Type: ' . $content_type . '; charset=UTF-8';

        return $headers;
    }

    private static function resolve_from_name() {
        if ( function_exists( 'get_bloginfo' ) ) {
            $name = get_bloginfo( 'name' );
            if ( is_string( $name ) ) {
                return $name;
            }
        }

        return '';
    }

    private static function resolve_from_address( $config, $request ) {
        $domain = self::site_domain( $request );
        if ( $domain === '' ) {
            $domain = 'localhost';
        }

        $candidate = self::config_value( $config, array( 'email', 'from_address' ) );
        if ( is_array( $candidate ) ) {
            $candidate = '';
        }

        $candidate = self::sanitize_email_value( $candidate );

        if ( $candidate !== '' && self::is_valid_email( $candidate ) && self::same_domain( $candidate, $domain ) ) {
            return $candidate;
        }

        if ( $candidate !== '' ) {
            self::warn_header_value( 'from_address_invalid', $candidate, $config, $request );
        }

        return 'no-reply@' . $domain;
    }

    private static function resolve_reply_to( $config, $values, $canonical_fields, $email, $request ) {
        $reply_to = self::config_value( $config, array( 'email', 'reply_to_address' ) );
        if ( is_array( $reply_to ) ) {
            $reply_to = '';
        }

        $reply_to = self::sanitize_email_value( $reply_to );

        if ( $reply_to !== '' ) {
            if ( self::is_valid_email( $reply_to ) ) {
                return $reply_to;
            }
            self::warn_header_value( 'reply_to_address_invalid', $reply_to, $config, $request );
        }

        $reply_field = self::config_value( $config, array( 'email', 'reply_to_field' ) );
        if ( is_array( $reply_field ) ) {
            $reply_field = '';
        }

        $reply_field = is_string( $reply_field ) ? trim( $reply_field ) : '';
        if ( $reply_field === '' ) {
            return '';
        }

        $value = array_key_exists( $reply_field, $values ) ? $values[ $reply_field ] : null;
        if ( is_array( $value ) ) {
            self::warn_header_value( 'reply_to_field_invalid', $reply_field, $config, $request );
            return '';
        }

        $value = is_scalar( $value ) ? (string) $value : '';
        $value = self::sanitize_email_value( $value );

        if ( $value !== '' && self::is_valid_email( $value ) ) {
            return $value;
        }

        self::warn_header_value( 'reply_to_field_invalid', $reply_field, $config, $request );
        return '';
    }

    private static function normalize_to( $to ) {
        $targets = array();

        if ( is_string( $to ) ) {
            $targets = array( $to );
        } elseif ( is_array( $to ) ) {
            $targets = $to;
        }

        $out = array();
        foreach ( $targets as $entry ) {
            if ( ! is_string( $entry ) ) {
                continue;
            }
            $email = self::sanitize_email_value( $entry );
            if ( $email !== '' && self::is_valid_email( $email ) ) {
                $out[] = $email;
            }
        }

        return array_values( array_unique( $out ) );
    }

    private static function sanitize_scalar( $value ) {
        if ( is_array( $value ) ) {
            return '';
        }

        if ( is_scalar( $value ) ) {
            return (string) $value;
        }

        return '';
    }

    private static function sanitize_email_value( $value ) {
        $value = self::sanitize_scalar( $value );
        $value = self::sanitize_header_text( $value );
        return trim( $value );
    }

    private static function sanitize_header_text( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = str_replace( array( "\r", "\n" ), ' ', $value );
        $value = preg_replace( '/[\x00-\x1F\x7F]/', ' ', $value );
        $value = preg_replace( '/\s+/', ' ', $value );
        $value = trim( $value );

        return $value;
    }

    private static function truncate_header_text( $value, $max_bytes ) {
        if ( ! is_string( $value ) || $value === '' ) {
            return '';
        }

        if ( ! is_int( $max_bytes ) || $max_bytes <= 0 ) {
            return '';
        }

        if ( strlen( $value ) <= $max_bytes ) {
            return $value;
        }

        if ( function_exists( 'mb_strcut' ) ) {
            return mb_strcut( $value, 0, $max_bytes, 'UTF-8' );
        }

        return substr( $value, 0, $max_bytes );
    }

    private static function is_valid_email( $value ) {
        if ( ! is_string( $value ) || $value === '' ) {
            return false;
        }

        if ( function_exists( 'is_email' ) ) {
            return is_email( $value ) !== false;
        }

        return filter_var( $value, FILTER_VALIDATE_EMAIL ) !== false;
    }

    private static function same_domain( $email, $domain ) {
        if ( ! is_string( $email ) || ! is_string( $domain ) || $domain === '' ) {
            return false;
        }

        $at = strrpos( $email, '@' );
        if ( $at === false ) {
            return false;
        }

        $email_domain = strtolower( substr( $email, $at + 1 ) );
        $domain = strtolower( $domain );

        return $email_domain === $domain;
    }

    private static function site_domain( $request ) {
        $host = '';

        if ( function_exists( 'home_url' ) ) {
            $home = home_url();
            if ( is_string( $home ) ) {
                $parsed = parse_url( $home, PHP_URL_HOST );
                if ( is_string( $parsed ) ) {
                    $host = $parsed;
                }
            }
        }

        if ( $host === '' && isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ) {
            $host = $_SERVER['HTTP_HOST'];
        }

        $host = strtolower( trim( $host ) );
        if ( strpos( $host, 'www.' ) === 0 ) {
            $host = substr( $host, 4 );
        }

        return $host;
    }

    private static function warn_header_value( $reason, $value, $config, $request ) {
        $meta = array(
            'reason' => $reason,
        );

        if ( is_string( $value ) && $value !== '' ) {
            $meta['value'] = $value;
        }

        Logging::event( 'warning', 'EFORMS_ERR_EMAIL_SEND', $meta, $request );
    }

    private static function resolve_client_ip( $request ) {
        if ( is_array( $request ) && isset( $request['client_ip'] ) && is_string( $request['client_ip'] ) ) {
            return trim( $request['client_ip'] );
        }

        if ( is_object( $request ) ) {
            if ( isset( $request->client_ip ) && is_string( $request->client_ip ) ) {
                return trim( $request->client_ip );
            }
            if ( method_exists( $request, 'get_client_ip' ) ) {
                $value = $request->get_client_ip();
                if ( is_string( $value ) ) {
                    return trim( $value );
                }
            }
        }

        return '';
    }

    private static function present_ip( $ip, $config ) {
        if ( ! is_string( $ip ) || $ip === '' ) {
            return '';
        }

        $mode = self::config_value( $config, array( 'privacy', 'ip_mode' ) );
        $mode = is_string( $mode ) ? $mode : 'none';

        if ( $mode === 'none' ) {
            return '';
        }

        if ( $mode === 'full' ) {
            return $ip;
        }

        if ( $mode === 'hash' ) {
            return hash( 'sha256', $ip );
        }

        if ( strpos( $ip, ':' ) !== false ) {
            return self::mask_ipv6( $ip );
        }

        return self::mask_ipv4( $ip );
    }

    private static function mask_ipv4( $ip ) {
        $parts = explode( '.', $ip );
        if ( count( $parts ) !== 4 ) {
            return '';
        }

        $parts[3] = '0';
        return implode( '.', $parts );
    }

    private static function mask_ipv6( $ip ) {
        $ip = strtolower( $ip );
        $parts = explode( ':', $ip );
        if ( count( $parts ) < 3 ) {
            return '';
        }

        $out = array();
        foreach ( $parts as $index => $part ) {
            if ( $index < 3 ) {
                $out[] = $part;
                continue;
            }
            $out[] = '0';
        }

        return implode( ':', $out );
    }

    private static function config_bool( $config, $path, $default ) {
        $value = self::config_value( $config, $path );
        if ( is_bool( $value ) ) {
            return $value;
        }

        return $default;
    }

    private static function config_value( $config, $path ) {
        if ( ! is_array( $path ) ) {
            return null;
        }

        $cursor = $config;
        foreach ( $path as $segment ) {
            if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
                return null;
            }
            $cursor = $cursor[ $segment ];
        }

        return $cursor;
    }

    private static function soft_fail_count( $security ) {
        if ( ! is_array( $security ) || ! isset( $security['soft_reasons'] ) || ! is_array( $security['soft_reasons'] ) ) {
            return 0;
        }

        return count( $security['soft_reasons'] );
    }

    private static function is_suspect( $soft_fail_count, $config ) {
        if ( $soft_fail_count <= 0 ) {
            return false;
        }

        $threshold = self::spam_soft_fail_threshold( $config );
        return $soft_fail_count < $threshold;
    }

    private static function spam_soft_fail_threshold( $config ) {
        $value = self::config_value( $config, array( 'spam', 'soft_fail_threshold' ) );
        $value = is_numeric( $value ) ? (int) $value : 1;
        if ( $value < 1 ) {
            $value = 1;
        }
        return $value;
    }

    private static function apply_suspect_subject_tag( $subject, $config ) {
        if ( ! is_string( $subject ) || $subject === '' ) {
            return $subject;
        }

        $tag = self::config_value( $config, array( 'email', 'suspect_subject_tag' ) );
        if ( is_array( $tag ) ) {
            $tag = '';
        }

        $tag = is_string( $tag ) ? trim( $tag ) : '';
        $tag = self::sanitize_header_text( $tag );
        if ( $tag === '' ) {
            return $subject;
        }

        $combined = $tag . ' ' . $subject;
        $combined = self::sanitize_header_text( $combined );
        $combined = self::truncate_header_text( $combined, self::HEADER_MAX_BYTES );
        return $combined;
    }

    private static function failure( $reason, $extra = array() ) {
        $out = array(
            'ok' => false,
            'reason' => $reason,
        );

        if ( is_array( $extra ) && ! empty( $extra ) ) {
            $out = array_merge( $out, $extra );
        }

        return $out;
    }
}
