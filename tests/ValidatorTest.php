<?php
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase {
    public function test_checkbox_valid() {
        $validator = new Validator();
        $field_map = [
            'opts' => [ 'type' => 'checkbox', 'choices' => ['a','b'] ],
        ];
        $submitted = [ 'opts' => ['a','b'] ];
        $result = $validator->process_submission( $field_map, $submitted, ['checkbox'] );
        $this->assertSame(['a','b'], $result['data']['opts']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['invalid_fields']);
    }

    public function test_checkbox_invalid_value() {
        $validator = new Validator();
        $field_map = [
            'opts' => [ 'type' => 'checkbox', 'choices' => ['a','b'] ],
        ];
        $submitted = [ 'opts' => ['a','c'] ];
        $result = $validator->process_submission( $field_map, $submitted, ['checkbox'] );
        $this->assertSame(['Invalid selection.'], array_values($result['errors']));
    }

    public function test_checkbox_required() {
        $validator = new Validator();
        $field_map = [
            'opts' => [ 'type' => 'checkbox', 'choices' => ['a','b'], 'required' => true ],
        ];
        $submitted = [ 'opts' => [] ];
        $result = $validator->process_submission( $field_map, $submitted, ['checkbox'] );
        $this->assertSame('At least one selection is required.', $result['errors']['opts']);
    }

    public function test_array_not_allowed() {
        $validator = new Validator();
        $field_map = [ 'name' => [ 'type' => 'text' ] ];
        $submitted = [ 'name' => ['foo'] ];
        $result = $validator->process_submission( $field_map, $submitted, ['checkbox'] );
        $this->assertSame(['name'], $result['invalid_fields']);
    }

    public function test_per_field_rules_override_type() {
        $validator = new Validator();
        $field_map = [
            'zip' => [
                'type' => 'text',
                'sanitize' => 'sanitize_digits',
                'validate' => 'validate_zip',
                'required' => true,
            ],
        ];
        $submitted = [ 'zip' => '12345' ];
        $result = $validator->process_submission( $field_map, $submitted, ['checkbox'] );
        $this->assertSame('12345', $result['data']['zip']);
        $this->assertSame([], $result['errors']);
    }
    public function test_normalization_trims_before_validation() {
        $validator = new Validator();
        $field_map = [ 'email' => [ 'type' => 'email' ] ];
        $submitted = [ 'email' => ' test@example.com ' ];
        $result = $validator->process_submission( $field_map, $submitted );
        $this->assertSame('test@example.com', $result['data']['email']);
    }

    public function test_number_coercion() {
        $validator = new Validator();
        $field_map = [
            'age' => [ 'type' => 'number' ],
            'pi'  => [ 'type' => 'number' ],
        ];
        $submitted = [ 'age' => '42', 'pi' => '3.14' ];
        $result = $validator->process_submission( $field_map, $submitted );
        $this->assertSame(42, $result['data']['age']);
        $this->assertSame(3.14, $result['data']['pi']);
    }
}
