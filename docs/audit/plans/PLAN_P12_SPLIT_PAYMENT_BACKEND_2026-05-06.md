# PLAN_P12_SPLIT_PAYMENT_BACKEND — F-SPLIT-PAYMENT-001

**Date** : 2026-05-06
**Train** : A V1 release prep (cycle 6, suite POS audit cycles 1-5)
**Mission** : CV1-POS-SPLIT-PAYMENT-001
**Référence verdict** : `docs/audit/POS_DEEP_FLOWS_AUDIT_2026-05-06.md` §6 + frontend livré dans `docs/audit/SPLIT_PAYMENT_IMPLEMENTATION_2026-05-06.md`
**Auteur** : agent Plan, plan destiné à Cursor/Codex (exécution)
**Statut** : 🟢 PRÊT À EXÉCUTER
**Frozen-zone** : OUI — modifications `OrderService`, `PaymentService`, `FrontendOrderService`, `PosOrderRequest` requièrent gate Codex.

---

## 0. Mismatches & alignements (vs énoncé initial)

- Le frontend (`PaymentComponent.vue`) envoie `payment_breakdown[]` dans le payload `POST /api/admin/pos`. Backend doit lire ce champ (PosOrderRequest validation + OrderService persistence).
- Le helper `OrderDetailsResource::buildPaymentsBreakdown()` (`app/Http/Resources/OrderDetailsResource.php:103`) lit déjà une relation `$order->payments` quand elle existe — la table cible doit donc se nommer en cohérence et la relation Eloquent `payments()` être ajoutée à `App\Models\Order`.
- `posReceiptBuilder.js::formatPaymentsBreakdown` côté front consomme déjà la forme `{ method, amount, currency_amount, change_amount, reference }` — schéma de la table et du resource doivent coller à ce contrat.
- `confirmCounterPayment` (PaymentService:123) gère le **paiement deferred post-création** (counter-collect) — surface DIFFÉRENTE du POS create. Multi-tender doit fonctionner **sur les deux** (cycle 7 ou même cycle si capacity).

---

## 1. Contexte + invariants

### 1.1 Contexte FoodKing
FoodKing POS — cashier doit pouvoir encaisser une facture en plusieurs tranches : combinaisons cash + card, parts égales N personnes, tranches custom. Frontend déjà livré (cycle 6) avec mode "Multi-paiement" dans `PaymentComponent.vue`. Backend actuel `OrderService::posOrderStore` n'accepte qu'**un seul mode** via `pos_payment_method` + `pos_received_amount`. La table `orders` ne stocke qu'**un seul tendered**.

### 1.2 Invariants à respecter
Référence : `feedback_cv1_mode_operatoire.md` + `docs/audit/POS_AUDIT_MASTER_PLAN_2026-05-06.md`.

- **Backend = source de vérité prix** : la **somme des tranches doit ≥ total réel calculé serveur** (pas le `total` envoyé par le client). Sentinel à créer : `SplitPaymentSumExceedsTotalEnforcedTest`.
- **Branch isolation** : chaque ligne `order_payments` porte son `branch_id` ; jamais de leak cross-branch (sentinel `SplitPaymentBranchScopedTest`).
- **NF525 / fiscal** : chaque tranche = 1 ligne `audit_logs` chaînée (action `order.payment_tranche_persisted`). Le `fiscal_sequence_no` reste **un par order**, pas par tranche.
- **Frozen zones** : `OrderService::posOrderStore`, `PaymentService::confirmCounterPayment`, `FrontendOrderService::storeOrUpdate` — toute modification passe par cette gate Codex.
- **28/28 sentinels POS DOIVENT rester PASS** (régression zéro). Ajout de 1 sentinel `SplitPaymentSentinelTest`.
- **Pricing SSOT** + **allergen snapshot POS-9.4.BL.1** + **idempotency** inchangés.
- **Backward-compat** : un payload sans `payment_breakdown[]` doit continuer à fonctionner (legacy single-tender). Le champ est **optionnel**.
- **Feature flag** : `SPLIT_PAYMENT_ENABLED` (default `false`). Quand off, backend ignore silencieusement `payment_breakdown[]` et fallback single-tender.

