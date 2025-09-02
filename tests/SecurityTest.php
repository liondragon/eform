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
    
    public function test_referrer_policy_soft_path_increments_score_on_mismatch() {
        if ( ! defined('EFORMS_SOFT_FAIL_THRESHOLD') ) {
            define('EFORMS_SOFT_FAIL_THRESHOLD', 1);
        }
        $security = new Security();
        $server = [
            'HTTP_USER_AGENT' => 'UA',
            'HTTP_REFERER'    => 'https://bad.com/path',
            'HTTP_HOST'       => 'example.com',
            'REQUEST_URI'     => '/home',
        ];
        $security->check_referrer( $server, 'soft_path' );
        $signals = $security->get_signals( $server );
        $this->assertSame( 1, $signals['score'] );
        $this->assertTrue( $signals['soft_fail'] );
        $this->assertFalse( $signals['signals']['referrer_ok'] );
    }

    public function test_js_soft_mode_increments_score() {
        if ( ! defined('EFORMS_SOFT_FAIL_THRESHOLD') ) {
            define('EFORMS_SOFT_FAIL_THRESHOLD', 1);
        }
        $security = new Security();
        $data = [];
        $security->check_js_enabled( $data, 'soft' );
        $server = [
            'HTTP_USER_AGENT' => 'UA',
            'HTTP_HOST'       => 'example.com',
        ];
        $signals = $security->get_signals( $server );
        $this->assertSame( 1, $signals['score'] );
        $this->assertTrue( $signals['soft_fail'] );
        $this->assertFalse( $signals['signals']['js_ok'] );
    }

    public function test_nonce_lifetime_constant_passed() {
        if ( ! defined( 'EFORMS_NONCE_LIFETIME' ) ) {
            define( 'EFORMS_NONCE_LIFETIME', 123 );
        }
        $security = new Security();
        $data = [ '_wpnonce' => 'valid', 'form_id' => 'f', 'instance_id' => 'i' ];
        $security->check_nonce( $data );
        $this->assertSame( 123, $GLOBALS['_last_nonce_ttl'] );
    }

    public function test_referrer_policy_hard_returns_error_on_mismatch() {
        $security = new Security();
        $server = [
            'HTTP_REFERER' => 'https://bad.com/x',
            'HTTP_HOST'    => 'example.com',
            'REQUEST_URI'  => '/y',
        ];
        $error = $security->check_referrer( $server, 'hard' );
        $this->assertSame( 'Referrer Check Failed', $error['type'] );
    }
}

