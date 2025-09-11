<?php
use PHPUnit\Framework\TestCase;
use EForms\Config;
use EForms\Helpers;

final class ClientIpHeaderTest extends TestCase
{
    private array $origConfig;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $this->origConfig = $prop->getValue();
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->origConfig);
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    private function setConfig(string $path, $value): void
    {
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $data = $prop->getValue();
        $segments = explode('.', $path);
        $cursor =& $data;
        $last = array_pop($segments);
        foreach ($segments as $seg) {
            if (!isset($cursor[$seg]) || !is_array($cursor[$seg])) {
                $cursor[$seg] = [];
            }
            $cursor =& $cursor[$seg];
        }
        $cursor[$last] = $value;
        $prop->setValue(null, $data);
    }

    public function testHeaderUsedWhenTrustedProxy(): void
    {
        $this->setConfig('privacy.client_ip_header', 'X-Forwarded-For');
        $this->setConfig('privacy.trusted_proxies', ['10.0.0.1']);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 70.1.1.1';
        $ip = Helpers::client_ip();
        $this->assertSame('203.0.113.5', $ip);
    }

    public function testHeaderIgnoredWhenUntrusted(): void
    {
        $this->setConfig('privacy.client_ip_header', 'X-Forwarded-For');
        $this->setConfig('privacy.trusted_proxies', ['10.0.0.0/8']);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5';
        $ip = Helpers::client_ip();
        $this->assertSame('198.51.100.9', $ip);
    }

    public function testHeaderUsedWhenTrustedProxyCidr(): void
    {
        $this->setConfig('privacy.client_ip_header', 'X-Forwarded-For');
        $this->setConfig('privacy.trusted_proxies', ['10.0.0.0/8']);
        $_SERVER['REMOTE_ADDR'] = '10.5.6.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5';
        $ip = Helpers::client_ip();
        $this->assertSame('203.0.113.5', $ip);
    }
}