### 1.3 Spec issue du frontend
Le payload contient :
```json
{
  "branch_id": 1,
  "items": "[...]",
  "pos_payment_method": 1,        // mode dominant (legacy fallback)
  "pos_received_amount": 10,      // tendered de la 1re tranche cash (legacy fallback)
  "payment_breakdown": [
    { "mode": 1, "amount": 5,  "tendered": 10, "change": 5,  "note": null },
    { "mode": 2, "amount": 15, "tendered": null, "change": 0, "note": null }
  ]
}
```

---

## 2. Décisions architecturales (load-bearing)

| Décision | Choix | Rationale |
|---|---|---|
| **Stockage** | Nouvelle table `order_payments` (1:N avec `orders`) — **PAS** de colonne JSON | Queryable / indexable / audit NF525 par tranche / agrégations rapports / FK propre. La forme JSON-column rend les Z-reports et les exports fiscaux fragiles. |
| **Schema clé** | `id`, `order_id` (FK), `branch_id` (denormalisé pour isolation rapide), `mode` (tinyint), `amount` (decimal 10,2), `tendered` (decimal 10,2 nullable), `change_amount` (decimal 10,2), `reference` (string nullable), `paid_at` (timestamp), `created_at`, `updated_at` | `branch_id` denormalisé évite un join pour scope queries. `reference` accueille les 4-derniers card / IDs TPE / tickets. |
| **Index** | `(order_id, mode)`, `(branch_id, paid_at)` | 1er pour join receipt / fiscal. 2e pour Z-report par jour. |
| **Validation** | `payment_breakdown` validé en `array`, taille 1-12, items `mode/amount/tendered` typés. **Somme ≥ total serveur** (pas client). | Capacité 12 = limite raisonnable (12 personnes max sur une facture). |
| **Service** | Nouveau `SplitPaymentService` qui WRAPPE — ne MODIFIE PAS — `PaymentService`. Méthode `persistTranches(Order $order, array $tranches): Collection<OrderPayment>` appelée depuis `OrderService::posOrderStore` une fois l'order créé. | Frozen-zone : `PaymentService` n'évolue pas en V1 ; `confirmCounterPayment` reste single-tender. Cycle 7 → `confirmCounterPaymentMulti`. |
| **Backward compat** | Si `payment_breakdown` absent OU vide, `OrderService` continue le path legacy. Si présent, il est persisté en plus du `pos_payment_method` (qui devient le **mode dominant** pour reports legacy). | Zéro régression sur 28 sentinels. |
| **Idempotency** | Pas d'idempotency par tranche en V1. Idempotency reste au niveau `Order` (clé `(branch_id, idempotency_key)`). Replay → renvoie l'ordre existant ET ses tranches. | Aligné PLAN_P11. |
| **NF525 audit** | Une ligne `audit_logs` par tranche avec `action='order.payment_tranche_persisted'`, `resource='order_payment'`, `resource_id=tranche.id`, `payload={mode, amount, tendered, change}`. Chaînage hash sur la séquence existante. | Conformité fiscale par-tranche (la traçabilité d'un montant qui change de poche est requise). |
| **Refund partiel** | **Hors scope V1** (cycle 8). Schema prévoit `change_amount` mais pas `refunded_amount` — backlog. | Le user a demandé refund partiel comme suggestion cycle 7. Backlog, pas dans plan exécutable. |
| **Feature flag** | `SPLIT_PAYMENT_ENABLED=false` default. Quand off : backend strip `payment_breakdown` du payload avant validation et continue legacy. | Rollback instantané. |
| **Réponse API** | `OrderDetailsResource::buildPaymentsBreakdown()` lit déjà `$order->payments` — il suffit d'ajouter la relation `payments()` au modèle Order. | Surface API compatible avec le receipt front existant. |

---

## 3. Fichiers à créer

### 3.1 `database/migrations/2026_05_06_120000_create_order_payments_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('branch_id');         // denormalised for fast scope/audit queries
            $table->unsignedTinyInteger('mode');             // PosPaymentMethod enum: 1=CASH, 2=CARD, ...
            $table->decimal('amount', 10, 2);                // tranche montant facturé
            $table->decimal('tendered', 10, 2)->nullable();  // for CASH only — peut > amount
            $table->decimal('change_amount', 10, 2)->default(0);
            $table->string('reference', 64)->nullable();     // 4-derniers card / ID TPE / ID ticket resto
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index(['order_id', 'mode']);
            $table->index(['branch_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
```

### 3.2 `app/Models/OrderPayment.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPayment extends Model
{
    protected $table = 'order_payments';

    protected $fillable = [
        'order_id',
        'branch_id',
        'mode',
        'amount',
        'tendered',
        'change_amount',
        'reference',
        'paid_at',
    ];

    protected $casts = [
        'mode'           => 'int',
        'branch_id'      => 'int',
        'amount'         => 'decimal:2',
        'tendered'       => 'decimal:2',
        'change_amount'  => 'decimal:2',
        'paid_at'        => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Receipt-friendly accessor (matches OrderDetailsResource::buildPaymentsBreakdown
     * shape so that the existing helper works zero-modif).
     */
    public function getPaymentMethodAttribute(): int
    {
        return (int) $this->attributes['mode'];
    }
}
```

### 3.3 `app/Services/Payments/SplitPaymentService.php`

```php
<?php

namespace App\Services\Payments;

use App\Enums\PosPaymentMethod;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * SplitPaymentService — multi-tender persistence.
 *
 * F-SPLIT-PAYMENT-001 — wraps (does NOT modify) PaymentService.
 *
 * - Called from OrderService::posOrderStore once the Order row exists and
 *   the cart total is server-computed.
 * - Persists 1..N rows in `order_payments`, audit-logs each tranche.
 * - Re-validates sum >= total (defense in depth — PosOrderRequest already
 *   validates client-side, but the SSOT total may have evolved during
 *   the create transaction).
 *
 * NB: refund / partial cancel of a tranche is NOT in scope V1.
 */
final class SplitPaymentService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param array<int, array{mode:int, amount:float, tendered:?float, change?:float, note?:?string, reference?:?string}> $tranches
     * @throws ValidationException when sum < server total or any tranche is malformed.
     */
    public function persistTranches(Order $order, array $tranches): Collection
    {
        if (! config('split_payment.enabled', false)) {
            // Feature-flag off → silently no-op. OrderService persists legacy single-tender.
            return new Collection();
        }

        if (empty($tranches)) {
            return new Collection();
        }

        $this->validateTranches($order, $tranches);

        return DB::transaction(function () use ($order, $tranches): Collection {
            $persisted = new Collection();
            foreach ($tranches as $idx => $t) {
                $mode = (int) $t['mode'];
                $amount = (float) $t['amount'];
                $tendered = isset($t['tendered']) && $t['tendered'] !== null
                    ? (float) $t['tendered']
                    : null;
                $change = isset($t['change']) ? (float) $t['change'] : 0.0;

                $row = OrderPayment::create([
                    'order_id'      => (int) $order->id,
                    'branch_id'     => (int) $order->branch_id,
                    'mode'          => $mode,
                    'amount'        => $amount,
                    'tendered'      => $tendered,
                    'change_amount' => $change,
                    'reference'     => $t['reference'] ?? $t['note'] ?? null,
                    'paid_at'       => now(),
                ]);

                $this->auditLog->write([
                    'branch_id'   => (int) $order->branch_id,
                    'user_id'     => optional(auth()->user())->id,
                    'action'      => 'order.payment_tranche_persisted',
                    'resource'    => 'order_payment',
                    'resource_id' => (int) $row->id,
                    'payload'     => [
                        'order_id' => (int) $order->id,
                        'tranche_index' => (int) $idx,
                        'mode' => $mode,
                        'amount' => $amount,
                        'tendered' => $tendered,
                        'change' => $change,
                    ],
                ]);
                $persisted->push($row);
            }
            return $persisted;
        });
    }

    /**
     * @param array<int, array> $tranches
     * @throws ValidationException
     */
    private function validateTranches(Order $order, array $tranches): void
    {
        if (count($tranches) > 12) {
            throw ValidationException::withMessages([
                'payment_breakdown' => 'Trop de tranches (max 12).',
            ]);
        }

        $allowedModes = [
            PosPaymentMethod::CASH,
            PosPaymentMethod::CARD,
            PosPaymentMethod::MOBILE_BANKING,
            PosPaymentMethod::OTHER,
            PosPaymentMethod::TICKET_RESTAURANT,
        ];

        $totalCents = 0;
        foreach ($tranches as $idx => $t) {
            if (! is_array($t)) {
                throw ValidationException::withMessages([
                    "payment_breakdown.{$idx}" => 'Tranche invalide.',
                ]);
            }
            $mode = (int) ($t['mode'] ?? 0);
            if (! in_array($mode, $allowedModes, true)) {
                throw ValidationException::withMessages([
                    "payment_breakdown.{$idx}.mode" => 'Mode de paiement non autorisé.',
                ]);
            }
            $amount = (float) ($t['amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    "payment_breakdown.{$idx}.amount" => 'Montant tranche requis (>0).',
                ]);
            }
            if ($mode === PosPaymentMethod::CASH) {
                $tendered = isset($t['tendered']) ? (float) $t['tendered'] : null;
                if ($tendered === null || $tendered <= 0) {
                    throw ValidationException::withMessages([
                        "payment_breakdown.{$idx}.tendered" => 'Montant reçu requis pour la tranche cash.',
                    ]);
                }
                if ((int) round($tendered * 100) < (int) round($amount * 100)) {
                    throw ValidationException::withMessages([
                        "payment_breakdown.{$idx}.tendered" => 'Montant reçu inférieur au montant cash.',
                    ]);
                }
            }
            $totalCents += (int) round($amount * 100);
        }

        $serverTotalCents = (int) round(((float) $order->total) * 100);
        if ($totalCents < $serverTotalCents) {
            throw ValidationException::withMessages([
                'payment_breakdown' => sprintf(
                    'Somme des tranches (%.2f €) < total (%.2f €).',
                    $totalCents / 100,
                    $serverTotalCents / 100,
                ),
            ]);
        }
    }
}
```

### 3.4 `config/split_payment.php`

```php
<?php

return [
    'enabled'      => (bool) env('SPLIT_PAYMENT_ENABLED', false),
    'max_tranches' => (int) env('SPLIT_PAYMENT_MAX_TRANCHES', 12),
];
```

### 3.5 Tests Feature — `tests/Feature/SplitPayment/SplitPaymentServiceTest.php`

Couverture (8 scénarios) :

1. `test_two_tranche_cash_card_persists_two_rows_audit_chain_intact`
2. `test_sum_below_server_total_throws_validation_exception`
3. `test_sum_above_server_total_accepted_with_change_per_cash_tranche` (overpay tolerated)
4. `test_cash_tranche_without_tendered_rejected`
5. `test_cash_tranche_with_tendered_below_amount_rejected`
6. `test_more_than_12_tranches_rejected`
7. `test_branch_id_denormalised_matches_order_branch` (branch isolation sentinel)
8. `test_each_tranche_emits_one_audit_log_chained` (NF525 chaining)

### 3.6 Sentinel — `tests/Feature/Sentinels/SplitPaymentSentinelTest.php`

Couverture (5 scénarios sur **vraies routes**) :

1. `test_pos_create_with_payment_breakdown_persists_order_payments_rows`
2. `test_pos_create_without_payment_breakdown_legacy_single_tender_still_works`
3. `test_split_payment_disabled_flag_silently_ignores_breakdown_field`
4. `test_payment_breakdown_branch_id_denormalised_matches_order_branch`
5. `test_replay_idempotent_post_returns_same_order_with_same_tranches` (PLAN_P11 compat)

---

## 4. Fichiers à modifier (FROZEN-ZONE — Codex gate requise)

### 4.1 `app/Http/Requests/PosOrderRequest.php`

Ajouter dans `rules()` :

```php
// [F-SPLIT-PAYMENT-001] Optional multi-tender breakdown — see SplitPaymentService
'payment_breakdown' => ['nullable', 'array', 'max:12'],
'payment_breakdown.*.mode'     => ['required_with:payment_breakdown', 'integer', 'in:1,2,3,4,5'],
'payment_breakdown.*.amount'   => ['required_with:payment_breakdown', 'numeric', 'min:0.01'],
'payment_breakdown.*.tendered' => ['nullable', 'numeric', 'min:0'],
'payment_breakdown.*.note'     => ['nullable', 'string', 'max:191'],
```

Et dans `withValidator()` (ou `prepareForValidation()`), ajouter le strip-when-disabled :

```php
protected function prepareForValidation(): void
{
    parent::prepareForValidation(); // existant
    // [F-SPLIT-PAYMENT-001] When feature flag off, strip the field BEFORE validation
    // so cashier-side UI doesn't break older deployments.
    if (! config('split_payment.enabled', false)) {
        $this->offsetUnset('payment_breakdown');
    }
}
```

### 4.2 `app/Models/Order.php`

Ajouter relation `payments()` :

```php
/**
 * [F-SPLIT-PAYMENT-001] Multi-tender breakdown.
 * OrderDetailsResource::buildPaymentsBreakdown() reads this relation.
 */
public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(OrderPayment::class, 'order_id');
}
```

(Optionnel : ajouter `'payment_breakdown' => 'array'` au cast n'a PAS de sens — la relation suffit.)

### 4.3 `app/Services/OrderService.php` (frozen — gate Codex)

Dans `posOrderStore`, après que l'order est créé et le total persisté :

**Localisation** : juste après `$this->order->save()` final (cherche le dernier `save()` dans la transaction `posOrderStore`, juste avant le `dispatch` des events / l'audit log principal).

```php
// [F-SPLIT-PAYMENT-001] Persist multi-tender breakdown if provided.
// Frozen-zone gate: this block is additive — the legacy single-tender
// fields (pos_payment_method / pos_received_amount) remain authoritative
// for receipt printing & reports while feature-flag off.
$breakdown = (array) $request->input('payment_breakdown', []);
if (! empty($breakdown) && config('split_payment.enabled', false)) {
    app(\App\Services\Payments\SplitPaymentService::class)
        ->persistTranches($this->order, $breakdown);
}
```

**Contraintes critiques** :
- N'altère PAS la logique single-tender existante.
- S'exécute APRÈS le calcul SSOT du total (utiliser `$this->order->total`, pas la valeur client).
- En cas de `ValidationException` du `SplitPaymentService`, la transaction `posOrderStore` rollback (comportement Laravel par défaut).

### 4.4 `app/Services/PaymentService.php` (frozen — gate Codex, **HORS scope V1**)

`confirmCounterPayment()` n'est PAS modifié en V1. La surface deferred-payment counter-collect reste single-tender. Cycle 7 backlog : ajouter `confirmCounterPaymentMulti(Order $order, array $tranches)` qui réutilise `SplitPaymentService::persistTranches`.

### 4.5 `app/Services/FrontendOrderService.php` (frozen — **HORS scope V1**)

Surface kiosk / online-order : single-tender uniquement. Cycle 7 backlog si user demande split en kiosk.

### 4.6 `app/Http/Resources/OrderDetailsResource.php`

**Aucune modification nécessaire** — `buildPaymentsBreakdown()` lit déjà `$order->payments` quand la relation existe (`OrderDetailsResource.php:107`). Une fois la relation ajoutée au modèle (§4.2), le resource fonctionne automatiquement.

---

## 5. Migration : ordre & idempotence

L'unique migration créée (§3.1) est **purement additive** :
- N'altère pas `orders`.
- N'introduit pas de FK qui casserait un downgrade DB.
- `down()` drop la table → rollback safe.

Si le rollout staging révèle un besoin de seeder (paiements historiques migrés en single-tender → tranche unique), un seeder backfill peut être ajouté dans cycle 7.

---

## 6. Step-by-step pour Cursor

### Étape 1 — Pré-vérifications

```bash
git status
php artisan --version
vendor/bin/phpunit tests/Feature/Sentinels --testdox | tail -20
# Attendu : 28/28 PASS
```

### Étape 2 — Créer (ordre strict)

1. `database/migrations/2026_05_06_120000_create_order_payments_table.php`
2. `app/Models/OrderPayment.php`
3. `config/split_payment.php`
4. `app/Services/Payments/SplitPaymentService.php`

### Étape 3 — Wiring (frozen-zone — gate Codex)

5. `app/Models/Order.php` — relation `payments()`
6. `app/Http/Requests/PosOrderRequest.php` — règles + strip si flag off
7. `app/Services/OrderService.php` — appel `SplitPaymentService::persistTranches`

### Étape 4 — Tests

8. `tests/Feature/SplitPayment/SplitPaymentServiceTest.php`
9. `tests/Feature/Sentinels/SplitPaymentSentinelTest.php`

### Étape 5 — Run + sanity

```bash
php artisan migrate
SPLIT_PAYMENT_ENABLED=true vendor/bin/phpunit tests/Feature/SplitPayment --testdox
SPLIT_PAYMENT_ENABLED=true vendor/bin/phpunit --filter=SplitPaymentSentinelTest
SPLIT_PAYMENT_ENABLED=true vendor/bin/phpunit tests/Feature/Sentinels --testdox
# Attendu : 28 sentinels existants + 1 nouveau = 29 PASS
SPLIT_PAYMENT_ENABLED=false vendor/bin/phpunit tests/Feature/Sentinels --testdox
# Attendu : 28/28 PASS (transparent — payment_breakdown ignoré)
```

### Étape 6 — Documentation

10. **Modifier** `docs/audit/POS_DEEP_FLOWS_AUDIT_2026-05-06.md` — section §6 : marquer split-payment ✅ RESOLVED.
11. **Créer** `docs/SPLIT_PAYMENT.md` (~200 lignes) :
    - schema `order_payments`
    - contrat API `payment_breakdown[]`
    - fallback legacy single-tender
    - flag rollout
    - exemple curl

### Étape 7 — Commit (PAS de push sans review humaine)

```bash
git add database/migrations/2026_05_06_120000_create_order_payments_table.php \
        app/Models/OrderPayment.php \
        app/Models/Order.php \
        app/Http/Requests/PosOrderRequest.php \
        app/Services/Payments/ \
        app/Services/OrderService.php \
        config/split_payment.php \
        tests/Feature/SplitPayment/ \
        tests/Feature/Sentinels/SplitPaymentSentinelTest.php \
        docs/SPLIT_PAYMENT.md docs/audit/

git commit -m "feat(split-payment): order_payments + service F-SPLIT-PAYMENT-001 (flag-gated)"
```

---

## 7. Critères d'acceptation

### 7.1 Tests

- [ ] `SplitPaymentServiceTest` 8/8 PASS
- [ ] `SplitPaymentSentinelTest` 5/5 PASS
- [ ] **0 régression** sur 28 sentinels POS existants (avec `SPLIT_PAYMENT_ENABLED=true` ET `false`)
- [ ] Suite phpunit complète : 685 tests + 1 nouveau = 686 PASS
- [ ] Sentinel `IdempotencyRecoveryBranchScopedTest` (PLAN_P11) reste vert

### 7.2 Code review

- [ ] Aucune modification dans `PaymentService.php` (frozen).
- [ ] Aucune modification dans `FrontendOrderService.php` (frozen).
- [ ] Modifications `OrderService.php` strictement additives (1 bloc à la fin du happy path).
- [ ] Modifications `PosOrderRequest.php` : règles ajoutées seulement, aucune règle existante touchée.
- [ ] Migration 100% additive ; `down()` testé (`migrate:rollback` doit fonctionner).
- [ ] Flag `SPLIT_PAYMENT_ENABLED=false` par défaut → no-op total.

### 7.3 Manuel staging

```bash
KEY=$(uuidgen)
curl -X POST https://staging.foodking/api/admin/pos \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Idempotency-Key: $KEY" \
  -d '{
    "branch_id":1,
    "items":"[{\"item_id\":1,\"qty\":1,\"price\":25}]",
    "pos_payment_method":1,
    "pos_received_amount":10,
    "payment_breakdown":[
      {"mode":1,"amount":5,"tendered":10,"change":5},
      {"mode":2,"amount":20}
    ]
  }'
