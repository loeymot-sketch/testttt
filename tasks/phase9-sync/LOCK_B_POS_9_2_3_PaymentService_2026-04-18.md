# LOCK_B — `app/Services/PaymentService.php` — POS-9.4.BL.2 + POS-9.3.5 + POS-9.3.10

**Posé par.** Track B (POS orchestrator) sur `feat/pos-phase-9-2-3`.
**Date.** 2026-04-18 post P9.5 + Phase H merge.

## Pré-conditions vérifiées

- [x] Aucun `LOCK_A_*` actif sur `PaymentService.php`. Phase H a édité (H.2.7 tickets TVA) mais sans lock persistant.
- [x] Aucun autre `LOCK_B_*` actif.

## Fichier et scope

**Fichier.** `app/Services/PaymentService.php`.

**Modifications planifiées** :

| Vague | Méthode | Scope |
|---|---|---|
| POS-9.4.BL.2 | `cashBack` (lignes **29-50**) + toute opération tiroir | wire `AuditLogService::write()` sur mouvement tiroir + cashBack |
| POS-9.3.5 | `cashBack` | idempotent + crée ligne `OrderPayment{amount<0, refund_of_id}`, loggue `AuditLog::action=payment.cash_back_issued` |
| POS-9.3.10 | nouveau `recordPayments(Order, array $tenders)` | implémente règle overpayment cash-only (plan §3.bis) ; crée N lignes `order_payments` + évalue state via `OrderPaymentStateMachine::apply` |

## Règles de respect invariants pendant ce lock

- **AuditLog obligatoire** sur toute écriture monétaire (AFTER P9.4.BL.2).
- **Idempotency** : `recordPayments` et `cashBack` doivent être idempotents via `idempotency_key` optionnel + unique constraint `(order_id, method, recorded_at)` sur `order_payments`.
- **State machine** : `OrderPaymentStateMachine::apply` est la seule autorité de transition `payment_status`.
- **Overpayment règle** : `PosPaymentMethod::isCashLike()` détermine autorisation rendu monnaie (voir plan §3.bis).
- **Transaction DB** : toute combinaison `OrderPayment::create` + `OrderPaymentStateMachine::apply` + `AuditLog::write` se fait dans une seule transaction.

## ETA libération

**Release par.** Commit POS-9.3.10 (dernière édition sur ce fichier).
**Durée estimée.** ~4 jours (réparti sur BL + 9.3.5 + 9.3.10).
**Procédure de release.** Mettre à jour `## Status` en `RELEASED` avec SHA du commit 9.3.10.

## Status

**ACTIVE** depuis 2026-04-18 création branche `feat/pos-phase-9-2-3`.

**PARTIAL RELEASE 2026-04-20** — édition POS-9.4.BL livrée via SHA `2d4d2c846` / `a7036f6ec` / `c3c0593e6` (cf. `reports/audit-orchestration/REPORT_TASK19_LOCKS_FROZEN_ZONES_2026-04-20.md`). Le lock reste **ACTIVE** (zone frozen toujours sous garde ; traçabilité d’une livraison autorisée passée).
