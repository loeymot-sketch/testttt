# ADVERSARIAL VERDICT — Vague E cross-surface (Round 1, dispute-2026-06-12)

Superviseur adversarial. Verdicts de sévérité rendus APRÈS lecture multimodale réelle des PNG,
inspection DOM/console/network, re-grep file:line, recalculs indépendants (DB foodking_e2e
directe) et re-vérification LIVE (`tests/e2e/_d1red-E-cross-surface-verify.mjs`,
captures `E-RED-01..04`).

## Statut: COMPLET — verdict final rendu

## Verdict global: **RED** — 2 P0 / 4 P1 / 3 P2 / 2 P3

La « convergence 0 P0/0 P1/0 P2 » du FINAL_REPORT 2026-06-11 ne tient pas sur cette vague :
deux P0 d'intégrité numérique vivent sur les surfaces mêmes que le GOAL prétendait
production-perfect (borne paiement + vue caisse du gérant).

---

## FINDINGS CONFIRMÉS

### E-ADV-1 — **P0 CONFIRMÉ** — Promo borne affichée au client mais JAMAIS persistée (intégrité numérique cross-écran + cross-surface)
- **Catégorie**: numeric_integrity (#11) + silent business failure.
- **Lecture visuelle faite (Read multimodal)**:
  - `E10-03-cart-apres-promo.png`: bloc vert « ✓ Code promo BORNEAUDIT5 appliqué (−1,50 €) », ligne « Code promo BORNEAUDIT5 -1,50 € », **Total 0,00 €**, CTA « Valider ma commande 0,00 € ».
  - `E10-04-payment-counter.png`: « TOTAL À RÉGLER : **0,00 €** ».
  - `E10-05-cash-instruction.png`: « Montant à régler **1,50 €** » — le client voit 0,00 € puis 1,50 € sur deux écrans consécutifs de la même borne.
  - Run 2 (4531): `E12-02` Total 5,00 € / `E12-03` 5,00 € / `E12-04` **10,00 €**.
- **DB recoupée (refaite par le superviseur)**: orders 4518 discount=0.000000/total=1.500000 ; orders 4531 discount=0.000000/total=10.000000 ; kiosk_promos id=1 `BORNEAUDIT5` uses_count=**0** après 2 applications.
- **Root cause re-greppée** (verify-before-report): `app/Services/Order/OrderQuoteService.php:207-220` — `calculatePricing()` kiosk construit `PricingRequest::forKiosk(0, $branchId, $items, coupon_id, actor, delivery)` **sans jamais passer `kiosk_promo_code`** ; le code n'entre que dans la signature canonique (`OrderQuoteService.php:416`). `withKioskLoyaltyDiscount()` ne traite que `loyalty_code`. Le seul service qui applique `KioskPromo::findValid`+`computeDiscount` est `app/Services/Kiosk/PricingPreviewService.php:82-88` — hors chemin quote/create. Côté UI, `resources/js/store/modules/kioskCart.js:191-196` (validation lecture-seule `/promo/validate`) + `:258` (total local soustrait `promoDiscount`) promettent une remise que la création de commande ne réalise jamais ; `kioskCart.js:152` ENVOIE bien `kiosk_promo_code` — c'est le backend qui le laisse tomber.
- **Recalcul superviseur**: clamp(5,00 ; panier 1,50)=1,50 → total attendu 0,00, persisté 1,50. Run 2 : 10,00−5,00=5,00 attendu, persisté 10,00. Le ticket NF525 2172 (`_E22-receipt.txt`) est arithmétiquement cohérent (7,73+1,36=9,09 HT ; 9,09×10%=0,91 ; 9,09+0,91=10,00) **mais cohérent avec le MAUVAIS total** : le client à qui l'écran de paiement a affiché 5,00 € est facturé 10,00 €.
- **Verdict**: **P0**. Le client paie PLUS que ce que l'écran de paiement a affiché. (Heal « promo dormante » existant sur `heal/ultra-audit-w4` NON mergé ici = régression de branche, mais la branche release est celle auditée.)
- **Re-lecture multimodale superviseur (2e passe, 2026-06-12)** : E10-03/E10-05/E12-02/E12-04 relus pixel par pixel — divergence client-facing prouvée sur DEUX commandes indépendantes. **P0 MAINTENU.**

### E-ADV-2 — **P0 CONFIRMÉ + RE-PROUVÉ LIVE** — « Vue Caisse Unifiée » et /admin/transactions excluent TOUTES les ventes caisse directes (sous-évaluation massive du CA encaissé)
- **Catégorie**: numeric_integrity (#11) — le même fait (CA encaissé du jour) diverge entre surfaces et vs DB.
- **Recalcul DB superviseur (2026-06-12)**: commandes PAYÉES business_date 2026-06-12 = **11 pos (79,17 €) + 1 kiosk (10,00 €) ≈ 89,17 €**. Table `transactions` type=payment du jour = **4 lignes / 39,80 €** (25,00 synthétique F2-PAY-4515 + 3 encaissements borne COUNTER-*). **0 des 11 ventes POS directes n'a de ligne `transactions`** (requête jointe re-exécutée par le superviseur : `pos_orders_with_txn = 0`).
- **LIVE re-prouvé** (`E-RED-02-cash-overview-live.png`, 09:39): « Vue Caisse Unifiée 12/06 — GRAND TOTAL **39,80 € · 4 tx** ; CAISSE **25,00 € · 1 tx** ; BORNE 14,80 € · 3 tx » → le bucket CAISSE du gérant ne contient que la ligne synthétique de test ; les 79,17 € de ventes caisse réelles sont invisibles. `E-RED-03-transactions-live.png`: /admin/transactions (750 entrées) ne contient NI 4543 NI aucune vente POS directe.
- **Root cause re-greppée**: `app/Http/Controllers/Admin/CashOverviewController.php:111-116` — la page est construite EXCLUSIVEMENT sur `Transaction::query()->where('type','payment')` ; or le flux de paiement POS direct n'écrit jamais `transactions` (créations limitées à `PaymentService.php:55` gateway-context et `:402` counter-collect `COUNTER-*`). Le tiroir (`cash_movements` id 227 +4,80 pour 4543) voit la vente ; la « vue unifiée » non.
- **Verdict**: **P0**. Le GRAND TOTAL et la répartition par mode présentés au gérant excluent la majorité du CA encaissé (39,80 € affichés vs ~89,17 € réels = −55 %). Une surface de pilotage cash qui se trompe de moitié est prod-breaking pour un restaurateur.

### E-ADV-3 — **P1 CONFIRMÉ + CASCADE FINANCIÈRE TRACÉE LIVE** — Identité client des commandes borne = « Admin Le Cayenne » → 3 libellés pour le même fait + remboursements borne qui créditent le wallet de l'admin
- **Faits cross-surface (même commande 4531/A0006)**: /admin/encaissement « **Client borne** » (E20-01) ; /admin/pos-orders/show « **Client Borne** » (E20-04/E23-04) ; /admin/historique CLIENT = « **Admin Le Cayenne** » (E23-06, `_log-E20-caisse.txt`) ; tracker carte « **Admin Le Cayenne** » (E23-07). 3 réponses différentes selon la surface.
- **Root cause DB re-vérifiée**: orders 4531 `user_id=1` (= Admin Le Cayenne, branch 0) ; **195 commandes kiosk portent user_id=1**. Historique/tracker lisent `order.user.name` ; encaissement/show ont un libellé codé en dur (fix W2 « Client borne » appliqué sur UNE seule surface).
- **CASCADE (élucide l'« élément 5,80 € » E-OBS-12 de l'équipe, désormais RÉSOLU)**: le montant mystère du chrome admin est le **wallet `users.balance` de l'admin affiché dans le dropdown profil topbar** (`E-RED-01-tracker-topbar` : H3 sous admin@lecayenne.fr / +330600000000, DB `users.balance=5.800000`). Traçage temporel: baseline 00:39 = 2,00 € (`_log-E20-caisse.txt` ligne CASH-OVERVIEW BASELINE) → refund borne 4329 (3,80 €) à 02:41 → topbar = **5,80 €** live à 09:39. Mécanisme: `app/Services/PaymentService.php:147` `$user->balance = ($user->balance + $order->total)` au cash_back — l'order borne ayant user_id=1, **chaque remboursement borne crédite le portefeuille de l'admin** en plus de la sortie tiroir réelle (`cash_movements` 225 out 3,80) = passif fantôme qui double-compte le remboursement. Risque aggravé latent: `app/Http/Controllers/Frontend/PaymentController.php:49` autorise le paiement gateway « credit » si `order.user.balance >= total` — et les commandes borne appartiennent à user 1.
- **Verdict**: **P1** (divergence d'identité user-visible sur 4 surfaces + pollution d'un grand livre financier latent ; NF525-adjacent, parent du thème operator-identity connu mais ICI c'est le champ CLIENT + le wallet).

### E-ADV-4 — **P1 CONFIRMÉ** — N° de file dupliqués multi-business-dates dans la file d'encaissement, affichés SANS date (risque d'encaisser la mauvaise commande)
- **DB re-vérifiée**: A0011 en attente ×2 (id 4330, 06-10, **1,00 €** ; id 4537, 06-12, **9,50 €**) ; idem A0013/A0014/A0015 ; **48 commandes pending_counter de 2026-06-10** jamais purgées.
- **LIVE** (`E-RED-04-encaissement-a0011.png`, 09:39): 60 cartes en attente, « Total en attente d'encaissement : **305,60 €** » ; les cartes n'affichent AUCUNE date — seule une puce d'attente relative (« 57h08min » orange vs minutes bleues) distingue les deux A0011. Un client annonce « A0011 » → deux cartes répondent, montants différents.
- **Structurel**: aucun mécanisme d'expiration/purge des commandes borne abandonnées (grep `app/Console/Commands` : rien sur PENDING_COUNTER) ; la réutilisation quotidienne des N° + l'affichage sans date garantissent la collision dès qu'une commande impayée survit à minuit. Effet secondaire: ces zombies occupent les 8 slots KDS (E24-01 « +11 en attente », la commande PAYÉE A0006 invisible à l'écran) et gonflent le bandeau « total en attente ».
- **Verdict**: **P1** (user-visible, risque d'erreur d'encaissement réel ; contexte backlog = données de test, mais le mécanisme est structurel).

### E-ADV-5 — **P1 CONFIRMÉ** — Remboursements wallet « credit » affichés « Carte bancaire » sur /admin/transactions
- **LIVE** (`E-RED-03-transactions-live.png`): lignes `TXN-fkDxb6PZtOwj` (−3,80 €) et `TXN-GXdX82uD6hf7` (−25,00 €) affichées mode « **Carte bancaire** » ; DB `transactions.payment_method='credit'` (= gateway wallet interne) ; le remboursement réel de 4329 est sorti du TIROIR en espèces (`cash_movements` 225 out 3,80).
- **Root cause re-greppée**: `app/Http/Resources/TransactionResource.php:51` — `'counter_card', 'card', 'credit' => 'Carte bancaire'` : le slug wallet `credit` est fusionné dans le libellé carte bancaire.
- **Verdict**: **P1**. Le gérant lit « remboursement carte bancaire » pour un remboursement espèces/wallet — fausse information de mode sur une surface monétaire (fausse la réconciliation par mode).

### E-ADV-6 — **P1 CONFIRMÉ** — Borne « Paiement en espèces uniquement à la caisse. » vs caisse qui accepte 4 modes pour la même commande
- **Evidence**: `E12-04-cash-instruction.png` (« Paiement en espèces uniquement à la caisse. ») vs `E21-01-modal.png` (modal d'encaissement de la MÊME commande A0006 : Espèces / Terminal (manuel) SumUp / Mobile / Ticket restaurant).
- **Root cause re-greppée**: `resources/js/languages/fr.json:1715` `"help": "Paiement en espèces uniquement à la caisse."` (`KioskCashInstructionComponent.vue:36` `kiosk.cash_instruction.help`). Copy périmée vs le mandat owner « encaissement UNIFIÉ » (4 modes au comptoir).
- **Verdict**: **P1**. Information factuellement fausse donnée au client sur un écran de paiement — un client sans espèces peut renoncer alors que sa carte est acceptée (vente perdue).

### E-ADV-7 — **P2 CONFIRMÉ** — Panneau « Réconciliation caisse (session en cours) » lié à une AUTRE session que celle qui reçoit les encaissements
- **DB re-vérifiée**: **3 sessions tiroir ouvertes simultanément** branch 1 (id 19 user 1, id 20 user 3, id 22 user 11) ; mouvement +10,00 (4531) → session **19**, +4,80 (4543) → session **19**, +1,50 (4552) → session **20** ; le panneau affiche la session **22** (« ouverte à 02:39, fond 50,00, espèces −3,80, attendues 46,20 » — confirmé LIVE `E-RED-02`).
- **Conséquence**: le caissier qui vient d'encaisser +10,00 € lit « Espèces encaissées (session en cours): **−3,80 €** » (E23-08). L'app autorise N sessions ouvertes sur la même branche sans indiquer laquelle est « en cours » ni à laquelle les mouvements s'attachent.
- **Verdict**: **P2** (UX-quality money surface ; multi-sessions créées par le harness mais autorisées par le produit — single-box V1 = 1 caisse, à arbitrer owner).

### E-ADV-8 — **P2 (WEAKENED depuis le claim « silent-error » de l'équipe)** — Échec 401 mid-confirm d'encaissement sans message d'échec explicite
- **Faits**: tentative 1 `POST counter-collect/4531/confirm` → 401 (token révoqué par vague parallèle) ; `_log-E20-caisse.txt` « POST-CONFIRM toasts: [] » (scanné 4 s après le clic).
- **Arbitrage code**: ce N'EST PAS totalement silencieux — `resources/js/app.js:171-177` (401 admin → `store.dispatch('logout')` + redirect `auth.login`) ; le catch par défaut du modal (`PosCounterCollectModal.vue:600`) toaste `err.response.data.message` = « Unauthenticated. » (EN brut, TTL 2000 ms, race avec la navigation — d'où le scan vide à +4 s). Le caissier est déconnecté mais ne reçoit AUCUN message « encaissement NON enregistré » ; s'il a déjà accepté les espèces du client, la commande reste À encaisser.
- **Verdict**: **P2** — la cause 401 était un artefact harness, mais un token expiré (TTL 480 min) produit le même chemin en prod ; le feedback d'échec d'encaissement explicite manque, et le message de secours est de l'anglais brut.

### E-ADV-9 — **P2 CONFIRMÉ (latent)** — `order_payments` jamais alimentée par counter-collect NI par le paiement POS mono-mode → ventilation par TPE des Z reports structurellement vide
- **DB re-vérifiée**: 0 ligne order_payments pour 4531 ET 4543 (re-exécuté).
- **Code re-greppé**: seule `app/Services/Payments/SplitPaymentService.php:247` écrit `OrderPayment::create` ; or `app/Services/Fiscal/ZReportCashEnrichmentService.php:157` (`aggregateByTerminal`) construit la ventilation cash/card/fees par TPE EXCLUSIVEMENT sur `OrderPayment::query()` → vide pour tous les paiements mono-mode (la quasi-totalité V1). Enrichissement additif hors signature HMAC (décorateur, frozen-zone respectée) donc PAS de corruption de chaîne — mais la ventilation TPE présentée est fausse par omission.
- **Verdict**: **P2** latent (TPE non câblés en V1 — devient P1 au branchement des terminaux).

### E-ADV-10 — **P3** — KDS : attentes affichées en HH:MM ambiguës au-delà de 24 h
- « ATTENTE 19:18 » / « 14:42 » (E23-10/E24-01) sur des commandes de J-2 ressemblent à des heures de la journée. Cosmétique, amplifié par le backlog non purgé (E-ADV-4).

### E-ADV-11 — **P3 / ARBITRAGE OWNER** — KDS « Démarrer » actif sur commandes non encaissées : politique owner DOCUMENTÉE ici, contradictoire avec le heal d'une autre branche
- L'équipe suspectait (E-SUS-6) l'absence du release-guard KDS. **Re-grep**: `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:399-404` — « `payment_pending_counter` now drives only a NON-blocking "non encaissé" badge — NOT a bump gate. **Owner reversed the Wave S-2 "must wait for cash" rule: the kitchen prepares before encashment** ». Donc PAS un défaut sur cette branche (badge « EN ATTENTE ENCAISSEMENT » présent, bouton actif = voulu).
- MAIS la branche `heal/ultra-audit-w4-2026-06-11` a livré un « KDS release-guard » (mémoire projet, postérieur au mandat 2026-05-30). Les deux branches codifient des politiques OPPOSÉES → un merge naïf fera ping-pong. **Arbitrage owner requis** (anti-drift §12). Pas compté comme défaut.

---

## CLAIMS DE L'ÉQUIPE REJETÉS OU REQUALIFIÉS

- **E-OBS-2 (401→200 sur /frontend/order/quote)** → **REJETÉ comme finding**: c'est le chemin de récupération conçu `app.js:100-130` (`__retry401Kiosk`, re-login coalescé + event `kiosk-auth-retried` → toast transitoire). Même famille que le gate connu « 401 one-shot boot kiosk ». Aucun impact user observé (retry 200).
- **E-SUS-5 (401 silencieux)** → requalifié **P2** (E-ADV-8) : pas totalement silencieux (logout+redirect+toast EN 2 s), mais feedback d'échec d'encaissement explicite manquant.
- **E-SUS-6 (KDS Démarrer)** → requalifié **P3/arbitrage** (E-ADV-11) : politique owner documentée dans le code.
- **E-SUS-11 (A0006 payée invisible au KDS)** → pas de finding séparé : sous la politique « cuisine avant encaissement », l'ordre ancienneté-d'abord est cohérent ; la cause réelle (backlog zombie qui occupe les slots) est comptée dans E-ADV-4.
- **E-OBS-12 (« 5,80 € » mystère)** → RÉSOLU, absorbé dans E-ADV-3 (wallet balance admin au dropdown profil, cascade refunds borne).
- **E-OBS-8 (attentes HH:MM)** → confirmé **P3** (E-ADV-10).

## INTÉGRITÉ TVA — RECALCULS INDÉPENDANTS DU SUPERVISEUR (tous verts)

| Ordre | Vérification | Résultat |
|---|---|---|
| 4531 (10,00 TTC @10 %) | Tacos 8,50/1,1=7,7273→7,73 ; Coca 1,50/1,1=1,3636→1,36 ; ΣHT 9,09 ; TVA 0,91 ; 9,09+0,91=10,00 | ✓ receipt `_E22-receipt.txt` = DB order_items (0,77+0,14=0,91=orders.total_tax) |
| 4543 (4,80 TTC) | Tiramisu 3,45 HT/0,35 ; Eau 0,91 HT/0,09 ; ΣHT 4,36 ; TVA 0,44 ; rendu 5,00−4,80=0,20 | ✓ receipt `_E31-receipt.txt` = DB = modal frozen |
| 4518 (1,50 TTC) | TVA 0,14 | ✓ |

**Aucune divergence HT/TVA/TTC** — la mécanique fiscale par ligne est saine ; les P0 sont en AMONT (montant promis ≠ montant facturé) et en AVAL (agrégats gérant).

---

## DISPUTES DU FINAL_REPORT 2026-06-11 (`reports/test-e2e/uiux-caisse-borne-2026-06-11/FINAL_REPORT.md`)

| # | Claim | Verdict | Preuve |
|---|---|---|---|
| D1 | « Cycle 2 : P0=0 · P1=0 · P2=0 — production-perfect au sens du GOAL §F » | **REFUTED** | 2 P0 démontrés sur la même branche, sur les surfaces du GOAL (paiement borne E-ADV-1 ; vue caisse gérant E-ADV-2) + 4 P1. Le double cycle n'a jamais recoupé un montant affiché avec la DB ni testé une promo. |
| D2 | « W5 cross-flow : prouvé (commandes borne créées → visibles et encaissées côté caisse, POST 200, file décrémentée, tracker à jour) » | **WEAKENED** | Le happy-path encaissement marche (re-prouvé ici : confirm 200, fiscal 2172, cash_movements +10). Mais « cross-flow prouvé » sans vérification des MONTANTS cross-surface a raté E-ADV-1/2/3 — l'intégrité, pas la plomberie, était la question. |
| D3 | « B-1 statut paiement borne sur show healé (PENDING_COUNTER/REFUNDED mappés, miroir historique) » | **UPHELD** | E20-04 avant : « À Encaisser » + « Comptoir différé » ; E23-04 après : « Payé » + « Espèces », miroir historique cohérent (« À encaisser » → « Payé », N° FISCAL — → 2172). |
| D4 | « A-5 libellé cash-overview “(session en cours)” healé » | **WEAKENED** | Le libellé existe (E20-00, E-RED-02) mais le panneau pointe la session 22 alors que les encaissements vont aux sessions 19/20 → « Espèces encaissées (session en cours): −3,80 € » juste après un +10,00 € (E-ADV-7). Le libellé est juste, le chiffre à côté trompe. |
| D5 | « W2 fix “Client borne” » | **WEAKENED** | Appliqué sur la carte encaissement uniquement ; historique et tracker affichent « Admin Le Cayenne » comme CLIENT de la même commande borne (E-ADV-3). Fix mono-surface d'un défaut cross-surface. |
| D6 | « formats € FR partout (appService Intl fr-FR) » | **UPHELD** | Tous les montants relevés sur ma vague sont au format FR (panier borne, modals, receipts, historique, transactions, cash-overview). Seule exception connue = wizard caisse frozen, déjà gated G4. |
| D7 | « tracker 48h (les actives d'hier survivent à minuit) » | **UPHELD (avec effet pervers documenté)** | Implémenté et observé. Mais il élargit la fenêtre où des N° de file dupliqués inter-dates coexistent sans affichage de date (E-ADV-4). |

## GATES DÉJÀ ARBITRÉS REVUS (non re-comptés)
- « VAT (10%) » DATA — revu sur les 2 receipts, toujours présent (gate G3).
- « Accepter » infinitif — revu (badges show + colonne STATUT historique).
- Dates listes à tirets « 12-06-2026 » — revu (historique, transactions).
- 401 one-shot boot kiosk — revu (console E10/E12), non re-compté ; la variante quote = récupération by-design (cf. claims rejetés).
- Spam log wizard frozen — la WARNING console `[kiosk-wizard.composer] step skipped (viande_2)` ×2 par état relève de la même famille de bruit wizard, non re-comptée.
- Tutoiement cash-overview / « : » orphelin tracker / deep-link « #— » — non re-déclenchés par cette vague.

## NOTES HARNESS (contexte, pas des findings)
- DB partagée entre vagues parallèles : sessions 20/22, refunds TXN-*, A0007+ créés par d'autres vagues. Tous mes recoupements décisifs sont par order_id en DB.
- Token admin partagé → 401 mid-flux (cause d'E-ADV-8 et du run E30 avorté) ; reproduit en prod par expiry TTL.
- Artefacts superviseur ajoutés : `E-RED-01-tracker-topbar`, `E-RED-02-cash-overview-live`, `E-RED-03-transactions-live`, `E-RED-04-encaissement-a0011` (+ `_log-E-RED-verify.txt`), script `tests/e2e/_d1red-E-cross-surface-verify.mjs`.

## SYNTHÈSE

| Sévérité | Count | IDs |
|---|---|---|
| **P0** | **2** | E-ADV-1 (promo borne non persistée), E-ADV-2 (vue caisse unifiée/transactions excluent les ventes POS) |
| **P1** | **4** | E-ADV-3 (identité client borne + cascade wallet admin), E-ADV-4 (N° file dupliqués sans date), E-ADV-5 (« Carte bancaire » pour refund wallet/espèces), E-ADV-6 (« espèces uniquement » faux) |
| P2 | 3 | E-ADV-7 (mauvaise session au panneau), E-ADV-8 (échec 401 sans message d'échec), E-ADV-9 (order_payments → ventilation TPE Z vide) |
| P3 | 2 | E-ADV-10 (attente HH:MM ambiguë), E-ADV-11 (politique KDS contradictoire inter-branches — arbitrage owner) |

**Verdict vague E : RED.** P0+P1 > 0 → loop-blocking. La vague la plus P0-critique du round a tenu sa promesse : les chiffres montrés au client (borne) et au gérant (vue caisse) ne sont pas ceux de la DB.
