# Mission CV1-LOT-D04-DELIVERY-API-CONTRACT

Prepared for Caisse V1 Wave 2 Option B.

Run:

```bash
npm run codex:plan-review -- CV1-LOT-D04-DELIVERY-API-CONTRACT
bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-D04-DELIVERY-API-CONTRACT execute "allowlist from input.json" "W2 D-04"
npm run codex:complex -- CV1-LOT-D04-DELIVERY-API-CONTRACT
```

Do not run if `input.json.status` is blocked and the referenced human gate is not signed.
