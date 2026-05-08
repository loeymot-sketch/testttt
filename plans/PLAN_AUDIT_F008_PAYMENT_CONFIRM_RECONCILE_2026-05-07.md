# PLAN_AUDIT_F008 — Payment Confirm Reconciliation Queue
**Severity:** P1 — TPE encaisse mais backend pas synchronisé → orders orphelins PENDING
**Owner agent:** Agent B (Payment integrity)
**Sprint:** S4
**Estimated:** 2 jours-agent
**Frozen-zone override:** NO
**Dépend de :** F-001 (fiscal seq) + F-002 (amount echo) — devraient être verts

---

## 0. STOP CHECKLIST

| # | Q | A |
|---|---|---|
| 1 | Why ? | TPE approuve transaction (cash débité côté banque), frontend retry 3× backoff 700ms, backend down → frontend abandonne → order PENDING orphelin → client paie mais commande pas en cuisine |
| 2 | What ? | (1) localStorage frontend pour orders confirm-failed ; (2) retry au boot du kiosk ; (3) endpoint backend reconcile-pending bulk idempotent ; (4) métrique exhaustion |
| 3 | Where ? | `KioskPaymentComponent.confirmBackendPayment` + `KioskAppComponent.created`, nouveau `app/Http/Controllers/Frontend/PaymentReconcileController.php`, route `/api/frontend/payment/reconcile-pending` |
| 4 | Who ? | Kiosks (offline blip), backend ops (dashboard orphelins), comptable (reconciliation) |
| 5 | How ? | `tests/Feature/Kiosk/PaymentReconcileTest.php` + `tests/js/KioskPaymentReconcile.spec.js` |
| 6 | When rollback ? | Si reconcile crée doublons (catch idempotency échoue) |

---

## 1. THINK

[`KioskPaymentComponent.vue:551-566`](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:551) :

```js
async confirmBackendPayment(orderId, payload) {
  let lastError = null;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      await axios.post(`frontend/order/${orderId}/payment-confirm`, payload);
      return;
    } catch (error) {
      lastError = error;
      if (attempt < 3) {
        await new Promise((resolve) => setTimeout(resolve, attempt * 700));
      }
    }
  }
  console.warn('[KioskPayment] payment-confirm failed after retries:', lastError?.message);
  throw new Error(this.$t('kiosk.pay_screen.payment_sync_failed'));
}
```

Scénario problématique :
1. TPE approuve (cash débité réellement).
2. Frontend retry 3× total ~2.1s.
3. Backend down (déploiement, network blip).
4. Frontend abandon → order reste UNPAID, jamais ACCEPT.
5. Client a payé mais commande pas en cuisine.

Aucun mécanisme backend pour réconcilier ces transactions orphelines :
- Pas de queue.
- Pas de cron.
- Pas d'endpoint d'audit "j'ai des transactions TPE non reconciliées".

## 2. PLAN

### 2.1 Architecture

```
TPE charge OK → frontend confirmBackendPayment retry 3×
  ├─ SUCCESS → flow nominal
  └─ FAIL after 3 retries
      ├─ Save to localStorage 'pending_payment_confirms' = [{order_id, transaction_id, amount_cents, card_type, payment_method, attempted_at}]
      ├─ Show error toast user
      └─ Continue UI (kiosk reset à idle)

KioskAppComponent.created()
  ├─ Read localStorage 'pending_payment_confirms'
  ├─ Si non vide → background retry chaque entry
  │   ├─ POST /api/frontend/payment/reconcile-pending [array]
  │   ├─ SUCCESS → remove de localStorage
  │   └─ FAIL × N (max 30 min depuis attempted_at) → log, alert ops, garder en localStorage
  └─ Périodique (toutes les 60s pendant session)

Backend POST /api/frontend/payment/reconcile-pending
  ├─ Body : [{order_id, transaction_id, amount_cents, card_type, payment_method, attempted_at}]
  ├─ Pour chaque : appeler logique paymentConfirm (idempotent — si PAID déjà, no-op 200)
  ├─ Tracking : table pending_payment_confirmations (UNIQUE(transaction_id))
  └─ Retour : array des résultats {order_id, status, action_taken}
```

### 2.2 Schema (optionnel mais recommandé)

`database/migrations/2026_05_xx_create_pending_payment_confirmations.php`

