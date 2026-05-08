# PLAN_AUDIT_F001 — Kiosk Fiscal Sequence Allocation
**Severity:** P0 — Compliance NF525 violée pour le canal kiosk
**Owner agent:** Agent A (Fiscal/NF525)
**Sprint:** S1
**Estimated:** 1 jour-agent (4-6h code + tests + verification)
**Frozen-zone override:** YES sur `FrontendOrderService` (zone scope-actif sensible, doit suivre pattern POS strict)

---

## 0. STOP CHECKLIST 6 QUESTIONS

| # | Question | Réponse |
|---|---|---|
| 1 | **Why** ? | NF525 exige numéro fiscal monotonique gap-free par branche pour CHAQUE encaissement. Kiosk = 0 occurrence de `fiscal_sequence_no` dans son service → exclus du Z report → CA caché → sanction administrative possible |
| 2 | **What** minimal ? | Ajouter 2 call sites de `app(FiscalSequenceService::class)->next($branchId)` dans `FrontendOrderService` aux moments `payment_status` devient `PAID` |
| 3 | **Where** ? | (a) `FrontendOrderService::myOrderStore` ligne ~510-525 (path cash kiosk auto-PAID) — (b) `FrontendOrderService::finalizePaidKioskOrder` lignes 792-804 (path card/ticket post-TPE) |
| 4 | **Who impacted** ? | `ZReportService::aggregate` (lit `whereNotNull(fiscal_sequence_no)`), `FiscalSequenceService::next` (allocator atomique), KioskMachine flow, AuditLog chain |
| 5 | **How valider** ? | `tests/Feature/Fiscal/KioskFiscalSequenceTest.php` (nouveau) + suite Fiscal complète + ZReportCloseTest no regression |
| 6 | **When rollback** ? | Si `ZReportCloseTest`, `AuditLogHashChainTest`, ou `FiscalSequenceTest` régresse → revert immédiat, escalade orchestrateur |

---

## 1. THINK — Contexte enrichi

### 1.1 Évidence brute (vérifiée par grep direct)

```bash
$ grep -c "fiscal_sequence" app/Services/FrontendOrderService.php
0

$ grep -c "fiscal_sequence" app/Services/OrderService.php
5

$ grep -n "fiscal_sequence_no" app/Services/Fiscal/ZReportService.php
209:            ->whereNotNull('fiscal_sequence_no')
```

### 1.2 Architecture du flux fiscal actuel

```
POS (OrderService::posOrderStore)
  ├─ Crée Order avec status=ACCEPT, payment_status=PAID
  ├─ Calcul prix SSOT
  ├─ Allocate fiscal_sequence_no (line 862)  ✅
  └─ Save → Z report inclut

Kiosk Cash (FrontendOrderService::myOrderStore)
  ├─ Crée FrontendOrder avec status=PENDING, payment_status=PAID (ligne 200)
  ├─ Calcul prix SSOT
  ├─ ❌ JAMAIS d'allocation fiscal_sequence_no
  ├─ Auto-promote à ACCEPT (ligne 557-561) si cash kiosk immédiat
  └─ Save → Z report L'EXCLUT (whereNotNull filter)

Kiosk Card/Ticket (FrontendOrderService::myOrderStore + paymentConfirm)
  ├─ Crée FrontendOrder avec status=PENDING, payment_status=UNPAID
  ├─ Frontend appelle TPE → transaction_id retourné
  ├─ POST /payment-confirm → set payment_status=PAID
  ├─ finalizePaidKioskOrder transition PENDING→ACCEPT
  ├─ ❌ JAMAIS d'allocation fiscal_sequence_no à aucun moment
  └─ Save → Z report L'EXCLUT
```

### 1.3 Conséquences métier

