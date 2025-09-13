<?php
declare(strict_types=1);

use EForms\Config;
use EForms\Helpers;
use EForms\Security\Throttle;

final class ThrottleTest extends BaseTestCase
{
    public function testKeyFromIpModes(): void
    {
        $ref = new \ReflectionClass(Throttle::class);
        $m = $ref->getMethod('keyFromIp');
        $m->setAccessible(true);

        set_config(['privacy' => ['ip_mode' => 'masked', 'ip_salt' => 's']]);
        $masked = $m->invoke(null, '1.2.3.4');
        $expectedMasked = hash('sha256', Helpers::mask_ip('1.2.3.4') . 's');
        $this->assertSame($expectedMasked, $masked);

        set_config(['privacy' => ['ip_mode' => 'full']]);
        $full = $m->invoke(null, '1.2.3.4');
        $this->assertSame('1.2.3.4', $full);

        set_config(['privacy' => ['ip_mode' => 'none']]);
        $none = $m->invoke(null, '1.2.3.4');
        $this->assertNull($none);
    }

    public function testCheckStateTransitions(): void
    {
        $dir = sys_get_temp_dir() . '/eforms-throttle-' . uniqid('', true);
        @mkdir($dir, 0700, true);

        set_config([
            'uploads' => ['dir' => $dir],
            'privacy' => ['ip_mode' => 'full'],
            'throttle' => [
                'enable' => true,
                'per_ip' => [
                    'max_per_minute' => 2,
                    'cooldown_seconds' => 10,
                    'hard_multiplier' => 3,
                ],
            ],
        ]);

        $ip = '1.2.3.4';
        $res1 = Throttle::check($ip);
        $this->assertSame('ok', $res1['state']);
        $this->assertSame(0, $res1['retry_after']);

        $res2 = Throttle::check($ip);
        $this->assertSame('ok', $res2['state']);

        $res3 = Throttle::check($ip);
        $this->assertSame('over', $res3['state']);
        $this->assertGreaterThan(0, $res3['retry_after']);
        $this->assertLessThanOrEqual(10, $res3['retry_after']);

        $res4 = Throttle::check($ip);
        $this->assertSame('over', $res4['state']);
        $this->assertGreaterThan(0, $res4['retry_after']);
        $this->assertLessThanOrEqual($res3['retry_after'], $res4['retry_after']);

        Throttle::check($ip);
        Throttle::check($ip);
        $res7 = Throttle::check($ip);
        $this->assertSame('hard', $res7['state']);
        $this->assertGreaterThan(0, $res7['retry_after']);
        $this->assertLessThanOrEqual($res4['retry_after'], $res7['retry_after']);

        foreach (glob($dir . '/throttle/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($dir . '/throttle/*') ?: [] as $sub) {
            @rmdir($sub);
        }
        @rmdir($dir . '/throttle');
        @rmdir($dir);
    }

    public function testGcRemovesOldFiles(): void
    {
        $dir = sys_get_temp_dir() . '/eforms-throttlegc-' . uniqid('', true);
        $throttleDir = $dir . '/throttle';
        @mkdir($throttleDir . '/aa', 0700, true);
        @mkdir($throttleDir . '/bb', 0700, true);
        $old = $throttleDir . '/aa/old.json';
        $new = $throttleDir . '/bb/new.json';
        file_put_contents($old, 'old');
        file_put_contents($new, 'new');
        touch($old, time() - 172800 - 1);

        set_config(['uploads' => ['dir' => $dir]]);
        Throttle::gc();

        $this->assertFileDoesNotExist($old);
        $this->assertDirectoryDoesNotExist($throttleDir . '/aa');
        $this->assertFileExists($new);
        $this->assertDirectoryExists($throttleDir . '/bb');

        @unlink($new);
        @rmdir($throttleDir . '/bb');
        @rmdir($throttleDir);
        @rmdir($dir);
    }
}
