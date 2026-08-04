<?php
/**
 * Signed bearer access for finalized managed-upload galleries.
 *
 * Contract: Managed review access
 * Contract: Signed gallery and file routes
 */

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../EformsAssets.php';
require_once __DIR__ . '/../FormProtocol.php';
require_once __DIR__ . '/../WordPressRuntime.php';
require_once __DIR__ . '/LocalPreviewProvider.php';
require_once __DIR__ . '/UploadBatchStore.php';
require_once __DIR__ . '/UploadPolicy.php';
require_once __DIR__ . '/WorkerClient.php';

class ReviewController {
    const DOMAIN = 'eforms-managed-review';
    const VERSION = '4';
    const ROUTE = 'review';
    private const ACTION_FIELD = 'eforms_review_action';
    private const DELETE_ACTION = 'delete_submission';
    private const DELETE_NONCE_FIELD = '_eforms_review_delete_nonce';
    private const AVAILABILITY_ACTION = 'update_availability';
    private const AVAILABILITY_NONCE_FIELD = '_eforms_review_availability_nonce';
    private const AVAILABILITY_CHOICE_FIELD = 'eforms_review_availability';

    private static $current_gallery_lightbox_enabled = false;

    public static function dispatch_current_request( $request = null, $overrides = array() ) {
        self::$current_gallery_lightbox_enabled = false;
        $request = is_array( $request ) ? $request : array();
        $overrides = is_array( $overrides ) ? $overrides : array();
        $method = isset( $request['method'] ) ? strtoupper( (string) $request['method'] ) : self::server_method();
        $parsed = self::parse_request( $request );
        if ( empty( $parsed['matched'] ) ) {
            return self::not_handled();
        }
        if ( ! self::clean_routes_available() ) {
            return self::unavailable();
        }
        $salt = self::salt( $overrides );
        $now = isset( $overrides['now'] ) && is_numeric( $overrides['now'] ) ? (int) $overrides['now'] : time();
        $uploads_dir = self::uploads_dir( $overrides );
        if ( $salt === '' || $uploads_dir === '' ) {
            return self::unavailable();
        }
        $grant = isset( $parsed['token'] )
            ? self::verify_token( $parsed['action'], $parsed['token'], $salt )
            : null;
        if ( ! is_array( $grant ) ) {
            return self::unavailable();
        }
        $submission_id = $grant['submission_id'];
        $upload_id = $grant['upload_id'];

        if ( $parsed['action'] === 'gallery' ) {
            if ( $method === 'POST' ) {
                return self::operator_post_response( $submission_id, $uploads_dir, $now, $salt, $overrides );
            }
            if ( $method !== 'GET' ) {
                return self::unavailable();
            }
            if ( self::worker_submission_context( $submission_id, $uploads_dir, $now ) !== null ) {
                return self::worker_gallery_response( $submission_id, $uploads_dir, $now, $salt, $overrides );
            }
            return self::gallery_response( $submission_id, $uploads_dir, $now, $salt, $overrides );
        }

        if ( $method !== 'GET' ) {
            return self::unavailable();
        }

        if ( $parsed['action'] === 'file' ) {
            if ( self::worker_submission_context( $submission_id, $uploads_dir, $now ) !== null ) {
                return self::worker_file_response(
                    $submission_id,
                    $upload_id,
                    $uploads_dir,
                    $now,
                    $overrides
                );
            }
            return self::file_response(
                $submission_id,
                $upload_id,
                $uploads_dir,
                $now
            );
        }

        if ( $parsed['action'] === 'preview' ) {
            if ( self::worker_submission_context( $submission_id, $uploads_dir, $now ) !== null ) {
                return self::worker_preview_response(
                    $submission_id,
                    $upload_id,
                    $uploads_dir,
                    $now,
                    $overrides
                );
            }
            return self::preview_response(
                $submission_id,
                $upload_id,
                $uploads_dir,
                $now,
                $overrides
            );
        }

        return self::unavailable();
    }

    public static function gallery_url( $submission_id, $base_url = null, $salt = null ) {
        $salt = is_string( $salt ) ? $salt : self::wordpress_salt();
        if ( $salt === '' ) {
            return '';
        }
        $token = self::token( 'gallery', $submission_id, '', $salt );
        if ( $token === '' ) {
            return '';
        }
        return self::review_url( 'gallery', $token, $base_url );
    }

