<?php
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase {
    public function test_collects_user_agent_and_referrer() {
        $security = new Security();
        $server = [
            'HTTP_USER_AGENT' => 'TestUA',
            'HTTP_REFERER'    => 'https://example.com/path',
            'HTTP_HOST'       => 'example.com',
        ];
        $signals = $security->get_signals($server);
        $this->assertSame('TestUA', $signals['signals']['user_agent']);
        $this->assertSame('https://example.com/path', $signals['signals']['referrer']);
        $this->assertSame(0, $signals['score']);
        $this->assertFalse($signals['soft_fail']);
    }
    
    public function test_referrer_policy_same_origin_increments_score_on_mismatch() {
        if ( ! defined('EFORMS_SECURITY_SOFT_FAIL_THRESHOLD') ) {
            define('EFORMS_SECURITY_SOFT_FAIL_THRESHOLD', 1);
        }
        $security = new Security();
        $server = [
            'HTTP_USER_AGENT' => 'UA',
            'HTTP_REFERER'    => 'https://bad.com/',
            'HTTP_HOST'       => 'example.com',
        ];
        $signals = $security->get_signals($server, 'same-origin');
        $this->assertSame(1, $signals['score']);
        $this->assertTrue($signals['soft_fail']);
        $this->assertSame('mismatch', $signals['signals']['referrer_status']);
    }

    public function test_js_soft_mode_increments_score() {
        if ( ! defined('EFORMS_SECURITY_SOFT_FAIL_THRESHOLD') ) {
            define('EFORMS_SECURITY_SOFT_FAIL_THRESHOLD', 1);
        }
        $security = new Security();
        $data = [];
        $security->check_js_enabled($data, 'soft');
        $server = [
            'HTTP_USER_AGENT' => 'UA',
            'HTTP_HOST'       => 'example.com',
        ];
        $signals = $security->get_signals($server);
        $this->assertSame(1, $signals['score']);
        $this->assertTrue($signals['soft_fail']);
        $this->assertSame('missing', $signals['signals']['js']);
    }
}

