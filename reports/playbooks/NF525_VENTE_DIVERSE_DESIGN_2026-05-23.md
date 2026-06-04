# NF525 "Vente Diverse" — Design propre Day-1 transition · 2026-05-23

> **Author** : DESIGN AGENT BRAIN.8 (read-only, no code touched)
> **Scope** : Design propre pour ouvrir Le Cayenne pendant que le TPE
> bancaire principal n'est pas encore configuré (J0..J+5). Mode dégradé =
> Espèces + SumUp manuel (TPE séparé déjà branché) ; mode cible
> J+5 = + Carte CB intégrée (Worldline Valina) sans migration de données.
> **Source audit** : `CLAUDE.md` §8, `FiscalSequenceService.php`,
> `AuditLogService.php`, `PricingService.php`, `PaymentService.php`,
> `OrderStateMachine.php`, `PaymentComponent.vue` (frozen),
> `PosCounterCollectModal.vue` (Wave X X1, non-frozen).

---

## TL;DR

- Une feature **"Vente Diverse"** dédiée — pas de bricolage — qui réutilise
  100% des invariants NF525 existants (FiscalSequenceService, AuditLogService,
  composition_snapshot) via un service wrapper `QuickSaleService`.
- **Zero frozen-zone touch** : PaymentComponent.vue, PricingService.php,
  FiscalSequenceService.php, AuditLogService.php restent intacts.
- **~6h dev + 1h verify**, applicable maintenant via executor follow-up
  Phase B Wave X+1.

---

## §1 Le problème à résoudre

Owner Le Cayenne ouvre son resto. Pendant **3-5 jours** post-ouverture, le
TPE bancaire principal de la caisse n'est **pas encore activé** (config
bancaire Worldline en attente). Pour pouvoir ouvrir et encaisser quand
même, il utilisera :

| Méthode | État | Hardware |
|---------|------|----------|
| Espèces | OK Day-1 | tiroir-caisse (avec/sans simulation hardware flag) |
| SumUp manuel | OK Day-1 | TPE SumUp physique autonome (déjà configuré) |
| Carte CB intégrée | KO Day-1 → OK J+5 | TPE Worldline Valina (en cours) |

**Risque sans feature dédiée** :
1. Le caissier "bricole" en créant des items fantômes dans le catalogue,
   ce qui pollue les rapports et fausse les stats Z-report.
2. Pire — le caissier saute la création d'Order côté système et tape le
   montant directement sur SumUp/TPE physique sans laisser de trace
   → **NF525 trou dans la chain HMAC + perte de transactions** (prison time
   risk per CLAUDE.md §8).
3. Le caissier crée un Order via POS catalog en sélectionnant un item au
   prix le plus proche, ce qui crée une **distorsion comptable**
   (mauvais TVA, mauvais reporting article).

**Solution = feature "Vente Diverse" dédiée**, scope-minimal, qui passe
par tous les invariants NF525 existants sans en toucher un seul.

---

## §2 Architecture proposée

### 2.1 Catalogue item "Vente Diverse"

Une **nouvelle ligne dans `items`** avec un flag dédié `price_variable=true`
(NEW column nullable défault false). L'item lui-même reste un Item normal
au regard de Spatie/Eloquent — c'est uniquement le pricing path qui
dévie. Tous les autres flags Item conservent leur sémantique.

| Champ | Valeur |
|-------|--------|
| `name` | "Vente Diverse" |
| `item_category_id` | NEW catégorie "Spécial" (ou réutiliser une catégorie POS-only existante) |
| `slug` | `vente-diverse` |
| `price` | `0.00` (valeur sentinelle — overrideée par payload) |
| `price_variable` | `true` (NEW flag) |
| `tax_id` | TVA 10 % (FR restauration — résolu via TaxTableSeeder existant) |
| `item_type` | normal (pas de wizard) |
| `is_featured` | `false` (pas en première page POS, accessible via tab "Spécial") |
| `status` | ACTIVE |
| `channels` | `['pos']` UNIQUEMENT (jamais kiosk/web — la borne ne propose pas Vente Diverse) |
| `composer_profile` | `null` (pas de wizard) |
| `description` | "Vente libre — montant saisi au moment du paiement (caisse seulement)" |

> **Sécurité** : le flag `channels=['pos']` exclut automatiquement Vente
> Diverse de la borne (Kiosk respecte `isVisibleOn('kiosk')`) et du
> web (`isVisibleOn('web')`). Le sentinel
> `frontend/kiosk catalog` ne le verra jamais.

