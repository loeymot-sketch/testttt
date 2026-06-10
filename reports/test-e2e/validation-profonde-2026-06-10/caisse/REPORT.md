# W-B CAISSE/POS — Parcours profond 100% (GOAL VALIDATION PROFONDE)
2026-06-10 — clone jetable `foodking_e2e` via http://127.0.0.1:8766 (serveur pre-cloud-exec, bundle servi = HEAD `b4389d34e`).
Spec : `tests/e2e/zz-caisse-parcours-profond-2026-06-10.spec.js` (serial T0→T7, 2 cycles identiques).
Heal-validation : `tests/e2e/zz-caisse-heal-remise-2026-06-10.spec.js` (serveur privé :8767, bundle rebuilt du worktree healé).

## Verdict
- **Cycle 1 : 8/8 PASSED** (après 3 boucles de correction spec + 1 heal P1 code).
- **Cycle 2 : 8/8 PASSED — résultats fonctionnellement IDENTIQUES** (diff attendu : fiscal_sequence_no 2155→2156 monotone +1 gap-free ; file encaissement 28→29 = commandes borne du run W-A PARALLÈLE sur le même clone — orders 4470/4471/4475 source=5 COUNTER_DEFERRED, pas les miennes).
- **1 P1 réel healé** (remise manuelle caisse morte — CAISSE-REMISE-01), **1 P1 connu re-confirmé avec preuve complète** (CAISSE-01 under-billing frites +2 €), 0 P0.

## Tableau parcours → statut → preuve
| Parcours | Statut | Preuve (cycle-1/) |
|---|---|---|
| B1 grille 45 tuiles, catégories (Tacos=2), recherche « Cayenne »=3/3 match, clear | ✅ | b1-01..04, run-state.t1 |
| B2 wizard FROZEN Sandwich Cayenne : viande 1/1, formule Menu+3€, frites Grande+1€, Cheddar+1€, note KDS, total €12.00 == panier 12,00 € | ✅ | b2-01..b2-10, run-state.t2 |
| B3 qty + (3,80→7,60) / − (→3,80) | ✅ | b3-02/03 |
| B3 remise : gate Appliquer désactivé sans motif | ✅ (gate) | b3-04 |
| B3 remise appliquée | ❌ sur bundle servi (P1 CAISSE-REMISE-01) → ✅ healé (3,80→3,42, 0 erreur) | b3-05 + heal-remise/1-2.jpg + result.json |
| B3 park (caissier pos@, 201) → panier vidé → recall (toast FR, 3,80 restauré) → clear (empty state) | ✅ | b3-06..09 |
| B4 paiement CASH : modal Espèces, reçu 22, rendu 10.00€, POST /api/admin/pos 201 | ✅ | b4-02/03 |
| B4 reçu : SIRET 10417050100019, TVA intra FR19104170501, Opérateur Admin Le Cayenne, désignations, TVA 10% (0,91), N°A0117, NF525 #2155, empreinte audit | ✅ | b4-04, b4-recu-texte.txt |
| B4 bouton imprimer (policy print-anyway) | ✅ cliqué, pas d'assert imprimante | b4-05 |
| B4 DB : source=15, payment_status=5, status=7, fiscal_sequence_no=2155, pos_received_amount=22, cash_movement order_payment in 10,00 lié session 17 | ✅ | run-state.t4 |
| B4 CAISSE-01 frites Grande+Cheddar | ⚠️ **CONFIRMÉ under-billing** (détail ci-dessous) | run-state.t4.caisse01 |
| B5 session : dialog auto-open, fond 50, +5/+10/+20/+50 | ✅ | b5-01..03 |
| B5 vue active (attendu 60,00 = 50+10), mouvements (1 row) | ✅ | b5-05/06 |
| B5 no-sale | ⚠️ 422 — data-gap clone (voir findings) | b5-04 |
| B5 clôture : compté 62, écart +2,00 affiché, raison obligatoire (gate), DB reconciled variance=2.00 reason persistée | ✅ | b5-07/08, run-state.t5 |
| B6 liste Commandes Caisse : colonnes/badges FR (En préparation, Livré), montants FR, 0 raw label, 0 null leak | ✅ | b6-01 |
| B6 show #1006264473 : Payé/En Préparation, Espèces, À emporter, client « Passager » (pas de nullXXXX), Imprimer/Rembourser | ✅ | b6-02 |
| B6 tracker : colonnes présentes, 0 raw label | ✅ | b6-03 |
| B7 file encaissement : 28 en attente, 154,60 €, cards Borne N°A0009+ âge 7h+, Encaisser | ✅ | b7-01 |
| B7 P2 connu « deux N°A0001 inter-jours » | non reproductible (0 doublon queue_number sur les 28 pending du clone) — déjà divulgué | run-state.t7 |

