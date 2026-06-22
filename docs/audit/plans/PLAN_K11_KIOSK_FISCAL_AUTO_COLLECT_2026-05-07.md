# PLAN_K11_KIOSK_FISCAL_AUTO_COLLECT — KR1 (F-VERIFY-08-K01)

**Date** : 2026-05-07
**Cycle parent** : KIOSK Audit (K0-K6)
**Finding** : KR1 — Kiosk direct TPE orders ne reçoivent **PAS** de `fiscal_sequence_no` automatiquement
**Statut** : 🟢 PRÊT À EXÉCUTER (override frozen-zone gate déjà cleared cycle 7)

---

## 1. Contexte & invariants

### 1.1 Le gap M-08 Option B
`FrontendOrderService::finalizePaidKioskOrder()` (frozen, ligne ~965-1038) marque l'order `payment_status=PAID + status=ACCEPT` après confirmation TPE kiosk, **MAIS n'alloue PAS de `fiscal_sequence_no`** (M-08 Option B documenté lignes 1007-1010). La séquence fiscale n'est allouée que lors d'un POS counter-collect manuel.

**Conséquence NF525** : si le client paie sur la borne avec carte (TPE direct) et part sans interaction POS :
- Order finalisée techniquement (status ACCEPT, payment PAID)
- **Mais `fiscal_sequence_no` reste null indéfiniment**
- Z-report aggregate ne couvre PAS cet order (filter `fiscal_sequence_no IS NOT NULL`)
- Trou fiscal silencieux

### 1.2 Décision architecturale

**OPTION RETENUE** : auto-allocation `fiscal_sequence_no` dans `finalizePaidKioskOrder()` AVANT le set status=ACCEPT, sous le même DB::transaction que la promotion d'état. Cette modification est **compatible avec l'invariant existant** (l'order entre dans Z courant, comme s'il avait été POS-collected immédiatement).

**ALTERNATIVE rejetée** : daily reconciliation cron job qui scanne les orders kiosk PAID sans fiscal_sequence_no et les seal en batch. Plus complexe + fenêtre de risque jusqu'à exécution.

### 1.3 Frozen-zones touchées
- `app/Services/FrontendOrderService.php::finalizePaidKioskOrder` — gate cleared par user 2026-05-06
- Aucune autre frozen-zone modifiée

---

## 2. Modifications

### 2.1 `FrontendOrderService::finalizePaidKioskOrder()`

**Squelette** (insertion juste avant `OrderStateMachine::recordTransition` PENDING→ACCEPT) :

```php
// [P-K11-FZH / KR1] Auto-allocate fiscal_sequence_no for kiosk direct TPE.
// Without this, kiosk-paid orders never enter Z aggregation (M-08 Option B
// gap). The allocation runs inside the same DB::transaction so a fail
// triggers full rollback.
if ($order->fiscal_sequence_no === null
    && in_array((int) $order->payment_status, [PaymentStatus::PAID], true)
    && config('fiscal.kiosk_auto_allocate_sequence', true)
) {
    try {
        $newSeq = app(FiscalSequenceService::class)->next((int) $order->branch_id);
        $order->fiscal_sequence_no = $newSeq;
        $order->save();

        Log::channel('fiscal')->info('kiosk.fiscal_sequence_auto_allocated', [
            'order_id'           => $order->id,
            'branch_id'          => $order->branch_id,
            'fiscal_sequence_no' => $newSeq,
            'payment_method'     => $order->payment_method,
            'source_surface'     => $order->source_surface,
        ]);
    } catch (\Throwable $e) {
        Log::channel('fiscal')->error('kiosk.fiscal_sequence_alloc_failed', [
            'order_id' => $order->id,
            'error'    => $e->getMessage(),
        ]);
        // Fail-loud: block order finalization if fiscal seal fails. NF525
        // compliance > UX (rare path; alert ops via fiscal channel).
        throw $e;
    }
}
```

### 2.2 `config/fiscal.php` — feature flag

```php
'kiosk_auto_allocate_sequence' => filter_var(
    env('FISCAL_KIOSK_AUTO_ALLOCATE_SEQUENCE', true),
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
) ?? true,
```

Default `true` car résout un trou fiscal critique. Override `FISCAL_KIOSK_AUTO_ALLOCATE_SEQUENCE=false` uniquement pour rollback urgence.

### 2.3 Tests

#### `tests/Feature/Kiosk/KioskFiscalAutoAllocateTest.php` (4 tests)

```php
// Test 1: Kiosk direct TPE order receives fiscal_sequence_no after finalize
// Test 2: Sequence is monotonic per branch (FiscalSequenceService::next)
// Test 3: Allocation rollback on transaction fail
// Test 4: Feature flag false → no allocation (legacy behavior)
```

#### `tests/Feature/Sentinels/KioskFiscalSealedSentinelTest.php` (1 test)

```php
// Sentinel: assert finalizePaidKioskOrder allocates fiscal_sequence_no
// when KIOSK_AUTO_ALLOCATE_SEQUENCE=true AND payment_status=PAID
```

---

## 3. Régression à protéger

- `tests/Feature/KioskPaymentStateMachineTest.php`
- `tests/Feature/PosCollectKioskCashRouteTest.php` (counter-collect path doit toujours fonctionner)
- `tests/Feature/Sentinels/PaymentConfirm*SentinelTest.php` (cycle 7A patches restent green)
- `tests/Feature/Fiscal/RefundPostZTest.php` (fiscal aggregation inchangée pour orders pré-existants)

---

## 4. Critères d'acceptation

- [ ] `KioskFiscalAutoAllocateTest` 4/4 PASS
- [ ] `KioskFiscalSealedSentinel` PASS
- [ ] Régression : 0 sur 302+ tests Kiosk baseline
- [ ] Régression : 0 sur 39+ sentinels POS (cycle 7C/7D baseline)
- [ ] Order kiosk direct TPE → `fiscal_sequence_no` non null après payment-confirm
- [ ] Z-report aggregate inclut désormais ces orders dans `total_ttc`
- [ ] Log fiscal channel `kiosk.fiscal_sequence_auto_allocated` émis

---

## 5. Risques + rollback

### 5.1 Risques
- **R1**: FiscalSequenceService cache lock contention si TPE kiosk + POS collect simultanés sur même branche → mitigation : la cache lock 5s couvre déjà ce cas, no-op
- **R2**: Si `fiscal_sequence_no` existe déjà (race) → la condition `=== null` court-circuite

### 5.2 Plan de rollback
- `FISCAL_KIOSK_AUTO_ALLOCATE_SEQUENCE=false` → désactive l'allocation, retour M-08 Option B legacy
- Aucune migration → rollback instantané sans schema change

---

## 6. Alternative documentée (V2 backlog)

Daily reconciliation cron job `php artisan fiscal:kiosk-reconcile` qui scanne `orders WHERE source_surface='kiosk' AND payment_status=PAID AND fiscal_sequence_no IS NULL AND created_at > NOW()-1day` et alloue les séquences en batch. Utile en complément si feature flag désactivé.

---

**Statut** : 🟢 Plan complet. Exécution recommandée à l'ouverture du cycle K7+ ou directement si décision user.
