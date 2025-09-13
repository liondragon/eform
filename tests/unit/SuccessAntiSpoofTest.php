<?php
declare(strict_types=1);

use EForms\Config;

final class SuccessAntiSpoofTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::bootstrap();
    }

    public function testNoSuccessWithQueryOnly(): void
    {
        header_remove();
        $_GET = ['eforms_success' => 'contact_us'];
        $_COOKIE = [];
        $fm = new \EForms\Rendering\FormManager();
        $html = $fm->render('contact_us');
        $this->assertSame('', $html);
    }

    public function testNoSuccessWithCookieOnly(): void
    {
        header_remove();
        $_GET = [];
        $_COOKIE = ['eforms_s_contact_us' => 'contact_us:inst'];
        $fm = new \EForms\Rendering\FormManager();
        $html = $fm->render('contact_us');
        $this->assertStringNotContainsString('Thanks! We got your message.', $html);
    }

    public function testNoSuccessWithMismatchedCookie(): void
    {
        header_remove();
        $_GET = ['eforms_success' => 'contact_us'];
        $_COOKIE = ['eforms_s_contact_us' => 'other:inst'];
        $fm = new \EForms\Rendering\FormManager();
        $html = $fm->render('contact_us');
        $this->assertSame('', $html);
    }

    public function testSuccessWithMatchingCookieAndQuery(): void
    {
        header_remove();
        $_GET = ['eforms_success' => 'contact_us'];
        $_COOKIE = ['eforms_s_contact_us' => 'contact_us:inst'];
        $fm = new \EForms\Rendering\FormManager();
        $html = $fm->render('contact_us');
        $this->assertStringContainsString('Thanks! We got your message.', $html);
    }

    public function testSuccessCookieConsumedOnlyOnce(): void
    {
        header_remove();
        $_GET = ['eforms_success' => 'contact_us'];
        $_COOKIE = ['eforms_s_contact_us' => 'contact_us:inst'];
        $fm = new \EForms\Rendering\FormManager();
        $html1 = $fm->render('contact_us');
        $this->assertStringContainsString('Thanks! We got your message.', $html1);
        $fm2 = new \EForms\Rendering\FormManager();
        $html2 = $fm2->render('contact_us');
        $this->assertSame('', $html2);
    }
}

