# PLAN_AUDIT_F004 — Cancel Reason Enforcement
**Severity:** P1 — Audit trail incomplet sur transitions terminales
**Owner agent:** Agent D (State machine & idempotency)
**Sprint:** S3
**Estimated:** 1 jour-agent
**Frozen-zone override:** Partiel — `OrderStateMachine` (frozen) NON modifié, on enforce côté call sites + frontend

---

## 0. STOP CHECKLIST

| # | Question | Réponse |
|---|---|---|
| 1 | Why ? | State machine docs exigent `reason` non vide pour CANCELED/REJECTED/RETURNED ; les call sites frozen et frontend kiosk envoient sans reason → audit trail aveugle |
| 2 | What minimal ? | (a) Whitelist enum `OrderCancelReason` ; (b) Frontend envoie reason ; (c) Backend `OrderStatusRequest` + service rejette si terminal sans reason |
| 3 | Where ? | `app/Enums/OrderCancelReason.php` (nouveau), `app/Http/Requests/OrderStatusRequest.php`, `FrontendOrderService::changeStatus`, `OrderService::changeStatus`, frontend KioskPaymentComponent + POS Vue cancel paths |
| 4 | Who ? | KDS (cancel from kitchen), Manager dashboard, Caissier POS, Kiosk auto-cancel TPE |
| 5 | How valider ? | `tests/Feature/OrderCancelReasonTest.php`, suite OrderStateMachine, Vue cancel tests |
| 6 | When rollback ? | Si cancel flow legit échoue 422 sur reason valide → revert |

---

## 1. THINK

[`KioskPaymentComponent.vue:545`](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:545) :
```js
axios.post(`frontend/order/change-status/${this._lastOrder.id}`, { status: 16 })
```

[`FrontendOrderService.php:644-678`](app/Services/FrontendOrderService.php:644) : la méthode `changeStatus` valide via `ValidStatusTransition` mais `recordTransition` est appelé avec `reason = null` (ligne 677).

[`docs/ORDER_FLOW.md:48`](docs/ORDER_FLOW.md:48) : table légale documente `reason required` pour CANCELED/REJECTED/RETURNED.

→ Drift entre doc et code : la doc exige, le code n'enforce pas dans les call sites frozen.

## 2. PLAN

### 2.1 Enum whitelist

`app/Enums/OrderCancelReason.php` :

```php
<?php

namespace App\Enums;

class OrderCancelReason
{
    public const TPE_CANCEL_USER       = 'tpe_cancel_user';
    public const TPE_DECLINED          = 'tpe_declined';
    public const TPE_TIMEOUT           = 'tpe_timeout';
    public const CASHIER_VOID          = 'cashier_void';
    public const CUSTOMER_REQUEST      = 'customer_request';
    public const STOCKOUT              = 'stockout';
    public const KITCHEN_REJECT        = 'kitchen_reject';
    public const DUPLICATE             = 'duplicate';
    public const MANAGER_OVERRIDE      = 'manager_override';
    public const SYSTEM_TIMEOUT        = 'system_timeout';
    public const PAYMENT_FAILED        = 'payment_failed';
    public const OTHER                 = 'other';

    public static function all(): array
    {
        return [
            self::TPE_CANCEL_USER, self::TPE_DECLINED, self::TPE_TIMEOUT,
            self::CASHIER_VOID, self::CUSTOMER_REQUEST, self::STOCKOUT,
            self::KITCHEN_REJECT, self::DUPLICATE, self::MANAGER_OVERRIDE,
            self::SYSTEM_TIMEOUT, self::PAYMENT_FAILED, self::OTHER,
        ];
    }

    public static function isValid(?string $code): bool
    {
        return is_string($code) && in_array($code, self::all(), true);
    }
}
```

### 2.2 Request validation

`app/Http/Requests/OrderStatusRequest.php` (existant, à enrichir) :

```php
public function rules(): array
{
    return [
        'status' => ['required', 'integer'],
        'reason' => [
            'sometimes',
            'nullable',
            'string',
            'max:255',
        ],
    ];
}

public function withValidator($validator)
{
    $validator->after(function ($v) {
        $status = (int) $this->input('status');
        $terminals = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];
        if (in_array($status, $terminals, true)) {
            $reason = trim((string) $this->input('reason', ''));
            if ($reason === '') {
                $v->errors()->add('reason', 'Reason is required for cancel, reject or return transitions.');
                return;
            }
            if (!OrderCancelReason::isValid($reason)) {
                $v->errors()->add('reason', 'Reason code is not whitelisted.');
            }
        }
    });
}
```

### 2.3 Service propagation

`FrontendOrderService::changeStatus` ligne 671-678 :

**BEFORE :**

```php
OrderStateMachine::recordTransition(
    FrontendOrder::class,
    (int) $frontendOrder->id,
    (int) $oldStatus,
    (int) $request->status,
    Auth::check() ? (int) Auth::id() : null,
    null
);
```

**AFTER :**

```php
$reason = $request->input('reason');
$terminals = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];
if (in_array((int) $request->status, $terminals, true) && empty($reason)) {
    throw new \InvalidArgumentException('Reason required for terminal transition.', 422);
}
OrderStateMachine::recordTransition(
    FrontendOrder::class,
    (int) $frontendOrder->id,
    (int) $oldStatus,
    (int) $request->status,
    Auth::check() ? (int) Auth::id() : null,
    $reason  // ⬅ enforced
);
```

Idem dans `OrderService::changeStatus`.

