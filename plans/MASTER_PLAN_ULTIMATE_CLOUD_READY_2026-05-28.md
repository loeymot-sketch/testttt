# 🎯 MASTER PLAN ULTIMATE — Cloud-Ready Validation

**Date** : 2026-05-28
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`
**HEAD** : `e361686e6`
**Mandate owner** : « décompose chaque système, max agents adversarial + GStack + Superpowers, cloud-ready 100% »

## §1 — Décomposition 15 systèmes

### 10 Systèmes visibles + métier

| ID | Système | Route principale | Responsabilité |
|----|---------|------------------|----------------|
| **SYS-A** | POS Caisse | /admin/pos | Vente directe + encaisser borne |
| **SYS-B** | Borne Kiosk | /kiosk/idle | Self-service client + paiement |
| **SYS-C** | KDS Cuisine | /admin/kitchen-display-system | Workflow chef + bump |
| **SYS-D** | OSS Public | /admin/order-status-screen | Affichage public client |
| **SYS-E** | Cash Drawer | /admin/cash-overview | Tiroir caisse + variance |
| **SYS-F** | Stock & Catalog | /admin/items + /admin/ingredients + /admin/stock/rupture | Produits + rupture |
| **SYS-G** | Customers + Loyalty | /admin/customers + /admin/loyalty | CRM + fidélité |
| **SYS-H** | Reporting + Dashboard | /admin/dashboard + /admin/sales-report | KPIs + EOD PDF |
| **SYS-I** | Settings + RBAC | /admin/settings + /admin/permission + /admin/role | Config + permissions |
| **SYS-J** | Synchronization (cross-cutting) | Echo + Outbox + Polling | Cohérence cross-surface |

### 5 Systèmes cachés / infrastructure

| ID | Système | Composant | Critique pour |
|----|---------|-----------|---------------|
| **INFRA-1** | NF525 Fiscal Chain | audit_logs + z_reports + composition_snapshot + triggers | Conformité légale |
| **INFRA-2** | Echo/Pusher Broadcast | Soketi + private-branch.{id} channels | Temps réel |
| **INFRA-3** | Outbox/Queue Redis | DispatchDomainEventsJob + 14 Persist*ToOutbox | Garantie livraison |
| **INFRA-4** | Cache Invalidation | Cache::* + InvalidateKioskMenuCache listeners | Fraicheur catalogue |
| **INFRA-5** | Cron Scheduler | 20 lanes Kernel.php | Backups + Z-loop + retry |

## §2 — Discipline (10 contracts)

- **DM1** Frozen-zone PROPOSAL only (14 §7 files)
- **DM2** Pre+post diff per action
- **DM3** Captures quartet (PNG + DOM + console + network)
- **DM4** Production-real scenarios
- **DM5** Adversarial inline (1 hostile paragraph per agent)
- **DM6** NF525 READ-ONLY ABSOLU (NO INSERT on audit_logs/z_reports)
- **DM7** Rate-limit aware (5 max per batch + pause)
- **DM8** Honest reporting (PASS-source-only if can't trigger visual)
- **DM9** Convergence loop max 3 rounds
- **DM10** Bundle freshness gate

## §3 — Architecture exécution

```
PHASE 1 · DECOMPOSITION (THIS DOC)
         10 systèmes + 5 infra catalogués

PHASE 2 · PER-SYSTEM DEEP TEST (10 agents séquentiel batchés 5+5)
         Pour CHAQUE système : technical + visual + functional + 
         data + security + sync + adversarial

PHASE 3 · REAL ORDER FLOW TRACKING (3 agents parallèle)
         Trace COMPLET d'une commande : borne → KDS → OSS → cash
         + POS direct sale flow
         + Refund flow

PHASE 4 · CROSS-SYSTEM INTERACTIONS (4 agents)
         Sync rupture, race conditions, multi-tab, multi-cashier

PHASE 5 · ADVERSARIAL RED-TEAM (3 agents)
         Cross-system attacks, NF525 stress, security probes

PHASE 6 · SYNTHESIS CLOUD-READINESS (1 synth agent)
         Final verdict GO/AMBER/STOP cloud

