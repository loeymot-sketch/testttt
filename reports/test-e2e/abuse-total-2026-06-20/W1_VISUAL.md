W1 POS visual 2026-06-20 (pos@ operator, :8766)
- POS main: CLEAN (money FR 5,30€/0,00€, labels resolved, empty-states, availability banner, data-testid on buttons)
- Encaissement modal: CLEAN (MONTANT TOTAL 5,30€, 4 tenders w/ simulation labels, montant-reçu pristine-select, full keypad, X close)
- 196 console 401s = ARTIFACT of localStorage.clear mid-session (NOT a defect); P3 obs: poll-401 silent no session-expired UX

## W1 POS/Caisse — VALIDATED 2026-06-20
- Visual: POS main + encaissement modal CLEAN (FR money, labels, empty-states, data-testid).
- Invariant tests: 259 Cash/Encaissement + 125 Abuse = 384 GREEN (variance gate, double-close, audit-rollback, savepoint, broadcast-never-loses-row).
- LIVE abuse (pos@ token, :8766): encaisser 4974 → PAID + fiscal 2505 ; double-collect (same cashier) → idempotent 200, NO 2nd fiscal, gap-free ; chain OK 4 branches.
- Code review confirmCounterPayment: same-cashier no-op 200 (V5.5) / diff-cashier 409 (K2-HEAL-01) / sub-pay 422 / mode-invalid 422 / lockForUpdate + fiscal-seal. ROBUST.
- 2 false-positives REFUTED by verify-before-report: (a) 196 console-401s = my localStorage.clear artifact ; (b) double-collect-200 = intentional same-cashier idempotency.
- 0 new P0/P1. Manual coverage: cash-drawer/Z + encaissement/payment. NOT manually probed: floorplan/parked/refund/wizard → Workflow breadth retry.
