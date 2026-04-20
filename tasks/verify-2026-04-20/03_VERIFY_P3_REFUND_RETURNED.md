# VERIFY-03 — P3 Refund / RETURNED (motif, cashback, audit NF525)

**Date :** 2026-04-20  **Origine :** P3 (commit `b007c6344`)  **Priorité :** P0  **Mode :** AUDIT-ONLY

## 1. Contexte
P3 a ajouté la transition `DELIVERED → RETURNED` côté `OrderService::changeStatus` (staff) avec **motif obligatoire**, cashback / repoints, audit `order.returned`, et `Order::restore()` bloqué. Il faut prouver l'idempotence, la traçabilité fiscale, l'absence de remboursement fantôme, l'impossibilité d'annuler un order non éligible.

## 2. Sources OBLIGATOIRES
- `app/Services/OrderService.php` (changeStatus, RETURNED branche)
- `app/Models/Order.php` (boot `restoring`)
- `app/Services/LoyaltyService.php` (refundPoints)
- `app/Services/AuditLogService.php`
- `app/Services/Fiscal/*`
- Requests : `OrderStatusRequest`, `PosOrderStatusRequest`
- Tests : `tests/Feature/PosOrderBL2AuditCallSitesTest.php`, `OrderCancellationLoyaltyTest.php`, tests fiscal
- Audits : `AUDIT_POS_110_PAYMENTS_REFUND_2026-04-19.md`, `..._FISCAL_NF525_*`

## 3. Hypothèses à challenger
- H1 : Le motif peut être contourné via un autre endpoint (ex. `destroy`, route admin).
- H2 : Un RETURNED sans paiement préalable réussi déclenche un cashback positif.
- H3 : Double appel RETURNED → cashback doublé.
- H4 : RETURNED ne génère pas l'événement / l'audit NF525 attendu (pas de chaînage hash).
- H5 : Loyalty `refundPoints` est appelé même si la commande n'a pas consommé de points.

## 4. Plan multi-agent
1. **Explore A** : flux back RETURNED, garde paiement, audit, fiscal (X/Z impact).
2. **Explore B** : tests existants — couverture par scénario (delivered→returned, double-call, no-payment, with-loyalty, without-loyalty).
3. **GeneralPurpose** : synthèse + matrice de cas + checklist NF525.

## 5. Vérifications obligatoires
- [ ] V1 : Motif obligatoire validé côté back (422 sans motif) — preuve test.
- [ ] V2 : Idempotence : 2× RETURNED ne re-crédite pas points / cashback.
- [ ] V3 : `Order::restore()` bloqué prouvé (test ou exception).
- [ ] V4 : Audit log inclut `reason`, `actor_id`, `previous_status`, `payment_method`, hash chain conservé.
- [ ] V5 : Émission de l'event `OrderReturned` ou équivalent + cohérence Outbox.
- [ ] V6 : Z/X reports prennent en compte le RETURNED (ou warning explicite si hors scope).
- [ ] V7 : `LoyaltyService::refundPoints` no-op si pas de transaction `redeem` antérieure (cf. patch utilisateur sur `OrderCancellationLoyaltyTest`).
- [ ] V8 : Aucun chemin POS legacy ne marque RETURNED en bypassant `changeStatus`.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V8 prouvés.
- WARN si V6 partiel.
- FAIL si idempotence cassée ou audit NF525 incomplet.

## 7. Livrables
- `reports/review/VERIFY_03_P3_REFUND_RETURNED_2026-04-20.md`

## 8. Suite
- FAIL idempotence → cycle P11 `P11_RETURNED_IDEMPOTENCY`.
- FAIL fiscal → cycle P12 `P12_RETURNED_FISCAL_INTEGRATION`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/03_VERIFY_P3_REFUND_RETURNED.md et exécute §4-§7.

OBLIGATIONS:
- 2 subagents `explore` parallèles (A back+fiscal, B tests+couverture).
- 1 subagent `generalPurpose` synthèse + matrice scénarios.
- 0 écriture de code.
- Livrable: reports/review/VERIFY_03_P3_REFUND_RETURNED_2026-04-20.md

Donne d'abord ton plan en 5 lignes. Conclus par "GLOBAL: ALL_GREEN|WARN|FAIL" + cycles P proposés.
```
