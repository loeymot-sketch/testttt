# CROSS_TRACK_STATUS — Kiosk Phase 9 ↔ POS Phase 9

**Version.** 2026-04-18 (resolved merge HEAD ←→ `feat/kiosk-phase-9-5`)
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
| A | Kiosk Phase 9 | P9.5 | 9.5.1→9.5.8 (8 items order pipeline hardening) | feat/kiosk-phase-9-5 | **merging → main** (merge commit en cours, 8/8 verified, BROADCAST published) | P9.2 backend (merged in branch) | POS-9.4 wire-ins (3 BLOCKERs), POS-9.2 backend |
| A | Kiosk Phase 9 | P9.6→P9.10 | Analytics, a11y, E2E, build | — | open | P9.5 merged | — |
| B | POS Phase 9 | POS-A (audit) | 64 findings livrées | — | closed | — | POS-B |
| B | POS Phase 9 | POS-B (plan) | 10 vagues POS-9.1→POS-9.10 | — | closed | — | POS-9.1 |
| B | POS Phase 9 | POS-9.1 | 14 items stop-the-bleed | feat/pos-phase-9-1 | **merged** (`bee6333cb` sur main, 14/14) | — | POS-9.4 |
| B | POS Phase 9 | POS-9.4 | 9.4.1 orders.fiscal_sequence_no migration | feat/pos-phase-9-4 | resolved (commit `f1bff8bfd`) | POS-9.1 | 9.4.2 |
| B | POS Phase 9 | POS-9.4 | 9.4.2a FiscalSequenceService (atomic) | feat/pos-phase-9-4 | resolved (commit `876fd560b`) | 9.4.1 | 9.4.2b wire-in (BLOCKER) |
| B | POS Phase 9 | POS-9.4 | 9.4.2b wire-in posOrderStore | — | **BLOCKED → unblocked par P9.5 merge** (will be resolved as POS-9.4.BL.1 on `feat/pos-phase-9-2-3`) | Kiosk P9.5 merged ✅ | — |
| B | POS Phase 9 | POS-9.4 | 9.4.3 audit_logs INSERT-only | feat/pos-phase-9-4 | resolved (commit `e59910042`) | — | 9.4.4/5/11 |
| B | POS Phase 9 | POS-9.4 | 9.4.4 AuditLogService HMAC chain | feat/pos-phase-9-4 | resolved (commit `e4a2e3ad4`) | 9.4.3 | 9.4.5 |
| B | POS Phase 9 | POS-9.4 | 9.4.5 adapt sensitive call-sites | — | **BLOCKED → unblocked par P9.5 merge** (will be resolved as POS-9.4.BL.2) | Kiosk P9.5 merged ✅ | — |
| B | POS Phase 9 | POS-9.4 | 9.4.6 z_reports migration | feat/pos-phase-9-4 | resolved (commit `bf090c443`) | — | 9.4.7/8/9/11 |
| B | POS Phase 9 | POS-9.4 | 9.4.7 ZReportService open/close | feat/pos-phase-9-4 | resolved (commit `480dd3cc4`) | 9.4.6 | 9.4.8/9/11 |
| B | POS Phase 9 | POS-9.4 | 9.4.8 XReportService snapshot | feat/pos-phase-9-4 | resolved (commit `7c0306e3f`) | 9.4.7 | 9.4.9 |
| B | POS Phase 9 | POS-9.4 | 9.4.9 /admin/fiscal/* endpoints | feat/pos-phase-9-4 | resolved (commit `33b56cb7a`) | 9.4.7/8 | — |
| B | POS Phase 9 | POS-9.4 | 9.4.10 destroy() blocked after Z | — | **BLOCKED → unblocked par P9.5 merge** (will be resolved as POS-9.4.BL.3) | Kiosk P9.5 merged ✅ | — |
| B | POS Phase 9 | POS-9.4 | 9.4.11 FiscalArchiveCommand | feat/pos-phase-9-4 | resolved (commit `3a9d29f98`) | 9.4.3/6/7 | — |
| B | POS Phase 9 | POS-9.4 | 9.4.12 pos-manage-fiscal / pos-reopen-z | feat/pos-phase-9-4 | resolved (commit `dc4e57978`) | — | — |
| B | POS Phase 9 | Phase H Hardening | 26 items (H.1 + H.2 + H.3) | feat/pos-phase-9-hardening | **merged** (merge commit `3914ae059` sur main, Gate H PASS, 81 Fiscal + 17 Vitest green, CI invariants 6/6) | POS-9.4 | POS-9.2 |
| B | POS Phase 9 | POS-9.4.BL (pré-vague) | BL.1 fiscal seq wire-in + allergen snapshot, BL.2 audit log call-sites, BL.3 destroy after Z guard (3 items, 3 commits atomiques) | feat/pos-phase-9-2-3 (to create) | in_progress | P9.5 merged ✅, Phase H merged ✅ | POS-9.2 |
| B | POS Phase 9 | POS-9.2 | State machine canonicalisation (10 items) | feat/pos-phase-9-2-3 | pending | POS-9.4.BL | POS-9.3 |
| B | POS Phase 9 | POS-9.3 | Multi-tender + events canoniques (10 items) | feat/pos-phase-9-2-3 | pending | POS-9.2 | POS-9.INT |
| B | POS Phase 9 | POS-9.INT | E2E cross-surface + BROADCAST Track A (2 items) | feat/pos-phase-9-2-3 | pending | POS-9.3 | #P-1 stock sync |
| B | POS Phase 9 | POS-9.5→9.10 | Cash drawer, drawer kiosk cash, payment UX, fin journée, offline, outbox hardening | — | pending | POS-9.INT | — |

## Locks shared actifs

**Track A Kiosk P9.5** — tous RELEASED par le merge vers main :

- `LOCK_A_P9_5_FrontendOrderService_2026-04-18.md` — RELEASED (commit 9.5.1 landed).
- `LOCK_A_P9_5_OrderService_2026-04-18.md` — RELEASED (préventif ; fichier non-édité par Track A, merge P9.5 libère Track B).
- `LOCK_A_P9_5_PricingService_PricingRequests_2026-04-18.md` — RELEASED (commit 9.5.6 landed).
- `LOCK_A_P9_5_OrderItem_migration_allergens_2026-04-18.md` — RELEASED (migration `2026_04_18_140004` landed).
- `LOCK_A_P9_5_idempotency_key_migration_2026-04-18.md` — RELEASED (migration `2026_04_18_140003` landed).

**Track B (à poser à démarrage POS-9.4.BL)** — voir `PLAN_POS_9_2_ET_9_3_2026-04-18.md` §1/§2/§3.

## BLOCKERs

**Unblocked par P9.5 merge + Phase H merge (à clôturer par POS-9.4.BL sur `feat/pos-phase-9-2-3`)** :

- `BLOCKER_POS_9_4_2b_OrderService_posOrderStore_2026-04-18.md` → POS-9.4.BL.1.
- `BLOCKER_POS_9_4_5_AuditLog_call_sites_2026-04-18.md` → POS-9.4.BL.2.
- `BLOCKER_POS_9_4_10_destroy_after_Z_2026-04-18.md` → POS-9.4.BL.3.

## Broadcasts récents

- 2026-04-18 — Track A : P9.1 mergée sur `main` (`0fd3aceac`, 14/14 verified).
- 2026-04-18 — Track A : P9.2 livrée sur branche (`d59d50d7b`, 9/9 verified, pending human merge).
- 2026-04-18 — Track A : P9.4 livrée sur branche (`84cfde998`, 12/12 verified, pending human merge).
- 2026-04-18 — Track A : P9.5 ouverte sur `feat/kiosk-phase-9-5` (baseline = P9.2 + main merged, 5 LOCK_A posés).
- 2026-04-18 — Track A : P9.5 **merging vers main** (8/8 verified, BROADCAST `BROADCAST_P9_5_MERGED_2026-04-18.md` published, 5 LOCK_A released).
- 2026-04-18 — Track B : POS-9.1 mergée sur `main` (`bee6333cb`).
- 2026-04-18 — Track B : POS-9.4 livrée (branche `feat/pos-phase-9-4`, 9 items livrables / 3 BLOCKERs OrderService).
- 2026-04-18 — Track B : **Phase H Hardening mergée sur `main`** (merge commit `3914ae059`, 26 atomic commits + 2 docs, Gate H PASS, VISION + PLAN POS-9.2/9.3 landed).
- 2026-04-18 — Track B : POS-9.4.BL démarre sur `feat/pos-phase-9-2-3` dès P9.5 merge finalisé (plan §0 armé).
