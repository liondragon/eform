<?php
declare(strict_types=1);

namespace EForms {
    function syslog($level, $message) {
        global $TEST_F2B_SYSLOG;
        $TEST_F2B_SYSLOG[] = [$level, $message];
    }
}

namespace {
// bootstrap handled by phpunit.xml

use EForms\Config;
use EForms\Logging;

final class Fail2banLoggingTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $TEST_FILTERS, $TEST_F2B_SYSLOG, $TEST_ARTIFACTS;
        $TEST_F2B_SYSLOG = [];
        $TEST_FILTERS = [];
        register_test_env_filter();
        file_put_contents($TEST_ARTIFACTS['log_file'], '');
    }

    protected function tearDown(): void
    {
        global $TEST_FILTERS;
        $TEST_FILTERS = [];
        register_test_env_filter();
        parent::tearDown();
    }

    private function boot(array $opts): void
    {
        $ref = new \ReflectionClass(Config::class);
        $boot = $ref->getProperty('bootstrapped');
        $boot->setAccessible(true);
        $boot->setValue(false);
        $data = $ref->getProperty('data');
        $data->setAccessible(true);
        $data->setValue([]);
        add_filter('eforms_config', function ($defaults) use ($opts) {
            $defaults['logging']['mode'] = 'off';
            $defaults['logging']['fail2ban'] = array_replace($defaults['logging']['fail2ban'], $opts);
            return $defaults;
        });
        Config::bootstrap();
    }

    public function testErrorLogTarget(): void
    {
        global $TEST_ARTIFACTS;
        $this->boot(['enable' => true, 'target' => 'error_log']);
        Logging::write('warn', 'EFORMS_F2B_ERR', ['ip' => '1.2.3.4']);
        $log = file_get_contents($TEST_ARTIFACTS['log_file']);
        $this->assertStringContainsString('code=EFORMS_F2B_ERR', (string) $log);
        $this->assertStringContainsString('eforms[f2b]', (string) $log);
    }

    public function testSyslogTarget(): void
    {
        global $TEST_F2B_SYSLOG;
        $this->boot(['enable' => true, 'target' => 'syslog']);
        Logging::write('warn', 'EFORMS_F2B_SYS', ['ip' => '5.6.7.8']);
        $this->assertNotEmpty($TEST_F2B_SYSLOG);
        $this->assertStringContainsString('code=EFORMS_F2B_SYS', $TEST_F2B_SYSLOG[0][1]);
        $this->assertStringContainsString('eforms[f2b]', $TEST_F2B_SYSLOG[0][1]);
    }

    public function testFileRotationAndRetention(): void
    {
        $this->boot([
            'enable' => true,
            'target' => 'file',
            'file' => 'f2b/eforms-f2b.log',
            'file_max_size' => 40,
            'retention_days' => 1,
        ]);
        $base = rtrim(Config::get('uploads.dir'), '/') . '/f2b';
        $file = $base . '/eforms-f2b.log';
        @unlink($file);
        foreach (glob($base . '/eforms-f2b-*.log') ?: [] as $f) {
            @unlink($f);
        }
        @mkdir($base, 0700, true);
        $old = $base . '/eforms-f2b-20000101-000000.log';
        file_put_contents($old, 'old');
        touch($old, time() - 86400 * 2);
        Logging::write('warn', 'CODE1', ['ip' => '1.1.1.1']);
        Logging::write('warn', 'CODE2', ['ip' => '1.1.1.1']);
        $rotated = glob($base . '/eforms-f2b-*.log');
        $this->assertNotEmpty($rotated);
        $this->assertStringContainsString('CODE1', file_get_contents($rotated[0]));
        $this->assertStringContainsString('CODE2', (string) file_get_contents($file));
        $this->assertStringContainsString('eforms[f2b]', (string) file_get_contents($file));
        $this->assertFalse(file_exists($old));
    }
}
}
