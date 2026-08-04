<?php
/**
 * Unit tests for cross-field rules (bounded set).
 *
 * Contract: Cross-field rules
 * Contract: Validation pipeline
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Validation/Validator.php';

Config::reset_for_tests();

$context = array(
    'fields' => array(
        array( 'key' => 'country', 'type' => 'text' ),
        array( 'key' => 'region', 'type' => 'text' ),
        array( 'key' => 'state', 'type' => 'text' ),
        array( 'key' => 'phone', 'type' => 'text' ),
        array( 'key' => 'email', 'type' => 'email' ),
        array( 'key' => 'password', 'type' => 'text' ),
        array( 'key' => 'confirm_password', 'type' => 'text' ),
        array( 'key' => 'customer_type', 'type' => 'text' ),
        array( 'key' => 'membership', 'type' => 'text' ),
        array( 'key' => 'discount_code', 'type' => 'text' ),
        array( 'key' => 'credit_card', 'type' => 'text' ),
        array( 'key' => 'paypal', 'type' => 'text' ),
    ),
    'descriptors' => array(
        array( 'key' => 'country', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'region', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'state', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'phone', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'email', 'type' => 'email', 'is_multivalue' => false ),
        array( 'key' => 'password', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'confirm_password', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'customer_type', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'membership', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'discount_code', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'credit_card', 'type' => 'text', 'is_multivalue' => false ),
        array( 'key' => 'paypal', 'type' => 'text', 'is_multivalue' => false ),
    ),
    'rules' => array(
        array( 'rule' => 'required_if', 'target' => 'state', 'field' => 'country', 'equals' => 'US' ),
        array( 'rule' => 'required_if', 'target' => 'state', 'field' => 'region', 'equals' => 'NA' ),
        array( 'rule' => 'required_unless', 'target' => 'email', 'field' => 'phone', 'equals' => 'provided' ),
        array( 'rule' => 'required_if_any', 'target' => 'discount_code', 'fields' => array( 'customer_type', 'membership' ), 'equals_any' => array( 'partner', 'gold' ) ),
        array( 'rule' => 'matches', 'target' => 'confirm_password', 'field' => 'password' ),
        array( 'rule' => 'one_of', 'fields' => array( 'email', 'phone' ) ),
        array( 'rule' => 'mutually_exclusive', 'fields' => array( 'credit_card', 'paypal' ) ),
    ),
);

$values = array(
    'country' => 'US',
    'region' => 'NA',
    'state' => '',
    'phone' => '',
    'email' => '',
    'customer_type' => 'partner',
    'membership' => '',
    'discount_code' => '',
    'password' => 'secret',
    'confirm_password' => 'not-secret',
    'credit_card' => '4111111111111111',
    'paypal' => 'user@example.com',
);

$result = Validator::validate( $context, array( 'values' => $values ) );
$errors = $result['errors']->to_array();

// Given required_if rules with the same target...
// When both triggers are true...
// Then errors are emitted in rules[] order (deterministic).
eforms_test_assert( isset( $errors['state'] ) && is_array( $errors['state'] ), 'State should have errors.' );
eforms_test_assert( count( $errors['state'] ) === 2, 'State should have two required_if errors.' );
eforms_test_assert( $errors['state'][0]['code'] === 'EFORMS_ERR_FIELD_REQUIRED', 'First required_if should emit required.' );
eforms_test_assert( $errors['state'][1]['code'] === 'EFORMS_ERR_FIELD_REQUIRED', 'Second required_if should emit required.' );

// Given required_unless and phone not equal to sentinel value...
// When email is missing...
// Then the target field is required.
eforms_test_assert( isset( $errors['email'] ) && $errors['email'][0]['code'] === 'EFORMS_ERR_FIELD_REQUIRED', 'Email should be required unless phone equals sentinel.' );

// Given required_if_any...
// When one of the inspected fields matches equals_any...
// Then the target becomes required.
eforms_test_assert( isset( $errors['discount_code'] ) && $errors['discount_code'][0]['code'] === 'EFORMS_ERR_FIELD_REQUIRED', 'Discount code should be required when a trigger field matches.' );

// Given matches...
// When the values differ...
// Then the target receives a deterministic type error.
eforms_test_assert( isset( $errors['confirm_password'] ) && $errors['confirm_password'][0]['code'] === 'EFORMS_ERR_FIELD_INVALID', 'Matches rule should emit an invalid-field error on mismatch.' );

// Given one_of...
// When all listed fields are empty...
// Then a global required error is emitted.
eforms_test_assert( isset( $errors['_global'] ) && $errors['_global'][0]['code'] === 'EFORMS_ERR_ONE_OF_REQUIRED', 'one_of should emit a global required error.' );

// Given mutually_exclusive...
// When more than one listed field is present...
// Then a global mutual-exclusion error is emitted (after the one_of error).
eforms_test_assert( isset( $errors['_global'] ) && count( $errors['_global'] ) === 2, 'Should have two global errors.' );
eforms_test_assert( $errors['_global'][1]['code'] === 'EFORMS_ERR_MUTUALLY_EXCLUSIVE', 'mutually_exclusive should emit a global mutual-exclusion error.' );

$alternative_message = 'Please provide a listing URL or upload at least one photo.';
$alternative_context = array(
    'fields' => array(
        array( 'key' => 'listing_url', 'type' => 'url' ),
        array( 'key' => 'project_photos', 'type' => 'files', 'upload_mode' => 'staged', 'max_files' => Anchors::get( 'MANAGED_STAGED_MAX_FILES' ), 'max_total_bytes' => 314572800 ),
    ),
    'descriptors' => array(
        array( 'key' => 'listing_url', 'type' => 'url', 'is_multivalue' => false ),
        array( 'key' => 'project_photos', 'type' => 'files', 'is_multivalue' => true ),
    ),
    'rules' => array(
        array( 'rule' => 'one_of', 'fields' => array( 'listing_url', 'project_photos' ), 'message' => $alternative_message ),
    ),
);

$missing_alternatives = Validator::validate(
    $alternative_context,
    array( 'values' => array( 'listing_url' => '', 'project_photos' => null ) )
);
$missing_errors = $missing_alternatives['errors']->to_array();
eforms_test_assert(
    isset( $missing_errors['_global'][0] )
        && $missing_errors['_global'][0]['code'] === 'EFORMS_ERR_ONE_OF_REQUIRED'
        && $missing_errors['_global'][0]['message'] === $alternative_message,
    'one_of should emit its configured global message when every alternative is missing.'
);

$listing_only = Validator::validate(
    $alternative_context,
    array( 'values' => array( 'listing_url' => 'https://example.com/listing', 'project_photos' => null ) )
);
eforms_test_assert( $listing_only['ok'] === true, 'A listing URL should satisfy the alternative evidence rule without photos.' );

$photo = UploadValue::staged_item( array(
    'upload_id' => 'photo_one',
    'ordinal' => 0,
    'display_name' => 'room.png',
    'bytes' => 1024,
    'mime' => 'image/png',
    'width' => 800,
    'height' => 600,
) );
$photo_only = Validator::validate(
    $alternative_context,
    array( 'values' => array( 'listing_url' => '', 'project_photos' => array( $photo ) ) )
);
eforms_test_assert( $photo_only['ok'] === true, 'A staged photo should satisfy the alternative evidence rule without a listing URL.' );
