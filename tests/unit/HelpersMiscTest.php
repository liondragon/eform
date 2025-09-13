<?php
declare(strict_types=1);

use EForms\Helpers;

final class HelpersMiscTest extends BaseTestCase
{
    public function testUuid4Format(): void
    {
        $uuid = Helpers::uuid4();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function testRandomIdDefault(): void
    {
        $id = Helpers::random_id();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $id);
        $this->assertSame(22, strlen($id));
    }

    public function testRandomIdCustomBytes(): void
    {
        $id = Helpers::random_id(1);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $id);
        $this->assertSame(2, strlen($id));
    }

    public function testSanitizeUserAgent(): void
    {
        set_config(['security' => ['ua_maxlen' => 10]]);
        $ua = "Foo\x01Bar\x80BazQux"; // contains non-ASCII and >10 chars after cleanup
        $sanitized = Helpers::sanitize_user_agent($ua);
        $this->assertSame('FooBarBazQ', $sanitized);
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $sanitized);
        $this->assertSame(10, strlen($sanitized));
    }

    public function testIpDisplayModes(): void
    {
        set_config(['privacy' => ['ip_mode' => 'masked']]);
        $this->assertSame('192.0.2.0', Helpers::ip_display('192.0.2.123'));

        set_config(['privacy' => ['ip_mode' => 'hash', 'ip_salt' => 'pepper']]);
        $expected = hash('sha256', '192.0.2.123' . 'pepper');
        $this->assertSame($expected, Helpers::ip_display('192.0.2.123'));

        set_config(['privacy' => ['ip_mode' => 'none']]);
        $this->assertSame('', Helpers::ip_display('192.0.2.123'));
    }

    public function testRequestUriFiltering(): void
    {
        $_SERVER['REQUEST_URI'] = '/foo?bar=1&eforms_token=a&baz=2&eforms_mode=x';
        $this->assertSame('/foo?eforms_token=a&eforms_mode=x', Helpers::request_uri());

        $_SERVER['REQUEST_URI'] = '/foo?bar=1&baz=2';
        $this->assertSame('/foo', Helpers::request_uri());

        unset($_SERVER['REQUEST_URI']);
        $this->assertSame('', Helpers::request_uri());
    }
}
