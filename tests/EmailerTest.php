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
        $this->assertContains('From: Jane Doe <noreply@example.test>', $mail['headers']);
        $hasReply = false;
        foreach ($mail['headers'] as $h) {
            $this->assertStringNotContainsString('Bcc', $h);
            if (strpos($h, 'Reply-To:') === 0) {
                $hasReply = true;
            }
        }
        $this->assertFalse($hasReply, 'Reply-To header should be omitted for invalid email');
    }
    public function test_ip_masked_by_default(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $logger = new Logging();
        $ip = $logger->get_ip();
        $emailer = new Emailer($ip);
        $config = ['email' => ['include_fields' => ['ip']]];
        $this->assertTrue($emailer->dispatch_email([], $config));
        $mail = $GLOBALS['_last_mail'];
        $this->assertStringContainsString('203.0.113.0', $mail['message']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ip_excluded_when_mode_none(): void {
        define('EFORMS_IP_MODE', 'none');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $logger = new Logging();
        $ip = $logger->get_ip();
        $emailer = new Emailer($ip);
        $config = ['email' => ['include_fields' => ['ip']]];
        $this->assertTrue($emailer->dispatch_email([], $config));
        $mail = $GLOBALS['_last_mail'];
        $this->assertStringNotContainsString('IP:', $mail['message']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ip_full_when_mode_full(): void {
        define('EFORMS_IP_MODE', 'full');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $logger = new Logging();
        $ip = $logger->get_ip();
        $emailer = new Emailer($ip);
        $config = ['email' => ['include_fields' => ['ip']]];
        $this->assertTrue($emailer->dispatch_email([], $config));
        $mail = $GLOBALS['_last_mail'];
        $this->assertStringContainsString('203.0.113.5', $mail['message']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ip_hashed_when_mode_hash(): void {
        define('EFORMS_IP_MODE', 'hash');
        define('EFORMS_IP_SALT', 'salt');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $logger = new Logging();
        $expected = hash('sha256', '203.0.113.5' . 'salt');
        $ip = $logger->get_ip();
        $this->assertSame($expected, $ip);
        $emailer = new Emailer($ip);
        $config = ['email' => ['include_fields' => ['ip']]];
        $this->assertTrue($emailer->dispatch_email([], $config));
        $mail = $GLOBALS['_last_mail'];
        $this->assertStringContainsString($expected, $mail['message']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_from_domain_can_be_overridden(): void {
        define('EFORMS_FROM_DOMAIN', 'override.test');
        define('EFORMS_FROM_USER', 'robot');
        $emailer = new Emailer('127.0.0.1');
        $this->assertTrue($emailer->dispatch_email([], []));
        $mail = $GLOBALS['_last_mail'];
        $found = false;
        foreach ($mail['headers'] as $h) {
            if (strpos($h, 'From:') === 0) {
                $found = $h;
                break;
            }
        }
        $this->assertNotFalse($found);
        $this->assertStringContainsString('robot@override.test', $found);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_staging_redirect_header(): void {
        define('EFORMS_STAGING_REDIRECT', 'stage@example.com');
        $emailer = new Emailer('127.0.0.1');
        $this->assertTrue($emailer->dispatch_email([], []));
        $mail = $GLOBALS['_last_mail'];
        $this->assertContains('X-Staging-Redirect: stage@example.com', $mail['headers']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_suspect_tag_header(): void {
        define('EFORMS_SUSPECT_TAG', 'suspect');
        $emailer = new Emailer('127.0.0.1');
        $config = ['email' => ['suspect' => true]];
        $this->assertTrue($emailer->dispatch_email([], $config));
        $mail = $GLOBALS['_last_mail'];
        $this->assertContains('X-Tag: suspect', $mail['headers']);
    }
}
