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
    private array $getSnapshot = [];
    /** @var array<string,mixed> */
    private array $postSnapshot = [];
    /** @var array<string,mixed> */
    private array $cookieSnapshot = [];
    /** @var array<string,mixed> */
    private array $serverSnapshot = [];
    /** @var array<string,mixed> */
    private array $filtersSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Snapshot superglobals that tests may mutate
        $this->getSnapshot = $_GET;
        $this->postSnapshot = $_POST;
        $this->cookieSnapshot = $_COOKIE;
        $this->serverSnapshot = $_SERVER;
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
        Config::resetForTests();
        Logging::resetForTests();
    }

    protected function tearDown(): void
    {
        // Restore superglobals for test isolation
        $_GET = $this->getSnapshot;
        $_POST = $this->postSnapshot;
        $_COOKIE = $this->cookieSnapshot;
        // Only restore server keys that were overridden by tests to avoid clobbering runtime
        foreach (array_keys($_SERVER) as $k) {
            if (!array_key_exists($k, $this->serverSnapshot)) {
                unset($_SERVER[$k]);
            }
        }
        foreach ($this->serverSnapshot as $k => $v) {
            $_SERVER[$k] = $v;
        }
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
        Config::resetForTests();
        Logging::resetForTests();
        parent::tearDown();
    }
}