```sql
CREATE TABLE pending_payment_confirmations (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  branch_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  transaction_id VARCHAR(255) NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  card_type VARCHAR(50) NULL,
  payment_method TINYINT NOT NULL,
  attempted_at TIMESTAMP NOT NULL,
  resolved_at TIMESTAMP NULL,
  status ENUM('pending', 'resolved', 'failed') NOT NULL DEFAULT 'pending',
  retry_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_transaction_id (transaction_id),
  INDEX idx_status_created (status, created_at),
  INDEX idx_branch_status (branch_id, status)
);
```

### 2.3 Frontend localStorage handling

Limites :
- Max 50 entries (pour éviter explosion).
- Borne 30 min (passé ce délai → entrée stale, alert ops, ne pas retry indéfiniment).
- Pas de PAN ni info bancaire sensible : seulement `transaction_id` (cf. PCI-DSS).

```js
const KEY = 'pending_payment_confirms';
const MAX_AGE_MS = 30 * 60 * 1000; // 30 min

function readPending() {
  try {
    return JSON.parse(localStorage.getItem(KEY) || '[]');
  } catch (_) {
    return [];
  }
}

function writePending(list) {
  localStorage.setItem(KEY, JSON.stringify(list.slice(0, 50)));
}

function appendPending(entry) {
  const list = readPending();
  list.push({ ...entry, attempted_at: new Date().toISOString() });
  writePending(list);
}

function removePending(transactionId) {
  writePending(readPending().filter(e => e.transaction_id !== transactionId));
}

function pendingExpired(entry) {
  return Date.now() - new Date(entry.attempted_at).getTime() > MAX_AGE_MS;
}
```

## 3. BUILD

### 3.1 Migration + Model (3h)

1. `2026_05_xx_create_pending_payment_confirmations.php`.
2. `app/Models/PendingPaymentConfirmation.php` (BranchScope, casts).
3. Tests unit.

### 3.2 Controller + Service (4h)

`app/Http/Controllers/Frontend/PaymentReconcileController.php` :

```php
class PaymentReconcileController extends Controller
{
    public function reconcile(\Illuminate\Http\Request $request, FrontendOrderService $service)
    {
        $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:50'],
            'entries.*.order_id' => ['required', 'integer'],
            'entries.*.transaction_id' => ['required', 'string', 'max:255'],
            'entries.*.amount_cents' => ['required', 'integer', 'min:1'],
            'entries.*.card_type' => ['nullable', 'string', 'max:50'],
            'entries.*.payment_method' => ['required', 'integer'],
        ]);

        $results = [];
        foreach ($request->input('entries') as $entry) {
            $order = FrontendOrder::find($entry['order_id']);
            if (!$order) {
                $results[] = ['order_id' => $entry['order_id'], 'status' => 'order_not_found'];
                continue;
            }
            // Idempotent : reuse paymentConfirm logic
            try {
                if ((int) $order->payment_status === PaymentStatus::PAID) {
                    $results[] = ['order_id' => $order->id, 'status' => 'already_paid'];
                    continue;
                }
                // Apply same checks as F-002 amount echo
                $expectedCents = (int) round($order->total * 100);
                if (abs((int) $entry['amount_cents'] - $expectedCents) > 1) {
                    $results[] = ['order_id' => $order->id, 'status' => 'amount_mismatch'];
                    continue;
                }
                DB::transaction(function () use ($order, $entry) {
                    $locked = FrontendOrder::where('id', $order->id)->lockForUpdate()->first();
                    if ((int) $locked->payment_status === PaymentStatus::PAID) return;
                    $locked->payment_status = PaymentStatus::PAID;
                    $locked->transaction_id = $entry['transaction_id'];
                    $locked->card_type = $entry['card_type'] ?? null;
                    $locked->payment_method = $entry['payment_method'];
                    if ($locked->fiscal_sequence_no === null) {
                        $locked->fiscal_sequence_no = app(FiscalSequenceService::class)->next((int) $locked->branch_id);
                    }
                    $locked->save();
                });
                $service->finalizePaidKioskOrder($order->fresh());
                $results[] = ['order_id' => $order->id, 'status' => 'reconciled'];
            } catch (\Throwable $e) {
                $results[] = ['order_id' => $order->id, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }
        return response(['data' => $results], 200);
    }
}
```

Route :
```php
Route::post('/payment/reconcile-pending', [PaymentReconcileController::class, 'reconcile'])
    ->middleware(['auth:sanctum', 'throttle:5,1']); // 5/min anti-abuse
```

### 3.3 Frontend localStorage + boot retry (4h)

