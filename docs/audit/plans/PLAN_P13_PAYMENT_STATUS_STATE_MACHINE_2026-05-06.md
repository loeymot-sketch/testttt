# PLAN_P13_PAYMENT_STATUS_STATE_MACHINE — F-VERIFY-09-01 + F-VERIFY-09-10

**Date** : 2026-05-06
**Train** : Caisse V1 — Train A V1 release prep
**Findings** : F-VERIFY-09-01 PARTIAL + F-VERIFY-09-10 OPEN
**Statut** : PLAN ONLY — Cursor/Codex à exécuter, frozen-zone gate requise

---

## 0. CRITICAL — Pre-write deltas vs spec initiale (BLOCKERS)

L'audit du code a révélé que la spec initiale entre en conflit avec l'existant :

### D1 — `PAID → REFUNDED` (NF525 sensitive)

`app/Domain/Order/PaymentStateMachine.php` EXISTE déjà avec map `TRANSITIONS`, `canTransition()`, `assertCanTransition()`. La map déclare `PaymentStatus::PAID => []` (TERMINAL). Le sentinel `tests/Feature/Payment/PaymentStateMachineTransitionsTest::test_terminal_payment_states_do_not_reopen` ligne 21 asserte `PAID→REFUNDED === false`.

| | Option A — Étendre | Option B — Status-quo (recommandée) |
|---|---|---|
| Action | Ajouter `PAID => [REFUNDED]` à `TRANSITIONS` | Laisser `PAID => []` ; refunds via `PaymentService::cancelCounterPayment` (déjà atomique avec `OrderStateMachine::recordTransition`, AuditLog, Transaction ledger) |
| Sentinel | **Casse** test ligne 21 — réécrire | Aucun impact |
| NF525 | Refund post-encaissement entre dans `changePaymentStatus` | Refund cantonné à service dédié — mieux pour NF525 |

**Recommandation** : **Option B**. Sans réponse TL, le plan exécute B (`payment_status=20` (REFUNDED) sur order PAID → 422).

### D2 — Type d'exception

| | Option A | Option B (recommandée) |
|---|---|---|
| Action | Créer `App\Exceptions\InvalidPaymentTransitionException` | Garder `\InvalidArgumentException` (déjà utilisée par `PaymentService::confirmCounterPayment`) |
| Churn | Élevé (call sites multiples) | Zéro |

**Recommandation** : **Option B**.

### D3 — `PaymentTransitionMap.php` séparé

| | Option A | Option B (recommandée) |
|---|---|---|
| Action | Extraire `TRANSITIONS` dans nouvelle classe | Garder `private const` dans `PaymentStateMachine` |

**Recommandation** : **Option B**. Si plus tard un consommateur externe a besoin, exposer `legalTransitions()` (cf. `OrderStateMachine.php:240`).

### D4 — Path correct EventContract

`app/Services/Sync/EventContract.php` n'existe **pas**. Le vrai path est `app/Domain/Events/EventContract.php`.

---

## 1. Contexte + invariants

### 1.1 Source

- Cycle parent : **CAISSE_V1_MASTERPLAY** (Train A V1 release prep)
- Audit : `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` §3.3
- Findings : **F-VERIFY-09-01 PARTIAL → CLOSED** + **F-VERIFY-09-10 OPEN → CLOSED**

### 1.2 État actuel

`OrderService::changePaymentStatus` (`app/Services/OrderService.php` lignes 1651-1721) :

- ✅ Branch isolation guard (1670-1675)
- ✅ No-op guard early-return (1658, 1677-1679)
- ✅ ActionLog + AuditLog NF525 (POS-9.4.BL.2)
- ❌ Pas de `Rule::in(...)` côté FormRequest (`payment_status=999` traverse)
- ❌ Pas de `DB::transaction` englobante (Order save + ActionLog + AuditLog non atomiques)
- ❌ Pas de lecture `X-Idempotency-Key`
- ❌ Pas de state machine appliquée à ce site (existe mais non câblée)
- ❌ Pas de domain event `OrderPaymentStatusChanged` → KDS / Z-report / outbox manquent le signal

### 1.3 Invariants à protéger

| # | Invariant | Risque local |
|---|---|---|
| 1 | Backend Pricing SSOT | OK, déjà figé sur Order |
| 3 | branch_id isolation | `abort(403)` déjà présent → conserver |
| 4 | Dispatch after commit (gate C9 — KI-001) | **Nouveau** event doit utiliser `DispatchableAfterCommit` |
| 5 | OS/FOS symmetry | `FrontendOrderService` n'a pas d'équivalent → vérifier en revue |
| 6 | Frozen zones | `OrderService.php` frozen → **plan Codex obligatoire**, gate `M-09` partial allowlist |

