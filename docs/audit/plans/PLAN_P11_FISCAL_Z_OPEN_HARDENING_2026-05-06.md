# PLAN_P11_FISCAL_Z_OPEN_HARDENING — F-VERIFY-08-01 + F-VERIFY-08-02

**Date** : 2026-05-06
**Train** : Caisse V1 — Train A V1 release prep
**Findings** : F-VERIFY-08-01 (P0, OPEN) + F-VERIFY-08-02 (P0, OPEN)
**Status** : PLAN ONLY — pas de commit, pas d'implémentation directe

---

## 0. CRITICAL — Calibration vs état réel du code (lire avant tout)

Le brief décrit la cible comme si `Z.open()` ne validait rien et que les sealed-Z guards étaient absents. **L'audit du code montre une réalité plus nuancée**.

### 0.1 Ce qui existe déjà (NE PAS recoder)

| Élément | Fichier | Ligne(s) | Status |
|---|---|---|---|
| `ZReportService::verifyChain($branchId)` (recompute HMAC, prev_hash, sequence_no) | `app/Services/Fiscal/ZReportService.php` | 363-472 | ✅ EXISTS |
| Appel `verifyChain()` AVANT réservation séquence dans `open()` | `app/Services/Fiscal/ZReportService.php` | 66 | ✅ EXISTS |
| Appel `verifyChain()` AVANT signature dans `close()` | `app/Services/Fiscal/ZReportService.php` | 132 | ✅ EXISTS |
| Strict mode throws `RuntimeException` 'NF525 Z-chain verification failed' | `app/Services/Fiscal/ZReportService.php` | 461-468 | ✅ EXISTS |
| Test `test_open_and_close_enforce_pre_checks_when_strict_mode_is_enabled` | `tests/Feature/Fiscal/ZOpenChainVerifiedTest.php` | 172-221 | ✅ PASS |
| Sealed-Z guard pattern (predicate `opened_at < order.created_at AND closed_at >= order.created_at`) | `app/Services/OrderService.php::destroy()` | 1820-1833 | ✅ EXISTS |
| Config `fiscal.verify_chain_strict` (env `FISCAL_VERIFY_CHAIN_STRICT`) | `config/fiscal.php` | 55 | ✅ EXISTS |
| `AuditLogService::verifyChain($branchId)` (re-walk full chain) | `app/Services/Fiscal/AuditLogService.php` | 199-231 | ✅ EXISTS |
| `RefundPostZTest::test_returned_order_after_previous_z_is_counted_as_negative_adjustment` | `tests/Feature/Fiscal/RefundPostZTest.php` | 14-54 | ✅ PASS |

### 0.2 Ce qui MANQUE réellement (delta à implémenter)

1. **`Z.open()` ne vérifie pas la chaîne `audit_logs`** (seulement Z chain). Vrai gap résiduel F-VERIFY-08-01.
2. **`OrderService::changeStatus → RETURNED` et `changePaymentStatus → REFUNDED` n'ont AUCUN guard sealed-Z** (1546-1611 et 1696-1711). Pattern existe pour `destroy()` mais non propagé. Gap F-VERIFY-08-02.
3. **Pas de typage d'exception** — `RuntimeException` générique masque la nature fiscale.
4. **Pas d'état transitoire `STATUS_CLOSING`** — race possible entre `close()` (lock 4s) et `open()` simultané.
5. **Pas de chemin "refund miroir"** explicite. Aujourd'hui mute la commande originale → re-comptabilise en négatif via `updated_at`. Fonctionnel mais **fragile** : contredit l'invariant NF525 d'immutabilité.

### 0.3 Conséquences sur le plan

- **Ne PAS recoder `verifyChain()` Z** — l'extraire dans `FiscalChainValidator` (orchestrateur) qui appelle `ZReportService::verifyChain()` + nouvelle méthode `verifyAuditChainTail()`.
- **`FiscalChainCorruptedException extends RuntimeException`** — préserve `instanceof RuntimeException` pour `ZOpenChainVerifiedTest::test_strict_mode_throws_runtime_exception`.
- **Préserver le message** `'NF525 Z-chain verification failed for branch ...'` (ligne 462, asserté tests 186 et 213).
- **Réutiliser predicate `(opened_at, closed_at]`** pour sealed guard, **pas** `business_date <= order.business_date` (drift garanti vs `destroy()` et `aggregate()`).
- **Borner lecture audit_logs** : nouvelle `verifyAuditChainTail($branchId, int $window)` ne valide que les N derniers rows (config `fiscal.audit_chain_tail_window`, default 500).

---

## 1. Contexte + invariants

### 1.1 Frozen zones impactées

D'après `AGENTS.md §1` et `docs/audit/POS_AUDIT_MASTER_PLAN_2026-05-06.md` ligne 200, requièrent **plan Codex (pas commit direct)** :