Dans `KioskPaymentComponent.confirmBackendPayment`, après abandonment :

```js
async confirmBackendPayment(orderId, payload) {
  let lastError = null;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      await axios.post(`frontend/order/${orderId}/payment-confirm`, payload);
      return;
    } catch (error) {
      lastError = error;
      if (attempt < 3) await new Promise(r => setTimeout(r, attempt * 700));
    }
  }
  // [AUDIT-F-008] Persist to localStorage for boot-time reconcile
  this._appendPendingReconcile({ order_id: orderId, ...payload });
  // Metric
  try {
    window.axios?.post('frontend/kiosk-event', {
      type: 'payment_confirm_retry_exhausted',
      details: `order_id=${orderId} tx=${payload.transaction_id}`,
    }).catch(() => {});
  } catch (_) {}
  throw new Error(this.$t('kiosk.pay_screen.payment_sync_failed'));
}
```

Dans `KioskAppComponent.created` (ou plus haut dans le boot kiosk) :

```js
async mounted() {
  await this._reconcilePendingPayments();
  // Périodique : retry toutes 60s
  this._reconcileInterval = setInterval(() => this._reconcilePendingPayments(), 60_000);
}
```

```js
async _reconcilePendingPayments() {
  const list = readPending();
  if (list.length === 0) return;
  const fresh = list.filter(e => !pendingExpired(e));
  const expired = list.filter(e => pendingExpired(e));

  if (expired.length > 0) {
    // Alert ops
    try {
      window.axios?.post('frontend/kiosk-event', {
        type: 'payment_reconcile_expired',
        details: JSON.stringify(expired.map(e => e.transaction_id)),
      });
    } catch (_) {}
  }

  if (fresh.length === 0) {
    writePending([]);
    return;
  }

  try {
    const r = await axios.post('frontend/payment/reconcile-pending', { entries: fresh });
    const results = r.data.data || [];
    const reconciledTxs = results
      .filter(x => x.status === 'reconciled' || x.status === 'already_paid')
      .map(x => x.transaction_id);
    writePending(list.filter(e =>
      !reconciledTxs.includes(e.transaction_id)
      && !pendingExpired(e)
    ));
  } catch (_) {
    // Stay in localStorage, retry next tick
  }
}
```

### 3.4 Tests (3h)

`tests/Feature/Kiosk/PaymentReconcileTest.php` :

```php
/** @test */
public function reconcile_endpoint_marks_unpaid_order_as_paid(): void
{
    $order = FrontendOrder::factory()->create([
        'payment_status' => PaymentStatus::UNPAID,
        'total' => 50.00,
    ]);

    $r = $this->actingAs($this->kioskUser, 'sanctum')
        ->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => $order->id,
                'transaction_id' => 'TX-RECONCILE-1',
                'amount_cents' => 5000,
                'card_type' => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]]
        ]);

    $r->assertStatus(200);
    $r->assertJson(['data' => [['status' => 'reconciled']]]);
    $this->assertEquals(PaymentStatus::PAID, $order->fresh()->payment_status);
}

/** @test */
public function reconcile_is_idempotent_on_already_paid_order(): void
{
    $order = FrontendOrder::factory()->create(['payment_status' => PaymentStatus::PAID]);

    $r = $this->actingAs($this->kioskUser, 'sanctum')
        ->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [['order_id' => $order->id, 'transaction_id' => 'TX-X', 'amount_cents' => 5000, 'payment_method' => 4]]
        ]);

    $r->assertStatus(200);
    $r->assertJson(['data' => [['status' => 'already_paid']]]);
}

/** @test */
public function reconcile_rejects_amount_mismatch(): void
{
    $order = FrontendOrder::factory()->create(['payment_status' => PaymentStatus::UNPAID, 'total' => 50.00]);
    $r = $this->actingAs($this->kioskUser, 'sanctum')
        ->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [['order_id' => $order->id, 'transaction_id' => 'TX-WRONG', 'amount_cents' => 100, 'payment_method' => 4]]
        ]);
    $r->assertStatus(200);
    $r->assertJson(['data' => [['status' => 'amount_mismatch']]]);
}

/** @test */
public function reconcile_processes_batch_partially(): void
{
    $okOrder = FrontendOrder::factory()->create(['payment_status' => PaymentStatus::UNPAID, 'total' => 30]);
    $missingId = 999999;

    $r = $this->actingAs($this->kioskUser, 'sanctum')
        ->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [
                ['order_id' => $okOrder->id, 'transaction_id' => 'TX-A', 'amount_cents' => 3000, 'payment_method' => 4],
                ['order_id' => $missingId, 'transaction_id' => 'TX-B', 'amount_cents' => 5000, 'payment_method' => 4],
            ]
        ]);

    $r->assertStatus(200);
    $results = $r->json('data');
    $this->assertEquals('reconciled', $results[0]['status']);
    $this->assertEquals('order_not_found', $results[1]['status']);
}
```

