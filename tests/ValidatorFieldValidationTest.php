<?php
use PHPUnit\Framework\TestCase;
use EForms\Validator;

final class ValidatorFieldValidationTest extends TestCase
{
    private function validate(array $field, $value): array
    {
        $tpl = ['fields' => [$field]];
        $desc = Validator::descriptors($tpl);
        $values = Validator::normalize($tpl, [$field['key'] => $value]);
        $res = Validator::validate($tpl, $desc, $values);
        return $res['errors'];
    }

    public function testUrlSchemeValidation(): void
    {
        $errors = $this->validate(['type' => 'url', 'key' => 'u'], 'ftp://example.com');
        $this->assertArrayHasKey('u', $errors);
    }

    public function testNumberRange(): void
    {
        $errors = $this->validate(['type' => 'number', 'key' => 'n', 'min' => 5, 'max' => 10], '3');
        $this->assertArrayHasKey('n', $errors);
    }

    public function testPatternEnforced(): void
    {
        $errors = $this->validate(['type' => 'text', 'key' => 't', 'pattern' => '[A-Z]+'], 'abc');
        $this->assertArrayHasKey('t', $errors);
    }

    public function testValidatorCallableExecutes(): void
    {
        $called = false;
        $field = [
            'type' => 'text',
            'key' => 't',
            'handlers' => [
                'validator' => function ($v, $f, &$errors) use (&$called) {
                    $called = true;
                    return $v;
                },
                'normalizer' => [Validator::class, 'identity'],
            ],
        ];
        $tpl = ['fields' => [$field]];
        $desc = ['t' => $field];
        Validator::validate($tpl, $desc, ['t' => 'foo']);
        $this->assertTrue($called);
    }
}
