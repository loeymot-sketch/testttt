# PLAN_AUDIT_F009 — Kiosk Cash Backend Confirmation Hook
**Severity:** P1 — Audit cash kiosk impossible
**Owner agent:** Agent B (Payment integrity)
**Sprint:** S4
**Estimated:** 1 jour-agent
**Frozen-zone override:** NO
**Décision dépendante :** F-003 = Option A (cashier-supervised) **actée par orchestrateur**

---

## 0. STOP CHECKLIST

| # | Q | A |
|---|---|---|
| 1 | Why ? | Kiosk cash flow ne push aucun signal backend après ouverture drawer → impossible de distinguer (drawer ouvert + cash dedans) vs (drawer ouvert + rien) vs (drawer fail + order PAID quand même) |
| 2 | What ? | Endpoint `cash-acknowledge` + frontend hook après `kioskHardware.openDrawer()`. Persiste `cash_acknowledged_at`, `drawer_event_status`, link cash_movement (F-003 wiring) |
| 3 | Where ? | Nouveau `KioskCashAcknowledgeController`, route `/api/frontend/order/{id}/cash-acknowledge`, `KioskPaymentComponent.processCashPayment` enrichi, schema `frontend_orders.cash_acknowledged_at` |
| 4 | Who ? | Z report (visibilité discrepancy), comptable, ops dashboard |
| 5 | How ? | `tests/Feature/Kiosk/KioskCashAcknowledgeTest.php` |
| 6 | When rollback ? | Si flow cash legit échoue 422 sur acknowledge |

---

## 1. THINK

[`KioskPaymentComponent.vue:494-513`](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:494) :

```js
async processCashPayment(navTarget) {
  if (kioskHardware.isKioskBridge()) {
    const drawerResult = await kioskHardware.openDrawer();
    if (!drawerResult.ok) {
      this._reportDrawerFailure(drawerResult.error || 'no success');
    }
  }
  // No backend call
  this.$router.push(navTarget);
}
```

Combiné avec [`FrontendOrderService.php:200`](app/Services/FrontendOrderService.php:200) qui pose `payment_status=PAID` dès création pour cash kiosk → le backend ne peut pas distinguer :
- Cash réussi (drawer ouvert, cash collecté)
- Drawer fail (drawer fermé, status PAID quand même)
- Drawer OK mais user parti sans payer (no detection)

## 2. PLAN — Option A choisie

Pas d'état intermédiaire `PENDING_CASH` (casse state machine V1 frozen). À la place :
- L'order naît PAID (statu quo).
- Un signal backend `cash-acknowledge` est posé après `openDrawer()` succès.
- Schema `frontend_orders.cash_acknowledged_at` (NULL si pas confirmé).
- Le Z report (F-003) inclut `cash_paid_count` vs `cash_acknowledged_count` discrepancy.

### 2.1 Schema

`database/migrations/2026_05_xx_add_cash_acknowledge_to_orders.php` :

```sql
ALTER TABLE orders
  ADD COLUMN cash_acknowledged_at TIMESTAMP NULL AFTER payment_status,
  ADD COLUMN cash_drawer_event_status VARCHAR(50) NULL AFTER cash_acknowledged_at;
```

(orders + frontend_orders partagent la table 'orders')

### 2.2 Endpoint

`app/Http/Controllers/Frontend/CashAcknowledgeController.php` :

