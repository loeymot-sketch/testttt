# AUDIT MANIFEST — Caisse (POS) System
**Date** : 2026-05-09
**Scope** : Système Caisse complet (front + back) — paiement cash/card, fiscal close, cash drawer
**Branch** : `review/audit-caisse-pos`
**Cible** : `/ultrareview <this-PR>` doit auditer tout le périmètre listé ci-dessous

---

## §1 — Files in scope (paths absolus à auditer)

### Backend Controllers + Services
- `app/Http/Controllers/Backend/PosOrderController.php`
- `app/Http/Controllers/Backend/CashDrawerController.php`
- `app/Http/Controllers/Backend/CashMovementController.php`
- `app/Services/OrderService.php` (focus méthodes POS : `posOrderStore`, `changeStatus` cash close)
- `app/Services/Pos/PosCheckoutService.php` (si existe)
- `app/Services/Payments/SplitPaymentService.php`
- `app/Services/Payments/PaymentService.php` (focus cash flow)
- `app/Services/Fiscal/FiscalSequenceService.php` (focus alloc cash close)
- `app/Services/Fiscal/ZReportService.php` (focus close + cash enrichment)
- `app/Services/Fiscal/CashDrawerService.php` (si existe)

### Backend Requests + Models
- `app/Http/Requests/OrderRequest.php` (focus POS path)
- `app/Http/Requests/PaymentStatusRequest.php`
- `app/Http/Requests/OrderStatusRequest.php` (focus POS shortcut DELIVERED)
- `app/Models/Order.php` (focus POS context)
- `app/Models/OrderPayment.php`
- `app/Models/CashDrawerSession.php`
- `app/Models/CashMovement.php`

