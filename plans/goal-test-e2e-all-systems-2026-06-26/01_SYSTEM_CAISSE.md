# 01 — SYSTÈME CAISSE (POS) — plan test-e2e abusif

**Contract** : terminal principal du commerçant — prise de commande, paiement,
encaissement, tiroir, fiscal Z, parking, fidélité, refund. Lentille dominante =
🧑‍💼 **COMMERÇANT/caissier** (+ client au paiement). Vague **séquentielle**
(touche fiscal/pricing partagés). DB live `foodking_e2e`. URL `/admin/pos`.

**Frozen touché** : `pos-wizard.js` + `.css` + `admin-pos-v4.blade.php` (STRICT no-touch) ;
`PaymentComponent.vue`, `pos/v5/PosV5TrancheRow.vue` (auditable + gate).
**Shared** : `PricingService` (SSOT), chaîne NF525 (alloc `fiscal_sequence_no` au PAID
comptoir), `OrderStateMachine`/`PaymentStateMachine`, bus sync (publie
`OrderCreated`/`OrderStatusChanged` → KDS/OSS).

**Anchors (vérifiés)** : `app/Http/Controllers/Admin/Pos/**` + `AdminPosV4Controller`,
`PosController`, `PosOrderController`, `PosCategoryController`, `PosLoyaltyController`,
`CashOverviewController`, `CashSessionReportController`, `CashDrawerController`,
`CashDrawerSessionController`, `ParkedOrderController`, `FloorplanController` ;
services `PaymentService`, `SplitPaymentService`, `CashDrawerService`, `Pos/**` ;
front `resources/js/components/admin/pos/**` (23 .vue) + posOrders/cash/cashOverview/
cashSessionReport/encaissement ; tests `tests/Feature/Pos/` (17) + `tests/js/pos*.spec.js`.

---

## INVENTAIRE PAGES/SURFACES

| Surface | Composant | Rôle |
|---|---|---|
| `/admin/pos` (landing category-first) | `PosComponent.vue` | Grille catégories → drill produits + panier |
| sélection simple | `ItemComponent.vue` | Ajout + **bridge variations/extras** (émet `item:added`) |
| popup wizard | `pos-wizard.js` **FROZEN** | Composition viande/sauce/suppl/formule |
| encaissement | `PaymentComponent.vue` **FROZEN** | cash/CB(TPE)/split/ticket-resto, monnaie |
| tranche split | `v5/PosV5TrancheRow.vue` **FROZEN** | 1 tranche multi-paiement |
| `/admin/pos/floorplan` | `FloorplanComponent.vue` | plan salle assign/release/transfer |
| modal parking | `ParkedOrdersComponent.vue` | park/resume/destroy |
| modal fidélité | `PosLoyaltyRedeemModal.vue` | redeem points |
| modal collecte comptoir | `PosCounterCollectModal.vue` | encaisse PENDING_COUNTER (borne+walk-in) |
| modal refund | `PosRefundModal.vue` | refund pre-Z / counter-entry post-Z |
| reçu | `ReceiptComponent.vue` (+Duplicata/Remboursement markers) | ticket client |
| suivi | `PosOrdersTrackerComponent.vue` | kanban live ACCEPT→DELIVERED |
| `/admin/pos-orders` | `posOrders/*` | historique POS |
| `/admin/encaissement` | `encaissement/EncaissementComponent.vue` | file unifiée encaissement |
| `/admin/cash-overview` | `cashOverview/*` | transactions encaissées |
| `/admin/cash-sessions-report` | `cashSessionReport/*` | rapport sessions tiroir |
| dialog tiroir | `cash/PosCashDrawerSessionDialog.vue` | open/close/reconcile |

---

## DÉCOMPOSITION (4 sous-systèmes)

### Sub 1.a — Prise commande / wizard
- T-1.a.1 Audit `quote` (`PosController::164`) — preview SANS effet de bord, total = backend.
- T-1.a.2 Audit bridge composer-aware (`ItemComponent.vue:661/692/713` + `master.blade.php:147`) — variations/extras réellement transférés (re-test du fix `065ab8ace`).
- T-1.a.3 Audit SSOT prix (`store`→`PricingService::calculateOrder`) — client n'envoie que `item_id/qty/option_ids`.
- T-1.a.4 Audit contraintes MAX/MIN viandes/extras (`MultiVariationConstraint`) — omis/excès → 422.
- T-1.a.5 Audit category-first landing + retour-auto (`posBrowseView.js`).
**Acceptance** : `QuoteBindingTest`, `PosOrderRequestNoClientTotalsTest`, `FritesWizardComposerTest`, `PosMenuRuntimeAccessTest` PASS + JS `posBrowseView/posWizardComposerAware/posVariationMultiQty` + *(À CRÉER `tests/Feature/Pos/PosQuoteVariationConstraintTest.php`)* + capture wizard 4 templates verte.

