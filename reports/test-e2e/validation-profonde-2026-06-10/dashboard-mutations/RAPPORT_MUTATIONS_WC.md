# W-C DASHBOARD-MUTATIONS — Rapport final (GOAL VALIDATION PROFONDE 100%)
2026-06-10 · pilote W-C · cible http://127.0.0.1:8766 (clone jetable `foodking_e2e`)
Spec : `tests/e2e/zz-dashboard-mutations-2026-06-10.spec.js` (serial, 9 tests / 25 étapes)
Anti-interférence : préfixe « E2E-WC » partout ; items 49-59 (pilote W-D, Eau Plate #58) JAMAIS touchés — sanity C8c verte aux 2 cycles.

## Verdict
- **Cycle 1 : 25/25 étapes PASS, 0 FAIL** (9 tests Playwright verts, 4.5 min, exit 0)
- **Cycle 2 (suffixe C2) : 25/25 PASS, 0 FAIL, 100 % attempt-1, 0 console error / 0 pageerror / 0 HTTP≥400** → **cycle-2 identique : OUI**
- Convergence atteinte en 3 itérations de spec (v1 sélecteurs → v2 Promise.all/bucket/modal → v3 requestSubmit/toast), conformément à la boucle max-3 par étape.

## Tableau mutation → statut → preuve
| # | Mutation | Statut C1 | Statut C2 | Preuve (cycle1/ sauf mention) |
|---|----------|-----------|-----------|-------------------------------|
| C1a | Créer item « E2E-WC Item » 5,00 € TVA-10% cat Burgers (UI drawer) | PASS | PASS | `c1a-items-before/after-create.jpg` + DB `items` id price=5.00 tax_id=3 |
| C1b | Éditer prix 5,00 → 6,00 | PASS | PASS | `c1b-item-edited.jpg` + DB price=6.00 |
| C1c | Toggle indisponible (stock/rupture, bucket Burgers) | PASS | PASS | `c1c-stock-before/after-toggle.jpg` (badge RUPTURE) + DB `item_branch_availability` [branch 1, is_available 0] |
| C1d | Supprimer item (soft) | PASS | PASS | `c1d-item-deleted.jpg` + DB `deleted_at` non NULL |
| C1e | Catégorie « E2E-WC Cat » créer → éditer → supprimer (MODAL `#categoryModal`) | PASS | PASS | `c1e-category-created/edited/deleted.jpg` + DB name EDIT puis deleted |
| C2a | Coupon « E2EWC10 » 10 %, dates J→15 du mois suivant | PASS* | PASS* | `c2a-coupon-form-filled.jpg`, `c2a-coupon-created.jpg` + DB coupons discount=10 type=10 ; *via `form.requestSubmit()` — clic souris IMPOSSIBLE (P2 F-WC-01) |
| C2b | Coupon edit remise 10 → 15 | PASS* | PASS* | `c2b-coupon-edited.jpg` + DB discount=15 |
| C2c | Coupon delete (hard) | PASS | PASS | `c2c-coupon-deleted.jpg` + DB count=0 |
| C3a | Employé « E2E-WC Emp » rôle POS Operator | PASS | PASS | `c3a-employee-form.jpg` + DB users + model_has_roles role 7 |
| C3b | Rôle affiché EN FRANÇAIS dans la liste | PASS | PASS | `c3b-employee-list.jpg` → « **Opérateur caisse** » |
| C3c | Edit téléphone → 0688997766 | PASS | PASS | `c3c-employee-edited.jpg` + DB phone |
| C3d | Delete employé (soft) | PASS | PASS | `c3d-employee-deleted.jpg` + DB users.deleted_at |
| C4a | Format horaire 12h → **24h** (DATA-FIX gardé) | PASS | PASS (déjà 24h) | `c4a-site-before/after-24h.jpg` + DB `site_time_format`='H:i' ; a nécessité de remplir copyright+clé maps (P2 F-WC-02) |
| C4b | /admin/historique reste 24h | PASS | PASS | `c4b-historique-24h.jpg` — 0 occurrence AM/PM ; NB : la page rendait DÉJÀ 24h avant le flip (formatter FR global) → check peu sensible au réglage |
| C4c | Company website edit → revert | PASS | PASS | `c4c-company-before/edited/reverted.jpg` + DB reverté `https://lecayenne.fr` |
| C5a | Créer « E2E-WC Stock » | PASS | PASS | DB items id (4,50 €) |
| C5b | Toggle OOS + persistance après reload | PASS | PASS | `c5b-stock-before.jpg`, `c5b-stock-persisted-oos.jpg` + DB is_available=0 |
| C5c | Race F-DASH-2 : toggle puis reload immédiat ×3 | PASS (3/3 persisté) | PASS (3/3) | `c5c-race-1/2/3.jpg` + log : aucun revert observé sur 6 essais cumulés — **lost-update NON reproduit** (3+3 essais ≠ preuve d'absence) |
| C6a | Export historique XLS | PASS | PASS | `export-historique.xlsx` 86 690 o ; sharedStrings : fiscal=true, mots FR (commande, Montant, Statut, Caisse) |
| C6b | Export sales-report XLS | PASS | PASS | `export-sales-report.xlsx` (taille>0, mots FR) |
| C7a | Push notification : formulaire SANS envoi | PASS | PASS | `c7a-push-form-filled.jpg` + DB push count=0 (rien créé) |
| C7b | Messages : liste (aucun thread cliquable — 1 msg DB) | PASS | PASS | `c7b-messages-list.jpg` |
| C8a | Delete stock item via UI | PASS | PASS | DELETE 2xx |
| C8b | Purge SQL E2E-WC + dump restes | PASS | PASS | dump : items 0, cats 0, coupons 0, users 0, push 0, time_format=H:i (24h GARDÉ) |
| C8c | Sanity items 49-59 W-D intacts | PASS | PASS | DB : 11 items 49-59 inchangés (Glace…Capri-Sun) |

## Findings (tous vérifiés file:line + reproduction)
### P2 — F-WC-01 : formulaire Coupon inutilisable à la souris (click-trap invisible)
- **Preuve** : `elementFromPoint` au centre du bouton « Enregistrer » (x724,y509,131×36) = `INPUT.custom-checkbox-field value="kiosk"` (rect x773, **y=0**, w=576) — une checkbox invisible s'étire sur la moitié droite du drawer et intercepte le clic. Log Playwright run-1 : « <input value="kiosk"> intercepts pointer events ».
- **Cause** : `resources/js/components/admin/coupons/CouponCreateComponent.vue:162-184` — checkboxes jours/surfaces avec classe `custom-checkbox-field` SANS wrapper positionné (`.custom-checkbox` relative) ; `resources/css/app.css:437` = `absolute z-10 opacity-0 w-full h-full` → l'input se dimensionne sur l'ancêtre positionné le plus proche (le drawer).
- **Impact réel** : un gérant qui clique « Enregistrer » coche/décoche « kiosk » au lieu de soumettre → création coupon souris impossible (Enter clavier ou requestSubmit OK). Idem en édition.
- **Fix suggéré** : entourer chaque input d'un `<div class="custom-checkbox relative w-4 h-4">` comme partout ailleurs.

### P2 — F-WC-02 : Paramètres/Site non sauvegardable sur l'install V1 (422 systématique)
- **Preuve** : run-1 `site save HTTP 422 {"site_google_map_key":["…obligatoire"],"site_copyright":["…obligatoire"]}` alors que DB `settings` contient ces 2 clés VIDES.
- **Cause** : `app/Http/Requests/SiteRequest.php:40` (`site_google_map_key` required) et `:43` (`site_copyright` required) vs install Le Cayenne livrée avec valeurs vides.
- **Impact** : impossible de changer le FORMAT HORAIRE (mandat FR 24h) ou tout autre réglage site sans inventer une clé Google Maps.
- **Data-fix appliqué (clone e2e, divulgué)** : copyright=`© Le Cayenne`, clé maps=`e2e-clone-placeholder`, puis flip 24h → DB `site_time_format='H:i'` **GARDÉ**. Pour la prod : passer ces 2 règles en `nullable` OU seeder de vraies valeurs.

### P2 (confirmation du divulgué) — F-WC-03 : labels i18n bruts formulaire Coupon — en réalité 13, pas 8
- Scanner DOM (8) : `label.monday_short`→`label.sunday_short` (7) + `label.branch_scope_help`.
- **L'analyse visuelle de `c2a-coupon-form-filled.jpg` en révèle d'autres** : `LABEL.MAX_USES_GLOBAL`, `LABEL.VALID_HOURS_START`, `LABEL.VALID_HOURS_END` (+ `label.valid_days_of_week`, `label.surfaces` plus bas) — manqués par le scan car les titres de champ sont en CSS `uppercase` et `innerText` restitue la casse rendue (leçon : regex minuscules insuffisante).
- **Cause** : clés absentes de `resources/js/languages/fr.json` (grep négatif sur les 13) ; fallback `$t()` echo la clé. `CouponCreateComponent.vue:239-245` a un fallback `|| 'Mon'` qui ne joue jamais ($t retourne la clé, pas une valeur falsy).

### P3 — F-WC-04 : recherche stock/rupture scopée au bucket actif sans l'indiquer
- **Preuve** : capture run-1 `C1c-…-FAIL.jpg` — recherche « E2E-WC Item » avec bucket « Sandwich Cayenne » actif → « Aucun produit ne correspond à votre recherche » alors que l'item existe (bucket Burgers).
- **Cause** : `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue:410-416` — `filteredProducts` filtre uniquement `activeBucket.items`.
- **Impact gérant** : faux « produit introuvable » en pleine rush si la mauvaise catégorie est sélectionnée. Fix : recherche globale cross-bucket ou mention « dans cette catégorie ».

### P3 — F-WC-05 : SiteComponent crash JS si la sauvegarde échoue sans réponse HTTP
- **Preuve** : run final C4a attempt-1 — `net::ERR_EMPTY_RESPONSE` puis pageerror « Cannot read properties of undefined (reading 'data') » ; le gérant n'a AUCUN feedback.
- **Cause** : `resources/js/components/admin/settings/Site/SiteComponent.vue:547` — branche else `alertService.error(err.response.data.message)` sans garde `err.response` (la garde existe ligne 544 pour l'autre branche).

### P3 — F-WC-06 : datepicker Coupon en format US 12h (MM/DD/YYYY, AM/PM)
- **Preuve** : `c2a-coupon-form-filled.jpg` — « 06/10/2026, 11:08 AM » / « 07/15/2026, 11:08 AM ».
- **Cause** : `CouponCreateComponent.vue:66-78` — `<Datepicker … :is24="false">` explicite + pas de `format`/locale FR. Incohérent avec le mandat FR/24h (la LISTE coupons affiche bien « 11:14, 10-06-2026 » FR).

### P3 (cosmétique) — F-WC-07 : libellés divers
- `timeFormatEnum.js` : l'option « 24 Hour (11:9) » n'a pas de zero-pad minutes (les options 12h l'ont) — `resources/js/enums/modules/timeFormatEnum.js:11-14`.
- Liste employés : téléphone rendu « +330699001122 » (indicatif +33 concaténé au 0 national) — affichage E.164 malformé.

### Non-reproduit — F-DASH-2 (race lost-update toggle stock)
6 itérations toggle→reload-immédiat (3 par cycle) : l'état voulu a persisté à chaque fois (UI + DB cohérents). Pas de revert observé sur cette stack (:8766, single worker). Disclosed : échantillon de 6, pas une preuve d'absence sous charge concurrente multi-onglets.

## Notes d'environnement (non-findings)
- ws://127.0.0.1:6001 refusé (soketi down sur l'env e2e) → fallback polling, connu SYNC-WS-01 ; apparu uniquement dans les runs intermédiaires.
- Données dev dans le clone : 19 employés stress-/soak-cashier, 2365 commandes historiques (pilote W-D actif pendant le run — lecture seule de notre côté).

## État final du clone (vérifié SQL)
- 0 reste E2E-WC (items, catégories, coupons, users, push) ; items 49-59 intacts.
- `site_time_format` = **H:i (24h, GARDÉ — data-fix mandat)** ; `company_website` reverté `https://lecayenne.fr` ; copyright/clé maps = valeurs data-fix divulguées ci-dessus.

## Artefacts
- `cycle1/` : 41 fichiers (JPEG q70 + results.jsonl + RESULTS.json + 2 exports xlsx)
- `cycle2/` : idem avec suffixe C2
- Runner logs : 3 itérations (v1/v2/v3) — les FAIL intermédiaires et leurs captures `*-FAIL.jpg` du run v2 sont retracés dans ce rapport (F-WC-01/02/04 découverts par ces échecs).
