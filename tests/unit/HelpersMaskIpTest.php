<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Helpers;

final class HelpersMaskIpTest extends TestCase
{
    public function testIpv4Mask(): void
    {
        $this->assertSame('192.0.2.0', Helpers::mask_ip('192.0.2.123'));
    }

    public function testIpv6Mask(): void
    {
        $this->assertSame('2001:db8:85a3::', Helpers::mask_ip('2001:db8:85a3:0:0:8a2e:370:7334'));
    }

    public function testInvalidInput(): void
    {
        $this->assertSame('', Helpers::mask_ip('192.0.2.foo'));
    }
}
