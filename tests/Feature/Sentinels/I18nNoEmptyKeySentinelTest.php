<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [F-7 I18N P1 lock-in — 2026-05-19]
 *
 * Sentinel pinning the absence of empty-string keys in the active Vue
 * locales (fr/en/ar). Three empty-string-key bugs were healed in commit
 * 86656f1d1 (chore(i18n-cleanup): empty-trailing-dot-keys-phase3) but
 * nothing prevents regression — a fresh `"":"..."` entry in any nested
 * namespace would silently re-introduce trailing-dot flat keys like
 * `menu.`, `label.`, `kiosk.filters.` that collide with their parent
 * namespace and risk leaking raw `{label.}` strings to the UI.
 *
 * Source audit: reports/audit/foundation-2026-05-18/round-1/F-7-I18N/STATUS.md
 *               §3 D.1 "Empty-string-key structural bugs (P1)"
 * Heal commit:  86656f1d1 (3 keys fixed in fr.json lines 287/656/1459)
 *
 * Mode: JSON-decoded recursive walk. Cheap, deterministic. Adds a CI gate
 * that catches the regression at PHPUnit time.
 *
 * Scope intentionally narrow: only fr/en/ar — bn/de are out-of-V1
 * (F-7 audit §2 + §D.5) and may carry legacy issues until V1.0.x activation.
 */
class I18nNoEmptyKeySentinelTest extends TestCase
{
    public function test_french_locale_has_no_empty_string_keys(): void
    {
        $this->assertLocaleHasNoEmptyKey('fr');
    }

    public function test_english_locale_has_no_empty_string_keys(): void
    {
        $this->assertLocaleHasNoEmptyKey('en');
    }

    public function test_arabic_locale_has_no_empty_string_keys(): void
    {
        $this->assertLocaleHasNoEmptyKey('ar');
    }

    private function assertLocaleHasNoEmptyKey(string $locale): void
    {
        $path = base_path("resources/js/languages/{$locale}.json");
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "{$locale}.json missing");

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $offenders = [];
        $this->walkForEmptyKeys($data, '', $offenders);

        $this->assertSame(
            [],
            $offenders,
            "{$locale}.json carries empty-string keys, producing trailing-dot flat keys "
                . "that collide with parent namespaces. Heal commit 86656f1d1 fixed three "
                . "occurrences in fr.json (lines 287/656/1459) — a regression has slipped through. "
                . "See reports/audit/foundation-2026-05-18/round-1/F-7-I18N/STATUS.md §3 D.1. "
                . 'Offenders: ' . json_encode($offenders, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Recursive walker that records the parent-path for every "":<value> entry.
     */
    private function walkForEmptyKeys(mixed $node, string $parentPath, array &$offenders): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === '') {
                $offenders[] = [
                    'parent_namespace' => $parentPath !== '' ? $parentPath : '<root>',
                    'value' => is_scalar($value) ? (string) $value : '<non-scalar>',
                ];
            }

            if (is_array($value)) {
                $nextPath = $parentPath === '' ? (string) $key : $parentPath . '.' . $key;
                $this->walkForEmptyKeys($value, $nextPath, $offenders);
            }
        }
    }
}