### 2.2 Workflow caisse POS — Quick Sale

```
┌───────────────┐    ┌────────────────────────┐    ┌─────────────────────────┐
│ Bouton "Vente │ →  │ PosQuickSaleModal.vue  │ →  │ POST /pos/quick-sale/   │
│ Diverse" POS  │    │ (NEW, sibling SSOT)    │    │   confirm               │
└───────────────┘    │ • numpad amount        │    │  body: amount, mode,    │
                     │ • 4 mode picker        │    │        note             │
                     │ • optional cashier note│    │  hdr: X-Idempotency-Key │
                     │ • PosV5Numpad shared   │    └─────────────────────────┘
                     └────────────────────────┘                │
                                                                ▼
                                       ┌──────────────────────────────────────┐
                                       │ QuickSaleController (NEW)            │
                                       │ • abort_unless can('pos')            │
                                       │ • Idempotency middleware applied     │
                                       │ • throttle:pos-order-update          │
                                       │ → QuickSaleService::sell()           │
                                       └──────────────────────────────────────┘
                                                       │
                                                       ▼
                                  ┌──────────────────────────────────────────┐
                                  │ QuickSaleService::sell()                 │
                                  │ DB::transaction wrap:                    │
                                  │   1. Order::create(branch, total=amount, │
                                  │      payment_status=PAID, status=PREPARED│
                                  │      surface='pos',                      │
                                  │      pos_payment_method=$mode)           │
                                  │   2. OrderItem::create with              │
                                  │      composition_snapshot JSON (frozen   │
                                  │      "Vente Diverse" structure)         │
                                  │   3. FiscalSequenceService::next(branch) │
                                  │      → Order->fiscal_sequence_no        │
                                  │   4. Transaction::create('payment')      │
                                  │   5. AuditLogService::write              │
                                  │      action='order.quick_sale_confirmed' │
                                  │      payload includes amount + mode      │
                                  │   6. PaymentService::recordCashOrder     │
                                  │      Movement (if CASH, strict=false)    │
                                  └──────────────────────────────────────────┘
                                                       │
                                                       ▼
                              ┌─────────────────────────────────────────────┐
                              │ Returns OrderDetailsResource → frontend     │
                              │ • Toast success (mirror Wave W copy)        │
                              │ • Modal close, ready for next sale          │
                              │ • Imprimante caisse trigger ticket          │
                              │   client (Wave A2 follow-up — same flow as  │
                              │   posOrderStore)                             │
                              └─────────────────────────────────────────────┘
```

### 2.3 Méthode picker (4 modes Day-1)

Mêmes 4 modes que `PosCounterCollectModal` Wave X X1 → réutilise l'enum
`PosPaymentMethod` :

| ID UI | PosPaymentMethod | Hardware Day-1 | Hardware J+5+ |
|-------|------------------|----------------|----------------|
| `CASH` | `CASH (1)` | tiroir-caisse | tiroir-caisse |
| `CARD` | `CARD (2)` | SumUp manuel (label sub : "Tapez sur SumUp puis confirmez") | TPE Worldline Valina intégré |
| `MOBILE` | `MOBILE_BANKING (3)` | (off — désactivé en option Day-1) | Worldline mobile |
| `TICKET` | `TICKET_RESTAURANT (4)` | accepté manuellement | accepté + Edenred future |

UX Day-1 : le bouton `CARD` affiche un sub-label dédié **"SumUp — tapez la
transaction sur le TPE puis confirmez ici"**. Le caissier confirme APRÈS
avoir effectivement validé la transaction sur le TPE physique. Mode
défensif : un texte d'aide en jaune **"Vérifiez le SumUp avant de
confirmer"** pour réduire le risque de fausse confirmation.

UX J+5+ : on remplace le sub-label par "TPE intégré — paiement automatique"
sans changer l'enum côté backend. **Pas de migration de données nécessaire.**

### 2.4 Z-report fin de journée

`ZReportService` agrège déjà les Orders par `pos_payment_method` et calcule
le total cash / card / mobile / ticket. Vente Diverse passe par le même
chemin :

- Les Orders Vente Diverse apparaissent dans la chain HMAC `z_reports`
  comme n'importe quel Order POS.
