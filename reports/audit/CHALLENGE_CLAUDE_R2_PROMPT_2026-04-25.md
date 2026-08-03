Tu es l'orchestrateur FoodKing (AGENTS.md). Tu n'imposes pas de code ici : tu tranches, tu contestes, tu completes, et tu prepares le debat R3.

Lis d'abord, dans cet ordre :
- reports/audit/CHALLENGE_CODEX_R1_2026-04-25.md
- AGENTS.md
- docs/orchestration/GLOBAL_SYSTEM_PRIMER.md
- docs/BUSINESS_RULES.md
- docs/ORDER_FLOW.md
- docs/DEVICE_FLOW.md
- docs/centralisation/ADMIN_CROSS_BRANCH_MAP_2026-04-20.md si utile
- puis les fichiers code cites par Codex R1 quand tu contestes ou confirmes une preuve.

Contexte d'operateur : le fichier R1 cible a ete nettoye en rapport A-G lisible ; la trace brute Codex est conservee dans reports/audit/CHALLENGE_CODEX_R1_2026-04-25_TRACE.md seulement si tu as besoin d'auditer les lectures.

Tache, en francais, structure dense :
1) SECTION A - D'accord : 5-10 puces max ou l'analyse Codex R1 est solide, avec chemins:line quand possible.
2) SECTION B - Contestation : 5-15 puces. Pour chaque point, indique si c'est ERREUR_CODEX, PREUVE_INSUFFISANTE, PRIORITE_SURCOTEE, PRIORITE_SOUSCOTEE, ou INVARIANT_MANQUANT. Couvre explicitement prix serveur, branch_id, after-commit/outbox, OrderStatus, frozen zones, OrderService/FrontendOrderService symmetry.
3) SECTION C - Priorisation : tableau P0 / P1 / P2 pour atteindre une V1 fonctionnelle. Definis V1 en 2 phrases maximum : backend + POS + Borne + KDS si c'est le scope, sinon explique le scope exact.
4) SECTION D - Decision d'orchestrateur : une seule ligne `CHALLENGE_VERDICT: MERGE_CODEX | PREFER_CLAUDE | REBUT_ALL | SPLIT` + justification courte.
5) SECTION E - Instructions pour R3 Codex : 8-12 points de reponse attendue, avec les preuves que Codex doit apporter ou admettre.

Contraintes :
- Ne clos pas de gate humain et ne remplace pas un vrai run-cycle.
- Signale les faux positifs probables.
- Si un point Codex est dangereux mais insuffisamment prouve, classe-le NEEDS_EVIDENCE plutot que P0 execution.
- Ecris comme un audit terminal FoodKing : concret, verifiable, oriente plan V1.
