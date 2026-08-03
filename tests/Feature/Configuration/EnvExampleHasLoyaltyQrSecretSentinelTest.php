<?php

namespace Tests\Feature\Configuration;

use Tests\TestCase;

/**
 * @FK-ID GOAL-I2-HEAL-03 / I.8 P1 I8-E-001
 * @source Phase I.8 implicit defaults audit (2026-05-24)
 *
 * Sentinel: `.env.example` MUST advertise `LOYALTY_QR_SECRET=` (key present)
 * AND include a generation comment (`openssl rand -hex 32`) so that a
 * first-time operator who copies `.env.example` to `.env` sees the
 * production-required secret BEFORE deploying.
 *
 * Why this matters
 * ----------------
 * `AppServiceProvider::boot()` (around line 161) REFUSES to start the
 * application in production when `config('loyalty.qr.secret')` is empty
 * (LCS-S-001 guard). That fail-fast behaviour is correct, but until this
 * sentinel was added, `.env.example` did NOT mention `LOYALTY_QR_SECRET` at
 * all — so an operator following the deploy README would copy `.env.example`,
 * not see the key, deploy, and hit a `RuntimeException` at boot with no
 * documentation pointer.
 *
 * This sentinel locks the contract: any future edit that silently drops the
 * key from `.env.example` (or strips the generation instruction) trips CI.
 *
 * Mirror surfaces:
 *   - config/loyalty.php (`qr.secret` reads env `LOYALTY_QR_SECRET`)
 *   - app/Providers/AppServiceProvider.php:161 (production boot guard)
 *   - scripts/deploy/README_DEPLOY.md Section 8.5 (owner physical action)
 */
class EnvExampleHasLoyaltyQrSecretSentinelTest extends TestCase
{
    private function envExampleContents(): string
    {
        $path = base_path('.env.example');
        $this->assertFileExists($path, '.env.example missing');

        return (string) file_get_contents($path);
    }

    public function test_env_example_declares_loyalty_qr_secret_key(): void
    {
        $contents = $this->envExampleContents();

        // The key MUST be present and uncommented (operators copy this file
        // verbatim and only fill the right-hand side). A commented-out
        // `# LOYALTY_QR_SECRET=` would let the boot guard crash silently
        // without the operator ever seeing the variable in their .env.
        $this->assertMatchesRegularExpression(
            '/^LOYALTY_QR_SECRET=/m',
            $contents,
            '.env.example must declare LOYALTY_QR_SECRET= (uncommented, empty value) so operators see the production-required key. AppServiceProvider:161 boot guard refuses to start in production without it.'
        );
    }

    public function test_env_example_documents_secret_generation_command(): void
    {
        $contents = $this->envExampleContents();

        // Accept either the canonical form or any variant that hints at
        // `openssl rand -hex 32` (case-insensitive) so operators always
        // have an exact, copy-pasteable generation command. Without this
        // hint, a hurried operator might invent a weak value that the
        // min_secret_length / dev_sentinels guards in config/loyalty.php
        // would then reject — and they would not know why.
        $this->assertMatchesRegularExpression(
            '/openssl\s+rand\s+-hex\s+32/i',
            $contents,
            '.env.example must include `openssl rand -hex 32` generation instruction near LOYALTY_QR_SECRET so first-time operators can mint a compliant secret without hunting through docs.'
        );
    }
}
