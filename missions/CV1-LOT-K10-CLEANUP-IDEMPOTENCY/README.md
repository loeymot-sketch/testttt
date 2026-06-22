# Mission CV1-LOT-K10-CLEANUP-IDEMPOTENCY

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-K10-CLEANUP-IDEMPOTENCY
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K10-CLEANUP-IDEMPOTENCY execute "allowlist from input.json" "W2 K-10"
npm run codex:complex -- CV1-LOT-K10-CLEANUP-IDEMPOTENCY
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