    public static function email_gallery_reference( $submission_id, $expected_upload_ids, $uploads_dir, $base_url = null, $salt = null, $now = null ) {
        if ( ! self::valid_id( $submission_id, FormProtocol::managed_id_pattern() ) || ! is_array( $expected_upload_ids ) ) {
            return array( 'ok' => false );
        }
        $expected_ids = self::upload_ids( $expected_upload_ids );
        if ( empty( $expected_ids ) || count( $expected_ids ) !== count( $expected_upload_ids ) ) {
            return array( 'ok' => false );
        }

        $now = is_numeric( $now ) ? (int) $now : time();
        $loaded = UploadBatchStore::worker_submission( $submission_id, $uploads_dir, $now );
        if ( empty( $loaded['ok'] ) ) {
            $loaded = UploadBatchStore::submission( $submission_id, $uploads_dir, $now );
        }
        if ( empty( $loaded['ok'] ) || ! isset( $loaded['submission']['items'] ) || ! array_key_exists( 'delete_after', $loaded['submission'] ) ) {
            return array( 'ok' => false );
        }
        $artifact_store = isset( $loaded['submission']['artifact_store'] ) ? $loaded['submission']['artifact_store'] : '';
        $artifact_store_identity = isset( $loaded['submission']['artifact_store_identity'] ) ? $loaded['submission']['artifact_store_identity'] : '';
        if ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER && ! WorkerClient::composition_matches( $artifact_store_identity ) ) {
            return array( 'ok' => false );
        }
        $items = is_array( $loaded['submission']['items'] ) ? $loaded['submission']['items'] : array();
        $actual_ids = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['upload_id'] ) ) {
                return array( 'ok' => false );
            }
            $actual_ids[] = $item['upload_id'];
        }
        $actual_ids = self::upload_ids( $actual_ids );
        if ( $actual_ids !== $expected_ids || count( $actual_ids ) !== count( $items ) ) {
            return array( 'ok' => false );
        }

        $delete_after = $loaded['submission']['delete_after'];
        $url = self::gallery_url( $submission_id, $base_url, $salt );
        if ( $url === '' ) {
            return array( 'ok' => false );
        }
        return array(
            'ok' => true,
            'count' => count( $items ),
            'url' => $url,
            'available_label' => self::availability_label( $delete_after ),
        );
    }

    public static function file_url( $submission_id, $upload_id, $base_url = null, $salt = null ) {
        return self::member_url( 'file', $submission_id, $upload_id, $base_url, $salt );
    }

    public static function preview_url( $submission_id, $upload_id, $base_url = null, $salt = null ) {
        return self::member_url( 'preview', $submission_id, $upload_id, $base_url, $salt );
    }

    public static function enable_lightbox_for_current_review( $enabled, $id = null ) {
        return self::$current_gallery_lightbox_enabled ? true : $enabled;
    }

    public static function worker_gallery_response( $submission_id, $uploads_dir, $now, $salt, $overrides = array() ) {
        $loaded = self::worker_submission_context( $submission_id, $uploads_dir, $now );
        if ( ! is_array( $loaded ) ) {
            return self::unavailable();
        }
        $submission = $loaded['submission'];
        $artifact_store_identity = $loaded['artifact_store_identity'];
        $gallery_items = self::worker_gallery_items( $submission['items'], $artifact_store_identity );
        if ( $gallery_items === null ) {
            return self::unavailable();
        }

        $status_client = isset( $overrides['worker_gallery_status'] ) && is_callable( $overrides['worker_gallery_status'] )
            ? $overrides['worker_gallery_status']
            : function ( $worker_submission_id, $storage_identity, $items, $expected_identity, $worker_now ) {
                return WorkerClient::worker_gallery_status( $worker_submission_id, $storage_identity, $items, $expected_identity, $worker_now );
            };
        $status = call_user_func(
            $status_client,
            $submission_id,
            $artifact_store_identity,
            $gallery_items,
            $artifact_store_identity,
            $now
        );
        $statuses = ! empty( $status['ok'] ) && isset( $status['statuses'] )
            ? WorkerProtocol::normalize_worker_gallery_statuses( $status['statuses'], $gallery_items )
            : null;
        if ( $statuses === null ) {
            return self::worker_gallery_status_unavailable_response( $submission, $submission_id, $uploads_dir, $now, $salt, $overrides );
        }

        $base_url = isset( $overrides['base_url'] ) && is_string( $overrides['base_url'] ) ? $overrides['base_url'] : null;
        $items = array();
        $has_pending = false;
        foreach ( $statuses as $item_status ) {
            $review_item = array( 'status' => $item_status['status'] );
            if ( $item_status['status'] === 'pending' ) {
                $has_pending = true;
            }
            if ( $item_status['status'] === 'accepted' ) {
                $download_url = self::file_url( $submission_id, $item_status['upload_id'], $base_url, $salt );
                $preview_url = self::preview_url( $submission_id, $item_status['upload_id'], $base_url, $salt );
                if ( $download_url === '' || $preview_url === '' ) {
                    return self::unavailable();
                }
                $preview_dimensions = self::preview_dimensions(
                    (int) $item_status['width'],
                    (int) $item_status['height']
                );
                $review_item['download_url'] = $download_url;
                $review_item['preview_url'] = $preview_url;
                $review_item['preview_width'] = $preview_dimensions['width'];
                $review_item['preview_height'] = $preview_dimensions['height'];
                $review_item['original_inline_available'] = false;
            }
            $items[] = $review_item;
        }

        $response = self::worker_gallery_page_response( $submission, $submission_id, $uploads_dir, $now, $salt, $items, $overrides, 200 );
        if ( $has_pending ) {
            $response['review_page']['refresh_url'] = self::gallery_url( $submission_id, $base_url, $salt );
        }
        return $response;
    }

    public static function worker_file_response( $submission_id, $upload_id, $uploads_dir, $now, $overrides = array() ) {
        return self::worker_member_response( $submission_id, $upload_id, $uploads_dir, $now, $overrides, 'download' );
    }

    public static function worker_preview_response( $submission_id, $upload_id, $uploads_dir, $now, $overrides = array() ) {
        return self::worker_member_response( $submission_id, $upload_id, $uploads_dir, $now, $overrides, 'preview' );
    }

    public static function prevent_canonical_redirect( $redirect_url, $requested_url = '' ) {
        $uri = is_string( $requested_url ) && $requested_url !== ''
            ? $requested_url
            : ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
        $parsed = self::parse_request(
            array(
                'uri' => $uri,
                'query' => isset( $_GET ) && is_array( $_GET ) ? $_GET : array(),
            )
        );
        return empty( $parsed['matched'] ) ? $redirect_url : false;
    }

    public static function register_rewrite_rule() {
        if ( function_exists( 'add_rewrite_rule' ) ) {
            add_rewrite_rule( '^' . self::ROUTE . '/(?:(?:file|preview)/)?[A-Za-z0-9_-]{1,' . self::maximum_token_chars() . '}$', 'index.php', 'top' );
        }
    }

    public static function emit_headers( $response ) {
        if ( ! is_array( $response ) ) {
            return;
        }
        $status = isset( $response['status'] ) ? (int) $response['status'] : 404;
        $location = isset( $response['location'] ) && is_string( $response['location'] ) ? $response['location'] : '';
        $redirected = false;
        if ( $location !== '' ) {
            $allowed_origin = isset( $response['redirect_origin'] ) && is_string( $response['redirect_origin'] )
                ? $response['redirect_origin']
                : '';
            $redirected = WordPressRuntime::external_redirect( $location, $status, $allowed_origin );
            if ( ! $redirected ) {
                $status = 404;
            }
        }
        if ( function_exists( 'status_header' ) ) {
            status_header( $status );
        } elseif ( function_exists( 'http_response_code' ) ) {
            http_response_code( $status );
        }
        $headers = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
        foreach ( $headers as $name => $value ) {
            if ( is_string( $name ) && strtolower( $name ) !== 'location' && $name !== '' && is_string( $value ) && $value !== '' && ! headers_sent() ) {
                header( $name . ': ' . $value, true );
            }
        }
    }

    private static function gallery_response( $submission_id, $uploads_dir, $now, $salt, $overrides ) {
        $loaded = UploadBatchStore::submission( $submission_id, $uploads_dir, $now );
        if ( empty( $loaded['ok'] ) || ! isset( $loaded['submission'] ) || ! is_array( $loaded['submission'] ) ) {
            if ( self::can_delete_review() ) {
                $management = self::expired_management_response( $submission_id, $uploads_dir, $now, $salt, $overrides );
                if ( $management !== false ) {
                    return $management;
                }
            }
            return self::unavailable();
        }
        $submission = $loaded['submission'];

        $base_url = isset( $overrides['base_url'] ) && is_string( $overrides['base_url'] ) ? $overrides['base_url'] : null;
        $artifact_store = isset( $submission['artifact_store'] ) ? $submission['artifact_store'] : '';
        $artifact_store_identity = isset( $submission['artifact_store_identity'] ) ? $submission['artifact_store_identity'] : '';
        $provider = self::review_provider( $overrides );
        if ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER
            && ! WorkerClient::composition_matches( $artifact_store_identity )
        ) {
            return self::unavailable();
        }
        $items = array();
        foreach ( isset( $submission['items'] ) && is_array( $submission['items'] ) ? $submission['items'] : array() as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['upload_id'] ) || ! is_string( $item['upload_id'] ) ) {
                return self::unavailable();
            }
            $preview_url = '';
            $download_url = self::file_url( $submission_id, $item['upload_id'], $base_url, $salt );
            if ( $artifact_store === FormProtocol::UPLOAD_TRANSPORT_WORKER || $provider === 'local' ) {
                $preview_url = self::preview_url( $submission_id, $item['upload_id'], $base_url, $salt );
            }
            if ( $download_url === '' ) {
                return self::unavailable();
            }
            $review_item = array(
                'download_url' => $download_url,
                'preview_url' => $preview_url,
                'original_inline_available' => $artifact_store === FormProtocol::UPLOAD_TRANSPORT_LOCAL
                    && UploadPolicy::staged_mime_has_browser_fallback( isset( $item['mime'] ) ? $item['mime'] : '' ),
            );
            if ( $preview_url !== '' ) {
                $preview_dimensions = self::preview_dimensions(
                    isset( $item['width'] ) ? (int) $item['width'] : 0,
                    isset( $item['height'] ) ? (int) $item['height'] : 0
                );
                $review_item['preview_width'] = $preview_dimensions['width'];
                $review_item['preview_height'] = $preview_dimensions['height'];
            }
            $items[] = $review_item;
        }

        self::enqueue_assets();
        self::$current_gallery_lightbox_enabled = true;

        $can_manage = self::can_delete_review();
        $review_page = array(
            'title' => 'Submitted Photos',
            'submission_id' => $submission_id,
            'items' => $items,
            'submitted_label' => self::submitted_label( isset( $submission['finalized_at'] ) ? $submission['finalized_at'] : null ),
            'availability_label' => self::availability_label( array_key_exists( 'delete_after', $submission ) ? $submission['delete_after'] : null ),
            'can_delete' => $can_manage,
            'template' => dirname( __DIR__, 2 ) . '/templates/pages/review-gallery.php',
        );
        if ( $can_manage ) {
            self::add_operator_lead_review( $review_page, $submission_id, $uploads_dir );
        } else {
            self::add_public_project_summary( $review_page, $submission_id, $uploads_dir );
        }
        if ( ! empty( $review_page['can_delete'] ) ) {
            $selected_choice = isset( $overrides['availability_selected_choice'] ) && is_string( $overrides['availability_selected_choice'] )
                ? $overrides['availability_selected_choice']
                : '';
            self::add_operator_actions( $review_page, $submission_id, $base_url, $salt, true, array_key_exists( 'delete_after', $submission ) ? $submission['delete_after'] : null, $selected_choice );
        }

        return array(
            'handled' => true,
            'render' => 'review_gallery',
            'status' => 200,
            'location' => '',
            'body' => '',
            'headers' => self::private_headers( 'text/html; charset=UTF-8' ),
            'review_page' => $review_page,
            'result' => array( 'ok' => true ),
        );
    }

    private static function expired_management_response( $submission_id, $uploads_dir, $now, $salt, $overrides ) {
        $loaded = UploadBatchStore::submission_management_status( $submission_id, $uploads_dir, $now );
        if ( empty( $loaded['ok'] ) || empty( $loaded['submission']['expired'] ) ) {
            return false;
        }
        $base_url = isset( $overrides['base_url'] ) && is_string( $overrides['base_url'] ) ? $overrides['base_url'] : null;
        $submission = $loaded['submission'];
        self::enqueue_assets();
        $review_page = array(
            'title' => 'Submitted Photos',
            'submission_id' => $submission_id,
            'items' => array(),
            'expired' => true,
            'submitted_label' => self::submitted_label( isset( $submission['finalized_at'] ) ? $submission['finalized_at'] : null ),
            'availability_label' => self::availability_label( $submission['delete_after'] ),
            'can_delete' => true,
            'template' => dirname( __DIR__, 2 ) . '/templates/pages/review-gallery.php',
        );
        self::add_operator_actions( $review_page, $submission_id, $base_url, $salt, false );
        return array(
            'handled' => true,
            'render' => 'review_gallery',
            'status' => 200,
            'location' => '',
            'body' => '',
            'headers' => self::private_headers( 'text/html; charset=UTF-8' ),
            'review_page' => $review_page,
            'result' => array( 'ok' => true, 'expired' => true ),
        );
    }

    private static function add_operator_actions( &$review_page, $submission_id, $base_url, $salt, $include_availability, $delete_after = null, $selected_choice = '' ) {
        $review_page['operator_action_url'] = self::gallery_url( $submission_id, $base_url, $salt );
        $review_page['operator_action_field'] = self::ACTION_FIELD;
        $review_page['delete_action'] = self::DELETE_ACTION;
        $review_page['delete_nonce_action'] = self::delete_nonce_action( $submission_id );
        $review_page['delete_nonce_field'] = self::DELETE_NONCE_FIELD;
        if ( ! $include_availability ) {
            return;
        }
        $review_page['availability_action'] = self::AVAILABILITY_ACTION;
        $review_page['availability_nonce_action'] = self::availability_nonce_action( $submission_id );
        $review_page['availability_nonce_field'] = self::AVAILABILITY_NONCE_FIELD;
        $review_page['availability_choice_field'] = self::AVAILABILITY_CHOICE_FIELD;
        $review_page['availability_options'] = self::availability_options( $delete_after, $selected_choice );
    }

    private static function add_public_project_summary( &$review_page, $submission_id, $uploads_dir ) {
        $loaded = UploadBatchStore::review_snapshot( $submission_id, $uploads_dir );
        if ( empty( $loaded['ok'] ) || ! isset( $loaded['snapshot'] ) || ! is_array( $loaded['snapshot'] ) ) {
            return;
        }

        $summary = SubmissionReviewSnapshot::public_summary( $loaded['snapshot'] );
        if ( empty( $summary['ok'] ) || ! isset( $summary['summary']['details'] ) || ! is_array( $summary['summary']['details'] ) || empty( $summary['summary']['details'] ) ) {
            return;
        }

        $rows = self::review_fact_rows( $summary['summary']['details'] );
        if ( empty( $rows ) ) {
            return;
        }
        $review_page['review_facts'] = array(
            'aria_label' => 'Project summary',
            'groups' => array(
                array(
                    'layout' => 'project',
                    'rows' => $rows,
                ),
            ),
        );
    }

    private static function add_operator_lead_review( &$review_page, $submission_id, $uploads_dir ) {
        $loaded = UploadBatchStore::review_snapshot( $submission_id, $uploads_dir );
        if ( empty( $loaded['ok'] ) || ! isset( $loaded['snapshot'] ) || ! is_array( $loaded['snapshot'] ) ) {
            return;
        }
        $operator = SubmissionReviewSnapshot::operator_review( $loaded['snapshot'] );
        if ( empty( $operator['ok'] ) || ! isset( $operator['review'] ) || ! is_array( $operator['review'] ) ) {
            return;
        }
        $title = isset( $operator['review']['title'] ) && is_string( $operator['review']['title'] ) ? $operator['review']['title'] : '';
        if ( $title !== '' ) {
            $review_page['title'] = $title;
        }

        $header = isset( $operator['review']['header'] ) && is_array( $operator['review']['header'] ) ? $operator['review']['header'] : array();
        $details = isset( $operator['review']['details'] ) && is_array( $operator['review']['details'] ) ? $operator['review']['details'] : array();
        $contact = array();
        $project = array();
        foreach ( array_merge( $header, $details ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $key = isset( $row['key'] ) && is_string( $row['key'] ) ? $row['key'] : '';
            $value = isset( $row['value'] ) && is_string( $row['value'] ) ? $row['value'] : '';
            if ( $key === 'name' ) {
                if ( $value !== '' ) {
                    $review_page['attribution_name'] = $value;
                }
                continue;
            }
            if ( $key === 'zip_us' || $key === 'email' || $key === 'tel_us' ) {
                $contact[] = $row;
            } else {
                $project[] = $row;
            }
        }

        $groups = array();
        $contact = self::review_fact_rows( $contact );
        $project = self::review_fact_rows( $project );
        if ( ! empty( $contact ) ) {
            $groups[] = array( 'layout' => 'equal', 'rows' => $contact );
        }
        if ( ! empty( $project ) ) {
            $groups[] = array( 'layout' => 'equal', 'rows' => $project );
        }
        if ( ! empty( $groups ) ) {
            $review_page['review_facts'] = array(
                'aria_label' => 'Lead details',
                'groups' => $groups,
            );
        }
    }

    private static function review_fact_rows( $rows ) {
        $facts = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $label = isset( $row['label'] ) && is_string( $row['label'] ) ? $row['label'] : '';
            $value = isset( $row['value'] ) && is_string( $row['value'] ) ? $row['value'] : '';
            if ( $label === '' || $value === '' ) {
                continue;
            }
            $key = isset( $row['key'] ) && is_string( $row['key'] ) ? $row['key'] : '';
            $type = isset( $row['type'] ) && is_string( $row['type'] ) ? $row['type'] : '';
            $facts[] = array(
                'label' => $label,
                'value' => $value,
                'href' => $type === 'url' ? $value : '',
                'wide' => $key === 'project_description',
            );
        }

        return $facts;
    }

    private static function member_url( $action, $submission_id, $upload_id, $base_url, $salt ) {
        $salt = is_string( $salt ) ? $salt : self::wordpress_salt();
        if ( $salt === '' ) {
            return '';
        }
        $token = self::token( $action, $submission_id, $upload_id, $salt );
        if ( $token === '' ) {
            return '';
        }
        return self::review_url( $action, $token, $base_url );
    }

    private static function operator_post_response( $submission_id, $uploads_dir, $now, $salt, $overrides ) {
        if ( ! self::can_delete_review() ) {
            return self::unavailable();
        }
        $post = self::post_payload( $overrides );
        $action = isset( $post[ self::ACTION_FIELD ] ) && is_string( $post[ self::ACTION_FIELD ] )
            ? $post[ self::ACTION_FIELD ]
            : '';
        if ( $action === self::DELETE_ACTION ) {
            return self::delete_submission_response( $submission_id, $uploads_dir, $now, $post, $overrides );
        }
        if ( $action === self::AVAILABILITY_ACTION ) {
            return self::update_availability_response( $submission_id, $uploads_dir, $now, $salt, $post, $overrides );
        }
        return self::unavailable();
    }

    private static function delete_submission_response( $submission_id, $uploads_dir, $now, $post, $overrides ) {
        $nonce = isset( $post[ self::DELETE_NONCE_FIELD ] ) && is_string( $post[ self::DELETE_NONCE_FIELD ] )
            ? $post[ self::DELETE_NONCE_FIELD ]
            : '';
        if ( ! self::verify_nonce( $nonce, self::delete_nonce_action( $submission_id ) ) ) {
            return self::unavailable();
        }
        if ( ! self::review_submission_matches_current_composition( $submission_id, $uploads_dir, $now ) ) {
            return self::unavailable();
        }
        $remote_delete = isset( $overrides['remote_delete'] ) && is_callable( $overrides['remote_delete'] )
            ? $overrides['remote_delete']
            : function ( $authority ) use ( $now ) {
                return WorkerClient::worker_delete_object(
                    $authority,
                    $now,
                    null,
                    'operator_delete'
                );
            };
        $deleted = UploadBatchStore::delete_finalized_submission( $submission_id, $uploads_dir, $now, $remote_delete );
        if ( empty( $deleted['ok'] ) ) {
            return self::unavailable();
        }

        self::enqueue_assets();

        return array(
            'handled' => true,
            'render' => 'review_gallery',
            'status' => 200,
            'location' => '',
            'body' => '',
            'headers' => self::private_headers( 'text/html; charset=UTF-8' ),
            'review_page' => array(
                'title' => 'Review Deleted',
                'submission_id' => $submission_id,
                'items' => array(),
                'deleted' => true,
                'template' => dirname( __DIR__, 2 ) . '/templates/pages/review-gallery.php',
            ),
            'result' => array(
                'ok' => true,
                'deleted' => true,
                'physical_delete_pending' => ! empty( $deleted['physical_delete_pending'] ),
            ),
        );
    }

    private static function update_availability_response( $submission_id, $uploads_dir, $now, $salt, $post, $overrides ) {
        $nonce = isset( $post[ self::AVAILABILITY_NONCE_FIELD ] ) && is_string( $post[ self::AVAILABILITY_NONCE_FIELD ] )
            ? $post[ self::AVAILABILITY_NONCE_FIELD ]
            : '';
        $choice = isset( $post[ self::AVAILABILITY_CHOICE_FIELD ] ) && is_string( $post[ self::AVAILABILITY_CHOICE_FIELD ] )
            ? $post[ self::AVAILABILITY_CHOICE_FIELD ]
            : '';
        if ( ! self::verify_nonce( $nonce, self::availability_nonce_action( $submission_id ) ) ) {
            return self::unavailable();
        }
        $delete_after = self::availability_delete_after( $choice, $now );
        if ( $delete_after === false ) {
            return self::unavailable();
        }
        if ( ! self::review_submission_matches_current_composition( $submission_id, $uploads_dir, $now ) ) {
            return self::unavailable();
        }
        $updated = UploadBatchStore::update_finalized_availability( $submission_id, $uploads_dir, $delete_after, $now );
        if ( empty( $updated['ok'] ) ) {
            return self::unavailable();
        }
        $overrides['availability_selected_choice'] = $choice;
        if ( self::worker_submission_context( $submission_id, $uploads_dir, $now ) !== null ) {
            return self::worker_gallery_response( $submission_id, $uploads_dir, $now, $salt, $overrides );
        }
        return self::gallery_response( $submission_id, $uploads_dir, $now, $salt, $overrides );
    }

    private static function review_submission_matches_current_composition( $submission_id, $uploads_dir, $now ) {
        $loaded = UploadBatchStore::submission_management_status( $submission_id, $uploads_dir, $now );
        if ( empty( $loaded['ok'] ) || ! isset( $loaded['submission'] ) || ! is_array( $loaded['submission'] ) ) {
            return false;
        }
        if ( ! isset( $loaded['submission']['artifact_store'] )
            || $loaded['submission']['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
        ) {
            return true;
        }
        $identity = isset( $loaded['submission']['artifact_store_identity'] ) && is_string( $loaded['submission']['artifact_store_identity'] )
            ? $loaded['submission']['artifact_store_identity']
            : '';
        return WorkerClient::composition_matches( $identity );
    }

    private static function preview_dimensions( $width, $height ) {
        $width = is_int( $width ) ? $width : 0;
        $height = is_int( $height ) ? $height : 0;
        if ( $width < 1 || $height < 1 ) {
            return array( 'width' => 0, 'height' => 0 );
        }
        $edge = Anchors::get( 'REVIEW_PREVIEW_MAX_EDGE' );
        if ( ! is_int( $edge ) || $edge < 1 ) {
            return array( 'width' => $width, 'height' => $height );
        }
        $scale = min( 1, $edge / max( $width, $height ) );
        return array(
            'width' => max( 1, (int) round( $width * $scale ) ),
            'height' => max( 1, (int) round( $height * $scale ) ),
        );
    }

    private static function worker_submission_context( $submission_id, $uploads_dir, $now ) {
        $loaded = UploadBatchStore::worker_submission( $submission_id, $uploads_dir, $now );
        $submission = ! empty( $loaded['ok'] ) && isset( $loaded['submission'] ) && is_array( $loaded['submission'] )
            ? $loaded['submission']
            : null;
        if ( ! is_array( $submission )
            || ! isset( $submission['artifact_store'], $submission['artifact_store_identity'], $submission['items'] )
            || $submission['artifact_store'] !== FormProtocol::UPLOAD_TRANSPORT_WORKER
            || ! is_string( $submission['artifact_store_identity'] )
            || ! WorkerClient::composition_matches( $submission['artifact_store_identity'] )
            || ! is_array( $submission['items'] )
        ) {
            return null;
        }
        return array(
            'submission' => $submission,
            'artifact_store_identity' => $submission['artifact_store_identity'],
        );
    }

    private static function worker_gallery_items( $items, $artifact_store_identity ) {
        if ( ! is_array( $items ) || ! is_string( $artifact_store_identity ) ) {
            return null;
        }
        $gallery_items = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item )
                || ! isset(
                    $item['upload_id'],
                    $item['ordinal'],
                    $item['validation_contract_version'],
                    $item['object_key'],
                    $item['object_version'],
                    $item['etag'],
                    $item['bytes'],
                    $item['policy_fingerprint'],
                    $item['storage_identity'],
                    $item['validation_until']
                )
                || ! is_string( $item['storage_identity'] )
                || ! hash_equals( $artifact_store_identity, $item['storage_identity'] )
            ) {
                return null;
            }
            $gallery_items[] = array(
                'upload_id' => $item['upload_id'],
                'ordinal' => (int) $item['ordinal'],
                'validation_contract_version' => $item['validation_contract_version'],
                'object_key' => $item['object_key'],
                'object_version' => $item['object_version'],
                'etag' => $item['etag'],
                'bytes' => (int) $item['bytes'],
                'policy_fingerprint' => $item['policy_fingerprint'],
                'validation_until' => (int) $item['validation_until'],
            );
        }
        return WorkerProtocol::normalize_worker_gallery_items( $gallery_items );
    }

    private static function worker_gallery_status_unavailable_response( $submission, $submission_id, $uploads_dir, $now, $salt, $overrides ) {
        $base_url = isset( $overrides['base_url'] ) && is_string( $overrides['base_url'] ) ? $overrides['base_url'] : null;
        $response = self::worker_gallery_page_response( $submission, $submission_id, $uploads_dir, $now, $salt, array(), $overrides, 503 );
        $response['review_page']['status_unavailable'] = true;
        $response['review_page']['refresh_url'] = self::gallery_url( $submission_id, $base_url, $salt );
        $response['result'] = array( 'ok' => false, 'status_unavailable' => true );
        return $response;
    }

    private static function worker_gallery_page_response( $submission, $submission_id, $uploads_dir, $now, $salt, $items, $overrides, $status ) {
        self::enqueue_assets();
        self::$current_gallery_lightbox_enabled = self::worker_gallery_has_preview_cards( $items );
        $base_url = isset( $overrides['base_url'] ) && is_string( $overrides['base_url'] ) ? $overrides['base_url'] : null;
        $can_manage = self::can_delete_review();
        $review_page = array(
            'title' => 'Submitted Photos',
            'submission_id' => $submission_id,
            'items' => $items,
            'submitted_label' => self::submitted_label( isset( $submission['finalized_at'] ) ? $submission['finalized_at'] : null ),
            'availability_label' => self::availability_label( array_key_exists( 'delete_after', $submission ) ? $submission['delete_after'] : null ),
            'can_delete' => $can_manage,
            'template' => dirname( __DIR__, 2 ) . '/templates/pages/review-gallery.php',
        );
        if ( $can_manage ) {
            self::add_operator_lead_review( $review_page, $submission_id, $uploads_dir );
        } else {
            self::add_public_project_summary( $review_page, $submission_id, $uploads_dir );
        }
        if ( ! empty( $review_page['can_delete'] ) ) {
            $selected_choice = isset( $overrides['availability_selected_choice'] ) && is_string( $overrides['availability_selected_choice'] )
                ? $overrides['availability_selected_choice']
                : '';
            self::add_operator_actions( $review_page, $submission_id, $base_url, $salt, true, array_key_exists( 'delete_after', $submission ) ? $submission['delete_after'] : null, $selected_choice );
        }
        return array(
            'handled' => true,
            'render' => 'review_gallery',
            'status' => $status,
            'location' => '',
            'body' => '',
            'headers' => self::private_headers( 'text/html; charset=UTF-8' ),
            'review_page' => $review_page,
            'result' => array( 'ok' => $status === 200 ),
        );
    }

    private static function worker_gallery_has_preview_cards( $items ) {
        foreach ( is_array( $items ) ? $items : array() as $item ) {
            if ( is_array( $item )
                && isset( $item['status'], $item['preview_url'] )
                && $item['status'] === 'accepted'
                && is_string( $item['preview_url'] )
                && $item['preview_url'] !== ''
            ) {
                return true;
            }
        }
        return false;
    }

    private static function worker_member_response( $submission_id, $upload_id, $uploads_dir, $now, $overrides, $action ) {
        $loaded = self::worker_submission_context( $submission_id, $uploads_dir, $now );
        if ( ! is_array( $loaded ) ) {
            return self::unavailable();
        }
        $submission = $loaded['submission'];
        $artifact_store_identity = $loaded['artifact_store_identity'];
        $item = self::worker_submission_item( $submission['items'], $upload_id, $artifact_store_identity );
        if ( ! is_array( $item ) ) {
            return self::unavailable();
        }

        $expires_at = (int) $now + Anchors::get( 'WORKER_REVIEW_GRANT_TTL_SECONDS' );
        if ( array_key_exists( 'delete_after', $submission ) && is_numeric( $submission['delete_after'] ) ) {
            $expires_at = min( $expires_at, (int) $submission['delete_after'] );
        }
        if ( $expires_at <= (int) $now ) {
            return self::unavailable();
        }

        $claims = array(
            'submission_id' => $submission_id,
            'upload_id' => $upload_id,
            'storage_identity' => $item['storage_identity'],
            'validation_contract_version' => $item['validation_contract_version'],
            'object_key' => $item['object_key'],
            'object_version' => $item['object_version'],
            'etag' => $item['etag'],
            'bytes' => (int) $item['bytes'],
            'policy_fingerprint' => $item['policy_fingerprint'],
            'validation_until' => (int) $item['validation_until'],
            'action' => $action,
            'recipe_version' => WorkerProtocol::REVIEW_RECIPE_VERSION,
            'expires_at' => $expires_at,
        );
        $review_url = isset( $overrides['worker_review_url'] ) && is_callable( $overrides['worker_review_url'] )
            ? call_user_func( $overrides['worker_review_url'], $claims, $artifact_store_identity, $now )
            : WorkerClient::worker_review_url( $claims, $artifact_store_identity, $now );
        if ( ! is_string( $review_url ) || $review_url === '' ) {
            return self::unavailable();
        }
        return array(
            'handled' => true,
            'render' => 'review_file',
            'status' => 302,
            'location' => $review_url,
            'redirect_origin' => WorkerClient::origin(),
            'body' => '',
            'headers' => self::private_headers( 'text/html; charset=UTF-8' ),
            'result' => array( 'ok' => true ),
        );
    }

    private static function worker_submission_item( $items, $upload_id, $artifact_store_identity ) {
        if ( ! is_array( $items ) || ! is_string( $upload_id ) || ! is_string( $artifact_store_identity ) ) {
            return null;
        }
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['upload_id'] ) || $item['upload_id'] !== $upload_id ) {
                continue;
            }
            if ( ! isset(
                $item['storage_identity'],
                $item['validation_contract_version'],
                $item['object_key'],
                $item['object_version'],
                $item['etag'],
                $item['bytes'],
                $item['policy_fingerprint'],
                $item['validation_until']
            )
                || ! is_string( $item['storage_identity'] )
                || ! hash_equals( $artifact_store_identity, $item['storage_identity'] )
                || ! is_numeric( $item['validation_until'] )
            ) {
                return null;
            }
            return $item;
        }
        return null;
    }

    private static function file_response( $submission_id, $upload_id, $uploads_dir, $now ) {
        $loaded = UploadBatchStore::submission_file( $submission_id, $upload_id, $uploads_dir, $now );
        $artifact = ! empty( $loaded['ok'] ) && isset( $loaded['artifact'] ) && is_array( $loaded['artifact'] )
            ? $loaded['artifact']
            : null;
        if ( ! is_array( $artifact ) ) {
            return self::unavailable();
        }
        if ( ! isset( $artifact['stream'], $artifact['mime'], $artifact['bytes'] )
            || ! is_resource( $artifact['stream'] )
        ) {
            if ( isset( $artifact['stream'] ) && is_resource( $artifact['stream'] ) ) {
                fclose( $artifact['stream'] );
            }
            return self::unavailable();
        }
        $stream = $artifact['stream'];
        $actual_bytes = (int) $artifact['bytes'];
        $headers = self::private_headers( (string) $artifact['mime'] );
        $headers['Content-Length'] = (string) $actual_bytes;
        $headers['Content-Disposition'] = self::content_disposition(
            isset( $artifact['display_name'] ) ? $artifact['display_name'] : '',
            (string) $artifact['mime']
        );
        return array(
            'handled' => true,
            'render' => 'review_file',
            'status' => 200,
            'location' => '',
            'body' => '',
            'stream' => $stream,
            'headers' => $headers,
            'result' => array( 'ok' => true ),
        );
    }

    private static function preview_response( $submission_id, $upload_id, $uploads_dir, $now, $overrides ) {
        $loaded = UploadBatchStore::submission_preview_source( $submission_id, $upload_id, $uploads_dir, $now );
        $artifact = ! empty( $loaded['ok'] ) && isset( $loaded['artifact'] ) && is_array( $loaded['artifact'] )
            ? $loaded['artifact']
            : null;
        if ( ! is_array( $artifact ) ) {
            return self::unavailable();
        }
        if ( self::review_provider( $overrides ) !== 'local' ) {
            return self::unavailable();
        }
        $concurrency = isset( $overrides['local_preview_concurrency'] ) && is_int( $overrides['local_preview_concurrency'] )
            ? $overrides['local_preview_concurrency']
            : WorkerClient::local_preview_concurrency();
        $preview = LocalPreviewProvider::render(
            $artifact,
            $uploads_dir,
            $concurrency,
            isset( $overrides['local_preview_encoder'] ) ? $overrides['local_preview_encoder'] : null,
            isset( $overrides['local_preview_admission'] ) && is_callable( $overrides['local_preview_admission'] )
                ? $overrides['local_preview_admission']
                : function ( $lifecycle, $path, $bytes ) use ( $uploads_dir ) {
                    return UploadBatchStore::reserve_preview_cache_allocation( $uploads_dir, $lifecycle, $path, $bytes );
                }
        );
        if ( empty( $preview['ok'] ) ) {
            if ( ! empty( $preview['transient'] ) ) {
                $response = self::unavailable();
                $response['status'] = 503;
                $response['headers']['Retry-After'] = (string) ( isset( $preview['retry_after'] ) ? (int) $preview['retry_after'] : 2 );
                return $response;
            }
            return self::unavailable();
        }
        $headers = self::private_headers( 'image/jpeg' );
        $headers['Content-Length'] = (string) $preview['bytes'];
        return array(
            'handled' => true,
            'render' => 'review_file',
            'status' => 200,
            'location' => '',
            'body' => '',
            'stream' => $preview['stream'],
            'headers' => $headers,
            'result' => array( 'ok' => true ),
        );
    }

    private static function parse_request( $request ) {
        $uri = isset( $request['uri'] ) && is_string( $request['uri'] )
            ? $request['uri']
            : ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
        $path = parse_url( $uri, PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';
        $home_path = function_exists( 'home_url' ) ? parse_url( (string) home_url(), PHP_URL_PATH ) : '';
        $home_path = is_string( $home_path ) ? rtrim( $home_path, '/' ) : '';
        $route = $home_path . '/' . self::ROUTE;
        if ( $path !== $route && strpos( $path, $route . '/' ) !== 0 ) {
            return array( 'matched' => false );
        }
        $query_string = parse_url( $uri, PHP_URL_QUERY );
        $query = isset( $request['query'] ) && is_array( $request['query'] )
            ? $request['query']
            : ( isset( $_GET ) && is_array( $_GET ) ? $_GET : array() );
        if ( is_string( $query_string ) || ! empty( $query ) ) {
            return array( 'matched' => true, 'action' => 'invalid' );
        }
        $relative = substr( $path, strlen( $home_path ) );
        $prefix = preg_quote( '/' . self::ROUTE, '#' );
        if ( strlen( $relative ) > strlen( '/' . self::ROUTE . '/preview/' ) + self::maximum_token_chars() ) {
            return array( 'matched' => true, 'action' => 'invalid' );
        }
        if ( preg_match( '#^' . $prefix . '/([A-Za-z0-9_-]+)$#D', $relative, $matches ) === 1 ) {
            return array( 'matched' => true, 'action' => 'gallery', 'token' => $matches[1] );
        }
        if ( preg_match( '#^' . $prefix . '/(file|preview)/([A-Za-z0-9_-]+)$#D', $relative, $matches ) === 1 ) {
            return array( 'matched' => true, 'action' => $matches[1], 'token' => $matches[2] );
        }
        return array( 'matched' => true, 'action' => 'invalid' );
    }

    private static function private_headers( $content_type ) {
        return array(
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Content-Type' => $content_type,
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        );
    }

    private static function unavailable() {
        return array(
            'handled' => true,
            'render' => 'review_file',
            'status' => 404,
            'location' => '',
            'body' => '<div class="eforms-error">Review unavailable.</div>',
            'headers' => self::private_headers( 'text/html; charset=UTF-8' ),
            'result' => array( 'ok' => false ),
        );
    }

    private static function not_handled() {
        return array( 'handled' => false, 'status' => 0, 'location' => '', 'body' => '', 'result' => null );
    }

    private static function uploads_dir( $overrides ) {
        if ( isset( $overrides['uploads_dir'] ) && is_string( $overrides['uploads_dir'] ) && $overrides['uploads_dir'] !== '' ) {
            return rtrim( $overrides['uploads_dir'], '/\\' );
        }
        $value = Config::value( Config::get(), array( 'uploads', 'dir' ), '' );
        return is_string( $value ) && $value !== '' ? rtrim( $value, '/\\' ) : '';
    }

    private static function salt( $overrides ) {
        if ( isset( $overrides['salt'] ) && is_string( $overrides['salt'] ) ) {
            return $overrides['salt'];
        }
        return self::wordpress_salt();
    }

    private static function wordpress_salt() {
        return function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
    }

    private static function base_url( $base_url ) {
        if ( is_string( $base_url ) && $base_url !== '' ) {
            return rtrim( $base_url, '/' );
        }
        return function_exists( 'home_url' ) ? rtrim( (string) home_url(), '/' ) : '';
    }

    private static function review_url( $action, $token, $base_url ) {
        $base = self::base_url( $base_url );
        if ( $base === ''
            || ! self::clean_routes_available()
            || ! in_array( $action, array( 'gallery', 'file', 'preview' ), true )
            || ! is_string( $token )
            || $token === ''
        ) {
            return '';
        }
        $member = $action === 'gallery' ? '' : '/' . $action;
        return $base . '/' . self::ROUTE . $member . '/' . $token;
    }

    public static function clean_routes_available() {
        if ( ! function_exists( 'get_option' ) ) {
            return false;
        }
        $structure = get_option( 'permalink_structure', '' );
        $structure = is_string( $structure ) ? ltrim( $structure, '/' ) : '';
        return $structure !== '' && $structure !== 'index.php' && strpos( $structure, 'index.php/' ) !== 0;
    }

    private static function server_method() {
        return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( $_SERVER['REQUEST_METHOD'] )
            : 'GET';
    }

    private static function post_payload( $overrides ) {
        if ( isset( $overrides['post'] ) && is_array( $overrides['post'] ) ) {
            return $overrides['post'];
        }
        return isset( $_POST ) && is_array( $_POST ) ? $_POST : array();
    }

    private static function can_delete_review() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }

    private static function delete_nonce_action( $submission_id ) {
        return 'eforms_review_delete_' . $submission_id;
    }

    private static function availability_nonce_action( $submission_id ) {
        return 'eforms_review_availability_' . $submission_id;
    }

    private static function availability_options( $delete_after = 0, $selected_choice = '' ) {
        $choices = self::availability_choices();
        $selected_choice = is_string( $selected_choice ) && isset( $choices[ $selected_choice ] ) ? $selected_choice : '';
        $options = array();
        foreach ( $choices as $key => $choice ) {
            $options[] = array(
                'key' => $key,
                'label' => $choice['label'],
                'checked' => $selected_choice !== ''
                    ? $key === $selected_choice
                    : ( $delete_after === null && $choice['duration_anchor'] === null ),
            );
        }
        return $options;
    }

    private static function availability_delete_after( $choice, $now ) {
        $choices = self::availability_choices();
        if ( ! isset( $choices[ $choice ] ) ) {
            return false;
        }
        $anchor = $choices[ $choice ]['duration_anchor'];
        if ( $anchor === null ) {
            return null;
        }
        $duration = Anchors::get( $anchor );
        if ( ! is_int( $duration ) || $duration < 1 ) {
            return false;
        }
        return (int) $now + $duration;
    }

    private static function availability_choices() {
        return array(
            '30_days' => array(
                'label' => '30 days',
                'duration_anchor' => 'MANAGED_AVAILABILITY_30_DAYS_SECONDS',
            ),
            '90_days' => array(
                'label' => '90 days',
                'duration_anchor' => 'MANAGED_AVAILABILITY_90_DAYS_SECONDS',
            ),
            '1_year' => array(
                'label' => '1 year',
                'duration_anchor' => 'MANAGED_AVAILABILITY_1_YEAR_SECONDS',
            ),
            'manual' => array(
                'label' => 'Until manually deleted',
                'duration_anchor' => null,
            ),
        );
    }

    private static function availability_label( $delete_after ) {
        return $delete_after === null ? 'manually deleted' : self::display_timestamp_label( $delete_after );
    }

    private static function submitted_label( $finalized_at ) {
        return self::display_timestamp_label( $finalized_at );
    }

    private static function display_timestamp_label( $timestamp ) {
        if ( ! is_numeric( $timestamp ) ) {
            return '';
        }
        $format = 'F j, Y \a\t g:i a';
        if ( function_exists( 'wp_date' ) ) {
            return wp_date( $format, (int) $timestamp );
        }
        return gmdate( $format, (int) $timestamp );
    }

    private static function verify_nonce( $nonce, $action ) {
        return is_string( $nonce ) && $nonce !== '' && function_exists( 'wp_verify_nonce' ) && wp_verify_nonce( $nonce, $action );
    }

    private static function valid_id( $value, $pattern ) {
        return is_string( $value ) && preg_match( $pattern, $value ) === 1;
    }

    private static function upload_ids( $values ) {
        $ids = array();
        foreach ( $values as $value ) {
            if ( ! self::valid_id( $value, FormProtocol::managed_id_pattern() ) ) {
                return array();
            }
            $ids[] = $value;
        }
        sort( $ids, SORT_STRING );
        return $ids;
    }

    private static function content_disposition( $display_name, $mime ) {
        $extension = UploadPolicy::staged_extension_for_mime( $mime );
        $extension = $extension !== '' ? $extension : 'image';
        $name = is_string( $display_name ) ? trim( $display_name ) : '';
        $stem = $name !== '' ? pathinfo( $name, PATHINFO_FILENAME ) : '';
        $stem = is_string( $stem ) && $stem !== '' ? $stem : 'submitted-image';
        $filename = $stem . '.' . $extension;
        return 'attachment; filename="submitted-image.' . $extension . '"; filename*=UTF-8\'\'' . rawurlencode( $filename );
    }

    private static function enqueue_assets() {
        EformsAssets::enqueue_review( Config::get() );
    }

    private static function review_provider( $overrides ) {
        if ( isset( $overrides['review_provider'] ) && in_array( $overrides['review_provider'], array( 'none', 'local', 'worker', 'unavailable' ), true ) ) {
            return $overrides['review_provider'];
        }
        return WorkerClient::review_provider();
    }

    private static function token( $action, $submission_id, $upload_id, $salt ) {
        if ( ! in_array( $action, array( 'gallery', 'file', 'preview' ), true )
            || ! is_string( $salt )
            || $salt === ''
        ) {
            return '';
        }
        $uuid = self::uuid_bytes( $submission_id );
        if ( $uuid === '' ) {
            return '';
        }
        $body = chr( (int) self::VERSION ) . $uuid;
        if ( $action === 'gallery' ) {
            if ( $upload_id !== '' ) {
                return '';
            }
        } elseif ( ! self::valid_id( $upload_id, FormProtocol::managed_id_pattern() ) ) {
            return '';
        } else {
            $body .= chr( strlen( $upload_id ) ) . $upload_id;
        }
        $tag = self::token_tag( $action, $body, $salt );
        return $tag === '' ? '' : self::base64url( $body . $tag );
    }

    private static function verify_token( $action, $token, $salt ) {
        if ( ! in_array( $action, array( 'gallery', 'file', 'preview' ), true )
            || ! is_string( $salt )
            || $salt === ''
            || ! self::token_length_allowed( $action, $token )
        ) {
            return null;
        }
        $decoded = self::base64url_decode( $token );
        $tag_bytes = Anchors::get( 'MANAGED_REVIEW_TAG_BYTES' );
        $uuid_bytes = Anchors::get( 'MANAGED_SUBMISSION_UUID_BYTES' );
        $minimum = 1 + $uuid_bytes + $tag_bytes;
        if ( $decoded === '' || strlen( $decoded ) < $minimum ) {
            return null;
        }
        $body = substr( $decoded, 0, -$tag_bytes );
        $provided = substr( $decoded, -$tag_bytes );
        $expected = self::token_tag( $action, $body, $salt );
        if ( $expected === '' || ! hash_equals( $expected, $provided ) || ord( $body[0] ) !== (int) self::VERSION ) {
            return null;
        }
        $submission_id = self::uuid_from_bytes( substr( $body, 1, $uuid_bytes ) );
        if ( $submission_id === '' ) {
            return null;
        }
        $offset = 1 + $uuid_bytes;
        if ( $action === 'gallery' ) {
            return strlen( $body ) === $offset
                ? array( 'submission_id' => $submission_id, 'upload_id' => '' )
                : null;
        }
        if ( strlen( $body ) <= $offset ) {
            return null;
        }
        $length = ord( $body[ $offset ] );
        $upload_id = substr( $body, $offset + 1 );
        if ( $length < 1
            || strlen( $upload_id ) !== $length
            || ! self::valid_id( $upload_id, FormProtocol::managed_id_pattern() )
        ) {
            return null;
        }
        return array( 'submission_id' => $submission_id, 'upload_id' => $upload_id );
    }

    private static function token_length_allowed( $action, $token ) {
        if ( ! is_string( $token ) ) {
            return false;
        }
        $tag_bytes = Anchors::get( 'MANAGED_REVIEW_TAG_BYTES' );
        $uuid_bytes = Anchors::get( 'MANAGED_SUBMISSION_UUID_BYTES' );
        $gallery_chars = self::base64url_length( 1 + $uuid_bytes + $tag_bytes );
        if ( $action === 'gallery' ) {
            return strlen( $token ) === $gallery_chars;
        }
        $minimum_chars = self::base64url_length( 1 + $uuid_bytes + 1 + 1 + $tag_bytes );
        return strlen( $token ) >= $minimum_chars && strlen( $token ) <= self::maximum_token_chars();
    }

    private static function maximum_token_chars() {
        return self::base64url_length(
            1
            + Anchors::get( 'MANAGED_SUBMISSION_UUID_BYTES' )
            + 1
            + Anchors::get( 'MANAGED_ID_MAX_CHARS' )
            + Anchors::get( 'MANAGED_REVIEW_TAG_BYTES' )
        );
    }

    private static function base64url_length( $bytes ) {
        return is_int( $bytes ) && $bytes > 0 ? intdiv( $bytes * 8 + 5, 6 ) : 0;
    }

    private static function token_tag( $action, $body, $salt ) {
        $tag_bytes = Anchors::get( 'MANAGED_REVIEW_TAG_BYTES' );
        if ( ! is_int( $tag_bytes ) || $tag_bytes < 1 || $tag_bytes > 32 ) {
            return '';
        }
        $message = UploadBatchStore::encode_parts( array( self::DOMAIN, self::VERSION, $action, $body ) );
        return $message === '' ? '' : substr( hash_hmac( 'sha256', $message, $salt, true ), 0, $tag_bytes );
    }

    private static function uuid_bytes( $submission_id ) {
        if ( ! is_string( $submission_id )
            || preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $submission_id ) !== 1
        ) {
            return '';
        }
        $bytes = hex2bin( str_replace( '-', '', $submission_id ) );
        return is_string( $bytes ) && strlen( $bytes ) === Anchors::get( 'MANAGED_SUBMISSION_UUID_BYTES' ) ? $bytes : '';
    }

    private static function uuid_from_bytes( $bytes ) {
        if ( ! is_string( $bytes ) || strlen( $bytes ) !== Anchors::get( 'MANAGED_SUBMISSION_UUID_BYTES' ) ) {
            return '';
        }
        $hex = bin2hex( $bytes );
        return substr( $hex, 0, 8 )
            . '-' . substr( $hex, 8, 4 )
            . '-' . substr( $hex, 12, 4 )
            . '-' . substr( $hex, 16, 4 )
            . '-' . substr( $hex, 20, 12 );
    }

    private static function base64url( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    private static function base64url_decode( $encoded ) {
        if ( ! is_string( $encoded ) || preg_match( '/^[A-Za-z0-9_-]+$/D', $encoded ) !== 1 ) {
            return '';
        }
        $padding = ( 4 - strlen( $encoded ) % 4 ) % 4;
        $decoded = base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', $padding ), true );
        return is_string( $decoded ) && self::base64url( $decoded ) === $encoded ? $decoded : '';
    }
}