1. **Z report incomplet** : si une journée a 50 commandes POS + 30 commandes kiosk, le Z affiche le total des 50 POS uniquement. Les 30 kiosk sont fiscal-invisibles.
2. **Délit fiscal** : NF525 article 286-I-3°bis CGI exige conservation et numérotation de tous les encaissements. Lacune = redressement + amende.
3. **Audit chain trous** : `audit_logs.fiscal_sequence_no` jamais référencé pour kiosk → preuves d'intégrité incomplètes.
4. **Reconciliation impossible** : impossible de prouver à l'inspection que le total déclaré inclut bien tous les paiements kiosk.

### 1.4 Pourquoi cela peut avoir été oublié

POS `posOrderStore` crée + paie en une seule transaction → un seul moment d'allocation, intuitif.
Kiosk a 2 paths : cash auto-PAID (myOrderStore) OU card-via-TPE (paymentConfirm). Le fix POS d'origine n'a pas couvert le 2e path.

---

## 2. PLAN — Stratégie

### 2.1 Décision d'architecture

**Allouer `fiscal_sequence_no` au moment où `payment_status` devient `PAID`**, pas à la création.

Raisons :
- Cohérent avec POS (où création = payment).
- Idempotent : si `paymentConfirm` est rejouée, l'order a déjà la séquence — on ne re-alloue pas.
- Aligne kiosk cash et kiosk card sur le même invariant : "PAID ⟹ fiscal_sequence_no IS NOT NULL".
- Ne nécessite aucune modification de `FiscalSequenceService` (frozen).

### 2.2 Flow modifié

```
Kiosk Cash :
  myOrderStore commit
    ├─ Si payment_status = PAID (cash immédiat)
    │   └─ ALLOCATE fiscal_sequence_no  ⬅ NOUVEAU
    └─ Save

Kiosk Card/Ticket :
  paymentConfirm
    ├─ DB::transaction lockForUpdate
    ├─ Si payment_status était UNPAID → set PAID
    │   └─ ALLOCATE fiscal_sequence_no  ⬅ NOUVEAU
    └─ Save
  finalizePaidKioskOrder (already exists)
```

### 2.3 Idempotency

Les 2 chemins doivent être idempotents :
- `myOrderStore` : la `idempotency_key` UNIQUE constraint protège déjà → si retry, on retourne l'existing avec sa séquence déjà allouée.
- `paymentConfirm` : `lockForUpdate` + check `payment_status === PAID` (ligne 106-109) court-circuite déjà les retries → si déjà PAID, on ne ré-alloue pas.

### 2.4 Atomicité

`FiscalSequenceService::next($branchId)` exécute son propre `Cache::lock` + `lockForUpdate` + transaction. Appelé depuis notre `DB::transaction` parente, il fait un savepoint MySQL. Si notre transaction parente rollback, le savepoint est annulé → le sequence n'est pas "consommé" (le prochain call verra le même MAX). Comportement déjà validé pour POS ; on réutilise tel quel.

---

## 3. BUILD — Sous-tâches numérotées

### Sub-task 3.1 — Vérification drift (10 min)

**Objectif** : confirmer que les line numbers cités matchent encore le code actuel.

```bash
# Vérifier ligne 200 de FrontendOrderService (payment_status auto-PAID)
sed -n '195,205p' app/Services/FrontendOrderService.php

# Doit contenir : 'payment_status' => $isImmediatePaidKioskCash ? PaymentStatus::PAID : PaymentStatus::UNPAID

# Vérifier lignes 510-525 (où ajouter l'allocation cash)
sed -n '505,530p' app/Services/FrontendOrderService.php

# Doit contenir : $this->frontendOrder->subtotal = ... etc.

# Vérifier lignes 792-804 (finalizePaidKioskOrder transaction)
sed -n '790,810p' app/Services/FrontendOrderService.php

# Doit contenir : $locked->status = OrderStatus::ACCEPT; $locked->save();
```

Si les contenus ne matchent pas → STOP, remonter à l'orchestrateur (drift détecté).

### Sub-task 3.2 — Test rouge (45 min)

**Objectif** : écrire `tests/Feature/Fiscal/KioskFiscalSequenceTest.php` qui FAIL sur le code actuel.

