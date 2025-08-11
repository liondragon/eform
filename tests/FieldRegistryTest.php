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

    public function testFieldMapUsesTypeMap() {
        $registry = new FieldRegistry();

        // Override the email sanitize callback via the internal type map to
        // ensure get_field_map() derives callbacks from the map rather than
        // hard-coded values.
        $ref = new \ReflectionProperty( FieldRegistry::class, 'type_map' );
        $ref->setAccessible( true );
        $map = $ref->getValue( $registry );
        $map['email']['sanitize_cb'] = 'strrev';
        $ref->setValue( $registry, $map );

        $fields = $registry->get_field_map();
        $this->assertSame( 'strrev', $fields['email']['sanitize_cb'] );
    }
    public function testInvalidSanitizeCallbackTriggersWarningAndIsNotRegistered() {
        $registry = new FieldRegistry();
        $error = null;
        set_error_handler(function($errno, $errstr) use (&$error) {
            $error = $errstr;
            return true; // suppress default error handling
        });

        // Attempt to register field with invalid sanitize callback.
        $registry->register_field('template', 'name', ['sanitize_cb' => 'nonexistent_function']);

        restore_error_handler();

        // Register another valid field to inspect registered set for template.
        $registry->register_field('template', 'email');

        $fields = $registry->get_fields('template');
        $this->assertArrayHasKey('email', $fields);
        $this->assertArrayNotHasKey('name', $fields);
        $this->assertNotNull($error);
        $this->assertStringContainsString('sanitize_cb', $error);
    }

    public function testInvalidValidateCallbackTriggersWarningAndIsNotRegistered() {
        $registry = new FieldRegistry();
        $error = null;
        set_error_handler(function($errno, $errstr) use (&$error) {
            $error = $errstr;
            return true; // suppress default error handling
        });

        // Attempt to register field with invalid validate callback.
        $registry->register_field('template', 'email', ['validate_cb' => 'nonexistent_function']);

        restore_error_handler();

        // Register another valid field to ensure template exists.
        $registry->register_field('template', 'name');

        $fields = $registry->get_fields('template');
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayNotHasKey('email', $fields);
        $this->assertNotNull($error);
        $this->assertStringContainsString('validate_cb', $error);
    }

    public function testMissingRequiredParamTriggersWarning() {
        $registry = new FieldRegistry();
        $error = null;
        set_error_handler(function($errno, $errstr) use (&$error) {
            $error = $errstr;
            return true;
        });

        // text_generic requires post_key.
        $registry->register_field('template', 'text_generic', [], true);
        // Register another field so the template's registered set exists.
        $registry->register_field('template', 'email');

        restore_error_handler();

        $fields = $registry->get_fields('template');
        $this->assertArrayHasKey('email', $fields);
        $this->assertArrayNotHasKey('text_generic', $fields);
        $this->assertNotNull($error);
        $this->assertStringContainsString('post_key', $error);
    }

    public function testValidatePatternHonorsRegex() {
        $registry = new FieldRegistry();
        $registry->register_field('template', 'text_generic', [
            'post_key' => 'code',
            'pattern'  => '\\d+',
            'required' => true,
        ], true);

        $field = $registry->get_fields('template')['text_generic'];

        $this->assertSame('Invalid format.', FieldRegistry::validate_pattern('abc', $field));
        $this->assertSame('', FieldRegistry::validate_pattern('123', $field));
    }

    public function testValidateRangeRespectsBounds() {
        $registry = new FieldRegistry();
        $registry->register_field('template', 'number_generic', [
            'post_key' => 'age',
            'min'      => 10,
            'max'      => 20,
        ], true);

        $field = $registry->get_fields('template')['number_generic'];

        $this->assertSame('Value must be at least 10.', FieldRegistry::validate_range('5', $field));
        $this->assertSame('Value must be at most 20.', FieldRegistry::validate_range('25', $field));
        $this->assertSame('', FieldRegistry::validate_range('15', $field));
    }

    public function testValidateChoiceAllowsOnlyListedValues() {
        $registry = new FieldRegistry();
        $registry->register_field('template', 'radio_generic', [
            'post_key' => 'color',
            'choices'  => ['red', 'blue'],
        ], true);

        $field = $registry->get_fields('template')['radio_generic'];

        $this->assertSame('Invalid selection.', FieldRegistry::validate_choice('green', $field));
        $this->assertSame('', FieldRegistry::validate_choice('red', $field));
    }
}
