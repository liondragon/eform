<?php
declare(strict_types=1);

use EForms\Email\Emailer;

final class EmailTokenExpansionTest extends BaseTestCase
{
    private function expand(string $str, array $canonical, array $meta): string
    {
        $ref = new \ReflectionClass(Emailer::class);
        $m = $ref->getMethod('expandTokens');
        $m->setAccessible(true);
        return (string) $m->invoke(null, $str, $canonical, $meta);
    }

    public function testTokensExpandedAndUnknownPreserved(): void
    {
        // Minimal template structure for context
        $tpl = [
            'id' => 't1',
            'version' => '1',
            'title' => 't',
            'success' => ['mode' => 'inline'],
            'email' => [
                'to' => 'a@example.com',
                'subject' => 's',
                'email_template' => 'default',
                'include_fields' => [],
            ],
            'fields' => [
                ['type' => 'text', 'key' => 'foo'],
                ['type' => 'file', 'key' => 'up', 'email_attach' => true],
            ],
            'submit_button_text' => 'Send',
            'rules' => [],
        ];

        $canonical = [
            'foo' => 'bar',
            '_uploads' => [
                'up' => [
                    ['original_name_safe' => 'a.pdf'],
                    ['original_name_safe' => 'b.pdf'],
                ],
            ],
        ];
        $meta = [
            'submitted_at' => '2024-01-01T00:00:00Z',
            'ip' => '1.2.3.4',
            'form_id' => 't1',
        ];

        $input = 'F={{field.foo}} U={{field.up}} S={{submitted_at}} I={{ip}} ID={{form_id}} X={{unknown}}';
        $out = $this->expand($input, $canonical, $meta);
        $this->assertSame(
            'F=bar U=a.pdf, b.pdf S=2024-01-01T00:00:00Z I=1.2.3.4 ID=t1 X={{unknown}}',
            $out
        );
    }
}
