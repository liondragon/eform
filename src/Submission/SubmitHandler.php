<?php
/**
 * SubmitHandler orchestration for POST submissions.
 *
 * Educational note: this stage wires the Security → Normalize → Validate → Coerce
 * pipeline in a deterministic order and returns structured results for rerender.
 *
 * Contract: Request lifecycle POST
 * Contract: Validation pipeline
 * Contract: Security
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Errors.php';
require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../Rendering/TemplateContext.php';
require_once __DIR__ . '/../Rendering/TemplateLoader.php';
require_once __DIR__ . '/../Security/PostSize.php';
require_once __DIR__ . '/../Security/Challenge.php';
require_once __DIR__ . '/../Security/Honeypot.php';
require_once __DIR__ . '/../Security/Security.php';
require_once __DIR__ . '/../Security/StorageHealth.php';
require_once __DIR__ . '/../Spam/ContentFilter.php';
require_once __DIR__ . '/../Email/Emailer.php';
require_once __DIR__ . '/../Uploads/UploadStore.php';
require_once __DIR__ . '/../Uploads/UploadBatchStore.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Success.php';
require_once __DIR__ . '/../Validation/Coercer.php';
require_once __DIR__ . '/../Validation/Normalizer.php';
require_once __DIR__ . '/../Validation/Validator.php';
if ( ! class_exists( 'Logging' ) ) {
    require_once __DIR__ . '/../Logging.php';
}

class SubmitHandler {
    private static $admin_email_failure_notified = false;

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
            $code = Errors::first_code( is_array( $template ) && isset( $template['errors'] ) ? $template['errors'] : null );
            $status = $code === 'EFORMS_ERR_STORAGE_UNAVAILABLE' ? 500 : 400;
            return self::fail( $code, $status, $trace, $trace_on );
        }

        $context_result = TemplateContext::build( $template['template'], $template['version'] );
        if ( ! is_array( $context_result ) || empty( $context_result['ok'] ) ) {
            $code = Errors::first_code( isset( $context_result['errors'] ) ? $context_result['errors'] : null );
            $status = $code === 'EFORMS_ERR_STORAGE_UNAVAILABLE' ? 500 : 400;
            return self::fail( $code, $status, $trace, $trace_on );
        }

        $context = $context_result['context'];
        $descriptors = isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ? $context['descriptors'] : array();
        Logging::remember_descriptors( $descriptors );
        $post = self::post_payload( $request );
        $upload_credentials = self::upload_credentials( $post );
        unset( $post[ FormProtocol::FIELD_UPLOAD_BATCHES ] );
        if ( is_array( $request ) && isset( $request['post'] ) && is_array( $request['post'] ) ) {
            unset( $request['post'][ FormProtocol::FIELD_UPLOAD_BATCHES ] );
        }
        $files = self::files_payload( $request );
        $form_post = self::form_payload( $post, $resolved_form_id );
        $form_files = self::form_files_payload( $files, $resolved_form_id );

        $allow_expired_recovery = self::has_staged_recovery_candidate( $context, $upload_credentials );
        $security = self::call_security( $overrides, $trace, $trace_on, $post, $resolved_form_id, $request, $uploads_dir, $config, $allow_expired_recovery );
        $security_meta = self::security_fields( $post, $security );
        $declined = array(
            'config' => $config,
            'form_id' => $resolved_form_id,
            'context' => $context,
            'request' => $request,
            'security' => $security,
            'values' => $form_post,
            'uploads' => $form_files,
        );

        $honeypot = Honeypot::evaluate( $post, $config );
        if ( ! empty( $honeypot['triggered'] ) ) {
            if ( self::token_ok( $security ) ) {
                self::capture_declined_submission(
                    $declined,
                    'EFORMS_ERR_HONEYPOT',
                    'honeypot',
                    'metadata_only',
                    array( 'honeypot' => true )
                );
            }

            return self::spam_short_circuit_result(
                'honeypot',
                'EFORMS_ERR_HONEYPOT',
                $honeypot['response'],
                $files,
                $overrides,
                $resolved_form_id,
                $security,
                $security_meta,
                $uploads_dir,
                $request,
                $config,
                $trace,
                $trace_on
            );
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

        $staged = null;
        if ( ! empty( $security['token_expired'] ) ) {
            $staged = self::resolve_staged_uploads(
                $context,
                $upload_credentials,
                $post,
                $form_files,
                $security,
                $uploads_dir
            );
            if ( ! self::staged_recovery_allowed( $staged ) ) {
                return self::fail( 'EFORMS_ERR_TOKEN', 400, $trace, $trace_on );
            }
        }

        $soft_signal = Security::soft_signal_context( $security, $config );
        $security['soft_reasons'] = $soft_signal['soft_reasons'];

        $soft_fail_count = $soft_signal['soft_fail_count'];
        $is_suspect = $soft_signal['is_suspect'];
        if ( $is_suspect ) {
            self::emit_soft_fail_headers( $soft_fail_count, true );
        }

        if ( $soft_signal['is_spam'] ) {
            self::capture_declined_submission(
                $declined,
                'EFORMS_ERR_SPAM',
                'spam_threshold',
                'raw_declared'
            );

            return self::spam_short_circuit_result(
                'spam',
                'EFORMS_ERR_SPAM',
                $honeypot['response'],
                $files,
                $overrides,
                $resolved_form_id,
                $security,
                $security_meta,
                $uploads_dir,
                $request,
                $config,
                $trace,
                $trace_on
            );
        }

        if ( $staged === null ) {
            $staged = self::resolve_staged_uploads(
                $context,
                $upload_credentials,
                $post,
                $form_files,
                $security,
                $uploads_dir
            );
        }
        if ( ! empty( $staged['errors'] ) && $staged['errors'] instanceof Errors && $staged['errors']->any() ) {
            if ( ! self::restore_recovered_claims_before_ledger( $staged, $resolved_form_id, $security['submission_id'], $uploads_dir, $request ) ) {
                return self::fail( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 500, $trace, $trace_on );
            }
            $result = self::error_result( 200, $staged['errors'], $security, $security_meta, $trace, $trace_on );
            $result['validated_upload_batches'] = $staged['rerender'];
            return $result;
        }

        $normalized = self::call_normalize( $overrides, $trace, $trace_on, $context, $form_post, $form_files );
        $normalized = self::inject_staged_values( $normalized, $staged );
        $validated = self::call_validate( $overrides, $trace, $trace_on, $context, $normalized );

        if ( ! self::validation_ok( $validated ) ) {
            if ( ! self::restore_recovered_claims_before_ledger( $staged, $resolved_form_id, $security['submission_id'], $uploads_dir, $request ) ) {
                return self::fail( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 500, $trace, $trace_on );
            }
            $result = self::validation_result( $validated, $security, $security_meta, $trace, $trace_on );
            $result['validated_upload_batches'] = $staged['rerender'];
            return $result;
        }

        $coerced = self::call_coerce( $overrides, $trace, $trace_on, $context, $validated );
        $challenge = self::call_challenge( $overrides, $trace, $trace_on, $post, $request, $config, $security );
        if ( ! self::challenge_ok( $challenge ) ) {
            if ( ! self::restore_recovered_claims_before_ledger( $staged, $resolved_form_id, $security['submission_id'], $uploads_dir, $request ) ) {
                return self::fail( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 500, $trace, $trace_on );
            }
            $code = self::challenge_error_code( $challenge );
            if ( $code === 'EFORMS_CHALLENGE_UNCONFIGURED' ) {
                return self::fail( $code, 500, $trace, $trace_on );
            }

            $security['require_challenge'] = self::challenge_required( $challenge );
            if ( Challenge::has_provider_response( $post ) ) {
                $declined['security'] = $security;
                $declined['values'] = self::extract_values( $coerced );
                self::capture_declined_submission(
                    $declined,
                    'EFORMS_ERR_CHALLENGE_FAILED',
                    'challenge_verify',
                    'canonical',
                    array(
                        'challenge' => array(
                            'required' => ! empty( $challenge['required'] ) ? '1' : '0',
                            'error_code' => self::challenge_error_code( $challenge ),
                        ),
                    )
                );
            }
            $errors = self::errors_for_code( 'EFORMS_ERR_CHALLENGE_FAILED' );
            $result = self::error_result( 200, $errors, $security, $security_meta, $trace, $trace_on );
            $result['validated_upload_batches'] = $staged['rerender'];
            return $result;
        }

        $security = self::apply_challenge_result( $security, $challenge );
        $soft_signal = Security::soft_signal_context( $security, $config );
        $security['soft_reasons'] = $soft_signal['soft_reasons'];

        $content_filter = self::call_content_filter( $overrides, $trace, $trace_on, $context, $coerced, $config );
        if ( ContentFilter::is_matched( $content_filter ) ) {
            $content_filter = ContentFilter::safe_metadata( $content_filter );
            $security['content_filter'] = $content_filter;
            if ( ContentFilter::is_reject( $content_filter ) ) {
                $declined['security'] = $security;
                $declined['values'] = self::extract_values( $coerced );
                self::capture_declined_submission(
                    $declined,
                    'EFORMS_ERR_SPAM',
                    'content_filter',
                    'canonical',
                    array( 'content_filter' => $content_filter )
                );

                return self::spam_short_circuit_result(
                    'content_filter_reject',
                    'EFORMS_ERR_SPAM',
                    $honeypot['response'],
                    $files,
                    $overrides,
                    $resolved_form_id,
                    $security,
                    $security_meta,
                    $uploads_dir,
                    $request,
                    $config,
                    $trace,
                    $trace_on
                );
            }

            self::log_content_filter_suspect( $resolved_form_id, $security, $request );
        }

        $freeze = self::freeze_staged_uploads( $staged, $security['submission_id'], $uploads_dir );
        if ( empty( $freeze['ok'] ) ) {
            self::reopen_new_claims_if_unused( $staged, $resolved_form_id, $security['submission_id'], $uploads_dir, $request );
            $code = self::staged_store_error_code( $freeze );
            return self::fail( $code, self::staged_store_error_status( $code ), $trace, $trace_on );
        }

        // Reserve ledger marker before any external side effects.
        $ledger = self::call_ledger_reserve( $overrides, $resolved_form_id, $security['submission_id'], $uploads_dir, $request, $config );
        if ( ! self::ledger_ok( $ledger ) ) {
            if ( self::ledger_duplicate( $ledger ) && self::staged_recovery_allowed( $staged ) ) {
                // The exact pre-email aggregate claim is the only duplicate exception.
            } elseif ( self::ledger_duplicate( $ledger ) ) {
                self::reopen_new_claims_if_unused( $staged, $resolved_form_id, $security['submission_id'], $uploads_dir, $request );
                return self::fail( 'EFORMS_ERR_TOKEN', 400, $trace, $trace_on );
            } else {
                self::reopen_new_claims_if_unused( $staged, $resolved_form_id, $security['submission_id'], $uploads_dir, $request );
                self::log_ledger_failure( $ledger, $resolved_form_id, $security, $request );
                return self::fail( 'EFORMS_ERR_LEDGER_IO', 500, $trace, $trace_on );
            }
        }

        $finalized = self::finalize_staged_uploads( $staged, $security['submission_id'], $uploads_dir );
        if ( empty( $finalized['ok'] ) ) {
            return self::fail( 'EFORMS_ERR_STORAGE_UNAVAILABLE', 500, $trace, $trace_on );
        }

        $commit = self::call_commit( $overrides, $trace, $trace_on, $context, $coerced, $security, $request, $config, $staged );
        if ( self::commit_email_failed( $commit ) ) {
            return self::email_failure_result( $commit, $context, $coerced, $security, $resolved_form_id, $uploads_dir, $request, $config, $trace, $trace_on );
        }
        if ( ! self::commit_ok( $commit ) ) {
            return self::fail( self::commit_error_code( $commit ), self::commit_status( $commit ), $trace, $trace_on );
        }

        $ok = self::commit_ok( $commit );
        $status = self::commit_status( $commit );

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
     * Contract: PRG status is fixed at 303. Success responses MUST satisfy cache-safety.
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

        $context = array(
            'id' => $form_id,
        );

        return Success::redirect( $context, $options );
    }

    private static function call_security( $overrides, &$trace, $trace_on, $post, $form_id, $request, $uploads_dir, $config, $allow_expired_recovery = false ) {
        if ( $trace_on ) {
            $trace[] = 'security';
        }

        $callable = self::override_callable( $overrides, 'security' );
        if ( $callable ) {
            return call_user_func( $callable, $post, $form_id, $request, $uploads_dir, $config );
        }

        $options = $allow_expired_recovery ? array( 'allow_expired' => true ) : array();
        return Security::token_validate( $post, $form_id, $request, $uploads_dir, $options );
    }

    private static function resolve_staged_uploads( $context, $credentials, $post, $files, $security, $uploads_dir ) {
        $errors = new Errors();
        $field = is_array( $context ) && isset( $context['staged_field'] ) && is_array( $context['staged_field'] )
            ? $context['staged_field']
            : null;
        $key = is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
        if ( $credentials === null ) {
            $errors->add_global( 'EFORMS_ERR_TOKEN' );
        }
        $credentials = is_array( $credentials ) ? $credentials : array();
        foreach ( $credentials as $credential_key => $value ) {
            if ( ! is_string( $credential_key ) || $key === '' || $credential_key !== $key ) {
                $errors->add_global( 'EFORMS_ERR_TOKEN' );
            }
        }

        $file_map = UploadValue::file_map_from_payload( $files );
        $rerender = array();
        if ( $key !== '' && isset( $file_map[ $key ] ) ) {
            $body_items = UploadValue::items( $file_map[ $key ] );
            foreach ( $body_items as $body_item ) {
                if ( ! UploadValue::is_no_file( $body_item ) ) {
                    $errors->add_field( $key, 'EFORMS_ERR_UPLOAD_TYPE', 'Staged photos must finish uploading before submission.' );
                    break;
                }
            }
        }

        $state = null;
        if ( $key !== '' && isset( $credentials[ $key ] ) ) {
            $entry = $credentials[ $key ];
            $entry_keys = is_array( $entry ) ? array_keys( $entry ) : array();
            sort( $entry_keys, SORT_STRING );
            if ( $entry_keys !== array( FormProtocol::UPLOAD_BATCH_ID, FormProtocol::UPLOAD_BATCH_SECRET ) ) {
                $errors->add_field( $key, 'EFORMS_ERR_TOKEN' );
            } else {
                $batch_id = isset( $entry[ FormProtocol::UPLOAD_BATCH_ID ] ) && is_string( $entry[ FormProtocol::UPLOAD_BATCH_ID ] )
                    ? $entry[ FormProtocol::UPLOAD_BATCH_ID ]
                    : '';
                $batch_secret = isset( $entry[ FormProtocol::UPLOAD_BATCH_SECRET ] ) && is_string( $entry[ FormProtocol::UPLOAD_BATCH_SECRET ] )
                    ? $entry[ FormProtocol::UPLOAD_BATCH_SECRET ]
                    : '';
                if ( $batch_id === '' || $batch_secret === '' ) {
                    $errors->add_field( $key, 'EFORMS_ERR_TOKEN' );
                } else {
                    $binding = array(
                        'raw_token' => self::post_string( $post, FormProtocol::FIELD_TOKEN ),
                        'form_id' => isset( $context['id'] ) ? $context['id'] : '',
                        'instance_id' => self::post_string( $post, FormProtocol::FIELD_INSTANCE_ID ),
                        'field_key' => $key,
                    );
                    $resolved = UploadBatchStore::resolve_open( $batch_id, $batch_secret, $binding, $field, $uploads_dir );
                    $phase = 'open';
                    if ( empty( $resolved['ok'] ) ) {
                        $resolved = UploadBatchStore::resolve_recovery(
                            $batch_id,
                            $batch_secret,
                            $binding,
                            $field,
                            isset( $security['submission_id'] ) ? $security['submission_id'] : '',
                            $uploads_dir
                        );
                        $phase = isset( $resolved['phase'] ) ? $resolved['phase'] : '';
                    }
                    if ( empty( $resolved['ok'] ) ) {
                        $errors->add_field( $key, 'EFORMS_ERR_TOKEN' );
                    } else {
                        $state = array(
                            'key' => $key,
                            'batch_id' => $batch_id,
                            'batch_secret' => $batch_secret,
                            'binding' => $binding,
                            'field' => $field,
                            'items' => isset( $resolved['items'] ) && is_array( $resolved['items'] ) ? $resolved['items'] : array(),
                            'phase' => $phase,
                            'accept_expired' => ! empty( $resolved['accept_expired'] ),
                            'preexisting_recovery' => $phase !== 'open',
                            'newly_claimed' => false,
                        );
                        if ( $phase === 'open' ) {
                            $rerender[ $key ] = array(
                                FormProtocol::UPLOAD_BATCH_ID => $batch_id,
                                FormProtocol::UPLOAD_BATCH_SECRET => $batch_secret,
                            );
                        }
                    }
                }
            }
        }

        return array( 'errors' => $errors, 'state' => $state, 'rerender' => $rerender );
    }

    /**
     * Restore a recovered pre-ledger claim while ledger reservation is excluded.
     * A durable marker keeps the aggregate terminal and its credentials hidden.
     */
    private static function restore_recovered_claims_before_ledger( &$staged, $form_id, $submission_id, $uploads_dir, $request ) {
        $state = isset( $staged['state'] ) && is_array( $staged['state'] ) ? $staged['state'] : null;
        if ( ! is_array( $state ) || ! isset( $state['phase'] ) || $state['phase'] !== 'finalizing' || empty( $state['preexisting_recovery'] ) ) {
            return true;
        }

        $restored = Ledger::run_if_unused(
            $form_id,
            $submission_id,
            $uploads_dir,
            function () use ( &$staged, $submission_id, $uploads_dir ) {
                $state = $staged['state'];
                if ( ! empty( $state['accept_expired'] ) ) {
                    $staged['rerender'][ $state['key'] ] = array(
                        FormProtocol::UPLOAD_BATCH_ID => $state['batch_id'],
                        FormProtocol::UPLOAD_BATCH_SECRET => $state['batch_secret'],
                    );
                    return array( 'ok' => true );
                }
                $reopened = UploadBatchStore::reopen_claim( $state['batch_id'], $submission_id, $uploads_dir );
                if ( empty( $reopened['ok'] ) ) {
                    return array( 'ok' => false );
                }
                $staged['state']['phase'] = 'open';
                $staged['state']['preexisting_recovery'] = false;
                $staged['state']['newly_claimed'] = false;
                $staged['rerender'][ $state['key'] ] = array(
                    FormProtocol::UPLOAD_BATCH_ID => $state['batch_id'],
                    FormProtocol::UPLOAD_BATCH_SECRET => $state['batch_secret'],
                );
                return array( 'ok' => true );
            },
            $request
        );

        if ( empty( $restored['ok'] ) ) {
            return ! empty( $restored['duplicate'] );
        }
        return isset( $restored['result']['ok'] ) && $restored['result']['ok'] === true;
    }

    private static function inject_staged_values( $normalized, $staged ) {
        $normalized = is_array( $normalized ) ? $normalized : array();
        if ( ! isset( $normalized['values'] ) || ! is_array( $normalized['values'] ) ) {
            $normalized['values'] = array();
        }
        $state = isset( $staged['state'] ) && is_array( $staged['state'] ) ? $staged['state'] : null;
        if ( is_array( $state ) ) {
            $normalized['values'][ $state['key'] ] = isset( $state['items'] ) && is_array( $state['items'] ) ? $state['items'] : array();
        }
        return $normalized;
    }

    private static function freeze_staged_uploads( &$staged, $submission_id, $uploads_dir ) {
        $state = isset( $staged['state'] ) && is_array( $staged['state'] ) ? $staged['state'] : null;
        if ( ! is_array( $state ) || $state['phase'] === 'finalized' || $state['phase'] === 'finalizing' ) {
            return array( 'ok' => true );
        }
        $claim = UploadBatchStore::claim_finalization(
            $state['batch_id'],
            $state['batch_secret'],
            $state['binding'],
            $state['field'],
            $state['items'],
            $submission_id,
            $uploads_dir
        );
        if ( empty( $claim['ok'] ) ) {
            return is_array( $claim )
                ? $claim
                : array( 'ok' => false, 'code' => 'EFORMS_ERR_STORAGE_UNAVAILABLE', 'reason' => 'claim_failed' );
        }
        $staged['state']['phase'] = 'finalizing';
        $staged['state']['newly_claimed'] = empty( $claim['recovered'] );
        $staged['state']['preexisting_recovery'] = ! empty( $claim['recovered'] );
        return array( 'ok' => true );
    }

    private static function staged_store_error_code( $result ) {
        return is_array( $result )
            && isset( $result['code'] )
            && is_string( $result['code'] )
            && $result['code'] !== ''
            ? $result['code']
            : 'EFORMS_ERR_STORAGE_UNAVAILABLE';
    }

    private static function staged_store_error_status( $code ) {
        return $code === 'EFORMS_ERR_TOKEN' ? 400 : 500;
    }

    private static function reopen_new_claims_if_unused( $staged, $form_id, $submission_id, $uploads_dir, $request ) {
        $state = isset( $staged['state'] ) && is_array( $staged['state'] ) ? $staged['state'] : null;
        if ( ! is_array( $state ) || empty( $state['newly_claimed'] ) ) {
            return;
        }

        Ledger::run_if_unused(
            $form_id,
            $submission_id,
            $uploads_dir,
            function () use ( $state, $submission_id, $uploads_dir ) {
                return UploadBatchStore::reopen_claim( $state['batch_id'], $submission_id, $uploads_dir );
            },
            $request
        );
    }

    private static function staged_recovery_allowed( $staged ) {
        $state = isset( $staged['state'] ) && is_array( $staged['state'] ) ? $staged['state'] : null;
        return is_array( $state ) && ! empty( $state['preexisting_recovery'] );
    }

    private static function finalize_staged_uploads( $staged, $submission_id, $uploads_dir ) {
        $state = isset( $staged['state'] ) && is_array( $staged['state'] ) ? $staged['state'] : null;
        if ( ! is_array( $state ) || $state['phase'] === 'finalized' ) {
            return array( 'ok' => true );
        }
        $result = UploadBatchStore::finalize( $state['batch_id'], $submission_id, $uploads_dir );
        if ( empty( $result['ok'] ) ) {
            return array( 'ok' => false );
        }
        return array( 'ok' => true );
    }

    private static function upload_credentials( $post ) {
        if ( ! is_array( $post ) || ! array_key_exists( FormProtocol::FIELD_UPLOAD_BATCHES, $post ) ) {
            return array();
        }
        return is_array( $post[ FormProtocol::FIELD_UPLOAD_BATCHES ] )
            ? $post[ FormProtocol::FIELD_UPLOAD_BATCHES ]
            : null;
    }

    private static function has_staged_recovery_candidate( $context, $credentials ) {
        $field = is_array( $context ) && isset( $context['staged_field'] ) && is_array( $context['staged_field'] )
            ? $context['staged_field']
            : null;
        $key = is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
        return $key !== '' && is_array( $credentials ) && array_key_exists( $key, $credentials );
    }

    private static function without_staged_descriptors( $context ) {
        $context = is_array( $context ) ? $context : array();
        $field = isset( $context['staged_field'] ) && is_array( $context['staged_field'] ) ? $context['staged_field'] : null;
        $key = is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : '';
        if ( $key === '' || ! isset( $context['descriptors'] ) || ! is_array( $context['descriptors'] ) ) {
            return $context;
        }
        $context['descriptors'] = array_values( array_filter(
            $context['descriptors'],
            function ( $descriptor ) use ( $key ) {
                return ! is_array( $descriptor ) || ! isset( $descriptor['key'] ) || $descriptor['key'] !== $key;
            }
        ) );
        return $context;
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

    private static function call_content_filter( $overrides, &$trace, $trace_on, $context, $coerced, $config ) {
        if ( ! ContentFilter::is_enabled( $config ) ) {
            return ContentFilter::evaluate( $context, $coerced, $config );
        }

        if ( $trace_on ) {
            $trace[] = 'content_filter';
        }

        $callable = self::override_callable( $overrides, 'content_filter' );
        if ( $callable ) {
            return call_user_func( $callable, $context, $coerced, $config );
        }

        return ContentFilter::evaluate( $context, $coerced, $config );
    }

    private static function call_commit( $overrides, &$trace, $trace_on, $context, $coerced, $security, $request, $config, $staged ) {
        if ( $trace_on ) {
            $trace[] = 'commit';
        }

        $callable = self::override_callable( $overrides, 'commit' );
        if ( $callable ) {
            return call_user_func( $callable, $context, $coerced, $security, $request, $config );
        }

        return self::default_commit( $context, $coerced, $security, $request, $config, $staged, $overrides );
    }

    private static function default_commit( $context, $coerced, $security, $request, $config, $staged, $overrides ) {
        $uploads_dir = self::uploads_dir( $config );
        $submission_id = is_array( $security ) && isset( $security['submission_id'] ) && is_string( $security['submission_id'] )
            ? $security['submission_id']
            : '';

        $move_context = self::without_staged_descriptors( $context );
        if ( ! empty( $staged['state'] ) ) {
            $move = UploadStore::move_staged_after_ledger( $move_context, $coerced, $submission_id, $uploads_dir );
        } else {
            $move = UploadStore::move_after_ledger( $move_context, $coerced, $submission_id, $uploads_dir );
        }
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

        $attempt = null;
        $before_transport = null;
        if ( ! empty( $staged['state'] ) ) {
            $before_transport = function () use ( $overrides, $submission_id, $uploads_dir, &$attempt ) {
                $before_marker = self::override_callable( $overrides, 'before_email_attempt_marker' );
                if ( $before_marker ) {
                    call_user_func( $before_marker, $submission_id, $uploads_dir );
                }
                $attempt = UploadBatchStore::mark_email_attempted( $submission_id, $uploads_dir );
                if ( empty( $attempt['ok'] ) ) {
                    return false;
                }
                $after_marker = self::override_callable( $overrides, 'after_email_attempt_marker' );
                if ( $after_marker ) {
                    call_user_func( $after_marker, $submission_id, $uploads_dir );
                }
                return true;
            };
        }

        $email = Emailer::send( $context, $values, $security, $request, $config, $before_transport );

        if ( is_array( $attempt ) && empty( $attempt['ok'] ) ) {
            // A concurrent winner may already be entering wp_mail() with these same recovered paths.
            return array(
                'ok' => false,
                'status' => 500,
                'error_code' => 'EFORMS_ERR_STORAGE_UNAVAILABLE',
                'reason' => 'email_attempt_marker_failed',
                'values' => $values,
                'stored' => $stored,
            );
        }

        if ( ! is_array( $email ) || empty( $email['ok'] ) ) {
            if ( empty( $staged['state'] ) || ( is_array( $attempt ) && ! empty( $attempt['ok'] ) ) ) {
                UploadStore::apply_retention( $stored, $config );
            }
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
        self::log_email_failure( $commit, $form_id, $security, $request );
        self::notify_admin_email_failure( $commit, $form_id, $security, $request );

        $errors = new Errors();
        $errors->add_global( 'EFORMS_ERR_EMAIL_SEND' );

        $result = array(
            'ok' => false,
            'status' => 500,
            'error_code' => 'EFORMS_ERR_EMAIL_SEND',
            'errors' => $errors,
            'mode' => isset( $security['mode'] ) ? $security['mode'] : '',
            'submission_id' => isset( $security['submission_id'] ) ? $security['submission_id'] : '',
            'form_id' => is_string( $form_id ) ? $form_id : '',
            'email_failed' => true,
            'soft_reasons' => isset( $security['soft_reasons'] ) && is_array( $security['soft_reasons'] ) ? $security['soft_reasons'] : array(),
        );

        if ( $trace_on ) {
            $result['trace'] = $trace;
        }

        return $result;
    }

    private static function log_email_failure( $commit, $form_id, $security, $request ) {
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

    private static function notify_admin_email_failure( $commit, $form_id, $security, $request ) {
        if ( self::$admin_email_failure_notified ) {
            return;
        }

        if ( ! function_exists( 'wp_mail' ) || ! function_exists( 'get_option' ) ) {
            return;
        }

        $admin_email = get_option( 'admin_email' );
        if ( ! is_string( $admin_email ) || $admin_email === '' ) {
            return;
        }

        if ( function_exists( 'is_email' ) && ! is_email( $admin_email ) ) {
            return;
        }

        self::$admin_email_failure_notified = true;

        $email = is_array( $commit ) && isset( $commit['email'] ) && is_array( $commit['email'] )
            ? $commit['email']
            : array();

        $lines = array(
            'An eForms submission could not be sent through wp_mail().',
            '',
            'This usually indicates a site mail transport configuration issue.',
            '',
            'Form: ' . ( is_string( $form_id ) ? $form_id : '' ),
            'Submission: ' . ( isset( $security['submission_id'] ) && is_string( $security['submission_id'] ) ? $security['submission_id'] : '' ),
            'Transport: ' . ( isset( $email['transport'] ) && is_string( $email['transport'] ) ? $email['transport'] : 'wp_mail' ),
            'Reason: ' . ( isset( $email['reason'] ) && is_string( $email['reason'] ) ? $email['reason'] : 'send_failed' ),
            'Error class: ' . ( isset( $email['error_class'] ) && is_string( $email['error_class'] ) ? $email['error_class'] : '' ),
            'Error message: ' . ( isset( $email['error_message'] ) && is_string( $email['error_message'] ) ? $email['error_message'] : '' ),
            'Request URI: ' . self::request_uri_for_admin_notice( $request ),
        );

        if ( function_exists( 'home_url' ) ) {
            $home = home_url();
            if ( is_string( $home ) && $home !== '' ) {
                $lines[] = 'Site: ' . $home;
            }
        }

        // This intentionally uses wp_mail(), so the site mail owner remains WordPress.
        wp_mail(
            $admin_email,
            '[eForms] Site mail delivery failed',
            implode( "\n", $lines )
        );
    }

    private static function request_uri_for_admin_notice( $request ) {
        if ( is_array( $request ) && isset( $request['uri'] ) && is_string( $request['uri'] ) ) {
            return $request['uri'];
        }

        if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
            return $_SERVER['REQUEST_URI'];
        }

        return '';
    }

    private static function spam_short_circuit_result( $trace_label, $error_code, $response, $files, $overrides, $form_id, $security, $security_meta, $uploads_dir, $request, $config, &$trace, $trace_on ) {
        if ( $trace_on ) {
            $trace[] = $trace_label;
        }

        Honeypot::cleanup_uploads( $files );

        if ( $trace_label === 'honeypot' ) {
            if ( self::token_ok( $security ) ) {
                self::call_spam_ledger_burn( $overrides, $form_id, $security['submission_id'], $uploads_dir, $request, $config );
            }
            Honeypot::log_event( $form_id, $security, $response, $request );
        } else {
            self::call_spam_ledger_burn( $overrides, $form_id, $security['submission_id'], $uploads_dir, $request, $config );
            self::log_spam_fail( $form_id, $security, $response, $config, $request );
        }

        if ( $response === 'hard_fail' ) {
            $errors = self::errors_for_code( $error_code );
            return self::error_result( 200, $errors, $security, $security_meta, $trace, $trace_on );
        }

        return self::honeypot_success_result( $security, $security_meta, $form_id, $trace, $trace_on );
    }

    private static function capture_declined_submission( $base, $decision_code, $decision_phase, $value_stage, $extra = array() ) {
        $config = isset( $base['config'] ) && is_array( $base['config'] ) ? $base['config'] : Config::get();
        if ( ! Config::bool( $config, array( 'declined_review', 'enable' ), false ) ) {
            return false;
        }
        if ( ! class_exists( 'DeclinedReviewLog' ) ) {
            require_once __DIR__ . '/../DeclinedReviewLog.php';
        }

        return DeclinedReviewLog::capture( array_merge( $base, array(
            'decision_code' => $decision_code,
            'decision_phase' => $decision_phase,
            'value_stage' => $value_stage,
        ), $extra ) );
    }

    private static function call_spam_ledger_burn( $overrides, $form_id, $submission_id, $uploads_dir, $request, $config ) {
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
            'error_code' => Errors::first_code( $errors ),
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

    private static function honeypot_success_result( $security, $security_meta, $form_id, $trace, $trace_on ) {
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
        $reserved = FormProtocol::reserved_field_key_map();

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
            'token' => self::post_string( $post, FormProtocol::FIELD_TOKEN ),
            'instance_id' => self::post_string( $post, FormProtocol::FIELD_INSTANCE_ID ),
            'timestamp' => self::post_string( $post, FormProtocol::FIELD_TIMESTAMP ),
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

    private static function log_spam_fail( $form_id, $security, $response, $config, $request ) {
        $soft_signal = Security::soft_signal_context( $security, $config );
        $meta = array(
            'form_id' => is_string( $form_id ) ? $form_id : '',
            'submission_id' => isset( $security['submission_id'] ) && is_string( $security['submission_id'] ) ? $security['submission_id'] : '',
            'mode' => isset( $security['mode'] ) && is_string( $security['mode'] ) ? $security['mode'] : '',
            'spam_decision' => 'fail',
            'soft_reasons' => $soft_signal['soft_reasons'],
            'soft_fail_count' => $soft_signal['soft_fail_count'],
            'threshold' => $soft_signal['threshold'],
            'stealth' => $response === Honeypot::RESPONSE_STEALTH_SUCCESS,
        );
        if ( isset( $security['content_filter'] ) && is_array( $security['content_filter'] ) ) {
            $meta['content_filter'] = $security['content_filter'];
        }

        Logging::event( 'warning', 'EFORMS_ERR_SPAM', $meta, $request );
    }

    private static function log_content_filter_suspect( $form_id, $security, $request ) {
        $meta = array(
            'form_id' => is_string( $form_id ) ? $form_id : '',
            'submission_id' => isset( $security['submission_id'] ) && is_string( $security['submission_id'] ) ? $security['submission_id'] : '',
            'mode' => isset( $security['mode'] ) && is_string( $security['mode'] ) ? $security['mode'] : '',
            'spam_decision' => 'suspect',
        );

        if ( isset( $security['content_filter'] ) && is_array( $security['content_filter'] ) ) {
            $meta['content_filter'] = $security['content_filter'];
        }

        Logging::event( 'info', 'EFORMS_CONTENT_FILTER_SUSPECT', $meta, $request );
    }

}