- `app/Services/Fiscal/ZReportService.php` (open path uniquement — close inchangé)
- `app/Services/OrderService.php` (`changeStatus`, `changePaymentStatus`)
- `routes/api.php` (ajout route refund-with-counter-entry)
- `app/Http/Controllers/Admin/PosOrderController.php` (action refund miroir)

Le reste (nouvelles classes, tests, migrations) est **hors zone frozen**.

### 1.2 Invariants NF525 à préserver

- **Chain integrity** : `Z[n].prev_hash == HMAC(Z[n-1])` ; `audit_logs[i].prev_hash == audit_logs[i-1].current_hash` ; HMAC SHA-256 chained avec secret par branche.
- **Sequence gap-free** : `Z[n].sequence_no = Z[n-1].sequence_no + 1` per branch ; pas de trous.
- **Immutability triggers** : `audit_logs` UPDATE/DELETE bloqués au niveau DB (POS-9.4.3) ET Eloquent (`AuditLog::booted()`).
- **Sealed orders** : `created_at ∈ (Z.opened_at, Z.closed_at]` ET `Z.status == CLOSED` → fiscalement sealed ; toute mutation post-fait DOIT créer un order miroir.
- **Branch isolation** : queries scoped `branch_id`.

### 1.3 Invariants du plan

- **Backward compatibility** : `ZReportService::verifyChain()` garde signature publique `(int $branchId, ?bool $strict = null): array`.
- **Test contract** : `ZOpenChainVerifiedTest::test_strict_mode_throws_runtime_exception` doit continuer à passer → `FiscalChainCorruptedException extends RuntimeException`.
- **Performance** : tail window default 500 rows = ~10ms.
- **Feature flag granularité** : 2 flags séparés (chain validation extension vs sealed-Z guard) pour rollback indépendant.

---

## 2. Architecture cible

```
ZReportService::open(branch, user)
 ├─ Cache::lock('z_report_b{N}', 10s)
 ├─ FiscalChainValidator::assertChainIntegrity($branch) ◄── NEW
 │     ├─ ZReportService::verifyChain($branch, strict=true)  → throws FiscalChainCorruptedException
 │     └─ FiscalChainValidator::verifyAuditChainTail() ◄── NEW
 │         → throws FiscalChainCorruptedException if fork
 ├─ assertNoPendingClose($branch) ◄── NEW (STATUS_CLOSING detection)
 ├─ DB::transaction → reserve next_sequence_no
 └─ return ZReport (status=OPEN)

OrderService::changeStatus(order, status=RETURNED)
 ├─ ValidStatusTransition rule (existing)
 ├─ SealedOrderGuard::assertMutable($order, RETURNED) ◄── NEW
 │   → throws OrderSealedException(409) if Z closed for window
 ├─ branch isolation guard (existing 1531-1538)
 └─ persist + audit (existing 1546-1611)

POST /api/pos-order/{order}/refund-with-counter-entry ◄── NEW
 └─ PosOrderController::refundWithCounterEntry()
     └─ RefundWithCounterEntryService::execute(order, reason)
         ├─ FiscalSequenceService::next($branch)
         ├─ Order::create(parent_order_id=N, total=-X, status=RETURNED, payment_status=REFUNDED)
         ├─ duplicate order_items with qty * -1
         ├─ AuditLogService::write('order.refund.counter_entry', ...)
         └─ return mirror order
```

---

## 3. Fichiers à CRÉER (hors zones frozen)

### 3.1 `app/Exceptions/FiscalChainCorruptedException.php` (~40 lignes)

```php
<?php
namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * [P11-FZH / F-VERIFY-08-01]
 * MUST extend RuntimeException pour préserver compat avec
 * ZOpenChainVerifiedTest::test_strict_mode_throws_runtime_exception.
 * Message DOIT commencer par 'NF525 Z-chain verification failed' pour
 * la branche Z (compat SIEM, FiscalArchiveCommand, tests existants).
 */
class FiscalChainCorruptedException extends RuntimeException
{
    public const KIND_Z_CHAIN     = 'z_chain';
    public const KIND_AUDIT_CHAIN = 'audit_chain';

    public string $kind;
    public int    $branchId;
    public array  $errors;

    public function __construct(
        string $kind, int $branchId, array $errors,
        string $message, ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->kind = $kind;
        $this->branchId = $branchId;
        $this->errors = $errors;
    }
}
```

### 3.2 `app/Exceptions/OrderSealedException.php` (~50 lignes)

