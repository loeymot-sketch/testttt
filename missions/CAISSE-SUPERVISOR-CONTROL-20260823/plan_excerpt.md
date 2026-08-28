# Extrait autoritaire

TASK_ID: `CAISSE-SUPERVISOR-CONTROL-20260823`  
PHASE: `EXECUTE`  
MODEL: `gpt-5.5-pro`, reasoning `xhigh`  
PLAN_REVIEW_VERDICT: `PASS`

Le scope couvre uniquement la santé POS branch-scopée, la quarantaine offline, le cockpit SLA, les correctifs clavier/labels POS-dashboard-borne et le harness Playwright critique listés dans `input.json`.

Le diff existant vient d'une exécution interrompue : l'auditer avant de proposer les modifications restantes. Le code courant gagne sur toute hypothèse ancienne.

Hors scope absolu : Wheel, migrations, prix, paiement, statuts, `OrderService`, `FrontendOrderService`, services fiscaux gelés, routes et configuration de production. Toute nécessité dans ces zones est une `ESCALATION`.
