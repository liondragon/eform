<?php
declare(strict_types=1);

use EForms\Config;

final class EnvBridgeTest extends BaseTestCase
{
    public function testEnvOverridesConfig(): void
    {
        putenv('EFORMS_LOG_LEVEL=2');
        $ref = new ReflectionClass(Config::class);
        $boot = $ref->getProperty('bootstrapped');
        $boot->setAccessible(true);
        $boot->setValue(null, false);
        $data = $ref->getProperty('data');
        $data->setAccessible(true);
        $data->setValue(null, []);
        Config::bootstrap();
        $this->assertSame(2, Config::get('logging.level'));
    }
}
