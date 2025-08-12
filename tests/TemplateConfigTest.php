<?php

use PHPUnit\Framework\TestCase;

class TemplateConfigTest extends TestCase {

    private string $pluginTemplatesDir;

    protected function setUp(): void {
        $this->pluginTemplatesDir = dirname( __DIR__ ) . '/templates';
        $GLOBALS['wp_cache']      = [];
    }

    public function test_plugin_config_loaded(): void {
        $result = eform_get_template_config( 'default' );

        $this->assertSame( 'Your Name', $result['fields']['name_input']['placeholder'] );
        $this->assertArrayHasKey( 'email_input', $result['fields'] );
    }

    public function test_missing_template_returns_empty(): void {
        $this->assertSame( [], eform_get_template_config( 'missing' ) );
    }

    public function test_cache_can_be_purged(): void {
        $template = 'temp';
        $path     = $this->pluginTemplatesDir . "/$template.json";
        file_put_contents( $path, json_encode( [ 'fields' => [] ] ) );

        eform_get_template_config( $template );

        $this->assertArrayHasKey( $template, $GLOBALS['wp_cache']['eform_template_config'] );

        eform_purge_template_config_cache();

        $this->assertArrayNotHasKey( $template, $GLOBALS['wp_cache']['eform_template_config'] ?? [] );

        unlink( $path );
    }
}

