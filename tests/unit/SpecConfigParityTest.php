<?php
declare(strict_types=1);

use EForms\Config;

final class SpecConfigParityTest extends BaseTestCase
{
    public function testSpecKeysAreSubsetOfDefaultsAndConstraintsMatch(): void
    {
        $specPath = dirname(__DIR__) . '/../docs/electronic_forms_SPEC.md';
        $this->assertFileExists($specPath, 'Spec file not found');
        $spec = file_get_contents($specPath);
        $this->assertNotFalse($spec, 'Unable to read spec file');

        $tableMeta = $this->parseNormativeConstraintsTable($spec);
        $this->assertNotEmpty($tableMeta, 'Failed to parse normative constraints table');

        $defaults = Config::DEFAULTS;

        foreach ($tableMeta as $key => $meta) {
            $this->assertTrue(
                $this->arrayPathExists($defaults, $key),
                sprintf('Spec documents key "%s" but it does not exist in Config::DEFAULTS', $key)
            );
        }

        foreach (Config::RANGE_CLAMPS as $key => $range) {
            $this->assertArrayHasKey(
                $key,
                $tableMeta,
                sprintf('Range clamp for "%s" missing from spec table', $key)
            );
            $specRange = $tableMeta[$key]['range'] ?? null;
            $this->assertNotNull($specRange, sprintf('Spec does not list a range for "%s"', $key));
            $expectedMin = $range['type'] === 'float' ? (float) $range['min'] : (int) $range['min'];
            $expectedMax = $range['type'] === 'float' ? (float) $range['max'] : (int) $range['max'];
            $specMin = $range['type'] === 'float' ? (float) $specRange['min'] : (int) $specRange['min'];
            $specMax = $range['type'] === 'float' ? (float) $specRange['max'] : (int) $specRange['max'];
            $this->assertSame(
                $expectedMin,
                $specMin,
                sprintf('Spec min clamp for "%s" does not match code (%s vs %s)', $key, $expectedMin, $specMin)
            );
            $this->assertSame(
                $expectedMax,
                $specMax,
                sprintf('Spec max clamp for "%s" does not match code (%s vs %s)', $key, $expectedMax, $specMax)
            );
        }

        foreach (Config::ENUMS as $key => $allowedValues) {
            $this->assertArrayHasKey(
                $key,
                $tableMeta,
                sprintf('Enum for "%s" missing from spec table', $key)
            );
            $specEnum = $tableMeta[$key]['enum'] ?? null;
            $this->assertIsArray($specEnum, sprintf('Spec does not list enum values for "%s"', $key));
            $expected = $allowedValues;
            $actual = $specEnum;
            sort($expected);
            sort($actual);
            $this->assertSame(
                $expected,
                $actual,
                sprintf('Spec enum for "%s" differs from code', $key)
            );
        }
    }

    /**
     * @param array<string,mixed> $array
     */
    private function arrayPathExists(array $array, string $path): bool
    {
        $segments = explode('.', $path);
        $cursor = $array;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }
        return true;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function parseNormativeConstraintsTable(string $spec): array
    {
        $lines = explode("\n", $spec);
        $inTable = false;
        $tableLines = [];
        foreach ($lines as $line) {
            if (str_contains($line, '#### 17.2')) {
                $inTable = true;
                continue;
            }
            if ($inTable && str_contains($line, '#### 17.3')) {
                break;
            }
            if ($inTable && str_starts_with(trim($line), '|')) {
                $tableLines[] = $line;
            }
        }

        $meta = [];
        foreach ($tableLines as $line) {
            $cells = array_map('trim', explode('|', trim($line)));
            if (count($cells) < 5) {
                continue;
            }
            if ($this->isSeparatorRow($cells)) {
                continue;
            }
            if (!preg_match('/`([^`]+)`/', $cells[2], $match)) {
                continue;
            }
            $key = $match[1];
            $constraints = $cells[4];
            $entry = [
                'domain' => $cells[1],
                'type' => $cells[3],
                'constraints' => $constraints,
            ];
            if (preg_match('/clamp\s+([0-9_\.]+)[\x{2013}-]([0-9_\.]+)/u', $constraints, $rangeMatch)) {
                $entry['range'] = [
                    'min' => $this->normalizeNumber($rangeMatch[1]),
                    'max' => $this->normalizeNumber($rangeMatch[2]),
                ];
            }
            if (preg_match('/\{([^}]+)\}/', $constraints, $enumMatch)) {
                $entry['enum'] = $this->parseEnumList($enumMatch[1]);
            }
            $meta[$key] = $entry;
        }

        return $meta;
    }

    /**
     * @param array<int,string> $cells
     */
    private function isSeparatorRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            $clean = str_replace(['-', ':'], '', $cell);
            if ($clean !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int,string>
     */
    private function parseEnumList(string $inner): array
    {
        if (preg_match_all('/`([^`]+)`/', $inner, $matches)) {
            return $matches[1];
        }
        $parts = preg_split('/[,|]/', $inner) ?: [];
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }

    /**
     * @return int|float
     */
    private function normalizeNumber(string $raw)
    {
        $normalized = str_replace('_', '', $raw);
        return str_contains($normalized, '.') ? (float) $normalized : (int) $normalized;
    }
}
