# BYPASS-P0 — Cartographie Payment + Printing (2026-05-08)

> Cartographie thorough par agent Explore avant implémentation BYPASS-P1/P2/P3.
> Mission : valider parcours E2E POS → KDS → Outbox → refund SANS dépendance hardware.
> Invariants à PRÉSERVER : sealing fiscal NF525, Outbox events, audit log, refund miroir, idempotency.

## Découvertes clés

1. **Aucun call TPE direct** dans le code. Le kiosk reçoit `transaction_id` via Electron app (qui parle au TPE physique), puis API valide juste l'ID.
2. **`NullPrinterTransport` existe DÉJÀ** dans `app/Services/Hardware/PrinterTransport/` (utilisé en `testing` env). ServiceProvider sélectionne le transport selon env.
3. Pattern feature-flag existing très propre dans `config/fiscal.php` + `config/payment.php` (gates documentés).

## 1. Payment call sites

| File:Line | Method | Variant | Idempotency |
|---|---|---|---|
| `app/Services/PaymentService.php:123` | `confirmCounterPayment()` | cash, card, mobile_banking, ticket_restaurant | UNIQUE app-layer |
| `app/Services/PaymentService.php:70` | `cashBack()` | cash_back | N/A |
| `app/Services/PaymentService.php:26` | `payment()` | gateway_based (legacy) | N/A |
| `app/Http/Controllers/Frontend/OrderController.php:95` | `paymentConfirm()` | card, ticket_restaurant (kiosk Electron) | X-Idempotency-Key |
| `app/Http/Controllers/Admin/PosOrderController.php:47` | `refundWithCounterEntry()` | counter_entry post-Z | X-Idempotency-Key |
| `app/Services/FrontendOrderService.php:965` | `finalizePaidKioskOrder()` | promote PENDING→ACCEPT + auto fiscal_seq | dans transaction |
| `app/Services/OrderService.php:1884` | wrapper PaymentService::confirmCounterPayment | délègue | N/A |

## 2. Printing call sites

| File:Line | Method | Hardware Layer | Bypass Target |
|---|---|---|---|
| `app/Services/Hardware/EscPosPrinterService.php:16` | `sendRaw(Printer, bytes)` | PrinterTransportInterface | **transport abstraction** |
| `app/Services/Hardware/EscPosPrinterService.php:38` | `testPrint(Printer)` | sendRaw → transport | via transport |
| `app/Services/Hardware/EscPosPrinterService.php:73` | `openDrawer(printerId, branchId)` | sendRaw → transport | via transport |
| `app/Http/Controllers/Admin/PrinterController.php:77` | `testPrint(Printer)` | service::testPrint | via service |
| `app/Http/Controllers/Admin/Pos/CashDrawerController.php:28` | `open(Request, printerId)` | service::openDrawer | via service |
| `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php:35` | `increment(Request, order)` | AuditLogService::write | audit MUST run |

**Architecture transport printer** :
- `PrinterTransportInterface::send(bytes, config): bool` — abstraction
- `NullPrinterTransport` — déjà existant (testing env)
- `TcpPrinterTransport` — production (fsockopen)
- ServiceProvider binding `app/Providers/AppServiceProvider.php:30` — sélectionne selon env

## 3. Fiscal chain à PRÉSERVER (NE JAMAIS BYPASS)

| Service | Method | Purpose |
|---|---|---|
| `FiscalSequenceService` | `next(branchId): int` | Allocate monotonic fiscal_seq per branch |
| `AuditLogService` | `write(data): AuditLog` | HMAC-chained audit row |
| `FiscalChainValidator` | `assertChainIntegrity(branchId)` | Pre-Z verification |
| `FiscalSealingService` | `signZReport(...)` | HMAC-SHA256 sign Z payload |
| `ZReportService` | `verifyChain(branchId, strict)` | Re-walk Z chain, detect tampering |
| `RefundWithCounterEntryService` | `execute(...)` | Mirror order avec fresh fiscal_seq post-Z |

**Invariants critiques** :
- Cache::lock fiscal_seq_b{n} 5s — bloque allocation concurrente
- DB UNIQUE(branch_id, fiscal_sequence_no) — rejette duplicates
- Cache::lock audit_chain_b{n} 10s — sérialise writers per branch
- HMAC chain prev_hash + UNIQUE(branch_id, prev_hash) — anti-fork

