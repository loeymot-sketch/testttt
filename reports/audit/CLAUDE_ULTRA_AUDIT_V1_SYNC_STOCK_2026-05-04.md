# Ultra Audit V1 FoodKing — Synchronisation + Stock — 2026-05-04

| Champ | Valeur |
|---|---|
| Date | 2026-05-04 ~20:30 UTC+2 |
| Auteur | Claude terminal (Opus 4.7 advisor + Sonnet 4.6 xhigh effort) |
| Source prompt | Prompt orchestrator FoodKing « Ultra Audit Synchronisation + Gestion Stock V1 » 2026-05-04 ~20:21 UTC+2 |
| Trace brute | `terminals/4.txt:159-714` (session terminal Claude pre-quota-limit) |
| Méthode | Revue statique 4 blocs en parallèle (events/dispatch • controllers/channels • frontend Echo • tests) + lectures directes pour confirmation. **Aucun test runtime exécuté** — validation empirique staging requise. |
| Verdict synthèse | **HEAL** (score 41/50 = 82% — au-dessus seuil "heal acceptable" 75%, sous seuil "continue sans réserve" 90%) |
| Statut master de healing | `CV1-V1.5C-SYNC-STOCK-HEAL-MASTER` créé 2026-05-04 ~21:00 UTC+2 — voir `plans/PLAN_CV1-V1.5C-SYNC-STOCK-HEAL-MASTER_2026-05-04.md` |

---

## 1. Architecture chaîne complète observée

### Toggle ingrédient

```
[ADMIN] toggle ingredient
  └─→ IngredientController::toggleAvailability (routes/api.php:649-659, perm:ingredients_manage)
      └─→ IngredientAvailabilityService::toggle (DB::transaction)
          └─→ IngredientAvailabilityChanged::dispatch
              └─[after-commit via DispatchableAfterCommit trait]
                  └─→ Listener InvalidateMenuProjectionOnIngredientChange
                      ├─ Cache::forget('kiosk.menu.branch.{branchId}')
                      ├─ MenuSnapshot::bump(branchId)
                      └─ CatalogChanged::dispatch
                          └─→ Listener PersistCatalogChangedToOutbox
                              ├─ DomainEvent row → channel='private-branch.{branchId}'
                              │   broadcast_as='CatalogChanged'
                              └─[DB::afterCommit] DispatchDomainEventsJob (queue)
                                  └─→ Pusher broadcast
                                      ├─→ POS PosComponent.vue:1809 → re-fetch
                                      └─→ Kiosk KioskAppComponent.vue:481 → re-fetch
```

### Toggle item / branch availability

```
[ADMIN] toggle item / branch availability
  └─→ AvailabilityController::toggle (perm:items_edit + branch scope check L43-49)
      └─→ ItemAvailabilityChanged → PersistItemAvailabilityChangedToOutbox
          └─→ broadcast 'ItemAvailabilityChanged' on private-branch.{branchId}
              ├─→ POS    PosComponent.vue:1808
              ├─→ Kiosk  KioskAppComponent.vue:475
              └─→ KDS    KitchenDisplaySystemComponent.vue:1243
```

### Décrément stock à l'order

```
[ORDER] decrement stock
  └─→ StockService (lockForUpdate L86-91 + idempotency_key)
      └─→ syncItemAvailabilityForStockLevel
          └─→ ItemAvailabilityChanged::forBranch(itemId, branchId, false, 'stock_rupture')
              └─→ même chaîne broadcast
```

**Verdict architectural** : ✅ L'invariant **I4** (dispatch après commit) est tenu, l'invariant **I3** (branch isolation broadcast) est tenu, l'**outbox absorbe les pannes Pusher transitoires**.

---

## 2. Trace scénarios S1-S8 (résumé)

| # | Scénario | Verdict | Gaps |
|---|---|---|---|
| S1 | Ingrédient rupture | ✅ TENU (path complet) | S1.g/h re-validation submit à confirmer (cf. R1) |
| S2 | Restock | ✅ Symétrique S1 | OK |
| S3 | Stock numérique → 0 | ✅ TENU | priorité ingredient_rupture > stock_rupture confirmée (`ChoiceAvailabilityResolver.php:295-312`) |
| S4 | Item désactivé | ✅ TENU | pas de visuel "grisé vs caché" testé Playwright |
| S5 | Catégorie cachée | ⚠️ PARTIEL | pas d'event catégorie dédié, propagation via CatalogChanged global non testée |
| S6 | Addon rupture | ⚠️ PARTIEL | coverage 20% — pas de test composer wizard avec addon désactivé |
| S7 | Multi-bornes simultanées | ✅ backend tenu | pas de test Playwright multi-borne, pas de test ordering sous storm |
| S8 | Offline → reconnect | 🔴 **DEFECT** | reconnect ne déclenche pas re-fetch menu — polling 30s > SLA 5s |

---

## 3. Score checklist 50 points

| Section | Score | Notes |
|---|---|---|
| A. Événements + dispatch | 10/10 | DispatchableAfterCommit + branch-scoped channel + sentinels |
| B. Cache + projection | 8/10 | B3 TTL non explicite, B5 fan-out scalabilité, B6 R-T3 dette, B7 race LOW |
| C. Frontend POS | 7/10 | C7/C8 re-validation submit non confirmée (CRITIQUE), C10 test latence absent |
| D. Frontend Kiosk | 7/10 | D4 reconnect re-fetch absent, D8 offline queue ne re-fetch pas |
| E. Stock + invariants | 9/10 | E7 symétrie OrderService/FrontendOrderService à confirmer (couplé C7/C8) |

