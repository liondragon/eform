<?php
use PHPUnit\Framework\TestCase;

class FieldRegistryTest extends TestCase {
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
}