- Listing détaillé : un filtre `where('items.0.item_id', VENTE_DIVERSE_ID)`
  dans le ZReportService listing produit une section "Ventes Diverses"
  avec amount + heure + cashier.
- Chain HMAC continue (pas de break) — `verifyChain(branchId)` reste vert.

> **Pas de modification de ZReportService** nécessaire pour l'agrégation
> par méthode (déjà faite). Section "Ventes Diverses" detail = nice-to-have
> en V1.0.2 (l'agrégation par méthode est déjà suffisante pour le
> rapprochement caisse Day-1).

---

## §3 Diff code requis (scope-minimal)

### NEW files (8)

| Path | Purpose | LOC est. |
|------|---------|----------|
| `database/migrations/2026_05_23_100000_add_price_variable_to_items_table.php` | Ajoute `price_variable` boolean nullable defaut `false` | ~25 |
| `database/seeders/VenteDiverseSeeder.php` | Inserts 1 row catalogue "Vente Diverse" idempotent (`updateOrCreate`) | ~35 |
| `app/Http/Controllers/Admin/Pos/QuickSaleController.php` | Endpoint `POST /admin/pos/quick-sale/confirm` | ~60 |
| `app/Http/Requests/Pos/QuickSaleRequest.php` | Validation amount/mode/note + authz `can('pos')` | ~50 |
| `app/Services/Pos/QuickSaleService.php` | Orchestration NF525 (Order + OrderItem + Fiscal + Audit + Transaction) | ~180 |
| `resources/js/components/admin/pos/PosQuickSaleModal.vue` | UI modal sibling SSOT (mirror PosCounterCollectModal V5 atoms) | ~450 |
| `tests/Feature/Pos/QuickSaleControllerTest.php` | Tests integration : 4 modes × amount validation × NF525 chain | ~250 |
| `tests/js/sentinels/posQuickSaleModalSentinel.spec.js` | Vitest sentinel testids + emits stables | ~80 |

**Total NEW LOC** : ~1100 LOC test-included.

### Touched files (3, all ADDITIVE — no logic change to existing methods)

| Path | Change | Risk |
|------|--------|------|
| `routes/api.php` | Ajoute 1 route `Route::post('/quick-sale/confirm', ...)` à côté de counter-collect | LOW |
| `resources/js/components/admin/pos/PosComponent.vue` | Ajoute 1 bouton "Vente Diverse" + 1 import + 1 ref modal | LOW (PosComponent NON-frozen) |
| `database/seeders/DatabaseSeeder.php` | Ajoute 1 ligne `$this->call(VenteDiverseSeeder::class)` | LOW |

### NOT touched (frozen-zones — verified ZERO touch)

| Path | Why preserved |
|------|---------------|
| `app/Services/Fiscal/FiscalSequenceService.php` | Called as-is via `app(FiscalSequenceService::class)->next($branchId)` |
| `app/Services/Fiscal/AuditLogService.php` | Called as-is via `app(AuditLogService::class)->write([...])` |
| `app/Services/Fiscal/ZReportService.php` | Aggregation by `pos_payment_method` already covers Vente Diverse |
| `app/Services/Pricing/PricingService.php` | **NOT called** — QuickSaleService bypasses PricingService.calculateOrder() because amount is server-validated trusted-user input. See §3.1 below. |
| `resources/js/components/admin/pos/PaymentComponent.vue` | Sibling modal pattern (Wave X X1 precedent) — no mount of PaymentComponent |
| `app/Domain/Order/OrderStateMachine.php` | `apply()` called as-is from PaymentService for status transitions |
| `app/Http/Middleware/IdempotencyKeyMiddleware.php` | Route applies `idempotency` middleware as-is |
| `public/js/pos-wizard.js` | POS Vanilla wizard never mounted for Vente Diverse |

### §3.1 Critical — Why PricingService is bypassed (and how that stays NF525-safe)

`PricingService::calculateOrder()` does three things : tax math, options
validation, composition_snapshot building. Vente Diverse has **no options**
(no variations, no extras, no addons, no wizard step). Calling
calculateOrder() with a 0€ item + a "tax override" requirement would
force a **modification of PricingService logic** (which is FROZEN).

Instead, `QuickSaleService` does the equivalent inline, server-side
(NEVER trusts client) :

