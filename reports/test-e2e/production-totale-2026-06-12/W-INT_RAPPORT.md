# W-INT — Rapport d'intégration `release/v1-integration-2026-06-12`
2026-06-12 · Intégrateur W-INT · BASE_SHA pinné `120597bc7`

## Résumé
3 merges exécutés dans l'ordre du plan (W4 → clients-next → cms-spine), tous les
conflits résolus selon les arbitrages pré-tranchés §0.4, acceptance ciblée 100 %
verte, frozen-diff **0 ligne sur les 15 fichiers §7**, chaîne NF525 attestée
avant ET après (CHAIN OK, historique croissant only). Rebuild Mix + suites FULL
**DÉFÉRÉS sur contrainte disque** (1,1 Gi libres — gate G-DISK owner non franchi),
manifest de reprise consigné dans `EXECUTION_STATUS.md`.

## Commits produits
| SHA | Contenu |
|---|---|
| `e28cba39b` | merge T-INT.2 `heal/ultra-audit-w4-2026-06-11` (HEAD source `da32af6b9`) |
| `9fbef73c9` | merge T-INT.3 `heal/clients-next-2026-06-10` (HEAD source `c10320958`) |
| `168064249` | merge T-INT.3bis `goal/cms-gestion-2026-06-10-spine` (HEAD source `02042a687`) |
| (final) | docs W-INT : EXECUTION_STATUS + ce rapport |

## Baseline / POST chaîne NF525 (T-INT.1b / T-INT.5b)
- AVANT : `APP_ENV=e2e php artisan fiscal:verify-chain --all` → `branch=1 CHAIN OK / SWEEP COMPLETE — CHAIN OK on every active branch (1 total)` ; `SELECT COUNT(*), MAX(id) FROM audit_logs` → **3718 / 3722**
- APRÈS : même sortie CHAIN OK ; **3724 / 3728** — delta +6 = append-only légitime (logins artisan tinker + créations comptes dédiés), aucun historique réécrit.

## T-INT.2 — merge W4 : arbitrages conflit par conflit
| Fichier | Arbitrage appliqué |
|---|---|
| `public/js/{admin-reports,admin-shell,kiosk-shell,manifest,pos-app,pos-shell}.js` + `public/mix-manifest.json` | `--theirs` (§0.4 : jamais mergés, rebuild final fera foi) |
| `resources/js/helpers/kioskFormatPrice.js` | `--ours` = version SPINE (§0.4 ligne 1) |
| `resources/js/components/admin/posOrders/PosOrderShowComponent.vue` | UNION manuelle : rendu spine `variationsText()` (UIUX-W2 G4, orphelins filtrés) prime ; fallbacks W4 `attribute_name\|\|variation_name` / `name\|\|variation_name` portés DANS le helper + garde anti-doublon `group !== value` |
| `app/Services/OrderService.php` | auto-merge — UNION vérifiée par lecture : guard W4 `assertPaidTransitionHasTenderTrace` (lignes ~2412-2470) inséré, branches spine (customer self-service lockForUpdate, branch isolation, idempotency, SealedOrderGuard REFUNDED) intactes |
| `app/Services/Kiosk/KioskMenuService.php` | auto-merge — hunks disjoints K4 (catégories sans item, l.112+) + dormance promos (l.152-155 `config('kiosk.promos_redeemable')`) tous deux présents |

**Acceptance T-INT.2 (citée)** :
- 10 classes (`ChangePaymentStatusOffBookGuardTest`, `ItemVariationGroupAndUniquenessTest`, `CaisseBillableUpgradesSeederTest`, `KdsUnreleasedOrderBumpGuardTest`, `KdsBoardsReleaseFilterTest`, `KioskPromoDormancyGateTest`, `SalesReportOverviewPermissionTest`, `EodPaymentBucketTenderTest`, `ChangePaymentStatusOutboxTest`, `ChangePaymentStatusTransactionalTest`) → **OK (32 tests, 105 assertions)**
- `tests/Feature/Pos/` → **OK (90 tests, 458 assertions)**
- `tests/Feature/Dashboard/` → **OK (28 tests, 100 assertions)**
- `tests/Feature/KDS/` (casse canonique) → **OK (43 tests, 154 assertions)**

