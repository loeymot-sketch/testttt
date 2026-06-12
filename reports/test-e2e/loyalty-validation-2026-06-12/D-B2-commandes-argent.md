# Lane D-B2-commandes-argent — journal au fil de l'eau (2026-06-12)

Harnais : :8767 / foodking_e2e. TIME_FORMAT e2e = H:i, DATE_FORMAT = d-m-Y (confirmé .env.e2e).
Pages : pos-orders, pos-orders-tracker, online-orders, table-orders, historique, encaissement, cash-overview, cash-sessions-report, credit-balance-report, transactions.

## Étape 0 — setup
- lib.cjs lu. Piège harnais : token orchestrateur = undefined → 1er sweep entier bounce /login (401 default-access). Root cause vérifiée : `app/Http/Controllers/Auth/LoginController.php:155` supprime tous les tokens name='auth_token' à chaque login → le token curl meurt dès que uiLogin tourne. Fix lane : token tinker name='audit-lane-db2' (immune à la révocation login). PAS un bug produit — design volontaire anti token-sprawl (§9 CLAUDE.md).
- Playwright headless-shell absent du cache (chromium_headless_shell-1208) → installé via npx playwright install chromium-headless-shell.
- Ground truth DB e2e : orders total=2398 ; orders.source hétérogène {"10":738,"4":1,"1":1373,"":6,"15":60,"5":205,"mobile":1,"POS":14} (pollution de data e2e probable, à vérifier si une page filtre par source) ; transactions=748 ; cash_drawer_sessions=19.

## Étape 1 — sweep 10 pages (load + 1 interaction + captures)
(en cours, résultats ci-dessous)

