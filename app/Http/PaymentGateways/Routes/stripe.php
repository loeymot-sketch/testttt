<?php

use Illuminate\Support\Facades\Route;
use App\Http\PaymentGateways\Gateways\Stripe;

/*
|--------------------------------------------------------------------------
| Stripe Webhook Route — [Sprint 3A — Webhook idempotency 2026-05-16]
|--------------------------------------------------------------------------
|
| Stripe POSTs event notifications (charge.succeeded, payment_intent.*, ...)
| to this endpoint. The handler verifies the Stripe-Signature header against
| config('services.stripe.webhook_secret') and records each event in
| webhook_events via WebhookEvent::firstOrCreate to enforce single-processing
| under retry storms.
|
| CSRF: excluded via VerifyCsrfToken middleware (providers don't send tokens).
| Auth: none — signature header is the sole authentication primitive.
|
*/

Route::prefix('payment')->name('payment.')->middleware(['installed'])->group(function () {
    // [Wave 1 P1 SYNC-RED-01 2026-05-18] throttle:60,1 → mitigates LAN HMAC-CPU DoS
    // (invalid-signature spam burning CPU + filling `fiscal` log channel).
    // Stripe in production sends ~5/s burst max → 60/min is generous + safe.
    Route::post('/stripe-webhook/', [Stripe::class, 'handleWebhook'])
        ->middleware(['throttle:60,1'])
        ->name('stripe.webhook');
});