## T-INT.3 — merge clients-next : arbitrages
| Fichier | Arbitrage appliqué |
|---|---|
| `app/Services/FrontendOrderService.php` | UNION primauté heals spine dispute-r1 C-RED-02 : le spine a DÉFÉRÉ la mutation fidélité dans `consumePendingKioskLoyaltyRedemption()` (debit après sealForCommit) ; le dispatch `LoyaltyBalanceChanged` clients-next (F3-02, 8e site d'écriture) est CONSERVÉ dans le consumer déféré ; les 2 lignes clients-next `$calculatedDiscount += $maxDiscount; $this->loyaltyApplied = true;` sont REJETÉES (déjà appliquées en phase pending spine l.999-1000 — les reprendre = double application + variable locale indéfinie) |
| `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue` | UNION : import+méthode `formatPrice`/`formatPriceFr` spine (UIUX-W2 F1) + import `onEvents`+méthode `subscribeLoyaltyLive` clients-next (L2/F3-01 envelope payload/F3-04 filtre user_id) — deux ajouts disjoints à la même ancre |
| `PROJECT_BRAIN.md` | concat les deux (blocs clients-next 06-12/06-11 placés en tête de §2) |
| `public/js/{admin-shell,pos-app}.js` + `mix-manifest.json` | `--theirs`, rebuild final |
| `OrderQuoteService` / `User.php` / `kioskCart.js` / `KioskCartComponent.vue` / `loyalty.js` / `PosComponent.vue` | auto-merge propre (0 conflit) — validés par l'univers fidélité ci-dessous |

**Acceptance T-INT.3 (citée)** :
- `--filter Loyalty` → **OK (97 tests, 406 assertions)** ; `tests/Feature/Loyalty/` → **OK (28, 113)** ; filtre élargi `Loyalty|Redeem|EventContract` → **OK (118 tests, 471 assertions)** (≥ les ~112 attendus)
- `npx vitest run tests/js/routerRedirectIntegrity.spec.js` → **1 passed (1)**
- 7 specs fidélité (`kioskLoyalty429A11y`, `kioskLoyaltyConsentWiring`, `kioskLoyaltyPlaceholderEmailI18n`, `kioskLoyaltyRegisterPhonePrefill`, `posLoyaltyLiveBalance`, `posLoyaltyMainPageCta`, `posLoyaltyRedeemModal`) → **7 files / 48 tests passed**

## T-INT.3bis — merge cms-spine (inconditionnel)
**Constat frozen décisif** : sur les 17 commits uniques, 2 ([LOCK-W6] `04747a52f`,
`c93a37bc9`) touchent le frozen `public/js/pos-wizard.js` — MAIS
`git diff HEAD goal/cms-gestion-2026-06-10-spine -- pos-wizard.js pos-wizard.css
master.blade.php posWizardGenericRender.spec.js` = **VIDE** : la spine porte déjà
ces hunks bit-identiques (LOCK parallèle owner `plans/LOCK_POS_WIZARD_GENERIC_RENDER_2026-06-10.md`).
→ Aucune exclusion de commit nécessaire, **0 frozen modifié par ce merge** (prouvé
par le frozen-diff global ci-dessous).

| Fichier | Arbitrage appliqué |
|---|---|
| `resources/js/languages/{ar,bn,de,en,fr}.json` | deep-merge JSON programmatique (union des clés ; primauté SPINE sur les divergences scalaires : ar=6, bn=7, de=7, en=14, fr=21 — heals i18n spine postérieurs) ; re-validés `json.load` 5/5 |
| `KioskStepGenericChoicesComponent.vue` | UNION : classes B6 cms (`--media`, `cell` — utilisées par le template auto-mergé) + `.kiosk-generic-choice` aux valeurs spine BU-01 (84px/2px/10px) + `flex:1 1 auto` cms requis dans la cell |
| `PROJECT_BRAIN.md` | concat + dédup (2 blocs CMS quasi-identiques côté HEAD → superset « TEST-E2E ADVERSARIAL » conservé) |
| `public/css/app.css` + `public/js/*` + `mix-manifest.json` | `--theirs`, rebuild final |

**Acceptance T-INT.3bis (citée)** : `tests/Feature/Composer/` → **123 tests, 535
assertions, 2 skipped (flag-gated baseline), 0 fail** ; `tests/Feature/Catalog/`
→ **58, 202, 3 skipped, 0 fail** ; filtre sous-catégories → **OK (3, 9)**.

## T-INT.4 — comptes / seed / barème (cité)
- Purge tokens : `SELECT … WHERE name LIKE 'goal-0612%' OR 'curl-admin%' OR 'f1-lane%'` → **0 ligne** (déjà purgés par spine `c10320958`) ; DELETE exécuté = 0 row.
- Comptes dédiés (tinker `APP_ENV=e2e`, updateOrCreate+assignRole+createToken) : `ultraheal@foodking.test` **id=61 role=Admin token_id=2771** ; `ultraheal-pos@foodking.test` **id=62 role=POS Operator token_id=2772**.
- `db:seed --class=CaisseBillableUpgradesSeeder` → « **8 upgrade extras ensured on 4 frites items (1, 2, 33, 34)** ».
- `foodking:set-loyalty-rates 1 100 100` → « Barème fidélité seedé : 1 pt/€ · 100 pts = 1 € · min 100 pts. » ; vérif DB `settings` group `loyalty_setup` : `loyalty_points_per_euro=1`, `loyalty_points_for_1_euro_discount=100`, `loyalty_min_redeem_points=100`.

## T-INT.5 — gate de sortie (partiel, df-gated)
- **Frozen-diff** : `git diff --stat release/v1-2026-06-10..HEAD -- <15 chemins §7>` → **sortie vide = 0 ligne**. ✅
- **Chaîne POST** : CHAIN OK, count croissant only (3718→3724). ✅
- Garde supplémentaire exécutée : `tests/Feature/Fiscal/` → **221 tests, 776 assertions, 3 skipped, 0 fail** ; `FrontendOrder|KioskLoyaltyBilling|OrderQuote` → **OK (19, 72)**.

## DEFERRED (contrainte disque — df 1,1 Gi < seuils 1,5/1,2 Go)
1. **Rebuild Mix full** (`npm run prod`) + sentinelles fraîcheur `tests/js/sentinels/` — les bundles courants sont des `--theirs` mixtes des 3 merges, ils ne reflètent PAS les sources unionnées (PosOrderShowComponent, PosLoyaltyRedeemModal, KioskStepGenericChoices, langues). **Ne pas servir ces bundles en validation visuelle avant rebuild.**
2. **PHPUnit FULL** + **Vitest FULL** — compensés par ~9 suites ciblées (toutes vertes, counts cités ci-dessus), à rejouer intégralement post G-DISK.
3. Reprise documentée dans `EXECUTION_STATUS.md` § DEFER MANIFEST.

## Risques résiduels / notes pour l'Adversaire-merge
- La résolution `FrontendOrderService` rejette 2 lignes clients-next : justification lue dans le code (phase pending spine l.999-1000) + univers fidélité 118/118 vert. Point à contre-vérifier en priorité.
- `posWizardGenericRender.spec.js` : version HEAD conservée (= version spine = version cms, identiques).
- Bundles : tout écart visuel constaté avant rebuild = artefact attendu.
