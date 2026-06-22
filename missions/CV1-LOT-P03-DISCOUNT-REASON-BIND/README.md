# Mission CV1-LOT-P03-DISCOUNT-REASON-BIND

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-P03-DISCOUNT-REASON-BIND
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P03-DISCOUNT-REASON-BIND execute "allowlist from input.json" "W2 P-03"
npm run codex:complex -- CV1-LOT-P03-DISCOUNT-REASON-BIND
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
