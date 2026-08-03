<?php

namespace Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;

/**
 * [P0-POS-01 GOAL round-2 2026-05-18] Regression sentinel for Stripe charge
 * metadata.order_id injection.
 *
 * BUG (pre-2026-05-18): `Stripe::payment()` called `charges->create([...])`
 *       without a `metadata` key. The webhook handler `Stripe::handleWebhook`
 *       at line 273-275 reads `$charge->metadata->order_id` to correlate the
 *       inbound webhook event back to the originating order — without
 *       metadata, that lookup always returns null and the
 *       `CapturePaymentNotification` row is never written.
 *
 *       Effect: live charge succeeds, but any out-of-band webhook receipt
 *       (storm retries, async capture, DLQ replay) loses the order linkage
 *       → order silently stays PENDING → payment ghosting in production.
 *
 * FIX:  `charges->create()` payload now includes
 *       `'metadata' => ['order_id' => (string) $order->id]`. Stripe coerces
 *       all metadata values to strings, so the cast is explicit.
 *
 * This sentinel guarantees the call site at Stripe.php:57-67 stays correct
 * by regex-matching the source. Pattern proven in StripeCentsCastTest
 * (P0-6 CTO audit 2026-05-16).
 */
class StripeChargeMetadataTest extends TestCase
{
    private string $stripePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripePath = __DIR__ . '/../../../app/Http/PaymentGateways/Gateways/Stripe.php';
    }

    public function test_stripe_payment_call_includes_metadata_order_id(): void
    {
        $this->assertFileExists($this->stripePath, 'Stripe.php must exist at the audited path');

        $code = file_get_contents($this->stripePath);

        // The metadata array must contain order_id pulled from $order->id.
        // Matches: 'metadata' => [ ... 'order_id' => (string) $order->id ... ]
        // Allows whitespace/newlines because the payload spans multiple lines.
        $this->assertMatchesRegularExpression(
            "/'metadata'\s*=>\s*\[[^\]]*'order_id'\s*=>\s*\(string\)\s*\\\$order->id[^\]]*\]/s",
            $code,
            'Stripe.php charges->create() MUST include metadata.order_id (P0-POS-01 sentinel). '
            . 'Webhook handler at handleWebhook() reads $charge->metadata->order_id to '
            . 'correlate inbound webhook events back to the originating order — without '
            . 'this, async/retry webhooks orphan the charge and the order stays PENDING.'
        );
    }

    public function test_stripe_payment_metadata_is_inside_charges_create_payload(): void
    {
        $code = file_get_contents($this->stripePath);

        // Find the charges->create([...]) call and assert metadata key is
        // inside it (not elsewhere in the file).
        $this->assertSame(
            1,
            preg_match(
                '/\$this->gateway->charges->create\(\s*\[(.+?)\]\s*\)/s',
                $code,
                $matches
            ),
            'Stripe.php must contain exactly one charges->create([...]) call'
        );

        $payload = $matches[1];
        $this->assertStringContainsString(
            "'metadata'",
            $payload,
            'metadata key must be inside the charges->create([...]) payload, not elsewhere'
        );
        $this->assertMatchesRegularExpression(
            "/'order_id'\s*=>\s*\(string\)\s*\\\$order->id/",
            $payload,
            'metadata.order_id must be set to (string) $order->id inside the charges->create payload'
        );
    }

    public function test_webhook_handler_still_reads_metadata_order_id(): void
    {
        // Twin guard: if a future engineer removes the webhook-side read,
        // the metadata sentinel above becomes a dead invariant. Assert the
        // webhook handler at handleWebhook() still consumes the same key.
        $code = file_get_contents($this->stripePath);

        $this->assertMatchesRegularExpression(
            '/\$charge->metadata->order_id/',
            $code,
            'Stripe::handleWebhook MUST continue reading $charge->metadata->order_id. '
            . 'If this assertion fails the webhook-side consumer was removed and the '
            . 'metadata injection at Stripe::payment becomes dead code (P0-POS-01).'
        );
    }
}
