# V4 — CAISSE : tiroir + fiscal inline + remboursement/annulation

Cible : cash-drawer (open/close/reconcile), refund, change-payment-status, discount.
HEAD 61e9ea7b7 + working-tree. Read-only, aucune écriture DB. `fiscal:verify-chain --all` = CHAIN OK avant/après (aucun write).

## VERDICT : BROKEN — 1 × P1 (NF525 fiscal/cash-trail bypass via change-payment-status)

---

## P1 — change-payment-status flippe PENDING_COUNTER/UNPAID → PAID SANS fiscal_sequence_no NI cash_movement

**Fichiers**
- Route : `routes/api.php:1007` `POST /api/admin/pos-order/change-payment-status/{order}` (middleware `permission:pos-orders` via `PosOrderController::__construct` `app/Http/Controllers/Admin/PosOrderController.php:28-36`).
- Contrôleur : `PosOrderController::changePaymentStatus` `app/Http/Controllers/Admin/PosOrderController.php:348-357` — aucune garde autre que le groupe.
- Requête : `app/Http/Requests/PaymentStatusRequest.php:19` autorise `Admin|Branch Manager|POS Operator` ; :33-41 whitelist inclut `PAID`.
- Service : `app/Services/OrderService.php:2301-2482` — sur transition vers PAID : `payment_status=PAID` + ActionLog + AuditLog + event `OrderPaymentStatusChanged`, **JAMAIS** `FiscalSequenceService::next()` ni `CashDrawerService::recordMovement()`.
- Machine d'état : `app/Domain/Order/PaymentStateMachine.php:13-16` — `PENDING_COUNTER => [PAID, REFUNDED]`, `UNPAID => [PAID]` : transition LÉGALE.
- Chemin canonique (contraste) : `app/Services/PaymentService.php:335-336` alloue `fiscal_sequence_no`, `:541` écrit le `cash_movement` (TYPE_ORDER_PAYMENT / DIRECTION_IN). Le chemin change-payment-status ne fait NI l'un NI l'autre.

**Repro LIVE (lecture seule, verify-before-report)**
- `PaymentStateMachine::canTransition(15,5)` = `true` ; `canTransition(10,5)` = `true` (tinker).
- Rôle `POS Operator` → `hasPermissionTo('pos-orders')` = `true` (tinker) → passe le middleware ET `PaymentStatusRequest::authorize()`.
- Seul listener de `OrderPaymentStatusChanged` = `PersistOrderPaymentStatusChangedToOutbox` (EventServiceProvider:189-191) → outbox/broadcast uniquement, aucune allocation fiscale.
- Aggrégat Z-report : `app/Services/Fiscal/ZReportService.php:339-341` `whereNotNull('fiscal_sequence_no')` → la commande PAID orpheline est **EXCLUE du CA fiscal**.
- Retry cron : `app/Console/Commands/RetryFiscalAllocCommand.php:66-69` ne rattrape QUE `whereNotNull('fiscal_alloc_error_at')`. change-payment-status ne pose jamais ce flag → **orphelin PERMANENT**, jamais ré-alloué.
- Filet existant = observabilité seule : `ZReportService.php:611-635` LOG un warning `orphan_paid_orders_in_window` mais **ne bloque pas la clôture** (best-effort).
- État DB actuel : 9 commandes `PAID` avec `fiscal_sequence_no=null` (toutes seed delivery/online type=5 + 1 type=10 ; pas de PENDING_COUNTER courant à flipper sans write, donc repro par tracé de code + légalité transition confirmée tinker).

**Impact** — Un opérateur caisse (POS Operator, rôle standard) peut, depuis l'écran détail commande (`PosOrderShowComponent.vue:143` bouton statut-paiement) OU par appel API direct, marquer une commande BORNE (PENDING_COUNTER, Plan B) — ou toute UNPAID — en PAID sans passer par l'encaissement (`counter-collect/confirm` → `confirmCounterPayment`). Résultat : vente PAID **hors chaîne fiscale NF525** (aucune séquence, exclue du Z), **hors trail de caisse** (aucun cash_movement, invisible à la réconciliation). Revenu off-book permanent = exactement la fraude que NF525 interdit. Distinct du heal V2 (`cash_movement gate !deferToCounter` dans confirmCounterPayment — ne couvre PAS ce chemin alternatif).

**Fix suggéré (owner-gate NF525)** — soit `changePaymentStatus` route vers `PaymentService::confirmCounterPayment` quand target=PAID (alloue fiscal + cash_movement), soit `PaymentStateMachine` interdit `PENDING_COUNTER→PAID` / `UNPAID→PAID` par ce chemin générique (force l'encaissement). NON appliqué (touche zone fiscale + state machine = gate owner).

---

## Angles tenus VERTS (attaques tentées, réfutées)

- **Double refund pré-Z (RETURNED→RETURNED)** : `OrderService::changeStatus` idempotent — `app/Services/OrderService.php:2139-2146` no-op si `status===target` sous lockForUpdate → pas de double cashBack/loyalty. Non-Admin bloqué depuis RETURNED (`OrderStateMachine.php:81-86`).
- **Double refund post-Z (counter-entry)** : UNIQUE(parent_order_id) → 409 `MIRROR_ALREADY_EXISTS` (`PosOrderController.php:170-176`), défense DB au-dessus du guard service. Deux X-Idempotency-Key distincts convergent quand même vers 409.
- **Refund forge / permission** : `refundWithCounterEntry` + `changeStatus(RETURNED)` gatés `can('pos-refund')` (Admin/Branch Manager only) fail-fast avant validation (`PosOrderController.php:58-62`, `:328-334`) + cross-branch abort 403 (`:70-72`). Parité des deux routes-jumelles vérifiée.
- **Cash-drawer close/reconcile ownership** : `assertSessionVisibleToUser` (`CashDrawerSessionController.php:317-338`) — cross-branch 403, same-branch non-owner exige `cash.reconcile.variance.override`. Reconcile variance > seuil exige reason + permission manager (`CashDrawerService.php:276-311`). Idempotence close/reconcile (statut déjà CLOSED/RECONCILED = no-op sans double audit).
- **Cash-drawer concurrence** : open via `Cache::lock` + `DB::transaction`+`lockForUpdate` (`CashDrawerService.php:78-84`) ; close/reconcile/recordMovement tous `lockForUpdate` — pas de double mouvement.
- **Reconcile expected/variance** : `expected = opening + Σ signedAmount`, `variance = closing - expected` arrondis 2 déc (`:262-263`) — pas de double comptage.
- **changePaymentStatus concurrence** : re-lock + re-validation transition sous lock (`OrderService.php:2413-2426`), 1 seul ActionLog/AuditLog/event par flip réel.
- **NF525 chain** : `fiscal:verify-chain --all` = CHAIN OK sur 4 branches, avant et après (aucun write effectué).
- **Discount forge** : PricingService SSOT frozen ; `PosDiscountForgeryTest` existant (non ré-exécuté — aucun write ; SSOT prix backend intacte).