```bash
# Créer le fichier
touch tests/Feature/Fiscal/KioskFiscalSequenceTest.php
```

Contenu minimal (squelette à étoffer) :

```php
<?php

namespace Tests\Feature\Fiscal;

use Tests\TestCase;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Branch;
use App\Models\User;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KioskFiscalSequenceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function kiosk_cash_order_receives_fiscal_sequence_at_creation(): void
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        $kioskMachine = KioskMachine::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($kioskUser, 'sanctum');

        $payload = $this->validKioskOrderPayload([
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'order_type' => OrderType::KIOSK,
        ]);

        $response = $this->postJson('/api/frontend/order', $payload);

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $order = FrontendOrder::find($orderId);
        $this->assertNotNull(
            $order->fiscal_sequence_no,
            'Kiosk cash order MUST have fiscal_sequence_no allocated at creation (NF525).'
        );
        $this->assertEquals(PaymentStatus::PAID, $order->payment_status);
    }

    /** @test */
    public function kiosk_card_order_receives_fiscal_sequence_at_payment_confirm(): void
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($kioskUser, 'sanctum');

        $createPayload = $this->validKioskOrderPayload([
            'payment_method' => PaymentGateway::CARD,
            'order_type' => OrderType::KIOSK,
        ]);

        $createResponse = $this->postJson('/api/frontend/order', $createPayload);
        $orderId = $createResponse->json('data.id');

        // Avant payment-confirm : fiscal_sequence_no NULL (UNPAID)
        $orderBefore = FrontendOrder::find($orderId);
        $this->assertNull($orderBefore->fiscal_sequence_no);
        $this->assertEquals(PaymentStatus::UNPAID, $orderBefore->payment_status);

        // Confirm
        $confirmResponse = $this->postJson("/api/frontend/order/{$orderId}/payment-confirm", [
            'transaction_id' => 'TX-TEST-' . uniqid(),
            'card_type' => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents' => (int) round($orderBefore->total * 100), // F-002 dependency
        ]);

        $confirmResponse->assertStatus(200);

        // Après : fiscal_sequence_no allocé
        $orderAfter = FrontendOrder::find($orderId);
        $this->assertNotNull(
            $orderAfter->fiscal_sequence_no,
            'Kiosk card order MUST receive fiscal_sequence_no on payment-confirm (NF525).'
        );
        $this->assertEquals(PaymentStatus::PAID, $orderAfter->payment_status);
    }

    /** @test */
    public function kiosk_orders_fiscal_sequence_is_monotonic_per_branch(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $user->id, 'branch_id' => $branch->id]);

        $this->actingAs($user, 'sanctum');

        $sequences = [];
        for ($i = 0; $i < 5; $i++) {
            $r = $this->postJson('/api/frontend/order', $this->validKioskOrderPayload([
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'order_type' => OrderType::KIOSK,
            ]));
            $sequences[] = FrontendOrder::find($r->json('data.id'))->fiscal_sequence_no;
        }

        // Strictly monotonic
        for ($i = 1; $i < count($sequences); $i++) {
            $this->assertGreaterThan(
                $sequences[$i - 1],
                $sequences[$i],
                "fiscal_sequence_no must be strictly monotonic per branch (NF525)."
            );
        }
    }

    /** @test */
    public function kiosk_payment_confirm_retry_does_not_reallocate_sequence(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $user->id, 'branch_id' => $branch->id]);

        $this->actingAs($user, 'sanctum');

        $createR = $this->postJson('/api/frontend/order', $this->validKioskOrderPayload([
            'payment_method' => PaymentGateway::CARD,
        ]));
        $orderId = $createR->json('data.id');

        $payload = [
            'transaction_id' => 'TX-IDEMP-' . uniqid(),
            'card_type' => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents' => (int) round(FrontendOrder::find($orderId)->total * 100),
        ];

        $r1 = $this->postJson("/api/frontend/order/{$orderId}/payment-confirm", $payload);
        $seq1 = FrontendOrder::find($orderId)->fiscal_sequence_no;

        $r2 = $this->postJson("/api/frontend/order/{$orderId}/payment-confirm", $payload);
        $seq2 = FrontendOrder::find($orderId)->fiscal_sequence_no;

        $this->assertEquals($seq1, $seq2, 'Retry must NOT reallocate fiscal_sequence_no.');
    }

    /** @test */
    public function kiosk_paid_orders_appear_in_z_report(): void
    {
        // Crée 3 kiosk orders cash + Z report close → assert orderCount = 3 et total correct
        // (Détail à étoffer en lien avec ZReportCloseTest existant)
        $this->markTestIncomplete('To implement once ZReport factory is wired.');
    }

    private function validKioskOrderPayload(array $overrides = []): array
    {
        // À implémenter selon les fixtures existantes du projet
        return array_merge([
            'items' => json_encode([
                ['item_id' => 1, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]
            ]),
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'is_advance_order' => 0,
            'source' => 1,
            // ... autres champs requis selon OrderRequest
        ], $overrides);
    }
}
```