---

## 2. Fichiers — état après plan

### 2.1 Fichiers à CRÉER

| Path | Rôle | LOC |
|---|---|---|
| `app/Events/OrderPaymentStatusChanged.php` | Plain domain event, `DispatchableAfterCommit` | ~30 |
| `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php` | Outbox row + `DispatchDomainEventsJob` | ~90 |
| `tests/Unit/Domain/Order/PaymentStateMachineExtendedTest.php` | Étend matrice | ~60 |
| `tests/Feature/Order/ChangePaymentStatusTransactionalTest.php` | Atomicité | ~150 |
| `tests/Feature/Order/ChangePaymentStatusValidationTest.php` | `payment_status=999` → 422 | ~60 |
| `tests/Feature/Order/ChangePaymentStatusOutboxTest.php` | `domain_events` row + payload | ~110 |
| `tests/Feature/Sentinels/PaymentStatusStateMachineSentinelTest.php` | Sentinel anti-régression | ~120 |

### 2.2 Fichiers à MODIFIER (frozen — gate M-09)

| Path | Surface | Modification |
|---|---|---|
| `app/Services/OrderService.php` | `changePaymentStatus` (1651-1721) | DB::transaction + state machine + Idempotency-Key + event |
| `app/Http/Requests/PaymentStatusRequest.php` | `rules()` | `Rule::in([UNPAID, PAID, PENDING_COUNTER, REFUNDED])` |
| `app/Providers/EventServiceProvider.php` | `$listen` | câblage event → listener |
| `app/Domain/Events/EventContract.php` | `BROADCAST_MAP` + `REQUIRED_PAYLOAD_KEYS` | nouvelle entry |
| `app/Enums/EventType.php` | const + `all()` | `ORDER_PAYMENT_STATUS_CHANGED = 'order.payment_status_changed'` |

### 2.3 Fichiers explicitement NON créés (vs spec)

- ~~`app/Domain/Order/OrderPaymentStateMachine.php`~~ → utilise existant `PaymentStateMachine.php`
- ~~`app/Domain/Order/PaymentTransitionMap.php`~~ → conservé inline (D3)
- ~~`app/Exceptions/InvalidPaymentTransitionException.php`~~ → status-quo `\InvalidArgumentException` (D2)
- ~~`app/Services/Sync/EventContract.php`~~ → path réel `app/Domain/Events/EventContract.php` (D4)

### 2.4 Migration : AUCUNE

---

## 3. Squelettes code

### 3.1 `app/Events/OrderPaymentStatusChanged.php`

```php
<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use App\Events\Concerns\DispatchableAfterCommit;

/**
 * Plain domain event fired when an order's payment_status transitions.
 * Mirrors OrderStatusChanged. Uses DispatchableAfterCommit (gate C9 — KI-001)
 * so the event is deferred until DB::transaction() commits.
 *
 * Plan source: docs/audit/plans/PLAN_P13_PAYMENT_STATUS_STATE_MACHINE_2026-05-06.md
 * Findings: F-VERIFY-09-01 (PARTIAL → CLOSED) + F-VERIFY-09-10 (OPEN → CLOSED)
 */
class OrderPaymentStatusChanged
{
    use DispatchableAfterCommit;

    public function __construct(
        public BroadcastableOrder $order,
        public int $oldPaymentStatus,
        public int $newPaymentStatus
    ) {}
}
```

### 3.2 `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php`

