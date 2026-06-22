<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID F-007 — Kiosk Lock Branch Fallback (Option B refinée)
 * @source plans/PLAN_AUDIT_F007_KIOSK_LOCK_BRANCH_FALLBACK_2026-05-07.md
 * @sprint S3 P1
 *
 * Décision orchestrateur 2026-05-08: route /api/frontend/order is dual-purpose
 * (kiosk + web/mobile users). Plan littéral hard-fail 403 si KioskMachine absent
 * casserait web/mobile users régression P0. Option (b) refinée appliquée:
 *   1. Préférer KioskMachine.branch_id (kiosk flow)
 *   2. Sinon Auth user.branch_id (web/mobile users)
 *   3. Si toujours 0 → HttpException 422 (ferme leak idempotency cross-branch)
 *
 * Sentinel structural verrouille les 2 invariants:
 *   - lock namespace utilise le branch_id résolu (pas 0)
 *   - HttpException 422 si pas de branch context (au lieu de leak silent à 0)
 */
class F007KioskLockBranchFallbackSentinelTest extends TestCase
{
    public function test_lock_branch_fallback_resolves_kiosk_machine_first(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        // Verrou: KioskMachine query lookup pour user actuel (kiosk flow priority)
        $this->assertMatchesRegularExpression(
            "/KioskMachine::where\(\s*'user_id'\s*,\s*Auth::id\(\)\s*\)->first\(\)/",
            $source,
            'F-007: lockBranchId must first try KioskMachine for the auth user.'
        );

        // Verrou: chaîne de fallback explicite KioskMachine → user.branch_id
        $this->assertMatchesRegularExpression(
            '/\$kioskMachine\?->branch_id\s*\?\?\s*Auth::user\(\)\?->branch_id\s*\?\?\s*0/',
            $source,
            'F-007: fallback chain must be kiosk → user → 0 (last guarded by 422).'
        );
    }

    public function test_lock_branch_id_zero_throws_HttpException_422(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        // Verrou critique: si lockBranchId <= 0, throw HttpException 422
        // Sans ce check, idempotency keys cross-branch collisionnent silencieusement (leak)
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$lockBranchId\s*<=\s*0\s*\)[\s\S]{0,300}HttpException[\s\S]{0,100}422/',
            $source,
            'F-007: branch_id <= 0 must throw HttpException 422 (anti-leak idempotency).'
        );

        // Verrou message explicite
        $this->assertStringContainsString(
            'no resolvable branch context',
            $source,
            'F-007: HttpException message must explicitly mention branch context resolution failure.'
        );
    }

    public function test_orchestrateur_decision_documented_in_comment(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        // Verrou: la décision orchestrateur (option B refinée) doit rester documentée
        // pour que future agent comprenne pourquoi pas hard-fail 403 du plan littéral
        $this->assertStringContainsString(
            'AUDIT-F-007',
            $source,
            'F-007: comment must reference AUDIT-F-007 and orchestrateur decision.'
        );
        $this->assertStringContainsString(
            'dual-purpose',
            $source,
            'F-007: comment must explain the dual-purpose route rationale.'
        );
    }
}
