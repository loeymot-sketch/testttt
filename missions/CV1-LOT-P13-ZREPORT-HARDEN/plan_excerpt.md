# Plan Excerpt — CV1-LOT-P13-ZREPORT-HARDEN

Source run order: `missions/W2_LOT_CODEX_RUN_ORDER_OPTION_B_2026-04-26.md`  
Source plans:

- `reports/audit/CLAUDE_CAISS_V1_FULLSTACK_ORCHESTRATION_PLAN_2026-04-26.md`
- `reports/audit/CLAUDE_DATA_CENTRAL_SYNC_GLOBAL_MASTER_2026-04-26.md`
- `reports/audit/CLAUDE_POS_ORDER_FLOW_MASTER_PLAN_2026-04-26.md`
- `reports/audit/CLAUDE_KIOSK_ORDER_FLOW_MASTER_PLAN_2026-04-26.md`
- `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md`
- `plans/masterplay/MASTERPLAY_QUEUE.md`
- `AGENTS.md`

Lot: `P-13`  
Family: `POS`  
Objective: Z idempotent par branch_id/business_date et alert sur gap ticket.

Invariants:

- branch_id
- commit_before_dispatch