## Étape 1 — sweep 10 pages : VERDICT
Toutes les 10 pages chargent : 0 console error, 0 HTTP>=400, au load ET après interaction th-click. Captures D-B2-<page>.png lues et analysées.
- pos-orders : table FR propre (7,50 € / 23:54, 10-06-2026 / badges FR), "1 à 10 sur 60" = cohérent DB source 15 (60 rows). Pagination visible.
- pos-orders-tracker : kanban cards, empty-states FR propres, "0 actives / 4 aujourd'hui" — scope = today (PosOrdersTrackerComponent.vue:651 todayCount=orders.length + :759 _todayRange) → cohérent.
- online-orders : **MONTANT brut "7.00" sans € ni virgule** (OnlineOrderListComponent.vue:119 `order.total_amount_price` = flatAmountFormat) alors que PosOrderListComponent.vue:132 a été healé WT-D-R1-F4 vers formatPrice(order.total). + **badge STATUT "Accepter"** (verbe, fr.json:859 label.accept partagé bouton/statut).
- table-orders : empty-state FR propre ("Aucune donnée disponible." + illustration). Dine-in désactivé V1 → attendu.
- historique : propre, 2389 entrées, money/dates FR OK, N° fiscal affiché.
- encaissement : 51 en attente / 251,40 €, cards FR, badges âge "48h49" (commandes test du 10-06). Contraste avec tracker "À ENCAISSER 0" = différence de scope today vs all-time (pas un bug, page dédiée = vérité).
- cash-overview : cards 0,00 €/0 tx (du=au=today) MAIS "Espèces encaissées aujourd'hui: 86,00 €" — root-cause CashOverviewController.php:228-249 (CASH-JOIN-01: cash_collected = Σ mouvements DE LA SESSION ouverte, session #20 ouverte 10-06 22:46) vs label `label.cash_collected_today` (CashOverviewComponent.vue:144). Empiriquement: mouvements du 12-06 = 4,5 € ≤ 86 €. Label périmé, calcul correct.
- cash-sessions-report : groupes par jour FR ("mercredi 10 juin 2026"), écarts rouges 2,00 €, statuts FR. 2 sessions "Ouverte" simultanées (#19 Admin 19:53, #20 Caissier 22:46) = sessions par caissier, design plausible.
- credit-balance-report : 3 clients, 0,00 €, FR propre.
- transactions : 744 affichées vs 748 DB → **expliqué** : 4 transactions orphelines (order_id 4284-4287 supprimés, test purge 08-06) exclues par whereHas('order') TransactionService.php:36-41. Comptage page VÉRACE pour son filtre. Note data: orphans = pollution e2e.
- Latence : full-reload 4,4-5,9s par page (SPA boot app.js 2,2 Mo non-minifié dev) ; API list = 34-150 ms. Navigation in-SPA rapide.
- Inputs date "mm/dd/yyyy" sur cash-overview/cash-sessions = artefact harnais (Chrome headless locale en-US, input natif type=date) — PAS un bug produit.
- 0 raw label, 0 AM/PM, 0 "0undefined", 0 mot EN détecté sur les 10 pages.

## Étape 2 — interactions réelles (pagination/filtre/recherche/modal) : en cours

## Étape 2 — interactions réelles : VERDICT
- Pagination page-2 : FONCTIONNE sur pos-orders / online-orders / historique / transactions (first-row change prouvé + "Affichage de 11 à 20"). 0 erreur console/réseau.
- Filtre pos-orders : panneau s'ouvre (N° commande / Statut / Client / Date + Rechercher/Effacer) — capture D-B2-pos-orders-filter.png.
- Tracker : recherche "A0009" filtre réellement (12 actives → 0 actives), tabs cliquables, "19 aujourd'hui" = DB 19 (même seconde). VÉRACE.
- Encaissement : badge 53→55 suit la DB en continu ; même-seconde API=55 = DB=55, somme 272,50 € = 272,50 €. VÉRACE. Modal unifié "Encaisser la commande Borne" (Espèces/Terminal manuel/Mobile/Ticket resto + pavé) s'ouvre/se ferme proprement. Touches ⌫/C doublées = span émulé INTENTIONNEL avec aria (PosV5Numpad.vue:27-28,53-65) — pas un défaut.
- cash-overview plage 01→12/06 : recherche 626 ms, GRAND TOTAL 17 563,90 € (584 tx) ; CAISSE 32 + BORNE 17 531,90 = total exact ; répartition par mode somme exacte (542+15+18+7+2=584 ; 17 228,70+93,20+160+78+4=17 563,90). Widget rouge "488 encaissements espèces sans session caisse / 16 631,00 €" = surfacing correct de la pollution data e2e (bon comportement produit). "Espèces encaissées aujourd'hui: 90,50 €" = Σ session #20 mesurée en DB (90,5) → preuve label session-scope.
- cash-sessions-report plage : 5 groupes-jours FR rendus (12/10/09/08/07 juin).
- credit-balance : 3 lignes = 3 customers DB. Exporter table-orders : dropdown Imprimer/XLS s'ouvre.
- table-orders : RAS (vide attendu, dine-in off V1).

## Étape 3 — latence (mesure réelle)
- Full reload content-ready (1re ligne de table visible) : pos-orders 4320 ms, transactions 3996 ms, historique 3966 ms, cash-overview 3938 ms. Bundle app.js MINIFIÉ 2,2 Mo (prod-like, mix-manifest versionné).
- in-SPA nav → Historique : 16 ms. API lists : 34-150 ms.
→ P2 (lentille ④) scope = hard-load/refresh uniquement ; usage caisse réel = SPA ouverte.

## FINDINGS (4)
1. P3 — online-orders MONTANT "7.00" US sans € — OnlineOrderListComponent.vue:119 (`total_amount_price`/flatAmountFormat) ; POS healé WT-D-R1-F4 (PosOrderListComponent.vue:120-132 formatPrice) mais pas online. Captures D-B2-online-orders.png.
2. P3 — badge STATUT "Accepter" (verbe) — fr.json:859 label.accept partagé bouton/statut, OnlineOrderListComponent.vue:125+244. + uniformité mineure type "POS" vs origine "Caisse".
3. P3 — label "Espèces encaissées aujourd'hui" inexact si session multi-jours — CashOverviewComponent.vue:144 vs CashOverviewController.php:228-249 (session-scope, heal CASH-JOIN-01 correct). Prouvé : mouvements 12-06 = 4,50 € vs widget 86→90,50 €.
4. P2 — hard-load ~4 s toutes pages admin (bundle 2,2 Mo) ; in-SPA 16 ms, API <150 ms.

DEDUP-suspect : sweep FR-money dashboard-deep 06-08 (autre branche) — l'occurrence online-orders persiste ICI ; "tracker skeleton" P3 connu fix-campaign : NON reproduit (tracker fonctionnel).
Hors findings : 4 transactions orphelines (orders 4284-4287 purgés 08-06) = pollution data e2e, exclues honnêtement par whereHas (TransactionService.php:36-41).
