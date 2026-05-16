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
    Route::post('/stripe-webhook/', [Stripe::class, 'handleWebhook'])->name('stripe.webhook');
});
