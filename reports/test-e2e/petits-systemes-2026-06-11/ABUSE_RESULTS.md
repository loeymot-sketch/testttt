# ABUSE RESULTS — vague #14 petits systèmes (2026-06-11)

> Harnais : :8767 / foodking_e2e (clone jetable). Agent abuse-test. Append-only, une ligne par étape.

| Étape | Verdict | Détail |
|---|---|---|
| offers-create | FAIL | ligne visible=false ; 403 POST /api/admin/offer / [error] Failed to load resource: the server responded with a status of 403 (Forbidden); [error] TypeError: Cannot read properties of undefined (reading 'name')     at Proxy.<anonymous> (http://127.0.0.1:8767/js/admin-shell.js:2:1161772)     at gn (http://127.0.0.1:8767/js/app.js?id=73fc99bbb8fd77d7cfb8b7d31658dc6c:2:136167)     at d.fn (http:// |
| offers-delete | FAIL | row-not-found ; aucun HTTP>=400 / console propre |
| offers-create (requalifié) | FINDING P2 | 403 = guard INTENTIONNEL V1 (OfferController: features.offers_enabled=false, "module Offres désactivé"). MAIS l'UI montre quand même « Ajouter Une Offre » + drawer complet, et le handler front crashe sur le 403 (TypeError reading 'name', admin-shell.js) → aucun message propre à l'utilisateur. Captures 06-offers-before/filled/after-save.png |
| offers-delete (requalifié) | OK (N/A) | row-not-found attendu : la création est bloquée par design, liste vide « Aucune donnée disponible » — pas un bug delete |
| ingredients-probe | OK | create UI présent=true (page = liste dispo/usage, pas de CRUD ingrédient) ; boutons=[Filiale Le Cayenne (Principal), Bonjour Admin Le Cayenne, Déconnexion, CAISSE ET COMMANDES, COMMUNICATIONS, UTILISATEURS] ; aucun HTTP>=400 / console propre |
| dining-tables-create | OK | ligne visible=true ; aucun HTTP>=400 / console propre |
| dining-tables-delete | OK | deleted ; aucun HTTP>=400 / console propre |
| ingredients-probe (requalifié) | OK (N/A CRUD) | Page Ingrédients = catalogue READ-ONLY d'utilisation (44 entrées, onglets Tous/Viandes/Suppléments/Add-ons, drawer « Utilisation de l'ingrédient » fonctionne, ex. Viande 1 → Big Classique). Aucun bouton create/edit/delete par design → le scénario create/rename/delete ne s'applique pas. 0 erreur console/HTTP. Captures 06-ingredients-list/drawer.png |
| employees-create | FAIL | visible=false ; 422 POST /api/admin/employee / [error] Failed to load resource: the server responded with a status of 422 (Unprocessable Content) |
| employees-delete | FAIL | skipped: create failed |
| chefs-create | FAIL | visible=false ; 422 POST /api/admin/chef / [error] Failed to load resource: the server responded with a status of 422 (Unprocessable Content) |
| chefs-delete | FAIL | skipped: create failed |
| employees-create (requalifié 1er essai) | OK (validation correcte) | 422 = MA faute de script : « mot de passe doit contenir au moins 12 caractères » + « Le champ rôle est obligatoire » affichés proprement en FR sous les champs (capture 06-employees-after-save.png). Pas un bug produit — retry ci-dessous |
| chefs-create (requalifié 1er essai) | OK (validation correcte) | 422 idem : password min 12 affiché proprement FR (06-chefs-after-save.png). Retry ci-dessous |
| employees-create-retry | FAIL | script error: TimeoutError: locator.click: Timeout 30000ms exceeded. Call log: [2m  - waiting for locator('#role_id')[22m  ; aucun HTTP>=400 / console propre |
| chefs-create-retry | OK | visible=true ; aucun HTTP>=400 / console propre |
| chefs-delete | OK | deleted ; aucun HTTP>=400 / console propre |
| employees-create-retry2 | OK | visible=true ; aucun HTTP>=400 / console propre |
| employees-delete | OK | deleted ; aucun HTTP>=400 / console propre |
| messages-list | OK | empty-state: aucun texte vide trouvé ; aucun HTTP>=400 / console propre |
| subscribers-export | OK | export cliqué (Imprimer) ; download=non-intercepté ; resp export=[aucune url /export] ; aucun HTTP>=400 / console propre |
| push-modal-no-send | OK | modal rempli puis FERMÉ sans envoi ; garde/confirm visible dans le modal=false ; aucun HTTP>=400 / console propre |
| transactions-filter | OK | lignes avant=10 après filtre date 2026-06-11=10 ; aucun HTTP>=400 / console propre |
| messages-list (requalifié) | OK | PAS un empty-state : UI chat fonctionnelle — 3 fils clients (Client Passage, E2E Client EDIT ×2), conversation affichée (« central-dash-vis test message »), recherche client présente. 0 erreur. Capture 06-messages-list.png |
| messages-format-heure | FINDING P3 | Horodatage chat « 11:23 PM, 10-06-2026 » = format EN (AM/PM) sur UI FR — idem transactions (« 01:25 AM, 11-06-2026 », col DATE, 06-transactions-filtered.png). Attendu FR 23:23 / 01:25 |
| transactions-filter (requalifié 1er essai) | FAIL (script) | Le panneau Filtrer s'ouvre (champs ID/N° commande/mode paiement/DATE) mais mon fill date n'a pas pris : lignes du 10-06 toujours présentes après Rechercher → retry ci-dessous avec preuve URL requête |
| transactions-filter-retry | FAIL | requête=[http://127.0.0.1:8767/api/admin/transaction?paginate=1&page=1&per_page=10&order_column=id&order_type=desc&branch_id=1] ; lignes 10-06 présentes=true, 11-06 présentes=true ; aucun HTTP>=400 / console propre |
| transactions-filter-retry2 | OK | requête=[http://127.0.0.1:8767/api/admin/transaction?paginate=1&page=1&per_page=10&order_column=id&order_type=desc&branch_id=1&from_date=2026-06-11T05:37:00.000Z&to_date=2026-06-11T05:37:00.000Z] ; lignes 10-06=false, 11-06=true ; aucun HTTP>=400 / console propre |
| coupon-abuse-a-create-valide | FAIL | E2EABUSE1 visible=false ; 422 POST /api/admin/coupon / [error] Failed to load resource: the server responded with a status of 422 (Unprocessable Content) |
| coupon-abuse-b-code-duplique | FAIL | script error: TimeoutError: locator.click: Timeout 30000ms exceeded. Call log: [2m  - waiting for getByRole('button', { name: /Ajouter Un Coupon/i })[22m [2m    - locator resolved to <button  |
| coupon-abuse-c-remise-negative | FAIL | script error: TimeoutError: locator.click: Timeout 30000ms exceeded. Call log: [2m  - waiting for getByRole('button', { name: /Ajouter Un Coupon/i })[22m [2m    - locator resolved to <button  |
| coupon-abuse-d-dates-inversees | FAIL | script error: TimeoutError: locator.click: Timeout 30000ms exceeded. Call log: [2m  - waiting for getByRole('button', { name: /Ajouter Un Coupon/i })[22m [2m    - locator resolved to <button  |
| coupon-abuse-cleanup | FAIL | delete E2EABUSE1=row-not-found ; coupons fantômes (auraient dû être rejetés)=[aucun] ; aucun HTTP>=400 / console propre |
| coupon-abuse-a-create-valide-retry | OK | E2EABUSE1 visible=true ; aucun HTTP>=400 / console propre |
| coupon-abuse-b-code-duplique | OK | HTTP=[422 POST /api/admin/coupon] ; messages visibles=« La valeur du champ code est déjà utilisée. // IDs de filiales séparés par des virgules (vide = toutes). » |
| coupon-abuse-c-remise-negative | OK | HTTP=[422 POST /api/admin/coupon] ; créé-à-tort=false ; messages=« La valeur de discount doit être supérieure ou égale à 0. // IDs de filiales séparés par des virgules (vide = toutes). » |
| coupon-abuse-d-dates-inversees | OK | HTTP=[422 POST /api/admin/coupon] ; créé-à-tort=false ; messages=« End date can't be older than Start date. // IDs de filiales séparés par des virgules (vide = toutes). » |
| coupon-abuse-cleanup | OK | delete E2EABUSE1=deleted ; fantômes=[aucun] ; aucun HTTP>=400 / console propre |
| coupon-i18n-dates | FINDING P3 | Message d'erreur dates inversées HARDCODÉ EN ANGLAIS : « End date can't be older than Start date. » (CouponRequest::withValidator, app/Http/Requests/CouponRequest.php:96-103) alors que doublon et négatif sont bien FR. Capture 06-coupon-d2-dates-inversees.png |
| historique-filtre-jour | OK | filtre appliqué ; lignes=1 ; aucun HTTP>=400 / console propre |
| historique-detail-totaux | FAIL | montants non extraits (sub=null, tot=null) — voir capture ; aucun HTTP>=400 / console propre |
| historique-detail-totaux-retry | OK | commande #SUP-LOY-1 : sous-total=8.5 − remise=1 = 7.5 vs total affiché=7.5 → cohérent=true ; aucun HTTP>=400 / console propre |
| push-mass-send-guard (statique) | OK | Garde de masse CONFIRMÉE dans le code : `appService.confirmation("Cette notification sera envoyée immédiatement…", "Envoyer la notification ?")` avant tout envoi (PushNotificationCreateComponent.vue:169-176). Non déclenchée pendant le test (envoi volontairement évité, modal fermé proprement — 06-push-modal-filled/closed.png) |

## Synthèse (fin vague #14 — 2026-06-11)

**Bilan : 18 OK / 0 FAIL produit bloquant / 3 findings réels (1 P2, 2 P3).** Tous les FAIL bruts en cours de route étaient des erreurs de MON script (datepicker readonly/range, vue-select sans id DOM, password <12, rôle non choisi) — requalifiés ligne à ligne ci-dessus ; les 422 rencontrés étaient des validations CORRECTES affichées proprement (leçon agent précédent appliquée).

### Couverture
- offers : create→403 by design (module désactivé V1), delete N/A — capture ×4
- ingredients : page read-only par design (catalogue d'utilisation, drawer OK) — CRUD N/A
- dining-tables : create E2E-T99 + delete ✅
- employees : create (password 12+, rôle via vue-select) + delete ✅ ; chefs : create + delete ✅
- messages : UI chat fonctionnelle (3 fils, conversation, recherche) ✅
- subscribers : liste + dropdown Exporter (option « Imprimer » cliquée, 0 erreur réseau) ✅
- push-notifications : modal rempli puis FERMÉ sans envoi ; garde confirm prouvée dans le code ✅
- transactions : filtre date du jour PROUVÉ au niveau requête (`from_date/to_date=2026-06-11`) + liste purgée des lignes 10-06 ✅
- coupons ABUSE : doublon code→422 FR propre ; remise −5→422 FR propre (« supérieure ou égale à 0 ») ; dates inversées→422 ; cleanup E2EABUSE1 supprimé, 0 fantôme ✅
- historique : filtre jour (1 ligne) + détail #SUP-LOY-1 : 8,50 − 1,00 = 7,50 € affiché → cohérent ✅

### Findings réels (preuves citées)
| ID | Sév | Finding | Preuve |
|---|---|---|---|
| AB14-01 | P2 | Module Offres désactivé V1 côté backend (403 intentionnel, OfferController.php:36-43 `features.offers_enabled`) mais l'UI affiche « Ajouter Une Offre » + drawer complet ; au save le front CRASHE sur la forme du 403 (`TypeError: Cannot read properties of undefined (reading 'name')`, admin-shell.js) → l'utilisateur remplit tout et n'a AUCUN feedback | `06-offers-before/filled/after-save.png` + `403 POST /api/admin/offer` |
| AB14-02 | P3 | Horodatage format EN (AM/PM) sur UI FR : chat Messages « 11:23 PM, 10-06-2026 » et Transactions col DATE « 01:25 AM, 11-06-2026 » (attendu 23:23 / 01:25) | `06-messages-list.png`, `06-transactions-filtered.png` |
| AB14-03 | P3 | Erreur coupon dates inversées hardcodée EN « End date can't be older than Start date. » (CouponRequest::withValidator ~:96-103) alors que doublon/négatif sont FR | `06-coupon-d2-dates-inversees.png` + `422 POST /api/admin/coupon` |

Confirme aussi PS-01 (vague précédente) : attributs de validation coupon mi-traduits (« Le champ start date est obligatoire ») revus sur `06-coupon-a-valid-after-save.png` (1er essai).
