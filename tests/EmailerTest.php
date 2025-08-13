<?php
use PHPUnit\Framework\TestCase;

class EmailerTest extends TestCase {
    protected function setUp(): void {
        unset($GLOBALS['_last_mail']);
    }

    public function test_plain_text_and_field_inclusion_and_tel_formatting(): void {
        $emailer = new Emailer('127.0.0.1');
        $config = [
            'email' => ['include_fields' => ['name', 'phone']],
            'display_format_tel' => true,
        ];
        $data = [
            'name'  => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890',
            'zip'   => '99999',
        ];
        $this->assertTrue($emailer->dispatch_email($data, $config));
        $mail = $GLOBALS['_last_mail'];
        $this->assertStringContainsString('Name: John Doe', $mail['message']);
        $this->assertStringContainsString('Phone: 123-456-7890', $mail['message']);
        $this->assertStringNotContainsString('Zip', $mail['message']);
        $this->assertContains('Content-Type: text/plain; charset=UTF-8', $mail['headers']);
    }

    public function test_html_email_enabled_via_constant(): void {
        if (!defined('EFORM_ALLOW_HTML_EMAIL')) {
            define('EFORM_ALLOW_HTML_EMAIL', true);
        }
        $emailer = new Emailer('127.0.0.1');
        $data = [
            'name'    => 'Jane',
            'email'   => 'jane@example.com',
            'message' => "Hello world",
        ];
        $this->assertTrue($emailer->dispatch_email($data, []));
        $mail = $GLOBALS['_last_mail'];
        $this->assertContains('Content-Type: text/html; charset=UTF-8', $mail['headers']);
        $this->assertStringContainsString('<table', $mail['message']);
    }

    public function test_header_sanitization_and_validation(): void {
        $emailer = new Emailer('127.0.0.1');
        $data = [
            'name'  => "Jane\r\nDoe",
            'email' => "bad@example.com\r\nBcc:evil@example.com",
        ];
        $this->assertTrue($emailer->dispatch_email($data, []));
        $mail = $GLOBALS['_last_mail'];
        $this->assertContains('From: Jane Doe <noreply@flooringartists.com>', $mail['headers']);
        $hasReply = false;
        foreach ($mail['headers'] as $h) {
            $this->assertStringNotContainsString('Bcc', $h);
            if (strpos($h, 'Reply-To:') === 0) {
                $hasReply = true;
            }
        }
        $this->assertFalse($hasReply, 'Reply-To header should be omitted for invalid email');
    }
}
