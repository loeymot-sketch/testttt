# CROSS_TRACK_STATUS — Kiosk Phase 9 ↔ POS Phase 9

**Version.** 2026-04-18
**But.** Registre unique consolidé des 2 tracks en parallèle (SYNC_PROTOCOL §5).
**Mise à jour.** Par chaque commit de chaque track. Consulté par l'humain avant chaque décision de merge.

## Légende
- **status** : `open` / `in_progress` / `blocked` / `merged` / `closed`

## Table consolidée

| Track | Phase | Wave | Item | Branch | Status | Depends on | Blocks |
|---|---|---|---|---|---|---|---|
| A | Kiosk Phase 9 | P9.1 | 9.1.1→9.1.14 (14 items) | feat/kiosk-phase-9-1 | in_progress (commits locaux, non mergé main) | — | P9.2+ kiosk |
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
| B | POS Phase 9 | POS-9.1 | 9.1.10 POS subscribe Item86 | feat/pos-phase-9-1 | open | — | — |
| B | POS Phase 9 | POS-9.1 | 9.1.11 Son+toast new order | feat/pos-phase-9-1 | open | — | — |
| B | POS Phase 9 | POS-9.1 | 9.1.12 cash drawer on cash | feat/pos-phase-9-1 | open | — | POS-9.5 |
| B | POS Phase 9 | POS-9.1 | 9.1.13 TVA ventilée ticket | feat/pos-phase-9-1 | open | — | POS-9.8 |
| B | POS Phase 9 | POS-9.1 | 9.1.14 allergens admin | feat/pos-phase-9-1 | open | sync kiosk | POS-9.9 |

## Locks shared actifs

(aucun — mis à jour à la pose / release)

## Broadcasts récents

(aucun — mis à jour en fin de vague)