```php
<?php
namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [P11-FZH / F-VERIFY-08-02]
 * Extends HttpException(409) — le contrôleur surface 409 propre sans 422 catch-all.
 * Resolution: cashier MUST use POST /api/pos-order/{order}/refund-with-counter-entry.
 */
class OrderSealedException extends HttpException
{
    public int $orderId;
    public int $sealedByZReportId;
    public int $branchId;
    public string $attemptedTransition;

    public function __construct(
        int $orderId, int $branchId, int $sealedByZReportId, string $attemptedTransition
    ) {
        parent::__construct(409, sprintf(
            'Order #%d is sealed by closed Z report #%d (NF525 immutability). '
            . 'Use refund-with-counter-entry instead of "%s".',
            $orderId, $sealedByZReportId, $attemptedTransition
        ));
        $this->orderId = $orderId;
        $this->branchId = $branchId;
        $this->sealedByZReportId = $sealedByZReportId;
        $this->attemptedTransition = $attemptedTransition;
    }
}
```

### 3.3 `app/Services/Fiscal/FiscalChainValidator.php` (~180 lignes)

```php
<?php
namespace App\Services\Fiscal;

use App\Exceptions\FiscalChainCorruptedException;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * [P11-FZH / F-VERIFY-08-01]
 * Single-entry orchestrator: asserts BOTH chains intact for a branch.
 * Z chain → ZReportService::verifyChain() (full replay, fast O(daily)).
 * Audit chain → bounded tail (config window, default 500) pour éviter deadlock cache lock 4s.
 * Feature flag: `fiscal.chain_validation_enabled` (default true).
 */
class FiscalChainValidator
{
    public function __construct(
        private ZReportService $zReportService,
        private AuditLogService $auditLogService,
    ) {}

    public function assertChainIntegrity(int $branchId): void
    {
        if (!$this->isEnabled()) return; // legacy path

        // 1) Z chain — réutilise verifyChain existant
        try {
            $this->zReportService->verifyChain($branchId, strict: true);
        } catch (\RuntimeException $e) {
            $report = $this->zReportService->verifyChain($branchId, strict: false);
            throw new FiscalChainCorruptedException(
                FiscalChainCorruptedException::KIND_Z_CHAIN,
                $branchId, $report['errors'] ?? [],
                $e->getMessage(), // garde 'NF525 Z-chain verification failed'
                $e
            );
        }

        // 2) Audit chain tail (bornée)
        $window = (int) Config::get('fiscal.audit_chain_tail_window', 500);
        $errors = $this->verifyAuditChainTail($branchId, $window);
        if ($errors !== []) {
            Log::channel('fiscal')->error('NF525 audit chain tail verification failed', [
                'event' => 'fiscal.audit_chain.verification_failed',
                'branch_id' => $branchId, 'window' => $window, 'errors' => $errors,
            ]);
            throw new FiscalChainCorruptedException(
                FiscalChainCorruptedException::KIND_AUDIT_CHAIN,
                $branchId, $errors,
                sprintf('NF525 audit chain verification failed for branch %d (window=%d, errors=%d).',
                    $branchId, $window, count($errors))
            );
        }
    }

    public function verifyAuditChainTail(int $branchId, int $window): array
    {
        $tailIds = AuditLog::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('id')->limit($window)->pluck('id')->toArray();

        if (empty($tailIds)) return [];

        $oldestId = min($tailIds);
        $rows = AuditLog::query()
            ->where('branch_id', $branchId)
            ->where('id', '>=', $oldestId)
            ->orderBy('id')->cursor();

        $errors = [];
        $expectedPrev = null;
        $isFirstRow = true;

        foreach ($rows as $row) {
            $rowPrev = $row->prev_hash === null ? null : trim((string) $row->prev_hash);

            if (!$isFirstRow) {
                $expectedPrevNorm = $expectedPrev === null ? null : trim((string) $expectedPrev);
                if ($rowPrev !== $expectedPrevNorm) {
                    $errors[] = [
                        'audit_log_id' => (int) $row->id, 'kind' => 'chain_break',
                        'expected' => (string) $expectedPrevNorm, 'actual' => (string) $rowPrev,
                    ];
                }
            }

            $recomputed = $this->auditLogService->computeHash(
                (int) ($row->branch_id ?? 0), $rowPrev,
                (string) $row->action, (array) ($row->payload ?? [])
            );

            $stored = trim((string) $row->current_hash);
            if (!hash_equals($stored, $recomputed)) {
                $errors[] = [
                    'audit_log_id' => (int) $row->id, 'kind' => 'signature_mismatch',
                    'expected' => $recomputed, 'actual' => $stored,
                ];
            }

            $expectedPrev = $stored;
            $isFirstRow = false;
        }

        return $errors;
    }

    private function isEnabled(): bool
    {
        return (bool) Config::get('fiscal.chain_validation_enabled', true);
    }
}
```

### 3.4 `app/Services/Order/SealedOrderGuard.php` (~80 lignes)

