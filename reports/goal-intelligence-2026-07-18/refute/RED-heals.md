# RED-TEAM — charge hostile contre les 5 heals du registre (range 57df489ce..HEAD)

> Mission : casser / prouver incomplets les 5 fix. Read-only (aucune modif repo/DB dev).
> Serveur :8000 UP, DB foodking_e2e (tinker read-only), suite PHPUnit sur sqlite :memory:.
> **Verdict global : 0 P0, 0 P1.** Les 5 fix RÉSISTENT. 1 effet de bord P2 (test-infra deploy),
> 4 nitpicks P3. Détail + preuves ci-dessous.

---

## Fix 1 — `8a67523be` SalesReportController P1-5 — **ROBUSTE**

Attaques tentées :
- **Route overview réellement gardée ?** `routes/api.php:1169` → `/overview` bind `salesReportOverview`.
  Constructeur `SalesReportController.php:43` : `->only('index','export','pdf','salesReportOverview')`.
  Le middleware `permission:sales-report` matche par NOM DE MÉTHODE → maintenant aligné sur la vraie
  méthode. GATE EFFECTIF.
- **Casse l'accès légitime ?** NON. `sales-report` accordé à Admin (`Permission::all()`) + Branch
  Manager (`RolePermissionTableSeeder.php:76`). POS Operator / Chef / Livreur NE l'ont PAS → 403
  attendu (c'était justement la fuite). Aucun rôle légitime perdu.
- **Autre méthode sur/dé-gardée ?** NON. Les 4 seules méthodes publiques exposant des données sont
  index/export/pdf/salesReportOverview, toutes dans la liste. Pas d'autre route vers l'agrégat CA
  (`SalesReportOverviewResource`) — seul `api.php:1169` l'expose (les autres `*overview` = stock/cash/sync,
  contrôleurs & gates distincts).
- **Sentinelle contournable ?** NON — durcie avec `method_exists()` : un `->only('nom-inexistant')` fait
  désormais échouer le test (le bug exact de REP-AUTHZ-01 est verrouillé).

**Preuve** : `MgmtReadAuthzGateSentinelTest` 2/2 PASS ; route+seeder lus.

---

## Fix 2 — `8b560c920` KDS onKey + scheduler continuité P2-k/P1-2 — **ROBUSTE**

Attaques tentées :
- **[A][B][C] cassées ?** NON. Grille rend `v-for="(o,idx) in visibleActiveOrders"`
  (`KdsV2Grid.vue:64`), `visibleActiveOrders() = activeOrders.slice(0,3)` (:251). `onKey` borné à
  `visibleActiveOrders` (:310-311) → A/B/C mappent EXACTEMENT les 3 cartes rendues.