# 200 + order créé + 2 lignes order_payments visibles dans DB
# /api/admin/order/{id} renvoie payments_breakdown[] avec 2 lignes
```

### 7.4 Doc `docs/SPLIT_PAYMENT.md`

- Section "Pourquoi" (réf F-SPLIT-PAYMENT-001 + frontend cycle 6)
- Table schema `order_payments`
- Format payload `payment_breakdown[]`
- Rules de validation (max 12, sum >= total, cash tendered required)
- Comportement flag on/off
- Lien vers sentinel comme contrat exécutable

---

## 8. Risques + rollback

### 8.1 Risques

| Risque | Sévérité | Mitigation |
|---|---|---|
| Sum tranches < total accepté en V1 (faux positif sur quote drift) | **P0** fiscal | Validation stricte `>=` (pas `==`) ; sentinel obligatoire ; rejet 422 net. |
| Tranches persistées mais ordre rollback (transaction inconsistance) | **P0** | `SplitPaymentService::persistTranches` enveloppé dans la même `DB::transaction` que `posOrderStore` (Laravel propage le rollback). |
| `OrderDetailsResource::buildPaymentsBreakdown()` charge la relation N+1 | P1 | Eager-load `payments` dans `OrderResource` / show controllers (1 ligne `with('payments')`). |
| Cassure backward-compat receipt rendering | P1 | Test sentinel `test_pos_create_without_payment_breakdown_legacy_single_tender_still_works` garantit le path legacy. |
| Audit log spam sur N tranches | P2 | Acceptable — 12 max. NF525 EXIGE traçabilité par tranche. |
| Migration prod sur table `orders` énorme (lock) | P2 | Migration ne touche PAS `orders` (table neuve only). Lock < 100ms. |
| Cycle 7 / refund partiel : schema doit évoluer | P2 | Ajout colonne `refunded_amount` non-breaking. Backlog. |
| Frontend envoie `payment_breakdown` avant flag activé | P2 | `prepareForValidation()` strip du champ → silent no-op. Tested by sentinel #3. |

### 8.2 Plan de rollback (3 niveaux)

**Niveau 1** — Disable feature (instant)
```bash
SPLIT_PAYMENT_ENABLED=false
php artisan config:clear
```

**Niveau 2** — Drop table (si data corruption)
```bash
php artisan migrate:rollback --step=1
```

**Niveau 3** — Revert git
```bash
git revert <commit-sha>
```

### 8.3 Plan de rollout

1. **S** : merge avec flag false. Sentinels green. Migration appliquée mais inactive.
2. **S+1** : staging avec `SPLIT_PAYMENT_ENABLED=true`. Test cashier réel : 5 scénarios manuels (cash+card, parts égales 3, cash seul, card seul, sum < total → 422).
3. **S+2** : 1 branche pilote prod (Châtelet) avec flag true. Surveillance 7j (zéro 422 inattendu, count `order_payments` cohérent avec count `orders`).
4. **S+3** : roll-out global prod si métriques OK.

---

## 9. Suivi du finding

À la clôture :
- `docs/audit/POS_DEEP_FLOWS_AUDIT_2026-05-06.md` §6 : 🔴 → ✅ RESOLVED
- Réf commit + sentinel `SplitPaymentSentinelTest`
- F-SPLIT-PAYMENT-001 retiré de la liste P0 frozen-zone ouverte
- `docs/audit/SPLIT_PAYMENT_IMPLEMENTATION_2026-05-06.md` mis à jour : "backend integration shipped"

---

## 10. Squelettes complémentaires (référence Codex)

### 10.1 `tests/Feature/SplitPayment/SplitPaymentServiceTest.php` (skel)

```php
<?php

