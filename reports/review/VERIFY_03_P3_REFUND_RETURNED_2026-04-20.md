# VERIFY-03 — P3 Refund / RETURNED (motif, cashback, audit NF525)

**Date :** 2026-04-20  **Mode :** AUDIT-ONLY (lecture seule, aucune modification de code)  **Origine :** P3 (commit `b007c6344`)  **Priorité :** P0
**Task source :** `tasks/verify-2026-04-20/03_VERIFY_P3_REFUND_RETURNED.md`
**Branche / état :** `feat/ton-sujet` — `tests/Feature/OrderCancellationLoyaltyTest.php` a été inspecté à HEAD (aucun diff local résiduel : `git status` working tree clean sur ce fichier ; les modifs utilisateur évoquées dans la tâche sont déjà committées en `93431ae83 fix(ci): … loyalty_code ≤15`).

**GLOBAL : FAIL** — V2 (idempotence) cassée hors loyalty + V8 (bypass KDS) ouvert + V4/V5/V6 partiels.

---

## 0. TL;DR

| Critère | Statut | Synthèse |
|---|---|---|
| V1 — Motif obligatoire 422 | **PASS** | `OrderService.php:1502-1504` (`reason: required\|max:700`) ; test `PosOrderBL2AuditCallSitesTest::test_returned_without_reason_fails_validation` |
| V2 — Idempotence 2× RETURNED | **FAIL** | `OrderStateMachine.php:29-31` autorise `from === to` ; aucune garde dans `changeStatus`. 2e appel = 2× cashBack + 2× ActionLog + 2× audit `order.returned` (commandes sans loyalty) |
| V3 — `Order::restore()` bloqué | **PASS** | `Order.php:98-106` ; tests `PosOrderRestoreIntegrityTest` (3 cas) |
| V4 — Audit `order.returned` complet | **WARN** | Payload manque `payment_method` / `pos_payment_method` / `transaction_id` ; chain HMAC OK |
| V5 — Event + Outbox | **PARTIAL** | `OrderStatusChanged` générique persisté en outbox (after-commit), pas d'event `OrderReturned` dédié, payload outbox sans `reason` |
| V6 — Z/X intègre RETURNED | **WARN** | `refund_count` exposé mais `total_ttc/ht/tva` non rabattus (pas d'AVOIR avec `fiscal_sequence_no` propre) |
| V7 — `refundPoints` no-op sans redeem | **PASS** | `LoyaltyService.php:23-33` (double garde `loyalty_customer_code` + `redeemTxns->isEmpty()`) ; tests `OrderCancellationLoyaltyTest` |
| V8 — Pas de bypass legacy | **FAIL/WARN** | `KitchenDisplaySystemOrderService::changeStatus` (`app/Services/KitchenDisplaySystemOrderService.php:117-157`) accepte `status=22` sur un Order DELIVERED sans motif / cashback / refund / audit fiscal |

---

## 1. Pass A — Backend RETURNED + fiscal

### 1.1 Flux `OrderService::changeStatus` (branche staff RETURNED)

```1499:1567:app/Services/OrderService.php
                    $toStatus = (int) $request->status;
                    // [P3] RETURNED — même barrière motif / contrepartie que CANCELED & REJECTED.
                    if (in_array($toStatus, [OrderStatus::REJECTED, OrderStatus::CANCELED, OrderStatus::RETURNED], true)) {
                        $request->validate([
                            'reason' => 'required|max:700',
                        ]);
                        if ($request->reason) {
                            $order->reason = $request->reason;
                        }
                        if ($order->transaction) {
                            app(PaymentService::class)->cashBack(
                                $order,
                                'credit',
                                'TXN-' . \Illuminate\Support\Str::random(12)
                            );
                        }
                        app(LoyaltyService::class)->refundPoints($order, 'pos');
                    }
                    // ... save + recordTransition + ActionLog + AuditLogService::write(order.returned)
```

Constats :

- **V1** — `request->validate(['reason' => 'required|max:700'])` exécuté **dans** la `DB::transaction` ; un `ValidationException` 422 déclenche un rollback intégral (✅ acceptable, mais ouvre/ferme un tunnel transactionnel pour rien — finding mineur perf, pas P0).
- **V2 / H3** — `$order->transaction` est un `hasOne` non scoped sur `type` (cf. `Order.php:189-192`). Après une 1ʳᵉ ligne `cash_back` créée à T0, `$order->transaction` reste truthy à T1 → `cashBack()` rejouable. Aucun guard `if ($oldStatus === $newStatus) return $order;` en début de méthode.
- **H1** — Aucune route alternative ne marque RETURNED hors `OrderService::changeStatus` côté staff général ; les contrôleurs `PosOrderController:84-93`, `OnlineOrderController:94-97`, `TableOrderController:63-66` délèguent tous à `OrderService::changeStatus`. ⚠️ **Exception** : `KitchenDisplaySystemController` court-circuite ce service (cf. §1.7).
- **H2** — Garde `if ($order->transaction)` empêche le cashback si la commande n'a jamais été payée ✅.
- Chemin auto-cancel client (`auth=true`, lignes 1447-1485) **ne déclenche pas** la branche RETURNED (un client ne peut envoyer que CANCELED), donc le motif n'est pas exigé (`reason` optionnel) — non bloquant pour P3 mais cohérent avec H1.

### 1.2 `OrderStateMachine::allows` autorise `from === to`

```27:32:app/Domain/Order/OrderStateMachine.php
    public static function allows(int $from, int $to, ?Authenticatable $user = null): bool
    {
        if ($from === $to) {
            return true;
        }
```

Conséquence : `RETURNED → RETURNED` passe la rule `ValidStatusTransition`. `recordTransition` est protégé (`if ($fromStatus === $toStatus) return;` ligne 92-94), mais **pas** `cashBack`, `refundPoints`, `ActionLog::create`, ni `AuditLogService::write`. Effets indésirables d'un 2e appel sur une commande sans loyalty :

| Effet | Source |
|---|---|
| Nouvelle ligne `transactions(sign='-', type='cash_back')` | `PaymentService.php:33-42` (toujours `Transaction::create`, jamais d'update / dédoublonnage par `transaction_no`) |
| Re-crédit `users.balance += order->total` | `PaymentService.php:44-48` |
| Nouvel audit `payment.cash_back_issued` | `PaymentService.php:54-68` (chain HMAC OK mais comptablement faux) |
| Nouvel audit `order.returned` | `OrderService.php:1550-1565` |
| Nouvelle ligne `ActionLog 'Changement de statut'` | `OrderService.php:1531-1541` |
| Nouveau dispatch `OrderStatusChanged(returned, returned)` | `OrderService.php:1573-1578` (oldStatus=newStatus → le listener Outbox crée tout de même un `DomainEvent`) |

**Mitigation involontaire** sur les commandes **avec loyalty** : la 2e `LoyaltyTransaction(type='manual_add')` viole `UNIQUE(user_id, order_id, type)` (`database/migrations/2026_03_26_075919_add_unique_to_loyalty_transactions.php`) → `QueryException` rollback → 422 opaque. Ce n'est pas un design idempotent : c'est un effet de bord qui masque le bug pour ~1 sous-cas.

### 1.3 `Order::restoring` (V3)

```98:106:app/Models/Order.php
        static::restoring(function (self $order) {
            throw new \RuntimeException(
                'Order::restore() is disabled — OrderService::destroy() performs '
                . 'hard deletes on child rows (address, coupon) that cannot be '
                . 'rebuilt. A soft-deleted order is kept for audit only. '
                . 'To reopen an order, create a new one and reference the '
                . 'soft-deleted id in its notes.'
            );
        });
```

Tests : `tests/Feature/PosOrderRestoreIntegrityTest::test_restoring_a_soft_deleted_order_throws` (ligne 27-40), `test_force_deleting_a_trashed_order_still_works`, `test_save_on_a_live_order_is_not_blocked_by_restore_guard`. ✅ V3 PASS.

### 1.4 `LoyaltyService::refundPoints` (V7 / H5)

```21:38:app/Services/LoyaltyService.php
    public function refundPoints($order, string $sourceSurface = 'pos'): void
    {
        if (!$order->loyalty_customer_code) {
            return;
        }
        $redeemTxns = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', 'redeem')
            ->get();
        if ($redeemTxns->isEmpty()) {
            return;
        }
```

V7 confirmé. H5 réfutée. Test `OrderCancellationLoyaltyTest::test_cancel_order_without_loyalty_does_nothing` (ligne 96-124) prouve le no-op pour `loyalty_customer_code = null`.

⚠️ Note d'idempotence : `refundPoints` ne contrôle pas l'existence d'un `manual_add` antérieur — son idempotence repose entièrement sur l'index UNIQUE (cf. §1.2). Si l'index disparaissait (rollback de migration), un 2e appel **doublerait** les points re-crédités (`DB::table('users')->increment('loyalty_points', $totalPointsToRefund)` + nouvelle ligne `manual_add`).

### 1.5 `AuditLogService::write` — chain hash (V4 / H4)

```70:113:app/Services/Fiscal/AuditLogService.php
    public function write(array $data): AuditLog
    { /* exige action + branch_id explicite, lock per branch, DB::transaction → performInsert → prev_hash + HMAC SHA256 */ }
```

Payload écrit pour `order.returned` :

```1550:1565:app/Services/OrderService.php
                        app(AuditLogService::class)->write([
                            'branch_id'   => (int) $order->branch_id,
                            'user_id'     => Auth::check() ? (int) Auth::id() : null,
                            'action'      => $action,
                            'resource'    => 'order',
                            'resource_id' => (int) $order->id,
                            'payload'     => [
                                'order_serial_no' => $order->order_serial_no,
                                'from_status'     => (int) $oldStatusForBroadcast,
                                'to_status'       => (int) $request->status,
                                'reason'          => $request->reason,
                                'total'           => round((float) $order->total, 2),
                                'payment_status'  => (int) $order->payment_status,
                                'fiscal_sequence_no' => $order->fiscal_sequence_no,
                            ],
                        ]);
```

Couverture du cahier (V4) :

| Champ exigé | Présence | Source |
|---|---|---|
| `reason` | ✅ | payload.reason |
| `actor_id` | ✅ | row column `user_id` (`AuditLogService.php:117,131`) |
| `previous_status` | ✅ | payload.from_status |
| `payment_method` | ❌ | **absent** (seul `payment_status` figure) |
| Hash chain conservé | ✅ | `verifyChain($branchId)` retourne `null` après `test_change_status_to_returned_writes_order_returned_audit` (`PosOrderBL2AuditCallSitesTest.php:182-185`) |

→ **V4 = WARN** : sans `payment_method` ni `pos_payment_method` ni `transaction_id`, l'analyse forensique d'un cashBack croisé carte/cash ou d'un cashier détourné est dégradée. La chain HMAC reste intacte (H4 globalement réfutée).

### 1.6 Event + Outbox (V5)

`OrderService::changeStatus` (ligne 1573-1578) dispatch `\App\Events\OrderStatusChanged` après commit, dans un `try/catch` qui logge sur échec (conforme « dispatch after commit »).

Le listener `PersistOrderStatusChangedToOutbox` (`app/Listeners/PersistOrderStatusChangedToOutbox.php:14-39`) écrit un `DomainEvent` puis programme `DispatchDomainEventsJob` via `DB::afterCommit` :

```17:38:app/Listeners/PersistOrderStatusChangedToOutbox.php
        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_STATUS_CHANGED,
            ...
            'payload' => [
                'order_id' => $order->id,
                'queue_number' => $order->queue_number,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'token' => $order->token ?? null,
            ],
            ...
        ]);
        DB::afterCommit(function () use ($domainEvent): void {
            DispatchDomainEventsJob::dispatch($domainEvent->id)->onQueue('high');
        });
```

Constats V5 :

- ✅ Persistence outbox + dispatch after commit conformes.
- ⚠️ **Aucun event `OrderReturned` distinct** (`Glob app/Events/Order*.php` → uniquement `OrderCreated.php` et `OrderStatusChanged.php`). Les listeners downstream (KDS, OSS, customer apps, fiscal pipelines) doivent inférer le retour via `(old_status=13, new_status=22)`.
- ⚠️ **Le `reason` du retour n'est pas propagé** dans le payload outbox — les surfaces consommatrices ne reçoivent pas le motif.

### 1.7 V8 — Bypass KDS détecté (revue critique)

L'audit fait remonter un **second site d'écriture** capable de muter un Order vers RETURNED :

```117:157:app/Services/KitchenDisplaySystemOrderService.php
    public function changeStatus(Order $order, OrderStatusRequest $request)
    {
        try {
            $newStatus = (int) $request->status;
            $expectedFrom = (int) $order->status;

            $result = DB::transaction(function () use ($order, $newStatus, $expectedFrom) {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                ...
                if (!OrderStateMachine::allows((int) $locked->status, $newStatus, auth()->user())) {
                    throw new Exception(trans('all.message.invalid_status_transition'), 422);
                }
                $fromLocked = (int) $locked->status;
                $locked->status = $newStatus;
                $locked->save();
                OrderStateMachine::recordTransition(
                    Order::class, (int) $locked->id, $fromLocked, $newStatus,
                    auth()->check() ? (int) auth()->id() : null,
                    null
                );
                ...
            });
```

- Route exposée : `POST /api/admin/kds-order/change-status/{order}` (`routes/api.php:778`), middleware `permission:kitchen-display-system` (`KitchenDisplaySystemController.php:22`).
- `OrderStatusRequest::authorize()` (`app/Http/Requests/OrderStatusRequest.php:24`) accepte les rôles `Admin | Branch Manager | Chef | POS Operator | Cashier`.
- `OrderStateMachine::allows(DELIVERED, RETURNED)` retourne `true` (`OrderStateMachine.php:57-58`).
- L'`OrderStatusRequest` n'impose pas `reason` (la validation `required|max:700` n'existe **que** dans `OrderService::changeStatus` ; le KDS service ne la rappelle pas).

Conséquence : un porteur du permission `kitchen-display-system` peut envoyer `{status: 22, reason omitted}` sur un Order DELIVERED depuis un client direct → la transition est persistée, mais :

- ❌ aucun `cashBack` (le cashier ne le sait pas, le client n'est pas remboursé) ;
- ❌ aucun `refundPoints` (loyalty corrompue) ;
- ❌ aucune ligne `audit_logs.order.returned` (NF525 trou) ;
- ❌ aucune `ActionLog 'Changement de statut'` typée retour ;
- ✅ `OrderStateMachine::recordTransition` écrit bien la ligne (avec `reason=null`).

L'UI KDS standard ne propose pas le bouton « Retourner » (l'`index()` filtre les statuts ACCEPT/PREPARING/PREPARED, ligne 54), mais le contrôle d'accès du POS-110 doit raisonner par endpoint, pas par UI. → **V8 FAIL** sur le périmètre fiscal NF525, **WARN** sur l'exploitation pratique (rôle Chef requis + Order DELIVERED — combinaison rare en flux normal).

### 1.8 Z/X reports (V6)

```207:281:app/Services/Fiscal/ZReportService.php
        $query = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereNotNull('fiscal_sequence_no')
            ->where('created_at', '<=', $to);
        ...
        $orders = (clone $query)->where('payment_status', '!=', PaymentStatus::UNPAID)->get();
        // total_ttc / total_ht / total_tva agrégés sur $orders (inclut RETURNED car payment_status reste PAID)
        $cancelCount = (clone $query)->where('status', OrderStatus::CANCELED)->count();
        $refundCount = (clone $query)->where('status', OrderStatus::RETURNED)->count();
```

Constats V6 (WARN) :

- ✅ `refund_count` exposé sur le Z signé.
- ⚠️ `total_ttc/ht/tva` **incluent** la commande RETURNED — `payment_status` reste `PAID` après retour (jamais flippé en UNPAID/REFUNDED), et le filtre Z est `payment_status != UNPAID`.
- ⚠️ Aucun document fiscal négatif (« note de crédit » / AVOIR) avec son propre `fiscal_sequence_no` n'est émis. Le retour mute l'original au lieu de produire une ligne fiscale séparée. Le `cash_back` est tracé dans `transactions` (`sign='-', type='cash_back'`) mais n'est pas réfléchi dans les agrégats Z.
- ✅ `total_by_method` et `total_by_tax_rate` calculés sur `order_items` (cohérence stricte avec les receipts NF525 individuels — invariant d'audit `POS-9-H.2.8 / F-B6`).

---

## 2. Pass B — Tests + matrice scénarios

### 2.1 Inventaire couverture

| Fichier:test | Scénario couvert | V correspondant |
|---|---|---|
| `tests/Feature/Fiscal/PosOrderBL2AuditCallSitesTest::test_change_status_to_returned_writes_order_returned_audit` (`:161-186`) | DELIVERED → RETURNED 200 + audit `order.returned` + chain valide | V4 (partiel), V1 indirect |
| `…::test_returned_without_reason_fails_validation` (`:188-199`) | RETURNED sans `reason` → 422 | V1 |
| `tests/Feature/OrderCancellationLoyaltyTest::test_cancel_order_refunds_loyalty_points` (`:73-94`) | `LoyaltyService::refundPoints` crédite et écrit `manual_add` | V7 (positif) |
| `…::test_cancel_order_without_loyalty_does_nothing` (`:96-124`) | sans `loyalty_customer_code` → no-op | V7 / H5 |
| `tests/Feature/PosOrderRestoreIntegrityTest::test_restoring_a_soft_deleted_order_throws` (`:27-40`) | `Order::restore()` bloqué | V3 |
| `…::test_force_deleting_a_trashed_order_still_works` (`:42-55`) | forceDelete autorisé | V3 (négatif/limite) |

**Manques** :

- ❌ Pas de test idempotence `2× DELIVERED→RETURNED` (sans loyalty / avec loyalty / sans paiement).
- ❌ Pas de test E2E « POS RETURNED + LoyaltyTransaction redeem antérieure » — la couverture loyalty est unitaire (service direct), pas via l'endpoint.
- ❌ Pas de test couvrant le KDS bypass (§1.7).
- ❌ Pas de test post-Z (RETURNED après clôture Z(N) ne déclenche aucune note dans Z(N+1)).

### 2.2 Matrice scénarios RETURNED

| Scénario | Couvert ? | Comportement attendu | Comportement réel observé | Verdict |
|---|---|---|---|---|
| DELIVERED → RETURNED, motif présent, payé cash, sans loyalty | ✅ `test_change_status_to_returned_writes_order_returned_audit` | 200, audit chain OK | Conforme | **PASS** |
| DELIVERED → RETURNED, **motif vide** | ✅ `test_returned_without_reason_fails_validation` | 422 | Conforme | **PASS** |
| **2× DELIVERED → RETURNED, sans loyalty** (idempotence) | ❌ non couvert | 2e appel idempotent (no-op ou 409) | 2e appel passe : nouveau `transactions.cash_back`, `users.balance` re-crédité, 2e `ActionLog`, 2e `audit_logs.order.returned`, dispatch `OrderStatusChanged(22→22)` créant un `DomainEvent` parasite. Chain HMAC reste valide. | **FAIL** |
| 2× DELIVERED → RETURNED **avec** loyalty redeem | ❌ non couvert | Idempotent | 2e `LoyaltyTransaction(manual_add)` viole `UNIQUE(user_id,order_id,type)` → rollback → 422 opaque (`QueryException`). Mitigation involontaire, pas par design. | **MASQUE** |
| DELIVERED → RETURNED **sans paiement préalable** (`order->transaction` null) | ❌ non couvert direct | No cashback | `if ($order->transaction)` → cashback skipped ✅ ; `refundPoints` no-op si pas de redeem | **PASS** |
| DELIVERED → RETURNED **avec** loyalty redeem (1ʳᵉ fois) | ⚠️ couvert seulement au niveau service | Refund points + audit `order.returned` + manual_add | Service couvert isolément ; pas de test E2E POS → endpoint | **WARN** |
| RETURNED **post-Z** (clôture déjà signée) | ❌ non couvert | RETURNED autorisé, Z(N) immutable, Z(N+1) reflète l'écart | `refund_count++` dans Z(N+1) mais `total_ttc` non rabattu — divergence comptable cumulée non visible dans le PDF Z | **WARN** |
| Bypass via `destroy` (admin) | ❌ non testé | Pas de RETURNED via destroy | `OrderService::destroy()` (`:1714-1768`) = soft-delete + hard-delete enfants ; ne touche pas `status`. | **PASS** |
| **Bypass via KDS endpoint** (Chef → status=22) | ❌ non couvert | 422 ou délégation à `OrderService::changeStatus` | `KitchenDisplaySystemOrderService::changeStatus` mute le statut sans motif/cashback/refund/audit. | **FAIL** |
| Self-cancel client → status=22 | n/a | Refusé | `OrderStatusRequest::authorize` n'autorise `kiosk:order` que pour status=16 ; un user normal sans rôle staff = 403 ✅ | **PASS** |

### 2.3 Hypothèses §3 challengées

| ID | Hypothèse | Verdict | Preuve |
|---|---|---|---|
| H1 | Motif contournable via destroy ou autre route | **PARTIELLEMENT CONFIRMÉ** | `destroy` ne touche pas `status` (réfutée), mais `KitchenDisplaySystemOrderService::changeStatus` (§1.7) bypasse motif **et** cashback / refund / audit |
| H2 | RETURNED sans paiement → cashback positif | ❌ Réfutée | Garde `if ($order->transaction)` (`OrderService.php:1508`) |
| H3 | Double appel RETURNED → cashback doublé | ✅ **Confirmée** pour orders sans loyalty (cf. matrice §2.2) |
| H4 | Pas d'audit NF525 / chain rompue | ❌ Réfutée (audit présent, chain OK) ; mais payload incomplet → V4 WARN |
| H5 | refundPoints appelé même sans redeem | ❌ Réfutée | Garde `redeemTxns->isEmpty()` (`LoyaltyService.php:31`) |

---

## 3. Vérifications obligatoires §5 — synthèse pondérée

| V | Statut | Preuve fichier:ligne | Remarque |
|---|---|---|---|
| V1 | **PASS** | `OrderService.php:1502-1504` ; `PosOrderBL2AuditCallSitesTest.php:188-199` | Validation seulement pour staff path et seulement dans `OrderService::changeStatus` (cf. V8 pour KDS) |
| **V2** | **FAIL** | `OrderStateMachine.php:29-31` ; `OrderService.php:1499-1567` ; `PaymentService.php:31-72` | Aucun guard `oldStatus === newStatus` ; `cashBack` non idempotent (toujours `Transaction::create`) |
| V3 | **PASS** | `Order.php:98-106` ; `PosOrderRestoreIntegrityTest.php:27-40` | OK |
| V4 | **WARN** | `OrderService.php:1550-1565` (payload) ; `AuditLogService.php:115-173` (chain) ; `PosOrderBL2AuditCallSitesTest.php:182-185` (verifyChain) | `payment_method` / `pos_payment_method` / `transaction_id` absents |
| V5 | **PARTIAL** | `OrderService.php:1573-1578` ; `PersistOrderStatusChangedToOutbox.php:14-39` ; `Glob app/Events/Order*.php` | Pas d'event `OrderReturned` ; outbox payload sans `reason` |
| V6 | **WARN** | `ZReportService.php:207-281` | `refund_count` OK ; `total_ttc/ht/tva` non net ; pas d'AVOIR avec `fiscal_sequence_no` propre |
| V7 | **PASS** | `LoyaltyService.php:21-38` ; `OrderCancellationLoyaltyTest.php:96-124` | Double garde + test négatif |
| **V8** | **FAIL** | `KitchenDisplaySystemOrderService.php:117-157` ; `routes/api.php:778` ; `KitchenDisplaySystemController.php:22` ; `OrderStatusRequest.php:24` ; `OrderStateMachine.php:57-58` | Bypass motif + cashback + refund + audit via endpoint KDS |

---

## 4. Checklist NF525 (RETURNED)

| Exigence | Statut | Détail |
|---|---|---|
| Document fiscal du retour daté + signé | **PARTIAL** | Audit row `order.returned` HMAC-chaîné par branche, mais pas de `fiscal_sequence_no` propre (réutilise celui de la commande d'origine) |
| Motif tracé | **PASS** | `payload.reason` + `Order.reason` |
| Actor identifiable | **PASS** | `audit_logs.user_id` + `ActionLog.user_id` |
| Cash-back tracé | **PASS** | `payment.cash_back_issued` audit + `transactions(sign='-',type='cash_back')` |
| Z reflète les retours | **PARTIAL** | `refund_count` présent ; `total_ttc` brut |
| Immutabilité audit_logs | **PASS** | UPDATE/DELETE bloqués DB-niveau (POS-9.4.3) ; `Order::restore()` bloqué |
| Idempotence opérationnelle | **FAIL** | Cf. V2 |
| Étanchéité périmètre RETURNED | **FAIL** | Cf. V8 (bypass KDS) |

---

## 5. Findings prioritaires

| ID | Sévérité | Titre | Preuve |
|---|---|---|---|
| **F-VERIFY-03-01** | **P0** | Idempotence `changeStatus(RETURNED)` cassée — 2e appel double cashback / audit / outbox sur orders sans loyalty | `OrderStateMachine.php:29-31` (`from===to` allowed) ; `OrderService.php:1499-1567` (pas de guard) ; `PaymentService.php:31-72` (`Transaction::create` inconditionnel) |
| **F-VERIFY-03-02** | **P0** | Bypass KDS — Chef peut RETURNED un Order DELIVERED sans motif/cashback/refund/audit fiscal | `KitchenDisplaySystemOrderService.php:117-157` ; `routes/api.php:778` ; `OrderStatusRequest.php:24` ; `OrderStateMachine.php:57-58` |
| **F-VERIFY-03-03** | **P0/P1** | Z report — `total_ttc/ht/tva` incluent les commandes RETURNED ; pas de note de crédit avec `fiscal_sequence_no` propre | `ZReportService.php:207-281` ; `OrderService::changeStatus` ne flippe jamais `payment_status` ni n'émet de séquence négative |
| **F-VERIFY-03-04** | **P1** | Audit `order.returned` payload incomplet — `payment_method` / `pos_payment_method` / `transaction_id` absents → forensique dégradée | `OrderService.php:1550-1565` |
| **F-VERIFY-03-05** | **P1** | Outbox `OrderStatusChanged` ne propage pas `reason` ; pas d'event `OrderReturned` dédié → KDS/OSS/customer apps n'affichent pas le motif | `PersistOrderStatusChangedToOutbox.php:18-29` ; `Glob app/Events/Order*.php` |
| F-VERIFY-03-06 | P2 | `PaymentService::cashBack($order, 'credit', …)` ignore le mode de paiement original — un retour cash crédite `users.balance` au lieu de produire un remboursement espèces | `OrderService.php:1509-1513` ; `PaymentService.php:31-48` |
| F-VERIFY-03-07 | P2 | `refundPoints` n'est pas idempotent par design — repose entièrement sur `UNIQUE(user_id,order_id,type)` (`2026_03_26_075919_add_unique_to_loyalty_transactions.php`) ; un rollback de migration douberait les points | `LoyaltyService.php:35-71` |
| F-VERIFY-03-08 | P2 | `request->validate(['reason'…])` exécuté **dans** la `DB::transaction` → ouvre/ferme un tunnel pour rien sur 422 | `OrderService.php:1502-1504` |
| F-VERIFY-03-09 | P3 | `OrderStateMachine::allows` permet `from === to` globalement, ce qui complique l'idempotence ailleurs ; cas légitime non documenté | `OrderStateMachine.php:29-31` |

---

## 6. Cycles P proposés + routing modèle

| Cycle | Sujet | Sévérité | Routage suggéré (cf. `.cursor/routing.md`) |
|---|---|---|---|
| **P11_RETURNED_IDEMPOTENCY** | Ajouter `if ((int) $order->status === (int) $request->status) { return $order; }` après `ValidStatusTransition` et **avant** la `DB::transaction` ; couvrir tests `2×` (a) sans loyalty (b) avec loyalty (c) sans paiement. **Ne pas** modifier `OrderStateMachine::allows` (frozen zone V1). | **P0** | **GPT-5.4 / `foodking-complex-implementer`** — touche logique de cycle de vie + état atomique |
| **P12_RETURNED_FISCAL_INTEGRATION** | Soit retirer la commande RETURNED des `total_ttc/ht/tva` du Z, soit créer une ligne « note de crédit » avec `fiscal_sequence_no` négatif/dédié. Décision NF525 à arbitrer (`docs/FISCAL_SECRETS.md` + `AUDIT_POS_110_FISCAL_NF525_2026-04-19.md`). Tests Z post-RETURNED + X intraday. | **P0/P1** | **Claude (plan) → GPT-5.4 / `foodking-complex-implementer`** — décision architecture fiscale + impl complexe |
| **P13_RETURNED_KDS_BYPASS_LOCKDOWN** | Dans `KitchenDisplaySystemOrderService::changeStatus`, refuser `RETURNED/CANCELED/REJECTED` (rediriger vers `OrderService::changeStatus`) **ou** dupliquer la barrière motif + cashback + refund + audit. Ajouter test E2E « Chef + KDS endpoint + status=22 » → 422. | **P0** | **GPT-5.4 / `foodking-complex-implementer`** — symétrie services + frozen zone |
| **P14_RETURNED_AUDIT_PAYLOAD_ENRICHMENT** | Ajouter `payment_method`, `pos_payment_method`, `transaction_id`, `transaction_no` au payload `order.returned` ; propager `reason` dans le payload outbox (ou créer `OrderReturned` event dédié). | **P1** | **Composer / `foodking-routine-implementer`** — patch ciblé, test unitaire payload |
| **P15_CASHBACK_GATEWAY_ALIGNMENT** | Aligner `PaymentService::cashBack($order, 'credit', …)` pour utiliser `pos_payment_method`/`payment_method` original au lieu du `'credit'` en dur ; documenter la sémantique (crédit compte vs remboursement espèces). | **P2** | **Composer / `foodking-routine-implementer`** + revue Claude |

---

## 7. Risques cachés & axes hors-périmètre signalés

1. **Double cashback silencieux (P0)** : pas de log warning ni de message UX sur la 2e mutation RETURNED → un cashier maladroit double l'avoir client sans déclencher d'alerte.
2. **Z post-RETURNED non rabattu** : la divergence comptable est cumulative ; au-delà du correctif fiscal P12, il faut un job de réconciliation (`refund_amount_diff = sum(transactions.cash_back) - sum(z_reports.refund_count_amount)`).
3. **`OrderStatusChanged(22→22)` parasite** : sur un 2e appel idempotent involontaire, l'outbox crée un `DomainEvent` redondant que les listeners doivent ignorer (sinon double notif KDS / OSS).
4. **Couverture tests insuffisante** : sur 8 vérifications V1-V8, seules V1 / V3 / V4 partiel / V7 ont un test direct. V2 / V5 / V6 / V8 sont **non testés** — cf. `docs/MASSIVE_TEST_PLAN.md` à compléter.
5. **`OrderCancellationLoyaltyTest`** : tests passent au niveau service mais pas au niveau endpoint ; l'addition d'un test E2E POS via `actingAs($admin) → /api/admin/pos-order/change-status/{id}` est un quick-win.

---

## 8. Conclusion

Critère §6 du fichier de tâche : *« FAIL si idempotence cassée ou audit NF525 incomplet »* — les deux conditions sont vérifiées (V2 + V8 + V6 partial).

> **GLOBAL: FAIL — Idempotence cashback/audit non garantie sur 2e RETURNED + bypass motif/cashback/refund/audit via endpoint KDS ; cycles P11 et P13 à ouvrir en P0, P12/P14/P15 à programmer.**
