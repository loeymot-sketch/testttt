<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [F-3 SYNC P1 V1.0.1 quick win — 2026-05-19]
 *
 * Sentinel locking the Stripe webhook signature verification to pass an
 * explicit tolerance window (300s = Stripe SDK default for replay
 * protection). Without an explicit tolerance, the Webhook::constructEvent
 * call silently accepts arbitrarily-old payloads as long as the signature
 * is valid, extending the replay-attack window to the WebhookEvent
 * retention horizon (180 days).
 *
 * Source audit: reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md
 *               §P1 — Hardening → "Stripe replay tolerance"
 *
 * Mode: source-pin. Stripe SDK signature for constructEvent is
 *   (string $payload, string $sigHeader, string $secret, int $tolerance = null)
 * → we assert the 4th positional argument is set, materialising the V1
 *   replay-protection contract at PHPUnit time.
 */
class StripeWebhookReplayToleranceSentinelTest extends TestCase
{
    public function test_stripe_webhook_construct_event_passes_explicit_replay_tolerance(): void
    {
        $source = file_get_contents(
            base_path('app/Http/PaymentGateways/Gateways/Stripe.php')
        );
        $this->assertNotFalse($source, 'app/Http/PaymentGateways/Gateways/Stripe.php missing');

        // Match the Webhook::constructEvent call site in handleWebhook().
        // Pattern is namespace-agnostic to handle StripeClient\Webhook OR \Stripe\Webhook.
        $count = preg_match(
            '/Webhook::constructEvent\(\s*\$payload\s*,\s*\$sigHeader\s*,\s*\$secret\s*,\s*(\d+)\s*\)/',
            $source,
            $m
        );

        $this->assertSame(
            1,
            $count,
            'Webhook::constructEvent in Stripe::handleWebhook MUST pass an explicit 4th positional '
                . "argument for replay tolerance (in seconds). See "
                . 'reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1 "Stripe replay tolerance".'
        );

        $tolerance = (int) $m[1];
        $this->assertGreaterThanOrEqual(
            60,
            $tolerance,
            'Replay tolerance MUST be at least 60s to absorb modest clock skew.'
        );
        $this->assertLessThanOrEqual(
            600,
            $tolerance,
            'Replay tolerance MUST NOT exceed 600s — beyond that, replay protection is materially weakened.'
        );
    }
}
