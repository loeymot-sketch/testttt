# CAISSE — CHASSE AUX PROBLÈMES DE LOGIQUE (toutes pages, même indirectes) — 2026-07-11
> /goal « trouve les problèmes de logique, test complet caisse de TOUTES les pages, même indirectes ».
> 4 agents adversaires LOGIQUE (disjoints) + e2e interactif navigateur. Chaque finding VÉRIFIÉ avant action.

## 3 BUGS DE LOGIQUE RÉELS TROUVÉS + CORRIGÉS + TESTÉS
| # | Bug | Fichier (non-frozen) | Fix | Test |
|---|---|---|---|---|
| 1 | **Rendu (change) d'une tranche pris du CLIENT sans recalcul** (forge 99€ stocké au lieu de 1,50) → rendu faux à l'écran | `SplitPaymentService:230` | `change = max(0, tendered−amount)` serveur | `test_persist_recomputes_change_ignoring_forged_client_value` ✅ |
| 2 | **Mouvement tiroir FANTÔME sur remboursement carte/en-ligne** (`cashBack('credit')` écrivait une sortie espèces) → faux surplus au rapprochement | `PaymentService:176` | garde sur la MÉTHODE du remboursement (`gatewaySlug==='cash'`), pas `pos_payment_method` | `PaymentServiceCashHookTest` 8/8 (incl. carte→0 mvt, cash→mvt) ✅ |
| 3 | **Fidélité : points brûlés au-delà de la valeur livrée** (garde `discount>subtotal` ignorait la remise préexistante → sur-rachat clampé à 0 mais TOUS les points débités) | `PosRedemptionService:145` | garde sur la remise CUMULÉE (`existing+redeem > subtotal → 422`) | `test_redeem_rejected_when_cumulative_discount_exceeds_subtotal` ✅ |

## 1 FAUX POSITIF réfuté (vérif avant action)
- Agent paiement F1 « comptoir cash sans garde reçu≥total » → **RÉFUTÉ** : le garde existe déjà (`PaymentService:331-335`). L'agent avait lu la l.352 sans voir la l.331. (Et le garde proposé par l'agent refund — sur `pos_payment_method` — cassait le test légitime I-D : c'est en vérifiant que j'ai trouvé le VRAI critère = méthode du remboursement.)

## E2E interactif navigateur (POS /admin/pos) — logique cœur CORRECTE
- Wizard sauce « 1ère gratuite, +0,50/sauce » : 2 sauces → **6,50€** (badge « 2 sauces = +€0.50 ») ✅
- Total ligne composée = sous-total = total ✅
- Park (mise en attente) → ticket vidé, compteur +1, libellé préservé ✅
- Recall (restaurer) → compo complète + 6,50€ intacts, compteur −1 ✅

## Findings DOCUMENTÉS (owner / non-code / hors périmètre)
- **Tiroir** (math saine, 0 P0) : P1 asymétrie Z-enrichment (caisse RECONCILED-only vs livreur CLOSED+RECONCILED) = sémantique compta owner ; P2 label « espèces encaissées aujourd'hui » = net signé depuis ouverture ; tiroirs zombies jamais clôturés faussent la vue carte ; Grand Total exclut tx dont commande soft-deleted.
- **Refund** : P3 recall ne valide pas la dispo de l'ITEM de base (seulement variations) — le P3 du contrat ; remboursement PARTIEL non implémenté (décision produit V1=total).
- **Fidélité** : P2-B pré-rachat `order_id=NULL` jamais remboursable (LoyaltyController, transverse) → points perdus si abandon ; P3 close-session livreur idempotent efface un closing corrigé.
- **UX** : « Mettre en attente » utilise un `prompt()` natif (P3) ; appel `loyalty/balance?code=` vide → 422 capté (smell).

## Gates
0 frozen · NF525 chain clean 4 branches · régression POS+Cash+Fiscal+Loyalty+Split VERTE · 3 fixes non-frozen + 3 tests.
