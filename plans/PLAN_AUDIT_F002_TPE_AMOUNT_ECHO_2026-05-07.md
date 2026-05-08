# PLAN_AUDIT_F002 — TPE Amount Echo Verification
**Severity:** P0 — Fraude possible dès branchement TPE réel
**Owner agent:** Agent B (Payment integrity)
**Sprint:** S1
**Estimated:** 1 jour-agent
**Frozen-zone override:** NO (touche `OrderController` + `KioskPaymentComponent.vue` qui n'est PAS dans la frozen-zone wizard kiosk — le composant Payment est hors wizard)

---

## 0. STOP CHECKLIST 6 QUESTIONS

| # | Question | Réponse |
|---|---|---|
| 1 | **Why** ? | Le backend valide `transaction_id` mais pas le **montant** retourné par le TPE. Un TPE compromis peut approuver un montant arbitraire (ex. 1€ sur panier 50€) ; le backend marque PAID avec ce transaction_id falsifié. NF525 + PCI-DSS exigent vérification stricte du montant. |
| 2 | **What** minimal ? | (1) Ajouter `amount_cents` (required, integer, min:1) au validation rules de `paymentConfirm`. (2) Comparer `abs(amount_cents - order.total*100) > 1` → 422. (3) Frontend envoie `amount_cents` dans le payload. (4) Bridge contract `kioskHardware.tpeCharge` retourne `amount_cents_approved`. |
| 3 | **Where** ? | Backend : `app/Http/Controllers/Frontend/OrderController.php:80-115` — Frontend : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:439-444` (confirmBackendPayment call) + `resources/js/services/kioskHardware.js` (contrat) — Stub : même fichier (echo amountCents) |
| 4 | **Who impacted** ? | `KioskPaymentComponent`, `kioskHardware` service, `OrderController::paymentConfirm`, `confirmBackendPayment`, dashboards ops (nouveau metric `tpe.amount_mismatch_rejected`), implémentation Electron future (TPE drivers Ingenico/Verifone/ConCert) |
| 5 | **How valider** ? | `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php` (nouveau) + `tests/js/KioskPayment.spec.js` extended + suite Kiosk + Vue tests |
| 6 | **When rollback** ? | Si `KioskFrontendComprehensiveTest` ou `KioskPaymentRestyle.spec` régresse → revert immédiat |

---

## 1. THINK — Contexte enrichi

### 1.1 Évidence brute (vérifiée par lecture directe)

[`app/Http/Controllers/Frontend/OrderController.php:80-84`](app/Http/Controllers/Frontend/OrderController.php:80) :

```php
$request->validate([
    'transaction_id' => ['required', 'string', 'max:255'],
    'card_type'      => ['nullable', 'string', 'max:50'],
    'payment_method' => ['nullable', 'integer'],
]);
```

→ AUCUN champ `amount`. Le backend ne sait pas ce que le TPE a réellement approuvé.

[`resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:439-444`](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:439) :

```js
await this.confirmBackendPayment(this._lastOrder.id, {
  transaction_id: paymentResult.transaction_id,
  card_type:      paymentResult.card_type || 'CARD',
  payment_method: this.method === 'tr' ? 5 : 4,
});
```

→ Le frontend connaît `this.cartTotal` mais ne le transmet pas.

[`resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:471`](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:471) :

```js
const amountCents = Math.round(Number(amountEuros) * 100);
const result = await kioskHardware.tpeCharge(amountCents, method);
```

→ Le frontend envoie `amountCents` au TPE via le bridge. Le TPE doit retourner ce montant approuvé. Actuellement le retour [lignes 481-491] ne contient PAS `amount_cents_approved`.

### 1.2 Scénarios d'attaque (post branchement TPE réel)

**Attaque 1 — Firmware altéré** :
- TPE modifié approuve 1€ au lieu de 50€.
- Retourne un `transaction_id` valide bancaire pour 1€.
- Backend marque PAID. Différentiel 49€ disparaît dans le compte de l'attaquant.

**Attaque 2 — MITM Electron bridge** :
- Process attaquant intercepte `tpeCharge` côté Electron.
- Approuve un montant arbitraire ; renvoie un transaction_id fictif.
- Backend ne détecte rien.

**Attaque 3 — Replay** :
- Un transaction_id bancaire valide d'une vraie transaction passée est rejoué.
- Backend marque PAID sur l'order courant. Le client paie avec un ticket d'un autre.
- Détection seulement si `transaction_id` est UNIQUE constraint (à vérifier).

### 1.3 Pourquoi cela est-il P0 alors que le stub tourne en simulation

La simulation actuelle retourne `STUB-${Date.now()}` qui n'est pas bancaire. Pas exploitable maintenant. **MAIS** :
- Le code DOIT être prêt avant le branchement matériel.
- L'orchestration owner mène vers la prod ; ne pas livrer cette faille.
- Le test (à écrire) doit prévenir un futur dev de retirer la vérification.

### 1.4 Pattern de référence côté POS

[`app/Services/OrderService.php:828-835`](app/Services/OrderService.php:828) :

```php
if ($request->pos_payment_method == \App\Enums\PosPaymentMethod::CASH
    && $request->pos_received_amount !== null
    && (float) $request->pos_received_amount < $this->order->total) {
    throw new \InvalidArgumentException(
        'Le montant reçu (' . $request->pos_received_amount . '€) est inférieur au total réel (' . $this->order->total . '€).',
        422
    );
}
```

POS valide déjà `pos_received_amount` côté serveur. L'équivalent kiosk doit valider `amount_cents` côté serveur.

---

## 2. PLAN — Stratégie

### 2.1 Architecture cible

```
Frontend KioskPaymentComponent
  ├─ kioskHardware.tpeCharge(amountCents, method)
  │   └─ Bridge Electron → TPE driver → returns {ok, tx_ref, amount_cents_approved}
  │       └─ Stub retourne mirroir : {ok:true, tx_ref:"STUB-xxx", amount_cents_approved: amountCents}
  ├─ Si !approved → KioskErrorPaymentRefusedComponent
  ├─ Si approved → confirmBackendPayment(orderId, {transaction_id, amount_cents, card_type, payment_method})
  └─ Backend OrderController::paymentConfirm
      ├─ Validate amount_cents required + integer + min:1
      ├─ Calculate expected = round(order.total * 100)
      ├─ if (abs(amount_cents - expected) > 1) → 422 'AMOUNT_ECHO_MISMATCH'
      ├─ Else → set PAID + (F-001) allocate fiscal_sequence_no
      └─ Return 200
```

### 2.2 Tolérance

`±1 centime` car les calculs en flottant Vue / arrondis tax peuvent générer des écarts de 1 centime. Au-delà = anomalie.

### 2.3 Error code stable

`AMOUNT_ECHO_MISMATCH` — utilisé par les dashboards ops, ne doit PAS changer une fois en prod.

---

## 3. BUILD — Sous-tâches numérotées

### Sub-task 3.1 — Vérification drift (10 min)

```bash
sed -n '76,116p' app/Http/Controllers/Frontend/OrderController.php
sed -n '435,475p' resources/js/components/frontend/kiosk/KioskPaymentComponent.vue
sed -n '160,260p' resources/js/services/kioskHardware.js
```

Les contenus doivent matcher les snippets §1.1.

### Sub-task 3.2 — Test rouge backend (45 min)

**File:** `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php` (nouveau)

```php
<?php

namespace Tests\Feature\Kiosk;

use Tests\TestCase;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Branch;
use App\Models\User;
use App\Enums\PaymentStatus;
use App\Enums\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KioskPaymentConfirmAmountTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function rejects_payment_confirm_when_amount_is_under_total(): void
    {
        $order = $this->createKioskCardOrder(50.00); // €50

        $response = $this->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-FRAUD-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 100, // €1 — clearly under €50
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error_code' => 'AMOUNT_ECHO_MISMATCH']);
        $this->assertEquals(
            PaymentStatus::UNPAID,
            $order->fresh()->payment_status,
            'payment_status MUST remain UNPAID on amount mismatch.'
        );
    }

    /** @test */
    public function rejects_payment_confirm_when_amount_is_over_total(): void
    {
        $order = $this->createKioskCardOrder(50.00);

        $response = $this->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-OVER-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 5500, // €55, also rejected
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error_code' => 'AMOUNT_ECHO_MISMATCH']);
    }

    /** @test */
    public function accepts_payment_confirm_with_exact_amount(): void
    {
        $order = $this->createKioskCardOrder(50.00);

        $response = $this->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-OK-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 5000, // exact
        ]);

        $response->assertStatus(200);
        $this->assertEquals(PaymentStatus::PAID, $order->fresh()->payment_status);
    }

    /** @test */
    public function tolerates_one_cent_rounding_difference(): void
    {
        // Order total = 50.005€ (float arithmetic edge case)
        $order = $this->createKioskCardOrder(50.005);

        // Stored as 50.01 after round(2)
        $this->assertEquals(50.01, (float) $order->total);

        $response = $this->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-CENT-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 5000, // €50.00 — 1 cent off
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function rejects_payment_confirm_when_amount_cents_missing(): void
    {
        $order = $this->createKioskCardOrder(50.00);

        $response = $this->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-NOAMT-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            // amount_cents missing
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function rejects_payment_confirm_when_amount_cents_negative_or_zero(): void
    {
        $order = $this->createKioskCardOrder(50.00);

        foreach ([0, -1, -5000] as $invalid) {
            $response = $this->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
                'transaction_id' => 'TX-NEG-' . uniqid(),
                'card_type'      => 'VISA',
                'payment_method' => PaymentGateway::CARD,
                'amount_cents'   => $invalid,
            ]);
            $response->assertStatus(422);
        }
    }

    private function createKioskCardOrder(float $total): FrontendOrder
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $user->id, 'branch_id' => $branch->id]);
        $this->actingAs($user, 'sanctum');

        return FrontendOrder::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'total' => $total,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => PaymentGateway::CARD,
        ]);
    }
}
```

**Vérification rouge** :

```bash
./vendor/bin/phpunit tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php --testdox
# Attendu : 6 tests, plusieurs échecs (selon la rule manquante actuelle)
```

### Sub-task 3.3 — Implémentation backend (30 min)

**File:** [`app/Http/Controllers/Frontend/OrderController.php`](app/Http/Controllers/Frontend/OrderController.php)

**BEFORE (lignes 78-95) :**

```php
public function paymentConfirm(FrontendOrder $frontendOrder, \Illuminate\Http\Request $request): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
{
    try {
        $request->validate([
            'transaction_id' => ['required', 'string', 'max:255'],
            'card_type'      => ['nullable', 'string', 'max:50'],
            'payment_method' => ['nullable', 'integer'],
        ]);
        $authenticatedUserId = $request->user('sanctum')?->id
            ?? $request->user()?->id
            ?? Auth::id();

        if (!$authenticatedUserId) {
            return response(['status' => false, 'message' => 'Unauthenticated'], 401);
        }
        $authenticatedUserId = (int) $authenticatedUserId;
```

**AFTER :**

```php
public function paymentConfirm(FrontendOrder $frontendOrder, \Illuminate\Http\Request $request): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
{
    try {
        $request->validate([
            'transaction_id' => ['required', 'string', 'max:255'],
            'card_type'      => ['nullable', 'string', 'max:50'],
            'payment_method' => ['nullable', 'integer'],
            // [AUDIT-F-002] amount_cents echoed by TPE driver — MUST match order.total
            // (within ±1 cent tolerance for floating rounding artefacts).
            // Without this, a compromised TPE could approve any amount and the backend
            // would mark PAID without detecting the discrepancy. NF525 + PCI-DSS.
            'amount_cents'   => ['required', 'integer', 'min:1'],
        ]);

        // [AUDIT-F-002] Echo amount verification — gate before any state mutation.
        $expectedCents = (int) round($frontendOrder->total * 100);
        $providedCents = (int) $request->input('amount_cents');
        if (abs($providedCents - $expectedCents) > 1) {
            \Illuminate\Support\Facades\Log::warning('[Kiosk Payment] amount mismatch', [
                'order_id' => $frontendOrder->id,
                'expected_cents' => $expectedCents,
                'provided_cents' => $providedCents,
                'transaction_id' => $request->input('transaction_id'),
            ]);
            return response([
                'status' => false,
                'message' => 'Amount approved by TPE does not match order total',
                'error_code' => 'AMOUNT_ECHO_MISMATCH',
            ], 422);
        }

        $authenticatedUserId = $request->user('sanctum')?->id
            ?? $request->user()?->id
            ?? Auth::id();

        if (!$authenticatedUserId) {
            return response(['status' => false, 'message' => 'Unauthenticated'], 401);
        }
        $authenticatedUserId = (int) $authenticatedUserId;
```

### Sub-task 3.4 — Run backend test (5 min)

```bash
./vendor/bin/phpunit tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php --testdox
# Attendu : 6 tests verts
```

### Sub-task 3.5 — Frontend amount echo (30 min)

**File:** [`resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue)

**BEFORE (lignes 439-444) :**

```js
await this.confirmBackendPayment(this._lastOrder.id, {
  transaction_id: paymentResult.transaction_id,
  card_type:      paymentResult.card_type || 'CARD',
  payment_method: this.method === 'tr' ? 5 : 4,
});
```

**AFTER :**

```js
await this.confirmBackendPayment(this._lastOrder.id, {
  transaction_id: paymentResult.transaction_id,
  card_type:      paymentResult.card_type || 'CARD',
  payment_method: this.method === 'tr' ? 5 : 4,
  // [AUDIT-F-002] Send amount approved by TPE for backend echo verification.
  // Falls back to order total if bridge does not return amount_cents_approved
  // (legacy stub). Real TPE drivers (Ingenico/Verifone/ConCert) MUST echo.
  amount_cents:   paymentResult.amount_cents_approved
    ?? Math.round((this._lastOrder?.total ?? this.cartTotal) * 100),
});
```

**BEFORE (lignes 481-491) — `_invokeTpe` return shape :**

```js
const raw = result.data || result;
const approved =
  result.ok !== false &&
  (raw.status === 'approved' || raw.approved === true || !!raw.transaction_id || !!raw.tx_ref);
return {
  approved,
  transaction_id: raw.transaction_id || raw.tx_ref || result.tx_ref || null,
  card_type: raw.card_type || raw.cardType || 'CARD',
  error: !approved ? (raw.error || result.error || 'declined') : null,
  error_code: raw.error_code || result.error_code || null,
};
```

**AFTER :**

```js
const raw = result.data || result;
const approved =
  result.ok !== false &&
  (raw.status === 'approved' || raw.approved === true || !!raw.transaction_id || !!raw.tx_ref);
return {
  approved,
  transaction_id: raw.transaction_id || raw.tx_ref || result.tx_ref || null,
  card_type: raw.card_type || raw.cardType || 'CARD',
  // [AUDIT-F-002] Echo amount approved by TPE driver. Real EU-EMV protocols
  // (ConCert, Ingenico, Verifone) provide this. Fallback to amountCents
  // sent (mirror) if bridge returns nothing — caller MUST verify backend rejects
  // mismatched echoes.
  amount_cents_approved:
    raw.amount_cents_approved ?? raw.amountCentsApproved ?? raw.approved_amount_cents ?? null,
  error: !approved ? (raw.error || result.error || 'declined') : null,
  error_code: raw.error_code || result.error_code || null,
};
```

**BEFORE (stub lignes 466-469) :**

```js
if (!kioskHardware.isKioskBridge()) {
  this.tpeMessage = this.$t('kiosk.pay_screen.tpe_browser_sim');
  await new Promise((r) => setTimeout(r, 2000));
  return { approved: true, transaction_id: `STUB-${Date.now()}`, card_type: 'VISA' };
}
```

**AFTER :**

```js
if (!kioskHardware.isKioskBridge()) {
  this.tpeMessage = this.$t('kiosk.pay_screen.tpe_browser_sim');
  await new Promise((r) => setTimeout(r, 2000));
  return {
    approved: true,
    transaction_id: `STUB-${Date.now()}`,
    card_type: 'VISA',
    // [AUDIT-F-002] Stub echoes the requested amount (mirror) so dev/test stays green.
    amount_cents_approved: Math.round(Number(amountEuros) * 100),
  };
}
```

### Sub-task 3.6 — Bridge contract documentation (15 min)

**File:** [`resources/js/services/kioskHardware.js`](resources/js/services/kioskHardware.js)

Localiser la docstring de `tpeCharge` (ligne ~230) et enrichir :

```js
/**
 * [PHASE-6.1] Charge le TPE pour un montant donné.
 *
 * [AUDIT-F-002] CONTRACT — le retour DOIT inclure amount_cents_approved
 * pour que le backend puisse vérifier l'écho. Toute implémentation Electron
 * (drivers TPE Ingenico/Verifone/ConCert) DOIT respecter ce contrat sous peine
 * de rejet 422 'AMOUNT_ECHO_MISMATCH' au backend.
 *
 * @param {number} amountCents - Montant à charger en centimes (intégrer Math.round)
 * @param {string} method - 'CB', 'tr' (ticket restaurant)
 * @returns {Promise<{ok: boolean, tx_ref?: string, amount_cents_approved?: number, error?: string, error_code?: string}>}
 */
async tpeCharge(amountCents, method = 'CB') {
  // ... existing impl
}
```

### Sub-task 3.7 — Vue test extension (30 min)

**File:** `tests/js/KioskPayment.spec.js` (extend ou nouveau `KioskPaymentAmountEcho.spec.js`)

```js
import { mount } from '@vue/test-utils';
import KioskPaymentComponent from '@/components/frontend/kiosk/KioskPaymentComponent.vue';
import * as kioskHardware from '@/services/kioskHardware';

describe('Kiosk Payment — Amount Echo (F-002)', () => {
  it('sends amount_cents to backend on confirmBackendPayment', async () => {
    const axiosPostMock = jest.fn().mockResolvedValue({ data: { status: true } });
    window.axios = { post: axiosPostMock };

    const wrapper = mount(KioskPaymentComponent, {
      data() { return { _lastOrder: { id: 42, total: 50.00 }, cartTotal: 50.00 }; }
    });

    await wrapper.vm.confirmBackendPayment(42, {
      transaction_id: 'TX-TEST',
      card_type: 'VISA',
      payment_method: 4,
      amount_cents: 5000,
    });

    expect(axiosPostMock).toHaveBeenCalledWith(
      'frontend/order/42/payment-confirm',
      expect.objectContaining({ amount_cents: 5000 })
    );
  });

  it('stub returns amount_cents_approved as mirror of amountCents', async () => {
    jest.spyOn(kioskHardware, 'isKioskBridge').mockReturnValue(false);

    const wrapper = mount(KioskPaymentComponent);
    const result = await wrapper.vm._invokeTpe(50.00, 'CB');

    expect(result.approved).toBe(true);
    expect(result.amount_cents_approved).toBe(5000);
  });
});
```

### Sub-task 3.8 — Observability metric (15 min)

Ajouter au log `Log::warning` dans le controller (déjà fait au Sub-task 3.3). 

Documenter dans `docs/OBSERVABILITY.md` (section nouvelle) :

```markdown
## TPE Amount Echo Verification (F-002)

- **Metric** : `tpe.amount_mismatch_rejected{branch_id, error_code}`
- **Source** : log warning `[Kiosk Payment] amount mismatch` dans `OrderController::paymentConfirm`
- **Threshold** : > 0 → alert ops (potential TPE compromise ou bug client)
- **Dashboard** : ops/payment-integrity
```

---

## 4. TEST PLAN — Détaillé

### 4.1 Tests à écrire

| File | Cases | Type |
|---|---|---|
| `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php` | 6 cases | Feature, RefreshDatabase |
| `tests/js/KioskPaymentAmountEcho.spec.js` (ou extension) | 2 cases | Vue |

### 4.2 Suites à runner

```bash
./vendor/bin/phpunit tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php --testdox
./vendor/bin/phpunit tests/Feature/KioskFrontendComprehensiveTest.php
./vendor/bin/phpunit tests/Feature/KioskAuthTest.php
./vendor/bin/phpunit tests/Feature/Kiosk/  # full Kiosk feature
./vendor/bin/phpunit tests/Feature/Fiscal/  # F-001 dépendant — vérifier coexistence
npm run test -- KioskPayment
```

### 4.3 Critères de réussite

- [ ] 6 backend tests verts
- [ ] 2 vue tests verts
- [ ] 0 régression Kiosk
- [ ] 0 régression Fiscal (F-001 toujours OK)
- [ ] `npm run test` global vert
- [ ] `npm run lint` vert

---

## 5. EDGE CASES

| Cas | Comportement attendu |
|---|---|
| `amount_cents` parfaitement égal | Accepté |
| `amount_cents` ±1 cent | Accepté (tolerance) |
| `amount_cents` ±2 cents | Rejeté 422 |
| `amount_cents` = 0 | Rejeté 422 (validation) |
| `amount_cents` négatif | Rejeté 422 |
| `amount_cents` manquant | Rejeté 422 |
| `amount_cents` non-integer | Rejeté 422 |
| `amount_cents` énorme (1B) | Rejeté 422 (mismatch) |
| Order total = 0 (full loyalty redemption) | `amount_cents = 0` rejeté par `min:1` MAIS le flow ne devrait jamais appeler payment-confirm pour un order à 0€ ; vérifier la logique frontend |
| Retry payment-confirm avec amount mismatch sur 1er, correct sur 2e | 1er rejette 422, 2e accepte 200, allocation seq F-001 sur le 2e |

---

## 6. ROLLBACK PLAN

```bash
git revert <commit-hash>
./vendor/bin/phpunit tests/Feature/Kiosk/
npm run test
```

Le rollback est sûr car le changement est purement additif (validation + log) sans migration ni état persistant.

---

## 7. DEFINITION OF DONE

- [ ] Test rouge backend écrit AVANT le fix
- [ ] 6 cases backend verts après fix
- [ ] 2 cases vue verts
- [ ] Suite Kiosk complète verte
- [ ] Suite Fiscal complète verte (F-001 coexistence)
- [ ] `npm run test` vert
- [ ] `npm run lint` vert
- [ ] Documentation `docs/OBSERVABILITY.md` mise à jour
- [ ] Commit message : `audit(F-002): tpe amount echo verification on payment-confirm`
- [ ] PR ouverte avec template
- [ ] Rapport `REPORT_F002_tpe_amount_echo.md` produit
- [ ] Graphiti episode poussé

---

## 8. ACCEPTANCE CRITERIA

| # | Critère | Vérification |
|---|---|---|
| AC1 | Backend rejette amount_cents < total | Test `rejects_payment_confirm_when_amount_is_under_total` |
| AC2 | Backend rejette amount_cents > total | Test `rejects_payment_confirm_when_amount_is_over_total` |
| AC3 | Backend accepte amount_cents exact | Test `accepts_payment_confirm_with_exact_amount` |
| AC4 | Tolerance 1 cent | Test `tolerates_one_cent_rounding_difference` |
| AC5 | Backend rejette amount_cents manquant | Test `rejects_payment_confirm_when_amount_cents_missing` |
| AC6 | Frontend envoie amount_cents | Vue test `sends amount_cents to backend` |
| AC7 | Stub retourne amount_cents_approved mirror | Vue test `stub returns amount_cents_approved as mirror` |
| AC8 | Error code stable `AMOUNT_ECHO_MISMATCH` | Tests AC1+AC2 verifient `error_code` exact |

---

## 9. ANTI-DRIFT CHECKLIST

- [ ] Aucune modification frozen-zone (kiosk wizard, pos-wizard.js)
- [ ] Aucune modification de `KioskWizardComponent.vue`, `KioskCartComponent.vue`, etc.
- [ ] `KioskPaymentComponent.vue` modifié (pas dans la frozen wizard) — confirmé : Payment ≠ Wizard
- [ ] Aucun bypass de pricing SSOT
- [ ] Tolerance ±1 cent strictement respectée
- [ ] Error code `AMOUNT_ECHO_MISMATCH` non renommé

---

## 10. RISK REGISTER

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Real TPE driver ne retourne pas `amount_cents_approved` | Medium | High | Doc contract obligatoire ; fallback frontend = mirror du sent ; tester chaque driver d'intégration séparément |
| Faux positifs ±2 cents par arrondi cumulatif | Low | Medium | Tolerance 1 cent stricte ; si cumul empire, augmenter à 5 cents avec validation par seuil dynamique |
| Stub mirror cache un bug réel | Low | Low | Sub-plan F-014 (TPE QA toggle) permet forcer mismatch en dev pour exercer le path 422 |
| Regression Vue cart total ≠ backend total | Low | Medium | Test backend recalcule via SSOT ; frontend mirror ne fait que repasser le sent |

---

## 11. REPORTING

À pusher dans `reports/execution/audit_2026-05-07/REPORT_F002_tpe_amount_echo.md`.

---

## 12. GRAPHITI REFLECTION

```json
{
  "name": "F-002 closed: TPE amount echo verification",
  "group_id": "foodking",
  "source": "json",
  "episode_body": {
    "finding_id": "F-002",
    "severity": "P0",
    "status": "closed",
    "commit_hash": "<filled at ship>",
    "tests_added": 8,
    "files_modified": [
      "app/Http/Controllers/Frontend/OrderController.php",
      "resources/js/components/frontend/kiosk/KioskPaymentComponent.vue",
      "resources/js/services/kioskHardware.js",
      "tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php",
      "tests/js/KioskPaymentAmountEcho.spec.js",
      "docs/OBSERVABILITY.md"
    ],
    "invariant_enforced": "abs(amount_cents - order.total*100) <= 1 OR reject 422",
    "blocks_resolved": ["Real TPE rollout safety"],
    "audit_id": "ultra_review_2026-05-07"
  }
}
```

---

## 13. DISCOVERED

```
- [ ] À compléter par exécuteur si applicable
```