```php
<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\OrderPaymentStatusChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Outbox listener for OrderPaymentStatusChanged.
 * Mirrors PersistOrderStatusChangedToOutbox pattern.
 */
class PersistOrderPaymentStatusChangedToOutbox
{
    public function handle(OrderPaymentStatusChanged $event): void
    {
        $order = $event->order;

        $domainEvent = DomainEvent::query()->create([
            'event_type'     => EventType::ORDER_PAYMENT_STATUS_CHANGED,
            'aggregate_type' => get_class($order),
            'aggregate_id'   => $order->id,
            'branch_id'      => $order->branch_id,
            'payload'        => [
                'order_id'              => $order->id,
                'branch_id'             => (int) $order->branch_id,
                'queue_number'          => $order->queue_number,
                '_origin'               => $this->resolveOrigin($order),
                'payment_method'        => $this->resolvePaymentMethod($order),
                'old_status'            => $event->oldPaymentStatus,
                'new_status'            => $event->newPaymentStatus,
                'total'                 => round((float) $order->total, 2),
                'fiscal_sequence_no'    => $order->fiscal_sequence_no,
                'token'                 => $order->token ?? null,
            ],
            'channel'        => json_encode(['private-branch.' . $order->branch_id]),
            'broadcast_as'   => 'OrderPaymentStatusChanged',
            'correlation_id' => $this->resolveCorrelationId(),
            'occurred_at'    => now(),
        ]);

        DB::afterCommit(function () use ($domainEvent): void {
            DispatchDomainEventsJob::dispatch($domainEvent->id);
        });
    }

    private function resolveCorrelationId(): string
    {
        $sharedContext = Log::sharedContext();
        $sharedCorrelationId = is_array($sharedContext) ? ($sharedContext['correlation_id'] ?? null) : null;
        if (is_string($sharedCorrelationId) && trim($sharedCorrelationId) !== '') {
            return $sharedCorrelationId;
        }
        $requestCorrelationId = request()?->header('X-Correlation-ID');
        if (is_string($requestCorrelationId) && trim($requestCorrelationId) !== '') {
            return $requestCorrelationId;
        }
        return (string) Str::uuid();
    }

    private function resolveOrigin(object $order): string
    {
        $surface = trim((string) ($order->source_surface ?? ''));
        if ($surface !== '') return $surface;
        if (($order->pos_payment_method ?? null) !== null) return 'pos';
        return ($order->queue_number ?? null) !== null ? 'kiosk' : 'web';
    }

    private function resolvePaymentMethod(object $order): int|string|null
    {
        if (($order->pos_payment_method ?? null) !== null) return $order->pos_payment_method;
        return $order->payment_method ?? null;
    }
}
```

### 3.3 `app/Services/OrderService.php::changePaymentStatus` (frozen — scope chirurgical)

Diff ciblé conservant la branche `$auth=true` (out of scope V1) intacte :

```php
public function changePaymentStatus(Order $order, PaymentStatusRequest $request, bool $auth = false): Order|array
{
    try {
        $targetPaymentStatus = (int) $request->payment_status;

        if ($auth) {
            // Branche customer self-service — INCHANGÉE (out of scope V1)
            if ($order->user_id == Auth::user()->id) {
                if ((int) $order->payment_status === $targetPaymentStatus) return $order;
                $order->payment_status = $request->payment_status;
                $order->save();
                return $order;
            }
            abort(403, 'Access denied: you do not have permission to modify this order.');
        }

        // Branch isolation guard — INCHANGÉ (1670-1675)
        if (Auth::check() && !Auth::user()->hasRole('Admin')) {
            $userBranch = Auth::user()->branch_id ?? null;
            if ($userBranch && (int) $userBranch !== (int) $order->branch_id) {
                abort(403, 'Accès refusé : cette commande appartient à une autre succursale.');
            }
        }

        // [F-VERIFY-09-01 P13] Idempotency-Key replay protection
        $idempotencyKey = request()?->header('X-Idempotency-Key');
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $cacheKey = sprintf(
                'change_payment_status:%d:%d:%s',
                (int) $order->branch_id, (int) $order->id, substr($idempotencyKey, 0, 64)
            );
            if (\Illuminate\Support\Facades\Cache::get($cacheKey) !== null) {
                return $order->fresh();
            }
        }

        $oldPaymentStatus = (int) $order->payment_status;
        if ($oldPaymentStatus === $targetPaymentStatus) {
            return $order; // No-op
        }

        // [F-VERIFY-09-01 P13] State machine guard — throws \InvalidArgumentException(422)
        \App\Domain\Order\PaymentStateMachine::assertCanTransition(
            $oldPaymentStatus, $targetPaymentStatus
        );

        // [F-VERIFY-09-01 P13] Atomic Order + ActionLog + AuditLog + event dispatch
        \Illuminate\Support\Facades\DB::transaction(function () use (
            $order, $request, $oldPaymentStatus, $targetPaymentStatus
        ): void {
            $order->payment_status = $request->payment_status;
            $order->save();

            \App\Models\ActionLog::create([
                'user_id'  => Auth::check() ? Auth::id() : null,
                'action'   => 'Statut paiement modifié',
                'resource' => 'Commande #' . $order->order_serial_no,
                'details'  => sprintf(
                    'Statut paiement: %d → %d | Par: %s (branch_id=%s)',
                    $oldPaymentStatus, $targetPaymentStatus,
                    Auth::check() ? Auth::user()->name : 'Système',
                    Auth::check() ? (Auth::user()->branch_id ?? 'admin') : '?'
                ),
            ]);

            // POS-9.4.BL.2 NF525 audit trail
            app(AuditLogService::class)->write([
                'branch_id'   => (int) $order->branch_id,
                'user_id'     => Auth::check() ? (int) Auth::id() : null,
                'action'      => 'order.payment_status_changed',
                'resource'    => 'order',
                'resource_id' => (int) $order->id,
                'payload'     => [
                    'order_serial_no'     => $order->order_serial_no,
                    'from_payment_status' => $oldPaymentStatus,
                    'to_payment_status'   => $targetPaymentStatus,
                    'total'               => round((float) $order->total, 2),
                    'fiscal_sequence_no'  => $order->fiscal_sequence_no,
                ],
            ]);

            // [F-VERIFY-09-10 P13] Domain event for outbox / KDS / Z-report
            // DispatchableAfterCommit defers actual dispatch until COMMIT.
            \App\Events\OrderPaymentStatusChanged::dispatch(
                $order, $oldPaymentStatus, $targetPaymentStatus
            );
        });

        // [F-VERIFY-09-01 P13] Persist Idempotency-Key replay marker (TTL 24h)
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $cacheKey = sprintf(
                'change_payment_status:%d:%d:%s',
                (int) $order->branch_id, (int) $order->id, substr($idempotencyKey, 0, 64)
            );
            \Illuminate\Support\Facades\Cache::put($cacheKey, $order->id, now()->addHours(24));
        }

        return $order;
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
        throw $http;
    } catch (\InvalidArgumentException $invalid) {
        throw new Exception($invalid->getMessage(), 422);
    } catch (Exception $exception) {
        Log::info($exception->getMessage());
        throw new Exception(QueryExceptionLibrary::message($exception), 422);
    }
}
```