Total : ~21 agents · 60-90 min wall-clock · Rate-limit safe
```

## §4 — Per-system task template

Chaque agent dédié couvre :

### A. Technical (code structure)
1. Map controllers + services + models
2. Endpoints API inventory + middleware
3. Database schema integrity
4. Sentinels existing pass/fail
5. Events/listeners wired

### B. Visual interface (Playwright captures)
6. Page load
7. Every button identified + tested (sample 5+ critical)
8. All states (empty / with data / error / loading)
9. WCAG accessibility
10. i18n FR/EN/AR
11. Responsive mobile

### C. Fonctionnel
12. CRUD operations
13. Workflow business
14. Edge cases (extreme inputs)

### D. Data
15. Where stored (table + columns)
16. Where archived (backup chain)
17. Where sent (event/broadcast/audit)
18. Retention policy

### E. Sécurité
19. RBAC matrix
20. Branch isolation
21. SQL injection / XSS / CSRF probes
22. Auth + token validity

### F. Synchronisation (le plus critique)
23. Where data appears (UI surfaces)
24. How sync to other systems (Echo channels)
25. Polling fallback timing
26. Latency mesured

### G. Adversarial inline (DM5)
27. Worst-case scenario
28. Race conditions
29. NF525 violation possible ?
30. Owner-impact if breaks

## §5 — Per-order flow tracking template

Phase 3 trace 3 flows :

### Flow 1 — Order BORNE
```
Idle → Catalog → Wizard → Cart → Payment → Confirmation
   ↓
   Database : orders + order_items + composition_snapshot + payment_capture_notifications
   ↓
   Audit : audit_logs (kiosk.order.created + payment.confirmed)
   ↓
   Broadcast : private-branch.{id} OrderCreated + OrderStatusChanged
   ↓
   KDS : appears on board via Echo
   ↓
   OSS : appears in PRÉPARATION column
   ↓
   Chef bumps PREPARED
   ↓
   Audit : order_status_transitions
   ↓
   OSS : moves to PRÊT column
   ↓
   Customer takes order
   ↓
   Order COMPLETE
   ↓
   End-of-day Z close
   ↓
   Audit : z_report.closed cross-chain anchor in audit_logs
   ↓
   z_reports row appended
   ↓
   Archive : backup-daily 03:00 → 30d daily + 12mo monthly + 24q quarterly = 6 years NF525
```

### Flow 2 — POS Direct Sale
```
Login admin → POS cart → Add items → Encaisser → 
Counter-collect modal → Confirm Liquide → Receipt print
   ↓
   Database : orders + order_items + transactions + cash_movements
   ↓
   Audit : audit_logs (counter_payment_confirmed)
   ↓
   Cash drawer : balance updated
   ↓
   Broadcast : OrderPaidAtCounter + OrderStatusChanged
   ↓
   KDS : appears
   ↓
   ... (continues like Flow 1)
```

### Flow 3 — Refund (HEAL-4 + K2-HEAL-07)
```
PosOrders → Past PAID order → 💸 Rembourser → Reason 5+ chars → Confirm
   ↓
   RefundWithCounterEntryService::refund
   ↓
   NEW counter-entry mirror order (parent_order_id set, total negated)
   ↓
   Audit : audit_logs (refund_with_counter_entry)
   ↓
   Cash : cash_movement TYPE_CASHBACK DIRECTION_OUT
   ↓
   Loyalty : ClawbackLoyaltyPointsOnRefund (if customer had loyalty)
   ↓
   Stock : ReleaseStockOnOrderCanceled + ReleaseAvailabilityOnOrderCanceled
   ↓
   Broadcast : RefundCreated + OrderStatusChanged
   ↓
   Receipt : REMBOURSEMENT marker
