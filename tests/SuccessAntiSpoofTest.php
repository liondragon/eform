<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Config;

final class SuccessAntiSpoofTest extends TestCase
{
    protected function setUp(): void
    {
        Config::bootstrap();
    }

    public function testNoSuccessWithQueryOnly(): void
    {
        header_remove();
        $_GET = ['eforms_success' => 'contact_us'];
        $_COOKIE = [];
        $fm = new \EForms\FormManager();
        $html = $fm->render('contact_us');
        $this->assertStringNotContainsString('Thanks! We got your message.', $html);
    }

    public function testNoSuccessWithCookieOnly(): void
    {
        header_remove();
        $_GET = [];
        $_COOKIE = ['eforms_s_contact_us' => 'contact_us:inst'];
        $fm = new \EForms\FormManager();
        $html = $fm->render('contact_us');
        $this->assertStringNotContainsString('Thanks! We got your message.', $html);
    }

    public function testNoSuccessWithMismatchedCookie(): void
    {
        header_remove();
        $_GET = ['eforms_success' => 'contact_us'];
        $_COOKIE = ['eforms_s_contact_us' => 'other:inst'];
        $fm = new \EForms\FormManager();
        $html = $fm->render('contact_us');
        $this->assertStringNotContainsString('Thanks! We got your message.', $html);
    }

    public function testSuccessWithMatchingCookieAndQuery(): void
    {
        header_remove();
        $_GET = ['eforms_success' => 'contact_us'];
        $_COOKIE = ['eforms_s_contact_us' => 'contact_us:inst'];
        $fm = new \EForms\FormManager();
        $html = $fm->render('contact_us');
        $this->assertStringContainsString('Thanks! We got your message.', $html);
    }
}

