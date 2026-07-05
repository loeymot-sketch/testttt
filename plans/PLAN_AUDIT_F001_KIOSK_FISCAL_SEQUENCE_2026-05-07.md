# PLAN AUDIT F-001 — Kiosk Fiscal Sequence (2026-05-07)

> **Reconstruit 2026-07-02 (ultra-audit).** L'original vivait dans un worktree transitoire
> (`.claude/worktrees/blissful-mclean-c915c2`) supprimé depuis. La traçabilité de clôture
> est préservée par le rapport d'exécution (contenu réel des findings + preuves).

## Portée
Audit de l'allocation de la séquence fiscale NF525 côté borne (kiosk) : la séquence est-elle
allouée gap-free, monotone, et au bon moment (paiement kiosk = à création vs Plan B counter =
à l'encaissement) ?

## Invariants vérifiés (voir sentinelle `F001KioskFiscalSequenceInvariantSentinelTest`)
- `FrontendOrderService` : pas de double allocation, Plan B → PENDING_COUNTER sans seq.
- `PaymentService` : allocation à l'encaissement (counter-collect confirm).
- `ZReportService` : clôture cohérente.

## Résultat / clôture
→ **`reports/execution/audit_2026-05-07/REPORT_F001_kiosk_fiscal_sequence.md`** (artefact de closure).
Chaîne NF525 re-vérifiée CHAIN OK (4 branches) le 2026-07-02.
