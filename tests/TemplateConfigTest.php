<?php

use PHPUnit\Framework\TestCase;

class TemplateConfigTest extends TestCase {

    private string $themeDir;
    private string $pluginTemplatesDir;

    protected function setUp(): void {
        $this->themeDir             = sys_get_temp_dir() . '/theme_' . uniqid();
        $GLOBALS['_eform_theme_dir'] = $this->themeDir;
        mkdir( $this->themeDir . '/eform', 0777, true );

        $this->pluginTemplatesDir = dirname( __DIR__ ) . '/templates';

        $GLOBALS['wp_cache'] = [];
    }

    protected function tearDown(): void {
        if ( is_dir( $this->themeDir ) ) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $this->themeDir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $files as $file ) {
                $file->isDir() ? rmdir( $file->getRealPath() ) : unlink( $file->getRealPath() );
            }
            rmdir( $this->themeDir );
        }
    }

    public function test_json_config_merges_with_defaults(): void {
        $config = [
            'fields' => [
                'name_input' => [
                    'type' => 'text',
                    'placeholder' => 'Alt Name'
                ],
            ],
        ];
        file_put_contents( $this->themeDir . '/eform/default.json', json_encode( $config ) );

        $result = eform_get_template_config( 'default' );

        $this->assertSame( 'Alt Name', $result['fields']['name_input']['placeholder'] );
        $this->assertArrayHasKey( 'email_input', $result['fields'] );
    }

    public function test_php_config_merges_with_defaults(): void {
        $php = "<?php\nreturn [\n    'fields' => [\n        'email_input' => [\n            'type' => 'email',\n            'placeholder' => 'Alt Email'\n        ],\n    ],\n];\n";
        file_put_contents( $this->themeDir . '/eform/default.php', $php );

        $result = eform_get_template_config( 'default' );

        $this->assertSame( 'Alt Email', $result['fields']['email_input']['placeholder'] );
        $this->assertArrayHasKey( 'name_input', $result['fields'] );
    }

    public function test_invalid_json_returns_defaults(): void {
        file_put_contents( $this->themeDir . '/eform/default.json', '{invalid json' );

        $result = eform_get_template_config( 'default' );

        $this->assertSame( 'Your Name', $result['fields']['name_input']['placeholder'] );
    }

    public function test_plugin_config_used_when_theme_missing(): void {
        $result = eform_get_template_config( 'default' );
        $this->assertSame( 'Your Name', $result['fields']['name_input']['placeholder'] );
        $this->assertArrayHasKey( 'email_input', $result['fields'] );
    }

    public function test_cache_invalidates_when_file_changes(): void {
        $path = $this->themeDir . '/eform/default.json';
        file_put_contents( $path, json_encode( [
            'fields' => [
                'name_input' => [ 'placeholder' => 'First' ],
            ],
        ] ) );

        $result = eform_get_template_config( 'default' );
        $this->assertSame( 'First', $result['fields']['name_input']['placeholder'] );

        // Ensure file modification time changes.
        sleep( 1 );
        file_put_contents( $path, json_encode( [
            'fields' => [
                'name_input' => [ 'placeholder' => 'Second' ],
            ],
        ] ) );

        $result = eform_get_template_config( 'default' );
        $this->assertSame( 'Second', $result['fields']['name_input']['placeholder'] );
    }

    public function test_cache_can_be_purged(): void {
        file_put_contents( $this->themeDir . '/eform/default.json', json_encode( [ 'fields' => [] ] ) );
        eform_get_template_config( 'default' );

        $cache_key = 'default|' . rtrim( $this->themeDir, '/\\' );
        $this->assertArrayHasKey( $cache_key, $GLOBALS['wp_cache']['eform_template_config'] );

        eform_purge_template_config_cache();

        $this->assertArrayNotHasKey( $cache_key, $GLOBALS['wp_cache']['eform_template_config'] ?? [] );
    }
}
