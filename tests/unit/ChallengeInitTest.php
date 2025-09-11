<?php
use PHPUnit\Framework\TestCase;
use EForms\Config;
use EForms\Rendering\FormManager;

final class ChallengeInitTest extends TestCase
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
        $GLOBALS['wp_enqueued_scripts'] = [];
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

    public function testChallengeModeAutoDoesNotEnqueueScript(): void
    {
        $this->setConfig('challenge.mode', 'auto');
        $fm = new FormManager();
        $GLOBALS['wp_enqueued_scripts'] = [];
        $fm->render('contact_us');
        $this->assertNotContains('eforms-challenge-turnstile', $GLOBALS['wp_enqueued_scripts']);
    }

    public function testPolicyChallengeDoesNotEnqueueScript(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'challenge');
        $fm = new FormManager();
        $GLOBALS['wp_enqueued_scripts'] = [];
        $fm->render('contact_us');
        $this->assertNotContains('eforms-challenge-turnstile', $GLOBALS['wp_enqueued_scripts']);
    }

    public function testChallengeModeAlwaysEnqueuesScript(): void
    {
        $this->setConfig('challenge.mode', 'always');
        $fm = new FormManager();
        $GLOBALS['wp_enqueued_scripts'] = [];
        $fm->render('contact_us');
        $this->assertContains('eforms-challenge-turnstile', $GLOBALS['wp_enqueued_scripts']);
    }
}