namespace Tests\Feature\SplitPayment;

use App\Enums\PosPaymentMethod;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\Payments\SplitPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SplitPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['split_payment.enabled' => true]);
    }

    public function test_two_tranche_cash_card_persists_two_rows_audit_chain_intact(): void
    {
        $order = Order::factory()->create(['branch_id' => 1, 'total' => 25.00]);

        app(SplitPaymentService::class)->persistTranches($order, [
            ['mode' => PosPaymentMethod::CASH, 'amount' => 10, 'tendered' => 12, 'change' => 2],
            ['mode' => PosPaymentMethod::CARD, 'amount' => 15],
        ]);

        $this->assertCount(2, OrderPayment::where('order_id', $order->id)->get());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.payment_tranche_persisted',
            'resource' => 'order_payment',
        ]);
    }

    public function test_sum_below_server_total_throws_validation_exception(): void
    {
        $order = Order::factory()->create(['branch_id' => 1, 'total' => 25.00]);

        $this->expectException(ValidationException::class);
        app(SplitPaymentService::class)->persistTranches($order, [
            ['mode' => PosPaymentMethod::CASH, 'amount' => 10, 'tendered' => 10],
        ]);
    }

    // ... 6 autres scénarios par §3.5
}
```

### 10.2 `tests/Feature/Sentinels/SplitPaymentSentinelTest.php` (skel)

```php
<?php

