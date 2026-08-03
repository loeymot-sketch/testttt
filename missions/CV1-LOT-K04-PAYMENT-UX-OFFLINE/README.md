# Mission CV1-LOT-K04-PAYMENT-UX-OFFLINE

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-K04-PAYMENT-UX-OFFLINE
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K04-PAYMENT-UX-OFFLINE execute "allowlist from input.json" "W2 K-04"
npm run codex:complex -- CV1-LOT-K04-PAYMENT-UX-OFFLINE
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
