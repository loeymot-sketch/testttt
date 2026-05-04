<?php

namespace Tests\Feature\I18n;

use Tests\TestCase;

/**
 * Sentinel — studio namespace key parity across locales (CV1-V2-CATALOG-REWORK-001 β-PRE-S1).
 *
 * Detects missing/extra translation keys under `all.studio` without modifying lang files.
 */
class StudioKeyParityTest extends TestCase
{
    private const LOCALES = ['fr', 'en', 'de', 'ar', 'bn'];

    /**
     * Flatten nested associative arrays into dot-paths for leaf values (scalars + empty arrays).
     *
     * @return list<string>
     */
    private function dotKeys(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                if ($value === []) {
                    $out[] = $path;
                } else {
                    $out = array_merge($out, $this->dotKeys($value, $path));
                }
            } else {
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }

    public function test_studio_namespace_exists_in_all_locales(): void
    {
        foreach (self::LOCALES as $locale) {
            $path = base_path("lang/{$locale}/all.php");
            $this->assertFileExists($path, "Missing locale file: lang/{$locale}/all.php");

            /** @var array<string, mixed> $all */
            $all = require $path;
            $this->assertIsArray($all, "lang/{$locale}/all.php must return an array");
            $this->assertArrayHasKey(
                'studio',
                $all,
                "locale \"{$locale}\" missing top-level 'studio' key in lang/{$locale}/all.php"
            );
            $this->assertIsArray($all['studio'], "locale \"{$locale}\": 'studio' must be an array");
        }
    }

    public function test_studio_namespace_keys_are_identical_across_5_locales(): void
    {
        $byLocale = [];
        foreach (self::LOCALES as $locale) {
            $path = base_path("lang/{$locale}/all.php");
            /** @var array<string, mixed> $all */
            $all = require $path;
            $studio = $all['studio'] ?? [];
            $this->assertIsArray($studio);
            $keys = $this->dotKeys($studio);
            $prefixed = array_map(static fn (string $k): string => 'studio.' . $k, $keys);
            sort($prefixed);
            $byLocale[$locale] = $prefixed;
        }

        $canonical = $byLocale['fr'];
        $messages = [];

        foreach (self::LOCALES as $locale) {
            $missing = array_values(array_diff($canonical, $byLocale[$locale]));
            $extra = array_values(array_diff($byLocale[$locale], $canonical));
            if ($missing !== [] || $extra !== []) {
                $parts = [];
                if ($missing !== []) {
                    $parts[] = 'missing keys: ' . json_encode($missing);
                }
                if ($extra !== []) {
                    $parts[] = 'extra keys: ' . json_encode($extra);
                }
                $messages[] = sprintf('locale "%s" — %s', $locale, implode('; ', $parts));
            }
        }

        $this->assertSame(
            [],
            $messages,
            'assertSame() failed: studio key parity mismatch across locales. ' . implode(' | ', $messages)
        );
    }
}