### Sub 1.b — Paiement / encaissement / split
- T-1.b.1 Audit `confirmCounterPayment` (`PaymentService:193`) — modes ⊆ [CASH,CARD,MOBILE,OTHER,TICKET_RESTAURANT], lock+PAID.
- T-1.b.2 Audit `SplitPaymentService::validateBreakdown` (`:51`) — Σ tranches == total, tendered≥amount.
- T-1.b.3 Audit alloc fiscale au PAID comptoir (cash-trail NF525, séquence gap-free).
- T-1.b.4 Audit concurrence double-encaissement (`PaymentAlreadyCollectedException`→409, race `lockForUpdate`).
**Acceptance** : `SplitPaymentEndToEndTest`, `TerminalIdWireInTest`, `PosCashTrailTest` PASS + JS `posSplitPaymentValidation/posSplitPaymentBidirectional/posPaymentComponentContract` + *(À CRÉER `CounterCollectConcurrencyTest.php`)*.

### Sub 1.c — Tiroir-caisse / cash / Z
- T-1.c.1 Audit `openSession/closeSession/reconcileSession` (`CashDrawerService:52/126/225`) — 1 session/user, écart calculé.
- T-1.c.2 Audit ownership session (`CashDrawerSessionOwnershipTest`).
- T-1.c.3 Audit `simulation_hardware` (`config/pos.php:37`) + boot-guard prod refuse `true`.
- T-1.c.4 Audit traçabilité movements (`recordMovement:365`).
**Acceptance** : `PosCashTrailTest`, `CashDrawerSessionOwnershipTest`, `PosSimulationHardware4ScenariosTest` PASS + *(À CRÉER `CashReconcileDiscrepancyTest.php`)*.

### Sub 1.d — Commandes / parking / loyalty / refund
- T-1.d.1 Audit park/resume/destroy + purge cron (`PosPurgeParkedScheduleTest`).
- T-1.d.2 Audit `refundWithCounterEntry` (`PosOrderController:47`) — gate `pos-refund` (Admin+BM only), sealed→mirror / pre-Z→RETURNED.
- T-1.d.3 Audit `redeem` loyalty (`PosLoyaltyController:36`) — gate `pos.redeem-loyalty`, solde.
- T-1.d.4 Audit `changeStatus/changePaymentStatus` (`PosOrderController:312/323`) — **pas de refund-bypass**.
- T-1.d.5 Audit floorplan assign/release/transfer + release-after-order.
**Acceptance** : `PosLoyaltyRedeemTest`, `PosWalkinDeferredCreateTest`, `DiningTableReleaseAfterPosOrderTest`, `FloorplanControllerTest` PASS + *(À CRÉER `RefundBypassGuardTest.php`)*.

---

## GERMES ADVERSAIRES (🧑‍💼 commerçant + 🧑 client)
- **Commande** : viande/supplément non facturé (bridge composer), forge `total_price`/`option_ids` hors profil → backend doit gagner, 2ᵉ viande omise vs MAX, `quote`≠`store` (intent-hash), ajout produit INACTIF (status=10).
- **Paiement** : caissier pressé **double-clic confirm** → 1 seul PAID (409), **montant reçu < dû** (monnaie négative ?), **split qui ne boucle pas** (Σ≠total bloque), mode hors-liste injecté, re-payer order PAID.
- **Tiroir** : **fermer Z avec commande impayée/PENDING_COUNTER** en file, **rouvrir tiroir** / 2 sessions, reconcile écart masqué, `simulation_hardware=true` en prod (boot-guard refuse), closing_amount forgé.
- **Refund/loyalty** : **refund-bypass via `change-status→RETURNED`** (Operator sans `pos-refund`), refund cross-branch, double-refund (mirror ×2), redeem > solde / après refund, resume order déjà payé.

---

## PIÈGES & DÉFAUTS CONNUS (re-tester)
1. **Fantôme-upcharge viande +2,50** : `pos-wizard.js:89` `VIANDE_SUPPL_PRICE` (alimenté par `master.blade.php:280` settings `order_setup_viande_suppl_price ?? 2.50`). Le prix affiché est-il **sérialisé en option-prix backend** ou décoratif (sous-facturation devis vs réel) ? → frozen + décision business = **ESCALADE** si écart.
2. **Money en-US** `pos-wizard.js:218` `'€'+toFixed(2)` (non-FR `7,90 €`). FROZEN → owner-gate.
3. **Double-encaissement** : commentaire `PaymentService.php:~230` « UNHEALED two cashiers ». Re-tester 409 vs silencieux.
4. **Refund ne flippe PAS payment_status→REFUNDED** (volontaire `PaymentStateMachine.php:17`). Vérifier aucun chemin ne contourne.
5. **Écran Rapport Z dédié absent** du dossier pos/ (clôture via `ZReportService` fiscal). À vérifier surface fiscale séparée.
