<?php
use EForms\Config;
use EForms\Rendering\FormRenderer;

final class ChallengeInitTest extends BaseTestCase
{
    private array $origConfig;

    protected function setUp(): void
    {
        parent::setUp();

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
        parent::tearDown();
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

    public function testChallengeModeAutoEnqueuesScriptAndMarkup(): void
    {
        $this->setConfig('challenge.mode', 'auto');
        $fm = new FormRenderer();
        $GLOBALS['wp_enqueued_scripts'] = [];
        $html = $fm->render('contact_us');
        $this->assertContains('eforms-challenge-turnstile', $GLOBALS['wp_enqueued_scripts']);
        $this->assertStringContainsString('<div class="cf-challenge"', $html);
    }

    public function testPolicyChallengeEnqueuesScriptWhenCookieMissing(): void
    {
        $this->setConfig('security.cookie_missing_policy', 'challenge');
        unset($_COOKIE['eforms_t_contact_us']);
        $fm = new FormRenderer();
        $GLOBALS['wp_enqueued_scripts'] = [];
        $html = $fm->render('contact_us');
        $this->assertContains('eforms-challenge-turnstile', $GLOBALS['wp_enqueued_scripts']);
        $this->assertStringContainsString('<div class="cf-challenge"', $html);
    }

    public function testChallengeModeAlwaysEnqueuesScriptAndMarkup(): void
    {
        $this->setConfig('challenge.mode', 'always');
        $fm = new FormRenderer();
        $GLOBALS['wp_enqueued_scripts'] = [];
        $html = $fm->render('contact_us');
        $this->assertContains('eforms-challenge-turnstile', $GLOBALS['wp_enqueued_scripts']);
        $this->assertStringContainsString('<div class="cf-challenge"', $html);
    }
}
