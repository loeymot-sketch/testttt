# Mission CV1-LOT-K07-WIZARD-UNIFY

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-K07-WIZARD-UNIFY
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K07-WIZARD-UNIFY execute "allowlist from input.json" "W2 K-07"
npm run codex:complex -- CV1-LOT-K07-WIZARD-UNIFY
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
