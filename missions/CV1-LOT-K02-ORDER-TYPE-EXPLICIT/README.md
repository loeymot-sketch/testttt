# Mission CV1-LOT-K02-ORDER-TYPE-EXPLICIT

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-K02-ORDER-TYPE-EXPLICIT
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K02-ORDER-TYPE-EXPLICIT execute "allowlist from input.json" "W2 K-02"
npm run codex:complex -- CV1-LOT-K02-ORDER-TYPE-EXPLICIT
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
