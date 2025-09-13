<?php
declare(strict_types=1);

use EForms\Email\Emailer;
use EForms\Config;

final class EmailTelFormattingTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // reset mail file
        global $TEST_ARTIFACTS;
        @file_put_contents($TEST_ARTIFACTS['mail_file'], '[]');
    }

    /**
     * @dataProvider formatProvider
     */
    public function testTelFormatting(string $fmt, string $expected): void
    {
        Config::bootstrap();
        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => [
                'to' => 'a@example.com',
                'subject' => 's',
                'email_template' => 'default',
                'include_fields' => ['phone'],
                'display_format_tel' => $fmt,
            ],
            'fields' => [
                ['type' => 'tel_us', 'key' => 'phone'],
            ],
            'submit_button_text' => 'Send',
            'rules' => [],
        ];
        $canonical = ['phone' => '1234567890'];
        $meta = ['form_id' => 't1', 'instance_id' => 'i1'];
        Emailer::send($tpl, $canonical, $meta);
        global $TEST_ARTIFACTS;
        $mail = json_decode((string) file_get_contents($TEST_ARTIFACTS['mail_file']), true);
        $this->assertStringContainsString("phone: $expected", $mail[0]['message'] ?? '');
    }

    public static function formatProvider(): array
    {
        return [
            ['xxx-xxx-xxxx', '123-456-7890'],
            ['(xxx) xxx-xxxx', '(123) 456-7890'],
            ['xxx.xxx.xxxx', '123.456.7890'],
        ];
    }
}
