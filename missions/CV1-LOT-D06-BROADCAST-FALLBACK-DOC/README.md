# Mission CV1-LOT-D06-BROADCAST-FALLBACK-DOC

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-D06-BROADCAST-FALLBACK-DOC
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-D06-BROADCAST-FALLBACK-DOC execute "allowlist from input.json" "W2 D-06"
npm run codex:complex -- CV1-LOT-D06-BROADCAST-FALLBACK-DOC
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
