<?php
use PHPUnit\Framework\TestCase;

class TemplateCacheFieldHandlingTest extends TestCase {
    private string $templatesDir;

    protected function setUp(): void {
        $this->templatesDir = dirname( __DIR__ ) . '/templates';
        $GLOBALS['wp_cache'] = [];
    }

    public function test_multivalue_fields_and_reserved_keys(): void {
        $template = 'field-handling';
        $path     = $this->templatesDir . '/' . $template . '.json';
        $config   = [
            'id'      => $template,
            'version' => 1,
            'title'   => 'Field Handling',
            'email'   => [],
            'success' => [],
            'fields'  => [
                [ 'key' => 'options', 'type' => 'checkbox' ],
                [ 'key' => 'form_id', 'type' => 'text' ],
            ],
        ];
        file_put_contents( $path, json_encode( $config ) );

        $fields = eform_get_template_fields( $template );
        $this->assertArrayHasKey( 'options', $fields );
        $this->assertSame( 'options[]', $fields['options']['post_key'] );
        $this->assertArrayNotHasKey( 'form_id', $fields );

        unlink( $path );
    }
}
