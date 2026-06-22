# PHASE G — Closure Proofs

Status: BLOCKED_PHASE_A_UNSIGNED
Owner: Codex + human where physical/signature evidence is required.

## Goal

Produce a defensible V1 release packet. Passing tests alone is not enough.

## Tasks

### G.1 `CV1-CLOSURE-FULL-TEST-SUITE`

Commands:
- `php artisan test`
- `npm run vitest`
- `npx playwright test`
- `bash scripts/lint-fk-bundle-legacy.sh strict`
- `bash scripts/lint-fk-branch-isolation.sh`
- `bash scripts/lint-fk-enum-status.sh`

Exit criteria:
- 0 failed tests, or explicit signed HOLD for non-release blocker.

### G.2 `CV1-CUTOVER-LEGACY-BUNDLES-DECISION`

Exit criteria:
- shim signed or purged.
- strict legacy scan PASS.

### G.3 `CV1-REHEARSAL-V1-STAGING`

Objective: multi-role staging rehearsal with evidence.

Evidence:
- screenshots
- timestamps
- test account list
- failure/retry log

Exit criteria:
- admin, cashier, chef, kiosk, OSS flows complete on target-like environment.

### G.4 `CV1-HARDWARE-UAT-V1`

Owner: human primary.

Evidence:
- printer receipt
- cash drawer
- kiosk device
- scanner/barcode if in scope
- network-loss scenario
- signed hardware grid

### G.5 `CV1-FISCAL-PROOF-V1`

Objective: fiscal evidence packet for Option B POS finalize.

Evidence:
- Z-report signed output
- HMAC chain verification
- void/refund policy proof
- compliance caveat

### G.6 `CV1-MEMORY-MANIFEST-SIGN-V1`

Objective: freeze memory manifest and decision log for V1.

Commands:
- `bash scripts/after-execute-memory.sh`
- `python3 memory/verify.py` if Graphiti stack is available

Exit criteria:
- manifest exists.
- count and included JSONL files documented.

### G.7 `CV1-CLOSURE-AUDIT-INDEPENDENT`

Objective: final independent audit.

Required inputs:
- all phase reports
- release packet
- final test logs
- gate log
- dirty worktree summary

Exit criteria:
- `AUDIT_VERDICT: PASS` and `GPT_FINAL_AUDIT_VERDICT: PASS`, or explicit `HOLD`.

## Final Packet

Create:

`reports/release/CAISSE_V1_RELEASE_DECISION_PACKET_2026-04-26.md`

Required verdict values:
- `GO`
- `HOLD`
- `NO_GO`
