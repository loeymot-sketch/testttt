# GATE BRIEF — VERIFY P0 FROZEN ZONES (CONSOLIDATED)

- **Gate ID** : `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20`
- **Date d'émission** : 2026-04-20
- **Auteur (planner)** : `foodking-planner-orchestrator` (rôle Claude — PLANNER, cf. `AGENTS.md:13-17`)
- **Status** : `AWAITING_HUMAN_APPROVAL`
- **Format de référence** : `docs/gates/GATE_V1_STATUS_MACHINE_001_2026-04-15.md`
- **Plan maître** : `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.1 (P0) + §3 (Gate humain requis)
- **Tracker source** : `reports/review/VERIFY_TRACKER_2026-04-20.md` §1 (verdicts), §2 (findings), §3 (priorisation)
- **Périmètre** : 8 cycles P0 critiques consolidés, tous touchant des **frozen zones** et/ou des **invariants non‑négociables** FoodKing.

> **Prohibition absolue — `.cursor/rules/human-gates.mdc:79-86`** : aucun agent ne peut s'auto‑approuver. Le champ `Approval` reste **non rempli** ici ; il ne peut être complété que par l'humain responsable.

---

## 0. Déclencheurs (Triggers) — Règles Gate invoquées

Ce gate est déclenché par **plusieurs conditions cumulatives** listées dans `.cursor/rules/human-gates.mdc` §Hard Gates et dans `.cursor/rules/auto-remediation.mdc` §Critical Zones :

| # | Déclencheur gate | Règle source |
|---|---|---|
| T1 | Schema migration (colonnes/contraintes/indexes) | `.cursor/rules/human-gates.mdc:19` |
| T2 | Auth logic change (abilities, middlewares, signatures) | `.cursor/rules/human-gates.mdc:20` |
| T3 | Frozen zone file edit required (`OrderService`, `PaymentService`, `routes/api.php`, migrations) | `.cursor/rules/human-gates.mdc:23` |
| T4 | FoodKing invariant violation detected (SSOT, state machine, isolation, fiscal) | `.cursor/rules/human-gates.mdc:24` |
| T5 | `branch_id` isolation logic added or modified | `.cursor/rules/human-gates.mdc:26` |
| T6 | Zones critiques `Schéma DB / Auth / Frozen / Symétrie Order / Branch isolation / OrderStatus / Dispatch / Pricing` → `HUMAN_GATE` | `.cursor/rules/auto-remediation.mdc:82-96` |
| T7 | Ajout/modification de migration touchant table pricing/orders/coupons/payments | `.cursor/rules/auto-remediation.mdc:82-84` |
| T8 | Modification `OrderService` / `PaymentService` / `DiscountCalculator` / frozen zones P9 | `.cursor/rules/auto-remediation.mdc:86-88` |

Exigences de plan rappelées :
- **`.cursor/rules/scope.mdc:30`** — No Implicit Expansion ; `SUBSYSTEMS_TOUCHED` et `INVARIANTS_AT_RISK` doivent être explicites.
- **`.cursor/rules/safety.mdc:14-54`** — Règles n°1 (SSOT pricing serveur), n°2 (isolation branche), n°3 (transitions statut), n°4 (Auth/tokens), n°5 (notifications hors transaction), n°6 (validation entrées).

---

## 1. Portée globale (Scope)

- **8 cycles P0 critiques** listés dans `plans/PLAN_POST_VERIFY_2026-04-20.md:24-35` (§1.1 « P0 — Critiques »), tous `GATE=OUI` et `PRIMARY_MODEL=GPT5` (cf. `AGENTS.md:15` « GPT‑5.4 — complex implementation »).
- Frozen zones concernées (rappel `plans/PLAN_POST_VERIFY_2026-04-20.md:154-180` §3) :
  - `app/Services/OrderService.php` (LOCK B POS 9.2/9.3 — partial release)
  - `app/Services/PaymentService.php` (LOCK B POS 9.2/9.3 — partial release)
  - `routes/api.php` (LOCK B POS 9.2 — ACTIVE)
  - `app/Services/Pricing/DiscountCalculator.php` (LOCK B POS 9.4 — released unused)
  - Migrations `idempotency_key`/coupons/pricing (LOCK A P9.5 — released)
- **Invariants non‑négociables** rappelés dans `AGENTS.md:66-72` (FoodKing Non‑Negotiables) :
  1. Server pricing = SSOT.
  2. `branch_id` isolation pour toutes les opérations multi‑succursales.
  3. OrderStatus via machine à états + `OrderAuditLog`.
  4. Fiscal NF525 : chaîne HMAC immuable, séquences Z, traçabilité totale.
  5. Notifications/broadcast hors transaction (`DB::afterCommit()`).

---

## 2. Vue d'ensemble des 8 cycles (synthèse)

Tableau synthétique (source : `plans/PLAN_POST_VERIFY_2026-04-20.md:24-35`) :

| # | Cycle P11 | Findings source | Invariants touchés | Frozen zones | `PRIMARY_MODEL` | Gate |
|---|---|---|---|---|---|---|
| C1 | P11_RETURNED_IDEMPOTENCY | F-VERIFY-03-01, F-VERIFY-03-04 | OrderStatus, Fiscal NF525, SSOT pricing | `OrderService.php`, `OrderStateMachine.php` | GPT5 | OUI |
| C2 | P11_RETURNED_KDS_BYPASS_LOCKDOWN | F-VERIFY-03-02 | OrderStatus, Fiscal NF525, Symétrie Order | `KitchenDisplaySystemOrderService.php`, `OrderStateMachine.php`, `routes/api.php` | GPT5 | OUI |
| C3 | P11_FISCAL_Z_OPEN_HARDENING | F-VERIFY-08-01, F-VERIFY-08-02, F-VERIFY-08-03 | Fiscal NF525, OrderStatus | `ZReportService.php`, `OrderService.php`, `ZReport` model | GPT5 | OUI |
| C4 | P11_PAYMENT_STATUS_STATE_MACHINE | F-VERIFY-09-01 | PaymentStatus state machine, Symétrie Order | `OrderService.php`, `PaymentStatusRequest` | GPT5 | OUI |
| C5 | P11_IDEMPOTENCY_KEY_MIDDLEWARE | F-VERIFY-09-02 | Idempotency HTTP, Isolation branche | `app/Http/Kernel.php`, middleware nouveau, migration cache | GPT5 | OUI |
| C6 | P11_COUPON_BRANCH_ISOLATION | F-VERIFY-06-01 | Isolation branche, SSOT pricing | migration `coupons`, `Coupon` model, `CouponService.php` | GPT5 | OUI |
| C7 | P11_COUPON_LIMIT_PER_USER_KIOSK | F-VERIFY-06-02 | Fair usage, intégrité coupons | `CouponService.php`, kiosk order flow | GPT5 | OUI |
| C8 | P11_WEBHOOK_SIGNATURE_AUDIT | F-VERIFY-12-01 | Auth webhook, NF525, fiscal | `PaymentGateways/Routes/senangpay.php`, gateways | GPT5 | OUI |

> **Séquencement imposé par dépendances** : C1 → C3 → C2 ; C4 indépendant ; C5 prérequis de C4 et C7 ; C6 prérequis de C7 ; C8 indépendant. Voir §12.

---

## 3. Cycle C1 — P11_RETURNED_IDEMPOTENCY

### 3.1 Trigger
- `.cursor/rules/human-gates.mdc:23` (frozen zone edit — `OrderService.php`)
- `.cursor/rules/human-gates.mdc:24` (invariant violation — OrderStatus transitions + idempotence fiscale)
- `.cursor/rules/auto-remediation.mdc:86-88` (zones critiques : Symétrie Order / OrderStatus / Dispatch)

### 3.2 Subsystems affectés (verified file:line)
| Fichier | Ligne | Rôle |
|---|---|---|
| `app/Services/OrderService.php` | 1499‑1567 | `changeStatus` — branche `status == RETURNED` exécute cashback + `refundPoints` + `AuditLogService::write` **sans guard d'idempotence** |
| `app/Domain/Order/OrderStateMachine.php` | 29‑31 | `if ($from === $to) { return true; }` — autorise `RETURNED → RETURNED` (re‑entrée) |
| `app/Domain/Order/OrderStateMachine.php` | 57‑58 | Autorise `DELIVERED → RETURNED` |
| `reports/review/VERIFY_03_P3_REFUND_RETURNED_2026-04-20.md` | Finding F-VERIFY-03-01 | Preuve : double cashback + double audit log observable |

### 3.3 Invariants at risk
- Invariant #3 OrderStatus SSOT (`AGENTS.md:66-72`, `safety.mdc:31-35`).
- Invariant #4 NF525 — chaîne d'audit fiscale immuable (double écriture = corruption séquence).
- Invariant #1 SSOT pricing — cashback dupliqué fausse la trésorerie.

### 3.4 Plan de modification minimal envisagé
1. Dans `OrderStateMachine::allows()` (`app/Domain/Order/OrderStateMachine.php:29-31`) : retirer le `return true;` inconditionnel sur `$from === $to` **au moins pour les statuts terminaux** (`RETURNED`, `CANCELED`, `REJECTED`, `DELIVERED`). Rejeter la re‑entrée vers un statut terminal.
2. Dans `OrderService::changeStatus` (`app/Services/OrderService.php:1499-1567`) : wrapper dans `DB::transaction` + `lockForUpdate()` sur la commande, relire `$order->status`, **guard** `if ($locked->status === Order::STATUS_RETURNED) { abort(409); }` avant cashback/audit.
3. Déplacer les dispatchs `SendOrder*` en `DB::afterCommit()` (cohérence avec `LOCK_B_POS_9_2_3_OrderService_2026-04-18.md` §Partial Release).

### 3.5 Justification — aucune alternative
- Un garde applicatif seul (sans correction du state machine) laisse passer la re‑entrée directe via `KitchenDisplaySystemOrderService::changeStatus` (cf. C2).
- Corriger uniquement le state machine sans lock pessimiste laisse passer les races (2 requêtes simultanées `→ RETURNED` passent toutes deux le check).
- Aucun patch « cosmétique » possible : l'idempotence fiscale est un **invariant non‑négociable** (`AGENTS.md:66-72`).

### 3.6 Rollback
- Migration : aucune (code uniquement) — rollback = `git revert <sha>` du patch `OrderService.php` + `OrderStateMachine.php`.
- Pas de backfill requis ; les commandes déjà doublées doivent être traitées en script fiscal séparé (hors scope gate).

### 3.7 Tests critiques à rejouer / ajouter
- `tests/Feature/Pos/ReturnedIdempotencyTest.php` (nouveau) : 2 requêtes concurrentes `→ RETURNED` doivent aboutir à **1 cashback, 1 audit, 1 transition**.
- Suite existante `OrderStateMachineTest` — vérifier que les tests `RETURNED → RETURNED` retournent désormais `false`.
- `tests/Feature/Fiscal/AuditLogChainTest.php` — vérifier qu'aucun trou de séquence n'est introduit.

### 3.8 Options offertes à l'humain
- **Option A (recommandée)** : refactor complet state machine + guard `changeStatus` (portée : ~80 LoC).
- **Option B** : guard applicatif seulement (laisse dette sur state machine).
- **Option C** : Reporter — NON RECOMMANDÉ (risque fiscal actif en production).

---

## 4. Cycle C2 — P11_RETURNED_KDS_BYPASS_LOCKDOWN

### 4.1 Trigger
- `.cursor/rules/human-gates.mdc:23` (frozen zone — `routes/api.php` sous LOCK B 9.2 ACTIVE)
- `.cursor/rules/human-gates.mdc:24` (invariant violation — fiscal bypass)
- `.cursor/rules/auto-remediation.mdc:86-88` (Symétrie Order, OrderStatus, Dispatch)

### 4.2 Subsystems affectés
| Fichier | Ligne | Rôle |
|---|---|---|
| `app/Services/KitchenDisplaySystemOrderService.php` | 117‑157 | `changeStatus` applique la transition sans blocage spécifique pour `RETURNED` (pas de cashback ni audit fiscal dans ce chemin) |
| `app/Domain/Order/OrderStateMachine.php` | 57‑58 | Laisse passer `DELIVERED → RETURNED` depuis n'importe quel appelant |
| `routes/api.php` | 778‑806 | Expose `kds/orders/{order}/status` sans restriction fine sur `status=RETURNED` |
| `reports/review/VERIFY_03_P3_REFUND_RETURNED_2026-04-20.md` | Finding F-VERIFY-03-02 | Reproduction : KDS peut émettre `→ RETURNED` sans audit fiscal ni cashback |

### 4.3 Invariants at risk
- Invariant #3 OrderStatus symétrie (`AGENTS.md:66-72`).
- Invariant #4 NF525 — obligation d'audit pour tout changement de statut terminal.
- Cohérence inter‑surfaces (KDS ↔ POS ↔ API publique).

### 4.4 Plan minimal envisagé
1. Dans `KitchenDisplaySystemOrderService::changeStatus` (`app/Services/KitchenDisplaySystemOrderService.php:117-157`) : **interdire** `$newStatus === Order::STATUS_RETURNED` avec `abort(422, 'Use /admin/pos-order/{id}/return endpoint (fiscal audit required).');`
2. Dans `OrderStateMachine::allows()` : ajouter un paramètre facultatif `$surface` et bloquer `RETURNED` si `$surface === 'kds'`.
3. Ajouter rétroactivement un `OrderAuditLog` dans la route KDS pour les transitions non‑terminales (traçabilité).

### 4.5 Justification — aucune alternative
- Exposer un endpoint distinct pour `RETURNED` est la seule manière de centraliser le chemin fiscal (audit + cashback).
- Un guard côté front (KDS Vue) est insuffisant — bypass trivial via `curl`.

### 4.6 Rollback
- Code‑only ; `git revert` du patch KDS + state machine.
- Les commandes déjà retournées via KDS (période passée) nécessitent script correctif fiscal hors gate (reporté).

### 4.7 Tests critiques
- `tests/Feature/Kds/KdsReturnedBlockedTest.php` (nouveau) : tentative `KDS → RETURNED` doit retourner 422.
- `tests/Feature/Pos/ReturnedViaPosTest.php` : confirmer que le chemin POS fait bien cashback + audit.

### 4.8 Options
- **A (recommandée)** : blocage KDS + endpoint POS dédié (fiscal).
- **B** : guard sur `OrderStateMachine` uniquement (sans endpoint dédié → toute surface bloquée → régressif).
- **C** : Reporter — refusé (fiscal actif).

---

## 5. Cycle C3 — P11_FISCAL_Z_OPEN_HARDENING

### 5.1 Trigger
- `.cursor/rules/human-gates.mdc:24` (invariant fiscal NF525)
- `.cursor/rules/human-gates.mdc:19` (schema migration potentielle — ajout état `CLOSING`)
- `.cursor/rules/auto-remediation.mdc:82-88`

### 5.2 Subsystems affectés
| Fichier | Ligne | Rôle |
|---|---|---|
| `app/Services/Fiscal/ZReportService.php` | 50‑95 | `open()` n'effectue **aucune** vérification de la chaîne HMAC précédente avant de scellter un nouveau Z |
| `app/Services/OrderService.php` | 1499‑1567 | `changeStatus` ne bloque pas les transitions sur commandes **déjà incluses dans un Z scellé** |
| `app/Services/OrderService.php` | 1592‑1646 | `changePaymentStatus` idem — pas de guard post‑Z |
| `app/Models/ZReport.php` | 15‑16 | Seuls états `STATUS_OPEN`/`STATUS_CLOSED` — pas d'état intermédiaire `CLOSING` rendant la transition non atomique |
| `reports/review/VERIFY_08_FISCAL_NF525_Z_OPEN_2026-04-20.md` | F-VERIFY-08-01/02/03 | Preuves |

### 5.3 Invariants at risk
- NF525 — intégrité chaîne + scellé irréversible.
- OrderStatus — cohérence période comptable.

### 5.4 Plan minimal envisagé
1. Dans `ZReportService::open()` (`app/Services/Fiscal/ZReportService.php:50-95`) : avant création du nouveau Z, invoquer un `AuditLogChainVerifier::verify($branchId, $lastClosedZ)` ; abort si rupture détectée.
2. Ajouter méthode `ZReport::isSealedForOrder(int $orderId): bool` et **guard** dans `OrderService::changeStatus` et `changePaymentStatus` (`app/Services/OrderService.php:1499-1567` / `1592-1646`) : `abort(423 Locked)` si la commande appartient à un Z scellé.
3. Migration : ajouter enum `STATUS_CLOSING` (`app/Models/ZReport.php:15-16`) + colonne `closing_started_at`. Rendre `close()` atomique (`OPEN → CLOSING → CLOSED`).

### 5.5 Justification
- Sans vérification de chaîne à l'ouverture, une corruption passée (trou HMAC) est « blanchie » par le nouveau Z.
- Sans état `CLOSING`, la transaction `close()` expose une fenêtre où des écritures concurrentes peuvent arriver.

### 5.6 Rollback
- Migration réversible (`down()` supprime `STATUS_CLOSING` + colonne).
- Guards code‑only → `git revert`.

### 5.7 Tests critiques
- `tests/Feature/Fiscal/ZOpenChainVerificationTest.php` (nouveau).
- `tests/Feature/Fiscal/OrderChangeAfterZSealedTest.php`.
- `tests/Feature/Fiscal/ZReportClosingRaceTest.php`.

### 5.8 Options
- **A** : 3 patches (chain verify + guard + `CLOSING`) — recommandé.
- **B** : chain verify + guard uniquement (`CLOSING` reporté en P1).
- **C** : Reporter — refusé (risque NF525 audit externe).

---

## 6. Cycle C4 — P11_PAYMENT_STATUS_STATE_MACHINE

### 6.1 Trigger
- `.cursor/rules/human-gates.mdc:23` (frozen zone — `OrderService.php` sous LOCK B 9.2/9.3)
- `.cursor/rules/human-gates.mdc:24` (invariant PaymentStatus)
- `.cursor/rules/auto-remediation.mdc:86-88`

### 6.2 Subsystems affectés
| Fichier | Ligne | Rôle |
|---|---|---|
| `app/Services/OrderService.php` | 1592‑1646 | `changePaymentStatus` assigne `payment_status` sans machine à états, sans transaction, sans `Rule::in()`, sans idempotence |
| `app/Http/Requests/PaymentStatusRequest.php` | 27‑33 | Rules : `payment_status` accepte n'importe quel entier (`numeric`) |
| `reports/review/VERIFY_09_PAYMENTS_IDEMPOTENCY_2026-04-20.md` | F-VERIFY-09-01 | Preuves |

### 6.3 Invariants at risk
- Invariant #3 Symétrie paiement (`AGENTS.md:66-72`, `safety.mdc:31-35`).
- Invariant #4 Auth/tokens (idempotency = partie de la sécurité paiement).

### 6.4 Plan minimal envisagé
1. Créer `app/Domain/Payment/PaymentStateMachine.php` (nouveau) avec matrice `PENDING → PAID → REFUNDED | FAILED`, etc.
2. Dans `PaymentStatusRequest::rules()` (`app/Http/Requests/PaymentStatusRequest.php:27-33`) : `Rule::in(PaymentStatus::values())`.
3. Dans `OrderService::changePaymentStatus` (`app/Services/OrderService.php:1592-1646`) : wrapper `DB::transaction` + `lockForUpdate` + `PaymentStateMachine::allows()` + `PaymentStateMachine::recordTransition()` + audit `AuditLogService::write`.
4. Header `Idempotency-Key` **obligatoire** (cohérence C5).

### 6.5 Justification
- Un state machine paiement est la **seule** manière structurelle d'empêcher `PAID → PENDING` accidentel, ou `REFUNDED → PAID`.
- Validation `Rule::in` sans state machine rejette des valeurs invalides mais laisse passer les transitions illégales.

### 6.6 Rollback
- Code‑only ; `git revert`.
- Pas de migration.

### 6.7 Tests critiques
- `tests/Unit/Domain/PaymentStateMachineTest.php` (nouveau).
- `tests/Feature/Pos/ChangePaymentStatusTest.php` — couvrir toutes transitions valides/invalides + idempotence.

### 6.8 Options
- **A** : state machine + idempotency header — recommandé.
- **B** : state machine seul (idempotence reportée à C5).
- **C** : Reporter — refusé.

---

## 7. Cycle C5 — P11_IDEMPOTENCY_KEY_MIDDLEWARE

### 7.1 Trigger
- `.cursor/rules/human-gates.mdc:20` (Auth logic — middleware global)
- `.cursor/rules/human-gates.mdc:23` (frozen zone — `app/Http/Kernel.php`)
- `.cursor/rules/human-gates.mdc:26` (`branch_id` isolation : la clé doit être scopée branche, cohérence avec migration `2026_04_18_140003_scope_idempotency_key_to_branch.php`)
- `.cursor/rules/auto-remediation.mdc:82-84` (Schéma DB / cache table)

### 7.2 Subsystems affectés
| Fichier | Ligne | Rôle |
|---|---|---|
| `app/Http/Kernel.php` | 43‑51 | Groupe `api` ; aucun middleware `idempotency` n'est enregistré |
| `app/Http/Kernel.php` | 61‑85 | Alias `routeMiddleware` — à étendre avec `'idempotency' => \App\Http\Middleware\IdempotencyKeyMiddleware::class` |
| `routes/api.php` | 625‑638 | Routes POS sans enforce `Idempotency-Key` |
| `routes/api.php` | 778‑806 | Routes KDS/OSS/Fiscal idem |
| `reports/review/VERIFY_09_PAYMENTS_IDEMPOTENCY_2026-04-20.md` | F-VERIFY-09-02 | Preuves |

### 7.3 Invariants at risk
- Invariant idempotence globale.
- Invariant #2 `branch_id` isolation (clé scoped `(branch_id, idempotency_key)` — déjà migrée, cf. `tasks/phase9-sync/LOCK_A_P9_5_idempotency_key_migration_2026-04-18.md`).

### 7.4 Plan minimal envisagé
1. Créer `app/Http/Middleware/IdempotencyKeyMiddleware.php` :
   - Lit header `Idempotency-Key` (obligatoire sur POST/PUT mutants).
   - Clé de cache : `idem:{branch_id}:{user_id}:{key}`.
   - TTL 24h ; cache la réponse sérialisée ; rejoue sur collision.
2. Enregistrer l'alias dans `app/Http/Kernel.php:61-85`.
3. Appliquer sur les routes mutantes critiques : `routes/api.php:625-638` (POS), `:778-806` (KDS/OSS/Fiscal).
4. Migration : table `idempotency_cache` si on choisit stockage DB (sinon Redis → pas de migration).

### 7.5 Justification
- Un middleware unique est la **seule** façon de garantir l'idempotence sans dupliquer la logique dans 20+ contrôleurs.
- Scope `(branch_id, key)` aligné avec la contrainte DB unique déjà posée.

### 7.6 Rollback
- Migration `idempotency_cache` réversible.
- Middleware/alias → `git revert`.

### 7.7 Tests critiques
- `tests/Feature/Http/IdempotencyKeyMiddlewareTest.php` : même clé → même réponse ; clés différentes → traitement indépendant.
- `tests/Feature/Http/IdempotencyBranchScopeTest.php` : clé identique sur 2 branches différentes → traitements indépendants.

### 7.8 Options
- **A** : middleware + cache Redis (recommandé — aligné infra actuelle).
- **B** : middleware + table DB (si Redis indisponible).
- **C** : Reporter — refusé (prérequis C4, C7).

---

## 8. Cycle C6 — P11_COUPON_BRANCH_ISOLATION

### 8.1 Trigger
- `.cursor/rules/human-gates.mdc:19` (schema migration — ajout `branch_id` à `coupons`)
- `.cursor/rules/human-gates.mdc:26` (**`branch_id` isolation logic added**)
- `.cursor/rules/auto-remediation.mdc:82-84` (Schéma DB)

### 8.2 Subsystems affectés
| Fichier | Ligne | Rôle |
|---|---|---|
| `database/migrations/2022_11_17_110910_create_coupons_table.php` | 16‑33 | Table `coupons` créée **sans** colonne `branch_id` |
| `app/Models/Coupon.php` | 14‑27 | `$fillable` ne contient pas `branch_id` ; aucun scope global par branche |
| `app/Services/CouponService.php` | 285‑321 | `validateCouponForOrder` ne vérifie pas l'appartenance branche |
| `reports/review/VERIFY_06_P8_P9_COUPONS_2026-04-20.md` | F-VERIFY-06-01 | Preuves |

### 8.3 Invariants at risk
- Invariant #2 — `branch_id` isolation multi‑tenant (`AGENTS.md:66-72`, `safety.mdc:24-28`).
- Invariant #1 — SSOT pricing (coupons affectent le total).

### 8.4 Plan minimal envisagé
1. Nouvelle migration `2026_04_2X_add_branch_id_to_coupons_table.php` :
   - Ajouter `branch_id` unsigned BigInt nullable puis backfill.
   - Index composite `(branch_id, code)` unique après backfill.
2. Backfill déterministe : associer les coupons existants à la branche de leur créateur (`creator_id`) ; fallback branche 0 (global) documenté.
3. Model `Coupon` (`app/Models/Coupon.php:14-27`) : ajouter `branch_id` au `$fillable` + `BranchScope` global (aligné patterns FoodKing).
4. `CouponService::validateCouponForOrder` (`app/Services/CouponService.php:285-321`) : filtrer par `branch_id` de la commande ; rejeter `422` sinon.

### 8.5 Justification
- Un coupon cross‑branche casse la facturation par succursale (F-VERIFY-06-01).
- Aucune alternative non‑destructrice : la colonne DOIT exister pour que le scope applicatif soit fiable.

### 8.6 Rollback
- Migration `down()` supprime colonne + index.
- Backfill documenté → plan de rollback nommé dans la migration.

### 8.7 Tests critiques
- `tests/Feature/Coupon/CouponBranchIsolationTest.php` (nouveau).
- `tests/Unit/Models/CouponScopeTest.php`.
- Re‑lancer `tests/Feature/Coupon/ApplyCouponTest.php` existants.

### 8.8 Options
- **A** : colonne + scope + validation + migration — recommandé.
- **B** : validation applicative uniquement (sans colonne) — refusé (pas de SSOT).
- **C** : Reporter — refusé (risque isolation inter‑clients).

---

## 9. Cycle C7 — P11_COUPON_LIMIT_PER_USER_KIOSK

### 9.1 Trigger
- `.cursor/rules/human-gates.mdc:24` (invariant fair‑use / intégrité promo)
- `.cursor/rules/auto-remediation.mdc:86-88` (zones critiques pricing)

### 9.2 Subsystems affectés
| Fichier | Ligne | Rôle |
|---|---|---|
| `app/Services/CouponService.php` | 308‑318 | `limit_per_user` comparé à `count(orders where user_id == current)` — contournable par utilisateur machine kiosk |
| `app/Services/CouponService.php` | 285‑321 | Pas de fallback sur `table_id` / `device_id` / `kiosk_session` pour commandes anonymes |
| `reports/review/VERIFY_06_P8_P9_COUPONS_2026-04-20.md` | F-VERIFY-06-02 | Preuves |

### 9.3 Invariants at risk
- Intégrité promotions (fair usage).
- Cohérence SSOT pricing (abus coupons → écart caisse).

### 9.4 Plan minimal envisagé
1. Étendre `CouponService::validateCouponForOrder` (`app/Services/CouponService.php:285-321`) :
   - Identifiant effectif = `user_id` si authentifié non‑kiosk, sinon `device_fingerprint` (kiosk token ability) ou `phone` / `email` commande.
   - Compter les usages sur une fenêtre glissante `(branch_id, effective_identity, coupon_id)`.
2. Dépend de C6 (scope `branch_id` obligatoire avant ce filtre).
3. Ajouter table `coupon_usages` (`user_ref`, `device_ref`, `branch_id`, `coupon_id`, `order_id`) — migration.

### 9.5 Justification
- Seule structure data permettant un comptage robuste multi‑surface.
- Simple « unique email per coupon » est contournable (MPE, kiosk anon). Le fingerprint appareil + téléphone est le pattern FoodKing adopté.

### 9.6 Rollback
- Migration réversible.
- Backfill des usages historiques optionnel (documenté hors gate).

### 9.7 Tests critiques
- `tests/Feature/Coupon/CouponLimitPerUserKioskTest.php`.
- `tests/Feature/Coupon/CouponLimitAnonymousTableTest.php`.

### 9.8 Options
- **A** : table `coupon_usages` + logique fingerprint — recommandé.
- **B** : seulement phone/email (laisse trou kiosk anonyme pur) — risque résiduel.
- **C** : Reporter — refusé (dépendance C6).

---

## 10. Cycle C8 — P11_WEBHOOK_SIGNATURE_AUDIT

### 10.1 Trigger
- `.cursor/rules/human-gates.mdc:20` (**Auth logic change** — ajout validation signature)
- `.cursor/rules/human-gates.mdc:24` (invariant Auth/NF525)
- `.cursor/rules/auto-remediation.mdc:82-88`

### 10.2 Subsystems affectés
| Fichier | Ligne | Rôle |
|---|---|---|
| `app/Http/PaymentGateways/Routes/senangpay.php` | 17‑19 | Route `payment/senangpay-webhook/` enregistrée **sans** middleware de vérification de signature |
| `routes/api.php` | 778‑806 | Zone fiscale — pas d'audit côté webhook |
| `reports/review/VERIFY_12_SECURITY_2026-04-20.md` | F-VERIFY-12-01 | Preuves |

### 10.3 Invariants at risk
- Invariant #4 Auth webhook (`safety.mdc:38-43`).
- NF525 — toute confirmation fiscale venant d'un webhook non signé est compromise.

### 10.4 Plan minimal envisagé
1. Créer `app/Http/Middleware/VerifyPaymentWebhookSignature.php` (par provider) — HMAC SHA256 + secret config.
2. Appliquer sur `app/Http/PaymentGateways/Routes/senangpay.php:17-19` et routes analogues (Stripe, etc.) via middleware group dédié.
3. Ajouter `AuditLogService::write('payment_webhook_received', ...)` pour chaque webhook vérifié.
4. Config secrets → `config/services.php` + `.env.example` (sans secret réel — conforme `.cursor/rules/safety.mdc`).

### 10.5 Justification
- Signature + audit est la **seule** contre‑mesure conforme PCI/NF525.
- Rate‑limit ou filtrage IP insuffisant (IPs des gateways changent).

### 10.6 Rollback
- Code‑only ; `git revert`.
- Rotation de secret non requise si jamais déployé.

### 10.7 Tests critiques
- `tests/Feature/Webhook/SenangpaySignatureTest.php` : OK/KO/missing.
- `tests/Feature/Webhook/WebhookAuditLogTest.php`.

### 10.8 Options
- **A** : middleware par provider + audit — recommandé.
- **B** : middleware générique paramétré (plus complexe).
- **C** : Reporter — refusé (faille active).

---

## 11. Matrice de risques inter‑cycles

| Risque | C1 | C2 | C3 | C4 | C5 | C6 | C7 | C8 |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Fuite frozen zone `OrderService.php` | ✅ | ✅ | ✅ | ✅ | – | – | – | – |
| Invariant OrderStatus | ✅ | ✅ | ✅ | – | – | – | – | – |
| Invariant NF525 fiscal | ✅ | ✅ | ✅ | ✅ | – | – | – | ✅ |
| Isolation `branch_id` | – | – | – | – | ✅ | ✅ | ✅ | – |
| SSOT pricing | ✅ | – | – | – | – | ✅ | ✅ | – |
| Auth / tokens / webhook | – | – | – | – | ✅ | – | – | ✅ |
| Schema migration | – | – | ✅ | – | (opt) | ✅ | ✅ | – |
| State machine modification | ✅ | ✅ | – | ✅ | – | – | – | – |

> Tout cycle touchant à la fois `OrderService.php` + state machine doit être implémenté **en sérialisation stricte** : pas de parallélisme sur ces 3 cycles (C1, C2, C4) pour éviter les régressions inter‑PR.

---

## 12. Séquencement recommandé

```
Phase 1 — Pré‑requis infrastructure
  C5 (Idempotency-Key middleware)           [débloque C4, C7]
  C6 (Coupon branch_id isolation)            [débloque C7]
  C8 (Webhook signature)                    [indépendant, parallélisable]

