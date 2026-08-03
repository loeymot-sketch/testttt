# PROMPT CLAUDE — MAX ORCHESTRATION / MEGA PLAN CAISSE V1

Tu es Claude, orchestrateur FoodKing selon `AGENTS.md`. Tu ne codes pas. Tu audites, contestes, arbitres, priorises, et produis le plan de correction V1 le plus robuste possible.

Mission: produire un rapport stratégique et technique de niveau maximum pour transformer les audits Caisse/POS/Kiosk/KDS en plan de correction V1 exécutable, phase par phase, avec gates, preuves, tests, risques, et protocole Codex/Claude à chaque étape.

Langue: français.  
Style: dense, technique, structuré, sans narratif inutile.  
Niveau attendu: audit d’architecture + plan d’exécution + arbitrage de sécurité métier.  
Interdit: implémenter du code, auto-approuver un gate humain, ignorer les invariants FoodKing, élargir hors V1 sans le marquer P2.

## 1. Lecture obligatoire

Lis d’abord:

- `AGENTS.md`
- `reports/audit/_TERMINAL_CONTEXT_BRIEF.md`
- `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`
- `.cursor/ACTIVE_CYCLE.md`

Lis ensuite les ressources d’audit principales:

- `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md`
- `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md`
- `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md`
- `reports/audit/CHALLENGE_MASTER_CHECKLIST_DEEP_SINGLE_2026-04-25.md`
- `reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_HANDOFF_CONTEXT_INTEGRATION_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md`

Lis les handoffs et plans existants:

- `docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md`
- `docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md`
- `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_BRIEF_2026-04-26.md`
- `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`
- `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md`

Lis au besoin les références SSOT:

- `docs/ORDER_FLOW.md`
- `docs/BUSINESS_RULES.md`
- `docs/API_MAP.md`
- `docs/AUTHZ_MATRIX.md`
- `docs/DATABASE_SCHEMA_CORE.md`
- `docs/TEST_PLAN.md`
- `docs/MASSIVE_TEST_PLAN.md`
- `docs/orchestration/MEMORY_MATRIX.md`

Si un fichier manque, continue et liste-le dans une section `FICHIERS_MANQUANTS`.

## 2. Invariants non négociables à auditer

Tu dois vérifier et intégrer explicitement:

- Prix: backend seule source de vérité; aucune logique de prix/remise côté frontend.
- `OrderStatus`: enum unique; pas de chaînes magiques ni status numériques opaques.
- `branch_id`: isolation stricte, y compris order/payment/device/KDS/fiscal/queue.
- Dispatch: events/jobs/broadcast/KDS/fiscal après commit DB.
- Frozen zones: OrderService, FrontendOrderService, PaymentService, pricing, migrations ou zones gelées seulement avec gate.
- Symétrie: OrderService / FrontendOrderService si l’un est modifié.
- Paiement: pas de commande “paid” sans preuve et transition valide.
- KDS: pas de cuisine sans KitchenRelease conforme.
- Offline: pas de faux paiement, pas de faux stock, pas de réconciliation ambiguë.
- Fiscal/Z: pas de trou de caisse, annulation/remboursement traçables.

## 3. Points à contester chez Codex

Lis puis challenge les rapports Codex, notamment:

- `OrderIntent`, `OrderQuote`, `PaymentProof/PaymentLedger`, `KitchenRelease`: est-ce la bonne architecture ou trop lourde pour V1?
- Le verdict `READY_FOR_MEGA_PLAN: YES` / `READY_FOR_IMPLEMENTATION: NO_WITHOUT_GATES`: confirme ou conteste.
- La Phase 0 gates/preuves: est-elle suffisante?
- Les P0 proposés: lesquels sont surclassés, fusionnés, ou déplacés?
- Risque de surarchitecture: où faut-il faire minimal V1 au lieu de refactor global?
- Risque inverse: où un patch minimal serait dangereux?

Tu dois être dur: ne valide rien par politesse. Si Codex est trop optimiste, dis-le.

## 4. Angles cachés à couvrir

Ne te limite pas aux pages visibles. Cherche les coins indirects:

