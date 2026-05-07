# BYPASS MODE — Operational Runbook

> **Gates** : `GATE_BYPASS_MODE_2026-05-08` (payment) + `GATE_BYPASS_PRINTING_2026-05-08` (printing)
> **Audience** : Dev local, staging E2E, QA validators. **JAMAIS production**.
> **Date** : 2026-05-08

## 1. À quoi sert ce mode

Permet de valider le **parcours complet** FoodKing V1 (POS → KDS → Outbox → refund) **sans dépendre du hardware** :
- TPE physique (driver Electron + terminal bancaire)
- Imprimante thermique (TCP/IP ESC/POS)

Objectif : valider la chaîne logique métier en E2E **avant** de brancher les vrais drivers hardware en cycle ultérieur. Quand on rebranche TPE + printer en prod, **zéro surprise** car la chaîne d'événements/audit/fiscal est déjà validée par tests E2E.

## 2. Invariants critiques PRÉSERVÉS (NE JAMAIS contourner)

Même en bypass mode, les invariants suivants **DOIVENT** rester actifs :

| Invariant | Service | Fichier source | Sentinel |
|---|---|---|---|
| Sealing fiscal NF525 | `FiscalSequenceService::next()` | `app/Services/Fiscal/FiscalSequenceService.php` | `tests/Feature/Sentinels/BypassPaymentInvariantsTest.php` |
| Outbox event `OrderPaidAtCounter` | `PaymentService::confirmCounterPayment()` | `app/Services/PaymentService.php:123` | sentinel idem |
| Outbox event `OrderStatusChanged` | `OrderStateMachine::recordTransition()` | `app/Services/Order/OrderStateMachine.php` | existant |
| Audit log HMAC chain | `AuditLogService::write()` | `app/Services/Fiscal/AuditLogService.php` | existant |
| Refund miroir post-Z | `RefundWithCounterEntryService::execute()` | `app/Services/Order/RefundWithCounterEntryService.php` | existant |
| Idempotency middleware | `IdempotencyKeyMiddleware::handle()` | `app/Http/Middleware/IdempotencyKeyMiddleware.php` | existant |

## 3. Ce qui est short-circuité

| Element | Mode normal | Mode bypass |
|---|---|---|
| Kiosk TPE call | App Electron → TPE physique | Stub navigateur `STUB-{Date.now()}` (déjà existant `KioskPaymentComponent.vue:566`) |
| POS counter cash/card | Manuel (pas de TPE call) | Identique (pas de TPE call à bypasser) |
| Printer ESC/POS bytes | `TcpPrinterTransport` → fsockopen | `NullPrinterTransport` (silently swallow) |
| Receipt screen render | Affichage normal | Marqueur "🔧 MODE TEST — IMPRESSION BYPASSÉE" en haut |
| Audit log `[BYPASS-PAYMENT]` | Aucun log spécifique | `Log::warning('[BYPASS-PAYMENT]')` à chaque appel |

## 4. Activation locale (dev/staging seulement)

### Étape 1 — Définir les flags dans `.env`

```bash
# .env (local ou staging)
PAYMENT_BYPASS_MODE=true
PRINTING_BYPASS_MODE=true
# Optionnel:
PRINTING_BYPASS_SCREEN_MARKER="🔧 MODE TEST — IMPRESSION BYPASSÉE"
```

### Étape 2 — Vider la config cache (si applicable)

```bash
php artisan config:clear
php artisan cache:clear
```

### Étape 3 — Recompiler les assets frontend (master.blade.php injecte le flag dans `window.foodkingConfig`)

```bash
npm run dev -- --build
```

### Étape 4 — Vérifier l'activation

```bash
# Backend
php artisan tinker --execute="echo config('payment.bypass.enabled') . PHP_EOL; echo config('printing.bypass.enabled') . PHP_EOL;"
# → true
# → true

# Frontend (browser console)
window.foodkingConfig.bypassMode
# → { payment: true, printing: true, printingScreenMarker: "🔧 MODE TEST — IMPRESSION BYPASSÉE" }
```

### Étape 5 — Tester le parcours

1. Login POS (`http://localhost:8000/login`)
2. Créer une commande POS (catalogue → wizard → cart → pay)
3. Cliquer "Confirm Counter Payment" — doit fonctionner sans TPE physique
4. Le ticket s'affiche à l'écran avec le marqueur orange "🔧 MODE TEST"
5. Vérifier `domain_events` : `OrderPaidAtCounter` row créée
6. Vérifier `audit_logs` : row `order.counter_payment_confirmed` créée
7. Vérifier `orders.fiscal_sequence_no` : incrémenté monotone
8. Vérifier KDS reçoit le ticket via Pusher (ou polling 5s fallback)
9. Tester refund miroir → row `RETURNED` créée avec fresh fiscal_seq

## 5. Désactivation (rebasculement vers vrai hardware)

### Étape 1 — Mettre les flags à false dans `.env`

```bash
PAYMENT_BYPASS_MODE=false
PRINTING_BYPASS_MODE=false
```

### Étape 2 — Vider config cache + rebuild

```bash
php artisan config:clear && npm run dev -- --build
```

### Étape 3 — Vérifier désactivation

```bash
php artisan tinker --execute="var_dump(config('payment.bypass.enabled'), config('printing.bypass.enabled'));"
# → bool(false), bool(false)
```

