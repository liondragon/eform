<?php
use PHPUnit\Framework\TestCase;
use EForms\Validator;

class RulesTest extends TestCase
{
    private function run(array $tpl, array $post): array
    {
        $desc = Validator::descriptors($tpl);
        $values = Validator::normalize($tpl, $post);
        $res = Validator::validate($tpl, $desc, $values);
        return $res['errors'];
    }

    public function testRequiredIf(): void
    {
        $tpl = [
            'fields' => [
                ['type'=>'text','key'=>'a'],
                ['type'=>'text','key'=>'b'],
            ],
            'rules' => [
                ['rule'=>'required_if','field'=>'a','other'=>'b','equals'=>'x']
            ],
        ];
        $errors = $this->run($tpl, ['b'=>'x']);
        $this->assertArrayHasKey('a', $errors);
    }

    public function testRequiredIfAny(): void
    {
        $tpl = [
            'fields' => [
                ['type'=>'text','key'=>'a'],
                ['type'=>'text','key'=>'b'],
                ['type'=>'text','key'=>'c'],
            ],
            'rules' => [
                ['rule'=>'required_if_any','field'=>'a','fields'=>['b','c'],'equals_any'=>['yes','y']]
            ],
        ];
        $errors = $this->run($tpl, ['b'=>'y']);
        $this->assertArrayHasKey('a', $errors);
    }

    public function testRequiredUnless(): void
    {
        $tpl = [
            'fields' => [
                ['type'=>'text','key'=>'a'],
                ['type'=>'text','key'=>'b'],
            ],
            'rules' => [
                ['rule'=>'required_unless','field'=>'a','other'=>'b','equals'=>'skip']
            ],
        ];
        $errors = $this->run($tpl, ['b'=>'no']);
        $this->assertArrayHasKey('a', $errors);
    }

    public function testMatches(): void
    {
        $tpl = [
            'fields' => [
                ['type'=>'text','key'=>'a'],
                ['type'=>'text','key'=>'b'],
            ],
            'rules' => [
                ['rule'=>'matches','field'=>'a','other'=>'b']
            ],
        ];
        $errors = $this->run($tpl, ['a'=>'one','b'=>'two']);
        $this->assertArrayHasKey('a', $errors);
    }

    public function testOneOf(): void
    {
        $tpl = [
            'fields' => [
                ['type'=>'text','key'=>'a'],
                ['type'=>'text','key'=>'b'],
                ['type'=>'text','key'=>'c'],
            ],
            'rules' => [
                ['rule'=>'one_of','fields'=>['a','b','c']]
            ],
        ];
        $errors = $this->run($tpl, []);
        $this->assertArrayHasKey('a', $errors);
        $this->assertArrayHasKey('b', $errors);
        $this->assertArrayHasKey('c', $errors);
    }

    public function testMutuallyExclusive(): void
    {
        $tpl = [
            'fields' => [
                ['type'=>'text','key'=>'a'],
                ['type'=>'text','key'=>'b'],
            ],
            'rules' => [
                ['rule'=>'mutually_exclusive','fields'=>['a','b']]
            ],
        ];
        $errors = $this->run($tpl, ['a'=>'one','b'=>'two']);
        $this->assertArrayHasKey('a', $errors);
        $this->assertArrayHasKey('b', $errors);
    }
}