```

## §6 — Cross-system interactions (Phase 4)

| # | Interaction | Test |
|---|-------------|------|
| **I1** | Admin toggle rupture → kiosk+POS+KDS sync | Echo latency + visual |
| **I2** | Multi-cashier same kiosk-cash order | K2-HEAL-01 race 409 |
| **I3** | Borne order paid → KDS → OSS chain | E2E timing |
| **I4** | Refund → cash + stock + loyalty cascade | K2-HEAL-07 full chain |
| **I5** | Z close → audit_logs anchor | K2-HEAL-06 cross-chain |
| **I6** | Catalog change admin → all surfaces invalidate | I.3 ItemUpdated chain |

## §7 — Adversarial red-team (Phase 5)

| # | Attack | Defense |
|---|--------|---------|
| **R1** | Cross-branch leak attempt | BranchScope + channel auth |
| **R2** | NF525 chain tamper TRUNCATE | Triggers + Ansible CVP0-1 REVOKE |
| **R3** | Concurrent fiscal_sequence_no race | Cache::lock + DB FOR UPDATE |
| **R4** | RBAC escalation POS Operator → admin | Spatie + middleware |
| **R5** | Idempotency replay attack | UNIQUE + 2xx-only cache |
| **R6** | XSS / SQL injection / CSRF | Eloquent binding + Vue v-html absent + CSRF token |
| **R7** | Cloud-prep config drift | AppServiceProvider boot guards |
| **R8** | Backup integrity attack | Triggers in dump + restore drill |

## §8 — Cloud-readiness criteria (Phase 6)

| Gate | Criterion | Status pre-cycle |
|------|-----------|------------------|
| **G1** | All heals shipped + verified empirically | ✓ 6/6 (last cycle) |
| **G2** | NF525 chain integrity preserved | ✓ CHAIN OK fresh |
| **G3** | Frozen-zone 0 LOC across all 14 §7 files | ✓ verified |
| **G4** | Backup + restore drill validated | ✓ 88 tables + 9 triggers |
| **G5** | Production boot guards in place | ✓ AppServiceProvider |
| **G6** | Ansible CVP0-1 REVOKE ready | ✓ deploy/ansible/site.yml |
| **G7** | Cross-system sync verified <1s | ✓ 200ms POS / 1.3s p99 |
| **G8** | RBAC + branch isolation verified | ✓ 82 perms web + sanctum |
| **G9** | Adversarial 5+ scenarios blocked | ✓ verified |
| **G10** | Visual captures per system | TO DO this cycle |
| **G11** | Real order flow traced end-to-end | TO DO this cycle |
| **G12** | Cross-system interactions verified | TO DO this cycle |
| **G13** | Cloud deployment scripts ready | ✓ Hetzner + Ansible |
| **G14** | Owner physical walk simulation | TO DO Monday |
| **G15** | Documentation complete | ✓ GO_LIVE_CHECKLIST |

**Target** : 15/15 GO → cloud cutover authorized.

## §9 — Output structure

```
reports/test-e2e/master-ultimate-2026-05-28/
├── plan.md (this file)
├── agents/
│   ├── SYS-A-POS-ultimate.json
│   ├── SYS-B-Borne-ultimate.json
│   ├── SYS-C-KDS-ultimate.json
│   ├── SYS-D-OSS-ultimate.json
│   ├── SYS-E-Cash-ultimate.json
│   ├── SYS-F-Stock-ultimate.json
│   ├── SYS-G-Customers-ultimate.json
│   ├── SYS-H-Reporting-ultimate.json
│   ├── SYS-I-Settings-ultimate.json
│   ├── SYS-J-Sync-ultimate.json
│   ├── FLOW-1-Borne-trace.json
│   ├── FLOW-2-POS-trace.json
│   ├── FLOW-3-Refund-trace.json
│   ├── INTERACTIONS-cross-system.json
│   ├── ADV-redteam-ultimate.json
│   └── CLOUD-READINESS-synth.json
├── captures/
│   └── per-system/* (~100+ PNG expected)
└── convergence/
    └── CONVERGENCE_CLOUD_READY.md
```

## §10 — GO criteria final

Cloud cutover authorized IF:
- 15/15 G-gates GREEN
- 0 P0 introduced this cycle
- 0 new ship blocker
- Owner sign-off

ELSE: heal-wave → re-test → loop max 3 rounds.

---

**Plan ready. Phase 2 launching now.**