```php
class CashAcknowledgeController extends Controller
{
    public function acknowledge(FrontendOrder $frontendOrder, \Illuminate\Http\Request $request)
    {
        $request->validate([
            'drawer_event_status' => ['required', 'string', 'in:opened,fail,timeout'],
            'amount_collected_cents' => ['required', 'integer', 'min:0'],
        ]);

        $authId = (int) Auth::id();
        if ((int) $frontendOrder->user_id !== $authId) {
            return response(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $expectedCents = (int) round($frontendOrder->total * 100);
        $providedCents = (int) $request->input('amount_collected_cents');

        // Tolérance ±1 cent (cf. F-002)
        if (abs($providedCents - $expectedCents) > 1
            && $request->input('drawer_event_status') === 'opened') {
            // Seulement warning si drawer ouvert ET amount différent
            \Illuminate\Support\Facades\Log::warning('[Kiosk Cash] amount mismatch on acknowledge', [
                'order_id' => $frontendOrder->id,
                'expected_cents' => $expectedCents,
                'provided_cents' => $providedCents,
            ]);
            // Ne pas reject — c'est de l'observabilité, pas un blocker
        }

        DB::transaction(function () use ($frontendOrder, $request) {
            $locked = FrontendOrder::where('id', $frontendOrder->id)->lockForUpdate()->first();
            if ($locked->cash_acknowledged_at !== null) {
                return; // idempotent
            }
            $locked->cash_acknowledged_at = now();
            $locked->cash_drawer_event_status = $request->input('drawer_event_status');
            $locked->save();
        });

        // Si F-003 schema en place : record cash_movement (déjà fait dans FrontendOrderService::myOrderStore via hook F-003)
        // Le movement existe déjà — on ajoute juste l'ack sur l'order.

        return response([
            'status' => true,
            'message' => 'Cash acknowledged',
            'data' => ['order_id' => $frontendOrder->id]
        ], 200);
    }
}
```

Route :
```php
Route::post('/order/{frontendOrder}/cash-acknowledge', [CashAcknowledgeController::class, 'acknowledge'])
    ->middleware(['auth:sanctum', 'throttle:60,1']);
```

### 2.3 Frontend hook

`KioskPaymentComponent.processCashPayment` modifié :

```js
async processCashPayment(navTarget) {
  let drawerStatus = 'fail';
  if (kioskHardware.isKioskBridge()) {
    const drawerResult = await kioskHardware.openDrawer();
    if (drawerResult.ok) {
      drawerStatus = 'opened';
    } else {
      drawerStatus = drawerResult.error === 'timeout' ? 'timeout' : 'fail';
      this._reportDrawerFailure(drawerResult.error || 'no success');
    }
  } else {
    // Stub — simule "opened"
    drawerStatus = 'opened';
  }

  // [AUDIT-F-009] Push backend acknowledge — observability for cash flow
  if (this._lastOrder?.id) {
    try {
      await axios.post(
        `frontend/order/${this._lastOrder.id}/cash-acknowledge`,
        {
          drawer_event_status: drawerStatus,
          amount_collected_cents: Math.round((this._lastOrder.total || this.cartTotal) * 100),
        }
      );
    } catch (e) {
      // Non-blocking — log + continue
      console.warn('[KioskPayment] cash-acknowledge failed:', e.message);
    }
  }

  // Analytics
  try {
    kioskAnalytics.track('payment_completed', {
      method: 'cash',
      drawer_status: drawerStatus,
      total_cents: Math.round((this._lastOrder?.total || this.cartTotal) * 100),
    });
  } catch (_) {}

  this.$router.push(navTarget);
}
```

### 2.4 Z report enrichment (couplé F-003)

Dans `ZReportService::aggregate` (déjà enrichi par F-003) :

```php
// [AUDIT-F-009] Cash discrepancy : orders cash PAID without ack
$cashOrdersPaid = (clone $query)
    ->where('payment_method', PaymentGateway::CASH_ON_DELIVERY)
    ->whereIn('order_type', [OrderType::KIOSK, OrderType::TAKEAWAY])
    ->count();

$cashOrdersAcknowledged = (clone $query)
    ->where('payment_method', PaymentGateway::CASH_ON_DELIVERY)
    ->whereIn('order_type', [OrderType::KIOSK, OrderType::TAKEAWAY])
    ->whereNotNull('cash_acknowledged_at')
    ->count();

$cashUnacknowledgedCount = $cashOrdersPaid - $cashOrdersAcknowledged;
```

Inclure dans le payload signé du Z report.

## 3. BUILD

### 3.1 Migration + endpoint (3h)

§2.1 + §2.2.

### 3.2 Frontend hook (2h)

§2.3.

### 3.3 Z report enrichment (2h)

§2.4 + tests.

### 3.4 Tests (1h)

`tests/Feature/Kiosk/KioskCashAcknowledgeTest.php` :