- routes legacy web/table/order;
- POS v4 vs POS actuel;
- ancien kiosk vs nouveau kiosk;
- `payment-confirm`, captures, refunds, voids, partial payments;
- cash kiosk, card kiosk, TPE, Stripe/web;
- cents/rounding/taxes/promos/discounts/service fees;
- queue sequence, idempotency, retries, double click, refresh, offline replay;
- `expected_status`, status 16, transitions KDS;
- KDS listing >50, pagination/realtime, multi-écran;
- broadcast channel scoping, auth, reconnect;
- scheduler, workers, domain events, outbox;
- fiscal Z, audit log, fiscal sequence, report closure;
- branch/device/user permissions;
- admin PIN, machine binding, scanner/loyalty;
- cache invalidation menu/prix/promo;
- migrations/schema/index uniques;
- tests absents, CI absente, observabilité/log correlation.

## 5. Rapport attendu

Produis une seule réponse complète avec les sections suivantes.

### A — Verdict de préparation

Dis clairement:

- sommes-nous prêts à rédiger le méga plan?
- sommes-nous prêts à implémenter?
- quels gates bloquent?
- quel est le niveau de risque V1?

Termine la section avec:

`CLAUDE_READINESS_VERDICT: READY_TO_PLAN | NEEDS_MORE_EVIDENCE | NOT_READY`

### B — Contestation Codex

Table:

`Thèse Codex | Accepté | Contesté | Décision Claude | Preuve/chemin`

Inclure au minimum:

- primitives domaine;
- gates humains;
- ordre des phases;
- payment ledger;
- fiscal/Z;
- KDS release;
- offline;
- Graphiti absent;
- tests sentinelles.

### C — Définition V1 fonctionnelle

Définis en 5 à 10 phrases ce qu’est une V1 fonctionnelle réelle pour FoodKing:

- POS caisse;
- kiosk;
- KDS;
- paiement;
- fiscal minimum;
- branch isolation;
- tests minimum;
- cutover legacy.

### D — P0/P1/P2 consolidé

Table:

`Priorité | Sujet | Pourquoi | Risque si ignoré | Preuve attendue | Phase`

P0 = bloque V1.  
P1 = durcit V1 ou limite incidents.  
P2 = amélioration après V1.

### E — Méga plan phase par phase

Produis un plan opérationnel détaillé:

- Phase 0: gates, scope, preuves, tests sentinelles.
- Phase 1: contrats domaine et transitions.
- Phase 2: backend core order/payment/KDS release.
- Phase 3: POS caisse.
- Phase 4: kiosk.
- Phase 5: KDS.
- Phase 6: fiscal/reporting/reconciliation.
- Phase 7: legacy/cutover/feature flags.
- Phase 8: qualification V1.
- Phase 9: close/gate/go-no-go.

Pour chaque phase, donner:

- objectif;
- fichiers/zones probables;
- invariants touchés;
- tâches;
- tests;
- risques;
- gate de sortie;
- EXECUTE owner recommandé (`codex-extension`, humain, ou autre);
- AUDIT owner recommandé (`claude-terminal`);
- critère `PASS/REWORK`.

### F — Plan de tests et preuves

Table:

`Preuve | Type test | Surface | Commande probable | Bloquant? | Pourquoi`

Inclure unit, feature, integration, Playwright, hardware smoke, branch isolation, fiscal smoke.

### G — Gates humains nécessaires

Table:

`Gate | Décision exacte | Options | Recommandation Claude | Impact plan | Peut-on coder avant?`

### H — Stratégie d’implémentation Codex/Claude

Décris le protocole:

1. créer plan `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md`;
2. découper en `TASK_ID`;
3. mission Codex par phase;
4. auto-audit Codex;
5. validation locale;
6. audit Claude terminal;
7. REWORK loop;
8. gate humain;
9. close.

### I — Risques de surarchitecture / sous-architecture

Deux tables:

- où ne pas surconstruire pour V1;
- où un patch minimal serait dangereux.

### J — Questions finales pour humain

Liste courte, uniquement les questions qui changent vraiment le plan.

### K — Verdict final

Termine exactement par une ligne:

`CLAUDE_MAX_ORCHESTRATION_VERDICT: READY_TO_WRITE_MEGA_PLAN | NEEDS_EVIDENCE_FIRST | HUMAN_SPLIT`

## 6. Exigences de preuve

Quand tu cites un fait venant du repo, cite le chemin. Si tu connais une ligne, cite `chemin:ligne`. Si tu n’as pas la ligne, cite le chemin seul.

Si une affirmation est une inférence, marque-la comme `INFERENCE`.

Si une preuve manque, ne l’invente pas: écris `PREUVE_MANQUANTE`.