```php
// QuickSaleService.php (pseudo-code) — NF525 invariants preserved
$venteDiverseItem = Item::where('slug', 'vente-diverse')
    ->where('price_variable', true)
    ->firstOrFail();

$tax = Tax::find($venteDiverseItem->tax_id);
$taxRate = (float) $tax->tax_rate;        // 10.0 for FR resto
$taxType = (int) $tax->type;               // PERCENT (TaxType::PERCENT)

// Server-validated amount input (request->amount, range-checked by FormRequest)
$amount = round((float) $request->amount, 2);

// Recompute tax from TTC amount (identical to PricingService TTC path)
$taxAmount = round($amount * ($taxRate / (100 + $taxRate)), 2);

$compositionSnapshot = [
    'kind' => 'vente_diverse',          // NEW canonical kind for ZReport listing
    'label' => 'Vente diverse',
    'amount_ttc' => $amount,
    'tax_amount' => $taxAmount,
    'tax_rate' => $taxRate,
    'cashier_note' => $request->note,
    'mode' => $request->mode,
    'snapshot_version' => 1,
];

$order = Order::create([
    'branch_id' => $branchId,
    'user_id' => null,                   // anonyme — pas de customer attaché
    'order_type' => OrderType::POS,
    'order_datetime' => now(),
    'status' => OrderStatus::PREPARED,  // pas de cuisine — vente directe
    'payment_status' => PaymentStatus::PAID,
    'payment_method' => PaymentGateway::CASH_ON_DELIVERY,  // POS path
    'pos_payment_method' => $request->mode,
    'pos_received_amount' => $request->mode === PosPaymentMethod::CASH
        ? $request->amount : null,
    'pos_payment_note' => $request->note,
    'source_surface' => 'pos',
    'subtotal' => $amount - $taxAmount,
    'total_tax' => $taxAmount,
    'total' => $amount,
    'discount' => 0,
    'delivery_charge' => 0,
    'idempotency_key' => $request->header('X-Idempotency-Key'),
]);

OrderItem::create([
    'order_id' => $order->id,
    'branch_id' => $branchId,
    'item_id' => $venteDiverseItem->id,
    'quantity' => 1,
    'discount' => 0,
    'tax_name' => $tax->name,
    'tax_rate' => $taxRate,
    'tax_type' => $taxType,
    'tax_amount' => $taxAmount,
    'price' => $amount,                   // server-validated input
    'item_variations' => json_encode([]),
    'item_extras' => json_encode([]),
    'composition_snapshot' => json_encode($compositionSnapshot),
    'item_variation_total' => 0,
    'item_extra_total' => 0,
    'total_price' => $amount,
]);

$order->fiscal_sequence_no = app(FiscalSequenceService::class)->next($branchId);
$order->save();

Transaction::create([
    'order_id' => $order->id,
    'transaction_no' => 'QS-' . $order->id . '-' . now()->format('YmdHis'),
    'amount' => $amount,
    'payment_method' => $this->paymentMethodLabel($request->mode),
    'sign' => '+',
    'type' => 'payment',
]);

app(AuditLogService::class)->write([
    'branch_id' => $branchId,
    'user_id' => Auth::id(),
    'action' => 'order.quick_sale_confirmed',
    'resource' => 'order',
    'resource_id' => $order->id,
    'payload' => [
        'amount' => $amount,
        'tax_amount' => $taxAmount,
        'mode' => $request->mode,
        'fiscal_sequence_no' => $order->fiscal_sequence_no,
        'cashier_note' => $request->note,
        'kind' => 'vente_diverse',
    ],
]);

if ($request->mode === PosPaymentMethod::CASH) {
    app(PaymentService::class)->recordCashOrderMovement($order, $request->note);
}
```

**Why this is NF525-safe** :
- Tax math matches PricingService TTC formula exactly (lineTaxAmountFromTTC).
- composition_snapshot is JSON frozen at creation, never re-written.
- fiscal_sequence_no allocated via FiscalSequenceService (FROZEN) — chain stays monotonic.
- audit_logs HMAC chain entry written via AuditLogService (FROZEN) — chain stays valid.
- Transaction row created idempotent via firstOrCreate pattern (mirror PaymentService::confirmCounterPayment L296-307).
- Idempotency middleware on the route prevents double-tap duplicate orders.
- Cash drawer movement recorded best-effort if open session exists.

---

## §4 NF525 compliance verification