```php
/** @test */
public function acknowledges_cash_drawer_opened(): void
{
    $order = $this->createKioskCashOrder(50.00);
    $r = $this->postJson("/api/frontend/order/{$order->id}/cash-acknowledge", [
        'drawer_event_status' => 'opened',
        'amount_collected_cents' => 5000,
    ]);
    $r->assertStatus(200);
    $this->assertNotNull($order->fresh()->cash_acknowledged_at);
    $this->assertEquals('opened', $order->fresh()->cash_drawer_event_status);
}

/** @test */
public function acknowledges_drawer_fail_status(): void
{
    $order = $this->createKioskCashOrder(50.00);
    $r = $this->postJson("/api/frontend/order/{$order->id}/cash-acknowledge", [
        'drawer_event_status' => 'fail',
        'amount_collected_cents' => 0,
    ]);
    $r->assertStatus(200);
    $this->assertEquals('fail', $order->fresh()->cash_drawer_event_status);
}

/** @test */
public function is_idempotent_on_already_acknowledged(): void
{
    $order = $this->createKioskCashOrder(50.00);
    $this->postJson("/api/frontend/order/{$order->id}/cash-acknowledge", [
        'drawer_event_status' => 'opened',
        'amount_collected_cents' => 5000,
    ]);
    $firstAck = $order->fresh()->cash_acknowledged_at;

    sleep(1);
    $r2 = $this->postJson("/api/frontend/order/{$order->id}/cash-acknowledge", [
        'drawer_event_status' => 'opened',
        'amount_collected_cents' => 5000,
    ]);
    $r2->assertStatus(200);
    $this->assertEquals($firstAck, $order->fresh()->cash_acknowledged_at);
}

/** @test */
public function rejects_unauthorized_user(): void
{
    $order = $this->createKioskCashOrder(50.00);
    $randomUser = User::factory()->create();
    $r = $this->actingAs($randomUser, 'sanctum')
        ->postJson("/api/frontend/order/{$order->id}/cash-acknowledge", [
            'drawer_event_status' => 'opened',
            'amount_collected_cents' => 5000,
        ]);
    $r->assertStatus(403);
}

/** @test */
public function logs_warning_on_amount_mismatch_but_does_not_reject(): void
{
    $order = $this->createKioskCashOrder(50.00);
    \Illuminate\Support\Facades\Log::shouldReceive('warning')->once();
    $r = $this->postJson("/api/frontend/order/{$order->id}/cash-acknowledge", [
        'drawer_event_status' => 'opened',
        'amount_collected_cents' => 1000,  // Big mismatch
    ]);
    $r->assertStatus(200);  // Still OK — observability only
}
```

`tests/Feature/Cash/ZReportCashUnacknowledgedTest.php` :

```php
/** @test */
public function z_report_includes_cash_unacknowledged_count(): void
{
    // Create 3 kiosk cash orders : 2 acked, 1 not
    // Close Z, assert cash_unacknowledged_count = 1
}
```

## 4. ACCEPTANCE CRITERIA

| AC | Critère |
|---|---|
| AC1 | Endpoint persist cash_acknowledged_at + drawer_event_status |
| AC2 | Idempotent sur déjà ack |
| AC3 | Rejette user non-owner (403) |
| AC4 | Amount mismatch logué warning sans bloquer |
| AC5 | Frontend appelle endpoint après openDrawer |
| AC6 | Stub navigateur push 'opened' (cohérent simu) |
| AC7 | Z report inclut cash_unacknowledged_count |
| AC8 | HMAC signature inclut le champ |

## 5. ANTI-DRIFT

- [ ] Pas d'état intermédiaire `PENDING_CASH` (cf. F-003 décision)
- [ ] payment_status=PAID reste posé à la création (statu quo)
- [ ] Pas de cassure de la state machine V1
- [ ] Pas de modif frozen zones
- [ ] cash-acknowledge non-blocking (frontend continue même si endpoint fail)

## 6. RISK

| Risk | Mit |
|---|---|
| Nouveau champ casse signature HMAC ancienne | Champ nullable, signature accept legacy NULL |
| Frontend bloque sur cash-acknowledge fail | try/catch + console.warn + continuer flow |
| Drawer real failed mais user paie quand même | drawer_event_status='fail' permet identifier ces cas pour audit comptable |

## 7. DEFINITION OF DONE

- [ ] Migration + Endpoint + Frontend hook + Z enrichment
- [ ] 5+ tests backend verts
- [ ] Suite Kiosk + Fiscal vertes
- [ ] Commit : `audit(F-009): kiosk cash backend acknowledge hook`
- [ ] Report + Graphiti
