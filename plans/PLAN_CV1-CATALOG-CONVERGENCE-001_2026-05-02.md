# PLAN — CV1-CATALOG-CONVERGENCE-001

| Champ | Valeur |
|---|---|
| Cycle ID | `CV1-CATALOG-CONVERGENCE-001` |
| Date plan | 2026-05-02 |
| Auteur plan | Claude (Anthropic, terminal `claude`, modèle `claude-opus-4-7`, effort `xhigh`) |
| Périmètre | Mission #1 — Catalog Sync POS ↔ Kiosk ↔ KDS (V1.5 convergence) |
| Audit source | `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md` |
| Mission liée | #2 (`plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md`) |
| Frozen zones touchées | **Aucune.** PaymentService / OrderService / PricingService / FrontendOrderService / PaymentComponent.vue / ItemComponent.vue restent intacts. |
| Gates humains | Aucun pour Vague 1 et 2. Vague 3 = `GATE_CATALOG_CHANNELS_REQUIRED` (V2). |
| Estimation | Vague 1 ≈ 1 sprint (3-5 jours-dev) ; Vague 2 ≈ 1-2 sprints. |
| Effort cumulé | XL |

---

## 0. Lecture rapide pour Codex / Cursor

**But :** rendre la projection POS et la projection Kiosk indissociables structurellement, en gardant la chaîne fiscale NF525 intacte.

**Trois clés du plan :**

1. **Vague 1 = quick wins** (branch-scope, sentinels, warnings, fallback polling). Aucun changement de schéma, aucun frozen.
2. **Vague 2 = convergence** derrière feature flag `catalog_v15.unified_projection.enabled`. Activé d'abord en `shadow_compare` puis flippé en `unified` une fois la parité prouvée par 14 jours de logs sans diff.
3. **Vague 3 = refactor** (channels=required, suppression NULL=ALL). Repoussé à V2 derrière gate humain.

**Fondations déjà posées (à reprendre, NE PAS recréer) :**
- `config/catalog_v15.php` — feature flags ; lire `unified_projection`, `pos_fallback_polling`, `channels_filter`.
- `app/Services/Menu/PosMenuProjection.php` — service shim 3-modes (legacy / shadow_compare / unified) avec kill-switch.
- `resources/js/services/PosSyncService.js` — squelette fallback polling POS.
- 5 sentinels PHPUnit `markTestSkipped` à dé-skipper progressivement (cf. §5).
- Composants Vue squelettes : `ItemPreviewComponent`, `CatalogChangeToastComponent` (M2 mais réutilisé ici pour parité Kiosk).

**Règles d'or de ce cycle :**
- Aucune modification dans frozen zones.
- Toute migration POS/Kiosk vers `MenuProjectionService` passe par le shim `PosMenuProjection` ; jamais de saut direct.
- Tests de parité **avant** de flipper le flag.
- Documentation runbook **avant** d'activer en prod.

---

## 1. Tableau de bord exécutif

