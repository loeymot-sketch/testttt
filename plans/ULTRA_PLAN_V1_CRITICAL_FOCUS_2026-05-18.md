# ULTRA-PLAN V1 CRITICAL FOCUS — Le Cayenne Production-Perfect

> **Auteur** : Claude (orchestrator) — 2026-05-18
> **Branche** : `v1-0-1-hardening-2026-05-17` · HEAD `6908edbde` · tag `v1.0.2-rc1-2026-05-18`
> **Méthodologie** : `ultra-architect-planify` (décompose) + `ultra-audit-profond` (zones d'audit) — focused mode (V1 single-resto, fast-food, NF525)
> **Mantra** : *no useless complexity*. Seulement les zones où une régression = perte d'argent, perte légale, ou kitchen stop.

---

## §0 — Contexte et limites de scope

### État actuel (lu depuis BRAIN.md + Graphiti `foodking` group)
- Mission GOAL Production Readiness 2026-05-18 ~95% complete (13+ P0 closed).
- NF525 chain bit-identical : `count=26 | last_hash=ca4ac1fdc208dae1`.
- Frozen-zone diff = 0 sur 13 fichiers protégés.
- BranchScope appliqué sur 17 models.
- Idempotency étendue 13 → 17 routes.
- Test files 471 → 479 + 33+ NEW cases.
- Vitest 1444/1447 stable. PHPUnit broad smoke 914/914.

### Owner-gates pending (HORS scope ultra-plan — owner physique)
- B1-B4 visual finalisation
- AWS rotation (commit `a4a88df06` ultra-goal 2026-05-13)
- POS XSS LOCK plan countersign (`plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md`)
- OVH VPS-1 setup Phase D

### Hors scope (V1.0.2 / V2 backlog — *ne pas* re-prioriser)
- POS XSS escape pos-wizard.js (LOCK plan attente)
- 83 FormRequest authz restants (5 fait Wave 5H)
- Sanctum TTL 8h → 1h sensitive ops
- API key versioning
- 12 composer advisories restants (4H/6M/2L)
- KDS V2 layout polish
- Stripe refund webhook handler V2
- Auto-dispatch livreur

### V1 NORTH STAR (immutable)
Fast-food Le Cayenne, single restaurant, FR locale, NF525 compliance absolue, 6 surfaces production : **POS · Kiosk · KDS · OSS · Admin · Sync**.

---

## §1 — Doctrine ranking : *où concentrer les calories*

| Tier | Critère | Coût d'une régression |
|---|---|---|
| **Tier 0** | Risque légal / NF525 | Prison + fermeture admin |
| **Tier 1** | Restaurant ne peut PAS vendre | Journée perdue, encaissement bloqué |
| **Tier 2** | Échec visible client / kitchen | NPS effondré, retours, repli |
| **Tier 3** | Owner daily admin | Inconvénient, contournable |
| **Tier 4** | V1.0.2 polish | À ignorer pour V1 ship |

L'ultra-plan ne traite que **Tier 0 + Tier 1 + sélection Tier 2**. Le reste = bruit pour une V1 single-resto.

---

## §2 — 7 ZONES CRITIQUES V1 (TOP focus)

### 🟥 Zone 1 — NF525 Fiscal Chain Integrity *(Tier 0 — legal)*

**Pourquoi vitale**
La loi de finance française impose chaînage HMAC-SHA256 sur `audit_logs`, immuabilité du `composition_snapshot`, monotonie de `fiscal_sequence_no`, et rétention 6 ans. Toute brèche = inspecteur peut fermer l'établissement et engager la responsabilité pénale du gérant.

**Surfaces**
- `app/Services/Fiscal/FiscalSequenceService.php` *(frozen)*
- `app/Services/Fiscal/AuditLogService.php` *(frozen)*
- `app/Services/Fiscal/ZReportService.php` *(frozen)*
- Tables `audit_logs` + `z_reports` + triggers `BEFORE DELETE` + `BEFORE UPDATE` (MySQL prod only)
- CLI `php artisan fiscal:verify-chain`
- Colonnes `composition_snapshot` + `allergens_snapshot` (JSON immuable sur `order_items`)

**Invariants à protéger (Graphiti confirm)**
1. `audit_logs.count` n'a jamais décru (currently 26).
2. `last_hash = ca4ac1fdc208dae1` recalculable à partir de la chaîne complète.
3. `composition_snapshot` 5 write sites uniquement à création d'order, zéro `UPDATE`.
4. `fiscal_sequence_no` strictement monotonic per branch, gap-free.
5. Triggers DELETE/UPDATE active.

**Discipline obligatoire**
- **Read-only audit uniquement** (frozen-zone absolue).
- Toute mod nécessaire → LOCK plan dans `plans/LOCK_*` avec sign-off owner.
- Vérification : `php artisan fiscal:verify-chain` retourne `CHAIN OK` (null), pas tamper.
- Cron de retention 6y vérifié sur Phase D (Ansible playbook).

**E2E scenarios chronologiques (raisonnement = pourquoi chaque test prouve quoi)**

```
09:00 — Z report opening verification
  → assert audit_logs count == 26 (baseline)
  → assert php artisan fiscal:verify-chain returns CHAIN OK
  RAISONNEMENT: prouve que la chaîne fiscale est verrouillée AVANT toute opération du jour.

12:30 — Order creation (POS cash) writes immutable snapshot
  → POST /api/admin/pos/order avec sandwich Cayenne + bacon + menu coca
  → assert order_items[0].composition_snapshot JSON contient lines+extras
  → SQL: UPDATE order_items SET composition_snapshot = '{}' WHERE id = X
  → assert UPDATE rejected ou trigger SIGNAL fired
  RAISONNEMENT: prouve que le snapshot historique est gravé.

13:00 — Concurrent fiscal_sequence allocation
  → 5 orders POST parallel via different terminals (concurrence simulation)
  → assert fiscal_sequence_no = [N, N+1, N+2, N+3, N+4] sans gap, sans collision
  RAISONNEMENT: prouve que Cache::lock 5s + DB FOR UPDATE tiennent sous concurrence.

23:00 — Z report close + chain extend
  → admin trigger Z report close
  → assert z_reports.count incremented
  → assert audit_logs.count == 26 + N orders
  → assert last_hash != precedent (chain extended)
  RAISONNEMENT: prouve clôture journalière conforme NF525.

23:01 — Tamper attempt detection
  → SQL: UPDATE audit_logs SET payload_json = '{}' WHERE id = 1
  → assert trigger BEFORE UPDATE rejects ou raise SIGNAL
  → run php artisan fiscal:verify-chain
  → assert returns "TAMPER at id=1" (not CHAIN OK)
  RAISONNEMENT: prouve détection effraction post-write.
```

**Critère de done**
- `php artisan fiscal:verify-chain` = `CHAIN OK` start + end of day
- `audit_logs` count strictement croissant
- `composition_snapshot` aucun UPDATE jamais effectué (vérifié via slow query log)
- Triggers actifs (vérifié via `SHOW TRIGGERS LIKE '%audit_logs%'`)

---

### 🟧 Zone 2 — POS Cash Drawer + Payment + Receipt Lifecycle *(Tier 1 — money)*

**Pourquoi vitale**
C'est l'encaissement réel. Cash drawer + TPE + ticket = la fonction quotidienne d'un fast-food. Une régression ici = on ne peut pas vendre. Wave 5I a ajouté `POS_SIMULATION_HARDWARE` avec production boot guard — il faut **prouver** que le flip vers `false` au go-live ne casse rien.

**Surfaces**
- `app/Http/Controllers/Admin/PosController.php`
- `app/Http/Controllers/Admin/PosOrderController.php` *(post Wave 5I IDOR timing-leak fix)*
- `app/Http/Controllers/Admin/Pos/CashDrawerController.php` + `CashDrawerSessionController.php`
- `app/Services/Payments/PaymentService.php` *(Wave 5F RefundCreated dispatch + simulation_hardware skip)*
- `app/Services/Payments/SplitPaymentService.php` *(Wave 5F terminal_id required + simulation skip)*
- `app/Services/Cash/CashDrawerService.php` *(H2 manager-gate routine close)*
- `app/Services/Order/RefundWithCounterEntryService.php` *(NF525 mirror order)*
- `config/pos.php:37` + `AppServiceProvider` boot guard (Insights heal Round 1 P0-#1)
- `.env` `POS_SIMULATION_HARDWARE` flag

**Risques identifiés**
- `.env` actuel = `POS_SIMULATION_HARDWARE=true` (dev mode). Production day = doit basculer à `false`.
- Si `simulation_hardware=false` ET pas de drawer physique branché → blocage CASH tranche.
- Wave 5F sentinel `PosSplitPaymentPhantomCard` doit rester GREEN (régression possible).
- Refund counter-entry doit créer mirror Z window sans toucher parent (NF525).

**Discipline obligatoire**
- **STOP checklist** : suis-je dans frozen-zone (pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php — toutes FROZEN). Si oui → LOCK plan.
- Scope-minimal (≤30 LOC inline) ou Implementer subagent.
- TDD : test sentinel NEW avant heal, puis heal.
- Adversarial RED dispute après commit : "qu'est-ce qui peut encore casser ?"
- Visual mandate sur les surfaces touchées (PosComponent.vue, PaymentComponent.vue).
- Production boot guard verified : `app()->environment('production') && config('pos.simulation_hardware')` doit throw `RuntimeException`.

**E2E scenarios chronologiques**

```
09:00 — Open day: drawer session start
  → admin POST /api/v1/admin/pos/cash-drawer-session/open
  → assert CashDrawerSession row created
  → assert audit_logs +1 row TYPE_DRAWER_OPEN
  RAISONNEMENT: prouve traçabilité fiscale ouverture.

09:01 — Production guard verification (simulation off)
  → set POS_SIMULATION_HARDWARE=false + APP_ENV=production
  → boot app
  → assert no RuntimeException thrown
  RAISONNEMENT: prouve guard ne bloque pas le démarrage légitime.

12:30 — Cash payment full flow
  → POST /api/admin/pos/order item=sandwich_cayenne + supp_bacon + menu_coca
  → assert order created + fiscal_sequence_no allocated + composition_snapshot frozen
  → POST /api/admin/pos/payment method=CASH amount=10.40
  → assert payment row + drawer movement +10.40 + ticket printed
  → assert ticket NF525 mentions: TVA + register_id + vat_intra
  RAISONNEMENT: prouve cash flow complet end-to-end avec ticket conforme.

12:45 — Split payment (cash + card) WITH terminal_id required
  → POST /api/admin/pos/payment method=SPLIT
       tranches=[{type:CASH, amount:5}, {type:CARD, amount:5.40, terminal_id:T1}]
  → assert ok 201
  → POST same SPLIT WITHOUT terminal_id on CARD tranche
  → assert 422 "terminal_id required for CARD"
  RAISONNEMENT: prouve Wave 5F phantom-card guard tient.

14:00 — Refund counter-entry NF525 mirror
  → POST /api/admin/pos-order/{12:30_order}/refund-with-counter-entry reason="client a retourné"
  → assert NEW order created (mirror) within current Z window
  → assert mirror.fiscal_sequence_no > parent.fiscal_sequence_no
  → assert mirror.parent_order_id == parent.id
  → assert parent.composition_snapshot UNCHANGED
  RAISONNEMENT: prouve NF525 immuabilité + traçabilité refund.

22:30 — Variance close (manager gate H2 if enabled)
  → admin POST /api/v1/admin/pos/cash-drawer-session/close avec variance=-2.50
  → if CASH_MANAGER_GATE_ROUTINE_CLOSE=true → assert require permission cash.reconcile.variance.override
  RAISONNEMENT: prouve manager-gate H2 fonctionnel.

23:00 — Z report close
  → admin POST /api/v1/admin/fiscal/z-report/close
  → assert sum(orders.amount) == sum(payments.tranches) +/- 0
  → assert audit_logs chain extended + last_hash NEW
  → assert PDF generated
  RAISONNEMENT: prouve clôture cohérente + signature fiscale.
```

**Critère de done**
- 25/25 cumulative POS tests stable (FritesWizardComposerTest 4 + PosSimulationHardware4Scenarios 6 + PosCashTrail 6 + SplitPaymentEndToEnd 6 + SplitPaymentSentinel 3)
- POS_SIMULATION_HARDWARE production guard sentinel GREEN
- `PosSplitPaymentPhantomCard` sentinel GREEN
- Visual capture POS login + payment modal sans truncation + sans console error

---

### 🟧 Zone 3 — Kiosk → KDS Sync Reliability *(Tier 1 — kitchen ne stoppe pas)*

**Pourquoi vitale**
Si une commande borne n'arrive pas en cuisine, le client attend dans le vide, le staff découvre 20 min plus tard. C'est le chemin "happy path" le plus chargé en latence cumulée : Vue 3 → POST API → Sanctum auth → Pricing → Order creation → Domain event → Outbox → Soketi WebSocket OU polling 30s fallback → KDS card displayed.

**Surfaces**
- `app/Http/Controllers/Frontend/OrderController.php` (FrontendOrderService)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` *(frozen 3104 LOC)*
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` *(frozen 1576 LOC)*
- `resources/js/components/admin/kds/KitchenDisplaySystemComponent.vue` *(2639 LOC orchestrator)*
- `app/Services/FrontendOrderService.php` *(zone validée NF525)*
- `app/Listeners/DispatchKdsTicket.php` (Outbox → KDS)
- Soketi WebSocket channel `private-branch.{branchId}`
- Polling fallback 30s adaptive (FK_CATALOG_KDS_DISCONNECTED_BASE_MS)
- `kiosk_offline_queue` table (F-008 reconcile pending)
- Sanctum ability `kiosk:order` TTL 480min

**Risques identifiés**
- Soketi down → polling fallback doit prendre le relais ≤30s
- Sanctum token expiration mid-flight (480min) → kiosk re-auth machinery
- Race : 2 orders same kiosk same X-Idempotency-Key → idempotency guard
- TPE simulation fail → kiosk_offline_queue replay → assert no double-charge
- KioskOfflineConflictModal flow (P1 from Wave Z)

**Discipline obligatoire**
- **Frozen-zone discipline ABSOLUTE** : KioskWizardComponent + KioskAppComponent intouchables. Toute modif logique = LOCK plan.
- Inline-edit exception 30 LOC OK sur les composants non-frozen (KioskCart, KioskPayment, KioskOfflineConflictModal).
- Visual mandate sur kiosk/idle + wizard 4 templates (tacos/sandwich/burger/bowl/menu_formule).
- Test latence : assert KDS card visible <1s (WebSocket) ou <30s (polling fallback).

**E2E scenarios chronologiques**

```
12:00 — Happy path order (WebSocket alive)
  → kiosk idle screen → tap start → wizard sandwich cayenne (4 steps)
  → cart 7.50€ → TPE simulation succeed
  → assert FrontendOrder created + composition_snapshot frozen
  → assert KDS card visible <1s via private-branch.1 channel
  RAISONNEMENT: prouve chemin nominal kiosk→KDS.

12:05 — WebSocket disconnect → polling fallback
  → kill Soketi process via supervisor stop foodking-soketi
  → kiosk wizard new order succeed
  → assert KDS card visible ≤30s via polling fallback
  → restart Soketi
  RAISONNEMENT: prouve résilience disconnection.

12:10 — TPE simulation fail → offline queue replay
  → mock TPE return 500
  → kiosk order proceed
  → assert kiosk_offline_queue row inserted with Idempotency-Key
  → mock TPE return 200
  → run reconcile-pending cron
  → assert order finalized + KDS receives once (no duplicate)
  RAISONNEMENT: prouve F-008 reconcile sans double-charge.

12:15 — Idempotent retry guard
  → POST /api/v1/frontend/order with X-Idempotency-Key=K1
  → assert 201 created
  → POST same payload same K1
  → assert 200 cached replay (NOT 201 NEW row)
  → POST different payload same K1
  → assert 409 conflict
  RAISONNEMENT: prouve idempotency middleware tient.

12:20 — Wizard 4 templates parity
  → For template in [tacos, sandwich, burger, bowl, menu_formule]:
       kiosk wizard complete template
       assert composition_summary contains expected fields
       assert backend price == frontend display ± 0
  RAISONNEMENT: prouve parité visuelle/data sur les 5 templates V1.

12:25 — KDS bump end-to-end
  → KDS button bump (52px touch target)
  → assert PATCH /api/v1/admin/kds/order/{id}/status status=PREPARING
  → assert OSS screen reflects PREPARING <1s
  → bump → PREPARED
  → assert OSS shows READY
  RAISONNEMENT: prouve KDS→OSS sync continue.

19:00 — Sanctum token mid-flight expiration
  → kiosk authed at 11:00 (TTL 480min → expires 19:00)
  → at 19:01 attempt order
  → assert 401 + kiosk re-auth flow triggered (re-login machine creds)
  RAISONNEMENT: prouve re-auth machinery propre.
```

**Critère de done**
- WebSocket+polling chain latency <30s p95
- Idempotency middleware sur 17 routes (Wave 5I) — sentinel test GREEN
- Wizard 4 templates : 6/6 E2E Frites + 4/4 Bols + Sandwich + Burger + Menu = couvert
- Visual capture kiosk/idle + 5 wizard step screens (Read PNG + analyze)

---

### 🟨 Zone 4 — Multi-tenant BranchScope + Auth Hardening *(Tier 0+1 — security + correctness)*

**Pourquoi vitale**
V1 = single-resto Le Cayenne (branch_id=1) mais l'architecture multi-tenant EST déjà appliquée (17 models). Si un guard IDOR fuit, un staff branch=1 lit ou modifie une ressource branch=2 → légalement = breach RGPD + financier = leak data. Wave 5I a healed le POS IDOR timing-leak (`PosOrderController:107-128`). Il faut **prouver** qu'il n'y a pas d'autre.

**Surfaces**
- `app/Models/Scopes/BranchScope.php` *(frozen)*
- 17 models scoped (Order, FrontendOrder, OrderItem, OrderPayment, KioskMachine, StockLevel, StockMovement, CashDrawerSession, CashMovement, PendingPaymentConfirmation, PushNotification, DiningTable, Printer, ParkedOrder, OrderQuote + post-GOAL 2 NEW)
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` *(frozen)*
- `app/Http/Middleware/EnsureUserStatusActive.php` (Sprint H1 Z6-06)
- Sanctum `kiosk:order` ability + TTL 480min
- Spatie permissions (135 gates)
- `app/Http/Controllers/Admin/Auth/LoginController.php` *(bcrypt 12 + revoke prior tokens Wave Z 5D)*
- `BranchController::update` + `BranchStatusChanged` event Wave 5G R10 (revoke tokens on deactivate)

**Risques identifiés**
- ModelNotFoundException timing leak ailleurs (PosOrderController fixed mais autres controllers ?)
- Sanctum token sprawl (Wave 5D fix : revoke on relogin)
- Permission gates manquants sur routes admin sensibles (88 endpoints scope V1.0.2 mais critiques V1 = settings/fiscal/users)
- Mass-assignment vulnérabilité (Sprint H1 Z6-05 closed mais verifier sur NEW controllers)

**Discipline obligatoire**
- Read-only audit cross-controller pour IDOR pattern (`Model::findOrFail` sans `withoutGlobalScope` then explicit branch check).
- FormRequest authz `$this->user()?->can(...)` au lieu de `return true;`.
- Permission gate vérifié sur toute route admin (`->middleware(['permission:X'])`).
- Adversarial RED test : try cross-branch access avec staff token.

**E2E scenarios chronologiques**

```
09:00 — Admin login + permissions
  → POST /api/v1/admin/auth/login admin@lecayenne.fr
  → assert bcrypt cost 12 + auto-rehash if stored at 10
  → assert prior tokens revoked
  → assert NEW token created with full abilities
  RAISONNEMENT: prouve Wave 5D auth hygiene.

10:00 — Staff branch=1 IDOR attempt → assert 403 unified
  → login pos@lecayenne.fr (branch=1)
  → GET /api/admin/pos-order/{order_belonging_to_branch_2_id}
  → assert 403 "Cross-branch access denied" (NOT 404 timing leak)
  → GET /api/admin/pos-order/{nonexistent_id}
  → assert 403 unified (same shape)
  RAISONNEMENT: prouve Wave 5I PosOrderController unified abort.

10:15 — Staff branch=1 mass-assign attempt
  → POST /api/v1/admin/order with branch_id=2 in body
  → assert FormRequest strips branch_id
  → assert created order.branch_id == 1 (auth user's branch)
  RAISONNEMENT: prouve Sprint H1 Z6-05 mass-assign protection.

11:00 — Kiosk Sanctum ability scope
  → kiosk authed with ability=['kiosk:order']
  → POST /api/v1/frontend/order → assert 201 ok
  → POST /api/admin/pos/order → assert 403 (kiosk token cannot pos:order)
  RAISONNEMENT: prouve Sanctum ability strict scope.

15:00 — Branch deactivate → tokens revoke fanout
  → admin PATCH /api/v1/admin/branch/2 status=DEACTIVATED
  → assert BranchStatusChanged event fired
  → assert all Sanctum tokens for branch=2 users revoked
  → staff branch=2 attempt API call → 401
  RAISONNEMENT: prouve Wave 5G R10.

16:00 — User status revalidation every-request middleware
  → admin disables user pos@lecayenne.fr
  → pos@lecayenne.fr next API call → assert 401/403
  RAISONNEMENT: prouve Sprint H1 Z6-06 EnsureUserStatusActive.
```

**Critère de done**
- BranchScope cross-branch IDOR sentinel GREEN sur 17 models
- Permission gate audit : routes admin/fiscal/settings/users all gated
- Sanctum ability scope sentinel kiosk:order vs pos:order
- 0 fail sur sentinel `OrderShowBranchGuardSentinelTest`

---

### 🟧 Zone 5 — Pricing SSOT + Composition Snapshot *(Tier 0+1 — money & legal accuracy)*

**Pourquoi vitale**
Le frontend ne calcule jamais les prix. Backend authoritative via `PricingService::calculateOrder`. Si un wizard envoie un prix custom et que le backend l'accepte = perte d'argent + violation NF525. Le `composition_snapshot` doit être figé à création — sinon un admin qui change le prix d'un item modifie rétro-activement des reçus émis (illégal).

**Surfaces**
- `app/Services/Pricing/PricingService.php` *(frozen)*
- `app/Services/OrderService.php` *(NF525 critical)*
- `app/Services/FrontendOrderService.php` *(NF525 critical)*
- Colonnes `composition_snapshot` (JSON) + `allergens_snapshot` (JSON) sur `order_items`
- Wizard composer profiles (sandwich/burger/bowl/menu_formule)
- POS wizard pos-wizard.js *(frozen 5964 LOC)*
- Mobile + Web canonical menu (frozen-zone scope: mobile/data/menu.js + web/data/menu.js post GOAL)

**Risques identifiés**
- Wizard envoie `price` au backend (devrait envoyer seulement `item_id, quantity, option_ids`)
- composition_snapshot UPDATE quelque part (5 write sites au création, 0 UPDATE — must verify)
- Pricing tax inclusive/exclusive flag
- Multi-sauce extra pricing (sandwich Cayenne 2 viandes)
- Bol gratiné +2€ option pricing

**Discipline obligatoire**
- Read-only audit Grep all `composition_snapshot` write sites — assert ZERO `UPDATE` queries.
- Adversarial : POST POS order avec `price=0` ou `price=-10` payload custom → assert backend rejects.
- Visual mandate cart total = receipt = backend amount = KDS card = OSS card (numeric integrity).

**E2E scenarios chronologiques**

```
09:30 — composition_snapshot immutability check (DB-level)
  → SQL: SELECT COUNT(*) FROM order_items WHERE composition_snapshot IS NULL
  → assert 0 (all populated at creation)
  → SQL: UPDATE order_items SET composition_snapshot = '{}' WHERE id = 1
  → assert returns 0 affected rows OR error (if trigger BEFORE UPDATE active)
  RAISONNEMENT: prouve snapshot frozen.

12:30 — Pricing SSOT backend authoritative
  → POST /api/admin/pos/order avec
       items=[{item_id: 100 (sandwich Cayenne), quantity: 1, option_ids: [bacon_supp, menu_coca]}]
       PAS de "price" envoyé
  → assert backend computes total via PricingService::calculateOrder
  → assert order.amount == 10.40€ (7.50 + 0.90 + 2.50)
  RAISONNEMENT: prouve backend SSOT.

12:35 — Pricing tampering attempt rejected
  → POST /api/admin/pos/order avec items=[...] + custom price=0.01
  → assert backend IGNORES custom price (or 422)
  → assert order.amount == calculated value (not 0.01)
  RAISONNEMENT: prouve aucun trust frontend.

13:00 — Cart line composition_summary = receipt = KDS card
  → POST kiosk order: Big Cayenne 7.50€ + 2 viandes + sauce_locked Algérienne
  → kiosk cart line displays "viande_count=2 sauce=Algérienne"
  → ticket print mentions same lines
  → KDS card mentions same lines
  → OSS mentions same order id
  → assert ALL surfaces same composition_summary string
  RAISONNEMENT: prouve numeric+text integrity cross-surface.

15:00 — Admin price update mid-day → in-flight orders untouched
  → at 12:30 order item=sandwich price=7.50€ → order recorded amount=7.50
  → at 14:00 admin updates Item.price=8.00€
  → assert order from 12:30 unchanged (composition_snapshot frozen)
  → at 15:00 new order item=sandwich → assert order amount=8.00€
  RAISONNEMENT: prouve retro-modification impossible (NF525 fundamental).

23:00 — Z report sum integrity
  → Z close
  → SELECT SUM(amount) FROM orders WHERE created_at BETWEEN Z_open AND Z_close
  → assert == z_reports.total_sales_amount
  → assert == SUM(order_payments.amount)
  RAISONNEMENT: prouve clôture cohérente €0.99 → 999 cents (Insights P0-#2).
```

**Critère de done**
- 0 UPDATE query on composition_snapshot in production audit
- Pricing SSOT sentinel : POST custom price ignored
- Cross-surface numeric integrity test (cart=receipt=KDS=OSS)
- Wave 5I Stripe cents round-before-cast verified (€9.99 → 999, not 900)

---

### 🟨 Zone 6 — Sync Outbox + Webhook Idempotency *(Tier 1-2 — resilience)*

**Pourquoi vitale**
Une commande créée mais l'event ne propage pas = KDS aveugle, OSS aveugle, stock pas décrementé. Un webhook Stripe replay = double-charge client. Wave Z a healed 6 listeners `wasRecentlyCreated` guards et Wave 5G a closed Stripe webhook dedup via `WebhookEvent UNIQUE(provider, webhook_id)`.

**Surfaces**
- 10 Persist*ToOutbox listeners (`PersistOrderToOutbox`, `PersistOrderStatusChanged`, `PersistOrderPaymentStatusChanged`, `PersistOrderTableChanged`, `PersistItemAvailabilityChanged`, `PersistItemExtraAvailabilityChanged`, `PersistItemVariationAvailabilityChanged`, `PersistSettingsUpdatedToOutbox`, `RevokeTokensOnBranchDeactivated`, `DispatchKdsTicket`)
- `domain_events` table + `DispatchDomainEventsJob` (lockForUpdate + tries=6 backoff [1,5,15,60,300]s)
- `webhook_events` table UNIQUE constraint Stripe + SenangPay
- Soketi WebSocket channels (`private-branch.{id}`, `private-table.{id}`, `private-printer.{id}`)
- Cron jobs `PruneOutboxCommand` + `PruneWebhookEventsCommand` daily 04:00 + 04:15

**Risques identifiés**
- Listener fires twice if `wasRecentlyCreated` guard manquant → outbox duplicate
- Webhook replay attaque (Stripe replay attack) → must reject same `webhook_id`
- Outbox stuck (DispatchDomainEventsJob fail after retries) → DLQ command V1.0.2 (Sprint H3 stub)
- Stripe webhook signature verification (Insights heal Round 1 P0-#5 doc'd `STRIPE_WEBHOOK_SECRET` CRITICAL)

**Discipline obligatoire**
- Test : assert each listener has `wasRecentlyCreated` or equivalent dedup.
- Test : POST webhook replay (same Stripe event_id) → assert idempotent.
- Verify cron schedule via `php artisan schedule:list` (production parity).
- Production STRIPE_WEBHOOK_SECRET set in PRODUCTION_ENV_TEMPLATE.

**E2E scenarios chronologiques**

```
12:30 — Outbox happy path
  → POS create order
  → assert domain_events row inserted
  → assert DispatchDomainEventsJob picks it up
  → assert Soketi broadcast on private-branch.1
  → assert KDS card visible <1s
  → assert OSS shows order
  RAISONNEMENT: prouve outbox→broadcast chain.

13:00 — Listener double-fire prevention
  → mock model.wasRecentlyCreated returns false (simulate update event)
  → assert PersistOrderStatusChanged listener does NOT enqueue domain_event
  RAISONNEMENT: prouve Wave Z 5C wasRecentlyCreated guard.

13:30 — DispatchDomainEventsJob retry chain
  → mock Soketi return 500 ×6
  → assert job retries [1, 5, 15, 60, 300]s
  → after retries exhausted, assert moved to failed_jobs
  RAISONNEMENT: prouve resilience.

14:00 — Stripe webhook idempotent replay
  → POST /api/webhooks/stripe with event_id=evt_123 → 200 ok
  → POST SAME event_id again → 200 (cached, NOT double-process)
  → assert webhook_events UNIQUE(stripe, evt_123) enforced
  RAISONNEMENT: prouve dedup.

14:05 — Stripe webhook forged signature rejected (Insights P0-#5)
  → POST /api/webhooks/stripe with valid event_id BUT invalid signature
  → assert 401/403 (signature verification fails)
  → requires STRIPE_WEBHOOK_SECRET set in env
  RAISONNEMENT: prouve forge attack mitigated.

04:00 — Cron PruneOutboxCommand
  → simulate clock 04:00
  → assert PruneOutboxCommand ran
  → assert old domain_events rows pruned (>30 days)
  → 04:15 PruneWebhookEventsCommand ran
  RAISONNEMENT: prouve growth control.
```

**Critère de done**
- 10/10 listeners with dedup guard verified
- Stripe webhook idempotent + signature verified sentinel
- Cron schedule verified Phase D Ansible
- Domain events table TTL pruning operational

---

### 🟩 Zone 7 — Admin Daily Flow (Catalogue + Stock + Z Report) *(Tier 2-3 — owner)*

**Pourquoi importante (mais pas critique)**
Owner utilise l'admin tous les jours. Update prix, mark item 86, consulter Z report. Si ça casse → owner ne peut pas opérer mais business continue (POS+Kiosk déconnectés du catalogue restent fonctionnels via composition_snapshot).

**Surfaces**
- `app/Http/Controllers/Admin/ItemController.php` *(Sprint H5 +13 i18n + barcode/kds_station)*
- `app/Http/Controllers/Admin/StockController.php` + `StockLevelController.php` (86 cascade)
- `app/Http/Controllers/Admin/Fiscal/ZReportController.php` *(read-only — Z generation in service frozen)*
- `app/Http/Controllers/Admin/SettingsController.php` (24 settings, Wave 5G fanout SettingsUpdated)
- `app/Http/Controllers/Admin/BranchController.php`
- 237 Vue components admin

**Risques identifiés**
- Stock 86 cascade kiosk badge race
- Settings fanout doit broadcast à POS + Kiosk + KDS
- Z report PDF generation deps (TCPDF/DomPDF)
- Catalogue add new item → ItemAvailabilityChanged event broadcast

**Discipline obligatoire**
- Standard CRUD audit + permission gates.
- Visual mandate sur admin/items + admin/stock-rupture-dashboard.
- Stock cascade sentinel : 86 → kiosk badge update <1s.

**E2E scenarios chronologiques**

```
09:00 — Admin login + dashboard
  → admin@lecayenne.fr login
  → GET /admin/dashboard
  → assert 15 widgets render no console error
  RAISONNEMENT: prouve admin landing OK.

10:00 — Catalogue add new item
  → POST /api/v1/admin/item name="Big Cayenne XXL" price=12
  → assert Item created + ItemAvailabilityChanged event broadcast
  → assert POS catalogue shows new item <5s
  → assert Kiosk catalogue shows new item <5s
  RAISONNEMENT: prouve catalogue fanout.

11:00 — Stock 86 cascade
  → admin POST /api/v1/admin/stock-level/item/{id}/86 reason="rupture"
  → assert StockMovement inserted + ItemAvailabilityChanged event
  → assert kiosk badge "épuisé" visible <1s
  → assert POS shows item disabled
  RAISONNEMENT: prouve stock cascade.

15:00 — Settings update fanout (Wave 5G R9)
  → admin PATCH /api/v1/admin/settings/currency value=USD
  → assert SettingsUpdated event broadcast
  → assert POS price display flips currency
  → assert Kiosk price display flips
  RAISONNEMENT: prouve Wave 5G R9 settings fanout.

23:00 — Z report close + PDF
  → admin POST /api/v1/admin/fiscal/z-report/close
  → assert Z row created + audit_logs chain extended
  → admin GET Z PDF
  → assert PDF content matches z_reports.total_sales_amount
  RAISONNEMENT: prouve Z close + PDF.

23:30 — Reports daily aggregation
  → admin GET /api/v1/admin/reports/daily?date=2026-05-18
  → assert sum match Z report + line per order_type
  RAISONNEMENT: prouve reports cohérent.
```

**Critère de done**
- Catalogue + Stock + Settings + Z report all functional
- Visual capture admin/items + admin/stock-rupture-dashboard sans truncation
- Stock cascade <1s p95

---

## §3 — TASKS COMPLEXES À EXÉCUTER (disciplines codifiées)

Les 7 tâches ci-dessous seront créées via TaskCreate. Chacune respecte :

1. **STOP checklist 6 questions** (référence `references/stop-checklist-quickref.md`)
   - Q1: ai-je compris ce qui est demandé ?
   - Q2: ai-je identifié les fichiers frozen-zone ?
   - Q3: ai-je un plan scope-minimal (≤30 LOC) ou dois-je dispatch Implementer ?
   - Q4: ai-je un test RED qui fail aujourd'hui ?
   - Q5: ai-je identifié les surfaces visual à capture ?
   - Q6: ai-je une stratégie rollback si fail ?

2. **Frozen-zone discipline** : 13 fichiers protégés. Tout touch = LOCK plan owner-gated.

3. **NF525 invariants** : audit chain + composition_snapshot + fiscal_sequence_no + 6y retention.

4. **TDD** : RED → GREEN → REFACTOR. Test new fail first, then heal.

5. **Adversarial RED dispute** : après commit, sub-agent hostile cherche P0/P1.

6. **Visual mandate** : si UI touchée → Playwright capture + Read PNG + analyze.

7. **Max 3 heal loops** sur le même problème puis escalate user.

8. **Commit format** : `INLINE-EDIT-EXCEPTION: scope=X LOC, files=N, tests=path, justification=...` + Co-Authored-By Claude.

9. **No push to main** sans owner gate explicite.

10. **No `--no-verify`** : pre-commit hooks must pass.

---

## §4 — Convergence Criteria (verdict GO)

Pour déclarer la zone "production-ready" :

- 0 P0 ouvert (cross-validé 2+ agents si audit massif)
- 0 P1 ouvert sur 2 cycles consécutifs (set-equality, kill flakes)
- `php artisan fiscal:verify-chain` = `CHAIN OK`
- Frozen-zone diff = 0 sur 13 fichiers
- PHPUnit + Vitest + Playwright heal-scope all GREEN
- Visual capture analysée pour chaque surface modifiée
- Sentinel tests stables sur 2 runs consécutifs

---

## §5 — Owner-facing summary

Ce que je recommande de faire **maintenant** pour V1 ship :

1. **Laisser tourner la routine 06:40** déjà programmée (couvre les 5 systèmes en autonomous loop). Elle traitera tous les détails que l'analyse ci-dessus identifie comme critiques.

2. **Owner-physique actions** restantes (hors scope Claude) :
   - AWS rotation (carryover ultra-goal 2026-05-13)
   - POS XSS LOCK plan countersign (`plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md`)
   - OVH VPS-1 Phase D setup
   - Branch deactivation des branches obsolètes (UPDATE branches SET status=5)
   - Stripe webhook secret production set

3. **Production day flip** :
   - `POS_SIMULATION_HARDWARE=true` → `false` (matériel branché)
   - `PAYMENT_BYPASS_MODE=true` → `false`
   - `PRINTING_BYPASS_MODE=true` → `false`
   - `APP_ENV=local` → `production`
   - `APP_DEBUG=false` (already)
   - `KIOSK_REQUIRE_MACHINE_LOGIN=false` → `true` (production)

4. **Smoke E2E day-0** : exécuter scenarios chronologiques Zone 2 + Zone 3 en production (matériel réel TPE/drawer) pour valider hardware integration.

---

## §6 — Anti-drift garde-fous

À ne PAS faire pour V1 ship (drift documenté V1.0.2) :
- Refactor 88 FormRequest authz (5/88 fait Wave 5H — suffit pour V1)
- Sanctum TTL 8h → 1h sensitive (V1.0.1 backlog)
- API key versioning (V1.0.2)
- Composer upgrade 12 advisories restantes (V1.0.2 — Wave 5H closed 5 critical)
- Auto-dispatch livreur (DEL-9 V1.0.2)
- KDS V2 layout polish (Wave 5G owner-gate decision Deprecate)
- Saga pattern Order+Payment+Stock (V1.x)

**Le périmètre est figé. La discipline est de NE PAS étendre le scope.**

---

*Plan généré 2026-05-18 par Claude. Source : Graphiti foodking + BRAIN §1-3 + CLAUDE.md §6-13 + Wave 5I + GOAL mission 2026-05-18.*
