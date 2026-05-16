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
        '/payment/stripe-webhook/*',
        '/payment/senangpay-webhook/*',
    ];
}
