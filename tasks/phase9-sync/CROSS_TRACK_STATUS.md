# CROSS_TRACK_STATUS — Kiosk Phase 9 ↔ POS Phase 9

**Version.** 2026-04-18 (synced on `feat/kiosk-phase-9-5` branch creation)
**But.** Registre unique consolidé des 2 tracks en parallèle (SYNC_PROTOCOL §5).
**Mise à jour.** Par chaque commit de chaque track. Consulté par l'humain avant chaque décision de merge.

## Légende
- **status** : `open` / `in_progress` / `blocked` / `verified` / `merged` / `closed`

## Table consolidée

| Track | Phase | Wave | Item | Branch | Status | Depends on | Blocks |
|---|---|---|---|---|---|---|---|
| A | Kiosk Phase 9 | P9.1 | 9.1.1→9.1.14 (14 items P0) | feat/kiosk-phase-9-1 | **merged** (`0fd3aceac` sur main, 14/14 verified) | — | P9.2+ kiosk |
| A | Kiosk Phase 9 | P9.2 | 9.2.1→9.2.9 (9 items catalog SSOT + realtime) | feat/kiosk-phase-9-2 | **verified** (9/9 resolved, await human merge) | P9.1 merged | P9.3, P9.5 (dépendance AllergenService+FR) |
| A | Kiosk Phase 9 | P9.3 | 11 items wizard robustness | (pending) | pending | P9.2 merged | — |
| A | Kiosk Phase 9 | P9.4 | 9.4.1→9.4.12 (12 items UX completeness) | feat/kiosk-phase-9-4 | **verified** (12/12 resolved, await human merge) | P9.1 merged (parallèle P9.2/P9.3) | — |
| A | Kiosk Phase 9 | P9.5 | 9.5.1→9.5.8 (8 items order pipeline hardening) | feat/kiosk-phase-9-5 | **in_progress** (started 2026-04-18, 5 LOCK_A posés) | P9.2 backend (merged in branch) | POS-9.4 wire-ins (3 BLOCKERs), POS-9.2 backend |
| A | Kiosk Phase 9 | P9.6→P9.10 | Analytics, a11y, E2E, build | — | open | P9.5 merged | — |
| B | POS Phase 9 | POS-A (audit) | 64 findings livrées | — | closed | — | POS-B |
| B | POS Phase 9 | POS-B (plan) | 10 vagues POS-9.1→POS-9.10 | — | closed | — | POS-9.1 |
| B | POS Phase 9 | POS-9.1 | 14 items stop-the-bleed | feat/pos-phase-9-1 | **merged** (`bee6333cb` sur main, 14/14) | — | POS-9.4 |
| B | POS Phase 9 | POS-9.4 | 9.4.1 orders.fiscal_sequence_no migration | feat/pos-phase-9-4 | resolved (commit `f1bff8bfd`) | POS-9.1 | 9.4.2 |
| B | POS Phase 9 | POS-9.4 | 9.4.2a FiscalSequenceService (atomic) | feat/pos-phase-9-4 | resolved (commit `876fd560b`) | 9.4.1 | 9.4.2b wire-in (BLOCKER) |
| B | POS Phase 9 | POS-9.4 | 9.4.2b wire-in posOrderStore | — | **BLOCKED** (waits Kiosk P9.5 merge — LOCK_A OrderService) | Kiosk P9.5 merge | — |
| B | POS Phase 9 | POS-9.4 | 9.4.3 audit_logs INSERT-only | feat/pos-phase-9-4 | resolved (commit `e59910042`) | — | 9.4.4/5/11 |
| B | POS Phase 9 | POS-9.4 | 9.4.4 AuditLogService HMAC chain | feat/pos-phase-9-4 | resolved (commit `e4a2e3ad4`) | 9.4.3 | 9.4.5 |
| B | POS Phase 9 | POS-9.4 | 9.4.5 adapt sensitive call-sites | — | **BLOCKED** (waits Kiosk P9.5 merge — LOCK_A OrderService) | Kiosk P9.5 merge | — |
| B | POS Phase 9 | POS-9.4 | 9.4.6 z_reports migration | feat/pos-phase-9-4 | resolved (commit `bf090c443`) | — | 9.4.7/8/9/11 |
| B | POS Phase 9 | POS-9.4 | 9.4.7 ZReportService open/close | feat/pos-phase-9-4 | resolved (commit `480dd3cc4`) | 9.4.6 | 9.4.8/9/11 |
| B | POS Phase 9 | POS-9.4 | 9.4.8 XReportService snapshot | feat/pos-phase-9-4 | resolved (commit `7c0306e3f`) | 9.4.7 | 9.4.9 |
| B | POS Phase 9 | POS-9.4 | 9.4.9 /admin/fiscal/* endpoints | feat/pos-phase-9-4 | resolved (commit `33b56cb7a`) | 9.4.7/8 | — |
| B | POS Phase 9 | POS-9.4 | 9.4.10 destroy() blocked after Z | — | **BLOCKED** (waits Kiosk P9.5 merge — LOCK_A OrderService) | Kiosk P9.5 merge | — |
| B | POS Phase 9 | POS-9.4 | 9.4.11 FiscalArchiveCommand | feat/pos-phase-9-4 | resolved (commit `3a9d29f98`) | 9.4.3/6/7 | — |
| B | POS Phase 9 | POS-9.4 | 9.4.12 pos-manage-fiscal / pos-reopen-z | feat/pos-phase-9-4 | resolved (commit `dc4e57978`) | — | — |
| B | POS Phase 9 | POS-9.2/9.3/9.5→9.10 | reste du plan POS | (pending) | pending | Kiosk P9.5 merge | — |

## Locks shared actifs (Track A Kiosk P9.5)

- `tasks/phase9-sync/LOCK_A_P9_5_FrontendOrderService_2026-04-18.md` — ACTIVE. Fichier : `app/Services/FrontendOrderService.php`. Release : après commit 9.5.1.
- `tasks/phase9-sync/LOCK_A_P9_5_OrderService_2026-04-18.md` — ACTIVE (préventif, no edit programmée). Fichier : `app/Services/OrderService.php`. Release : fin de P9.5 + merge + BROADCAST.
- `tasks/phase9-sync/LOCK_A_P9_5_PricingService_PricingRequests_2026-04-18.md` — ACTIVE. Fichiers : `app/Services/PricingService.php` (noop par défaut) + `app/Http/Requests/{Pricing,PosPricing,TablePricing,WebPricing}Request.php`. Release : après commit 9.5.6.
- `tasks/phase9-sync/LOCK_A_P9_5_OrderItem_migration_allergens_2026-04-18.md` — ACTIVE. Fichiers : `app/Models/OrderItem.php` + `database/migrations/<TS>_add_allergens_snapshot_to_order_items.php`. Release : après commit 9.5.1.
- `tasks/phase9-sync/LOCK_A_P9_5_idempotency_key_migration_2026-04-18.md` — ACTIVE. Fichier : `database/migrations/<TS>_scope_idempotency_key_to_branch.php`. Release : après commit 9.5.4.

## BLOCKERs ouverts (attendent Kiosk P9.5 merge)

- `tasks/phase9-sync/BLOCKER_POS_9_4_2b_OrderService_posOrderStore_2026-04-18.md`
- `tasks/phase9-sync/BLOCKER_POS_9_4_5_AuditLog_call_sites_2026-04-18.md`
- `tasks/phase9-sync/BLOCKER_POS_9_4_10_destroy_after_Z_2026-04-18.md`

## Broadcasts récents

- 2026-04-18 — Track A : P9.1 mergée sur `main` (`0fd3aceac`, 14/14 verified).
- 2026-04-18 — Track A : P9.2 livrée sur branche (`d59d50d7b`, 9/9 verified, pending human merge).
- 2026-04-18 — Track A : P9.4 livrée sur branche (`84cfde998`, 12/12 verified, pending human merge).
- 2026-04-18 — Track A : P9.5 ouverte sur `feat/kiosk-phase-9-5` (baseline = P9.2 + main merged, 5 LOCK_A posés).
- 2026-04-18 — Track B : POS-9.1 mergée sur `main` (`bee6333cb`).
- 2026-04-18 — Track B : POS-9.4 livrée (branche `feat/pos-phase-9-4`, 9 items livrables / 3 BLOCKERs OrderService).
