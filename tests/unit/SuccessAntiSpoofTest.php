<?php
declare(strict_types=1);

use EForms\Config;
use EForms\Security\Security;

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
        $getSnapshot = $_GET;
        try {
            $_GET = ['eforms_success' => 'contact_us'];
            $_COOKIE = [];
            $fm = new \EForms\Rendering\FormRenderer();
            $html = $fm->render('contact_us');
            $this->assertSame('', $html);
        } finally {
            $_GET = $getSnapshot;
        }
    }

    public function testNoSuccessWithCookieOnly(): void
    {
        header_remove();
        $getSnapshot = $_GET;
        try {
            $_GET = [];
            $_COOKIE = ['eforms_s_contact_us' => 'subm'];
            $fm = new \EForms\Rendering\FormRenderer();
            $html = $fm->render('contact_us');
            $this->assertStringContainsString('<form', $html);
            $this->assertStringNotContainsString('eforms-success-pending', $html);
        } finally {
            $_GET = $getSnapshot;
        }
    }

    public function testNoSuccessWithMismatchedCookie(): void
    {
        header_remove();
        $getSnapshot = $_GET;
        try {
            $_GET = ['eforms_success' => 'contact_us'];
            $_COOKIE = ['eforms_s_contact_us' => 'other-subm'];
            $fm = new \EForms\Rendering\FormRenderer();
            $html = $fm->render('contact_us');
            $this->assertSame('', $html);
        } finally {
            $_GET = $getSnapshot;
        }
    }

    public function testSuccessWithMatchingCookieAndQuery(): void
    {
        header_remove();
        $getSnapshot = $_GET;
        try {
            Security::successTicketStore('contact_us', 'subm');
            $_GET = ['eforms_success' => 'contact_us'];
            $_COOKIE = ['eforms_s_contact_us' => 'subm'];
            $fm = new \EForms\Rendering\FormRenderer();
            $html = $fm->render('contact_us');
            $this->assertStringContainsString('eforms-success-pending', $html);
            $this->assertStringContainsString('data-submission-id="subm"', $html);
        } finally {
            $_GET = $getSnapshot;
        }
    }

    public function testSuccessCookieConsumedOnlyOnce(): void
    {
        header_remove();
        $getSnapshot = $_GET;
        try {
            Security::successTicketStore('contact_us', 'subm');
            $_GET = ['eforms_success' => 'contact_us'];
            $_COOKIE = ['eforms_s_contact_us' => 'subm'];
            $fm = new \EForms\Rendering\FormRenderer();
            $html1 = $fm->render('contact_us');
            $this->assertStringContainsString('eforms-success-pending', $html1);
            $fm2 = new \EForms\Rendering\FormRenderer();
            $html2 = $fm2->render('contact_us');
            $this->assertSame('', $html2);
        } finally {
            $_GET = $getSnapshot;
        }
    }
}

