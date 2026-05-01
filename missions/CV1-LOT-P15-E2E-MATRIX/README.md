# Mission CV1-LOT-P15-E2E-MATRIX

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-P15-E2E-MATRIX
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P15-E2E-MATRIX execute "allowlist from input.json" "W2 P-15"
npm run codex:complex -- CV1-LOT-P15-E2E-MATRIX
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