**Vérification rouge** :

```bash
./vendor/bin/phpunit tests/Feature/Fiscal/KioskFiscalSequenceTest.php
# Attendu : 4 tests, 4 failures (assertNotNull fiscal_sequence_no)
```

Si le test passe au vert (le bug n'existe pas) → **STOP, escalade orchestrateur** (drift).

### Sub-task 3.3 — Implémentation cash path (45 min)

**File:** [`app/Services/FrontendOrderService.php`](app/Services/FrontendOrderService.php)

Localiser la zone après `OrderItem::insert` et avant `$this->frontendOrder->save();` final dans `myOrderStore` (autour de ligne 510-526).

**BEFORE (extrait pertinent ~510-526):**

```php
$this->frontendOrder->order_serial_no = date('dmy') . $this->frontendOrder->id;
$this->frontendOrder->queue_number = $queueNumber;
$this->frontendOrder->total_tax = round($totalTax, 2);
$this->frontendOrder->subtotal = round($realSubtotal, 2);
$this->frontendOrder->discount = $calculatedDiscount;
$this->frontendOrder->total = round(max(0, $realSubtotal + $totalTax + $this->frontendOrder->delivery_charge - $calculatedDiscount), 2);
// ...
if (!$this->frontendOrder->source_surface) {
    // ...
    $this->frontendOrder->source_surface = $isKiosk ? 'kiosk' : 'web';
}
$this->frontendOrder->save();
```

**AFTER (insertion immédiate avant `->save()`) :**

```php
$this->frontendOrder->order_serial_no = date('dmy') . $this->frontendOrder->id;
$this->frontendOrder->queue_number = $queueNumber;
$this->frontendOrder->total_tax = round($totalTax, 2);
$this->frontendOrder->subtotal = round($realSubtotal, 2);
$this->frontendOrder->discount = $calculatedDiscount;
$this->frontendOrder->total = round(max(0, $realSubtotal + $totalTax + $this->frontendOrder->delivery_charge - $calculatedDiscount), 2);
// ...
if (!$this->frontendOrder->source_surface) {
    // ...
    $this->frontendOrder->source_surface = $isKiosk ? 'kiosk' : 'web';
}

// [AUDIT-F-001] NF525: allouer fiscal_sequence_no si l'order naît PAID
// (cash kiosk auto-confirmé). Pour les paths PAID-after-create (card/ticket
// via paymentConfirm), l'allocation se fait dans paymentConfirm, pas ici.
// FiscalSequenceService::next() pose son propre lock + savepoint MySQL,
// donc inclus dans la transaction parente : si rollback, sequence pas
// effectivement consommée (next call verra le même MAX).
if (
    (int) $this->frontendOrder->payment_status === PaymentStatus::PAID
    && $this->frontendOrder->fiscal_sequence_no === null
) {
    $this->frontendOrder->fiscal_sequence_no = app(\App\Services\Fiscal\FiscalSequenceService::class)
        ->next((int) $this->frontendOrder->branch_id);
}

$this->frontendOrder->save();
```

### Sub-task 3.4 — Implémentation card path (60 min)

**File:** [`app/Services/FrontendOrderService.php`](app/Services/FrontendOrderService.php)

Localiser `finalizePaidKioskOrder` lignes 772-828.

**BEFORE (zone DB::transaction lignes 792-804) :**

```php
DB::transaction(function () use ($frontendOrder, &$promoted) {
    $locked = FrontendOrder::where('id', $frontendOrder->id)
        ->lockForUpdate()
        ->first();

    if ((int) $locked->status >= OrderStatus::ACCEPT) {
        return;
    }

    $locked->status = OrderStatus::ACCEPT;
    $locked->save();
    $promoted = true;
});
```

**Décision** : `finalizePaidKioskOrder` est appelé APRÈS la transaction de `paymentConfirm`. Le bon endroit pour allouer le sequence est en réalité **dans `paymentConfirm` lui-même**, là où `payment_status` est posé à PAID.

**File:** [`app/Http/Controllers/Frontend/OrderController.php`](app/Http/Controllers/Frontend/OrderController.php)

**BEFORE (paymentConfirm lignes 101-118) :**

```php
DB::transaction(function () use ($frontendOrder, $request, &$alreadyPaid) {
    $locked = FrontendOrder::where('id', $frontendOrder->id)
        ->lockForUpdate()
        ->first();

    if ((int) $locked->payment_status === PaymentStatus::PAID) {
        $alreadyPaid = true;
        return;
    }

    $locked->payment_status = PaymentStatus::PAID;
    $locked->payment_method = $request->payment_method ?? $locked->payment_method;
    $locked->transaction_id = $request->transaction_id;
    $locked->card_type = $request->card_type;
    $locked->save();

    $frontendOrder->refresh();
});
```

**AFTER :**

```php
DB::transaction(function () use ($frontendOrder, $request, &$alreadyPaid) {
    $locked = FrontendOrder::where('id', $frontendOrder->id)
        ->lockForUpdate()
        ->first();

    if ((int) $locked->payment_status === PaymentStatus::PAID) {
        $alreadyPaid = true;
        return;
    }

    $locked->payment_status = PaymentStatus::PAID;
    $locked->payment_method = $request->payment_method ?? $locked->payment_method;
    $locked->transaction_id = $request->transaction_id;
    $locked->card_type = $request->card_type;

    // [AUDIT-F-001] NF525: allocate fiscal_sequence_no at the moment payment
    // becomes PAID. Idempotent — only if not already allocated (retry-safe).
    // FiscalSequenceService::next() opens its own Cache::lock + lockForUpdate,
    // executing as a SAVEPOINT inside this parent transaction.
    if ($locked->fiscal_sequence_no === null) {
        $locked->fiscal_sequence_no = app(\App\Services\Fiscal\FiscalSequenceService::class)
            ->next((int) $locked->branch_id);
    }

    $locked->save();

    $frontendOrder->refresh();
});
```

### Sub-task 3.5 — Run test (5 min)

```bash
./vendor/bin/phpunit tests/Feature/Fiscal/KioskFiscalSequenceTest.php --testdox
```

Attendu : 4 tests passent, 1 incomplete (Z report — to be wired sub-task 3.6).

### Sub-task 3.6 — Test Z report inclusion (30 min)

Compléter le test `kiosk_paid_orders_appear_in_z_report` :

```php
/** @test */
public function kiosk_paid_orders_appear_in_z_report(): void
{
    $branch = Branch::factory()->create();
    $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
    KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

    // Admin pour Z operations
    $admin = User::factory()->admin()->create();

    $this->actingAs($kioskUser, 'sanctum');

    // 3 kiosk cash orders
    $kioskTotals = [];
    for ($i = 0; $i < 3; $i++) {
        $r = $this->postJson('/api/frontend/order', $this->validKioskOrderPayload([
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
        ]));
        $kioskTotals[] = (float) FrontendOrder::find($r->json('data.id'))->total;
    }

    $this->actingAs($admin, 'sanctum');
    $this->postJson('/api/admin/fiscal/z-report/open', ['branch_id' => $branch->id]);
    $closeResponse = $this->postJson('/api/admin/fiscal/z-report/close', ['branch_id' => $branch->id]);

    $closeResponse->assertStatus(200);
    $totalSales = (float) $closeResponse->json('data.total_sales');
    $orderCount = (int) $closeResponse->json('data.order_count');

    $this->assertEquals(3, $orderCount, 'Z report MUST count all 3 kiosk paid orders.');
    $this->assertEqualsWithDelta(
        array_sum($kioskTotals),
        $totalSales,
        0.01,
        'Z report total_sales MUST match sum of kiosk paid order totals.'
    );
}
```

### Sub-task 3.7 — Migration de rattrapage (GATED — ne pas merger sans gate)

**File:** `database/migrations/2026_05_xx_backfill_kiosk_fiscal_sequence.php`

**OBJECTIF** : assigner un `fiscal_sequence_no` aux commandes kiosk historiques `payment_status=PAID AND fiscal_sequence_no IS NULL`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [AUDIT-F-001] Backfill fiscal_sequence_no for legacy kiosk orders.
 *
 * GATED OWNER ONLY — touche les NF525 historiques. Ne pas exécuter sans :
 * 1. Backup full DB (mysqldump --single-transaction).
 * 2. Validation owner sur le périmètre (branches + dates).
 * 3. Z reports antérieurs déjà clos NE PEUVENT PAS être modifiés (immutable).
 *    → Cette migration cible uniquement les orders PAID NON encore inclus dans un Z.
 *    → Si certains sont déjà dans un Z clos (impossible normalement, le whereNotNull
 *       filtre les exclut), STOP et escalade.
 */
return new class extends Migration {
    public function up(): void
    {
        $branches = DB::table('orders')
            ->whereNull('fiscal_sequence_no')
            ->where('payment_status', '!=', 0) // != UNPAID
            ->whereIn('order_type', [25, 10]) // KIOSK, TAKEAWAY (kiosk)
            ->select('branch_id')
            ->distinct()
            ->pluck('branch_id');

        foreach ($branches as $branchId) {
            DB::transaction(function () use ($branchId) {
                $maxSeq = (int) (DB::table('orders')
                    ->where('branch_id', $branchId)
                    ->whereNotNull('fiscal_sequence_no')
                    ->max('fiscal_sequence_no') ?? 0);

                $orphans = DB::table('orders')
                    ->where('branch_id', $branchId)
                    ->whereNull('fiscal_sequence_no')
                    ->where('payment_status', '!=', 0)
                    ->whereIn('order_type', [25, 10])
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');

                foreach ($orphans as $idx => $orderId) {
                    DB::table('orders')
                        ->where('id', $orderId)
                        ->update(['fiscal_sequence_no' => $maxSeq + $idx + 1]);
                }

                Log::info("[AUDIT-F-001] Backfilled {$orphans->count()} kiosk orders for branch {$branchId} (start seq " . ($maxSeq + 1) . ").");
            });
        }
    }

    public function down(): void
    {
        // Pas de rollback automatique — gated owner only.
        throw new \LogicException('AUDIT-F-001 backfill is irreversible by policy.');
    }
};
```

**Note** : ce fichier est créé MAIS **NON exécuté** par l'exécuteur. Marquer dans le rapport "Migration F-001 prête, en attente gate owner".

---

## 4. TEST PLAN — Détaillé

### 4.1 Tests à écrire

| File | Cases | Type |
|---|---|---|
| `tests/Feature/Fiscal/KioskFiscalSequenceTest.php` | 5 cases (cash creation, card confirm, monotonic per branch, retry no realloc, Z report inclusion) | Feature, RefreshDatabase |

### 4.2 Suites à runner (no regression)

```bash
# Test direct du finding
./vendor/bin/phpunit tests/Feature/Fiscal/KioskFiscalSequenceTest.php --testdox

# Suite Fiscal complète
./vendor/bin/phpunit tests/Feature/Fiscal/ --testdox

# Suites adjacentes critiques
./vendor/bin/phpunit tests/Feature/Fiscal/ZReportCloseTest.php
./vendor/bin/phpunit tests/Feature/Fiscal/ZReportAggregateFilterTest.php
./vendor/bin/phpunit tests/Feature/Fiscal/AuditLogHashChainTest.php
./vendor/bin/phpunit tests/Feature/Fiscal/FiscalSequenceTest.php
./vendor/bin/phpunit tests/Feature/Fiscal/PosOrderBL1WireInTest.php

# Suite Kiosk complète (no regression sur flow existant)
./vendor/bin/phpunit tests/Feature/KioskFrontendComprehensiveTest.php
./vendor/bin/phpunit tests/Feature/KioskAuthTest.php
./vendor/bin/phpunit tests/Feature/KioskScopeIsolationTest.php

# POS suite (pour s'assurer que le pattern POS reste intact)
./vendor/bin/phpunit tests/Feature/Pos/

# Suite Vue (frontend, no regression UI)
npm run test
```

### 4.3 Critères de réussite

- [ ] 5 nouveaux tests verts dans `KioskFiscalSequenceTest`.
- [ ] 0 régression sur `Feature/Fiscal/`.
- [ ] 0 régression sur `Feature/Pos/`.
- [ ] 0 régression sur `KioskFrontendComprehensiveTest`.
- [ ] `npm run test` vert.
- [ ] `npm run lint` vert.
- [ ] `composer dump-autoload` propre.

---

## 5. EDGE CASES — À tester explicitement

| Cas | Comportement attendu |
|---|---|
| Kiosk cash + idempotency-key retry | 2e call retourne le même order avec MÊME fiscal_sequence_no |
| Kiosk card payment-confirm retry | 2e call sur PAID retourne 200 sans réallouer la séquence |
| Kiosk card payment-confirm avec amount_cents wrong (F-002) | rejet 422, fiscal_sequence_no NON alloué (cohérent : pas PAID) |
| 50 kiosk orders concurrent même branche | 50 séquences distincts strictement croissants |
| 50 kiosk orders concurrent branches différentes | Chaque branche a sa propre séquence monotonique, indépendantes |
| `FiscalSequenceService::next` lock timeout | Exception → transaction parente rollback → order non créé → état cohérent |
| Order créé sans paiement (pure online order non-kiosk) | Pas d'allocation (logique reste UNPAID, fiscal_sequence_no NULL) |
| `pricing.use_ssot_service = false` (legacy fallback) | Allocation se fait quand même (le check `payment_status === PAID` est indépendant du flag) |

---

## 6. ROLLBACK PLAN

Si la suite Fiscal régresse après merge :

```bash
# Identifier le commit
git log --grep "audit(F-001)" --oneline

# Revert
git revert <commit-hash>

# Run tests pour confirmer rétablissement
./vendor/bin/phpunit tests/Feature/Fiscal/

# Push
git push origin main
```

**La migration de backfill est IRRÉVERSIBLE** (down() throws). Si appliquée par erreur, restore depuis backup. C'est pourquoi elle est gated.

---

## 7. DEFINITION OF DONE

- [ ] Test rouge écrit AVANT le fix
- [ ] 5 cases du test verts après fix
- [ ] Suite Fiscal complète verte
- [ ] Suite Kiosk complète verte
- [ ] Suite POS complète verte
- [ ] `npm run test` vert
- [ ] Migration backfill créée mais NON exécutée (marquée "gated")
- [ ] PR ouverte avec template
- [ ] Rapport `reports/execution/audit_2026-05-07/REPORT_F001_kiosk_fiscal.md` produit
- [ ] Graphiti episode `F-001 closed` poussé
- [ ] Master plan checkbox cochée
- [ ] Commit message : `audit(F-001): allocate fiscal_sequence_no on kiosk PAID transition (NF525)`

---

## 8. ACCEPTANCE CRITERIA

| # | Critère | Vérification |
|---|---|---|
| AC1 | Kiosk cash order a `fiscal_sequence_no` non null après création | Test `kiosk_cash_order_receives_fiscal_sequence_at_creation` |
| AC2 | Kiosk card order a `fiscal_sequence_no` non null après payment-confirm | Test `kiosk_card_order_receives_fiscal_sequence_at_payment_confirm` |
| AC3 | Séquence strictement monotonique par branche | Test `kiosk_orders_fiscal_sequence_is_monotonic_per_branch` |
| AC4 | Retry payment-confirm idempotent | Test `kiosk_payment_confirm_retry_does_not_reallocate_sequence` |
| AC5 | Z report inclut les orders kiosk paid | Test `kiosk_paid_orders_appear_in_z_report` |
| AC6 | POS path inchangé (no regression) | Suite Pos/ verte |
| AC7 | Frozen zones intactes | grep verifying no diff in pos-wizard.js, kiosk wizard components |
| AC8 | Pricing SSOT non bypassed | OrderItem rows ont les bonnes valeurs `total_price`, `tax_amount` |

---

## 9. ANTI-DRIFT CHECKLIST

- [ ] Aucune modification de `FiscalSequenceService` (frozen)
- [ ] Aucune modification de `ZReportService` (le filtre `whereNotNull` reste intact)
- [ ] Aucune modification de `OrderStateMachine` (frozen)
- [ ] Aucun bypass de `BranchScope`
- [ ] Aucune modification de migration backfill exécutée sans gate
- [ ] Aucun touche aux composants kiosk wizard frozen (KioskWizardComponent, KioskCartComponent, etc.)
- [ ] Aucune touche à `pos-wizard.js` / `pos-wizard.css`

---

## 10. RISK REGISTER

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Migration backfill exécutée par erreur | Low | High (NF525 sealed history modified) | Migration isolée, marquée gated, owner-only |
| Sequence collision entre POS et Kiosk | Very low | Critical | `FiscalSequenceService::next` lock partagé par branche → impossible |
| Test flaky sur monotonicité concurrent | Medium | Medium | Tests utilisent RefreshDatabase + factories isolées, pas de timing |
| Régression Z aggregate (filter changé par accident) | Low | Critical | `whereNotNull` du ZReportService NON touché ; tests Z runnés explicitement |
| Idempotency hole sur paymentConfirm | Low | High (double allocation) | Check `=== null` AVANT allocation ; test retry ajouté |

---

## 11. REPORTING — Format final

À pusher dans `reports/execution/audit_2026-05-07/REPORT_F001_kiosk_fiscal.md` (template §0.4 du master plan).

---

## 12. GRAPHITI REFLECTION

Push après merge :

```json
{
  "name": "F-001 closed: kiosk fiscal_sequence_no NF525 compliance",
  "group_id": "foodking",
  "source": "json",
  "episode_body": {
    "finding_id": "F-001",
    "severity": "P0",
    "status": "closed",
    "commit_hash": "<filled at ship>",
    "tests_added": 5,
    "files_modified": [
      "app/Services/FrontendOrderService.php",
      "app/Http/Controllers/Frontend/OrderController.php",
      "tests/Feature/Fiscal/KioskFiscalSequenceTest.php",
      "database/migrations/2026_05_xx_backfill_kiosk_fiscal_sequence.php (gated)"
    ],
    "invariant_enforced": "kiosk PAID orders ⟹ fiscal_sequence_no IS NOT NULL ⟹ included in Z report",
    "blocks_resolved": ["Z report kiosk completeness"],
    "audit_id": "ultra_review_2026-05-07"
  }
}
```

---

## 13. DISCOVERED (à compléter par l'exécuteur si applicable)

> Tout bug adjacent découvert pendant le travail F-001 mais HORS scope F-001 va ici.
> L'exécuteur ne corrige PAS ces bugs. Il les note et les remonte.

```
- [ ] Aucun découvert pour l'instant
```
