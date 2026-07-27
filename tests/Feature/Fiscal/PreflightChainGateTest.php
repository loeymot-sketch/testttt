<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [REMEDIATION-3-POINTS 2026-07-15 / A3.2] Le gate go-live `app:preflight-production`
 * doit RÉELLEMENT re-vérifier la chaîne NF525 — pas seulement la longueur du secret.
 *
 * Défaut réel (staging 2026-07-15) : un secret FORT mais FAUX (rotation/reseed non
 * réconcilié) passait le preflight alors que `fiscal:verify-chain` renvoyait TAMPER.
 * Si une DB staging contaminée était promue en prod, le preflight l'aurait laissée
 * partir. Deux couches de verrou :
 *   1) la logique load-bearing (`AuditLogService::verifyChain`) détecte le mauvais secret ;
 *   2) le command `app:preflight-production` la câble bien (surface le TAMPER / la sentinelle).
 */
class PreflightChainGateTest extends TestCase
{
    use RefreshDatabase;

    /** Écrit 3 rows d'audit chaînés sous $secret (via le service frozen). */
    private function writeRows(string $secret): void
    {
        Config::set('fiscal.audit_secret', $secret);
        $svc = app(AuditLogService::class);
        $svc->write(['branch_id' => 1, 'action' => 'test.alpha', 'payload' => ['n' => 1]]);
        $svc->write(['branch_id' => 1, 'action' => 'test.beta', 'payload' => ['n' => 2]]);
        $svc->write(['branch_id' => 1, 'action' => 'test.gamma', 'payload' => ['n' => 3]]);
    }

    // ── Couche 1 : la logique que le gate appelle ────────────────────────────

    public function test_verifychain_intact_with_signing_secret_but_tampered_with_wrong_secret(): void
    {
        $secret = str_repeat('a', 40);
        $this->writeRows($secret);

        // Bonne clé → chaîne intègre (null).
        Config::set('fiscal.audit_secret', $secret);
        $this->assertNull(app(AuditLogService::class)->verifyChain(1));

        // Clé DIFFÉRENTE mais FORTE (40 ≥ 32) : passe la longueur, échoue le HMAC
        // → le gale doit voir un id tamperé (le 1er row).
        Config::set('fiscal.audit_secret', str_repeat('b', 40));
        $tampered = app(AuditLogService::class)->verifyChain(1);
        $this->assertNotNull($tampered, 'un secret fort-mais-faux DOIT être détecté comme tamper');
    }

    // ── Couche 2 : le command câble bien la vérif + rejette la sentinelle ─────

    public function test_preflight_flags_a_strong_but_wrong_secret_as_chain_tamper(): void
    {
        $this->writeRows(str_repeat('a', 40));
        Config::set('fiscal.audit_secret', str_repeat('b', 40)); // fort mais faux

        $this->artisan('app:preflight-production')
            ->expectsOutputToContain('TAMPER at id=');
    }

    public function test_preflight_passes_chain_check_with_the_signing_secret(): void
    {
        $secret = str_repeat('a', 40);
        $this->writeRows($secret);
        Config::set('fiscal.audit_secret', $secret);

        $this->artisan('app:preflight-production')
            ->expectsOutputToContain('chain intact');
    }

    public function test_preflight_rejects_a_long_dev_sentinel_secret(): void
    {
        // 'dev-fiscal-audit-secret-do-not-use-in-prod' = 42 chars (≥32) mais sentinelle
        // interdite : la garde doit la refuser, pas seulement contrôler la longueur.
        Config::set('fiscal.audit_secret', 'dev-fiscal-audit-secret-do-not-use-in-prod');

        $this->artisan('app:preflight-production')
            ->expectsOutputToContain('dev sentinel');
    }
}
