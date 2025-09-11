<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Email\Emailer;
use EForms\Config;

final class EmailAttachmentOverflowTest extends TestCase
{
    protected function setUp(): void
    {
        global $TEST_ARTIFACTS;
        @file_put_contents($TEST_ARTIFACTS['mail_file'], '[]');
        $ref = new \ReflectionClass(Config::class);
        $boot = $ref->getProperty('bootstrapped');
        $boot->setAccessible(true);
        $boot->setValue(false);
        $data = $ref->getProperty('data');
        $data->setAccessible(true);
        $data->setValue([]);
        add_filter('eforms_config', function ($defaults) {
            $defaults['uploads']['max_email_bytes'] = 100;
            return $defaults;
        });
        Config::bootstrap();
    }

    public function testOverflowAttachmentsSummarized(): void
    {
        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => ['to' => 'a@example.com', 'subject' => 's', 'email_template' => 'default', 'include_fields' => []],
            'fields' => [
                ['type' => 'file', 'key' => 'doc1', 'accept' => ['pdf'], 'email_attach' => true],
                ['type' => 'file', 'key' => 'doc2', 'accept' => ['pdf'], 'email_attach' => true],
            ],
            'submit_button_text' => 'Send',
            'rules' => [],
        ];
        $canonical = [
            '_uploads' => [
                'doc1' => [
                    ['path' => 'a/a.pdf', 'size' => 60, 'mime' => 'application/pdf', 'original_name' => 'a.pdf', 'original_name_safe' => 'a.pdf'],
                ],
                'doc2' => [
                    ['path' => 'a/b.pdf', 'size' => 60, 'mime' => 'application/pdf', 'original_name' => 'b.pdf', 'original_name_safe' => 'b.pdf'],
                ],
            ],
        ];
        $meta = ['form_id' => 't1', 'instance_id' => 'i1'];
        Emailer::send($tpl, $canonical, $meta);
        global $TEST_ARTIFACTS;
        $mail = json_decode((string) file_get_contents($TEST_ARTIFACTS['mail_file']), true);
        $this->assertCount(1, $mail[0]['attachments']);
        $this->assertSame('a.pdf', $mail[0]['attachments'][0]['name']);
        $this->assertStringContainsString('Omitted attachments: b.pdf', $mail[0]['message']);
    }
}

