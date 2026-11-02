# B — Règlement à la porte en plusieurs moyens, « Ma caisse » du livreur et clôture (2026-11-02)

Branche `feat/livreur-caisse-split-2026-11-02` (worktree `wt-livraison-qr`, base 7912b7309). Rien poussé, rien déployé, aucune production touchée.

## Ce qui est fait
1. **Règlement en plusieurs moyens** (`payments=[{mode: cash|card|meal_voucher, amount}]`, 1 à 4 tranches), sur `deliver` (`DeliveryRunController.php`) ET sur la confirmation du QR (`DeliveryHandoverController::confirm` → `DeliveryHandoverService::confirmLocked`).
   - Validation pure `app/Services/Delivery/DoorPaymentSplit.php` (somme = total SERVEUR au centime, ≥ 0,01 €, 2 décimales, un moyen une fois, ≤ 4, 422 en français) ; refaite SOUS le `lockForUpdate` de la commande dans `OrderService::deliveryBoyOrderChangeStatus` (nouveau 4ᵉ paramètre `?array $doorPayments`, bloc de validation puis branche `$doorSplitTranches !== null`, lignes `order_payments` créées dans la même transaction que PAYÉ + numéro fiscal, qui sont inchangés).
   - Espèces → UN `DeliveryBoyCashMovement` ENTRÉE du montant espèces + audit `delivery.cash_collected_escrow` ; carte / titre → `order.payment_tranche_persisted` par tranche (`tranche_index` = position dans les lignes `order_payments`) ; `declared_differs` / `chosen_mode` / `tranches` dans les payloads. `pos_payment_method` = moyen dominant (convention du comptoir, `PosOrderRequest` « mode dominant »). `ZReportService` non touché.
   - Sans `payments` : chemin historique identique (les 293 tests Delivery d'avant restent verts). Seul ajout au chemin historique : carte et titre encaissés ouvrent aussi le service du livreur (`ensureOpenDeliveryShift`, helper extrait de l'ancien bloc espèces).
   - QR : `declared_mode='split'`, `mode_mismatch=true`, `delivery.handover_validated.payments`.
2. **Écran `/livreur`** (`resources/views/livreur.blade.php`) : feuille de règlement (Espèces / Carte / Titre-restaurant / En plusieurs fois, total non modifiable, « Reste à encaisser : X € » en direct, Valider grisé tant que somme ≠ total, bouton « Reste »), accessible par « Autre moyen · en plusieurs fois » ; « Remis au client » historique conservé (les 4 specs Playwright anciens qui cliquent `button[data-deliver]` restent valides). Page QR (`livreur-remise.blade.php`) : même saisie. Erreurs réseau : message français, même clé d'idempotence rejouée.
3. **Ma caisse** : onglet de `/livreur` ; `GET /api/frontend/delivery-run/cash`, `POST …/cash/close` (`DeliveryRunCashController.php`, `DeliveryCashRecapService.php`, routes `routes/api.php`, limiteurs `delivery-run-cash*` dans `RouteServiceProvider.php`, `config/idempotency.php`). Récap sans nouvelle colonne. Aucune migration.
4. **Administration** : `DeliveryBoyCashSessionResource.recap` + `DeliveryBoyCashSessionShowComponent.vue` (fond de début, par moyen, attendues, comptées, écart ; libellés fr/en dans `resources/js/languages/*.json` et `lang/*/all.php`).
5. `docs/LIVRAISON.md` §9.

