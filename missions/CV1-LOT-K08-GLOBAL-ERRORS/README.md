# Mission CV1-LOT-K08-GLOBAL-ERRORS

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-K08-GLOBAL-ERRORS
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K08-GLOBAL-ERRORS execute "allowlist from input.json" "W2 K-08"
npm run codex:complex -- CV1-LOT-K08-GLOBAL-ERRORS
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
