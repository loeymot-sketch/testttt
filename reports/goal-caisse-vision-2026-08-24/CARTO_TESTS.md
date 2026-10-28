# CARTO_TESTS — Couverture réelle des surfaces CAISSE

**Périmètre** : suivi des commandes (`PosOrdersTrackerComponent`) + détail commande (`PosOrderShowComponent`).
**Méthode** : `ls` / `grep` uniquement, aucune suite exécutée. Ce qui n'est pas listé ici n'a pas été vu.
**Date** : 2026-08-24 · worktree `goal-caisse-vision-2026-08-24`

---

## 0. Ce que la caisse expose réellement (base factuelle)

- `app/Http/Resources/SimpleOrderResource.php:155` → `'order_items' => $this->resolveItemsForTracker()`.
  `resolveItemsForTracker()` (ligne 224) ne ship QUE `item_id`, `item_name`, `quantity`, `instruction`.
  **Aucune variation, aucun extra, aucun `composition_snapshot` n'est envoyé au suivi.**
- `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:323-334` → `<ul class="pos-tracker-card-items">`
  rend `itemsPreview(order)` (ligne 1995 : `items.slice(0, 3)`) puis
  `+ {{ extraItemsCount(order) }} {{ $t('pos.tracker.more_items') }}` (ligne 1999 : `Math.max(0, items.length - 3)`).
- Le détail (`/admin/pos-orders/show/:id`, `resources/js/router/modules/posOrderRoutes.js:36`) lit la composition via
  `normalizeReceiptVariations` / `normalizeReceiptExtras` (`PosOrderShowComponent.vue:472,757,760`).

---

## 1. PHPUnit

| Chemin | Cas | Ce qu'il prouve |
|---|---|---|
| `tests/Feature/Pos/SimpleOrderResourceTrackerContractTest.php` | 4 | Contrat du payload tracker : `created_at`/champs planifiés bruts, `has_instruction` reflète les notes de ligne, `order_items === []` sans eager-load (garde N+1). |
| `tests/Feature/Pos/PosOrderListLeanPaginationTest.php` | 4 | Le mode `lean` + `paginate` du listing POS coupe les relations lourdes « mais garde les besoins du tracker ». |
| `tests/Feature/Pos/WebOrdersPendingEndpointTest.php` | 3 | Une commande web PENDING est visible en caisse, expose nom+téléphone, exclut borne / web acceptée / autre branche. |
| `tests/Feature/Pos/WebOrdersPaidEndpointTest.php` | 7 | Commande web payée toujours visible en caisse ; exclut en-vol, préparée, trop ancienne, autres surfaces/branches ; livraison web incluse. |
| `tests/Feature/Pos/WebOrderInlineAcceptTest.php` | 5 `test_*` | Le caissier ne peut pas accepter une web carte impayée (2 routes jumelles) ; une payée passe ; l'acceptée arrive dans la file counter-collect. |
| `tests/Feature/Pos/CounterCollectQueueRobustTest.php` | 3 | Robustesse de la file d'encaissement comptoir. |
| `tests/Feature/Pos/CounterCollectSplitPaymentTest.php` | 4 | Encaissement comptoir en paiement fractionné. |
| `tests/Feature/Pos/SplitPaymentEndToEndTest.php` | 9 | Chaîne complète du split payment caisse. |
| `tests/Feature/Pos/PosCashTrailTest.php` | 6 | Piste d'espèces NF525 côté caisse. |
| `tests/Feature/Pos/PosReceiptPrintFlowTest.php` | 3 | Flux d'impression du ticket caisse. |
| `tests/Feature/OrderItemCompositionSnapshotTest.php` | 6 | L'instantané NF525 existe, se caste, est préféré au champ legacy, reste immuable après renommage de variation, supporte `quantity`. **Niveau ressource/modèle, pas UI caisse.** |
| `tests/Feature/OrderHistory/OrderHistoryShowSnapshotIntegrityTest.php` | 1 | Le détail HISTORIQUE rejoue l'instantané figé et ignore le prix menu vivant. |
| `tests/Feature/OrderHistoryUnifiedTest.php` | 4 | Listing historique unifié (toutes origines). |
| `tests/Feature/Uber/UberTicketCuisineEtCaisseTest.php` | 7 | Bandeau Uber sur ticket cuisine + `test_une_commande_uber_est_visible_en_caisse` — qui vérifie seulement que la ligne EXISTE en base (`Order::withoutGlobalScopes()->pluck('id')`), **jamais qu'elle s'affiche dans le suivi**. |
| `tests/Feature/Pos/PosOperatorWebOrderPermissionTest.php` | 2 | Le rôle POS Operator porte `online-orders` mais pas `pos-refund`. |

