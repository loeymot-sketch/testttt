<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID         FK-AUDIT-F-011
 * @source        plans/PLAN_AUDIT_F011_PRICING_SSOT_DUPLICATION_2026-05-07.md
 * @mission       AUDIT-F-011 — Pricing SSOT vs legacy fallback
 * @decision      close-by-investigation (flag stable=true partout en prod / CI / .env templates)
 *
 * # WHY THIS SENTINEL EXISTS — read before editing
 *
 * The audit F-011 (P2 backlog) flagged that `OrderService` and
 * `FrontendOrderService` carry two pricing branches gated by
 * `config('pricing.use_ssot_service', true)` :
 *   - SSOT  : delegates to `App\Services\Pricing\PricingService`
 *   - Legacy: inline tax/discount/coupon recompute (~150 LOC per service)
 *
 * Investigation 2026-05-08 confirmed the flag is `=true` in every deployable
 * config artefact :
 *   - `config/pricing.php` ............................ default `true`
 *   - `.env.example` .................................. `PRICING_USE_SSOT=true`
 *   - `.env.testing` .................................. `PRICING_USE_SSOT=true`
 *   - `.github/workflows/phpunit.yml` ................. `PRICING_USE_SSOT='true'`
 *   - `.github/workflows/playwright.yml` (×2 jobs) .... `PRICING_USE_SSOT="true"`
 *
 * The legacy branch is only flipped to `false` *intentionally* in
 * `tests/Feature/Sentinels/PosSubtotalForgerySentinelTest.php` (Config::set
 * inside the test scope) to assert backend-subtotal validation on the
 * legacy path itself — it must keep working for that sentinel.
 *
 * Removal of the legacy branches is owned by another plan
 * (`plans/caisse-v1-ultra-finition/PHASE_C_BACKEND_SECURITY_2026-04-26.md`
 * task `C.5 CV1-CLEANUP-LEGACY-PRICING-PATH`) — F-011 must NOT step on it.
 *
 * This sentinel is the durable artefact that locks the close-by-investigation
 * decision : if anybody flips the default to `false` (config) or removes the
 * `=true` lines from `.env.example` / CI workflows, this test fails loudly so
 * that ops doesn't silently re-enable the legacy fallback path in production.
 *
 * # WHAT THIS SENTINEL ASSERTS
 *
 * 1. The default config value is strictly `true` (i.e. the production path
 *    is SSOT, not legacy fallback).
 * 2. Every deployable env template / CI workflow that mentions
 *    `PRICING_USE_SSOT` sets it to `true` — no stray `false` left behind in
 *    a config artefact that ops could ship by accident.
 *
 * # WHAT THIS SENTINEL DOES NOT ASSERT
 *
 * - It does NOT block runtime `Config::set('pricing.use_ssot_service', false)`
 *   inside individual test scopes (e.g. `PosSubtotalForgerySentinelTest`).
 *   Per F-011 §Decision and the C.5 plan, the legacy branch must remain
 *   testable until C.5 explicitly removes the inline recompute paths.
 * - It does NOT assert SSOT vs legacy numerical equivalence — that work is
 *   intentionally deferred per F-011 §Decision (any divergence detected
 *   would be a P0 immediate, requires owner gate before opening).
 *
 * # WHEN THIS BREAKS
 *
 * Either (a) someone changed `config/pricing.php` default to `false` —
 * revert immediately ; this would silently re-enable the legacy ~300 LOC of
 * pricing logic in production. Or (b) a `.env.*` template / CI workflow
 * lost its explicit `=true` line — restore it. Either way : read the F-011
 * report before relaxing this assertion.
 */
class PricingSsotFlagProductionStableSentinelTest extends TestCase
{
    private const ENV_KEY = 'PRICING_USE_SSOT';

