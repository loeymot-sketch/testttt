# T06 — SSOT pricing / totals (kiosk + POS + web)

**Date** : 2026-04-20  **Statut** : DONE (rapport + T06b)  **Subagent** : `explore`

## Objectif unique

Vérifier que **tous les prix, taxes, remises, totaux** sont calculés **server-side** par
`PricingService` / `FrontendOrderService` / `OrderService`. Aucune route n'accepte un
`total` client comme valeur de vérité. Aucun composant Vue ne calcule un total écrit en DB.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Mission : auditer SSOT prix/totaux.

Racine principale : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93
(comparer à testttt si écart suspect).

Étapes :
1) Lire :
   - app/Services/PricingService.php
   - app/Services/FrontendOrderService.php
   - app/Services/OrderService.php
   - app/Services/Calculators/DiscountCalculator.php (si existe)
   - app/Http/Requests/{PricingRequest,PosPricingRequest,TablePricingRequest,WebPricingRequest,OrderRequest,PosOrderRequest}.php
2) Pour chaque Request : `total`, `subtotal`, `discount`, `tax_amount` sont-ils
   `nullable`/`sometimes` (server-recompute) ? Référence : note `OrderRequest scope ext
   2026-04-18 — additif uniquement` du PLAN_PHASE_9_KIOSK.
3) Recherche d'écritures suspectes :
   - `rg -n "total.*=>.*\\\$request->" app/Http/Controllers app/Services`
   - `rg -n "->order_total|order->total\s*=" app/Http app/Services`
4) Frontend :
   - resources/js/store/modules/kioskCart.js (pas de prix dans payload outbound — réf P9.5.8)
   - resources/js/components/frontend/kiosk/KioskPaymentComponent.vue (~309-310, AX4-04)
     → bloquer paiement si `total` serveur absent ?
   - resources/js/components/frontend/kiosk/KioskCartComponent.vue (`cartTotal` n'est qu'affichage)
   - resources/js/components/admin/pos/PosComponent.vue (totaux POS — pareil ?)
5) Cross-item guard P9.5.6 : `PricingService` interdit qu'une ligne client diffère du
   panier serveur ?
6) Tests : tests/Feature/Pricing/, tests/Feature/Orders/CrossItemGuardTest.php,
   tests/js/KioskPaymentComponent.spec.js — couvrent-ils refus de `total` client ?
7) Audit AX4-04 (kiosk) et tout finding POS pricing → consolider.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK06_SSOT_PRICING_2026-04-20.md
```

## Lecture obligatoire

- `PricingService.php`, `FrontendOrderService.php`, `OrderService.php`, `OrderRequest.php`
- `KioskPaymentComponent.vue`, `kioskCart.js`
- `reports/review/AUDIT_KIOSK_110_SYNC_PRICING_2026-04-19.md`
- `reports/review/AUDIT_POS_110_PAYMENTS_REFUND_2026-04-19.md`
- `tasks/k-hardening/PLAN_K2_MENU_AVAILABILITY_2026-04-18.md` (Guard 409)

## Checklist multi-points

- [ ] V1. Aucun controller ne lit `request('total')` comme vérité
- [ ] V2. Toutes les Request rendent montants `nullable` / `sometimes`
- [ ] V3. `PricingService` est seul à calculer (pas de duplication dans Service ou Job)
- [ ] V4. Frontend kiosk n'envoie aucun `total`/`subtotal` dans payload commande
- [ ] V5. `KioskPaymentComponent` bloque paiement si réponse pricing absente (AX4-04 fixé ?)
- [ ] V6. POS `PosComponent` même garantie
- [ ] V7. Cross-item guard P9.5.6 actif + testé
- [ ] V8. Tests Vitest + PHPUnit couvrant refus `total` client

## Critères PASS / FAIL

- **PASS** : 8 V cochées, AX4-04 résolu.
- **FAIL** : ≥ 1 surface accepte `total` client → invariant SSOT cassé → P0.

## Output

`reports/audit-orchestration/REPORT_TASK06_SSOT_PRICING_2026-04-20.md`

## Si FAIL → action

→ T06b `generalPurpose` : plan de remédiation (Request à modifier, controller à corriger,
test à ajouter). Pas d'application directe.