## Tests exécutés dans cette session
- PHPUnit : `tests/Feature/Delivery` **293 → 347 OK** (2329 assertions) : +33 `DeliveryDoorSplitTest`, +21 `DeliveryRunCashTest`. 
- Autres suites rejouées : `Fiscal` 333 OK (8 ignorés) · `Auth` 146 OK · `Branch` 20 OK · `Hardware` (dont `ClientTicket*`) 221 OK · `Admin/DeliveryBoyCashSessionControllerTest` 18 OK · `ClientTicketDeliveryQrTest` 11 OK.
- `Sentinels` : 371 tests, **6 échecs, tous étrangers au changement** (rejoués seuls) : 4 sentinelles exigent des fichiers `plans/*` absents du checkout partiel (sparse) ; `WithoutGlobalScopesAuditSentinelTest` (2) cite `ReviewAccountCommand.php` et `UberOrderIngestor.php:47`, fichiers non touchés (aucun `withoutGlobalScopes()` pluriel dans mon code).
- Zones gelées : `git diff 7912b7309 -- <zones §7>` = 0 ligne.
- Mutation PHPUnit : somme non vérifiée → 5 échecs ; code restauré.
- Vitest : `deliveryBoyCashSessionRecap.spec.js` 4 verts + sentinelle boutons cash 6 verts. 7 sentinelles « bundle … exists/fresh » + `KeyboardNavigationSentinel` échouent dans cet arbre : `public/js/*.js` / `public/css/app.css` non compilés (étranger au changement).
- Banc navigateur `tests/Playwright/livreur-caisse-split/run.js` (Chromium 390×844, serveur PHP de CET arbre, base SQLite jetable) : **77 preuves OK, 0 KO** (`bench-run.log`). Parcours : connexion e-mail+mot de passe (mauvais mot de passe d'abord) → tournée → « Je pars » → arrivée+photo → remise espèces seules (bouton historique) / carte seule / titre seul / 3 moyens → Ma caisse (fond 40, espèces 37,50, carte 37,50, titres 35,00, attendues 77,50) → livreur B isolé → QR en plusieurs fois → refus serveur (somme fausse, doublon, 0) sans écriture → clôture refusée tant que 3 livraisons en route → réseau coupé puis rétabli (1 seule ligne) → clôture avec écart −2,50 € (fond 40, espèces 97,50, carte 87,50, titres 35,00, total 220,00, attendues 137,50, comptées 135) → rejeu idempotent → rechargement → base (8 livrées/payées, 8 numéros fiscaux distincts, 10 lignes de paiement, 5 mouvements = 97,50 €, chaîne NF525 `verifyChain` = null) → fiche administration via l'API.
- **Le banc mord** (`bench-mord.log`) : mutant serveur (somme non vérifiée) → `G1 … « 200 »` KO, exit 1 ; code restauré → OK. Mutant écran (Valider actif malgré un reste) → `5a` KO. 
- Course réelle (`--only=race`, 3 POST simultanés, clés différentes) : 1 succès, 2 refus ; base : 1 mouvement, 1 escrow, 1 tranche, 1 numéro fiscal. Une exécution a vu un 500 sur l'un des refus (verrou SQLite probable) : aucun doublon.

## Captures LUES (reports/test-e2e/livreur-caisse-split-2026-11-02/captures/)
03-tournee-en-route (8 cartes, « Autre moyen · en plusieurs fois » sous chaque), 05-feuille-plusieurs-fois (total 27,50 €, 10/10/7,50, « Reste à encaisser : 0,00 € — c'est le compte », Valider vert), 12-hors-ligne (message rouge, feuille ouverte), 13-ma-caisse-complete, 14-cloture-ecart (−2,50 € en rouge, « Il manque 2,50 € »), 15-cloture-recap-final, 09/10 (QR). Défaut vu et corrigé : « 137,50 € » passait à la ligne dans l'encadré des espèces attendues (nowrap). Défauts mineurs restants : le symbole € peut passer à la ligne dans le bandeau « Réglé en plusieurs fois » de la page QR (pas d'espace insécable) ; la capture pleine page 15 montre l'en-tête collant au milieu (artefact de capture).

## Résidus honnêtes / non vérifié
- Écran d'administration (Vue) : composant testé (Vitest) mais JAMAIS vu dans un navigateur (assets non compilés dans l'arbre). L'API est prouvée par le banc.
- MySQL de production : toutes les preuves tournent sur SQLite (banc) et SQLite mémoire (PHPUnit). Course à deux processus : sous MySQL non rejouée.
- Le mouvement de caisse livreur reste écrit « au mieux » après COMMIT (comme avant) ; une clôture simultanée peut laisser un encaissement sans mouvement : `ledger_gap` le montre (testé en Vitest, pas provoqué en bout en bout).
- Carte/titre-restaurant : déclaratifs (aucun TPE piloté).
- `pos_payment_method` = moyen dominant : le Z range la commande entière sous ce moyen (même limite que le comptoir).
- Le service clôturé par le livreur reste `closed` (pas `reconciled`) : l'administration réconcilie.
- Un commit étranger `0dd408774` (PosOrdersTrackerComponent, compositionCommande) s'est posé sur ma branche pendant la session ; non touché par moi.