### 3.4 `app/Http/Requests/PaymentStatusRequest.php`

```php
<?php

namespace App\Http\Requests;

use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (!auth()->check()) return false;
        return auth()->user()->hasAnyRole(['Admin', 'Branch Manager', 'POS Operator']);
    }

    public function rules(): array
    {
        // [F-VERIFY-09-01 P13] Whitelist via enum constants
        // Note: PaymentStatus is an interface with const, not a BackedEnum
        $allowed = [
            PaymentStatus::PAID,
            PaymentStatus::UNPAID,
            PaymentStatus::PENDING_COUNTER,
            PaymentStatus::REFUNDED,
        ];

        return [
            'payment_status' => ['required', 'integer', Rule::in($allowed)],
        ];
    }

    public function messages(): array
    {
        return ['payment_status.in' => 'Statut de paiement invalide.'];
    }
}
```

### 3.5 `app/Providers/EventServiceProvider.php`

```php
use App\Events\OrderPaymentStatusChanged;
use App\Listeners\PersistOrderPaymentStatusChangedToOutbox;

// Dans $listen array:
OrderPaymentStatusChanged::class => [
    PersistOrderPaymentStatusChangedToOutbox::class,
],
```

### 3.6 `app/Enums/EventType.php`

```php
const ORDER_PAYMENT_STATUS_CHANGED = 'order.payment_status_changed'; // [P13]

// Dans all():
self::ORDER_PAYMENT_STATUS_CHANGED, // [P13]
```

### 3.7 `app/Domain/Events/EventContract.php`

```php
public const BROADCAST_MAP = [
    // ... existing entries ...
    'OrderPaymentStatusChanged' => EventType::ORDER_PAYMENT_STATUS_CHANGED, // [P13]
];

public const REQUIRED_PAYLOAD_KEYS = [
    // ... existing entries ...
    EventType::ORDER_PAYMENT_STATUS_CHANGED => ['order_id', 'branch_id', 'old_status', 'new_status', 'total', 'fiscal_sequence_no'], // [P13]
];
```

### 3.8 Mirror `resources/js/services/eventContract.js`

Le commentaire `EventContract.php:33` dit "Keep in sync with `resources/js/services/eventContract.js`". Cursor doit ajouter `OrderPaymentStatusChanged` dans la map JS. **Hors scope frozen-zone** mais inclure dans la PR.

---

## 4. Tests à écrire

