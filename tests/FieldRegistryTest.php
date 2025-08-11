<?php
use PHPUnit\Framework\TestCase;

class FieldRegistryTest extends TestCase {
    public function testRegisteredCallbacksAreCallable() {
        $registry = new FieldRegistry();
        register_template_fields_from_config( $registry, 'default' );
        $fields = $registry->get_fields( 'default' );
        foreach ( $fields as $details ) {
            $this->assertIsCallable( $details['sanitize_cb'] );
            $this->assertIsCallable( $details['validate_cb'] );
        }
    }

    public function testRegisterFieldFromConfigAppliesTypeCallbacks() {
        $registry = new FieldRegistry();
        $registry->register_field_from_config('tmpl', 'email', [
            'post_key' => 'email_input',
            'type'     => 'email',
            'required' => true,
        ]);
        $fields = $registry->get_fields('tmpl');
        $this->assertSame('sanitize_email', $fields['email']['sanitize_cb']);
        $this->assertSame([FieldRegistry::class, 'validate_email'], $fields['email']['validate_cb']);
    }

    public function testValidatePatternHonorsRegex() {
        $registry = new FieldRegistry();
        $registry->register_field_from_config('tmpl', 'code', [
            'post_key' => 'code_input',
            'type'     => 'text',
            'pattern'  => '\\d+',
            'required' => true,
        ]);
        $field = $registry->get_fields('tmpl')['code'];
        $this->assertSame('Invalid format.', FieldRegistry::validate_pattern('abc', $field));
        $this->assertSame('', FieldRegistry::validate_pattern('123', $field));
    }

    public function testValidateRangeRespectsBounds() {
        $registry = new FieldRegistry();
        $registry->register_field_from_config('tmpl', 'age', [
            'post_key' => 'age_input',
            'type'     => 'number',
            'min'      => 10,
            'max'      => 20,
        ]);
        $field = $registry->get_fields('tmpl')['age'];
        $this->assertSame('Value must be at least 10.', FieldRegistry::validate_range('5', $field));
        $this->assertSame('Value must be at most 20.', FieldRegistry::validate_range('25', $field));
        $this->assertSame('', FieldRegistry::validate_range('15', $field));
    }

    public function testValidateChoiceAllowsOnlyListedValues() {
        $registry = new FieldRegistry();
        $registry->register_field_from_config('tmpl', 'color', [
            'post_key' => 'color_input',
            'type'     => 'radio',
            'choices'  => ['red', 'blue'],
        ]);
        $field = $registry->get_fields('tmpl')['color'];
        $this->assertSame('Invalid selection.', FieldRegistry::validate_choice('green', $field));
        $this->assertSame('', FieldRegistry::validate_choice('red', $field));
    }
}