```php
<?php
namespace App\Services\Order;

use App\Exceptions\OrderSealedException;
use App\Models\Order;
use App\Models\ZReport;
use Illuminate\Support\Facades\Config;

/**
 * [P11-FZH / F-VERIFY-08-02]
 * Réutilise EXACT predicate de OrderService::destroy() (1820-1833) ET
 * ZReportService::aggregate() (238-247) — pas de drift sémantique.
 * Predicate: order is sealed iff
 *   exists ZReport where branch_id = order.branch_id
 *     AND status = CLOSED
 *     AND opened_at < order.created_at AND closed_at >= order.created_at
 *     AND order.fiscal_sequence_no IS NOT NULL
 */
class SealedOrderGuard
{
    public function assertMutable(Order $order, string $attemptedTransition): void
    {
        if (!$this->isEnabled()) return;

        // Legacy / unfiscalised → not under NF525 → let through
        if ($order->fiscal_sequence_no === null) return;

        $sealingZ = ZReport::query()
            ->where('branch_id', (int) $order->branch_id)
            ->where('status', ZReport::STATUS_CLOSED)
            ->where('opened_at', '<', $order->created_at)
            ->where('closed_at', '>=', $order->created_at)
            ->orderBy('id')->first();

        if (!$sealingZ) return;

        throw new OrderSealedException(
            (int) $order->id, (int) $order->branch_id,
            (int) $sealingZ->id, $attemptedTransition
        );
    }

    private function isEnabled(): bool
    {
        return (bool) Config::get('fiscal.sealed_z_guard_enabled', true);
    }
}
```

### 3.5 `app/Services/Order/RefundWithCounterEntryService.php` (~150 lignes)

```php
<?php
namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * [P11-FZH / F-VERIFY-08-02]
 * Crée un order "miroir" (counter-entry) pour refund post-Z scellé.
 * Mirror : same branch_id/customer/order_type, parent_order_id = parent.id,
 * status=RETURNED, payment_status=REFUNDED, total/subtotal/tax NEGATED,
 * fresh fiscal_sequence_no, items dupliqués qty*-1.
 * Le mirror est créé dans le Z window COURANT — agrégé via standard query
 * (même résultat comptable que post-Z RETURNED legacy mais parent IMMUTABLE).
 */
class RefundWithCounterEntryService
{
    public function __construct(
        private FiscalSequenceService $sequence,
        private AuditLogService $audit,
        private SealedOrderGuard $sealedGuard,
        private ?ConnectionInterface $connection = null,
    ) {
        $this->connection = $connection ?? DB::connection();
    }

    public function execute(Order $parent, string $reason, ?int $userId = null): Order
    {
        if ($parent->fiscal_sequence_no === null) {
            throw new InvalidArgumentException(
                'parent must have fiscal_sequence_no (use changeStatus → RETURNED pre-Z path).'
            );
        }
        if ((int) $parent->status === OrderStatus::RETURNED) {
            throw new InvalidArgumentException('parent already RETURNED — refusing double mirror.');
        }
        $reason = trim($reason);
        if ($reason === '') throw new InvalidArgumentException('reason required.');

        $userId = $userId ?? (Auth::check() ? (int) Auth::id() : null);
        $branchId = (int) $parent->branch_id;

        return $this->connection->transaction(function () use ($parent, $reason, $userId, $branchId) {
            $mirrorSeq = $this->sequence->next($branchId);

            $mirror = Order::create([
                'branch_id' => $branchId,
                'user_id' => $parent->user_id,
                'order_type' => $parent->order_type,
                'parent_order_id' => $parent->id, // FK self-ref
                'status' => OrderStatus::RETURNED,
                'payment_status' => PaymentStatus::REFUNDED,
                'total' => -1 * (float) $parent->total,
                'subtotal' => -1 * (float) ($parent->subtotal ?? 0),
                'total_tax' => -1 * (float) ($parent->total_tax ?? 0),
                'total_ht' => -1 * (float) ($parent->total_ht ?? 0),
                'fiscal_sequence_no' => $mirrorSeq,
                'reason' => $reason,
                'pos_payment_method' => $parent->pos_payment_method,
                'payment_method' => $parent->payment_method,
                'order_serial_no' => 'RTN-' . $parent->order_serial_no,
            ]);

            foreach ($parent->orderItems as $item) {
                OrderItem::create([
                    'order_id' => $mirror->id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'price' => $item->price,
                    'quantity' => -1 * (int) $item->quantity,
                    'tax_rate' => $item->tax_rate,
                    'tax_amount' => -1 * (float) $item->tax_amount,
                    // ... copy remaining columns 1:1
                ]);
            }

            $this->audit->write([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'action' => 'order.refund.counter_entry',
                'resource' => 'order',
                'resource_id' => (int) $mirror->id,
                'payload' => [
                    'parent_order_id' => (int) $parent->id,
                    'parent_serial_no' => $parent->order_serial_no,
                    'parent_fiscal_sequence_no' => (int) $parent->fiscal_sequence_no,
                    'mirror_fiscal_sequence_no' => $mirrorSeq,
                    'mirror_total' => round(-1 * (float) $parent->total, 2),
                    'reason' => $reason,
                ],
            ]);

            Log::channel('fiscal')->info('order.refund.counter_entry', [
                'parent_order_id' => $parent->id, 'mirror_order_id' => $mirror->id,
                'branch_id' => $branchId, 'user_id' => $userId, 'mirror_total' => $mirror->total,
            ]);

            return $mirror->refresh();
        });
    }
}
```

