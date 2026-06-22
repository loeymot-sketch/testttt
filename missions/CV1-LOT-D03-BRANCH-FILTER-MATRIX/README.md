# Mission CV1-LOT-D03-BRANCH-FILTER-MATRIX

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-D03-BRANCH-FILTER-MATRIX
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-D03-BRANCH-FILTER-MATRIX execute "allowlist from input.json" "W2 D-03"
npm run codex:complex -- CV1-LOT-D03-BRANCH-FILTER-MATRIX
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
