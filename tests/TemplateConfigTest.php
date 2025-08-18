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
        $this->assertSame( 'default', $result['id'] );
        $this->assertSame( 'inline', $result['success']['mode'] );
        $this->assertSame( 'Your Name', $result['fields'][0]['placeholder'] );

        $fields = eform_get_template_fields( 'default' );
        $this->assertArrayHasKey( 'name', $fields );
        $this->assertSame( 'Your Name', $fields['name']['placeholder'] );
    }

    public function test_missing_template_returns_empty(): void {
        $this->assertSame( [], eform_get_template_config( 'missing' ) );
    }

    public function test_invalid_template_slug_rejected(): void {
        $this->assertSame( [], eform_get_template_config( '../secret' ) );
        $this->assertSame( [], eform_get_template_config( 'Default' ) );
    }

    public function test_cache_can_be_purged(): void {
        $template = 'temp';
        $path     = $this->pluginTemplatesDir . "/$template.json";
        $config   = [
            'id'      => $template,
            'version' => 1,
            'title'   => 'Temp',
            'email'   => [],
            'success' => [],
            'fields'  => [],
        ];
        file_put_contents( $path, json_encode( $config ) );

        eform_get_template_config( $template );

        $this->assertArrayHasKey( $template, $GLOBALS['wp_cache']['eform_template_config'] );

        eform_purge_template_config_cache();

        $this->assertArrayNotHasKey( $template, $GLOBALS['wp_cache']['eform_template_config'] ?? [] );

        unlink( $path );
    }
}

