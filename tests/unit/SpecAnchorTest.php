<?php
declare(strict_types=1);

final class SpecAnchorTest extends BaseTestCase
{
    public function testSecurityAppendixAnchorsExist(): void
    {
        $specPath = dirname(__DIR__) . '/../docs/electronic_forms_SPEC.md';
        $this->assertFileExists($specPath, 'Spec file not found');
        $spec = file_get_contents($specPath);
        $this->assertNotFalse($spec, 'Unable to read spec file');

        foreach ([
            'sec-cookie-policy-matrix',
            'sec-cookie-lifecycle-matrix',
            'sec-cookie-ncid-summary',
        ] as $anchor) {
            $this->assertStringContainsString(
                '<a id="' . $anchor . '">',
                $spec,
                sprintf('Spec anchor "%s" missing or renamed', $anchor)
            );
        }
    }
}
