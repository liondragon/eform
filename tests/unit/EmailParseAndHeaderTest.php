<?php
declare(strict_types=1);

use EForms\Email\Emailer;

final class EmailParseAndHeaderTest extends BaseTestCase
{
    private function callParseEmail(string $email, string $policy): string
    {
        $ref = new \ReflectionClass(Emailer::class);
        $m = $ref->getMethod('parseEmail');
        $m->setAccessible(true);
        return (string) $m->invoke(null, $email, $policy);
    }

    private function callSanitizeHeader(string $header): string
    {
        $ref = new \ReflectionClass(Emailer::class);
        $m = $ref->getMethod('sanitizeHeader');
        $m->setAccessible(true);
        return (string) $m->invoke(null, $header);
    }

    public function testParseEmailValidStrict(): void
    {
        $out = $this->callParseEmail('user@example.com', 'strict');
        $this->assertSame('user@example.com', $out);
    }

    public function testParseEmailAutocorrectTypos(): void
    {
        $out = $this->callParseEmail('  User@Example.c0m  ', 'autocorrect');
        $this->assertSame('User@example.com', $out);
    }

    public function testParseEmailInvalidReturnsEmpty(): void
    {
        $out = $this->callParseEmail('not-an-email', 'strict');
        $this->assertSame('', $out);
    }

    public function testSanitizeHeaderRemovesInjection(): void
    {
        $in = "Subject\r\nX-Bad: value\x07";
        $out = $this->callSanitizeHeader($in);
        $this->assertSame('Subject X-Bad: value', $out);
        $this->assertStringNotContainsString("\r", $out);
        $this->assertStringNotContainsString("\n", $out);
        $this->assertStringNotContainsString("\x07", $out);
    }

    public function testSanitizeHeaderTruncatesAt255(): void
    {
        $in = str_repeat('a', 300);
        $out = $this->callSanitizeHeader($in);
        $this->assertSame(255, strlen($out));
        $this->assertSame(str_repeat('a', 255), $out);
    }
}
