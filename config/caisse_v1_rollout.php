<?php

return [
    'flags' => [
        'payment_ledger_v1' => [
            'default' => false,
            'owner' => 'BE+DevOps',
            'rollback_order' => 10,
            'invariant' => 'pricing_backend_ssot',
        ],
        'pos_revenue_guards' => [
            'default' => false,
            'owner' => 'BE+DevOps',
            'rollback_order' => 20,
            'invariant' => 'backend_payment_authority',
        ],
        'kds_strict_release' => [
            'default' => false,
            'owner' => 'BE+Ops',
            'rollback_order' => 30,
            'invariant' => 'order_status_enum',
        ],
        'quote_v1' => [
            'default' => false,
            'owner' => 'BE+FE',
            'rollback_order' => 40,
            'invariant' => 'pricing_backend_ssot',
        ],
        'fiscal_z_v1' => [
            'default' => false,
            'owner' => 'BE+NF525',
            'rollback_order' => 50,
            'invariant' => 'nf525_evidence',
        ],
        'kiosk_offline_strict' => [
            'default' => false,
            'owner' => 'Kiosk+Ops',
            'rollback_order' => 60,
            'invariant' => 'offline_gate_scope',
        ],
    ],
    'canary' => [
        'phases' => [
            ['name' => 'pilot_branch', 'branch_percentage' => null, 'requires_branch_id' => true],
            ['name' => 'ten_percent', 'branch_percentage' => 10, 'requires_branch_id' => false],
            ['name' => 'fifty_percent', 'branch_percentage' => 50, 'requires_branch_id' => false],
            ['name' => 'full', 'branch_percentage' => 100, 'requires_branch_id' => false],
        ],
        'rollback_predicates' => [
            'payment_success_rate' => ['operator' => '<', 'threshold' => 95.0, 'window' => '5m'],
            'fiscal_anomaly' => ['operator' => '>', 'threshold' => 0, 'window' => 'immediate'],
            'kds_error_rate' => ['operator' => '>', 'threshold' => 5.0, 'window' => '5m'],
        ],
        'required_evidence' => [
            'm14_preflight_report',
            'pilot_branch_id',
            'metrics_snapshot',
            'rollback_owner',
        ],
    ],
];