### Frontend POS V4/V5
- `resources/js/pos-app.js` (entry-point dédié POS, cf webpack.mix.js)
- `resources/js/components/admin/pos/PosComponent.vue` (3519 lignes)
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/FloorplanComponent.vue` (V1 dine-in disabled)
- `resources/js/components/admin/pos/ItemComponent.vue`
- **POS Vanilla JS wizard (FROZEN)** — `public/js/pos-wizard.js` (S25-SinglePage)
- `public/css/pos-wizard.css`
- `resources/views/admin-pos-v4.blade.php`

### Migrations + Database
- `database/migrations/2026_05_06_180000_create_order_payments_table.php` (F-SPLIT-PAYMENT)
- `database/migrations/2026_05_08_140000_create_cash_drawer_sessions_table.php`
- `database/migrations/2026_05_08_140001_create_cash_movements_table.php`
- `database/migrations/2026_04_22_000001_*` (fiscal_sequence_no addition)

### Tests existants à vérifier
- `tests/Feature/Pos/SplitPaymentEndToEndTest.php`
- `tests/Feature/Pos/PosOrderCreateTest.php`
- `tests/Feature/Sentinels/SplitPaymentSentinelTest.php`
- `tests/Feature/Sentinels/F003CashReconciliationSentinelTest.php`
- `tests/Unit/Services/Payment/SplitPaymentServiceTest.php`
- `tests/Feature/Sentinels/F006PosIdempotencyParitySentinelTest.php`
- `tests/e2e/02-pos-cash.spec.js`
- `tests/e2e/05-pos-card.spec.js`
- `tests/e2e/audit-coupon-dashboard-2026-05-06.spec.js`

---

## §2 — Invariants à vérifier (NF525 + multi-tenant + payment)

1. **Pricing SSOT backend authoritative** — tous les prix calculés par `PricingService`, jamais de bypass frontend
2. **Fiscal sequence monotonic gap-free** per branch sur cash close (Cache::lock 5s + DB FOR UPDATE)
3. **`composition_snapshot` JSON immutable** post-create (NF525 contract)
4. **Branch isolation** — POS staff ne voit/modifie que ses orders (BranchScope on Order, OrderItem, OrderPayment, CashDrawerSession, CashMovement)
5. **Spatie permission** — POS Operator role limité aux mutations POS, pas Admin
6. **Idempotency** — POS order create idempotent via `X-Idempotency-Key` middleware
7. **Race conditions** — `changePaymentStatus(auth=true)` lockForUpdate iter13
8. **Cash drawer audit trail** — chain-signed F-003 (cash_drawer_sessions + cash_movements)
9. **Z report close** — pre-check orphan paid+seq=NULL warns ops (iter14 SPECIALIST-3)
10. **DELETE triggers** MySQL z_reports + audit_logs (iter11 NF525 immutability)

---

## §3 — Questions critiques pour l'auditeur

### Architecture
- Le découpage POS V4 vs V5 est-il cohérent ? Y a-t-il duplication entre PosComponent.vue et pos-wizard.js ?
- `pos-wizard.js` (Vanilla S25-SinglePage) est-il vraiment frozen ? Cohérent avec PosComponent.vue logic ?
- Floorplan désactivé V1 (`pos.dine_in_enabled=false`) — propre ? Pas de dead UI ?

### Payment flow
- Cash flow : panier → checkout → cash drawer open → cash collected → Z report. Tracé complètement ?
- Card flow : terminal TPE → confirm amount → echo verify → payment_status=PAID → fiscal_seq alloc. Race conditions ?
- Split payment (F-SPLIT-PAYMENT) : sum check ≥ Order.total ? Idempotent si retry ?

### Fiscal compliance
- `fiscal_sequence_no` allocation timing : à payment_confirm ou à Z close ? Cohérent NF525 ?
- Si fiscal alloc fail (iter14 GATE-FZH-ALLOC) : retry cron `foodking:fiscal:retry-alloc` couvre POS aussi ?
- `audit_logs` HMAC chain pour POS status changes : intact ?

### Sécurité
- Branch isolation testée (cross-branch POS user blocked) ?
- Sanctum admin token ne doit PAS avoir kiosk:order ability (separation strict)
- Spatie permission `pos` role défini + tested ?
- CSRF protection sur routes POS mutating ?

---

## §4 — Acceptance criteria

L'audit est CLEAN si :
- ✅ Aucun finding P0 (cross-tenant leak, NF525 broken, race condition unhandled)
- ✅ Tous les invariants §2 vérifiés via grep + tests
- ✅ Tests `tests/Feature/Pos/*` passent + sentinels F003/F006 verts
- ✅ E2E `02-pos-cash` + `05-pos-card` passent
- ✅ Frozen-zone `public/js/pos-wizard.js` + `public/css/pos-wizard.css` non touchés depuis POS-V5-DESIGN-CONVERGENCE 2026-05-02

L'audit signale HEAL si :
- ⚠️ P1 trouvé (FormRequest authz scattered controller-level pas FormRequest-level)
- ⚠️ Logging insuffisant cash drawer reconcile
- ⚠️ Test coverage manquant sur split payment edge cases

L'audit BLOCK si :
- ❌ Cross-tenant POS access possible
- ❌ Fiscal sequence gap non-recovered
- ❌ Cash drawer audit chain compromis

---

## §5 — Out of scope (NE PAS auditer dans ce PR)

- Kiosk (Borne) — cf `AUDIT_BORNE_KIOSK_2026-05-09.md`
- Synchronisation cross-surface — cf `AUDIT_SYNC_EVENTS_2026-05-09.md`
- Stock + tracking commandes — cf `AUDIT_TRACKING_STOCK_2026-05-09.md`
- Global multi-tenant + Sanctum (couches transverses) — cf `AUDIT_GLOBAL_CROSS_SYSTEM_2026-05-09.md`
- Admin dashboards (catalogue, reports) — hors V1.0.1
- Frontend Vue admin général (sauf pos/) — hors scope POS

---

## §6 — Reference architecture

Voir `CLAUDE.md` :
- §7 Frozen Zones (POS Vanilla wizard paths verified iter15)
- §8 NF525 Fiscal Invariants
- §9 Multi-Tenant + Auth Invariants

Voir `PROJECT_BRAIN.md` :
- §1 NORTH STAR — V1 POS opérationnel
- §7 VERIFICATION CHECKLIST — domaines POS validés (split payment + cash audit + fiscal)

— *Manifest généré pour `/ultrareview review/audit-caisse-pos`. Audit ciblé Caisse système.*