### 4.1 Unit — `PaymentStateMachineExtendedTest.php`

Data providers `legalProvider()` et `illegalProvider()`. Sous **Option B** (D1 verrouillée) :

- Légal : `UNPAID→PAID`, `PENDING_COUNTER→PAID`, `PENDING_COUNTER→REFUNDED`
- Illégal : `PAID→UNPAID`, `PAID→REFUNDED`, `PAID→PENDING_COUNTER`, `REFUNDED→PAID`, `UNPAID→REFUNDED`, `UNPAID→PENDING_COUNTER`

> ⚠️ Si D1=A retenue : `PaymentStateMachineTransitionsTest.php:21` à réécrire.

### 4.2 Feature transactionnel — `ChangePaymentStatusTransactionalTest.php`

- `it_rolls_back_order_save_when_action_log_create_fails` — mock throw → `payment_status` inchangé, 0 row outbox/audit
- `it_rolls_back_when_audit_log_write_fails`
- `it_dispatches_event_only_after_commit` — `Event::fake([OrderPaymentStatusChanged::class])`
- `it_emits_one_action_log_one_audit_log_one_event_per_call`

### 4.3 Feature validation — `ChangePaymentStatusValidationTest.php`

- `test_payment_status_999_returns_422` (Rule::in)
- `test_payment_status_string_returns_422`
- `test_payment_status_negative_returns_422`
- `test_payment_status_paid_to_unpaid_returns_422_via_state_machine`

### 4.4 Feature outbox — `ChangePaymentStatusOutboxTest.php`

- `test_unpaid_to_paid_creates_domain_event_row` — vérifie row + payload + correlation_id
- `test_event_envelope_passes_assertEnvelopeValid` — `EventContract::assertEnvelopeValid` ne throw pas

### 4.5 Sentinel — `PaymentStatusStateMachineSentinelTest.php`

- `test_invalid_payment_status_returns_422` (régression detector si Rule::in supprimé)
- `test_idempotency_key_replay_yields_one_action_log` — 2 POST mêmes header → 1 ActionLog row + 1 DomainEvent row
- `test_cross_branch_returns_403`

---

## 5. Step-by-step Cursor / Codex

### 5.1 Pré-requis
- [ ] Approval gate **GATE_FROZEN_M09_PARTIAL_2026-05-XX** signée TL + QA NF525
- [ ] D1/D2/D3 tranchées (défaut Option B)
- [ ] Branch dédiée : `feature/p13-payment-status-state-machine-v1`

### 5.2 Séquence

| Step | Action | Fichier |
|---|---|---|
| 1 | Const `ORDER_PAYMENT_STATUS_CHANGED` | `app/Enums/EventType.php` |
| 2 | `BROADCAST_MAP` + `REQUIRED_PAYLOAD_KEYS` | `app/Domain/Events/EventContract.php` |
| 3 | Event class | `app/Events/OrderPaymentStatusChanged.php` |
| 4 | Listener | `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php` |
| 5 | EventServiceProvider câblage | `app/Providers/EventServiceProvider.php` |
| 6 | Validation durcie | `app/Http/Requests/PaymentStatusRequest.php` |
| 7 | Modifier `changePaymentStatus` (FROZEN — gate signoff) | `app/Services/OrderService.php` |
| 8 | Mirror frontend EventContract JS | `resources/js/services/eventContract.js` |
| 9 | Sentinel POS | `tests/Feature/Sentinels/PaymentStatusStateMachineSentinelTest.php` |

### 5.3 Commandes test

```bash
./vendor/bin/phpstan analyse app/Domain/Order app/Events app/Listeners app/Http/Requests --level=5
./vendor/bin/phpunit tests/Feature/Payment/PaymentStateMachineTransitionsTest.php   # 0 régression
./vendor/bin/phpunit tests/Unit/Domain/Order/PaymentStateMachineExtendedTest.php
./vendor/bin/phpunit tests/Unit/Domain/Order/OrderStateMachineTest.php               # 0 régression
./vendor/bin/phpunit tests/Feature/Order/ChangePaymentStatusValidationTest.php
./vendor/bin/phpunit tests/Feature/Order/ChangePaymentStatusTransactionalTest.php
./vendor/bin/phpunit tests/Feature/Order/ChangePaymentStatusOutboxTest.php
./vendor/bin/phpunit --testsuite=sentinels --filter=Pos
./vendor/bin/phpunit tests/Feature/Sentinels/PaymentStatusStateMachineSentinelTest.php
./vendor/bin/phpunit --group=pos
```