### 3.6 Tests à écrire

#### `tests/Unit/Services/Fiscal/FiscalChainValidatorTest.php` (~150 lignes)

- `test_z_chain_intact_audit_chain_intact_does_not_throw`
- `test_z_chain_signature_tampered_throws_kind_z_chain` (mutation forceFill)
- `test_z_chain_prev_hash_forged_throws_kind_z_chain`
- `test_audit_chain_tail_signature_tampered_throws_kind_audit_chain`
- `test_audit_chain_tail_prev_hash_fork_throws_kind_audit_chain`
- `test_audit_chain_window_bounded_does_not_load_full_table` (10k rows + window=10 + DB::listen)
- `test_feature_flag_disabled_skips_audit_chain_check`
- `test_exception_message_preserves_legacy_prefix` ('NF525 Z-chain verification failed')

#### `tests/Feature/Fiscal/ZOpenChainValidationTest.php` (~120 lignes)

- `test_open_throws_when_audit_chain_corrupted_strict_mode` (audit forgé → throws, no Z créé)
- `test_open_succeeds_when_both_chains_intact` (régression flow standard)
- `test_open_with_legacy_flag_disabled_falls_back_to_z_only_check`
- `test_open_logs_structured_error_to_fiscal_channel_on_audit_corruption`
- `test_open_does_not_full_walk_audit_table` (DB::listen counting tail window)

#### `tests/Feature/Fiscal/SealedOrderMutationGuardTest.php` (~180 lignes)

- `test_change_status_to_returned_throws_when_order_in_closed_z_window` (409 + audit `pos.refund.post_z_blocked`)
- `test_change_status_to_returned_succeeds_when_order_in_open_z_window` (régression legacy)
- `test_change_payment_status_to_refunded_throws_when_sealed`
- `test_change_payment_status_to_refunded_succeeds_when_pre_z`
- `test_change_status_to_canceled_succeeds_even_if_sealed` (CANCELED non bloqué)
- `test_change_status_legacy_unfiscalised_order_not_blocked` (`fiscal_sequence_no IS NULL`)
- `test_feature_flag_sealed_z_guard_disabled_falls_back_to_legacy`
- `test_refund_with_counter_entry_creates_mirror_order_with_negative_total` (POST endpoint, 201)
- `test_refund_with_counter_entry_mirror_aggregated_in_current_z`
- `test_refund_with_counter_entry_double_call_throws` (idempotency)
- `test_refund_with_counter_entry_requires_pos_manage_fiscal_permission`

#### `tests/Feature/Sentinels/FiscalSealedZSentinelTest.php` (~80 lignes)

- `test_sealed_guard_called_in_change_status_returned`
- `test_sealed_guard_called_in_change_payment_status_refunded`
- `test_z_open_calls_fiscal_chain_validator`
- `test_refund_with_counter_entry_route_protected_by_pos_permission`
- `test_audit_log_pos_refund_post_z_blocked_emitted_on_sealed_attempt`

---

## 4. Fichiers à MODIFIER (frozen — plan Codex requis)

### 4.1 `app/Services/Fiscal/ZReportService.php` (open path uniquement)

```php
public function __construct(
    private ?ConnectionInterface $connection = null,
    ?FiscalSealingService $sealing = null,
    ?FiscalChainValidator $chainValidator = null  // ◄── ADD
) {
    $this->connection = $connection ?? DB::connection();
    $this->sealing = $sealing ?? app(FiscalSealingService::class);
    $this->chainValidator = $chainValidator ?? app(FiscalChainValidator::class); // ◄── ADD
}

public function open(int $branchId, User|int|null $openedBy = null): ZReport
{
    // ... existing param checks + lock acquisition (51-62) UNCHANGED ...

    try {
        if (!$lock->block(self::LOCK_ACQUIRE_SECONDS)) { ... }

        // EXISTING — Z chain pre-check
        $this->verifyChain($branchId);

        // [P11-FZH / F-VERIFY-08-01] NEW — orchestrated chain check (Z + audit tail)
        $this->chainValidator->assertChainIntegrity($branchId);

        // ◄── NEW: STATUS_CLOSING recovery check
        $this->assertNoPendingClose($branchId);

        return $this->connection->transaction(function () use ($branchId, $openedById) {
            // ... existing reservation logic (69-100) UNCHANGED ...
        });
    } finally { ... }
}

// NEW helper (no-op si STATUS_CLOSING jamais écrit — détection seulement)
private function assertNoPendingClose(int $branchId): void
{
    $staleClosing = ZReport::query()
        ->where('branch_id', $branchId)
        ->where('status', ZReport::STATUS_CLOSING)
        ->where('updated_at', '<', now()->subSeconds(15))
        ->first();
    if ($staleClosing) {
        Log::channel('fiscal')->error('z_report.stuck_closing', [
            'z_report_id' => $staleClosing->id, 'branch_id' => $branchId,
            'stuck_since' => $staleClosing->updated_at?->toIso8601String(),
        ]);
        throw new RuntimeException(sprintf(
            'ZReportService: branch %d has Z (id=%d) stuck CLOSING >15s. Manual intervention.',
            $branchId, $staleClosing->id
        ));
    }
}
```