| Vague | Tâche | Cible | Effort | Risque | Sentinels |
|---|---|---|---|---|---|
| V1 | 1.1 Branch-scoper PosCategoryController | `app/Http/Controllers/Admin/PosCategoryController.php:35-99` | M | Modéré | `tests/Feature/Menu/PosCategoryBranchScopeTest.php` (déjà skipped) |
| V1 | 1.2 Filtre surface POS par défaut côté serveur | `app/Services/ItemService.php:115-154` | S | Faible | `tests/Feature/Menu/FrontendSurfaceFilteringTest.php` étendu |
| V1 | 1.3 Sentinel JS parité menu POS↔Kiosk | nouveau `tests/js/posComponentMenuFiltering.spec.js` | M | Faible | le test lui-même |
| V1 | 1.4 Warning `[catalog.channels-null]` | `app/Services/ItemService.php` (store/update) | S | Nul | `tests/Feature/Catalog/ChannelsNullWarningTest.php` (déjà skipped) |
| V1 | 1.5 Doc runbook divergence catalogue | `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md` | XS | Nul | n/a |
| V1 | 1.6 Harmoniser DispatchableAfterCommit | 5 events `app/Events/Item*` + `Category*` | S | Faible | `tests/Feature/Outbox/CatalogEventDispatchAfterCommitTest.php` (déjà skipped) |
| V1 | 1.7 Implémenter PosSyncService fallback polling | `resources/js/services/PosSyncService.js` (squelette) + `resources/js/components/admin/pos/PosComponent.vue` mounted | M | Modéré | `tests/js/posSyncFallback.spec.js` |
| V2 | 2.1 Activer le shadow_compare | flag `FK_CATALOG_UNIFIED_PROJECTION_SHADOW_COMPARE` | XS | Nul | log analysis |
| V2 | 2.2 Migrer PosCategoryController vers PosMenuProjection | `app/Http/Controllers/Admin/PosCategoryController.php` + `app/Services/Menu/PosMenuProjection.php::adaptUnifiedToLegacyShape` | L | Élevé | `tests/Feature/Menu/PosCategoryProjectionParityTest.php` |
| V2 | 2.3 Migrer ItemController index vers PosMenuProjection | `app/Http/Controllers/Admin/ItemController.php:43-57` | L | Élevé | `tests/Feature/Menu/PosItemListProjectionParityTest.php` |
| V2 | 2.4 Migrer KioskMenuService::build au-dessus de MenuProjectionService | `app/Services/Kiosk/KioskMenuService.php:71,100` | XL | Élevé | `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` étendu |
| V2 | 2.5 Sentinel parité backend POS↔Kiosk | `tests/Feature/Menu/PosKioskProjectionParityTest.php` (déjà skipped) | M | Faible | le test lui-même |
| V2 | 2.6 Activer flag staging puis prod | runbook ops | XS | Faible | runbook |
| V2 | 2.7 Cleanup legacy | `PosCategoryController` array literal + `KioskMenuService::projectItems` réécriture | M | Faible | n/a |
| V3 | 3.x | Channels=required + modèle stock unifié | n/a | Très élevé | `GATE_CATALOG_CHANNELS_REQUIRED` |

---

## 2. Vague 1 — Quick wins (détail tâche par tâche)

### 1.1 — Branch-scoper PosCategoryController::index

**Fichier(s) cible(s) :**
- `app/Http/Controllers/Admin/PosCategoryController.php:35-99`

**Contrat :**
- L'utilisateur authentifié sur POS DOIT voir uniquement les catégories qui contiennent au moins un item disponible sur sa branche active.
- Un Admin/Tenant Admin sans branche active spécifique conserve la vue globale (pas de breaking change pour leur usage actuel).

**Étapes Codex :**
1. Lire la convention d'authz dans `app/Services/DefaultAccessService.php` pour récupérer `active_branch_id` du user authentifié.
2. Modifier la query racine `ItemCategory::with('media')` (ligne 44) :
   - Ajouter `whereHas('items', function($q) use ($branchId) { ... })` qui exige au moins un item visible sur la branche.
   - Conserver le filtre `channels JSON contains 'pos' OR null` (lignes 60-67) pour ne pas casser back-compat.
3. Conserver l'injection virtuelle `id:0` "all_items" (lignes 74-80) en tête de liste.
4. Ne PAS toucher à la sérialisation (`SimpleItemResource` n'est pas appelé ici, c'est uniquement la liste catégories).

**Critères d'acceptation :**
- Branch A voit uniquement ses catégories avec items disponibles.
- Branch B voit uniquement ses catégories avec items disponibles.
- Tenant Admin sans branche voit toutes les catégories.
- La virtual `id:0` reste présente dans tous les cas.
- 422/500 si `branch_id` est invalide ; 200 propre sinon.

**Sentinel à dé-skipper :** `tests/Feature/Menu/PosCategoryBranchScopeTest.php` — implémenter les 3 cas (branch A, branch B, tenant admin).

**Risques :**
- Un Branch Manager qui s'attendait à voir toutes les catégories pour planning va voir une vue restreinte. **Mitigation :** rôle `BRANCH_MANAGER` continue de voir tout via `whereHas` désactivé pour ce rôle.
- Les catégories avec uniquement des items en rupture stockable seront masquées — **vérifier** si c'est le comportement souhaité (le brief dit oui).

---

### 1.2 — Filtre surface POS par défaut côté serveur

**Fichier(s) cible(s) :**
- `app/Services/ItemService.php:115` (`simpleList`) → `applyChannelsFilter` lignes 137-154
- `app/Http/Controllers/Admin/ItemController.php:43-57`