`tests/js/KioskPaymentReconcile.spec.js` :

```js
describe('Kiosk Payment Reconcile (F-008)', () => {
  beforeEach(() => localStorage.clear());

  it('persists to localStorage on retry exhaustion', async () => {
    window.axios = { post: jest.fn().mockRejectedValue(new Error('network')) };
    const wrapper = mount(KioskPaymentComponent);
    await expect(wrapper.vm.confirmBackendPayment(42, {
      transaction_id: 'TX-FAIL',
      amount_cents: 5000,
      card_type: 'VISA',
      payment_method: 4,
    })).rejects.toThrow();
    const persisted = JSON.parse(localStorage.getItem('pending_payment_confirms') || '[]');
    expect(persisted).toHaveLength(1);
    expect(persisted[0].transaction_id).toBe('TX-FAIL');
  });

  it('boot retry empties localStorage on success', async () => {
    localStorage.setItem('pending_payment_confirms', JSON.stringify([
      { order_id: 1, transaction_id: 'TX-OK', amount_cents: 5000, payment_method: 4, attempted_at: new Date().toISOString() }
    ]));
    window.axios = { post: jest.fn().mockResolvedValue({ data: { data: [{ order_id: 1, status: 'reconciled', transaction_id: 'TX-OK' }] } }) };
    const wrapper = mount(KioskAppComponent);
    await wrapper.vm._reconcilePendingPayments();
    expect(localStorage.getItem('pending_payment_confirms')).toBe('[]');
  });

  it('skips expired entries (>30min)', async () => {
    const old = new Date(Date.now() - 31 * 60 * 1000).toISOString();
    localStorage.setItem('pending_payment_confirms', JSON.stringify([
      { order_id: 1, transaction_id: 'TX-OLD', amount_cents: 5000, payment_method: 4, attempted_at: old }
    ]));
    window.axios = { post: jest.fn() };
    const wrapper = mount(KioskAppComponent);
    await wrapper.vm._reconcilePendingPayments();
    expect(window.axios.post).toHaveBeenCalledWith('frontend/kiosk-event', expect.objectContaining({ type: 'payment_reconcile_expired' }));
  });
});
```

## 4. ACCEPTANCE CRITERIA

| AC | Critère |
|---|---|
| AC1 | Reconcile endpoint marks UNPAID → PAID + alloue fiscal_sequence_no |
| AC2 | Idempotent on already PAID |
| AC3 | Rejette amount mismatch (cohérent F-002) |
| AC4 | Batch partial : process OK, marque erreurs |
| AC5 | localStorage persiste sur retry exhaustion |
| AC6 | Boot retry empties localStorage on success |
| AC7 | Skip expired entries (>30 min) + alert ops |
| AC8 | Pas de PAN ni info sensible dans localStorage |
| AC9 | Throttle 5/min anti-abuse |

## 5. ANTI-DRIFT

- [ ] Pas de modif frozen zones
- [ ] Pas de PAN dans localStorage
- [ ] Pas de retry indéfini (borne 30 min)
- [ ] Idempotent par transaction_id (UNIQUE)
- [ ] Logique amount echo (F-002) dupliquée dans reconcile = OK ; ne pas factoriser au prix de coupling

## 6. RISK

| Risk | Mit |
|---|---|
| localStorage corrompu (browser bug) | try/catch JSON.parse, fallback [] |
| Reconcile crée doublon si transaction_id rejoué | UNIQUE constraint sur pending_payment_confirmations.transaction_id |
| Boot retry boucle infinie | Borne 30 min + max 50 entries |
| PAN leak via localStorage | Validate fields persisted (whitelist) |

## 7. DEFINITION OF DONE

- [ ] 4 tests backend verts
- [ ] 3 tests Vue verts
- [ ] Migration + Model + Controller livrés
- [ ] Frontend reconcile loop livré
- [ ] Suite Kiosk verte
- [ ] Commit : `audit(F-008): payment confirm reconciliation queue + boot retry`
- [ ] Report + Graphiti
