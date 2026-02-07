<?php
/**
 * SubmitHandler orchestration for POST submissions.
 *
 * Educational note: this stage wires the Security → Normalize → Validate → Coerce
 * pipeline in a deterministic order and returns structured results for rerender.
 *
 * Spec: Request lifecycle POST (docs/Canonical_Spec.md#sec-request-lifecycle-post)
 * Spec: Validation pipeline (docs/Canonical_Spec.md#sec-validation-pipeline)
 * Spec: Security (docs/Canonical_Spec.md#sec-security)
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Errors.php';
require_once __DIR__ . '/../Rendering/TemplateContext.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Security/PostSize.php';
require_once __DIR__ . '/../Security/Challenge.php';
require_once __DIR__ . '/../Security/Honeypot.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Security/StorageHealth.php';
require_once __DIR__ . '/../Email/Emailer.php';
require_once __DIR__ . '/../Uploads/UploadStore.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Success.php';
require_once __DIR__ . '/../Validation/Coercer.php';
require_once __DIR__ . '/../Validation/Normalizer.php';
require_once __DIR__ . '/../Validation/Validator.php';
if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}

class SubmitHandler {
    /**
     * Handle a form submission.
     *
     * @param string $form_id Expected form id (template slug in stable mode).
     * @param mixed $request Optional request object/array.
     * @param array $overrides Optional callables/overrides for testing.
     * @return array Structured result for rerender or success.
     */
    public static function handle( $form_id, $request = null, $overrides = array() ) {
        $overrides = is_array( $overrides ) ? $overrides : array();
        $trace_on = ! empty( $overrides['trace'] );
        $trace = array();

        $config = Config::get();

        $content_type = self::header_value( $request, 'Content-Type' );
        $cap = PostSize::effective_cap( $content_type, $config );
        $length = self::content_length( $request );
        if ( $length !== null && $length > $cap ) {
            return self::fail( 'EFORMS_ERR_TYPE', 400, $trace, $trace_on );
        }

        $resolved_form_id = self::resolve_form_id( $form_id, $request );
        if ( $resolved_form_id === '' ) {
            return self::fail( 'EFORMS_ERR_INVALID_FORM_ID', 400, $trace, $trace_on );
        }

        $uploads_dir = self::uploads_dir( $config );
        $health = StorageHealth::check( $uploads_dir );
        if ( ! self::health_ok( $health ) ) {
            return self::fail( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 500, $trace, $trace_on );
        }

        $template_base = self::override_value( $overrides, 'template_base_dir' );
        $template = TemplateLoader::load( $resolved_form_id, $template_base );
        if ( ! is_array( $template ) || empty( $template['ok'] ) ) {
            $code = self::error_code_from_result( $template );
            $status = $code === 'EFORMS_ERR_STORAGE_UNAVAILABLE' ? 500 : 400;
            return self::fail( $code, $status, $trace, $trace_on );
        }

        $context_result = TemplateContext::build( $template['template'], $template['version'] );
        if ( ! is_array( $context_result ) || empty( $context_result['ok'] ) ) {
            $code = self::error_code_from_errors( isset( $context_result['errors'] ) ? $context_result['errors'] : null );
            $status = $code === 'EFORMS_ERR_STORAGE_UNAVAILABLE' ? 500 : 400;
            return self::fail( $code, $status, $trace, $trace_on );
        }

        $context = $context_result['context'];
        if ( class_exists( 'Logging' ) && method_exists( 'Logging', 'remember_descriptors' ) ) {
            $descriptors = isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ? $context['descriptors'] : array();
            Logging::remember_descriptors( $descriptors );
        }
        $post = self::post_payload( $request );
        $files = self::files_payload( $request );

        $security = self::call_security( $overrides, $trace, $trace_on, $post, $resolved_form_id, $request, $uploads_dir, $config );
        $security_meta = self::security_fields( $post, $security );

        $honeypot = Honeypot::evaluate( $post, $config );
        if ( ! empty( $honeypot['triggered'] ) ) {
            if ( $trace_on ) {
                $trace[] = 'honeypot';
            }

            // Honeypot short-circuit: cleanup uploads and (if token_ok) burn the ledger entry.
            Honeypot::cleanup_uploads( $files );
            if ( self::token_ok( $security ) ) {
                self::call_honeypot_burn( $overrides, $resolved_form_id, $security['submission_id'], $uploads_dir, $request, $config );
            }
            Honeypot::log_event( $resolved_form_id, $security, $honeypot['response'], $request );

            if ( $honeypot['response'] === 'hard_fail' ) {
                $errors = self::errors_for_code( 'EFORMS_ERR_HONEYPOT' );
                return self::error_result( 200, $errors, $security, $security_meta, $trace, $trace_on );
            }

            $success_config = isset( $context['success'] ) && is_array( $context['success'] ) ? $context['success'] : array();
            return self::honeypot_success_result( $security, $security_meta, $success_config, $resolved_form_id, $trace, $trace_on );
        }

        if ( ! self::token_ok( $security ) ) {
            $code = isset( $security['error_code'] ) && is_string( $security['error_code'] ) && $security['error_code'] !== ''
                ? $security['error_code']
                : 'EFORMS_ERR_TOKEN';
            $status = 400;
            $headers = array();
            if ( $code === 'EFORMS_ERR_THROTTLED' ) {
                $status = 429;
                $retry_after = self::security_retry_after( $security );
                if ( $retry_after > 0 ) {
                    $headers['Retry-After'] = (string) $retry_after;
                }
            } elseif ( $code === 'EFORMS_CHALLENGE_UNCONFIGURED' ) {
                $status = 500;
            } elseif ( $code === 'EFORMS_ERR_STORAGE_UNAVAILABLE' ) {
                $status = 500;
            }

            return self::fail( $code, $status, $trace, $trace_on, $headers );
        }

        $soft_fail_count = self::soft_fail_count( $security );
        self::emit_soft_fail_headers( $soft_fail_count, self::is_suspect( $soft_fail_count, $config ) );

        $form_post = self::form_payload( $post, $resolved_form_id );
        $form_files = self::form_files_payload( $files, $resolved_form_id );

        $normalized = self::call_normalize( $overrides, $trace, $trace_on, $context, $form_post, $form_files );
        $validated = self::call_validate( $overrides, $trace, $trace_on, $context, $normalized );

        if ( ! self::validation_ok( $validated ) ) {
            return self::validation_result( $validated, $security, $security_meta, $trace, $trace_on );
        }

        $coerced = self::call_coerce( $overrides, $trace, $trace_on, $context, $validated );
        $challenge = self::call_challenge( $overrides, $trace, $trace_on, $post, $request, $config, $security );
        if ( ! self::challenge_ok( $challenge ) ) {
            $code = self::challenge_error_code( $challenge );
            if ( $code === 'EFORMS_CHALLENGE_UNCONFIGURED' ) {
                return self::fail( $code, 500, $trace, $trace_on );
            }

            $security['require_challenge'] = self::challenge_required( $challenge );
            $errors = self::errors_for_code( 'EFORMS_ERR_CHALLENGE_FAILED' );
            return self::error_result( 200, $errors, $security, $security_meta, $trace, $trace_on );
        }

        $security = self::apply_challenge_result( $security, $challenge );

        // Reserve ledger marker before any side effects.
        $ledger = self::call_ledger_reserve( $overrides, $resolved_form_id, $security['submission_id'], $uploads_dir, $request, $config );
        if ( ! self::ledger_ok( $ledger ) ) {
            if ( self::ledger_duplicate( $ledger ) ) {
                return self::fail( 'EFORMS_ERR_TOKEN', 400, $trace, $trace_on );
            }

            self::log_ledger_failure( $ledger, $resolved_form_id, $security, $request );
            return self::fail( 'EFORMS_ERR_LEDGER_IO', 500, $trace, $trace_on );
        }

        $commit = self::call_commit( $overrides, $trace, $trace_on, $context, $coerced, $security, $request, $config );
        if ( self::commit_email_failed( $commit ) ) {
            return self::email_failure_result( $commit, $context, $coerced, $security, $resolved_form_id, $uploads_dir, $request, $config, $trace, $trace_on );
        }
        if ( ! self::commit_ok( $commit ) ) {
            return self::fail( self::commit_error_code( $commit ), self::commit_status( $commit ), $trace, $trace_on );
        }

        $ok = self::commit_ok( $commit );
        $status = self::commit_status( $commit );

        $success_config = isset( $context['success'] ) && is_array( $context['success'] ) ? $context['success'] : array();
        $success_mode = isset( $success_config['mode'] ) && is_string( $success_config['mode'] ) ? $success_config['mode'] : 'inline';

        $result = array(
            'ok' => $ok,
            'status' => $status,
            'mode' => isset( $security['mode'] ) ? $security['mode'] : '',
            'submission_id' => isset( $security['submission_id'] ) ? $security['submission_id'] : '',
            'soft_reasons' => isset( $security['soft_reasons'] ) && is_array( $security['soft_reasons'] ) ? $security['soft_reasons'] : array(),
            'require_challenge' => ! empty( $security['require_challenge'] ),
            'values' => self::commit_values( $commit, $coerced ),
            'errors' => null,
            'security' => $security_meta,
            'commit' => $commit,
            'success' => array(
                'mode' => $success_mode,
                'message' => isset( $success_config['message'] ) ? $success_config['message'] : '',
                'redirect_url' => isset( $success_config['redirect_url'] ) ? $success_config['redirect_url'] : '',
            ),
            'form_id' => $resolved_form_id,
        );

        if ( $trace_on ) {
            $result['trace'] = $trace;
        }

        return $result;
    }

    /**
     * Perform PRG redirect after successful submission.
     *
     * Spec: PRG status is fixed at 303. Success responses MUST satisfy cache-safety.
     *
     * @param array $result Result from handle() with ok=true.
     * @param array $options Optional overrides for testing.
     * @return array Redirect result from Success class.
     */
    public static function do_success_redirect( $result, $options = array() ) {
        if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
            return array( 'ok' => false, 'reason' => 'not_success' );
        }

        $form_id = isset( $result['form_id'] ) && is_string( $result['form_id'] ) ? $result['form_id'] : '';
        $success = isset( $result['success'] ) && is_array( $result['success'] ) ? $result['success'] : array();

        $context = array(
            'id' => $form_id,
            'success' => $success,
        );

        return Success::redirect( $context, $options );
    }

    private static function call_security( $overrides, &$trace, $trace_on, $post, $form_id, $request, $uploads_dir, $config ) {
        if ( $trace_on ) {
            $trace[] = 'security';
        }

        $callable = self::override_callable( $overrides, 'security' );
        if ( $callable ) {
            return call_user_func( $callable, $post, $form_id, $request, $uploads_dir, $config );
        }

        return Security::token_validate( $post, $form_id, $request, $uploads_dir );
    }

    private static function call_normalize( $overrides, &$trace, $trace_on, $context, $post, $files ) {
        if ( $trace_on ) {
            $trace[] = 'normalize';
        }

        $callable = self::override_callable( $overrides, 'normalize' );
        if ( $callable ) {
            return call_user_func( $callable, $context, $post, $files );
        }

        return NormalizerStage::normalize( $context, $post, $files );
    }

    private static function call_validate( $overrides, &$trace, $trace_on, $context, $normalized ) {
        if ( $trace_on ) {
            $trace[] = 'validate';
        }

        $callable = self::override_callable( $overrides, 'validate' );
        if ( $callable ) {
            return call_user_func( $callable, $context, $normalized );
        }

        return Validator::validate( $context, $normalized );
    }

    private static function call_coerce( $overrides, &$trace, $trace_on, $context, $validated ) {
        if ( $trace_on ) {
            $trace[] = 'coerce';
        }

        $callable = self::override_callable( $overrides, 'coerce' );
        if ( $callable ) {
            return call_user_func( $callable, $context, $validated );
        }

        return Coercer::coerce( $context, $validated );
    }

    private static function call_challenge( $overrides, &$trace, $trace_on, $post, $request, $config, $security ) {
        if ( ! self::challenge_verification_needed( $security, $post ) ) {
            return array(
                'ok' => true,
                'required' => false,
                'error_code' => '',
                'soft_reasons' => isset( $security['soft_reasons'] ) && is_array( $security['soft_reasons'] ) ? $security['soft_reasons'] : array(),
            );
        }

        if ( $trace_on ) {
            $trace[] = 'challenge';
        }

        $callable = self::override_callable( $overrides, 'challenge' );
        if ( $callable ) {
            return call_user_func( $callable, $post, $request, $config, $security );
        }

        return Challenge::verify( $post, $request, $config, $security );
    }

    private static function call_commit( $overrides, &$trace, $trace_on, $context, $coerced, $security, $request, $config ) {
        if ( $trace_on ) {
            $trace[] = 'commit';
        }

        $callable = self::override_callable( $overrides, 'commit' );
        if ( $callable ) {
            return call_user_func( $callable, $context, $coerced, $security, $request, $config );
        }

        return self::default_commit( $context, $coerced, $security, $request, $config );
    }

    private static function default_commit( $context, $coerced, $security, $request, $config ) {
        $uploads_dir = self::uploads_dir( $config );
        $submission_id = is_array( $security ) && isset( $security['submission_id'] ) && is_string( $security['submission_id'] )
            ? $security['submission_id']
            : '';

        $move = UploadStore::move_after_ledger( $context, $coerced, $submission_id, $uploads_dir );
        if ( ! is_array( $move ) || empty( $move['ok'] ) ) {
            return array(
                'ok' => false,
                'status' => 500,
                'error_code' => 'EFORMS_ERR_STORAGE_UNAVAILABLE',
                'reason' => is_array( $move ) && isset( $move['reason'] ) ? $move['reason'] : 'upload_move_failed',
            );
        }

        $values = isset( $move['values'] ) && is_array( $move['values'] )
            ? $move['values']
            : self::extract_values( $coerced );
        $stored = isset( $move['stored'] ) && is_array( $move['stored'] ) ? $move['stored'] : array();

        $email = Emailer::send( $context, $values, $security, $request, $config );

        if ( ! is_array( $email ) || empty( $email['ok'] ) ) {
            UploadStore::apply_retention( $stored, $config );
            return array(
                'ok' => false,
                'status' => 500,
                'email_failed' => true,
                'email' => $email,
                'values' => $values,
                'stored' => $stored,
            );
        }

        UploadStore::apply_retention( $stored, $config );

        return array(
            'ok' => true,
            'status' => 200,
            'committed' => true,
            'email' => $email,
            'values' => $values,
            'stored' => $stored,
        );
    }

    private static function call_ledger_reserve( $overrides, $form_id, $submission_id, $uploads_dir, $request, $config ) {
        $callable = self::override_callable( $overrides, 'ledger_reserve' );
        if ( $callable ) {
            return call_user_func( $callable, $form_id, $submission_id, $uploads_dir, $request, $config );
        }

        return Ledger::reserve( $form_id, $submission_id, $uploads_dir, $request );
    }

    private static function commit_email_failed( $commit ) {
        return is_array( $commit ) && ! empty( $commit['email_failed'] );
    }

    private static function commit_ok( $commit ) {
        if ( ! is_array( $commit ) ) {
            return false;
        }

        if ( ! array_key_exists( 'ok', $commit ) ) {
            return true;
        }

        return (bool) $commit['ok'];
    }

    private static function commit_error_code( $commit ) {
        if ( is_array( $commit ) && isset( $commit['error_code'] ) && is_string( $commit['error_code'] ) && $commit['error_code'] !== '' ) {
            return $commit['error_code'];
        }

        return 'EFORMS_ERR_STORAGE_UNAVAILABLE';
    }

    private static function commit_status( $commit ) {
        if ( is_array( $commit ) && isset( $commit['status'] ) && is_numeric( $commit['status'] ) ) {
            return (int) $commit['status'];
        }

        return self::commit_ok( $commit ) ? 200 : 500;
    }

    private static function commit_values( $commit, $fallback ) {
        if ( is_array( $commit ) && isset( $commit['values'] ) && is_array( $commit['values'] ) ) {
            return $commit['values'];
        }

        return self::extract_values( $fallback );
    }

    private static function email_failure_result( $commit, $context, $coerced, $security, $form_id, $uploads_dir, $request, $config, $trace, $trace_on ) {
        $values = self::commit_values( $commit, $coerced );
        $security_result = self::email_failure_security( $security, $form_id, $uploads_dir );
        if ( ! $security_result['ok'] ) {
            return self::fail( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 500, $trace, $trace_on );
        }

        self::log_email_failure( $commit, $form_id, $security, $request );

        $errors = new Errors();
        $errors->add_global( 'EFORMS_ERR_EMAIL_SEND', 'We couldn\'t send your message. Please try again.' );

        $result = array(
            'ok' => false,
            'status' => 500,
            'error_code' => 'EFORMS_ERR_EMAIL_SEND',
            'errors' => $errors,
            'mode' => isset( $security['mode'] ) ? $security['mode'] : '',
            'submission_id' => isset( $security['submission_id'] ) ? $security['submission_id'] : '',
            'soft_reasons' => isset( $security['soft_reasons'] ) && is_array( $security['soft_reasons'] ) ? $security['soft_reasons'] : array(),
            'require_challenge' => ! empty( $security['require_challenge'] ),
            'security' => $security_result['security'],
            'values' => $values,
            'email_retry' => true,
            'email_failure_summary' => Emailer::build_copy_summary( $context, $values, $security, $request, $config ),
            'email_failure_remint' => $security_result['remint'],
        );

        if ( $trace_on ) {
            $result['trace'] = $trace;
        }

        return $result;
    }

    private static function email_failure_security( $security, $form_id, $uploads_dir ) {
        $mode = isset( $security['mode'] ) ? $security['mode'] : '';

        if ( $mode === 'hidden' ) {
            $mint = Security::mint_hidden_record( $form_id, $uploads_dir );
            if ( ! is_array( $mint ) || empty( $mint['ok'] ) ) {
                return array( 'ok' => false );
            }

            return array(
                'ok' => true,
                'remint' => false,
                'security' => array(
                    'mode' => 'hidden',
                    'token' => $mint['token'],
                    'instance_id' => $mint['instance_id'],
                    'timestamp' => (string) $mint['issued_at'],
                ),
            );
        }

        return array(
            'ok' => true,
            'remint' => true,
            'security' => array(
                'mode' => 'js',
                'token' => '',
                'instance_id' => '',
                'timestamp' => '',
            ),
        );
    }

    private static function log_email_failure( $commit, $form_id, $security, $request ) {
        if ( ! class_exists( 'Logging' ) ) {
            return;
        }

        $email = is_array( $commit ) && isset( $commit['email'] ) && is_array( $commit['email'] )
            ? $commit['email']
            : array();

        $meta = array(
            'form_id' => is_string( $form_id ) ? $form_id : '',
            'submission_id' => isset( $security['submission_id'] ) && is_string( $security['submission_id'] ) ? $security['submission_id'] : '',
            'transport' => isset( $email['transport'] ) ? $email['transport'] : 'wp_mail',
            'error_class' => isset( $email['error_class'] ) ? $email['error_class'] : '',
            'error_message' => isset( $email['error_message'] ) ? $email['error_message'] : '',
            'reason' => isset( $email['reason'] ) ? $email['reason'] : 'send_failed',
        );

        Logging::event( 'error', 'EFORMS_ERR_EMAIL_SEND', $meta, $request );
    }

    private static function call_honeypot_burn( $overrides, $form_id, $submission_id, $uploads_dir, $request, $config ) {
        $callable = self::override_callable( $overrides, 'honeypot_burn' );
        if ( $callable ) {
            return call_user_func( $callable, $form_id, $submission_id, $uploads_dir, $request, $config );
        }

        return Ledger::reserve( $form_id, $submission_id, $uploads_dir, $request );
    }

    private static function override_callable( $overrides, $key ) {
        if ( is_array( $overrides ) && isset( $overrides[ $key ] ) && is_callable( $overrides[ $key ] ) ) {
            return $overrides[ $key ];
        }

        return null;
    }

    private static function override_value( $overrides, $key ) {
        if ( is_array( $overrides ) && isset( $overrides[ $key ] ) ) {
            return $overrides[ $key ];
        }

        return null;
    }

    private static function validation_ok( $validated ) {
        return is_array( $validated ) && ! empty( $validated['ok'] );
    }

    private static function challenge_verification_needed( $security, $post ) {
        if ( ! is_array( $security ) ) {
            return false;
        }

        if ( ! empty( $security['require_challenge'] ) ) {
            return true;
        }

        if ( ! empty( $security['challenge_response_present'] ) ) {
            return true;
        }

        return Challenge::has_provider_response( $post );
    }

    private static function challenge_ok( $challenge ) {
        return is_array( $challenge ) && ! empty( $challenge['ok'] );
    }

    private static function challenge_error_code( $challenge ) {
        if ( is_array( $challenge ) && isset( $challenge['error_code'] ) && is_string( $challenge['error_code'] ) && $challenge['error_code'] !== '' ) {
            return $challenge['error_code'];
        }

        return 'EFORMS_ERR_CHALLENGE_FAILED';
    }

    private static function challenge_required( $challenge ) {
        return is_array( $challenge ) && ! empty( $challenge['required'] );
    }

    private static function apply_challenge_result( $security, $challenge ) {
        if ( ! is_array( $security ) ) {
            $security = array();
        }

        if ( ! is_array( $challenge ) ) {
            $security['require_challenge'] = false;
            return $security;
        }

        $security['require_challenge'] = ! empty( $challenge['required'] );
        if ( isset( $challenge['soft_reasons'] ) && is_array( $challenge['soft_reasons'] ) ) {
            $security['soft_reasons'] = $challenge['soft_reasons'];
        }

        return $security;
    }

    private static function token_ok( $security ) {
        return is_array( $security )
            && ! empty( $security['token_ok'] )
            && empty( $security['hard_fail'] );
    }

    private static function ledger_ok( $ledger ) {
        return is_array( $ledger ) && ! empty( $ledger['ok'] );
    }

    private static function ledger_duplicate( $ledger ) {
        return is_array( $ledger ) && ! empty( $ledger['duplicate'] );
    }

    private static function log_ledger_failure( $ledger, $form_id, $security, $request ) {
        if ( ! class_exists( 'Logging' ) ) {
            return;
        }

        if ( is_array( $ledger ) && ! empty( $ledger['logged'] ) ) {
            return;
        }

        $meta = array(
            'form_id' => is_string( $form_id ) ? $form_id : '',
            'submission_id' => isset( $security['submission_id'] ) && is_string( $security['submission_id'] ) ? $security['submission_id'] : '',
            'reason' => is_array( $ledger ) && isset( $ledger['reason'] ) ? $ledger['reason'] : 'unknown',
        );

        if ( is_array( $ledger ) && isset( $ledger['path'] ) && is_string( $ledger['path'] ) ) {
            $meta['path'] = $ledger['path'];
        }

        Logging::event( 'error', 'EFORMS_LEDGER_IO', $meta, $request );
    }

    private static function validation_result( $validated, $security, $security_meta, $trace, $trace_on ) {
        $errors = null;
        if ( is_array( $validated ) && isset( $validated['errors'] ) ) {
            $errors = $validated['errors'];
        }

        if ( ! ( $errors instanceof Errors ) ) {
            $errors = self::errors_for_code( 'EFORMS_ERR_SCHEMA_TYPE' );
        }

        return self::error_result( 200, $errors, $security, $security_meta, $trace, $trace_on );
    }

    private static function error_result( $status, $errors, $security, $security_meta, $trace, $trace_on ) {
        $result = array(
            'ok' => false,
            'status' => (int) $status,
            'error_code' => self::error_code_from_errors( $errors ),
            'errors' => $errors,
            'mode' => isset( $security['mode'] ) ? $security['mode'] : '',
            'submission_id' => isset( $security['submission_id'] ) ? $security['submission_id'] : '',
            'soft_reasons' => isset( $security['soft_reasons'] ) && is_array( $security['soft_reasons'] ) ? $security['soft_reasons'] : array(),
            'require_challenge' => ! empty( $security['require_challenge'] ),
            'security' => $security_meta,
        );

        if ( $trace_on ) {
            $result['trace'] = $trace;
        }

        return $result;
    }

    private static function honeypot_success_result( $security, $security_meta, $success_config, $form_id, $trace, $trace_on ) {
        $success_config = is_array( $success_config ) ? $success_config : array();
        $success_mode = isset( $success_config['mode'] ) && is_string( $success_config['mode'] ) ? $success_config['mode'] : 'inline';
        $form_id = is_string( $form_id ) ? $form_id : '';

        $result = array(
            'ok' => true,
            'status' => 200,
            'mode' => isset( $security['mode'] ) ? $security['mode'] : '',
            'submission_id' => isset( $security['submission_id'] ) ? $security['submission_id'] : '',
            'soft_reasons' => isset( $security['soft_reasons'] ) && is_array( $security['soft_reasons'] ) ? $security['soft_reasons'] : array(),
            'require_challenge' => false,
            'values' => array(),
            'errors' => null,
            'security' => $security_meta,
            'commit' => array(
                'ok' => true,
                'status' => 200,
                'committed' => false,
            ),
            // Educational note: stealth honeypot paths should mirror success metadata.
            'success' => array(
                'mode' => $success_mode,
                'message' => isset( $success_config['message'] ) ? $success_config['message'] : '',
                'redirect_url' => isset( $success_config['redirect_url'] ) ? $success_config['redirect_url'] : '',
            ),
            'form_id' => $form_id,
        );

        if ( $trace_on ) {
            $result['trace'] = $trace;
        }

        return $result;
    }

    private static function fail( $code, $status, $trace, $trace_on, $headers = array() ) {
        $errors = self::errors_for_code( $code );
        $result = array(
            'ok' => false,
            'status' => (int) $status,
            'error_code' => $code,
            'errors' => $errors,
        );

        if ( is_array( $headers ) && ! empty( $headers ) ) {
            $result['headers'] = $headers;
        }

        if ( $trace_on ) {
            $result['trace'] = $trace;
        }

        return $result;
    }

    private static function errors_for_code( $code ) {
        $errors = new Errors();
        $errors->add_global( $code );
        return $errors;
    }

    private static function resolve_form_id( $form_id, $request ) {
        if ( is_string( $form_id ) && $form_id !== '' ) {
            return $form_id;
        }

        $post = self::post_payload( $request );
        if ( ! is_array( $post ) ) {
            return '';
        }

        $candidates = array();
        $reserved = self::reserved_keys();

        foreach ( $post as $key => $value ) {
            if ( ! is_string( $key ) || $key === '' ) {
                continue;
            }
            if ( isset( $reserved[ $key ] ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $candidates[] = $key;
            }
        }

        if ( count( $candidates ) === 1 ) {
            return $candidates[0];
        }

        return '';
    }

    private static function security_retry_after( $security ) {
        if ( is_array( $security ) && isset( $security['retry_after'] ) && is_numeric( $security['retry_after'] ) ) {
            return max( 1, (int) $security['retry_after'] );
        }

        return 0;
    }

    private static function reserved_keys() {
        return array(
            'form_id' => true,
            'instance_id' => true,
            'submission_id' => true,
            'eforms_token' => true,
            'eforms_hp' => true,
            'eforms_mode' => true,
            'timestamp' => true,
            'js_ok' => true,
            'eforms_email_retry' => true,
            'ip' => true,
            'submitted_at' => true,
        );
    }

    private static function post_payload( $request ) {
        if ( is_array( $request ) && isset( $request['post'] ) && is_array( $request['post'] ) ) {
            return $request['post'];
        }

        if ( isset( $_POST ) && is_array( $_POST ) ) {
            return $_POST;
        }

        return array();
    }

    private static function files_payload( $request ) {
        if ( is_array( $request ) && isset( $request['files'] ) && is_array( $request['files'] ) ) {
            return $request['files'];
        }

        if ( isset( $_FILES ) && is_array( $_FILES ) ) {
            return $_FILES;
        }

        return array();
    }

    private static function form_payload( $post, $form_id ) {
        if ( is_array( $post ) && is_string( $form_id ) && $form_id !== '' ) {
            if ( isset( $post[ $form_id ] ) && is_array( $post[ $form_id ] ) ) {
                return $post[ $form_id ];
            }
        }

        return is_array( $post ) ? $post : array();
    }

    private static function form_files_payload( $files, $form_id ) {
        if ( is_array( $files ) && is_string( $form_id ) && $form_id !== '' ) {
            if ( isset( $files[ $form_id ] ) && is_array( $files[ $form_id ] ) ) {
                return $files[ $form_id ];
            }
        }

        return is_array( $files ) ? $files : array();
    }

    private static function security_fields( $post, $security ) {
        return array(
            'mode' => isset( $security['mode'] ) ? $security['mode'] : '',
            'token' => self::post_string( $post, 'eforms_token' ),
            'instance_id' => self::post_string( $post, 'instance_id' ),
            'timestamp' => self::post_string( $post, 'timestamp' ),
        );
    }

    private static function post_string( $post, $key ) {
        if ( is_array( $post ) && isset( $post[ $key ] ) && is_string( $post[ $key ] ) ) {
            return $post[ $key ];
        }

        return '';
    }

    private static function extract_values( $payload ) {
        if ( is_array( $payload ) && isset( $payload['values'] ) && is_array( $payload['values'] ) ) {
            return $payload['values'];
        }

        return is_array( $payload ) ? $payload : array();
    }

    private static function uploads_dir( $config ) {
        if ( is_array( $config ) && isset( $config['uploads'] ) && is_array( $config['uploads'] ) ) {
            if ( isset( $config['uploads']['dir'] ) && is_string( $config['uploads']['dir'] ) ) {
                return rtrim( $config['uploads']['dir'], '/\\' );
            }
        }

        return '';
    }

    private static function health_ok( $health ) {
        return is_array( $health ) && ! empty( $health['ok'] );
    }

    private static function content_length( $request ) {
        if ( is_array( $request ) && isset( $request['content_length'] ) && is_numeric( $request['content_length'] ) ) {
            return (int) $request['content_length'];
        }

        if ( isset( $_SERVER['CONTENT_LENGTH'] ) && is_numeric( $_SERVER['CONTENT_LENGTH'] ) ) {
            return (int) $_SERVER['CONTENT_LENGTH'];
        }

        if ( isset( $_SERVER['HTTP_CONTENT_LENGTH'] ) && is_numeric( $_SERVER['HTTP_CONTENT_LENGTH'] ) ) {
            return (int) $_SERVER['HTTP_CONTENT_LENGTH'];
        }

        return null;
    }

    private static function header_value( $request, $name ) {
        if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
            $value = $request->get_header( $name );
            if ( is_string( $value ) ) {
                return trim( $value );
            }
        }

        if ( is_array( $request ) && isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
            foreach ( $request['headers'] as $key => $value ) {
                if ( is_string( $key ) && strcasecmp( $key, $name ) === 0 && is_string( $value ) ) {
                    return trim( $value );
                }
            }
        }

        $server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        if ( isset( $_SERVER[ $server_key ] ) && is_string( $_SERVER[ $server_key ] ) ) {
            return trim( $_SERVER[ $server_key ] );
        }

        return '';
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
        $threshold = 1;
        if ( is_array( $config ) && isset( $config['spam'] ) && is_array( $config['spam'] ) ) {
            if ( isset( $config['spam']['soft_fail_threshold'] ) && is_numeric( $config['spam']['soft_fail_threshold'] ) ) {
                $threshold = (int) $config['spam']['soft_fail_threshold'];
            }
        }

        if ( $threshold < 1 ) {
            $threshold = 1;
        }

        return $threshold;
    }

    private static function emit_soft_fail_headers( $soft_fail_count, $is_suspect ) {
        if ( $soft_fail_count <= 0 ) {
            return;
        }

        if ( headers_sent() ) {
            return;
        }

        header( 'X-EForms-Soft-Fails: ' . $soft_fail_count );
        if ( $is_suspect ) {
            header( 'X-EForms-Suspect: 1' );
        }
    }

    private static function error_code_from_result( $result ) {
        if ( is_array( $result ) && isset( $result['errors'] ) ) {
            return self::error_code_from_errors( $result['errors'] );
        }

        return 'EFORMS_ERR_SCHEMA_OBJECT';
    }

    private static function error_code_from_errors( $errors ) {
        if ( $errors instanceof Errors ) {
            $data = $errors->to_array();
            if ( isset( $data['_global'] ) && is_array( $data['_global'] ) ) {
                foreach ( $data['_global'] as $entry ) {
                    if ( is_array( $entry ) && isset( $entry['code'] ) && is_string( $entry['code'] ) ) {
                        return $entry['code'];
                    }
                }
            }
        }

        if ( is_array( $errors ) && isset( $errors['_global'] ) && is_array( $errors['_global'] ) ) {
            foreach ( $errors['_global'] as $entry ) {
                if ( is_array( $entry ) && isset( $entry['code'] ) && is_string( $entry['code'] ) ) {
                    return $entry['code'];
                }
            }
        }

        return 'EFORMS_ERR_SCHEMA_OBJECT';
    }
}