| Invariant | Status | Verification |
|-----------|--------|--------------|
| audit_logs HMAC chain entry created | OK | `AuditLogService::write()` called with action `order.quick_sale_confirmed` + payload includes amount + mode + fiscal_sequence_no |
| fiscal_sequence_no allocation monotonic | OK | `FiscalSequenceService::next(branchId)` called inside the DB::transaction envelope |
| composition_snapshot JSON frozen | OK | Created at Order/OrderItem creation, `kind='vente_diverse'` + `snapshot_version=1` + amount + tax + cashier_note + mode all captured |
| 6 ans rétention | OK | audit_logs table is append-only via DB trigger (BEFORE DELETE SIGNAL '45000') |
| Append-only | OK | NEVER UPDATE / NEVER DELETE on audit_logs / Order rows (soft delete blocked by Order::restoring throw — Z6-P1-WGS comment FiscalSequenceService:88) |
| price_variable trust boundary | OK | Server validates amount range 0.01-9999.99 via FormRequest, never trusts client amount blindly |
| Branch isolation | OK | `branch_id` resolved from `auth()->user()->branch_id` (admin can specify, cashier locked to own branch — mirror posOrderStore L656-664 pattern) |
| Idempotency double-tap | OK | `X-Idempotency-Key` middleware on route + Cache::lock preventive on QuickSaleService (mirror posOrderStore L617-637) |

> **Chain verification post-deployment** : run
> `php artisan tinker --execute='echo app(\App\Services\Fiscal\AuditLogService::class)->verifyChain(1) ?? "OK";'`
> on each branch after a fresh Vente Diverse → expect `OK` (null return).

---

## §5 Transition vers TPE bancaire (J+3-5)

Day-1 → J+5 = **purement additive** sur l'enum existante `PosPaymentMethod`.
Le pattern Wave X X1 PosCounterCollectModal a déjà validé les 4 modes :

| J0..J+5 (Day-1) | J+5..J+30 (TPE intégré) | Migration |
|-----------------|--------------------------|-----------|
| `CASH` (espèces tiroir) | `CASH` (idem) | aucune |
| `CARD` (SumUp manuel) | `CARD` (TPE Valina intégré, paiement auto) | UI sub-label "TPE intégré" change ; backend identique |
| `MOBILE` (off) | `MOBILE` (Worldline mobile) | activé par owner toggle |
| `TICKET` (manuel) | `TICKET` (Edenred futur intégré) | optionnel |

**Pas de migration de données** parce que :
- L'enum `PosPaymentMethod::CARD` ne distingue pas SumUp vs Valina au
  niveau base — c'est juste une "carte". Le ticket d'achat et le
  ZReport regroupent toutes les ventes carte sans distinction de
  hardware.
- Le `cashier_note` capture la précision (e.g. "SumUp J0..J5" /
  "Valina J5+") pour la traçabilité ops mais n'a pas d'impact fiscal.
- Les Orders Day-1 restent valides post-J+5 — `verifyChain()` reste vert.

**Le bouton "Vente Diverse" reste valide post-J+5** pour les vraies
ventes ad-hoc (ventes non-catalogue, dépannage, événements) — c'est la
raison d'être normale de la feature, indépendamment du Day-1.

---

## §6 Tests à ajouter

### Backend (PHPUnit)

`tests/Feature/Pos/QuickSaleControllerTest.php` :

1. `test_cashier_can_create_quick_sale_cash` — POST avec mode=CASH amount=12.50 → 200 + Order PAID + Transaction + audit_log
2. `test_cashier_can_create_quick_sale_card_sumup_manual` — mode=CARD → 200 + audit_log entry has mode=CARD
3. `test_cashier_can_create_quick_sale_ticket_resto` — mode=TICKET → 200
4. `test_invalid_amount_rejected_negative` — amount=-1 → 422
5. `test_invalid_amount_rejected_zero` — amount=0 → 422
6. `test_invalid_amount_rejected_over_max` — amount=10001 → 422
7. `test_cashier_locked_to_own_branch` — cashier tries branch_id≠own → 403
8. `test_admin_can_specify_branch` — Admin (branch_id=0) → 200
9. `test_unauthorized_user_blocked` — user without `can('pos')` → 403
10. `test_idempotency_key_replays_existing_order` — double-POST same key → returns same order_id
11. `test_nf525_chain_remains_valid_after_quick_sale` — verifyChain returns null post-sale
12. `test_fiscal_sequence_monotonic_across_quick_sales` — 3 sales → seq 1, 2, 3 monotonic
13. `test_composition_snapshot_frozen_post_sale` — UPDATE attempt blocked by snapshot immutability rule

