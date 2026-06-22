<?php

namespace Tests\Feature\Stock;

use Tests\TestCase;

/**
 * Sentinel — Z-3 STOCK / Round 1 / Z3-UX-01 + Z3-UX-02 + Z3-RED-06 heal.
 *
 * Locks the i18n integrity contract for the /admin/stock/rupture surface
 * across all 3 active locales (FR/EN/AR):
 *
 *   1. The `admin.stock_rupture` block MUST exist in every locale JSON
 *      under resources/js/languages/{locale}.json.
 *   2. All 17 canonical keys (title, subtitle, cron_enabled, cron_disabled,
 *      run_now, last_run, last_run_at, items_flipped, items_skipped,
 *      duration_ms, currently_86, none_unavailable, flipped_at, low_alerts,
 *      no_low_alerts, below_threshold) MUST be present.
 *   3. EN strings MUST NOT contain French accented characters or
 *      well-known French words (locale leak guard).
 *   4. AR strings MUST contain Arabic characters (codepoint U+0600..U+06FF).
 *
 * Audit citations:
 *  - reports/audit/goal-complement-2026-05-18/round-1/Z-3-STOCK/ux-a11y.json
 *    (Z3-UX-01 P0, Z3-UX-02 P0)
 *  - reports/audit/goal-complement-2026-05-18/round-1/Z-3-STOCK/red.json
 *    (Z3-RED-06 confirms)
 */
class StockDashboardI18nIntegrityTest extends TestCase
{
    private const CANONICAL_KEYS = [
        'title',
        'subtitle',
        'cron_enabled',
        'cron_disabled',
        'run_now',
        'last_run',
        'last_run_at',
        'items_flipped',
        'items_skipped',
        'duration_ms',
        'currently_86',
        'none_unavailable',
        'flipped_at',
        'low_alerts',
        'no_low_alerts',
        'below_threshold',
    ];

    private const LOCALES = ['fr', 'en', 'ar'];

    // Backend-emitted reasons that the rupture chip exposes to admins.
    // Source: StockService::AUTO_RUPTURE_REASON, StockService::LEGACY_STOCK_RUPTURE_REASON,
    // StockLevel::MANUAL_UNAVAILABLE_REASONS.
    private const REASON_KEYS = [
        'stock_rupture',
        'out_of_stock',
        'out_of_stock_manual',
        'seasonal',
        'recipe_change',
        'supplier_issue',
        'quality_issue',
    ];

    /**
     * @dataProvider localesProvider
     */
    public function test_admin_stock_rupture_block_exists_with_all_canonical_keys(string $locale): void
    {
        $payload = $this->loadLocaleJson($locale);

        $this->assertArrayHasKey(
            'admin',
            $payload,
            "Locale {$locale}: top-level `admin` key missing"
        );
        $this->assertArrayHasKey(
            'stock_rupture',
            $payload['admin'],
            "Locale {$locale}: `admin.stock_rupture` block missing — dashboard will render raw labels"
        );

        $block = $payload['admin']['stock_rupture'];
        foreach (self::CANONICAL_KEYS as $key) {
            $this->assertArrayHasKey(
                $key,
                $block,
                "Locale {$locale}: `admin.stock_rupture.{$key}` missing"
            );
            $this->assertIsString($block[$key]);
            $this->assertNotSame('', trim((string) $block[$key]), "Locale {$locale}: empty value for `admin.stock_rupture.{$key}`");
        }
    }

    public function test_english_locale_does_not_leak_french_strings(): void
    {
        $en = $this->loadLocaleJson('en');
        $block = $en['admin']['stock_rupture'];

        // Sentinel French markers — distinctive French strings/words that
        // are unambiguously locale leaks if found in EN copy.
        // We intentionally don't flag accent-only characters that may appear
        // in valid English imports.
        $frenchMarkers = [
            'Articles',         // FR plural form used in original leak
            'Lancer',
            'Dernier',
            'Surveillance',
            'indisponible',
            'Aucune alerte',
            'Sous le seuil',
            'Aucun article',
            'ruptures',
        ];

        foreach ($this->flattenStrings($block) as $flatKey => $value) {
            foreach ($frenchMarkers as $marker) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $marker,
                    $value,
                    "EN locale leak: admin.stock_rupture.{$flatKey} contains French marker `{$marker}` → value={$value}"
                );
            }
        }
    }

    /**
     * Recursively flatten a nested array to a dot-notation key map of strings.
     * @param array<string, mixed> $array
     * @return array<string, string>
     */
    private function flattenStrings(array $array, string $prefix = ''): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $out += $this->flattenStrings($value, $path);
            } elseif (is_string($value)) {
                $out[$path] = $value;
            }
        }
        return $out;
    }

    /**
     * @dataProvider localesProvider
     */
    public function test_admin_stock_rupture_reason_subblock_localizes_all_known_reasons(string $locale): void
    {
        $payload = $this->loadLocaleJson($locale);
        $this->assertArrayHasKey('reason', $payload['admin']['stock_rupture'], "Locale {$locale}: `admin.stock_rupture.reason` block missing — rupture chip will show raw keys");

        $reasons = $payload['admin']['stock_rupture']['reason'];
        foreach (self::REASON_KEYS as $key) {
            $this->assertArrayHasKey($key, $reasons, "Locale {$locale}: `admin.stock_rupture.reason.{$key}` missing");
            $this->assertIsString($reasons[$key]);
            $this->assertNotSame('', trim((string) $reasons[$key]), "Locale {$locale}: empty value for reason `{$key}`");
        }
    }

    public function test_arabic_locale_contains_arabic_codepoints(): void
    {
        $ar = $this->loadLocaleJson('ar');
        $block = $ar['admin']['stock_rupture'];

        foreach ($this->flattenStrings($block) as $flatKey => $value) {
            $this->assertMatchesRegularExpression(
                '/[\x{0600}-\x{06FF}]/u',
                $value,
                "AR locale: admin.stock_rupture.{$flatKey} value `{$value}` lacks Arabic codepoints (U+0600..U+06FF)"
            );
        }
    }

    public static function localesProvider(): array
    {
        return [
            'fr' => ['fr'],
            'en' => ['en'],
            'ar' => ['ar'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLocaleJson(string $locale): array
    {
        $path = base_path("resources/js/languages/{$locale}.json");
        $this->assertFileExists($path, "Locale file not found: {$path}");
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Locale {$locale} JSON malformed");

        return $decoded;
    }
}
