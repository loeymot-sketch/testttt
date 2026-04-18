# FoodKing — Playwright / E2E Reports

Dossier des rapports E2E générés par Playwright MCP (Phase 3).

## Fichiers
- `latest.md` — rapport du dernier run (écrasé à chaque cycle)
- `playwright-[TASK_ID]-[DATE].json` — archives JSON par cycle

## Flows couverts
1. Auth refresh — F5 sur /admin/pos → redirection correcte
2. POS Cash — login caissier → item → cash → KDS
3. KDS — login chef → PREPARING → PREPARED → OSS
4. POS Card — login caissier → item → carte → KDS
5. Kiosk — idle → type → menu → paiement → ticket → OSS

## Rapport de référence
RAPPORT_AUDIT_FINAL_CONSOLIDE_20260310.md — baseline de référence du projet
