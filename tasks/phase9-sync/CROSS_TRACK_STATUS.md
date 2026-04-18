# CROSS_TRACK_STATUS — Kiosk Phase 9 ↔ POS Phase 9

**Version.** 2026-04-18
**But.** Registre unique consolidé des 2 tracks en parallèle (SYNC_PROTOCOL §5).
**Mise à jour.** Par chaque commit de chaque track. Consulté par l'humain avant chaque décision de merge.

## Légende
- **status** : `open` / `in_progress` / `blocked` / `merged` / `closed`

## Table consolidée

| Track | Phase | Wave | Item | Branch | Status | Depends on | Blocks |
|---|---|---|---|---|---|---|---|
| A | Kiosk Phase 9 | P9.1 | 9.1.1→9.1.14 (14 items) | feat/kiosk-phase-9-1 | merged (sur main `0fd3aceac`) | — | P9.2+ kiosk |
| A | Kiosk Phase 9 | P9.2 | catalog SSOT + real-time hardening | feat/kiosk-phase-9-2 (estimated) | in_progress | P9.1 merged | P9.3 |
| A | Kiosk Phase 9 | P9.5 | state machine shared | (pending) | pending | P9.4 | POS-9.4 wire-ins, POS-9.2, POS-9.5 |
| B | POS Phase 9 | POS-A (audit) | 64 findings livrées | — | closed | — | POS-B |
| B | POS Phase 9 | POS-B (plan) | 10 vagues POS-9.1→POS-9.10 | — | closed | — | POS-9.1 |
| B | POS Phase 9 | POS-9.1 | 9.1.1 discount permission | feat/pos-phase-9-1 | resolved (commit) | — | POS-9.2 |
| B | POS Phase 9 | POS-9.1 | 9.1.2 destroy() sécurisé | feat/pos-phase-9-1 | resolved (commit) | — | POS-9.4 |
| B | POS Phase 9 | POS-9.1 | 9.1.3 BranchIsolationTest | feat/pos-phase-9-1 | resolved (commit) | — | — |
| B | POS Phase 9 | POS-9.1 | 9.1.4 action_logs.branch_id | feat/pos-phase-9-1 | resolved (commit) | — | POS-9.4 |
| B | POS Phase 9 | POS-9.1 | 9.1.5 KDS LIKE→= | feat/pos-phase-9-1 | resolved (commit) | — | — |
| B | POS Phase 9 | POS-9.1 | 9.1.6 dine-in flag | feat/pos-phase-9-1 | resolved (commit) | — | — |
| B | POS Phase 9 | POS-9.1 | 9.1.7 deliveryBoy save→dispatch | feat/pos-phase-9-1 | resolved (commit) | — | POS-9.2 |
| B | POS Phase 9 | POS-9.1 | 9.1.8 PosOrderRequest nullable | feat/pos-phase-9-1 | resolved (commit) | — | — |
| B | POS Phase 9 | POS-9.1 | 9.1.9 posCart scoped | feat/pos-phase-9-1 | resolved (commit) | — | — |
| B | POS Phase 9 | POS-9.1 | 9.1.10 POS subscribe Item86 | feat/pos-phase-9-1 | resolved (commit) | — | — |
| B | POS Phase 9 | POS-9.1 | 9.1.11 Son+toast new order | feat/pos-phase-9-1 | resolved (commit) | — | — |
| B | POS Phase 9 | POS-9.1 | 9.1.12 cash drawer on cash | feat/pos-phase-9-1 | resolved (commit) | — | POS-9.5 |
| B | POS Phase 9 | POS-9.1 | 9.1.13 TVA ventilée ticket | feat/pos-phase-9-1 | resolved (commit) | — | POS-9.8 |
| B | POS Phase 9 | POS-9.1 | 9.1.14 allergens admin | feat/pos-phase-9-1 | resolved (merged `bee6333cb`) | sync kiosk | POS-9.9 |
| B | POS Phase 9 | POS-9.4 | 9.4.1 orders.fiscal_sequence_no migration | feat/pos-phase-9-4 | resolved (commit `f1bff8bfd`) | POS-9.1 | POS-9.4.2 |
| B | POS Phase 9 | POS-9.4 | 9.4.2a FiscalSequenceService (atomic) | feat/pos-phase-9-4 | resolved (commit `876fd560b`) | 9.4.1 | 9.4.2b wire-in (BLOCKER) |
| B | POS Phase 9 | POS-9.4 | 9.4.2b wire-in posOrderStore | — | **BLOCKED** (OrderService frozen — Kiosk P9.5) | Kiosk P9.5 merge | — |
| B | POS Phase 9 | POS-9.4 | 9.4.3 audit_logs INSERT-only | feat/pos-phase-9-4 | resolved (commit `e59910042`) | — | 9.4.4/5/11 |
| B | POS Phase 9 | POS-9.4 | 9.4.4 AuditLogService HMAC chain | feat/pos-phase-9-4 | resolved (commit `e4a2e3ad4`) | 9.4.3 | 9.4.5 |
| B | POS Phase 9 | POS-9.4 | 9.4.5 adapt sensitive call-sites | — | **BLOCKED** (OrderService frozen — Kiosk P9.5) | Kiosk P9.5 merge | — |
| B | POS Phase 9 | POS-9.4 | 9.4.6 z_reports migration | feat/pos-phase-9-4 | resolved (commit `bf090c443`) | — | 9.4.7/8/9/11 |
| B | POS Phase 9 | POS-9.4 | 9.4.7 ZReportService open/close | feat/pos-phase-9-4 | resolved (commit `480dd3cc4`) | 9.4.6 | 9.4.8/9/11 |
| B | POS Phase 9 | POS-9.4 | 9.4.8 XReportService snapshot | feat/pos-phase-9-4 | resolved (commit `7c0306e3f`) | 9.4.7 | 9.4.9 |
| B | POS Phase 9 | POS-9.4 | 9.4.9 /admin/fiscal/* endpoints | feat/pos-phase-9-4 | resolved (commit `33b56cb7a`) | 9.4.7/8 | — |
| B | POS Phase 9 | POS-9.4 | 9.4.10 destroy() blocked after Z | — | **BLOCKED** (OrderService frozen — Kiosk P9.5) | Kiosk P9.5 merge | — |
| B | POS Phase 9 | POS-9.4 | 9.4.11 FiscalArchiveCommand | feat/pos-phase-9-4 | resolved (commit `3a9d29f98`) | 9.4.3/6/7 | — |
| B | POS Phase 9 | POS-9.4 | 9.4.12 pos-manage-fiscal / pos-reopen-z | feat/pos-phase-9-4 | resolved (commit `dc4e57978`) | — | — |

## Locks shared actifs

(aucun — Track B a livré POS-9.4 sans toucher à OrderService ; trois BLOCKERs posés en attente de Kiosk P9.5)

## BLOCKERs ouverts (attendent Kiosk P9.5)

- `tasks/phase9-sync/BLOCKER_POS_9_4_2b_OrderService_posOrderStore_2026-04-18.md` (wire FiscalSequenceService dans posOrderStore)
- `tasks/phase9-sync/BLOCKER_POS_9_4_5_AuditLog_call_sites_2026-04-18.md` (adapter cancel/destroy/discount/refund/payment_status à AuditLogService)
- `tasks/phase9-sync/BLOCKER_POS_9_4_10_destroy_after_Z_2026-04-18.md` (destroy 409 quand order scellée par Z fermé)

## Broadcasts récents

- 2026-04-18 — Track B : POS-9.1 mergée sur `main` (`bee6333cb`).
- 2026-04-18 — Track B : POS-9.4 livrée (branche `feat/pos-phase-9-4`, 9 items implementables / 3 BLOCKERs OrderService).
