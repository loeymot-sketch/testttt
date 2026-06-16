# FoodKing — Map des points faibles & non-optimisés (2026-06-16)

> Basé sur `SYSTEM_MAP.md` (5 systèmes + §6 zones partagées) + audits de cette session (3 agents
> correctness + vérif round-2) + 2 agents perf/dette + métriques dures vérifiées. Contexte V1 LOCAL
> Le Cayenne (mono-poste, mono-branche, ~46 items, ~2700 commandes). Tout est file:line-confirmé.

## Légende
🔴 systémique / impact large · 🟠 réel, ciblé · 🟡 mineur / dégradé-only · 🟢 SOLIDE (rien à faire)
**[NOW-SAFE]** corrigeable sans danger · **[GATE]** gate owner (frozen/sync/build) · **[SaaS]** ne mord qu'à l'échelle future · **[DOC]** fix doc seul

---

## A. CROSS-CUTTING / §6 ZONES PARTAGÉES — c'est ici que sont les vraies faiblesses

### 🔴 A1 — Fuite d'exception + cécité observabilité (LE #1 systémique)
- **434 lignes** `return response([...'message' => $e->getMessage()], 422)` dans des `catch`, sur **104 fichiers** (admin : 360 lignes / 78 contrôleurs).
- **97/104 fichiers ne loggent RIEN** (seulement 7 ont un `Log::`). Donc : (a) le texte brut d'exception (noms de tables/contraintes SQL, chemins, classes) part **au client** sur un `QueryException`, (b) **zéro trace** pour le monitoring → échec prod invisible.
- Ex : `AnalyticController.php:33-63` (6 catch, tous fuient, 0 log).
- **Fix** : trait partagé `jsonError($e)` → `Log::error(vrai message)` + renvoie un 422 générique traduit. **[trop large pour un seul passage]** → highest-risk-first : `Pos*` / `Order*` / `Payment*` / `Loyalty*` / `Fiscal*` d'abord.

### 🟠 A2 — Money en-US partout sauf la borne (incohérence locale FR)
- `appService.currencyFormat` (`appService.js:71-77`) hardcode `toFixed()+symbole` → `0.00€`, **pas** d'`Intl`/virgule/NBSP. **2 chemins de formatage money** cohabitent.
- **12 consommateurs .vue** : POS (corrigé cette session via `formatPrice`), Checkout (×5), Coupon, Item, nav/cart frontend, Table (×plusieurs), `PaymentComponent` (frozen).
- **Fix** : corriger **1 seule def** (`appService.currencyFormat` → `Intl fr-FR`) soigne **11/12 d'un coup** **[NOW-SAFE, présentation only, backend reste SSOT]**. Le 12e (`PaymentComponent`) hérite mais **[GATE]** (frozen §7).

