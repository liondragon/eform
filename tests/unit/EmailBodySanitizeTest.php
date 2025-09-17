<?php
declare(strict_types=1);

use EForms\Email\Emailer;
use EForms\Config;

final class EmailBodySanitizeTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // reset mail file
        global $TEST_ARTIFACTS;
        @file_put_contents($TEST_ARTIFACTS['mail_file'], '[]');
    }

    public function testControlCharsRemoved(): void
    {
        Config::bootstrap();
        $prep = function ($phpmailer) {
            $phpmailer->Host = 'localhost';
        };
        add_action('phpmailer_init', $prep);
        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => [
                'to' => 'a@example.com',
                'subject' => 's',
                'email_template' => 'default',
                'include_fields' => ['body'],
            ],
            'fields' => [],
            'submit_button_text' => 'Send',
            'rules' => [],
        ];
        $rawBody = "Hello\rWorld\nAgain\x00\x1F";
        $canonical = ['body' => $rawBody];
        $meta = ['form_id' => 't1', 'submission_id' => 'sub1'];
        Emailer::send($tpl, $canonical, $meta);
        remove_action('phpmailer_init', $prep);
        global $TEST_ARTIFACTS;
        $mail = json_decode((string) file_get_contents($TEST_ARTIFACTS['mail_file']), true);
        $this->assertNotNull($mail[0]['message'] ?? null);
        $message = $mail[0]['message'];
        $this->assertStringNotContainsString("\r", $message);
        $this->assertStringNotContainsString("\x00", $message);
        $this->assertStringNotContainsString("\x1F", $message);
        $this->assertStringContainsString("body: Hello\nWorld\nAgain\n", $message);
    }
}
