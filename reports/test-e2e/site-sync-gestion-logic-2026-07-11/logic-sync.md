# Audit adversaire LOGIQUE — synchronisation cross-système (state propagation)

Date 2026-07-11 · Backend live :8766 · MySQL `foodking` (2867 orders, 2 stock_levels, 103 stock_movements, 10188 domain_events) · Lecture seule + tinker (toutes mutations en transaction `rollBack`). Contrat = `SYNC_CONTRACT.md`.

Portée : invariants de cohérence d'état entre borne/caisse/KDS/OSS/écran-client. Pas la latence (déjà auditée).

---

## FINDINGS

### P1 — SYNC-LOGIC-01 : un item en rupture reste COMMANDABLE via la formule menu (garde checkout = top-level only)

**Invariant violé (Q4)** : « un item en rupture reste-t-il commandable quelque part ? » → **OUI**, via un menu qui le contient comme composant.

**Cause** — La garde d'orderabilité `AvailabilityService::assertItemsOrderableForBranch($branchId, $requestedItemIds, true)` est appelée sur les 2 chemins d'écriture :
- `app/Services/FrontendOrderService.php:363` (borne/web)
- `app/Services/OrderService.php:448` / `:929` / `:1504` (POS)

Mais `$requestedItemIds` ne contient que les items de **premier niveau** :
`FrontendOrderService.php:330` / `OrderService.php:428,912,1486` :
`$requestedItemIds = collect($requestItems)->pluck('item_id')->filter()->unique()->toArray();`

Les items **composants d'une formule** (`composition_snapshot.addons[].addon_item_id`) ne sont JAMAIS re-validés au checkout. Asymétrie avec le chemin de LECTURE `app/Services/Menu/MenuProjectionService.php:255-267` (`projectAddons`) qui, lui, filtre bien les composants 86 → la borne les cache, mais l'écriture ne les bloque pas. Même angle mort dans `AvailabilityService::decrementForOrder` (quota journalier auto-86) : `foreach ($order->orderItems as $line) … $line->item_id` uniquement → les ventes d'un composant via menu ne comptent pas vers SON cap journalier.

**Repro réelle (tinker, rolled-back — EXP D)**
```
item#2 marqué rupture (is_available=false, manual_rupture) branch 1
guard([1]) PASSED   ← item#2 comme addon NON vérifié (GAP)
guard([2]) BLOCKED  ← "Article 2 indisponible pour cette branche (manual_rupture)."
```
**Reachability prouvée (DB prod)** : 71 `order_items` portent `addon_item_id`. Ex. `oi#5422` : top item=26, `addons=[item=1 role=menu_boisson]`. Item#1 est stock-tracké (`stock_levels#1`, on_hand=914) et **auto-86-capable** (voir EXP B). Donc : item#1 (boisson) passe en rupture (manuel OU stock→0) ⇒ le client peut quand même commander le menu#26 qui l'inclut.

**Impact** — Pas de corruption de stock : `StockService::requirementsForOrderItem` décrémente bien le composant (decode `addons`), et si `on_hand < qty` il **throw `StockUnavailableException` → catch+log** (`DecrementStockOnOrderCreated`) → la commande passe, on_hand ne devient pas négatif. Mais la cuisine reçoit un menu avec une boisson en rupture (survente du composant 86). Race aggravante : composant ajouté au panier PUIS passé 86 → checkout ne l'attrape pas.

**Fix (non-frozen)** — Étendre `$requestedItemIds` avec les `addon_item_id` de chaque ligne (les 2 services) AVANT l'appel garde ; idem pour la portée quota de `AvailabilityService::decrementForOrder`. Ajouter un sentinel : « menu avec composant 86 → checkout 422 ». Aucun test actuel ne couvre ce cas (`grep assertItemsOrderable tests/` = vide côté composant).

---

### P3 — SYNC-LOGIC-02 : 16 lignes outbox `loyalty.balance_changed` empoisonnées, jamais délivrées (résidu de feature retirée)

`domain_events` : `loyalty.balance_changed` total=16, dispatched=**0**, contract_violation=**16** (créées 2026-06-12→06-14, **aucune depuis**). `EventContract::assertEnvelopeValid` rejette : le `type` n'est pas dans l'allowlist V1 (…|kds.order_recalled — pas de type loyalty). Producteur **absent du code** (`grep LoyaltyBalanceChanged app/` = vide). Outbox live SAIN (0 pending <24h). Déjà exclu du gate `/health/ready` par le plancher 24h.

