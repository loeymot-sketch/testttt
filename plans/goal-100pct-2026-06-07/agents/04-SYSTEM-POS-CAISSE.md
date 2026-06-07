# AGENT 04 — SYSTÈME POS / CAISSE
> Ton : caissier exigeant qui casse tout. CHAQUE bouton, CHAQUE mode de paiement.

## Scope / Anchors (vérifiés)
- `Admin/PosController.php`, `AdminPosV4Controller.php`, `PosOrderController.php`, `ComposerProfileController.php`, `ComposerStepController.php`
- `public/js/pos-wizard.js` (FROZEN), `public/js/pos-app.js`, `resources/views/admin-pos-v4.blade.php` (FROZEN)
- `PosCounterCollectModal.vue` (non-frozen), `EncaissementComponent.vue`
- Tests : `tests/Feature/Pos/*` (PosCashTrailTest, PosLoyaltyRedeemTest, PosMenuRuntimeAccessTest, FritesWizardComposerTest…)
- FROZEN : voir `memory/reference_frozen_zones.md` — pos-wizard.js, PaymentComponent.vue, PosV5TrancheRow.vue.

## Checklist abusif (6 axes)
- **A** Quote backend SSOT (front envoie id+qty+options), composition_snapshot figé, idempotency POST.
- **B1 CHAQUE bouton** : ouvrir caisse, wizard (item/variation/extras/suppléments), ajouter panier, modifier qté, supprimer ligne, parquer/reprendre, annuler, remise, no-sale, ouvrir tiroir, encaisser, clôturer Z.
- **B2** États vide/erreur/chargement/double-submit.
- **C** Capture (via agent 03) chaque effet ; vue opérateur lisible.
- **D** Fluidité clic→effet < 1s ; parcours complet sans blocage.
- **PAIEMENTS (cible §4)** : **CASH** ✅ + **CARD (SumUp ref manuel)** ⬜ + **Ticket-Resto** ⬜ + **Mobile** ⬜ — chacun : persiste 1 OrderPayment/méthode, card/TR = ref sans CashMovement (sinon sur-comptage tiroir D-3), fiscal alloué.
- **REMISE/COUPON** : appliquer remise → TVA recalculée correctement (ex-P0 coupon VAT-10) → vérifier ticket + Z.
- **REMBOURSEMENT** : bouton "Rembourser" (order-show) → log NF525, marqueur sur ticket, séquence préservée.
- **LOYALTY** : gagner + échanger points en caisse (PosLoyaltyRedeemTest).
- **10 COMMANDES** caisse variées (multi-items, options, paiements mixtes) → toutes correctes, fiscal gap-free.
- **F** Cash-trail complet, opérateur=caissier réel.

## Méthode
E2E :8766 (loginAsPosOperator/Admin) + DB verify après chaque action. Frozen wizard = piloter, JAMAIS modifier.

## PASS bar
Les 6 axes + 4 modes paiement + remise + refund + 10 commandes, prouvés avec capture+DB. Sinon ❌.

## Sortie `reports/test-e2e/goal-100pct-2026-06-07/<round>/04-pos.json`