> **Note** : `close()` PAS modifié (scope contenu). STATUS_CLOSING write path = follow-up `PLAN_P12_Z_CLOSING_RECOVERY`.

### 4.2 `app/Services/OrderService.php`

#### `changeStatus()` — ajouts ciblés (lignes 1546-1611)

```php
if (in_array($toStatus, [OrderStatus::REJECTED, OrderStatus::CANCELED, OrderStatus::RETURNED], true)) {
    $request->validate(['reason' => 'required|max:700']);

    // [P11-FZH / F-VERIFY-08-02] NEW — sealed-Z guard for RETURNED only
    // CANCELED/REJECTED = operational (pre-payment usually).
    // RETURNED = fiscal counter-entry → must go through mirror path post-Z.
    if ($toStatus === OrderStatus::RETURNED) {
        try {
            app(\App\Services\Order\SealedOrderGuard::class)
                ->assertMutable($order, 'changeStatus → RETURNED');
        } catch (\App\Exceptions\OrderSealedException $e) {
            // Audit blocked attempt (immutable trace)
            app(AuditLogService::class)->write([
                'branch_id' => (int) $order->branch_id,
                'user_id' => Auth::check() ? (int) Auth::id() : null,
                'action' => 'pos.refund.post_z_blocked',
                'resource' => 'order',
                'resource_id' => (int) $order->id,
                'payload' => [
                    'attempted_transition' => 'RETURNED',
                    'sealed_by_z_id' => $e->sealedByZReportId,
                    'reason_supplied' => (string) $request->input('reason'),
                ],
            ]);
            throw $e; // HttpException(409) bubble cleanly
        }
    }

    if ($request->reason) { $order->reason = $request->reason; }
    // ... rest UNCHANGED ...
}
```

#### `changePaymentStatus()` — ajout après ligne 1681

Réorg : déplacer le `$order->save()` actuel APRÈS le guard.

```php
// ... existing param checks + branch isolation (1668-1675) UNCHANGED ...

if ((int) $order->payment_status === $targetPaymentStatus) return $order;

// [P11-FZH / F-VERIFY-08-02] NEW — sealed-Z guard for REFUNDED only
if ($targetPaymentStatus === PaymentStatus::REFUNDED) {
    try {
        app(\App\Services\Order\SealedOrderGuard::class)
            ->assertMutable($order, 'changePaymentStatus → REFUNDED');
    } catch (\App\Exceptions\OrderSealedException $e) {
        app(AuditLogService::class)->write([
            'branch_id' => (int) $order->branch_id,
            'user_id' => Auth::check() ? (int) Auth::id() : null,
            'action' => 'pos.refund.post_z_blocked',
            'resource' => 'order',
            'resource_id' => (int) $order->id,
            'payload' => [
                'attempted_transition' => 'REFUNDED',
                'sealed_by_z_id' => $e->sealedByZReportId,
            ],
        ]);
        throw $e;
    }
}

$order->payment_status = $request->payment_status;
$order->save();
// ... rest UNCHANGED (ActionLog, AuditLog write) ...
```

### 4.3 `routes/api.php`

Ajout dans `Route::prefix('pos-order')` (793-806) :

```php
Route::post('/{order}/refund-with-counter-entry', [PosOrderController::class, 'refundWithCounterEntry'])
    ->middleware(['throttle:pos-order-update', 'permission:pos-manage-fiscal'])
    ->name('refundWithCounterEntry');
```

> Note : `pos-manage-fiscal` ability discutée dans `PLAN_P11_FISCAL_ROUTE_AUTHZ_HARDENING` (F-VERIFY-10-1). Si pas existante, fallback `permission:pos` + check in-method via `Gate::allows('pos-manage-fiscal')`.

### 4.4 `app/Http/Controllers/Admin/PosOrderController.php`