---

## 6. Critères d'acceptation

| ID | Critère | Mesure |
|---|---|---|
| AC-1 | `payment_status=999` → 422 | ValidationTest PASS |
| AC-2 | `PAID→UNPAID` → 422 (state machine) | ValidationTest PASS |
| AC-3 | `ActionLog::create` fails → rollback complet | TransactionalTest PASS |
| AC-4 | UNPAID→PAID → 1 row `domain_events` `order.payment_status_changed` | OutboxTest PASS |
| AC-5 | 2× POST même `X-Idempotency-Key` → 1 ActionLog | Sentinel PASS |
| AC-6 | Cross-branch → 403 | Sentinel PASS |
| AC-7 | `OrderStateMachineTest` (existant) — 0 régression | 18 tests PASS |
| AC-8 | `PaymentStateMachineTransitionsTest` — 0 régression sous Option B | 2 tests PASS |
| AC-9 | `EventContract::assertEnvelopeValid()` accepte nouveau payload | unit + feature PASS |
| AC-10 | Frontend `eventContract.js` reconnaît `OrderPaymentStatusChanged` | vitest PASS |

**Bonus QA manuel** : Playwright POS admin → Commande #X → "Marquer payé" → ticket count + KDS card update + Z-report compteur.

---

## 7. Risques + rollback

### 7.1 Feature flag

```php
// config/payment.php
'state_machine_enabled' => env('PAYMENT_STATE_MACHINE_ENABLED', true),
```

```php
// Dans changePaymentStatus, autour de l'appel state machine:
if (config('payment.state_machine_enabled', true)) {
    PaymentStateMachine::assertCanTransition($oldPaymentStatus, $targetPaymentStatus);
}
```

> Default `true` — flag pour rollback urgence Production. Off = ancien comportement (plus permissif).

### 7.2 Risques

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Flag false en prod laisse passer `999` | Moyenne | Haut | Boot-check fail-fast prod si flag=false |
| Listener throw → outbox row absente | Faible | Haut | try/catch + retry job + Sentry |
| `PaymentStateMachineTransitionsTest` cassé si D1=A sans réécriture | Haut (si A) | Moyen | Plan force réécriture si A |
| OS/FOS symmetry oubliée si futur ajout `FrontendOrderService::changePaymentStatus` | Faible | Moyen | Documenter dans `docs/ORDER_FLOW.md` |
| Idempotency-Key collision entre 2 orders | Faible | Faible | Cache key inclut `order.id` + `branch_id` |

### 7.3 Rollback procedure

1. `PAYMENT_STATE_MACHINE_ENABLED=false` dans `.env` prod → redéploie config seul
2. Si listener pose problème : retirer câblage dans `EventServiceProvider`
3. `php artisan queue:flush domain_events` si jobs backlog avec mauvais payload
4. Revert PR feature flag activé → reproduit comportement legacy

---

## 8. Conformité gates

- **Gate frozen zones** : `docs/gates/GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25.md` Option C — partial allowlist `OrderService::changePaymentStatus`. PR doit prouver `frozen_zones_touched: ["app/Services/OrderService.php#changePaymentStatus"]`.
- **Gate C9 dispatch-after-commit** : satisfaite via `DispatchableAfterCommit` trait.
- **Gate event-contract** : satisfaite via `BROADCAST_MAP` + `REQUIRED_PAYLOAD_KEYS`.
- **Branch isolation** : `abort(403)` ligne 1670-1675 conservé, sentinel `OrderListBranchExactnessSentinel` réutilisé.
- **Pricing SSOT** : pas touché.

---

## 9. Co-signs requis

- **Auteur** : Plan agent — 2026-05-06
- **Cycle parent** : CAISSE_V1_MASTERPLAY (Train A V1 release prep)
- **Approuvé pour exécution** :
  - [ ] TL — décision D1 (PAID→REFUNDED) loggée
  - [ ] BE owner OrderService — relecture diff frozen-zone
  - [ ] QA NF525 — relecture audit_logs + transaction atomicity
  - [ ] DevOps — env flag `PAYMENT_STATE_MACHINE_ENABLED` dispo prod

---

**Critical Files for Implementation** :
- `app/Services/OrderService.php`
- `app/Domain/Order/PaymentStateMachine.php`
- `app/Http/Requests/PaymentStatusRequest.php`
- `app/Domain/Events/EventContract.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php` (pattern de référence)

**Fin du plan.**
