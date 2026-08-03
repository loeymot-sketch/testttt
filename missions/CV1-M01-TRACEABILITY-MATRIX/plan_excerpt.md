# Plan Excerpt — CV1-M01-TRACEABILITY-MATRIX

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § M-01

M-01 — `CAISSE_V1_TRACEABILITY_COMPLETE_2026-04-25` (NO-GATE)

But: passer `TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` de `INITIAL_NOT_FINAL` à table **machine-checkable** `COMPLETE` (CSV + script de vérif).

Allowlist: `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md`, `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.csv` (NEW), `scripts/check-traceability.sh` (NEW). Hors scope: tout code produit.

Entrées source: MEGA_RAPPORT_FINAL_DISPUTE, AUDIT_TOTAL_SYSTEME, AUDIT BORNE/KIOSK, MASTER_REQUEST, CLAUDE_SUPER_MASTER, MASTER_REVIEW finitions, etc. (voir `input.json` → `source_reports`).

Critères PASS: 0 P0 sans PLAN-ID; 0 P0 sans test ou `PREUVE_MANQUANTE`; `scripts/check-traceability.sh` exit 0.