### 🟡 A3 — Sync dégradée non optimale (WS down = scénario courant ici)
- WS `:6001` down → fallback polling. `OssSyncService` poll **toutes les 2s** quand déconnecté (`intervalMsWhenDisconnected: 2_000`), 60s connecté.
- Pastilles "Prêt" KDS = **browser-local**, ne se synchronisent pas entre écrans KDS (banner "LOCAL" l'avoue).
- **[GATE]** (touche le contrat sync §6) — voir B3.

### 🟡 A4 — Gouvernance / cloud-prep (backlog V1.0.X documenté)
- **66** FormRequests en `return true` (sentinelle ratchetée à 66 — **CLAUDE.md §9 dit "69" = PÉRIMÉ**). Plafond serré OK, mais 66 autorisations délèguent au middleware.
- `UNI-03` : garde cache prod (`AppServiceProvider.php:294-296`) interdit `array`/`null` seulement → `file`/`database` **passent** (sûr mono-box, risque au cutover cloud). **CLAUDE.md §8 cite `:215` = réf périmée.**
- BranchScope : 12 modèles exemptés (2 archi + 10 backlog V1.0.2 fuite-cross-branch) — **[SaaS]**, hard-fail V2.

---

## B. PAR SYSTÈME

### 1. BORNE (kiosk) — 🟢 le plus sain
- Pricing re-quote backend, idempotence session, file offline robuste, déjà **FR-correct** (`formatPrice`). Audit BORNE+CAISSE : 0 P0/P1/P2.
- 🟡 **B1 [SaaS]** `MenuProjectionController` côté POS n'a pas de `Cache::remember` (la borne, elle, cache). 🟡 composer projection re-résout le stock par item en jetant le snapshot batché (`ComposerProfileProjection.php:25-27`) **[NOW-SAFE, petit]**.
- 🟡 P3 : pollution test-data du clone e2e (catégories `wval3cg-*`, promo `BORNEAUDIT5`) — **données clone, PAS prod**.

### 2. CAISSE (POS)
- 🟢 Money/fiscal "surface la moins risquée" : triple-défense concurrence, alloc fiscale OK, 0 fuite numérique (pas de total frontend accepté). Pricing N+1 = **FAUX** (batché, 0 requête).
- 🟠 **B2** [GATE-frozen] `pos-wizard.js` (297 Ko, S25-SinglePage) + `PaymentComponent` figés en money en-US (POS-ERG-07) → dette UX piégée, intouchable sans `LOCK`.
- 🟡 P3 : dedup parked-order opt-in (double-POST sans token → 2 brouillons ; pas d'argent/fiscal).

### 3. KDS + OSS — 🟠 c'est ici que se concentre le "pas optimisé" perf
- 🔴 **B3 [GATE]** **Double boucle de poll parallèle** (`KitchenDisplaySystemComponent.vue:1900-1919` + `:1545`) : quand WS down, le board complet **non-caché** `/admin/kds-order` (50 lignes + N+1) refetch toutes les 5s **en parallèle** du delta `sync` (lui caché) qui déclenche *aussi* un refresh complet. Redondant by-design. Fix = faire du delta l'unique fallback OU cacher `list()`.
- 🟠 **B4 [NOW-SAFE]** **N+1 ×2** sur `index` ET `sync` (`KDSOrderDetailsResource.php:50-51` : `loadMissing('orderItem')` + `diningTable?->name` → 1 SELECT/commande/poll, toutes les 5s, 2 endpoints). **Fix 1-ligne** : `with(['orderItems.orderItem','address','user','diningTable'])` dans `KitchenDisplaySystemOrderService.php:73` + `KdsSyncService.php:96`.
- 🟡 **B5 [NOW-SAFE]** board chef items (`KitchenDisplaySystemOrderService.php:544`) **sans cap de lignes** (contrairement au `.limit(51)` de `list()`).
- 🟢 Heals KDS-01/02 + OSS-01 re-prouvés runtime cette session. Listener isolation OK.

### 4. WEB + APP (storefront backend + standalone)
- 🟠 hérite de **A2** (Checkout/Coupon/Item/nav/cart frontend + Table en money en-US).
- 🟡 standalone (mobile/web) = NO API wireup V1 (par mandat) — pas une faiblesse, un choix.

### 5. CENTRAL (gestion/admin) — gros volume, dette concentrée
- 🟠 **B6 [NOW-SAFE]** **`salesReportOverview()` hydrate TOUTE la table orders** (`OrderService.php:2730`) : filtre date gated `if(from&&to)` → état **par défaut sans borne** → `->get()` charge les ~2700 commandes + eager-load `orderItems` **jamais lu**, re-tire à chaque page. Fix : drop eager-load + `selectRaw` agrégat (`scopeRealizedRevenue`).
- 🟠 **B7 [NOW-SAFE]** `customerStates()` (`DashboardService.php:301-315`) : 18 buckets en `->get()->count()` (hydrate des modèles pour les compter). Fix : `->count()` SQL / `GROUP BY HOUR()`.
- 🟠 **B8 [NOW-SAFE]** **Settings cache DÉSACTIVÉ** (`SettingService.php:9-24`) : `/frontend/setting` = **10 SELECTs** par appel, chargé à **chaque boot client** (borne idle, web, POS). **Le win le moins cher de tout l'audit** : `SETTINGS_CACHE_ENABLED=true` dans `.env`, **0 ligne de code**.
- 🟠 **B9 [NOW-SAFE]** **A11y** : **69 boutons icône-seule sans nom** (~40 = `modal-close fa-xmark` copié-collé), **51 contrôles form sans label**, + vrai bug `for="password"` orphelin dans 6 composants Show. (Edit-buttons déjà corrigés cette session.) `<img>` alt = propre (0/102).
- 🟡 **B10 [NOW-SAFE]** **Dead code** : 10 composants Vue orphelins (`ProductCreateWizardComponent` + frères composer morts `StepEditor`/`StepPreview`), 3 méthodes `appService` mortes, 23 exports helper morts, **21 clés i18n rendues brutes** (écrans coupon ; bug fallback `label.{day}_short`).
- 🟡 **B11 [NOW-SAFE-doc]** route stock périmée CLAUDE.md §6 (`/admin/stock-rupture-dashboard` 404 → vraie `/admin/stock/rupture`) ; STOCK-GRID corrigé cette session.

---

## C. POIDS BUNDLES (🟡 [GATE-build])
app.js **7,4 Mo** · pos-app **7,1 Mo** · admin-shell **6,7 Mo** · vendor **1,9 Mo** (non partagé dans les entries → dupliqué ; probablement non-minifié en dev). 1er paint lent sur hardware mono-box. Fix = config build (`mix --production` + extraction vendor) **[GATE]** (touche le câblage du frozen `pos-wizard.js`).

---

## D. CE QUI EST SOLIDE (ne pas toucher)
🟢 PricingService (batché, 0 N+1, SSOT) · index hot-columns **déjà posés** (`2026_03_12_130000_add_performance_indexes.php`) · chaîne NF525 (uniques = index implicites) · N+1 dashboard/reports principaux **déjà healed** (withCount/withSum) · pas de v-for 2700-lignes · BORNE end-to-end · alloc fiscale + idempotence + branch-isolation.

---

## E. TRIAGE — par où commencer

### Tier 1 — quick wins NOW-SAFE, fort ROI, 0 risque
1. **B8** `SETTINGS_CACHE_ENABLED=true` (.env, 0 code) — touche TOUS les boots client.
2. **B4** N+1 KDS : 1-ligne `with()` ×2 → tue 2 N+1 sur 2 endpoints @5s.
3. **A2** : 1 def `appService.currencyFormat`→Intl fr-FR → soigne 11 surfaces money.
4. **B6/B7** reports : drop eager-load inutile + `->count()`/`selectRaw`.

### Tier 2 — NOW-SAFE, plus de surface
5. **B9** a11y (aria-label sur les 69 + fix `for="password"`), **B10** purge dead-code + 21 clés i18n.

### Tier 3 — GATE owner (ne pas faire seul)
6. **A1** trait `jsonError` (434 lignes — par vagues highest-risk-first).
7. **B3** dé-doublonner le poll KDS (contrat sync §6).
8. **B2 / bundles / PaymentComponent** (frozen / build).

### Tier 4 — SaaS-deferred (V2, pas V1)
9. Index composites, cache projections POS, agrégats dashboard, BranchScope 10 modèles, UNI-03 cache cloud.

### Tier 0 — DOC seul
10. CLAUDE.md : §9 baseline 66 (pas 69), §8 réf `:215`, §6 route stock.