```php
public function refundWithCounterEntry(
    Order $order, Request $request,
    \App\Services\Order\RefundWithCounterEntryService $service
): \Illuminate\Http\JsonResponse {
    $validated = $request->validate(['reason' => 'required|string|max:700']);

    // Defense-in-depth (middleware déjà enforce)
    if (!Auth::user()->hasRole('Admin')
        && (int) Auth::user()->branch_id !== (int) $order->branch_id) {
        abort(403, 'Cross-branch refund denied.');
    }

    try {
        $mirror = $service->execute($order, $validated['reason']);
        return response()->json([
            'success' => true,
            'data' => new \App\Http\Resources\OrderDetailsResource($mirror->load('orderItems')),
            'meta' => [
                'parent_order_id' => $order->id,
                'mirror_fiscal_sequence_no' => $mirror->fiscal_sequence_no,
            ],
        ], 201);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
    }
}
```

`changeStatus`/`changePaymentStatus` controllers : pas de modif — `OrderSealedException` (HttpException 409) bubble naturellement.

---

## 5. Migrations DB

### 5.1 `database/migrations/2026_05_06_120000_add_parent_order_id_to_orders.php`

```php
public function up(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->unsignedBigInteger('parent_order_id')->nullable()->after('id');
        $table->foreign('parent_order_id')->references('id')->on('orders')->nullOnDelete();
        $table->index('parent_order_id');
    });
}

public function down(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->dropForeign(['parent_order_id']);
        $table->dropIndex(['parent_order_id']);
        $table->dropColumn('parent_order_id');
    });
}
```

> Pre-flight : grep `parent_order_id` dans `database/migrations` → 0 résultat confirmé. Aussi ajouter à `Order::$fillable`.

### 5.2 STATUS_CLOSING — **OPTIONNEL phase 2**

Recommandation : sortir du scope P11 → plan dédié `PLAN_P12_Z_CLOSING_RECOVERY`. Le check `assertNoPendingClose()` reste no-op silencieux jusqu'à activation.

### 5.3 Config `config/fiscal.php` — ajouts

```php
'chain_validation_enabled' => filter_var(
    env('FISCAL_CHAIN_VALIDATION_ENABLED', true),
    FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE
) ?? true,

'audit_chain_tail_window' => (int) env('FISCAL_AUDIT_CHAIN_TAIL_WINDOW', 500),

'sealed_z_guard_enabled' => filter_var(
    env('SEALED_Z_GUARD_ENABLED', true),
    FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE
) ?? true,
```

---

## 6. Step-by-step Cursor / Codex

### Phase 1 — Préparation
1. `git checkout -b feature/p11-fiscal-z-open-hardening`
2. `bash scripts/agent-activity-log.sh start`
3. Lire `AGENTS.md`, `plans/masterplay/MASTERPLAY_DISCIPLINE.md`
4. `php artisan test --filter=Fiscal` → baseline GREEN, capture run

### Phase 2 — Migrations + config
5. Migration `parent_order_id`
6. `Order::$fillable` + cast int + factory
7. Clés config `fiscal.php`
8. `php artisan migrate:fresh --env=testing && php artisan test --filter=Fiscal` → GREEN
9. Commit `feat(p11): add parent_order_id + feature flags (no behavior change)`

### Phase 3 — Exceptions + guards (TDD)
10. RED: `tests/Unit/Services/Fiscal/FiscalChainValidatorTest.php`
11. Créer exceptions + `FiscalChainValidator` + `SealedOrderGuard`
12. GREEN
13. Commit `feat(p11): FiscalChainValidator + SealedOrderGuard + typed exceptions`

### Phase 4 — Z.open hardening (frozen)
14. RED: `tests/Feature/Fiscal/ZOpenChainValidationTest.php`
15. Patch `ZReportService::__construct` + `open()` + `assertNoPendingClose`
16. GREEN inclus régression `ZOpenChainVerifiedTest`
17. Commit `feat(p11): Z.open extends chain validation to audit_logs tail`

### Phase 5 — Sealed-Z guard (frozen)
18. RED: `tests/Feature/Fiscal/SealedOrderMutationGuardTest.php`
19. Patch `OrderService::changeStatus` + `changePaymentStatus`
20. GREEN
21. Commit `feat(p11): sealed-Z guard on changeStatus/changePaymentStatus`

### Phase 6 — Refund miroir
22. `RefundWithCounterEntryService` + route + controller action
23. GREEN
24. Commit `feat(p11): refund-with-counter-entry mirror order endpoint`

### Phase 7 — Sentinels
25. `FiscalSealedZSentinelTest`
26. 28 → 29 PASS
27. Commit `test(p11): sentinel FiscalSealedZ`

### Phase 8 — Validation
28. `php artisan test` (full)
29. Update `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` ligne 141-142 → ✅ RESOLVED
30. PR avec body référençant ce plan
31. `bash scripts/agent-activity-log.sh done`

---

## 7. Critères d'acceptation

### Tests
- ✅ `FiscalChainValidatorTest` — 8/8 PASS
- ✅ `ZOpenChainValidationTest` — 5/5 PASS
- ✅ `SealedOrderMutationGuardTest` — 11/11 PASS
- ✅ `FiscalSealedZSentinelTest` — 5/5 PASS
- ✅ Régression `RefundPostZTest` — toujours PASS
- ✅ Régression `ZOpenChainVerifiedTest` — toujours PASS
- ✅ Régression `OrderFiscalSequenceSchemaTest` — toujours PASS
- ✅ Sentinels POS 28 → 29 PASS
- ✅ Suite complète — 0 régression