**Contrat :**
- Si l'utilisateur authentifié n'a QUE le scope `pos` (pas Admin/Tenant Admin), `simpleList` doit appliquer `?surface=pos` même si le client ne l'envoie pas.
- Pour Admin/Tenant Admin, comportement actuel inchangé (legacy back-compat).

**Étapes Codex :**
1. Dans `ItemController::index`, après authentification, déterminer si l'utilisateur a uniquement le scope POS (cf. `app/Services/DefaultAccessService.php` + `app/Http/Resources/DefaultAccessResource.php`).
2. Si oui, injecter `$request->merge(['surface' => 'pos'])` AVANT l'appel à `ItemService::simpleList`.
3. Documenter dans le commentaire pourquoi (référence à l'audit §A.1 #3).

**Critères d'acceptation :**
- POS user sans `?surface` → liste filtrée comme si `?surface=pos`.
- Admin user sans `?surface` → liste complète (legacy).
- Aucun changement pour POS user qui envoie déjà `?surface=pos` explicitement.

**Sentinel :** étendre `tests/Feature/Menu/FrontendSurfaceFilteringTest.php` avec un cas POS user sans paramètre.

---

### 1.3 — Sentinel JS parité menu POS↔Kiosk

**Fichier(s) cible(s) :**
- Nouveau `tests/js/posComponentMenuFiltering.spec.js`

**Contrat :**
- Pour un set de fixtures partagé (10 items, branch=1, mix de channels), le composant POS et le composant Kiosk affichent **le même set** d'items.
- Exception attendue : items avec `channels=['pos']` uniquement présents sur POS, items avec `channels=['kiosk']` uniquement sur Kiosk.

**Étapes Codex :**
1. Créer fixtures `tests/js/__fixtures__/menu-parity.json` avec 10 items couvrant tous les cas channels.
2. Mock store POS + store Kiosk avec les mêmes fixtures hydratées.
3. Asserter que `posStore.getters['item/visible']` ∩ `kioskStore.getters['kioskMenu/visibleItems']` = items channels NULL ou `['pos','kiosk']`.
4. Asserter que la diff symétrique = items channels exclusifs.

**Critères d'acceptation :**
- Test passe ✅ même si le code POS/Kiosk filtre actuel diverge (le test révèle la divergence).
- Documentation inline du test décrit chaque cas.

**Risques :**
- Le test échouera initialement vu la divergence actuelle — c'est le but, marquer comme `expectFail` n'est pas acceptable. **Solution :** ce test est créé dans le même PR que la tâche 1.1 ou 1.2 qui élimine la divergence pour les fixtures couvertes.

---

### 1.4 — Warning serveur `[catalog.channels-null]`

**Fichier(s) cible(s) :**
- `app/Services/ItemService.php` (méthodes `store` et `update`)
- `app/Services/Catalog/CatalogWarningService.php` (déjà créé — voir TODO Codex tasks 1.4)

**Contrat :**
- Pas de changement comportemental.
- À chaque création/modification d'item ou de catégorie où `channels === null` :
  - Émettre un log `Log::warning('[catalog.channels-null]', ['item_id' => ..., 'user_id' => ..., 'tenant_id' => ...])`.
  - Conditionné par `config('catalog_v15.channels_filter.warn_on_null', true)`.
- Exposer le warning dans `ItemController::show` via `CatalogWarningService::forItem` quand `config('catalog_v15.warnings.expose_to_admin_show')` est true.

**Étapes Codex :**
1. Dans `ItemService::store` et `update`, après save, check si `channels === null`. Si oui, log.
2. Implémenter `CatalogWarningService::forItem` détection `channels_null` (TODO marqué dans le squelette).
3. Modifier `ItemController::show` pour appeler `CatalogWarningService::exposeFor($item)` et merger le résultat dans la réponse JSON sous la clé `warnings`.
4. Utiliser le composant `ComposerProfileWarningBadge.vue` côté admin pour afficher le badge.

**Critères d'acceptation :**
- Item créé sans channels → 1 entrée log `[catalog.channels-null]`.
- Item créé avec `channels=['pos']` → aucun log.
- `GET /api/admin/items/{id}` inclut `{ "warnings": [{ "code": "channels_null", ... }] }` quand applicable.

**Sentinel à dé-skipper :** `tests/Feature/Catalog/ChannelsNullWarningTest.php`.

---

### 1.5 — Documentation runbook divergence catalogue

**Fichier(s) cible(s) :**
- `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md` (existant — section à ajouter)

**Contrat :**
- Section "Symptom : POS et Kiosk affichent des catégories différentes" avec :
  1. Vérification : `php artisan tinker` → comparer `MenuProjectionService::forChannel('pos', $branchId)` et `KioskMenuService::build($branchId)`.
  2. Cause possible n°1 : item `channels=NULL` côté admin.
  3. Cause possible n°2 : `item_branch_availability` row manquante ou avec `is_available=false`.
  4. Cause possible n°3 : feature flag `unified_projection.kill_switch=true`.
  5. Procédure de recovery : ré-émettre `CatalogChanged::dispatch($branchId)` pour invalider le cache.

**Étapes Codex :**
1. Lire le runbook existant pour la convention de présentation.
2. Ajouter la section sous l'ordre alphabétique ou chronologique du runbook.

---

### 1.6 — Harmoniser DispatchableAfterCommit sur events catalog

**Fichier(s) cible(s) :**
- `app/Events/ItemCreated.php:7-16`
- `app/Events/ItemDeleted.php:7-16`
- `app/Events/CategoryCreated.php:7-16`
- `app/Events/CategoryUpdated.php:7-16`
- `app/Events/CategoryDeleted.php` (vérifier l'existence)

**Contrat :**
- Tous ces events utilisent `Illuminate\Foundation\Events\Dispatchable` simple aujourd'hui.
- Référence : `CatalogChanged.php:5,9`, `ItemAvailabilityChanged.php:5,23`, `StockLevelChanged.php:5,9`, `ComposerProfileChanged.php:12-14` qui utilisent déjà le trait `DispatchableAfterCommit`.

**Étapes Codex :**
1. Pour chaque event ci-dessus, remplacer `use Dispatchable;` par `use App\Events\Concerns\DispatchableAfterCommit;` (vérifier le namespace exact).
2. Vérifier qu'aucun listener ne casse (test suite complète).

**Critères d'acceptation :**
- Aucun event catalog n'est dispatché si la transaction qui l'engendre rollback.

**Sentinel à dé-skipper :** `tests/Feature/Outbox/CatalogEventDispatchAfterCommitTest.php`.

---

### 1.7 — Implémenter PosSyncService fallback polling

**Fichier(s) cible(s) :**
- `resources/js/services/PosSyncService.js` (squelette posé)
- `resources/js/components/admin/pos/PosComponent.vue` (méthode `mounted` + `beforeUnmount`)

**Contrat :**
- Quand l'état Echo passe à `DISCONNECTED`, lancer un poll `/api/admin/item?surface=pos&branch_id={id}` toutes les 30s avec jitter 0-500ms.
- Quand l'état Echo repasse à `CONNECTED`, arrêter le poll immédiatement.
- Backoff doubling sur 5xx, capped à 30s.
- Ne pas dupliquer les fetch (abort previous via AbortController).

**Étapes Codex :**
1. Reprendre les 7 sub-tâches détaillées dans le squelette `PosSyncService.js` (lignes 69-92 du fichier).
2. Wirer dans `PosComponent.vue::mounted` : `PosSyncService.start({ branchId, store, axios, webSocketService })`.
3. Wirer dans `PosComponent.vue::beforeUnmount` : `PosSyncService.stop()`.
4. Lire le flag `window.fkConfig.posFallbackPolling.enabled` injecté côté Blade depuis `config('catalog_v15.pos_fallback_polling.enabled')`.

**Critères d'acceptation :**
- Flag off → aucun poll.
- WS disconnected + flag on → polling démarre.
- WS reconnects → polling stoppe.
- 5xx pendant 3 polls → backoff 5s → 10s → 20s → cap 30s.

**Sentinel :** `tests/js/posSyncFallback.spec.js`.

---

## 3. Vague 2 — Convergence (détail tâche par tâche)

### 2.1 — Activer shadow_compare en staging

**Étapes Codex :**
1. Sur staging, poser `FK_CATALOG_UNIFIED_PROJECTION_SHADOW_COMPARE=true`.
2. Surveiller `storage/logs/catalog-shadow-diff.log` pendant 7 jours.
3. Si zéro diff structurel → procéder à 2.2.
4. Si diff → analyser, corriger `adaptUnifiedToLegacyShape` (tâche 2.2 en avance), revenir au shadow_compare jusqu'à zéro diff.

---

### 2.2 — Migrer PosCategoryController vers PosMenuProjection

**Fichier(s) cible(s) :**
- `app/Http/Controllers/Admin/PosCategoryController.php` (méthode `index`)
- `app/Services/Menu/PosMenuProjection.php` (méthode `adaptUnifiedToLegacyShape`, TODO marqué ligne 95-105)

**Contrat :**
- Le shape JSON renvoyé par `PosCategoryController::index` après migration DOIT être structurellement IDENTIQUE à celui d'aujourd'hui :
  - `[{ "id": 0, "name": "Tous les produits", "slug": "all-items", "image_full_path": "...", "sort": 0 }, { "id": 42, "name": "Tacos", ... }, ...]`
- Aucun champ ajouté, aucun champ retiré.

**Étapes Codex :**
1. Lire `MenuProjectionService::forChannel` pour comprendre son shape de retour.
2. Implémenter `PosMenuProjection::adaptUnifiedToLegacyShape` :
   - Conserver la signature actuelle.
   - Mapper `unified.categories[i].id` → `legacy.id` ; `unified.categories[i].name` → `legacy.name` (ne PAS appliquer `kiosk_label` côté POS) ; etc.
   - Injecter la virtual `id:0` "all_items" en tête.
3. Modifier `PosCategoryController::index` pour appeler `$this->posMenuProjection->forBranch($branchId, fn() => $this->buildLegacy(...))` au lieu d'appeler directement la query.
4. La closure `fn() => $this->buildLegacy(...)` encapsule l'ancien code, qui devient privé.
5. Gardez `unified=false` (default) jusqu'à parité prouvée.

**Critères d'acceptation :**
- En mode `legacy` : zéro changement comportemental.
- En mode `shadow_compare` : la même réponse legacy + 0 diff log.
- En mode `unified` : la même réponse, calculée par le nouveau path.

**Sentinel :** `tests/Feature/Menu/PosCategoryProjectionParityTest.php` (à créer Vague 2). Asserer que les 3 modes retournent un shape IDENTIQUE pour 10 fixtures distinctes.

---

### 2.3 — Migrer ItemController::index vers PosMenuProjection

Symétrique à 2.2 mais sur la liste items. Le shape est `SimpleItemResource[]`. Adapter via `adaptUnifiedItemListToLegacyShape` (à ajouter dans `PosMenuProjection`).

**Sentinel :** `tests/Feature/Menu/PosItemListProjectionParityTest.php`.

---

### 2.4 — Migrer KioskMenuService::build au-dessus de MenuProjectionService

**Fichier(s) cible(s) :**
- `app/Services/Kiosk/KioskMenuService.php:71,100` (méthodes `build` et `projectItems`)

**Contrat :**
- `KioskMenuService::build` devient une couche orchestratrice :
  1. Appelle `MenuProjectionService::forChannel('kiosk', $branchId)` pour récupérer la base.
  2. Enrichit avec composer projection complète (`ComposerProfileProjection`).
  3. Garde le cache 5min `kiosk.menu.branch.{id}`.
  4. Garde le offline IndexedDB hint pour les bornes intermittentes.
- Les TESTS Vitest+PHPUnit existants continuent de passer **inchangés**.

**Étapes Codex :**
1. Refactor par ÉTAPES :
   a. Extraire `KioskMenuService::projectItems` en helper privé qui devient un adapter sur la sortie `MenuProjectionService::forChannel`.
   b. Faire passer la suite de tests existante.
   c. Supprimer le code mort.
2. Conserver le cache `kiosk.menu.branch.{id}` avec invalidation existante.

**Sentinel à étendre :** `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php`.

---

### 2.5 — Sentinel parité backend POS↔Kiosk

**Sentinel à dé-skipper :** `tests/Feature/Menu/PosKioskProjectionParityTest.php`.

Implémenter les 5 cas listés dans le squelette du test (lignes 18-34).

---

### 2.6 — Activation production

1. Sur production, basculer `FK_CATALOG_UNIFIED_PROJECTION_ENABLED=true`.
2. Maintenir `FK_CATALOG_UNIFIED_PROJECTION_KILL_SWITCH=false`.
3. Si incident, basculer `KILL_SWITCH=true` immédiatement (rollback en O(1)).
4. Soak 14 jours puis 2.7.

---

### 2.7 — Cleanup legacy

Supprimer le code legacy de `PosCategoryController::buildLegacy`, supprimer `KioskMenuService::projectItems` réécriture, garder uniquement la couche cache + composer enrichment.

---

## 4. Vague 3 — Refactor structurel (V2 — gates humains)

Gate humain à ouvrir : `docs/gates/GATE_CATALOG_CHANNELS_REQUIRED_2026-XX-XX.md`.

Contenu attendu :
- Migration backfill : `UPDATE items SET channels = JSON_ARRAY('pos','kiosk','web') WHERE channels IS NULL` (idem `item_categories`).
- Modifier `Item::isVisibleOn` et `ItemCategory::isVisibleOn` pour ne plus court-circuiter sur NULL.
- Ajouter contrainte `required|array|min:1` dans `ItemRequest` et `ItemCategoryRequest`.
- Sentinel migration `tests/Feature/Menu/ChannelsRequiredMigrationTest.php`.

**NE PAS exécuter ce cycle CV1.** Réservé à la V2 sous gate humain.

---

## 5. Sentinels — état et activation

| Sentinel | Statut squelette | Vague d'activation |
|---|---|---|
| `tests/Feature/Menu/PosCategoryBranchScopeTest.php` | skipped | V1 (1.1) |
| `tests/Feature/Catalog/ChannelsNullWarningTest.php` | skipped | V1 (1.4) |
| `tests/Feature/Outbox/CatalogEventDispatchAfterCommitTest.php` | skipped | V1 (1.6) |
| `tests/Feature/Menu/PosKioskProjectionParityTest.php` | skipped | V2 (2.5) |
| `tests/Feature/Menu/PosMenuProjectionFeatureFlagTest.php` | skipped | V2 (2.1-2.6) |
| `tests/js/posComponentMenuFiltering.spec.js` | à créer | V1 (1.3) |
| `tests/js/posSyncFallback.spec.js` | à créer | V1 (1.7) |
| `tests/Feature/Menu/PosCategoryProjectionParityTest.php` | à créer | V2 (2.2) |
| `tests/Feature/Menu/PosItemListProjectionParityTest.php` | à créer | V2 (2.3) |

**Règle :** un sentinel marqué `markTestSkipped` ne doit JAMAIS être dé-skippé sans implémenter la fonctionnalité métier sous-jacente.

---

## 6. Definition of Done — cycle CV1-CATALOG-CONVERGENCE-001

- [ ] Vague 1 complète (1.1 → 1.7) déployée en staging puis prod.
- [ ] Tous les sentinels Vague 1 passent ✅.
- [ ] `FK_CATALOG_UNIFIED_PROJECTION_SHADOW_COMPARE=true` posé en staging et 7 jours sans diff log.
- [ ] Tâches 2.2 → 2.5 mergées avec sentinels Vague 2 verts.
- [ ] Activation 2.6 effectuée en production.
- [ ] Soak 14 jours en `unified=true` sans incident.
- [ ] Cleanup 2.7 effectué.
- [ ] Cross-référence avec Mission #2 vérifiée (cf. `plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md`).

---

## 7. Hooks de garde-fou

**Avant tout PR sur ce cycle :**
- `scripts/audit-guard.sh` (existant) doit passer.
- Le pre-commit hook `safety-check.sh` doit valider qu'aucune frozen zone n'est touchée.
- Si un fichier dans la liste frozen est inadvertamment modifié, le PR doit s'arrêter et ouvrir un LOCK_*.md de justification (interdit dans ce cycle).

---

## 8. Risques résiduels et mitigations

| Risque | Mitigation |
|---|---|
| Divergence shape JSON entre legacy et adapté | Sentinel `PosCategoryProjectionParityTest` exigeant shape strict |
| Performance dégradée du nouveau path | Benchmarks avant/après dans `tests/Performance/MenuProjectionBenchmarkTest.php` (à créer si non existant) |
| Cache Kiosk désynchronisé du nouveau path | Vérifier que `InvalidateKioskMenuCacheOnCatalogChange` invalide `kiosk.menu.branch.{id}` à chaque mutation |
| Régression sur la virtual `id:0` | Sentinel explicite asserant la présence et la position [0] |

---

**Fin du plan CV1-CATALOG-CONVERGENCE-001.**