Répertoires inventoriés : `tests/Feature/Pos/` (58 fichiers), `tests/Feature/Order/` (16), `tests/Feature/Orders/` (8),
`tests/Feature/OrderHistory/` (5), `tests/Feature/OrderPipeline/` (1), `tests/Feature/OrderStatusScreen/` (1).

---

## 2. Vitest (`tests/js/`)

| Chemin | Cas | Ce qu'il prouve |
|---|---|---|
| `tests/js/PosOrdersTrackerComponent.spec.js` | 6 | Opérations caissier : `requestReprint`, erreur traduite, refus d'un motif d'annulation < 3 car., `changeStatus CANCELED + reason`, `markDelivered`, amorçage du dialogue d'annulation. |
| `tests/js/posOrdersTrackerWebVisibility.spec.js` | 6 | `sourceOf('web') === 'online'`, `isWebPending`, bucket « À encaisser », non-régression PENDING non-web, `acceptWebOrder` avec idempotency + anti double-clic. |
| `tests/js/posOrdersTrackerPhantomCount.spec.js` | 5 | `stats.todayCount` n'est plus gonflé par les PENDING non-web ; buckets intacts. |
| `tests/js/posTrackerWebIntel.spec.js` | 10 | Renseignement web sur la carte, dont `instructionPreview` lisant `order_items[].instruction`. |
| `tests/js/posTrackerStaleness.spec.js` | 13 | Vieillissement / commandes bloquées. |
| `tests/js/posTrackerBoutonMenteur.spec.js` | 16 | Les CTA de carte ne mentent plus sur l'action réellement possible. |
| `tests/js/posTrackerCashPendingAllStatuses.spec.js` | 3 | Cash-pending reconnu sur tous les statuts. |
| `tests/js/posTrackerRefundedOrphan.spec.js` | 4 | Commande remboursée orpheline. |
| `tests/js/posOrderShowComposition.spec.js` | 7 | **Seul spec du détail** : composition lue depuis l'instantané NF525 (rôles inversés `attribute_name`/`variation_name`, `extra_name`), compat descendante, rejet des entrées sans nom, suppléments non nommés annoncés, pas de ligne « Instruction » vide, tous les statuts nommés, livraison seulement si LIVRAISON. |
| `tests/js/posCounterCompositionLabels.spec.js` | 5 | `PosComponent.composedVariations/composedExtras` — composition lisible dans le **détail d'encaissement comptoir** : forme « lignes DB », forme « snapshot NF525 », jamais « undefined », plus de « , , ». |
| `tests/js/posOrderListStatusFilter.spec.js` | 3 | Filtre de statut de la liste POS. |
| `tests/js/posReceiptBuilder.spec.js` | 20 | `normalizeReceiptVariations` / `normalizeReceiptExtras` / `normalizeReceiptAddons` (`resources/js/helpers/posReceiptBuilder.js:164,198,244`). |
| `tests/js/sentinels/receiptAddonsRenderingSentinel.spec.js` | — | Sentinelle de rendu des addons du ticket. |
| `tests/js/sentinels/trackerEncaisserModalWiredSentinel.spec.js` | — | Le bouton « Encaisser » de la carte est bien câblé au modal. |
| `tests/js/kdsCustomization.spec.js` | 38 | `helpers/kdsCustomization.js` : `categorize`, `renderItem`, `kdsVariationGroupValue`, `kdsVariationLine`, `sanitizeKdsInstruction`. **Surface KDS, pas caisse.** |
| `tests/js/kioskFormatPrice.spec.js` | 3 | `helpers/kioskFormatPrice.js` (`formatKioskPrice`, `getPriceOptionsFromStore`). |