**Impact** : nul aujourd'hui. **Backlog** : si le push WS fidélité est réintroduit, ajouter le type à `EventContract` sinon 100 % rejet (les soldes ne se propageront jamais en temps réel — les clients dépendraient d'un re-fetch). Purger/marquer les 16 lignes.

---

### P3 — SYNC-LOGIC-03 : 16 commandes figées en statut hors-enum (5×15, 2×1), invisibles sur TOUS les tableaux

15 orders `status=5`, 1 order `status=2` — absents de `OrderStatus` {1,4,7,8,10,13,16,19,22}. Donc hors `KitchenReleaseRule::visibleStatuses()` (4,7,8), hors OSS (7,8), et `OrderStateMachine::allows()` `default: return false` → **impossible d'en sortir**. Dernière = 2026-06-17, aucune récente → résidu legacy/test. Impact live faible, mais classe de « zombie permanent ». **Fix** : purge hygiène + sentinel « aucune commande live en statut hors-enum ».

---

### Observation (pas un défaut) — couplage `released_qty`

`StockService::releaseForOrderInTransaction` LIT `order_items.released_qty` (`:381`) mais ne l'ÉCRIT jamais ; le ledger est avancé par `AvailabilityService` (`:768`), qui tourne APRÈS StockService dans la même cascade (EventServiceProvider Stock-avant-Availability). Le double-release reste idempotent via `stock_movements.idempotency_key` (EXP A). Edge P3 : si le listener availability throw (catché), `released_qty` non avancé → un refund partiel identique suivant réutilise la même clé mouvement et saute le re-crédit. Faible probabilité.

---

## INVARIANTS QUI TIENNENT (preuves)

| Invariant | Preuve |
|---|---|
| **Décrément idempotent** (Q1) | EXP A : 2× `decrementForOrder` ⇒ 1 mouvement, on_hand -2 stable. DB prod : 0 clé idempotency dupliquée / 103 mouvements. Sentinel `StockMovementsAppendOnlyTest` (UNIQUE). |
| **Release re-crédite 1×, double-release no-op** (Q1/Q3) | EXP A : release +2 → baseline ; release#2 inchangé. `StockReleaseOnCancelTest`, `StockReleaseOnRefundTest` verts. |
| **Flip dispo à on_hand=0, exactement 1×** (Q4) | EXP B : `is_available=false reason=stock_rupture`. `AvailabilityDecrementConcurrencyTest::serialized concurrent decrements dispatch flip event once` vert. |
| **Décrément concurrent dernier item** (Q1) | EXP C : throw `StockUnavailableException`, aucun mouvement, on_hand=0 (jamais négatif). `StockConcurrentDecrementTest::stress guard allows only 20 successes across 50 attempts` vert (lockForUpdate + FOR UPDATE). Survente tolérée by-design (commande passe, stock non-négatif). |
| **Propagation statut KDS↔OSS cohérente** (Q2) | Les 2 lisent `orders.status` + partagent `KitchenReleaseRule::applyBoardReleaseFilter` + fenêtre identique (staleFloor/tomorrow/advance) ⇒ pas de divergence logique (seulement ≤5 s de poll). `OssKdsMidnightStraddleTest::precommande en retard reste visible sur les quatre chemins` + `OssBoardReleaseParityTest` verts. |
| **Cascade annulation** (Q3) | CANCELED(16) exclu de KDS `visibleStatuses` + OSS + `KdsSyncService` `deleted_ids` ⇒ retiré partout. `OssBoardReleaseParityTest::delivered transition removes from oss` vert. |
| **composition_snapshot figé** (Q5) | Gelé à la création, chemin de lecture rend DEPUIS le snapshot (SYNC_CONTRACT HIST-10). KDS `orderItems` groupe par hash `allergens_snapshot` — même snapshot des 2 côtés. |
| **Idempotence outbox** (Q6) | `DispatchDomainEventsJob` : claim atomique (`lockForUpdate` + garde `dispatched_at`) ⇒ pas de double-broadcast au succès. At-least-once au échec absorbé par consumers id-keyed + listeners idempotents (movement key, `Cache::add` SETNX quota). |

Tests exécutés cette session : Stock+Availability **64 passed / 4 skipped** ; parité+concurrence **18 passed / 4 skipped**.
