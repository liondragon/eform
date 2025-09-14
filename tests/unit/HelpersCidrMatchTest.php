<?php
declare(strict_types=1);

use EForms\Helpers;

final class HelpersCidrMatchTest extends BaseTestCase
{
    private function invokeCidrMatch(string $ip, string $cidr): bool
    {
        $method = new \ReflectionMethod(Helpers::class, 'cidr_match');
        $method->setAccessible(true);
        return (bool) $method->invoke(null, $ip, $cidr);
    }

    public function testIpv4(): void
    {
        $this->assertTrue($this->invokeCidrMatch('192.168.1.5', '192.168.1.0/24'));
        $this->assertFalse($this->invokeCidrMatch('10.0.0.1', '192.168.1.0/24'));
    }

    public function testIpv6(): void
    {
        $this->assertTrue($this->invokeCidrMatch('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse($this->invokeCidrMatch('2001:db9::1', '2001:db8::/32'));
    }

    public function testInvalidCidrsReturnFalse(): void
    {
        $cases = [
            ['192.168.1.1', 'invalid'],
            ['192.168.1.1', '192.168.1.0/33'],
            ['2001:db8::1', '2001:db8::/129'],
            ['192.168.1.1', 'foo/bar'],
        ];
        foreach ($cases as [$ip, $cidr]) {
            $this->assertFalse($this->invokeCidrMatch($ip, $cidr));
        }
    }
}