### 2.4 Frontend kiosk

`KioskPaymentComponent.vue:545` :

**BEFORE :**

```js
axios.post(`frontend/order/change-status/${this._lastOrder.id}`, { status: 16 })
```

**AFTER :**

```js
axios.post(`frontend/order/change-status/${this._lastOrder.id}`, {
  status: 16,
  reason: 'tpe_cancel_user',
})
```

Chercher tous les call sites côté frontend :

```bash
grep -rn "change-status\|changeStatus.*16" resources/js/ --include="*.vue" --include="*.js"
```

Ajouter `reason` partout selon contexte (TPE decline → 'tpe_declined', timeout → 'tpe_timeout', etc.).

## 3. BUILD — Sub-tasks

### 3.1 Enum + Request (1h)

1. Créer `app/Enums/OrderCancelReason.php`.
2. Modifier `app/Http/Requests/OrderStatusRequest.php`.
3. Test unit `tests/Unit/Enums/OrderCancelReasonTest.php`.

### 3.2 Test rouge (1h)

`tests/Feature/OrderCancelReasonTest.php` :

```php
/** @test */
public function rejects_cancel_without_reason(): void
{
    $order = $this->createOrderInState(OrderStatus::PENDING);
    $r = $this->postJson("/api/admin/pos-order/change-status/{$order->id}", ['status' => 16]);
    $r->assertStatus(422);
    $r->assertJsonValidationErrors(['reason']);
}

/** @test */
public function rejects_cancel_with_unknown_reason_code(): void
{
    $order = $this->createOrderInState(OrderStatus::PENDING);
    $r = $this->postJson("/api/admin/pos-order/change-status/{$order->id}", [
        'status' => 16,
        'reason' => 'made_up_code'
    ]);
    $r->assertStatus(422);
}

/** @test */
public function accepts_cancel_with_valid_reason(): void
{
    $order = $this->createOrderInState(OrderStatus::PENDING);
    $r = $this->postJson("/api/admin/pos-order/change-status/{$order->id}", [
        'status' => 16,
        'reason' => 'cashier_void'
    ]);
    $r->assertStatus(200);
    $this->assertDatabaseHas('order_status_transitions', [
        'order_id' => $order->id,
        'to_status' => 16,
        'reason' => 'cashier_void',
    ]);
}

/** @test */
public function accepts_non_terminal_transition_without_reason(): void
{
    $order = $this->createOrderInState(OrderStatus::PENDING);
    $r = $this->postJson("/api/admin/pos-order/change-status/{$order->id}", ['status' => 4]);
    $r->assertStatus(200);
}

/** @test */
public function kiosk_cancel_persists_reason_in_audit_log(): void
{
    $order = $this->createKioskOrderInState(OrderStatus::PENDING);
    $r = $this->actingAs($this->kioskUser, 'sanctum')
        ->postJson("/api/frontend/order/change-status/{$order->id}", [
            'status' => 16,
            'reason' => 'tpe_cancel_user',
        ]);
    $r->assertStatus(200);
    $this->assertDatabaseHas('order_status_transitions', [
        'order_id' => $order->id,
        'to_status' => 16,
        'reason' => 'tpe_cancel_user',
    ]);
}
```

### 3.3 Backend implementation (2h)

Voir §2.2 et §2.3 ci-dessus.

### 3.4 Frontend implementation (3h)

1. Identifier tous les call sites change-status.
2. Ajouter le bon `reason` selon contexte.
3. Mettre à jour les Vue tests.

### 3.5 Verification (1h)

```bash
./vendor/bin/phpunit tests/Feature/OrderCancelReasonTest.php
./vendor/bin/phpunit tests/Unit/Domain/Order/OrderStateMachineTest.php
./vendor/bin/phpunit tests/Feature/Pos/
./vendor/bin/phpunit tests/Feature/KioskFrontendComprehensiveTest.php
npm run test
```

## 4. ACCEPTANCE CRITERIA

| AC | Critère |
|---|---|
| AC1 | Cancel sans reason → 422 |
| AC2 | Cancel avec reason inconnu → 422 |
| AC3 | Cancel avec reason whitelisté → 200 + persist |
| AC4 | Transition non-terminale sans reason → 200 (no change) |
| AC5 | Frontend kiosk envoie 'tpe_cancel_user' |
| AC6 | `OrderStateMachine::recordTransition` reçoit reason |
| AC7 | `order_status_transitions.reason` populated |
| AC8 | Pas de régression sur OrderStateMachineTest (77 cas) |

## 5. ANTI-DRIFT

- [ ] `OrderStateMachine` (le domain) non modifié — only call sites
- [ ] Pas de touche frozen kiosk wizard
- [ ] Backfill historique des `reason=NULL` NON appliqué (forward-only)

## 6. ROLLBACK

`git revert` sûr — purement additif côté validation. Migrate vers `nullable=false` reportée à un plan séparé (P3).

## 7. RISK

| Risk | Mit |
|---|---|
| Frontend POS web cancel sans reason → 422 surprise | Identifier tous les call sites avant merger |
| Tests OrderStateMachineTest existants passent reason=null → expected | Vérifier et adapter si besoin |

## 8. DEFINITION OF DONE

- [ ] 5+ tests verts ajoutés
- [ ] 0 régression
- [ ] Tous les frontend cancel paths envoient reason valide
- [ ] Commit : `audit(F-004): enforce reason on cancel/reject/return transitions`
- [ ] Report + Graphiti
