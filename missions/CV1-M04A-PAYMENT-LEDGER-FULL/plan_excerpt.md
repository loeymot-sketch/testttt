# Plan Excerpt — CV1-M04A-PAYMENT-LEDGER-FULL

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § M-04A

M-04A — `CAISSE_V1_PAYMENT_LEDGER_FULL_2026-04-25` (**GATE** : `GATE_PAYMENT_LEDGER_V1` = **Option A** + `GATE_SCHEMA` + frozen zones)

But: ledger complet `pending|authorized|captured|refunded|voided|failed`, idempotence callbacks, audit immuable, correctif cents Stripe si actif.

Allowlist (exécution seulement après déblocage humain + briefs signés) : migrations `payment_ledger` / `payment_transactions`, modèles `PaymentLedger` / `PaymentTransaction`, `PaymentLedgerService`, `PaymentStateMachine`, `PaymentService` (frozen), `OrderController` `paymentConfirm` (frozen), tests `PaymentLedger*`, `StripeCentsConversionTest`. `PaymentConfirmAbilitySentinelTest` doit passer vert après mission.

SYMMETRY: si `PaymentService` ou `paymentConfirm` touche OS/FOS → revue symétrie obligatoire.

Rollback: flag `payment_ledger_v1=off` ; runbook `docs/runbooks/PAYMENT_LEDGER_ROLLBACK.md`.

**Statut file d’attente** : `plans/masterplay/MASTERPLAY_QUEUE.md` indique **BLOCKED** tant que l’humain n’a pas choisi **Option A** (full ledger) et signé les gates requis. Ne pas lancer `codex:complex` tant que `input.json` → `execution_blocked` n’est pas retiré après preuve humaine.
