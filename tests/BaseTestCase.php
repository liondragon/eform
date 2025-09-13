<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use EForms\Config;
use EForms\Logging;

class BaseTestCase extends TestCase
{
    /** @var array<string,string> */
    private array $envSnapshot = [];
    /** @var array<string,mixed> */
    private array $filtersSnapshot = [];
    /** @var array<string,mixed> */
    private array $configSnapshot = [];
    /** @var array<string,mixed> */
    private array $loggingSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ((array) getenv() as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'EFORMS_')) {
                $this->envSnapshot[$k] = (string) $v;
            }
        }
        global $TEST_FILTERS;
        $this->filtersSnapshot = $TEST_FILTERS;
        if (isset($this->filtersSnapshot['eforms_config'][10])) {
            $cbs = $this->filtersSnapshot['eforms_config'][10];
            array_shift($cbs);
            if (!empty($cbs)) {
                $this->filtersSnapshot['eforms_config'][10] = $cbs;
            } else {
                unset($this->filtersSnapshot['eforms_config'][10]);
                if (empty($this->filtersSnapshot['eforms_config'])) {
                    unset($this->filtersSnapshot['eforms_config']);
                }
            }
        }
        $this->configSnapshot = $this->snapshotStatic(Config::class);
        $this->loggingSnapshot = $this->snapshotStatic(Logging::class);
    }

    protected function tearDown(): void
    {
        foreach ((array) getenv() as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'EFORMS_')) {
                putenv($k);
                unset($_ENV[$k], $_SERVER[$k]);
            }
        }
        foreach ($this->envSnapshot as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
        global $TEST_FILTERS;
        $TEST_FILTERS = $this->filtersSnapshot;
        register_test_env_filter();
        $this->restoreStatic(Config::class, $this->configSnapshot);
        $this->restoreStatic(Logging::class, $this->loggingSnapshot);
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotStatic(string $class): array
    {
        $rc = new ReflectionClass($class);
        $props = [];
        foreach ($rc->getProperties(ReflectionProperty::IS_STATIC) as $prop) {
            $prop->setAccessible(true);
            $props[$prop->getName()] = $prop->getValue();
        }
        return $props;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function restoreStatic(string $class, array $snapshot): void
    {
        $rc = new ReflectionClass($class);
        foreach ($snapshot as $name => $value) {
            $prop = $rc->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($value);
        }
    }
}
