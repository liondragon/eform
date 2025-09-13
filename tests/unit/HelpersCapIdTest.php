<?php
declare(strict_types=1);

use EForms\Helpers;

final class HelpersCapIdTest extends BaseTestCase
{
    public function testShortIdUnchanged(): void
    {
        $id = str_repeat('a', 20);
        $this->assertSame($id, Helpers::cap_id($id));
    }

    public function testExactLimitUnchanged(): void
    {
        $id = str_repeat('b', 128);
        $this->assertSame($id, Helpers::cap_id($id));
    }

    public function testLongIdTruncated(): void
    {
        $id = str_repeat('c', 200);
        $out = Helpers::cap_id($id, 128);
        $this->assertSame(128, strlen($out));
        $this->assertSame(substr($id, 0, 59), substr($out, 0, 59));
        $this->assertSame(substr($id, -59), substr($out, -59));
        $mid = substr($out, 60, 8);
        $this->assertMatchesRegularExpression('/^[a-z2-7]{8}$/', $mid);
    }

    public function testDeterministic(): void
    {
        $id = str_repeat('xyz', 50);
        $a = Helpers::cap_id($id);
        $b = Helpers::cap_id($id);
        $this->assertSame($a, $b);
    }

    public function testSmallMax(): void
    {
        $id = str_repeat('d', 100);
        $out = Helpers::cap_id($id, 16);
        $this->assertSame(16, strlen($out));
        $this->assertSame(substr($id, 0, 3), substr($out, 0, 3));
        $this->assertSame(substr($id, -3), substr($out, -3));
        $mid = substr($out, 4, 8);
        $this->assertMatchesRegularExpression('/^[a-z2-7]{8}$/', $mid);
    }

    public function testTinyMax(): void
    {
        $id = str_repeat('e', 20);
        $out = Helpers::cap_id($id, 8);
        $this->assertSame(8, strlen($out));
        $this->assertMatchesRegularExpression('/^[a-z2-7]{8}$/', $out);
    }
}