- **Edge 0/1/2/3/4+ cartes ?** Correct partout : `idx < visibleActiveOrders.length`. 0→aucune touche ;
  1→A ; 2→A,B ; 3→A,B,C ; 4+→A,B,C (les 4+ derrière la pastille +N, jamais bumpables — c'était le bug).
  D–H = no-op déterministe.
- **Scheduler casse le boot / cron invalide / overlap verify-chain ?** NON. `schedule:list` liste la
  lane `fiscal:verify-sequence-continuity` à `35 3 * * *` (03:35), APRÈS chain-monitor 03:30, AVANT
  z-membership 06:05 — pas de collision, `withoutOverlapping+onOneServer`. Boot OK.
- **La commande plante si lancée ?** NON. Lancée read-only sur foodking_e2e : elle a DÉTECTÉ un vrai trou
  (branche 1 : 2506-2508 manquants) et **exit code = 1** (vérifié `> /dev/null; echo $?` → 1). Donc
  `onFailure` du scheduler raise bien le `Log::error` pageable. Alarme FONCTIONNELLE (pas inerte).

**Preuve** : vitest `kds-v2-grid-keys` 5/5 + `kdsV2ArchiveSlotSentinel` 9/9 PASS ; `schedule:list` ;
exit-code réel = 1.

*P3 nitpick* : le docstring d'en-tête du sentinel (`kdsV2ArchiveSlotSentinel.spec.js:27`) dit encore
« renders activeOrders.slice(0, 8) » alors que le CORPS du test assert correctement `visibleActiveOrders`
+ `slice(0,3)`. Commentaire périmé uniquement, test vert.

---

## Fix 3 — `caf9f06df` kioskUpsell exclusion required/86 P1-4 — **ROBUSTE**

Attaques tentées (live `GET /api/frontend/item/kiosk-upsell?limit=6`, header `x-api-key`, 5 appels) :
- **Pool vide / trop pauvre ?** NON. **6 items retournés à CHAQUE appel** (5/5), rotation aléatoire :
  boissons (Sprite/Fanta/Coca Zero/Orangina/Capri-Sun/Oasis/Eau Plate) + desserts (Tiramisu/Glace/
  Tarte Daim). Pool sain.
- **Items simples conservés ?** OUI (tous ceux ci-dessus). **Items 40 (Menu Enfant Nuggets) & 106
  ABSENTS** sur les 5 appels (composition requise → correctement exclus, plus de 422 au paiement).
- **Sur-exclusion d'un `min_select=0` (optionnel) ?** NON. `composeRequiredItemIds()` filtre
  `ItemAttribute.min_select >= 1` uniquement — les attributs optionnels (min_select=0) ne sont pas
  matchés. Les boissons/desserts (sans attribut requis) restent dans le pool (prouvé live).
- **`applyChannelsFilter` private→public casse un autre appelant ?** NON. Élargir la visibilité ne
  change pas les appelants existants (`simpleList`/`featuredItems`/`itemDetails` appellent pareil).
  `$this->itemService` est bien injecté (`ItemController.php:17-21`) → pas de fatal.
- **N+1 / perf ?** NON. `composeRequiredItemIds` (3 requêtes) + `branchUnavailableItemIds` (1 requête)
  s'exécutent UNE fois par appel upsell, pas par item. Coût constant.

**Preuve** : 5 appels live (pool=6, 40/106 absents, items simples stables).

---

## Fix 4 — `f2de0d2c9` trigger orders_no_delete_when_fiscalized P1-1 — **ROBUSTE (code) ; 1 effet de bord P2 (test-infra)**

Attaques tentées :
- **Bloque un soft-delete légitime (annulation POS/borne) ?** NON. Trigger = `BEFORE DELETE`. Le
  soft-delete Eloquent = `UPDATE deleted_at` → le trigger NE FIRE JAMAIS. Vérifié : `PosOrderDestroyTest`
  4/4 PASS, y compris **« can destroy paid order »** (order PAID/fiscalisé, destroy soft OK).
- **Casse forceDelete légitime ?** NON pour non-fiscalisé. `OrderFactory` ne pose PAS
  `fiscal_sequence_no` (null par défaut) → `PosOrderRestoreIntegrityTest::test_force_deleting_a_trashed_order`
  3/3 PASS. Le trigger ne mord QUE `fiscal_sequence_no IS NOT NULL`.
- **Cascade delete (order→order_items) impacté ?** NON. Trigger sur `orders` avorte AVANT la cascade →
  cohérent. `order_payments` a déjà une FK restrictOnDelete → double garde.
- **Compteur 9→10 casse un autre test ?** NON. Seul `FiscalVerifyImmutabilityTriggersCommand::EXPECTED_TRIGGERS`
  fait autorité (map partagée installer/verifier), mis à jour dans le même commit. Pas de « 9 » hardcodé ailleurs.
- **down() sûr ? parité SIGNAL/RAISE ?** OUI. `down()` throw en prod ; hors-prod drop. MySQL
  `SIGNAL 45000` / SQLite `RAISE(ABORT)` = parité d'avortement correcte, `SqliteMysqlParitySentinel` MAJ.
- **Purge cmd test cassée ?** NON pour les commandes app : `Iter15CleanupTestOrdersCommand:94`
  (`->whereNull('fiscal_sequence_no')`) ET `CleanupWebTestOrdersCommand:39` (idem + soft-delete)
  GARDENT DÉJÀ les fiscalisés. `Iter15CleanupFiscalGuardTest` 3/3 PASS.

**Preuve trigger fonctionnel** : `OrdersNoDeleteWhenFiscalizedTest` 3/3 PASS (hard-delete fiscalisé
REJETÉ, non-fiscalisé AUTORISÉ, trigger présent). Régression : Fiscal dir **275 pass / 8 skip**
(MySQL-only), Order dir **56/56**.

### [P2] `reports/.../RED-heals` — effet de bord test-infra e2e, PLUS LARGE que la note du commit
- **Repro** : `wave-final-S2-pos.spec.js` pose `payment_status=PAID` (l.104) puis `forceDelete()` (l.148).
  **36 specs e2e** (`tests/e2e/*.spec.js`) nettoient avec `DB::table('orders')->...->delete()` /
  `Order::...->forceDelete()` **SANS garde `whereNull('fiscal_sequence_no')`**.
- **Impact** : une fois la migration jouée sur la DB live (foodking_e2e / prod), tout order de test
  AYANT traversé un vrai encaissement (`confirmCounterPayment` alloue `fiscal_sequence_no`) fera
  **échouer le cleanup e2e** par `SIGNAL 45000`. Le commit ne cite que « purge 186 cmd test » (Iter15,
  déjà gardée) — le vrai angle mort = les cleanups e2e INLINE, non gardés.
- **Sévérité P2 (pas P1)** : (a) NON migré sur foodking_e2e aujourd'hui → aucune casse live ; (b)
  gate-owner explicite avant deploy ; (c) test-infra dev, pas le money-path prod ; (d) un order de test
  fiscalisé DEVRAIT de toute façon être soft-deleté. **Reco** : avant migration, ajouter aux cleanups
  e2e le même `->whereNull('fiscal_sequence_no')` que les 2 commandes app, ou soft-delete.

---

## Fix 5 — `707c5a6e0` web encaissable + refund gate + scan durci P1-3/P2-e/P2-u — **ROBUSTE (le plus attaqué)**

### P1-3 — chemin d'encaissement web
Chaîne de gardes de `confirmCounterPayment` (PaymentService.php) tracée intégralement :
1. `assertCounterOrderVisible` (isolation branche)
2. `if payment_status === PAID` → **409 / no-op** (K2-HEAL-01) → **double-encaissement bloqué**
3. `assertCounterDeferredOrder` → exige surface∈[kiosk,pos,phone,web] **ET** `payment_method=CASH_ON_DELIVERY`
   **ET** `pos_payment_method=COUNTER_DEFERRED`
4. `PaymentStateMachine::assertCanTransition(status,PAID)` — table (`PaymentStateMachine.php:9-19`) :
   `UNPAID→PAID`, `PENDING_COUNTER→{PAID,REFUNDED}`, **`PAID→[]`, `REFUNDED→[]`** (terminaux)
5. garde statut terminal → `CANCELED/REJECTED/RETURNED` → 422

Attaques tentées :
- **Web CARTE (pas COD) différée par erreur ?** NON. Marqueur COUNTER_DEFERRED posé (OnlineOrderController
  changeStatus:163-166) SEULEMENT si `payment_method === CASH_ON_DELIVERY`. Une carte web (≠COD) échoue
  aussi `assertCounterDeferredOrder` (garde #3) → jamais encaissable au comptoir.
- **Web déjà PAID atteint un encaissement indu ?** NON — garde #2 (409). Après collecte,
  `pos_payment_method` devient CASH/CARD (≠COUNTER_DEFERRED) → garde #3 échoue aussi. Double verrou.
- **Web annulée / remboursée encaissable ?** NON. CANCELED→garde #5 ; REFUNDED→garde #4 (REFUNDED n'a
  aucune transition sortante) ; UNPAID→REFUNDED impossible aussi.
- **Double-encaissement livraison + comptoir ?** NON. Le marqueur COUNTER_DEFERRED n'est posé que sur
  **TAKEAWAY** ; la LIVRAISON web ne l'obtient pas → absente de la file counter-collect
  (`api.php` clause web exige COUNTER_DEFERRED) ET rejetée par garde #3. Le doorstep
  (`OrderService::$isWebDeliveryDeferred`) ne concerne QUE DELIVERY. **Chemins disjoints, zéro chevauchement.**

*P3 obs* : le bloc marqueur (OnlineOrderController) n'est PAS restreint à `source_surface='web'`
(le commentaire l'affirme mais le code teste seulement TAKEAWAY+COD+null). Un order surface hors
allowlist (ex. `uber_eats`) pourrait recevoir le marqueur — MAIS reste **non-encaissable** car garde #3
+ file counter-collect filtrent la surface. Non exploitable (le gate aval tient). Cosmétique.

*P3 obs* : condition `pos_payment_method === null` → un takeaway web COD avec un pos_payment_method
pré-posé n'obtiendrait pas le marqueur (edge improbable, P1-3 resterait pour cet order — inoffensif).

### P2-e — gate pos-refund online
- **Casse un refund manager légitime ?** NON. `pos-refund` accordé à Admin + Branch Manager
  (`RolePermissionTableSeeder.php:89`), refusé à POS Operator (mitigation mass-refund). Le gate 403 ne
  frappe QUE POS Operator (l'intention). Vérifié `OnlineOrderRefundRequiresPosRefundTest` 3/3 PASS
  (operator refusé, user pos-refund OK, transition non-refund non gatée).
- **Effet de bord du rethrow `catch(HttpException)`** : `OrderSealedException extends HttpException(409)`
  → désormais surface **409** au lieu du 422 avalé (c'est l'INTENTION documentée de l'exception) ;
  `abort(403)` isolation branche → **403** au lieu de 422. Deux changements PLUS CORRECTS (bénins).
  `InvalidArgumentException(422)` du state-machine → toujours 422 (pas HttpException). Pas de régression.

### P2-u — scan loyalty durci
- **Casse la vraie borne ?** NON. `KioskMachine::withoutGlobalScope(BranchScope)->where('user_id',...)`
  → une vraie KioskMachine résout le profil. Staff & propriétaire aussi. Vérifié
  `LoyaltyScanRequiresKioskMachineTest` 3/3 (real kiosk OK, guest ne peut PAS énumérer PII, owner OK).
- **Invariants HMAC/nonce cassés ?** NON. `LoyaltyQrSigningSentinelTest` 9/9 + `CustomerTokenHmacHardenedSentinelTest`
  4/4 PASS (signature/expiry/replay/legacy intacts).

*P3 obs* : `WebOrderCounterCollectableTest` (2/2) ne couvre QUE les happy-paths — pas de test négatif
verrouillant web-PAID / web-CANCELED / web-CARD non re-encaissables. Les gardes EXISTENT & fonctionnent
(tracées ci-dessus) mais aucun sentinel ne les fige côté surface web. Gap de couverture, pas de défaut.

---

## Récapitulatif exécution (preuves)

| Suite | Résultat |
|---|---|
| OrdersNoDeleteWhenFiscalizedTest | 3/3 PASS |
| PosOrderRestoreIntegrityTest | 3/3 PASS |
| Iter15CleanupFiscalGuardTest | 3/3 PASS |
| PosOrderDestroyTest | 4/4 PASS |
| WebOrderCounterCollectableTest | 2/2 PASS |
| OnlineOrderRefundRequiresPosRefundTest | 3/3 PASS |
| LoyaltyScanRequiresKioskMachineTest | 3/3 PASS |
| LoyaltyQrSigningSentinelTest | 9/9 PASS |
| CustomerTokenHmacHardenedSentinelTest | 4/4 PASS |
| MgmtReadAuthzGateSentinelTest | PASS |
| SchedulerHasContinuityCheckTest | PASS |
| Fiscal dir (broad) | 275 PASS / 8 skip |
| Order dir (broad) | 56/56 PASS |
| vitest kds-v2-grid-keys + kdsV2ArchiveSlot | 14/14 PASS |
| kiosk-upsell live | pool=6 ×5, 40/106 absents |
| fiscal:verify-sequence-continuity | exit=1 sur trou (alarme OK) |

**Aucun P0/P1. 1 P2 (test-infra e2e vs trigger, pre-deploy). 4 P3 cosmétiques.**
Les 5 heals sont solides.