### Sentinel Vue (Vitest)

`tests/js/sentinels/posQuickSaleModalSentinel.spec.js` :

- testids stables : `pos-quick-sale-modal`, `pos-quick-sale-amount-input`,
  `pos-quick-sale-mode-{CASH|CARD|MOBILE|TICKET}`, `pos-quick-sale-confirm`,
  `pos-quick-sale-cancel`
- emits stables : `confirmed`, `cancel`
- props minimum requis

### E2E Playwright (optional Wave X+1)

- workflow complet : POS catalog → click "Vente Diverse" → modal opens →
  numpad 12.50 → CASH → Confirmer → toast success → modal closes
- second sale identique → fiscal_sequence_no incremented

---

## §7 Effort estimé

| Phase | Tâche | Durée |
|-------|-------|-------|
| Backend | Migration + seeder | 30 min |
| Backend | QuickSaleController + QuickSaleRequest + Route | 1 h |
| Backend | QuickSaleService (NF525 orchestration) | 1 h 30 |
| Frontend | PosQuickSaleModal.vue (sibling SSOT) | 1 h 30 |
| Frontend | Bouton "Vente Diverse" + wiring dans PosComponent.vue | 30 min |
| Tests | QuickSaleControllerTest PHPUnit (13 cases) | 1 h |
| Tests | Sentinel Vitest | 30 min |
| Verify | Chain HMAC + bundle build + manual smoke | 30 min |
| **Total** | scope-minimal end-to-end | **~7 h** |

---

## §8 Risk analysis

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Implementation drift (touch frozen file by mistake) | LOW | HIGH | Pattern Wave X X1 PosCounterCollectModal explicitement validé sibling. Frozen-zone sentinel test catches drift. |
| NF525 chain break post-Vente Diverse | LOW | CRITICAL | verifyChain test included. AuditLogService called as-is. |
| Transition J+5 TPE intégré casse les Orders Day-1 | LOW | MEDIUM | Additive only — enum CARD intact, cashier_note traces precise hardware. verifyChain reste vert sur les historic Orders. |
| Owner trust : feature manuelle abusée par employés | MEDIUM | MEDIUM | RECOMMENDED: weekly log review via simple admin dashboard widget "Ventes diverses 7j" + threshold alert si >X par jour. Listé V1.0.2 nice-to-have. |
| Double-tap crée 2 Orders | LOW | MEDIUM | Idempotency middleware + Cache::lock (mirror posOrderStore pattern). Idempotency key formula = `quick-sale-{userId}-{minuteBucket}` from frontend. |
| Tax math drift PricingService vs QuickSaleService | LOW | HIGH | Tax formula explicit in QuickSaleService docblock cites PricingService.php:251-263 TTC path. Sentinel test compares both. |
| Cashier types wrong amount before SumUp confirmation | MEDIUM | LOW | UX hint jaune "Vérifiez SumUp avant de confirmer" + confirmation step before submit. Z-report rapprochement caisse catches eventual mismatch end-of-day. |

---

## §9 Recommandation finale

**APPLY** — scope-minimal, NF525-safe, transition Day-1 → J+5 propre,
pattern Wave X X1 explicitement réutilisé (sibling SSOT modal).

**Plan d'execution** suggéré pour l'orchestrator :
1. Commit séparé migration + seeder (BACKEND foundations)
2. Commit séparé controller + service + tests (BACKEND logic)
3. Commit séparé Vue modal + sentinel + button wiring (FRONTEND)
4. Commit final verify : `php artisan migrate` + `vendor/bin/phpunit --filter=QuickSale` + `npm run dev` + manual smoke 3 ventes (CASH + CARD + TICKET) + verifyChain check.

**Owner gate** suggéré : avant push to main, owner verify 3 ventes manuelles
sur la caisse réelle Day-1 (5 min) + verifyChain command output OK.

**Differable parts (V1.0.2)** :
- Section "Ventes Diverses" détail dans ZReport listing (l'agrégation par
  méthode est déjà suffisante pour le rapprochement caisse Day-1).
- Admin dashboard widget "Ventes diverses 7j" + threshold alert.

**Non-differable Day-1** : tout le reste — sans QuickSaleService, le owner
risque le bricolage + trou NF525.

---

*— DESIGN AGENT BRAIN.8, read-only audit complete, 0 file modified.*
