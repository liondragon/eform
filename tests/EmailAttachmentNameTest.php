<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Emailer;
use EForms\Config;

final class EmailAttachmentNameTest extends TestCase
{
    protected function setUp(): void
    {
        // reset mail file
        global $TEST_ARTIFACTS;
        @file_put_contents($TEST_ARTIFACTS['mail_file'], '[]');
    }

    public function testAttachmentUsesOriginalName(): void
    {
        Config::bootstrap();
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $cfg = $prop->getValue();
        $cfg['uploads']['transliterate'] = false;
        $prop->setValue($cfg);

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
        $meta = ['form_id' => 't1', 'instance_id' => 'i1'];
        Emailer::send($tpl, $canonical, $meta);
        global $TEST_ARTIFACTS;
        $mail = json_decode((string)file_get_contents($TEST_ARTIFACTS['mail_file']), true);
        $this->assertSame('résumé.pdf', $mail[0]['attachments'][0]['name']);
    }
}