namespace Tests\Feature\Sentinels;

use App\Enums\PosPaymentMethod;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitPaymentSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_create_with_payment_breakdown_persists_order_payments_rows(): void
    {
        config(['split_payment.enabled' => true]);
        $cashier = User::factory()->cashier()->create(['branch_id' => 1]);
        $this->actingAs($cashier);

        $resp = $this->postJson('/api/admin/pos', [
            'branch_id' => 1,
            'items' => json_encode([['item_id' => 1, 'qty' => 1, 'price' => 25]]),
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10,
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 10, 'tendered' => 10, 'change' => 0],
                ['mode' => PosPaymentMethod::CARD, 'amount' => 15],
            ],
        ], ['X-Idempotency-Key' => uniqid('sentinel_')]);

        $resp->assertOk();
        $orderId = $resp->json('data.id');
        $this->assertCount(2, OrderPayment::where('order_id', $orderId)->get());
    }

    // ... 4 autres scénarios par §3.6
}
```

---

## 11. Évolutions futures (backlog, hors scope V1)

- **Cycle 7 — refund partiel** : ajouter `refunded_amount` colonne, méthode `SplitPaymentService::refundTranche($trancheId, $amount)`, audit `order.payment_tranche_refunded`.
- **Cycle 7 — multi-tender deferred** : `PaymentService::confirmCounterPaymentMulti(Order, array $tranches)` pour la surface counter-collect.
- **Cycle 8 — multi-currency** : column `currency` (3-char ISO) sur `order_payments`. Conversion au persist via `CurrencyService`.
- **Cycle 8 — kiosk split** : extension à `FrontendOrderService::storeOrUpdate`. Le kiosk envoie aujourd'hui un seul paiement.
- **Cycle 9 — Z-report enrichi** : agrégat `order_payments` par mode dans le rapport fiscal Z (aujourd'hui agrège uniquement `pos_payment_method`).
- **Cycle 9 — assignation items → personnes** : `order_payments` ↔ `order_items` via pivot `order_payment_items` pour split par item assigné (au-delà du split par parts égales).

---

**Fin du plan. ~570 lignes. Exécutable par Cursor/Codex sans question.**