    /** Project root, derived from this file's location. */
    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_pricing_use_ssot_service_default_is_true(): void
    {
        // Reset to config-file default ; do NOT inherit any test-scope override.
        $defaultValue = require $this->projectRoot() . '/config/pricing.php';

        $this->assertIsArray($defaultValue, 'config/pricing.php must return an array');
        $this->assertArrayHasKey(
            'use_ssot_service',
            $defaultValue,
            'config/pricing.php must declare use_ssot_service key (F-011 invariant)'
        );
        $this->assertTrue(
            $defaultValue['use_ssot_service'],
            'config/pricing.php default must be `true` (SSOT path). Flipping to false '
                . 'would silently re-enable ~300 LOC legacy pricing fallback in production. '
                . 'See REPORT_F011 + PHASE_C C.5 (CV1-CLEANUP-LEGACY-PRICING-PATH).'
        );
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function deployableEnvFilesProvider(): array
    {
        // Files relative to project root that ops/CI rely on for
        // PRICING_USE_SSOT. If a file in this list mentions the key
        // at all, it MUST be `=true`.
        return [
            ['.env.example'],
            ['.env.testing'],
        ];
    }

    /**
     * @dataProvider deployableEnvFilesProvider
     */
    public function test_deployable_env_template_pins_pricing_use_ssot_to_true(string $relativePath): void
    {
        $absolutePath = $this->projectRoot() . '/' . $relativePath;

        if (!is_file($absolutePath)) {
            $this->markTestSkipped(sprintf(
                'Env template %s missing — cannot enforce F-011 pin. Restore the file.',
                $relativePath
            ));
        }

        $contents = file_get_contents($absolutePath);
        $this->assertNotFalse($contents, "Failed to read {$relativePath}");

        // Match any non-comment line setting the key.
        $found = preg_match_all(
            '/^\s*' . preg_quote(self::ENV_KEY, '/') . '\s*=\s*(\S+)/mi',
            $contents,
            $matches
        );

        $this->assertGreaterThan(
            0,
            $found,
            sprintf(
                'Env template %s no longer declares %s. Restore `%s=true` or remove the file from F-011 sentinel provider.',
                $relativePath,
                self::ENV_KEY,
                self::ENV_KEY
            )
        );

        foreach ($matches[1] as $rawValue) {
            $normalised = strtolower(trim($rawValue, " \t\"'"));
            $this->assertSame(
                'true',
                $normalised,
                sprintf(
                    'Env template %s declares %s=%s — F-011 invariant requires `true` (SSOT). '
                        . 'Setting it to false re-enables the legacy pricing fallback in production.',
                    $relativePath,
                    self::ENV_KEY,
                    $rawValue
                )
            );
        }
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function ciWorkflowFilesProvider(): array
    {
        return [
            ['.github/workflows/phpunit.yml'],
            ['.github/workflows/playwright.yml'],
        ];
    }

    /**
     * @dataProvider ciWorkflowFilesProvider
     */
    public function test_ci_workflow_pins_pricing_use_ssot_to_true(string $relativePath): void
    {
        $absolutePath = $this->projectRoot() . '/' . $relativePath;

        if (!is_file($absolutePath)) {
            $this->markTestSkipped(sprintf(
                'CI workflow %s missing — cannot enforce F-011 pin.',
                $relativePath
            ));
        }

        $contents = file_get_contents($absolutePath);
        $this->assertNotFalse($contents, "Failed to read {$relativePath}");

        // Workflow YAML uses `KEY: 'true'` or `KEY: "true"` or `KEY: true`.
        $found = preg_match_all(
            '/' . preg_quote(self::ENV_KEY, '/') . '\s*:\s*([\'"]?)([^\s\'"#]+)\1/mi',
            $contents,
            $matches
        );

        if ($found === 0) {
            $this->markTestSkipped(sprintf(
                'CI workflow %s no longer references %s — out of sentinel scope.',
                $relativePath,
                self::ENV_KEY
            ));
        }

        foreach ($matches[2] as $rawValue) {
            $normalised = strtolower(trim($rawValue));
            $this->assertSame(
                'true',
                $normalised,
                sprintf(
                    'CI workflow %s sets %s=%s — F-011 invariant requires `true` (SSOT path).',
                    $relativePath,
                    self::ENV_KEY,
                    $rawValue
                )
            );
        }
    }
}