## Findings
### P1-1 — CAISSE-REMISE-01 (NOUVEAU, root-causé, HEALÉ, non-frozen)
- **Symptôme** : remise manuelle (montant + motif ≥3 chars) → total inchangé ; console `[vuex] unknown action type: posCart/discountReason` + pageerror `Cannot read properties of undefined (reading 'then')`.
- **Root cause** : `resources/js/components/admin/pos/PosComponent.vue:3744` et `:3747` font `this.$store.dispatch('posCart/discountReason', …).then().catch()` alors que `posCart.js` ne définit `discountReason` qu'en **getter (L270) + mutation (L532)** — l'action n'existe pas (régression M4-02). Le TypeError abandonne `applyDiscount` AVANT le calcul/application de la remise. Idem pour l'effacement de remise (branche else).
- **Heal appliqué** (scope-minimal, PosComponent.vue NON frozen) : `dispatch(...).then().catch()` → `commit(...)` (2 lignes).
- **Validation runtime** : bundle rebuilt + serveur privé :8767 → 3,80 € → 3,42 € (-10 %), Remise -0,38 € affichée, 0 erreur vuex/TypeError. `heal-remise/{1-avant,2-apres}.jpg` + `result.json`.
- ⚠️ Le bundle servi par :8766 (checkout pre-cloud-exec) reste NON rebuilt — merger ce fix + rebuild sur la branche spine.

### P1-2 — CAISSE-01 under-billing frites (CONNU supervisor 2026-06-09, RE-CONFIRMÉ preuve complète, FROZEN-gated)
- Wizard + panier + modal paiement affichent **12,00 €** (7 sandwich + 3 menu + 1 Grande + 1 Cheddar) ; le POST `/api/admin/pos` facture **10,00 €** ; ticket TOTAL 10,00 € ; `order_items.item_extra_total = 0` sur les 2 lignes ; `composition_snapshot` sans trace Grande/Cheddar ; les upgrades ne voyagent qu'en **instruction texte** (« Grande Portion (+€1.00) Cheddar Fondu (+€1.00) » visibles sur b6-02).
- Cascade : rendu monnaie incohérent — modal affiche « Monnaie à rendre 10.00€ » (22−12) mais le ticket imprime « Rendu : 12,00 € » (22−10).
- Périmètre fix = `public/js/pos-wizard.js` (frozen) côté payload OU voie serveur (PricingService SSOT) — **gate owner requis** (déjà tracké, M3-01 voie serveur possible).
- Preuves : run-state.t4.caisse01 (delta_ui_vs_db=2, sum_item_extra_total=0), b4-recu-texte.txt, b4-03, b6-02, commandes DB 4472/4473.

### P2-1 — no-sale (« Ouvrir tiroir ») → 422 silencieux dans ce contexte
- `EscPosPrinterService::openDrawer` (app/Services/Hardware/EscPosPrinterService.php:85) retourne `invalid_branch` pour Admin (branch_id=0) et `no_printer` (table `printers` vide dans le clone) → POST `admin/pos/cash-drawer/open` 422, aucun mouvement `TYPE_DRAWER_OPEN`.
- Partie « by design » (guard branch + forensic F-7) ; partie data-gap clone (0 imprimante seedée ; prod Le Cayenne a la SAGA SGPR-200II). **DISCLOSE : mouvement manuel tiroir non validable en clone sans seed imprimante + caissier.**

### P3-1 — loyalty balance appelé avec code vide → 422 console
- `PosComponent.vue:4224` : `axios.get('frontend/loyalty/balance', { params: { code: customer.phone || '' } })` — client sans téléphone (ex. restauration park) → `?code=` → 422 systématique (bruit console/réseau). Guard `if (!customer.phone) return` suffirait.

### P3-2 — formats monétaires en-US dans le wizard frozen + modal paiement
- Wizard sticky total « €12.00 », options « +€1.00 » vs « +3,00 € » sur le MÊME écran (b2-06/b2-08) ; modal paiement « 12.00€ » / « 10.00€ » (b4-03). Famille POS-ERG-07 connue — fichiers frozen (pos-wizard.js, PaymentComponent.vue), gate owner.

### P3-3 — bouton « APPLIQUER » tronqué
- Bouton remise `w-16` (PosComponent.vue ~L914) coupe le label uppercase FR « APPLIQUER » (visible b3-04/heal-remise/2). Cosmétique.

### P3-4 — anglicisme « VAT (10%) » sur le ticket client
- b4-recu-texte.txt : ligne taxe « VAT (10%)· Base HT 9,09 € » — attendu « TVA ». Cosmétique ticket (ReceiptComponent non-frozen à vérifier avant heal).

### Disclosures méthode
- T0 ferme les sessions tiroir `open` restantes par SQL direct (clone jetable) pour rendre B5 déterministe.
- Table `transactions` : NON utilisée pour le POS cash (748 rows clone = sources 5/10 online/kiosk uniquement) — piste financière POS = `cash_movements` + n° fiscal. L'assert spec a été aligné en conséquence.
- Park/recall exécutés en caissier `pos@lecayenne.fr` (Admin → 403 by design P0-POS-04).
- « MODE TEST — IMPRESSION BYPASSÉE » sur le reçu = simulation hardware dev (POS_SIMULATION_HARDWARE=true), attendu hors prod.
- Spec exécutée depuis le worktree `magical-spence-46ec51` (branche `test/caisse-wb-validation-2026-06-10`, même commit b4389d34e) — le worktree pre-cloud-exec étant write-locké pour cet agent.
