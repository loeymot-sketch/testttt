# FOODKING — DUPLICATION MAP (synthèse X1 + observations cross-agent)

**Date** : 2026-05-17 | Source détaillée : `cross-cutting/X1-duplication.md`

## §0 Score & verdict

**Duplication score : 62/100**. Pas de catastrophe copy-paste, mais **~1800 LOC de potentiel consolidation** dont **~370 LOC low-risk shippable** en un sprint.

## §1 Top 7 duplications critiques (drift-risk)

| # | Type | Sites | LOC duplicate | Risque |
|---|---|---|---|---|
| **CRIT-1** | Dual Eloquent model même table | `app/Models/Order.php:13` ↔ `app/Models/FrontendOrder.php:13` (table `orders`, fillable divergent, restore-guard divergent, diningTable() différent) | ~180 | NF525-aggregate divergence latente |
| **CRIT-2** | Méthode métier dupliquée | `OrderService::myOrderStore:304` ↔ `FrontendOrderService::myOrderStore:131` | ~767 | Idempotency strategies différentes, pricing pre-assembly divergent |
| **CRIT-3** | Helpers privés copy-paste exact | `OrderService` ↔ `FrontendOrderService` : `safeJsonDecode`, `allocateQueueNumber`, `resolveBusinessDate`, `isQueueNumberUniqueViolation`, `saveOrderWithQueueNumber` | ~120 | Fix appliqué 1 fois, l'autre dérive |
| **CRIT-4** | Composer wizard triplicate | `KioskWizardComponent.vue` ↔ `pos-wizard.js` ↔ `mobile/screens-item-steps.jsx` | ~900 LOC fragmenté | Item cat IDs divergents (Vue 309/310/311/314 vs JSX 1-11) — drift garanti |
| **CRIT-5** | Listing methods overlap | 4 services listent orders avec foreach filter loops similaires : `OrderService::list/userOrder/deliveredOrder/deliveryBoyOrder` (lignes 116/189/225/262) | ~280 | Filter logic doit être patchée 4 fois |
| **CRIT-6** | Sync services boilerplate | `OssSyncService.js` ↔ `PosSyncService.js` ↔ `KdsSyncService.js` — `_jitter`, `_scheduleNext`, `_cleanup`, `_runtimeConfig`, `_bindWebSocketState` | ~250 | Polling fallback bug se reproduit 3 fois |
| **CRIT-7** | Wizard recap | Recap final commande ré-implémenté par surface (POS / Kiosk / Mobile) | ~150 | UX drift entre surfaces |

## §2 Healthy duplications (KEEP — patterns intentionnels)

- **10× `Persist*ToOutbox.php`** listeners — c'est le pattern Outbox correct. Pas duplication, c'est un template par event type.
- **9× Notification Builders** (`OrderMail`, `OrderGot`, `OrderDeliveryBoy` × Mail/SMS/Push) — matrice 3-axes documentée.
- **`KioskPosWizardComponent.vue`** = 15-ligne `<KioskWizardComponent v-bind="$attrs" />` wrapper (alias intentionnel, pas fork).
- **`app/Services/Order/` (workflow services)** vs **`app/Services/Orders/` (single allergen snapshot file)** — directories distincts par scope, pas duplication.

## §3 Dead code map

| Catégorie | Localisation | Volume |
|---|---|---|
| Archive directory | `_archive/` | 1.3 MB out-of-build |
| Backup files | `storage/backups/*.bak` | manuel cycle backups |
| Junk files working tree (créés par échecs shell quoting) | `,`, `[`, `Utilisateur non trouvé.,`, `L'article ne correspond pas.,` | 4 fichiers |
| Marginal wrapper | `OrderSetupService.php` (47-LOC, 1 caller) | inlinable |
| Versioned configs | `caisse_v1_rollout.php`, `catalog_v15.php` | 2 fichiers — consolider en feature flag registry |
| Abandoned subdirectory candidate | ~~`pos/v5/`~~ — **PAS abandonné** (actively imported par PosComponent.vue:56-138) | confirmé vivant |
| Orphan EventType constants | `ORDER_ITEM_ADDED`, `ORDER_CANCELLED`, `STOCK_LOW` (no producers) | 3 dans BROADCAST_MAP |

**Total dead code productif** : < 200 LOC. Mostly nettoyable trivialement.

## §4 Top 5 consolidations recommandées

| # | Cible | LOC sauvés | Risque | Effort |
|---|---|---|---|---|
| **CONS-1** | Collapse `FrontendOrder` → `Order` unique | ~700 | XL (NF525 fiscal touché) | 4 sem + LOCK doc |
| **CONS-2** | Extract `OrderHelpersTrait` (5 helpers dupliqués CRIT-3) | ~120 | LOW | 1 sprint indépendant |
| **CONS-3** | Base class `_BackoffPollingService.js` pour 3 sync services | ~250 | MEDIUM | 1 sprint |
| **CONS-4** | Refactor 9 Notification Builders avec factory | ~250 | LOW | 1 sprint |
| **CONS-5** | Composer wizard layer commun (POS+Kiosk+Mobile) | ~900 | HIGH (3 surfaces dont 2 frozen) | 6-8 sem multi-LOCK |

**Total potentiel** : ~2220 LOC.
**Shippable LOW-risk en 1 sprint** : ~370 LOC (CONS-2 + CONS-4).
**Restant high-leverage** : ~700 LOC (CONS-1 — collapse Order = highest impact mais long).

---

**Signature** : Synthèse de `cross-cutting/X1-duplication.md` (446 lignes détail). Cross-référence cite chaque file:line.
