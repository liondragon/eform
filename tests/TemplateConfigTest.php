<?php

use PHPUnit\Framework\TestCase;

class TemplateConfigTest extends TestCase {

    private string $themeDir;
    private string $pluginTemplatesDir;

    protected function setUp(): void {
        $this->themeDir           = sys_get_temp_dir() . '/theme_' . uniqid();
        $GLOBALS['_eform_theme_dir'] = $this->themeDir;
        mkdir( $this->themeDir . '/eform', 0777, true );

        $this->pluginTemplatesDir = dirname( __DIR__ ) . '/templates';
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
        file_put_contents($this->themeDir . '/eform/default.json', json_encode($config));

        $result = eform_get_template_config('default');

        $this->assertSame('Alt Name', $result['fields']['name_input']['placeholder']);
        $this->assertArrayHasKey('email_input', $result['fields']);
    }

    public function test_yaml_config_merges_with_defaults(): void {
        $yaml = "fields:\n  email_input:\n    type: email\n    placeholder: Alt Email";
        file_put_contents($this->themeDir . '/eform/default.yaml', $yaml);

        $result = eform_get_template_config('default');

        $this->assertSame('Alt Email', $result['fields']['email_input']['placeholder']);
        $this->assertArrayHasKey('name_input', $result['fields']);
    }

    public function test_invalid_config_returns_defaults(): void {
        $config = [
            'fields' => [
                'name_input' => [
                    // missing required "type" key
                    'placeholder' => 'Broken'
                ],
            ],
        ];
        file_put_contents($this->themeDir . '/eform/default.json', json_encode($config));

        $result = eform_get_template_config('default');

        $this->assertSame('Your Name', $result['fields']['name_input']['placeholder']);
    }

    public function test_plugin_config_used_when_theme_missing(): void {
        $result = eform_get_template_config( 'default' );
        $this->assertSame( 'Your Name', $result['fields']['name_input']['placeholder'] );
        $this->assertArrayHasKey( 'email_input', $result['fields'] );
    }
}

