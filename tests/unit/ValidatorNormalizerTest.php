<?php
declare(strict_types=1);

use EForms\Validation\Validator;

final class ValidatorNormalizerTest extends BaseTestCase
{
    public function testEmailNormalizerApplied(): void
    {
        $tpl = ['fields' => [
            ['type' => 'email', 'key' => 'e'],
        ]];
        $desc = Validator::descriptors($tpl);
        $values = Validator::normalize($tpl, ['e' => 'FOO@Example.COM'], $desc);
        $this->assertSame('FOO@example.com', $values['e']);
        $val = Validator::validate($tpl, $desc, $values);
        $this->assertSame([], $val['errors']);
        $canon = Validator::coerce($tpl, $desc, $val['values']);
        $this->assertSame('FOO@example.com', $canon['e']);
    }

    public function testMultivalueNormalizerApplied(): void
    {
        $field = [
            'type' => 'select',
            'key' => 's',
            'multiple' => true,
            'options' => [
                ['key' => '1234567890'],
                ['key' => '9876543210'],
            ],
            'handlers' => ['normalizer_id' => 'tel_us'],
        ];
        $tpl = ['fields' => [$field]];
        $desc = Validator::descriptors($tpl);
        $post = ['s' => ['(123) 456-7890', '1-987-654-3210']];
        $values = Validator::normalize($tpl, $post, $desc);
        $this->assertSame(['1234567890', '9876543210'], $values['s']);
        $val = Validator::validate($tpl, $desc, $values);
        $this->assertSame([], $val['errors']);
        $canon = Validator::coerce($tpl, $desc, $val['values']);
        $this->assertSame(['1234567890', '9876543210'], $canon['s']);
    }
}