Phase 2 — Cœur fiscal / OrderStatus (SÉRIEL)
  C3 (Z-open hardening)                      [pré‑requis C1, C2]
  C1 (RETURNED idempotency)
  C2 (RETURNED KDS lockdown)
  C4 (PaymentStatus state machine)           [peut enchaîner après C1]

Phase 3 — Finalisation promos
  C7 (Coupon limit_per_user kiosk)
```

Justification :
- C3 pose les garde‑fous Z scellé dont C1/C2 dépendent (F-VERIFY-08-02).
- C5 est un prérequis logique de C4 (idempotence paiement).
- C6 est prérequis de C7 (scope branche avant comptage usages).
- C8 est indépendant et peut être mené en parallèle par un second worktree — Option orchestrateur.

---

## 13. Routage subagent — justification GPT‑5.4

Tous les cycles C1‑C8 sont marqués `PRIMARY_MODEL=GPT5` dans `plans/PLAN_POST_VERIFY_2026-04-20.md:24-35`.

Justification (réf. `AGENTS.md:13-17` Model Roles) :
- `GPT-5.4` — complex implementation : tous ces cycles impliquent (a) frozen zones, (b) invariants non‑négociables, (c) schema migration ou machine à états. Ce sont précisément les critères énoncés par `AGENTS.md:15` pour router vers GPT‑5.4.
- `Composer` (`AGENTS.md:16`) — routine edits seulement ; explicitement **non éligible** pour les modifications d'`OrderService`, `PaymentService`, routes, migrations, middleware d'auth.
- `Claude` (`AGENTS.md:13-14`) — planner/orchestrator/audit : rôle actuel, ne code pas ces cycles (mode PLAN‑ONLY ici).

Subagent type attendu lors de l'exécution : `foodking-complex-implementer` (charte subagent cohérente avec GPT‑5.4). Routage `foodking-routine-implementer` **explicitement interdit** pour ces 8 cycles (toute zone P0 / frozen / auth / schema / isolation).

---

## 14. Rappel LOCK files (appendice dépendances frozen zones)

| LOCK | Statut | Impact sur gate |
|---|---|---|
| `tasks/phase9-sync/LOCK_A_P9_5_OrderService_2026-04-18.md` | `RELEASED (preventive)` | Pas d'édition résiduelle requise ; lock réouvrable si C1/C2/C3/C4 touchent `OrderService.php` |
| `tasks/phase9-sync/LOCK_B_POS_9_2_3_OrderService_2026-04-18.md` | `PARTIAL RELEASE 2026-04-20` | Zones réactivées pour `changeStatus` (C1) et `changePaymentStatus` (C4) — nouveau lock requis |
| `tasks/phase9-sync/LOCK_B_POS_9_2_3_PaymentService_2026-04-18.md` | `PARTIAL RELEASE 2026-04-20` | Peu impacté ; surveillance si C4 doit déléguer au `PaymentService` |
| `tasks/phase9-sync/LOCK_B_POS_9_4_BL_DiscountCalculator_2026-04-18.md` | `RELEASED (unused)` | Non réactivé sauf si C6/C7 touchent le `DiscountCalculator` (a priori non) |
| `tasks/phase9-sync/LOCK_B_POS_9_2_routes_api_2026-04-18.md` | `ACTIVE` | **Doit être étendu** pour C2 (endpoint `/admin/pos-order/{order}/return`) et C5 (application middleware idempotency) |
| `tasks/phase9-sync/LOCK_A_P9_5_idempotency_key_migration_2026-04-18.md` | `RELEASED` | Sert de précédent : contrainte unique `(branch_id, idempotency_key)` — C5 doit exploiter ce schema existant |

---

## 15. Décision humaine requise (global)

L'humain doit, **avant toute exécution**, se prononcer sur chacun des points suivants :

1. **Go/No‑Go par cycle** (C1…C8) — approbation ou ajournement explicite.
2. **Ordre d'exécution** — conforme §12 recommandé, ou ordre alternatif justifié.
3. **Routing confirmation** — confirmer `GPT-5.4` via `foodking-complex-implementer` pour les 8 cycles.
4. **Ouverture de nouveaux LOCKs** — autoriser l'extension de `LOCK_B_POS_9_2_routes_api_2026-04-18.md` et la réactivation des LOCKs `OrderService` / `PaymentService`.
5. **Migrations autorisées** — 4 migrations prévues (C3 `z_reports.status CLOSING`, C5 optionnelle `idempotency_cache`, C6 `coupons.branch_id`, C7 `coupon_usages`).
6. **Canary / rollback plan** — confirmer stratégie déploiement progressif (1 branche pilote avant généralisation).

---

## 16. Approval

> **`.cursor/rules/human-gates.mdc:79-86` — Absolute Prohibition** : aucun agent IA ne peut compléter cette section. Réservée à l'humain.

- **Decision** : ⬜ APPROVED   ⬜ APPROVED WITH CONDITIONS   ⬜ REJECTED   ⬜ DEFERRED
- **Approver (human)** : `__________________________`
- **Date** : `____-__-__`
- **Conditions / Notes** :
  - (à remplir par l'humain uniquement)

---

## 16bis. Addendum 2026-04-20 — Écarts plan/code découverts par cycle P11_BUSINESS_RULES_DOC_SYNC

> **Contexte :** le cycle `P11_BUSINESS_RULES_DOC_SYNC` (V1, Composer, GATE=NON) a été exécuté avant la signature humaine de ce brief. Son audit (`reports/execution/RUN_P11_BUSINESS_RULES_DOC_SYNC_2026-04-20.md` §AUDIT) a révélé 6 écarts entre les plans EXECUTE (01/02/03/04) et le code réel au 2026-04-20. **Ces écarts ne modifient pas les objectifs métier** mais précisent la nature des modifications (création vs durcissement). L'humain doit en prendre connaissance **avant** de signer §16 Approval.

| # | Écart observé | Impact sur cycle | Nature réelle du travail |
|---|---|---|---|
| 1 | Modèle `ItemBranchAvailability` / table `item_branch_availability` (pas `BranchItemAvailability` / `branch_item_availabilities`) | doc/observation | Nommage à aligner dans plans futurs — pas d'impact code ce brief |
| 2 | Garde sealed-Z actuelle = **HTTP 409** sur `OrderService::destroy` (L1735-1752) uniquement ; **aucune** garde sur `changeStatus`/`changePaymentStatus` | **C3 (cycle 02)** | "Durcir garde existante" → en fait **"créer garde nouvelle"** sur 2 méthodes. Le plan 02 reste valide ; nouveaux tests ZReportSealedGuardTest créeront 423 attendu. |
| 3 | Statut `CLOSING` inexistant ; `ZReport` = `open`/`closed` (`app/Models/ZReport.php:15-16`) | **C3 (cycle 02)** | Confirme nécessité migration schema + gate `human-gates.mdc:19`. Plan 02 cohérent. |
| 4 | `PaymentStateMachine` inexistante dans `app/Domain/Payment/` | **C4 (cycle 03)** | Confirme plan 03 = création de classe neuve (pas refactor). Plan cohérent. |
| 5 | `coupons.branch_id` et table `coupon_usages` absents ; `limit_per_user` = comptage `OrderCoupon` | **C6/C7 (V2)** | Plans V2 déjà en mode "création" — cohérent. |
| 6 | Route POS `.../return` inexistante ; `RETURNED` transite via `POST /api/admin/pos-order/change-status/{order}` (`routes/api.php:633-634`) | **C2 (cycle 04 non-top5) + partiel C1** | Pour lockdown KDS, nouvelle route POS à créer (pas "sécuriser route existante"). |

**Conclusion addendum :** les 8 cycles du brief restent tous valides et nécessaires. Décision humaine §16 peut se faire en connaissance de cause. Aucun objectif métier à réviser. Les plans EXECUTE 01/02/03 seront ajustés lexicalement par Claude orchestrator post-signature (création vs durcissement) sans modifier scope ni SCOPE_FILES.

**Référence preuve :** `reports/execution/RUN_P11_BUSINESS_RULES_DOC_SYNC_2026-04-20.md` §AUDIT + `docs/BUSINESS_RULES.md` §"Synthèse des écarts plan / code au 2026-04-20".

---

## 17. Annexes — références croisées

- Plan maître : `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.1 (P0) + §3 (Gate humain requis)
- Tracker : `reports/review/VERIFY_TRACKER_2026-04-20.md` §1 / §2 / §3
- Rapports VERIFY sources :
  - `reports/review/VERIFY_03_P3_REFUND_RETURNED_2026-04-20.md`
  - `reports/review/VERIFY_04_P4_KDS_CONCURRENCY_2026-04-20.md`
  - `reports/review/VERIFY_06_P8_P9_COUPONS_2026-04-20.md`
  - `reports/review/VERIFY_08_FISCAL_NF525_Z_OPEN_2026-04-20.md`
  - `reports/review/VERIFY_09_PAYMENTS_IDEMPOTENCY_2026-04-20.md`
  - `reports/review/VERIFY_12_SECURITY_2026-04-20.md`
  - `reports/review/VERIFY_20_BUSINESS_RULES_DOC_ALIGNMENT_2026-04-20.md`
- Gouvernance :
  - `.cursor/rules/human-gates.mdc`
  - `.cursor/rules/auto-remediation.mdc`
  - `.cursor/rules/scope.mdc`
  - `.cursor/rules/safety.mdc`
- Gate template : `docs/gates/GATE_V1_STATUS_MACHINE_001_2026-04-15.md`
- Rôles agents : `AGENTS.md`

---

*Fin du gate brief — `Approval` intentionnellement non rempli. Aucun autre fichier du workspace n'a été modifié par la génération de ce document.*
