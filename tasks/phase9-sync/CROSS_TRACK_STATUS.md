# CROSS_TRACK_STATUS — Kiosk Phase 9 ↔ POS Phase 9

**Version.** 2026-04-18
**But.** Registre unique consolidé des 2 tracks en parallèle (SYNC_PROTOCOL §5).
**Mise à jour.** Par chaque commit de chaque track. Consulté par l'humain avant chaque décision de merge.

## Légende
- **status** : `open` / `in_progress` / `blocked` / `merged` / `closed`

## Table consolidée

| Track | Phase | Wave | Item | Branch | Status | Depends on | Blocks |
|---|---|---|---|---|---|---|---|
| A | Kiosk Phase 9 | P9.1 | 9.1.1→9.1.14 (14 items) | feat/kiosk-phase-9-1 | **merged main** (baseline) | — | — |
| A | Kiosk Phase 9 | P9.2 | 9.2.1→9.2.9 (9 items) | feat/kiosk-phase-9-2 | **verified 9/9** — pending human merge | P9.1 | P9.3 (role enum baseline) |
| A | Kiosk Phase 9 | P9.4 | 9.4.1→9.4.12 (12 items) | feat/kiosk-phase-9-4 | **verified 12/12** — pending human merge | P9.1 | — |
| A | Kiosk Phase 9 | P9.5 | 9.5.1→9.5.8 (8 items) | feat/kiosk-phase-9-5 | **verified 8/8** — pending human merge | P9.1+P9.2 | POS-9.4 wire-ins (OrderService, once merged) |
| A | Kiosk Phase 9 | P9.3 | 9.3.1→9.3.15 (15 items, 11 baseline + 4 robustness ext) | feat/kiosk-phase-9-3 | **verified 15/15** — pending human merge (HEAD `d8cc15857`) | P9.2 (role enum, allergens FR, AllergenService) | P9.4 next-wave-handoff |
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
| B | POS Phase 9 | POS-9.1 | 9.1.14 allergens admin | feat/pos-phase-9-1 | resolved (commit) | sync kiosk | POS-9.9 |

## Locks shared actifs

| Lock | Scope | Owner | Opened | Status | File |
|---|---|---|---|---|---|
| LOCK_A_P9_3_ItemAttribute | `app/Models/ItemAttribute.php` + migration `role` enum | Kiosk P9.3 (item 9.3.1) | 2026-04-18 | **RELEASED** (SHA `3f0d86f9b`) | `tasks/phase9-sync/LOCK_A_P9_3_ItemAttribute_2026-04-18.md` |

## Broadcasts récents

- 2026-04-18 — `BROADCAST_P9_5_MERGED_2026-04-18.md` (préparé sur `feat/kiosk-phase-9-5`, diffusé au merge humain). Débloquera Track B POS-9.4.2b / .5 / .10 (`OrderService` LOCK_A released).
- 2026-04-18 — **À diffuser en fin P9.3** : `BROADCAST_P9_3_MERGED_<DATE>.md` avec exposition de `item_attributes.role` pour usage optionnel POS.
