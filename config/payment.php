<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Caisse V1 Public Web Payment Scope
    |--------------------------------------------------------------------------
    |
    | Gate GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25 selected Option B: public
    | web payment is off for V1. Keep this default code-owned; enabling the
    | route flow requires a new reviewed gate and an explicit config change.
    |
    */
    'web_payment_v1' => [
        'enabled' => false,
        'gate' => 'GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25',
        'decision' => 'Option B - Web payment off V1',
        'rollback_feature_flag' => 'web_payment_v1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Caisse V1 Payment Restricted Pilot
    |--------------------------------------------------------------------------
    |
    | Option B keeps the payment ledger in a restricted pilot. The allowlist is
    | code-owned on purpose: an environment variable must not silently enable a
    | non-reviewed payment method in production.
    |
    */
    'pilot_restrict' => [
        'enabled' => true,
        'allowed_methods' => [
            'credit',
        ],
        'audit_action' => 'payment.method_restricted',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Activation Guard
    |--------------------------------------------------------------------------
    |
    | Gate GATE_STRIPE_CENTS_ACTIVE_2026-04-25 selected Option B: Stripe stays
    | inactive for production V1. This guard blocks public Stripe activation
    | paths unless a later gate explicitly clears it.
    |
    */
    'stripe' => [
        'activation_guard' => [
            'enabled' => true,
            'activation_gate_cleared' => false,
            'gate' => 'GATE_STRIPE_CENTS_ACTIVE_2026-04-25',
            'decision' => 'Option B - Stripe inactive prod V1',
        ],
    ],
];
