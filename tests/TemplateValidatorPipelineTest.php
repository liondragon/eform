<?php
use PHPUnit\Framework\TestCase;

class TemplateValidatorPipelineTest extends TestCase {
    private string $templatesDir;

    protected function setUp(): void {
        $this->templatesDir = dirname( __DIR__ ) . '/templates';
    }

    public function test_invalid_template_triggers_error_code() {
        $template = 'invalid_schema';
        $path     = $this->templatesDir . '/' . $template . '.json';
        $config   = [
            'id'      => $template,
            'version' => 1,
            'title'   => 'Invalid',
            'email'   => [],
            'success' => [],
            'fields'  => [ [ 'key' => 'name', 'type' => 'bogus' ] ],
        ];
        file_put_contents( $path, json_encode( $config ) );

        $processor = new Enhanced_ICF_Form_Processor( new Logging() );
        $data = [
            '_wpnonce'   => 'valid',
            'eforms_hp'  => '',
            'timestamp'  => time() - 10,
            'js_ok'      => '1',
            'form_id'    => $template,
            'instance_id'=> 'i_test',
            $template    => [ 'name' => 'John' ],
        ];

        $result = $processor->process_form_submission( $template, $data );

        $this->assertFalse( $result['success'] );
        $this->assertSame( TemplateValidator::ERR_ENUM, $result['message'] );

        unlink( $path );
    }
}