## 4. Outbox events à PRÉSERVER

| Event | Listener | DomainEvent type |
|---|---|---|
| `OrderPaidAtCounter` | `PersistOrderPaidAtCounterToOutbox` | order.payment_confirmed |
| `OrderPaymentStatusChanged` | `PersistOrderPaymentStatusChangedToOutbox` | order.payment_status_changed |
| `OrderStatusChanged` | `PersistOrderStatusChangedToOutbox` | order.status_changed |

Pattern : `DB::afterCommit(fn() => DispatchDomainEventsJob::dispatch($id))` (à préserver intact).

## 5. Feature flags pattern existant

```php
// config/fiscal.php
'audit_secret' => env('FISCAL_AUDIT_SECRET', ''),
'kiosk_auto_allocate_sequence' => env('FISCAL_KIOSK_AUTO_ALLOCATE_SEQUENCE', true),

// config/payment.php
'web_payment_v1' => ['enabled' => false, 'gate' => 'GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25'],
'stripe.activation_guard' => ['enabled' => true, 'gate' => 'GATE_STRIPE_CENTS_ACTIVE_2026-04-25'],
```

## 6. Recommandations P1/P2/P3

### P1 (config flags + prod-guard)
- Étendre `config/payment.php` section `'bypass'` (DÉJÀ FAIT BLUE 2026-05-08)
- Créer `config/printing.php` section `'bypass'` (à faire)
- `.env.example` enrichi avec `PAYMENT_BYPASS_MODE` + `PRINTING_BYPASS_MODE`
- Sentinel `BypassProductionGuard` dans `AppServiceProvider::boot()` qui abort si `app()->environment('production')` && bypass actif

### P2 (bypass payment — minimaliste vu qu'il n'y a pas de call TPE direct)
- `OrderController::paymentConfirm` — court-circuit la validation `transaction_id` si bypass actif (accept any string commençant par `BYPASS-`)
- Garder TOUT le reste : FiscalSequenceService::next, OrderPaidAtCounter event, audit log
- Log structuré `[BYPASS-PAYMENT]` à chaque appel
- Côté frontend : `PaymentComponent` / `KioskPaymentComponent` génèrent `transaction_id = BYPASS-{uniqid}` au lieu d'attendre Electron

### P3 (bypass printing — TRIVIAL grâce à NullPrinterTransport existant)
- Étendre `AppServiceProvider::register()` ligne 30 :
```php
$this->app->bind(PrinterTransportInterface::class, function () {
    if ($this->app->environment('testing') || config('printing.bypass.enabled')) {
        return new NullPrinterTransport();
    }
    return new TcpPrinterTransport();
});
```
- Marqueur visible "🔧 MODE TEST — IMPRESSION BYPASSÉE" dans `ReceiptComponent.vue` quand flag actif
- Garder l'audit print count + duplicata marker

### Routes à protéger
```
POST /api/admin/pos                              # CreatePosOrder
POST /api/admin/pos-order/{order}/refund-with-counter-entry # NF525 refund
POST /api/frontend/order/{order}/payment-confirm # Kiosk TPE confirm
POST /api/admin/printer/{printer}/test-print
POST /api/admin/pos/cash-drawer/{printer}/open
POST /api/admin/pos-order/{order}/receipt/print
```

## 7. Critical checklist post-implémentation

- [ ] FiscalSequenceService::next jamais skipped
- [ ] AuditLogService::write jamais skipped
- [ ] OrderPaidAtCounter event toujours dispatché
- [ ] DomainEvent outbox row toujours créée
- [ ] Idempotency-Key handling identique en bypass
- [ ] Audit log 'order.counter_payment_confirmed' toujours appelé
- [ ] Receipt print count incrémenté en bypass
- [ ] Audit log 'pos.receipt.print' toujours appelé
- [ ] Duplicata marker count>=2 fonctionnel en bypass
- [ ] Aucun call TCP/IP printer
- [ ] Aucun call HTTP gateway (Stripe/PayPal)
- [ ] Garde-fou prod : abort si bypass=true en production
- [ ] Log [BYPASS-*] structuré toujours présent