**TOTAL : 41/50 (82%)** — verdict HEAL.

---

## 4. Défauts résiduels priorisés

### 🔴 BLOQUANT V1 (cycle V1.5c)

| # | Sev | Défaut | Action | Mappé sur cycle |
|---|---|---|---|---|
| **R1** | CRITIQUE | C7/C8 — Re-validation serveur disponibilité au submit non confirmée file:line dans `OrderService` / `FrontendOrderService`. Sans elle, contrat SSOT étendu rompu : kiosk avec menu stale peut soumettre order avec ingrédient en rupture. | TRACE puis NO_OP test sentinelle OU patch + gate frozen | **R1** master V1.5c |
| **R2** | MOYEN | D4/D8/S8 — Reconnect après perte WS ne re-fetch pas menu. Polling fallback 30s > SLA 5s business. | Patch `WebSocketService.js` + bind `KioskAppComponent.vue` / `PosComponent.vue` : sur `state_change → CONNECTED` (was DISCONNECTED), `dispatch('item/lists', { branch_id })` + `dispatch('menu/refresh')` immédiat. | **R2** master V1.5c |
| **R3** | CONFIG | `BROADCAST_DRIVER` env non vérifié — production risk silencieux si `null`/`log` casse toute la chaîne broadcast sans alarme. | Sentinel CI `tests/Feature/Config/BroadcastDriverConfiguredTest.php` — assert `config('broadcasting.default') in ['pusher','redis','ably']` en env != local/testing. | **R3** master V1.5c |

### ⚠️ HEAL post-cutover (différé V1.5d)

| # | Sev | Défaut | Effort estimé |
|---|---|---|---|
| E1 | MEDIUM | C10 — Test Playwright `v1-broadcast-latency.spec.js` mesurant <5s admin→POS+kiosk | M (3h) |
| E2 | MEDIUM | S6 addons coverage 20% — test composer wizard avec addon désactivé | M (4h) |
| E3 | MEDIUM | Cross-branch isolation broadcast sentinel — assert Branch A toggle ne reach pas Branch B clients | M (3h) |
| E4 | LOW | Concurrent admin toggles test — race condition rare non couverte | S (2h) |
| E5 | LOW | B6 — `ComposerProfileProjection` cache R-T3 V1.5c+ pattern branch_id à confirmer | M (4h) |

### 🟢 OK (notation pour mémoire)

- Architecture outbox + `DispatchableAfterCommit` excellente
- Branch scope cache + channel cohérent
- Priorité `ingredient_rupture` > `stock_rupture` correcte (`ChoiceAvailabilityResolver.php:295-312`)
- `StockService` `lockForUpdate` + `idempotency_key` solide
- a11y kiosk OK (axe-core + keyboard)
- 10/10 tests A — events + dispatch parfaits

---

## 5. Verdict final audit

**Décision : HEAL** (pas BLOCK, pas CONTINUE).

**Raison** :
- L'architecture du contrat business (toggle admin → propagation POS+kiosk) est **structurellement correcte** : outbox + `DispatchableAfterCommit` + Pusher branch-scoped + cache invalidation + frontend Echo listeners en place.
- Mais **3 risques empêchent le verdict "continue"** :
  - **R1** (CRITIQUE) : la re-validation serveur au submit non confirmée file:line. Sans elle, contrat SSOT étendu non tenu.
  - **R2** (MOYEN) : reconnect WS ne re-fetch pas le menu → 30s stale > SLA 5s.
  - **R3** (CONFIG) : `BROADCAST_DRIVER` env non vérifié — risque silencieux production.

**Healing demandé (Cursor)** :
1. Tracer file:line `OrderService::placeOrder` ou équivalent + confirmer re-check `ChoiceAvailabilityResolver` sur chaque ligne au submit. Si absent → patch + test (gate frozen requis).
2. Ajouter dans `WebSocketService.js` ou `KioskAppComponent.vue` un handler `state_change → CONNECTED (was DISCONNECTED)` qui force `dispatch('item/lists')` + `dispatch('menu/refresh')`.
3. Ajouter sentinel `tests/Feature/Config/BroadcastDriverConfiguredTest.php`.
4. (différé V1.5d) Playwright `v1-broadcast-latency.spec.js`.

**Pas de healing > 3 cycles consécutifs** — limite doctrine respectée (premier cycle audit dédié sync/stock).

**Escalation humaine** : non requise. Les défauts sont tracés et patchables sans révision architecturale.

---

## 6. Mémoire

✅ Findings critiques sauvegardés dans Graphiti `group_id=foodking` (épisode "V1 Sync Audit 2026-05-04 — Architecture chaîne complète").

Reférence Cursor (cycle de healing) : `plans/PLAN_CV1-V1.5C-SYNC-STOCK-HEAL-MASTER_2026-05-04.md`.

---

## 7. Note sur la complétion du rapport

Le terminal Claude a atteint sa limite quota Anthropic juste après livraison du verdict. Le rapport ci-dessus reproduit fidèlement la trace `terminals/4.txt:159-714` synthétisée en markdown clean. Aucune information n'a été perdue. La trace brute est conservée dans le répertoire des terminaux pour audit forensique futur.

---

**Auteur** : Claude terminal (Opus 4.7 advisor + Sonnet 4.6 high effort xhigh)
**Archivage** : Cursor orchestrator (Claude session) 2026-05-04 ~21:00 UTC+2
