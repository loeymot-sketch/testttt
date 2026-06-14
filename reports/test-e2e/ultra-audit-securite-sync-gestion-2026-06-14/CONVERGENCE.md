# ULTRA-AUDIT ABUSIF — Sécurité · Synchronisation · Gestion (+ caché/indirect/réactif) — VERDICT

**Date:** 2026-06-14 · **Tree:** spine `release/v1-integration` @ `1dc5785a5` · clone `foodking_2dot0:8780`.
**Run:** `wf_d15599db-91f` (13 agents : 8 lanes adversaires + dispute ≥2-sceptiques + completeness-critic). Raw: `round-1/W1-ultra-audit.json`. Plan: `plans/GOAL_ULTRA_AUDIT_SECURITE_SYNC_GESTION_2026-06-14.md`.

## Couverture (abusive) — surface caché/indirect/réactif balayée
8 lanes × 4 lentilles : SEC-AUTHZ · SEC-HARDEN · SYNC-LISTENERS · SYNC-CRON · SYNC-CLIENT · GES-NUMBERS · GES-MGMT-UX · REACTIVE-HIDDEN. Cible réelle balayée : **39 events × 46 listeners, 3 observers, 4 jobs, 44 commands + 41 tâches schedulées, 71 FormRequests return-true, 22 middleware, 4 JS sync-services, channels branch.{id}**.

## Résultat : **1 P1 confirmé (2/2) + 10 P2/P3 + 1 réfuté.** Concentré sur le réactif/caché (exactement la cible).

### ✅ HEALÉS (TDD, frozen 0)
| # | Sev | Lane | Fix | Commit |
|---|---|---|---|---|
| UA-P1 | **P1 sécu** | SEC-HARDEN | **forgot-password enumeration oracle** (200 known vs 400 unknown) → 200 neutre identique pour tous, travail DB only pour vrai user (le kiosk-login durci, ce chemin pas) | `11e6007e7` |
| UA-P2-sim | P2 sécu/data | SYNC-CRON | **`kiosk:simulate-orders` sans garde prod** (injecte des commandes PAID fictives + notifs) → hard-refuse en production | `156976cb2` |

### ❌ RÉFUTÉ (dispute 0/2)
- REACTIVE-HIDDEN : « kiosk loyalty redeem phantom discount » → réfuté (le ledger réconcilie).

### 📋 BACKLOG P2/P3 (prioritisé — réactif/caché)
**Réactif/sync (haute valeur) :**
- **P2 SYNC-LISTENERS — `PersistOrderCreatedToOutbox` premier dans la cascade OrderCreated avec `firstOrCreate()` NON-gardé** (`app/Listeners/PersistOrderCreatedToOutbox.php:26-50`, registered FIRST `EventServiceProvider:178`) : un throw DB y halte le dispatcher → skip stock/availability decrement (**survente**) + 500 au POS. *Fix : try/catch + envisager ordre listener (decrement avant outbox).* → heal candidat.
- **P2 SYNC-CRON — `CleanupStalePendingKioskOrders` suppression notif sur MAUVAISE colonne** (`source==10` dans les builders vs sélecteur `source_surface='kiosk'` du job, `Jobs/CleanupStalePendingKioskOrders.php:135-138`) : l'auto-reject peut spammer Mail/SMS/Push. *Fix : aligner la garde de suppression.* → heal candidat.
- **P2 GES-NUMBERS / ZReportCashEnrichment** : bornes fenêtre Z-précédente divergentes (`<=`+id-exclusion vs `<`) → POS-cash window ≠ delivery-cash window pour le même Z (`Fiscal/ZReportCashEnrichmentService.php:256-257 vs :327`).
- **P2 (completeness-critic) — stock double-crédit** : `ReleaseStockOnOrderCanceled` + `ReleaseStockOnRefundCreated` appellent tous deux `StockService::releaseForOrder` avec un `$reason` différent → `on_hand` double-crédité sur cancel-then-refund. *Fix : release idempotent par order.*
- **P2 (mon cross-check superviseur) — Z-window late-salvage** de mon heal G-DELIV-FISCAL : retry-cron qui alloue un seq COD APRÈS la clôture Z@23:59 → la vente échappe au Z (`SUPERVISOR_CROSSCHECK.md`).

**Gestion/UX :**
- **P2 GES-MGMT-UX — RBAC nav fail-OPEN** (`BackendMenuComponent.vue:235-247` `userHasPermissionUrl` renvoie true sur payload vide / permission absente → liens orphelins POS-operator). *Fix : fail-closed (needs rebuild).*
- **P2 GES-MGMT-UX — Rapport Articles inclut la catégorie interne admin-only** (combo upsell technique classé #1, écrase les vrais produits — `ItemsReportController:34` → `ItemService:622`). *Fix DATA/query : exclure la catégorie interne du rapport.*
- P3 — format heure 12h AM/PM non-FR (Historique / Sales-report) = DATA config TIME_FORMAT.

**Sécurité (gated/mineur) :**
- **P2 SEC-HARDEN — env() CURRENCY hors config** (`OrderItemResource:52`, `AppLibrary:289+`) cassé sous config:cache prod — **sibling de #16 UNI-03** → gate cloud-prep.
- P3 — `ApiKeyMiddleware:24` comparaison `===` non-constant-time (timing très faible) ; login timing oracle ~35ms (`LoginController:68`, dummy-hash recommandé).
- P2 — WCAG contraste blanc/#F4501E (= G-WCAG, owner gate brand).

## VERDICT (§10) : **HEAL** — 1 P1 sécu fermé + 1 P2 data fermé ; backlog réactif/caché documenté file:line+repro (heal candidats : outbox-unguarded, cleanup-notif, stock-double-credit) ; gated : env-currency #16, WCAG, Z-window. Aucun frozen touché. Spine reste shippable au niveau bloquant (0 P0 ; le seul P1 = healé).
**Owner gates** : G-FROZEN (#16 env-currency sibling), G-WCAG, G-CRON (cleanup-notif), G-PUSH/G-OVH.