Browser console :
```js
window.foodkingConfig.bypassMode
// → { payment: false, printing: false, printingScreenMarker: "..." }
```

### Étape 4 — Brancher le vrai hardware

- TPE : connecter app Electron + driver TPE bancaire (config réseau du terminal)
- Imprimante thermique : configurer host/port (cf. `config/printer.php` si existant) + tester via `POST /api/admin/printer/{printer}/test-print`

## 6. Garde-fou production

`AppServiceProvider::boot()` lève `RuntimeException` au démarrage de l'application si :
- `APP_ENV=production` ET `PAYMENT_BYPASS_MODE=true` → exception
- `APP_ENV=production` ET `PRINTING_BYPASS_MODE=true` → exception

Test de validation : `tests/Feature/Sentinels/BypassProductionGuardTest.php` (5/5 PASS).

**Conséquence** : si quelqu'un déploie accidentellement le `.env` local en prod avec ces flags `true`, l'app refuse de booter. **Aucun risque silencieux**.

## 7. Audit trail

Chaque appel en bypass mode log un warning structuré :

```
[BYPASS-PAYMENT] TPE call short-circuited — bypass mode active
  context: {
    "gate": "GATE_BYPASS_MODE_2026-05-08",
    "env": "local",
    "timestamp": "2026-05-08T13:24:00+02:00",
    "controller": "Frontend\\OrderController::paymentConfirm",
    "order_id": 1234,
    "transaction_id": "STUB-1715170000000"
  }
```

Pour récupérer tous les bypass-related logs :
```bash
grep "\[BYPASS-" storage/logs/laravel.log | tail -50
```

## 8. Tests de régression

### PHPUnit
```bash
php artisan test --filter=BypassProductionGuardTest        # 5/5 PASS
php artisan test --filter=BypassPaymentInvariantsTest      # 11/11 PASS
```

### Vitest
```bash
npx vitest run tests/js/sentinels/bypassPrintingMarkerHiddenPrint.spec.js   # 5/5 PASS
```

### Playwright (E2E)
```bash
npx playwright test tests/e2e/bypass-mode-end-to-end-flow-2026-05-08.spec.js --workers=1
```

## 9. FAQ

**Q1 : Le mode bypass casse-t-il NF525 ?**
Non. Le sealing fiscal (`FiscalSequenceService::next` + HMAC chain) est exécuté de la même manière. Seul le hardware TPE et le spool d'impression sont court-circuités. Le ticket à l'écran porte le marqueur "MODE TEST" pour que personne ne le confonde avec un ticket réel.

**Q2 : Et si Pusher tombe en bypass mode ?**
Le polling fallback HTTP reste actif. Le KDS recevra le ticket via polling 5s (cf. `KitchenDisplaySystemComponent.vue` `_pollingFallback`).

**Q3 : Comment tester le refund miroir post-Z en bypass ?**
1. Activer bypass + générer une commande PAID + fermer Z (POST `/admin/fiscal/z/close`)
2. POST `/api/admin/pos-order/{order}/refund-with-counter-entry` avec X-Idempotency-Key
3. Vérifier qu'une nouvelle row `RETURNED` est créée avec son propre `fiscal_sequence_no` (mirror-entry)
4. Vérifier `audit_logs` : action `order.refund.counter_entry`

**Q4 : Le marqueur "MODE TEST" peut-il s'imprimer accidentellement ?**
Non. Le marqueur est dans la div `class="hidden-print"` qui a un `@media print { display: none; }`. Sentinel `bypassPrintingMarkerHiddenPrint.spec.js` vérifie cette invariant.

**Q5 : Que faire si je veux activer juste un des deux flags ?**
Possible. Les flags sont indépendants. Ex :
- `PAYMENT_BYPASS_MODE=true PRINTING_BYPASS_MODE=false` : valider le parcours payment sans TPE physique mais en imprimant pour de vrai
- `PAYMENT_BYPASS_MODE=false PRINTING_BYPASS_MODE=true` : valider l'impression à l'écran sans toucher l'imprimante (utile si printer down)

## 10. Procédure rebasculement hardware (cycle V1.x post-validation)

Ordre recommandé :

1. **Démarrer cycle `CV1-TPE-DRIVER-001`** : intégration driver Electron + paramétrage terminal bancaire + test transactionnel
2. **Démarrer cycle `CV1-PRINTER-DRIVER-001`** : configuration imprimante thermique réseau + test ESC/POS print
3. **Désactiver bypass** : `.env` → false + config:clear + rebuild
4. **Test E2E avec hardware réel** : créer commande payment réel + vérifier ticket imprimé + KDS reçoit
5. **Test garde-fou prod** : déployer en staging avec `APP_ENV=production` + bypass flags accidentellement true → app doit refuser de booter (`RuntimeException`)
6. **Final** : tag V1 release + commit `[BYPASS-DECOMM] Bypass mode disabled, hardware integration validated`

## 11. Références

- Cartographie initiale : `docs/audit/BYPASS_MAPPING_2026-05-08.md`
- Méthodologie GSTACK : `docs/methodology/GSTACK_PIPELINE_2026-05-08.md`
- Memory feedback : `feedback_orchestrator_inline_edit_exception.md`, `feedback_gstack_pipeline_methodology.md`
- Plans V1.x liés : `CV1-POS-AVAILABILITY-LIVE-001`, `CV1-CI-WEBSOCKETS-HARNESS-001`, `CV1-OBSERVABILITY-OUTBOX-001`