**`resources/js/helpers/formatPrice.js` : AUCUN spec dédié.** `grep -rln formatPrice tests/js/` ne renvoie que
`KioskWizard.spec.js` et `sentinels/posRefundModalSentinel.spec.js`, qui l'utilisent sans le tester.

---

## 3. Playwright (`tests/e2e/`)

**Spécifiques au suivi caisse**
- `tests/e2e/wave-q1-suivi-commandes.spec.js` — `test('tracker cards render order_items (Cayenne + Tacos summary)')`
- `tests/e2e/wave-s4-suivi-4cols.spec.js` — `test('S-4.1 — kiosk cash-pending lands in À ENCAISSER; paid orders skip to PRÉPARATION')`, `test('S-4.2 — empty À ENCAISSER lane renders empty state, lane stays visible')`
- `tests/e2e/tracker-web-order-visibility-proof-2026-07-20.spec.js` — `test('PLAINTE OWNER — commande web visible + acceptable + encaissable dans le tracker')`
- `tests/e2e/wave-q2-status-consistency.spec.js` — `test('two paid POS orders both start ACCEPT; two bumps each → PREPARING → PREPARED')`, `test('regression artifact — no fatal console errors during the flow')`

**Sur `/admin/pos`**
- `tests/e2e/wave-x-pos-x1-x2.spec.js` — `'POS main page mounts with operator bar + shortcuts wrapper available'`, `'Counter-collect SSOT modal opens from shortcut Encaisser (if cash-pending exists)'`, `'Counter-collect modal flips to CARD mode (single-tap confirm UX)'`, `'Counter-collect CASH confirm POSTs with X-Idempotency-Key + row disappears'`
- `tests/e2e/goal-caisse-portes-de-mesure.spec.js` — `'C1 — la grille des catégories est atteignable sans défiler'`, `'C2 — aucun contrôle de saisie coupé dans l\'en-tête du panier'`, `'C3 — le gain du 19/08 n\'est pas repris (corps ≥ 20vh, pied entier)'`
- `tests/e2e/goal-caisse-raccourcis-fkeys.spec.js` — `'les touches F sont bien interceptées par la caisse'`, `'F2 ouvre la PREMIÈRE tuile, pas F1 — le décalage d\'un cran est réel'`, `'F3 et F4 entrent aussi dans une catégorie'`, `'une touche F ne détourne rien pendant la saisie d\'un champ'`
- `tests/e2e/kds-caisse-smoke.spec.js` — `'la caisse rend la grille + la file, sans label brut ni écran blanc'` (+ 1 test KDS)
- `tests/e2e/final-caisse-deep.spec.js` — `'00 — login admin + POS V4 ready + sidebar 12-cat observation + visual heals snapshot'`, scénarios paramétrés `` `${sc.code} — ${sc.label}` ``, `'CA-S10 — Multi-cart Bowl + Petite Frites + Tiramisu = 15.20€'`, `'ZZ — security audit (NF525 chain + BranchScope + idempotency) + retroactive KDS verification'`
- `tests/e2e/s2-v2-encaissement-cash-2026-07-29.spec.js`, `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts`, `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js`

