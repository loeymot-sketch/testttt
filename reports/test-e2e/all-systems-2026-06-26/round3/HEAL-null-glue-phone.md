# HEAL — Classe « null-glue » téléphone (`country_code + '' + phone` → « null60000993 »)

**Date** : 2026-06-26 · **Round** : 3 · **Type** : non-frozen · **Statut** : VERT

## Bug
En JS, `null + '' + phone` rend littéralement la chaîne « null » collée au numéro
(« null60000993 ») dès que `country_code` est `null`. Vu live sur `/admin/customers`.
**Fix** : `(X.country_code || '') + X.phone` — un `country_code` null/undefined devient
`''` au lieu de fuir « null ». Le guard `phone ? ... : ''` et la fonction `safePhone(...)`
existants sont préservés.

## Fichiers healés — 17 (la CLASSE complète, pas seulement les 15 listés)
Le brief listait 15 occurrences mais titrait « 16 ». Grep repo-wide a confirmé la classe
réelle = **17 occurrences buggées** (cohérent avec le record mémoire « 17 comps »). Les 2
jumeaux omis du brief (`AdministratorShowComponent`, `EmployeeShowComponent`) sont healés
pour fermer la classe (lentille jumeau-systémique).

| # | Fichier:ligne | Variante |
|---|---|---|
| 1 | `resources/js/components/admin/customers/CustomerListComponent.vue:94` | simple `''` |
| 2 | `resources/js/components/admin/customers/CustomerShowComponent.vue:89` | simple `''` |
| 3 | `resources/js/components/admin/waiters/WaiterListComponent.vue:105` | simple `''` |
| 4 | `resources/js/components/admin/waiters/WaiterShowComponent.vue:89` | simple `''` |
| 5 | `resources/js/components/admin/chefs/ChefListComponent.vue:105` | simple `''` |
| 6 | `resources/js/components/admin/chefs/ChefShowComponent.vue:89` | simple `''` |
| 7 | `resources/js/components/admin/deliveryBoys/DeliveryBoyShowComponent.vue:87` | simple `''` |
| 8 | `resources/js/components/admin/administrators/AdministratorListComponent.vue:108` | simple `''` |
| 9 | `resources/js/components/admin/administrators/AdministratorShowComponent.vue:88` | **jumeau hors-liste** · multi-ligne |
| 10 | `resources/js/components/admin/employees/EmployeeListComponent.vue:113` | multi-ligne (`}}` ligne suiv.) |
| 11 | `resources/js/components/admin/employees/EmployeeShowComponent.vue:89` | **jumeau hors-liste** |
| 12 | `resources/js/components/admin/creditBalanceReport/CreditBalanceReportComponent.vue:89` | **safePhone** |
| 13 | `resources/js/components/admin/tableOrders/TableOrderShowComponent.vue:246` | **double quotes `""`** · multi-ligne |
| 14 | `resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue:292` | multi-ligne |
| 15 | `resources/js/components/admin/onlineOrders/OnlineOrderReceiptComponent.vue:163` | **safePhone** |
| 16 | `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:331` | multi-ligne |
| 17 | `resources/js/components/frontend/account/myOrder/FrontendOrderReceiptComponent.vue:175` | **safePhone** (pas de guard `phone ?`) |

Transform appliqué partout : `X.country_code + '' +` (ou `+ "" +`) → `(X.country_code || '') +`.
Pour les variantes safePhone : `(X.country_code || '') + safePhone(X.phone)` — safePhone conservé.
Guard simple `+ ''` → `|| ''` uniformisé (single-quote), aligné sur les 2 frères DÉJÀ healés.

## Variantes gérées
- **safePhone** (3) : CreditBalanceReport, OnlineOrderReceipt, FrontendOrderReceipt — safePhone gardé intact.
- **double-quotes** (1) : TableOrderShow (`+ "" +`).
- **multi-ligne** (5) : Employee/Online/Pos/AdministratorShow + TableOrder (le `}}`/`: ''` sur ligne suivante) — Read du contexte exact avant édition.

## Hors-scope respecté
- ⛔ `CustomerCreateComponent.vue:42` **NON touché** — vérifié : `{{ props.form.country_code }}` seul (sélecteur d'input, pas du glue).
- Déjà guardés (laissés tels quels) : `DeliveryBoyListComponent.vue:95` + `BackendNavbarComponent.vue:142` (ont déjà `(country_code || '')`).

## Gates
- **Grep post-fix** : `0` occurrence buggée restante (`country_code + ''` ET `country_code + ""` côté phone = NONE).
- **Frozen-diff = 0** : `git diff --stat` sur pos-wizard.js/css, admin-pos-v4.blade, PaymentComponent, PosV5TrancheRow, 3 kiosk = vide.
- **Diff total** : 17 fichiers, 17 insertions, 17 deletions (1 ligne/fichier, scope-minimal).
- **Vitest sentinels** (hors freshness, rebuild superviseur) : **50 fichiers / 389 tests PASS**, 0 régression.
- **Specs dédiées** : aucune spec ne pinglait l'ancien comportement (pas de `phoneDisplay.spec`/`customerList.spec`) → rien à créer/mettre à jour. Seules les sentinelles de fraîcheur bundle référencent `country_code` (exclues).

## Non committé (per discipline). Bundle à rebuild par le superviseur (freshness sentinels).
