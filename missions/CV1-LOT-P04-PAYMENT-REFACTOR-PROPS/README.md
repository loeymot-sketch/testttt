# Mission CV1-LOT-P04-PAYMENT-REFACTOR-PROPS

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-P04-PAYMENT-REFACTOR-PROPS
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P04-PAYMENT-REFACTOR-PROPS execute "allowlist from input.json" "W2 P-04"
npm run codex:complex -- CV1-LOT-P04-PAYMENT-REFACTOR-PROPS
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