**Touchant `/admin/pos-orders`** — 38 fichiers via `grep -rln "admin/pos-orders" tests/e2e/`. Ceux qui ouvrent le **détail** `show/:id` :
`test-e2e-abuse-H-fiscal-z.spec.js:441`, `refund-prez-confirm.spec.js:93`, `owner-fixes-2026-06-04.spec.js:167`,
`wave-t-r1-f4-currency.spec.js:85`, `goal-pageby-stock-2026-05-18.spec.js:201`,
`test-e2e-wave-t-caisse-to-delivered-D-livreur.spec.js:398`.

---

## 4. Comment lancer

**(a) PHPUnit, filtre POS** — `phpunit.xml` définit les suites `Unit` / `Feature` / `Load` ; binaire `vendor/bin/phpunit`.
Pas de script composer de test (`composer.json:63-78` n'expose que `invariants`).

```
php artisan test --filter=SimpleOrderResourceTrackerContractTest
php artisan test tests/Feature/Pos
vendor/bin/phpunit --filter='Tracker|CounterCollect|WebOrders'
```

SQLite `:memory:` est imposé par `phpunit.xml:68-69` (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) — la base de dev n'est pas touchée.

**(b) Vitest** — `package.json:23-25` : `"test": "vitest run"`, `"vitest": "vitest run"`, `"test:watch": "vitest"`.
Config `vitest.config.mjs` (`include: ['tests/js/**/*.spec.js']`, `environment: 'happy-dom'`, exécution **en série**
`--no-file-parallelism` pour cause de flake happy-dom documentée lignes 21-30).

```
npm run test -- posOrderShowComposition
npx vitest run tests/js/PosOrdersTrackerComponent.spec.js
```

**(c) Playwright, un spec** — `package.json:26-27` : `"test:e2e:full": "playwright test --config=playwright.config.js"`,
`"test:e2e:smoke"` (5 specs). `playwright.config.js:46` → `testDir: './tests'`.

```
npx playwright test --config=playwright.config.js tests/e2e/wave-q1-suivi-commandes.spec.js
```

**Base URL** : `playwright.config.js:12` → `process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000'`.
Un `webServer` est monté automatiquement via `PLAYWRIGHT_WEB_SERVER_CMD || 'php artisan serve --host=127.0.0.1 --port=8000'`
(ligne 33). **Port attendu : 8000.**

---

## 5. TROUS DE COUVERTURE (le cœur)

### T-1 — La carte du suivi affiche-t-elle le contenu ? → **PARTIEL**
Prouvé une seule fois, et faiblement : `tests/e2e/wave-q1-suivi-commandes.spec.js:167-177` seede **1 ligne par commande**
et assert `toHaveCount(1)` + `'Sandwich Cayenne'` + `'2×'`.
Côté Vitest, **AUCUN** spec ne monte `itemsPreview()` / `extraItemsCount()` : `grep -rn "itemsPreview\|extraItemsCount" tests/js/`
ne renvoie **rien** (les seules occurrences du dépôt sont deux lignes de COMMENTAIRE dans `wave-q1`).
Conséquence : la liaison Vue n'est prouvée que par un e2e navigateur ; un refactor du template la casse silencieusement
dans toute CI qui ne lance pas Playwright.

### T-2 — Les personnalisations côté CAISSE → **TOTAL sur le suivi** (couvert ailleurs)
Le payload ne les contient pas (`SimpleOrderResource.php:224-247`) et le template ne les rend pas (aucune occurrence
`variation` / `extra` dans le bloc carte, ligne 323-334). **Aucun test ne peut prouver ce qui n'existe pas — et aucun test
ne dénonce l'absence non plus.** Le seul signal transmis est le booléen `has_instruction`.
Tests les plus proches, tous sur d'AUTRES surfaces :
- détail commande → `tests/js/posOrderShowComposition.spec.js` (7 cas)
- modal d'encaissement comptoir → `tests/js/posCounterCompositionLabels.spec.js` (5 cas)
- KDS → `tests/js/kdsCustomization.spec.js` (38 cas)

### T-3 — Plus de 3 lignes, le « + N autres » → **TOTAL**
`extraItemsCount()` (`PosOrdersTrackerComponent.vue:1999`) et le `<li class="pos-tracker-card-more">` (ligne 331) ne sont
assertés par **aucun** test, ni Vitest ni Playwright. `wave-q1` seede exactement 1 ligne, donc `extraItemsCount === 0` :
la branche n'est jamais exécutée. La clé i18n `pos.tracker.more_items` n'est vérifiée nulle part.
Test le plus proche : `tests/e2e/wave-q1-suivi-commandes.spec.js`.

### T-4 — Composition « instantané NF525 » vs « live » → **PARTIEL, jamais sur le suivi**
Prouvé sur le **détail** (`tests/js/posOrderShowComposition.spec.js:50` snapshot à rôles inversés, `:68` ancienne forme)
et sur le **modal d'encaissement** (`tests/js/posCounterCompositionLabels.spec.js:16` forme DB, `:24` forme snapshot).
Backend : `tests/Feature/OrderItemCompositionSnapshotTest.php:102` (`test_resource_prefers_snapshot_over_legacy_field`)
et `:155` (immuabilité après renommage) ; `tests/Feature/OrderHistory/OrderHistoryShowSnapshotIntegrityTest.php:81`
pour l'historique.
**Trou** : rien ne prouve le rendu snapshot dans le **suivi caisse** (conséquence directe de T-2), et aucun e2e ne fait
le tour complet « commande à snapshot NF525 → carte de suivi → fiche détail » pour vérifier que les deux affichages
racontent la même chose.

### T-5 — Web / borne / plateforme dans le suivi → **PARTIEL, très asymétrique**
- **Web** : bien couvert. `posOrdersTrackerWebVisibility.spec.js` (6), `posTrackerWebIntel.spec.js` (10),
  `posOrdersTrackerPhantomCount.spec.js` (5), `WebOrdersPendingEndpointTest.php` (3), `WebOrdersPaidEndpointTest.php` (7),
  `WebOrderInlineAcceptTest.php` (5), e2e `tracker-web-order-visibility-proof-2026-07-20.spec.js`.
- **Borne (kiosk)** : couvert au niveau **bucket** seulement — `wave-s4-suivi-4cols.spec.js` S-4.1 (cash-pending → À ENCAISSER)
  et `wave-q1` (carte TACOS seedée `typeInt: 17`). `sourceOf('kiosk')` tient sur une seule ligne
  (`posOrdersTrackerWebVisibility.spec.js:94`). Rien sur le contenu d'une commande borne multi-lignes dans le suivi.
- **Plateforme (Uber) : TROU quasi-TOTAL sur le suivi.** `PosOrdersTrackerComponent.vue:1987` contient la détection
  `s.includes('uber') || 'deliveroo' || 'just' || 'platform'` — **aucun test Vitest ni e2e ne l'exerce**
  (`grep -rn "uber" tests/js/posTracker*` → rien). Le plus proche,
  `tests/Feature/Uber/UberTicketCuisineEtCaisseTest.php:154`, prouve seulement qu'une `Order` `source_surface='uber_eats'`
  existe en base — jamais qu'elle apparaît sur une carte, avec le bon chip, le bon libellé client, ou le bouton
  ticket promo nominatif (`.vue:109`).

### T-6 — `formatPrice` → **TOTAL**
`resources/js/helpers/formatPrice.js` n'a aucun spec. Le plus proche, `tests/js/kioskFormatPrice.spec.js` (3 cas),
teste un **autre** helper (`helpers/kioskFormatPrice.js`). Le montant de la carte de suivi
(bloc `pos-tracker-card-foot`, fallbacks `total` → `total_amount_price` → `order_amount`) n'a donc pas de garde unitaire ;
seul `tests/e2e/wave-t-r1-f4-currency.spec.js` regarde le rendu monétaire, en navigateur.
