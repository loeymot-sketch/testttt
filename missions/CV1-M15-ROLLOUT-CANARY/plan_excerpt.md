# Plan Excerpt — CV1-M15-ROLLOUT-CANARY

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`

M-15 — `CAISSE_V1_ROLLOUT_CANARY_2026-04-25` (NO-GATE after M-04+M-08)

Flags: `payment_ledger_v1`, `pos_revenue_guards`, `kds_strict_release`, `quote_v1`, `fiscal_z_v1`, `kiosk_offline_strict`.

Canary: 1 pilot branch -> 10% -> 50% -> 100%.

Rollback predicates: `payment_success_rate < 95% / 5min`; `fiscal_anomaly > 0`; `kds_error_rate > 5%`.
