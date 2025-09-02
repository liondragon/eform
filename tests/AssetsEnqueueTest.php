<?php

use PHPUnit\Framework\TestCase;

class AssetsEnqueueTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['enqueued_scripts'] = [];
        $GLOBALS['enqueued_styles']  = [];
        $_SERVER['REQUEST_METHOD']   = 'GET';

        $prop = new ReflectionProperty( FormManager::class, 'assets_enqueued' );
        $prop->setAccessible( true );
        $prop->setValue( false );
    }

    private function get_manager(): FormManager {
        $logger    = new Logging();
        $processor = new Enhanced_ICF_Form_Processor( $logger );
        $form      = new Enhanced_Internal_Contact_Form( $processor, $logger );
        $renderer  = new Renderer();

        return new FormManager( $form, $renderer );
    }

    public function test_assets_enqueued_when_form_renders() {
        $manager = $this->get_manager();
        $manager->handle_shortcode();

        $this->assertContains( 'eforms-js', $GLOBALS['enqueued_scripts'] );
        $this->assertContains( 'eforms-css', $GLOBALS['enqueued_styles'] );
    }

    public function test_assets_not_enqueued_without_render() {
        $this->get_manager();

        $this->assertEmpty( $GLOBALS['enqueued_scripts'] );
        $this->assertEmpty( $GLOBALS['enqueued_styles'] );
    }

    public function test_assets_enqueued_only_once() {
        $manager = $this->get_manager();
        $manager->handle_shortcode();
        $manager->handle_shortcode();

        $this->assertCount( 1, array_unique( $GLOBALS['enqueued_scripts'] ) );
        $this->assertCount( 1, array_unique( $GLOBALS['enqueued_styles'] ) );
    }
}

