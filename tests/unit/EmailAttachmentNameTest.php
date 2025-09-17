<?php
declare(strict_types=1);

use EForms\Email\Emailer;
use EForms\Config;

final class EmailAttachmentNameTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // reset mail file
        global $TEST_ARTIFACTS;
        @file_put_contents($TEST_ARTIFACTS['mail_file'], '[]');
    }

    public function testAttachmentUsesRfc5987WhenNotTransliterated(): void
    {
        set_config(['uploads' => ['transliterate' => false]]);

        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => ['to' => 'a@example.com', 'subject' => 's', 'email_template' => 'default', 'include_fields' => []],
            'fields' => [
                ['type' => 'file', 'key' => 'doc', 'accept' => ['pdf'], 'email_attach' => true],
            ],
            'submit_button_text' => 'Send',
            'rules' => [],
        ];
        $canonical = [
            '_uploads' => [
                'doc' => [
                    ['path' => 'foo/bar.pdf', 'size' => 10, 'mime' => 'application/pdf', 'original_name' => 'résumé.pdf', 'original_name_safe' => 'résumé.pdf'],
                ],
            ],
        ];
        $meta = ['form_id' => 't1', 'submission_id' => 'sub1'];
        Emailer::send($tpl, $canonical, $meta);
        global $TEST_ARTIFACTS;
        $mail = json_decode((string)file_get_contents($TEST_ARTIFACTS['mail_file']), true);
        $att = $mail[0]['attachments'][0];
        $this->assertSame('résumé.pdf', $att['name']);
        $this->assertSame('utf-8', $att['encoding']);
    }

    public function testAttachmentNameTransliteratedWhenEnabled(): void
    {
        Config::bootstrap();
        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => ['to' => 'a@example.com', 'subject' => 's', 'email_template' => 'default', 'include_fields' => []],
            'fields' => [
                ['type' => 'file', 'key' => 'doc', 'accept' => ['pdf'], 'email_attach' => true],
            ],
            'submit_button_text' => 'Send',
            'rules' => [],
        ];
        $canonical = [
            '_uploads' => [
                'doc' => [
                    ['path' => 'foo/bar.pdf', 'size' => 10, 'mime' => 'application/pdf', 'original_name' => 'résumé.pdf', 'original_name_safe' => 'resume.pdf'],
                ],
            ],
        ];
        $meta = ['form_id' => 't1', 'submission_id' => 'sub1'];
        Emailer::send($tpl, $canonical, $meta);
        global $TEST_ARTIFACTS;
        $mail = json_decode((string)file_get_contents($TEST_ARTIFACTS['mail_file']), true);
        $att = $mail[0]['attachments'][0];
        $this->assertSame('resume.pdf', $att['name']);
        $this->assertArrayNotHasKey('encoding', $att);
    }
}