### Logs / observabilité
- ✅ `Log::channel('fiscal')` reçoit `fiscal.audit_chain.verification_failed`
- ✅ `audit_logs` row `pos.refund.post_z_blocked` à chaque tentative bloquée
- ✅ `audit_logs` row `order.refund.counter_entry` à chaque mirror

### NF525
- ✅ Aucune mutation directe d'ordre sealed
- ✅ Tout refund post-Z laisse trace mirror dans Z courant
- ✅ Audit chain tail validation O(window) bornée
- ✅ Branch isolation préservée

### Hors-scope
- ❌ STATUS_CLOSING write path (`PLAN_P12_Z_CLOSING_RECOVERY`)
- ❌ Audit chain full replay command (utilitaire ops)
- ❌ HMAC secret rotation runbook (déjà dans `docs/FISCAL_SECRETS.md`)

---

## 8. Risques + rollback

### Risque 1 — Faux positif après rotation HMAC
**Mitigation** : tail window default 500 — rotation suivie de >500 nouveaux audit_logs avant next Z.open. Procédure : freeze writes → rotate → re-sign last 500 via `php artisan fiscal:audit-chain:resign --branch=N --window=500` → unfreeze. Rollback : `FISCAL_CHAIN_VALIDATION_ENABLED=false`.

### Risque 2 — `OrderSealedException` casse UI clients
**Mitigation** : changelog API explicite ; front affiche modal "Cette commande est sealed — créer un avoir ?" → POST refund-with-counter-entry. Rollback : `SEALED_Z_GUARD_ENABLED=false`.

### Risque 3 — Lock contention chain validator
**Mitigation** : tail window borné 500 → ~10ms p99. Load test pré-déploiement : 100 Z.open / 60s sur staging avec 100k audit_logs → assert p95 < 500ms. Rollback : réduire window à 100 ou désactiver flag.

### Risque 4 — `parent_order_id` cascade soft-delete
**Mitigation** : FK `nullOnDelete()` + audit_log `order.refund.counter_entry` garde `payload.parent_order_id`.

### Risque 5 — Race verifyAuditChainTail vs concurrent audit_log writes
**Mitigation** : walker fixe `oldestId` au snapshot DESC. MySQL REPEATABLE READ snapshote au début. Documentation : ajouter `DB::transaction(fn() => $validator->assertChainIntegrity())` pour cohérence.

### Plan de rollback complet
1. `FISCAL_CHAIN_VALIDATION_ENABLED=false`
2. `SEALED_Z_GUARD_ENABLED=false`
3. Revert migration `parent_order_id` (vérifier 0 mirror existant avant)
4. `git revert <commit p11>` (commits indépendants par phase)

Aucune des 4 actions ne touche signatures HMAC ni schéma `audit_logs` / `z_reports` — rollback propre.

---

## 9. Synthèse — frozen zones touchées

| Fichier | Lignes touchées | Type |
|---|---|---|
| `app/Services/Fiscal/ZReportService.php` | constructor + 64-68 + 1 helper privé | DELTA (wrap, pas réécriture) |
| `app/Services/OrderService.php::changeStatus` | 1546 (insert 8 lignes) | DELTA (réutilise pattern destroy) |
| `app/Services/OrderService.php::changePaymentStatus` | 1677 (insert 6 lignes) | DELTA |
| `routes/api.php` | +1 ligne route | ADD |
| `app/Http/Controllers/Admin/PosOrderController.php` | +1 méthode (~30 lignes) | ADD |

Le reste (8 nouveaux fichiers, 1 migration, 3 clés config) = **hors zones frozen**.

---

**Auteur** : Plan agent — 2026-05-06
**Évidence d'audit** :
- `app/Services/Fiscal/ZReportService.php:66` (verifyChain dans open)
- `app/Services/Fiscal/ZReportService.php:363-472` (Z chain logic)
- `app/Services/Fiscal/AuditLogService.php:199-231` (audit chain verifier)
- `app/Services/OrderService.php:1820-1833` (sealed predicate destroy)
- `tests/Feature/Fiscal/ZOpenChainVerifiedTest.php:172-221` (test contract)
- `tests/Feature/Fiscal/RefundPostZTest.php:14-54` (legacy comportement à préserver)
- `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md:141-142,206`
- `config/fiscal.php:55,74-78` (flags pattern)

**Critical Files** :
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/OrderService.php`
- `app/Services/Fiscal/AuditLogService.php`
- `tests/Feature/Fiscal/ZOpenChainVerifiedTest.php`
- `config/fiscal.php`

**Fin du plan.**
