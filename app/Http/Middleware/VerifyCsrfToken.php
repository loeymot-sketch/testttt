<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        '/payment/sslcommerz/*',
        '/payment/paytm/*',
        '/payment/cashfree/*',
        '/payment/phonepe/*',
        '/payment/iyzico/*',
        '/payment/pesapal/*',
        // [Sprint 3A — Webhook idempotency 2026-05-16]
        // Stripe + SenangPay POST these webhook endpoints from external
        // origins without CSRF tokens. Authentication is enforced via
        // provider signature (Stripe-Signature header / SenangPay HMAC).
        //
        // [T-6.3.1 — V1.0.2 SYNC-ADV4-N1 2026-05-18]
        // Both bare and wildcard variants are required: Laravel pattern
        // `payment/stripe-webhook/*` matches `payment/stripe-webhook/foo`
        // but NOT bare `payment/stripe-webhook`. The actual routes
        // (`app/Http/PaymentGateways/Routes/{stripe,senangpay}.php`)
        // register at `/{provider}-webhook` (no trailing segment).
        '/payment/stripe-webhook',
        '/payment/stripe-webhook/*',
        '/payment/senangpay-webhook',
        '/payment/senangpay-webhook/*',
    ];
}
