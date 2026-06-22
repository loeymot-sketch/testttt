# MEGA PLAN READINESS & GAP ANALYSIS — Caisse V1 / POS / Kiosk / KDS

Date: 2026-04-25  
Scope: pre-cycle audit/orchestration, no product code change  
Primary output: decision on whether FoodKing has enough evidence to produce a mega correction plan, and what is still missing before implementation.

## 0. Verdict court

`READY_FOR_MEGA_PLAN: YES`

`READY_FOR_IMPLEMENTATION: NO_WITHOUT_GATES`

`READINESS_VERDICT: READY_TO_MEGA_PLAN_NOT_READY_TO_EXECUTE`

Nous avons assez de matière pour produire un plan de correction V1 très détaillé, phase par phase, avec priorités, invariants, tests, gates, et séquençage d’implémentation. Il manque encore des décisions humaines et des preuves exécutables pour lancer les corrections sans risque de refaire plusieurs fois l’architecture.

La bonne décision est donc : écrire maintenant le méga plan, mais le commencer par une Phase 0 de gates, preuves, choix produit, scope pilote, et tests sentinelles. Le plan ne doit pas supposer que les arbitrages cash/kiosk/fiscal/paiement sont déjà décidés.

## 1. Ressources déjà suffisantes

Les ressources actuelles couvrent déjà la majorité des angles nécessaires pour planifier intelligemment :

| Ressource | Utilité dans le méga plan |
| --- | --- |
| `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md` | Rapport source ultra profond caisse V1 : POS, paiement, OrderIntent, OrderQuote, ledger, KDS, scheduler, config cachée, migrations, CI. |
| `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md` | Audit fonctionnel POS/caisse, notamment la partie page-par-page à partir de la ligne 529. |
| `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md` | Audit kiosk connecté/offline, promos, payment-confirm, status 16, fiscal/Z, admin PIN, fidélité, scanner, machine binding. |
| `reports/audit/CHALLENGE_MASTER_CHECKLIST_DEEP_SINGLE_2026-04-25.md` | Master checklist Codex : couverture thématique A-O et verdict sur les gates humains. |
| `reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Position Codex R1 : primitives de domaine OrderIntent, OrderQuote, PaymentProof, KitchenRelease. |
| `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Rapport final de dispute consolidé à partir des audits existants et de la lecture repo. |
| `reports/audit/MEGA_HANDOFF_CONTEXT_INTEGRATION_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | Intégration des handoffs 2026-04-22 et 2026-04-26 sans écraser le rapport existant. |
| `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` | Audit Claude existant : 15 findings, NOT-READY 4/10, buckets et séquence. |
| `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` | Plan exécutable existant en lots, utile comme base de séquençage mais à refondre avec le scope Caisse V1 complet. |
| `docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md` | Handoff maître POS/KDS finitions : rationale, inventaire livrables, protocole de fusion de deux plans. |
| `docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md` | Index de contexte pour nouvel agent : chemins, lexique, maintenance, démarrage. |
| `AGENTS.md` | Contrat opératoire : invariants FoodKing, rôles Codex/Claude, run-cycle, gates, frozen zones. |

Ces fichiers donnent assez de profondeur pour produire un plan qui ne soit pas seulement une liste de bugs, mais une stratégie complète de stabilisation V1.

## 2. Ce qui est déjà tranchable

### 2.1 Thèse d’architecture V1

Le système doit être planifié autour de quatre primitives, même si l’implémentation finale utilise les classes et tables existantes :

| Primitive | Rôle |
| --- | --- |
| `OrderIntent` | Intention métier avant commande validée : canal, branche, device, utilisateur, panier, mode de paiement demandé, contraintes. |
| `OrderQuote` | Devis serveur immuable ou versionné : prix, taxes, remises, stock, disponibilité, total, devise, expiration. |
| `PaymentProof` / `PaymentLedger` | Preuve et état de paiement : cash, CB, TPE, Stripe, ticket restaurant, paiement différé, remboursement, annulation. |
| `KitchenRelease` / `KitchenTicketCreated` | Autorisation explicite d’entrée cuisine après conditions remplies : paiement, politique cash, validation POS, statut serveur. |

Sans ces concepts, le plan risque de corriger des symptômes : `payment-confirm`, status 16, KDS status mismatch, offline kiosk, promo frontend, queue sequence, etc. Avec ces concepts, chaque bug est rattaché à une responsabilité claire.

### 2.2 P0 déjà évidents

Les P0 sont assez stables à travers les rapports :

| P0 | Pourquoi c’est bloquant |
| --- | --- |
| Devis serveur obligatoire avant création/confirmation | Protège l’invariant prix backend SSOT et évite promos/prix frontend. |
| Paiement strict et branch-scoped | Empêche cross-branch, double capture, confirmation trop large, faux payé. |
| KDS release contrôlé serveur | Empêche les commandes non payées ou invalides d’entrer en cuisine. |
| Statuts unifiés via `OrderStatus` | Évite status 16, expected_status incohérent, chaînes magiques, transitions invalides. |
| Idempotence POS/kiosk/payment | Protège contre double commande, double paiement, retry offline, refresh navigateur. |
| Outbox/events after-commit | Empêche KDS/broadcast/queue/fiscal de voir un état DB non commité. |
| Isolation `branch_id` | Invariant transversal, à vérifier sur commandes, paiements, devices, KDS, fiscal, queue. |
| Gates frozen zones | OrderService, FrontendOrderService, PaymentService, pricing, migrations : pas d’édition sans gate. |

### 2.3 Le plan ne doit pas partir d’une page UI

Le plan doit partir du cycle de vie commande/paiement, puis projeter vers les surfaces :

1. POS caisse
2. Kiosk connecté/offline
3. KDS
4. Web/table/order legacy
5. Admin/config fiscal
6. Scheduler/queue/broadcast/outbox
7. Tests et observabilité

Si on commence par “corriger l’écran POS” ou “corriger la borne”, on va rater les erreurs transverses : paiement, quote, statut, branch, release cuisine.

## 3. Ce qui manque vraiment

### 3.1 Gates humains indispensables

Ces décisions ne peuvent pas être déduites proprement depuis le code. Elles doivent être tranchées avant implémentation P0, ou au minimum être explicitement paramétrées dans le plan.

| Gate | Question à trancher | Impact |
| --- | --- | --- |
| `GATE_PAYMENT_LEDGER_SCOPE` | Ledger complet V1 ou restriction pilote des moyens de paiement ? | Définit le niveau de refactor paiement. |
| `GATE_KIOSK_CASH_POLICY` | Une commande cash kiosk est-elle “payée à la borne”, “à payer au comptoir”, ou “bloquée avant KDS” ? | Décide KitchenRelease et fiscalisation. |
| `GATE_KIOSK_CARD_POLICY` | Kiosk CB/TPE autonome activé en V1 ou désactivé ? | Décide PaymentProof et hardware tests. |
| `GATE_FISCAL_Z_POLICY` | Les ventes kiosk entrent-elles dans le Z kiosk, POS, ou consolidation serveur ? | Conditionne audit fiscal et reporting. |
| `GATE_WEB_PAYMENT_SCOPE` | Stripe/web/table est-il actif en V1 ou hors scope ? | Si actif, P0 cents/capture/idempotence ; si off, déport P1/P2. |
| `GATE_KDS_BUMP_AUTHORITY` | KDS bump local, serveur, ou hybride avec expected_status ? | Conditionne transitions, tests KDS, conflits multi-écrans. |
| `GATE_OFFLINE_SCOPE` | Offline kiosk/POS autorisé à créer quoi exactement ? | Décide queue locale, reconciliation, interdiction paiement offline. |
| `GATE_FROZEN_ZONES` | Quelles zones gelées sont ouvertes pour correction ? | Nécessaire avant OrderService/PaymentService/pricing/migrations. |

Sans ces gates, un plan peut être brillant mais contradictoire avec la réalité produit ou fiscale.

### 3.2 Preuves techniques manquantes

Il faut convertir les findings en preuves exécutables. Les rapports savent où sont les risques, mais pas encore tous les tests de non-régression.

| Preuve attendue | Forme recommandée | Pourquoi |
| --- | --- | --- |
| `payment-confirm` branch-scoped et amount-scoped | Test backend feature/integration | Empêche confirmation d’une mauvaise commande ou mauvais montant. |
| Devis serveur POS/kiosk | Tests unitaires + feature sur endpoints panier/quote/order | Prouve l’invariant prix backend SSOT. |
| KDS expected_status / transitions | Tests backend état commande + test UI KDS minimal | Prouve que la cuisine ne reçoit que ce qui est autorisé. |
| OrderStatus enum unique | Test statique ou lint ciblé + tests transition | Élimine chaînes magiques/status 16. |
| Idempotency key composite | Test DB/feature avec double retry | Empêche doubles commandes et doubles paiements. |
| Dispatch after commit | Test integration/outbox ou assertion transactionnelle | Empêche broadcast/KDS/fiscal avant commit. |
| Branch isolation | Tests multi-branch sur order/payment/device/KDS | Prouve absence de fuite inter-branches. |
| Offline kiosk reconciliation | Test de file locale ou simulation offline | Empêche faux paiement / faux stock / double sync. |
| Fiscal/Z | Test export/report minimal + scénario annulation/remboursement | Prouve cohérence caisse et audit. |
| KDS volume > 50 | Test pagination/stream/listing | Couvre le risque d’ordre perdu ou masqué. |

### 3.3 Preuves environnement et runtime

Le méga plan doit demander des preuves de runtime, car plusieurs bugs sont invisibles en inspection statique.

| Zone | Preuve manquante |
| --- | --- |
| Queue | Driver utilisé, retry policy, dead letters, workers actifs. |
| Broadcast | Canal KDS/POS/kiosk réellement branch-scoped, auth, reconnect. |
| Scheduler | Tâches fiscal/Z/cleanup/expiration quote actives dans l’environnement cible. |
| Cache | Stratégie d’invalidation menu/prix/promo entre POS/kiosk/web. |
| TPE | Matériel cible, protocole, mode connecté/offline, mapping transaction/order. |
| Imprimante | Ticket cuisine, ticket client, reprise après échec. |
| Kiosk hardware | Résolution, tactile, scanner, admin PIN, machine binding. |
| Fiscal secrets | Où sont stockées les configs, qui peut les modifier, audit trail. |
| Observabilité | Logs corrélés par order_id, quote_id, payment_id, branch_id, device_id. |

### 3.4 Graphiti et mémoire MCP

Dans cette session, les ressources MCP exposées sont vides. La mémoire Graphiti n’est donc pas directement lisible ici, même si le dépôt documente son usage.

Conséquence : le méga plan peut s’appuyer sur `memory/INDEX.md`, les JSONL, les handoffs, et les rapports disque, mais il doit marquer `GRAPHiTI_RUNTIME_PROOF: MISSING_IN_THIS_SESSION` si la mémoire Graphiti doit être considérée comme source d’autorité.

Ce n’est pas bloquant pour écrire le plan. C’est bloquant seulement si une décision est censée venir exclusivement de Graphiti et n’est pas reproduite dans un fichier SSOT.

### 3.5 Claude terminal live

Les rapports Claude existants sont exploitables, notamment `MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`. En revanche, les invocations Claude terminal lancées pour la dispute R2/R4 n’ont pas produit de sortie exploitable dans les fichiers `MEGA_DISPUTE_CLAUDE_R2_*` pendant l’attente.

Conséquence : le méga plan peut être préparé maintenant côté Codex avec les rapports Claude existants. Pour le double avis demandé par l’utilisateur, il faudra lancer une nouvelle commande Claude ciblée et bornée, avec prompt plus compact, et comparer les deux plans.

## 4. Décision : faut-il encore auditer avant de planifier ?

Non pour la planification. Oui pour l’exécution.

Le niveau de données est suffisant pour écrire un plan excellent, parce que les convergences sont fortes :

- mêmes P0 qui reviennent dans plusieurs audits ;
- mêmes invariants FoodKing impactés ;
- mêmes zones de risque : paiement, devis, status, branch, KDS, offline, fiscal ;
- mêmes surfaces critiques : POS, kiosk, KDS ;
- mêmes besoins de gates : fiscal, paiement, scope pilote, frozen zones.

Attendre encore un audit avant de rédiger le plan créerait surtout du retard et de la répétition. Le bon mouvement est de transformer l’audit en plan opératoire, puis de faire auditer ce plan par Claude avant implémentation.

## 5. Format recommandé du futur méga plan

Fichier cible recommandé :

`plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md`

Le plan doit être exécutable par lots, pas seulement analytique.

### Structure minimale attendue

1. `PRIOR_CONTEXT` : liens vers tous les rapports et handoffs.
2. `V1_FUNCTIONAL_DEFINITION` : définition exacte d’une V1 fonctionnelle.
3. `NON_NEGOTIABLE_INVARIANTS` : prix, OrderStatus, branch_id, after-commit, frozen zones, symmetry.
4. `PHASE_0_GATES_AND_EVIDENCE` : décisions humaines + preuves runtime.
5. `PHASE_1_DOMAIN_CONTRACTS` : OrderIntent, OrderQuote, PaymentProof/Ledger, KitchenRelease.
6. `PHASE_2_BACKEND_CORE` : endpoints, services, transitions, idempotence, outbox.
7. `PHASE_3_POS_CAISSE` : UI caisse, quote-first, paiement, ticket, retry, permissions.
8. `PHASE_4_KIOSK` : menu source, pricing, payment, offline, machine binding, admin.
9. `PHASE_5_KDS` : listing, bump, expected_status, realtime, volume.
10. `PHASE_6_FISCAL_AND_REPORTING` : Z, audit logs, refunds, reconciliation.
11. `PHASE_7_LEGACY_AND_CUTOVER` : désactivation ou compatibilité web/table/old kiosk/POS v4.
12. `PHASE_8_TEST_MATRIX` : unit, feature, integration, Playwright, hardware.
13. `PHASE_9_AUDIT_AND_GATE_LOOP` : Codex execute, self-audit, validate, Claude audit, rework loop.
14. `RISK_REGISTER` : risques résiduels et preuves attendues.
15. `TASK_ID_BUCKETS` : découpage en missions bornées.

## 6. Système d’implémentation et audit par phase

Chaque phase doit avoir le même protocole :

| Étape | Exécutant | Artefact |
| --- | --- | --- |
| Plan détaillé phase | Claude/orchestrateur ou plan humain | `plans/PLAN_<TASK_ID>_<date>.md` |
| Préparation mission | Orchestrateur | `missions/<TASK_ID>/input.json`, `execute_brief.md`, `plan_excerpt.md` |
| Implémentation | Codex CLI `codex-extension` primaire | patch produit limité au plan |
| Auto-audit | Codex | `reports/audit/GPT_SELF_AUDIT_<TASK_ID>.md` |
| Validation locale | Tests prévus | rapport de validation / logs |
| Audit externe | Claude terminal primaire | verdict `PASS` ou `REWORK` |
| Gate | humain si nécessaire | `docs/gates/GATE_*` |
| Close | seulement après PASS | mise à jour cycle/report/mémoire |

Le méga plan doit interdire les phases “fourre-tout”. Chaque phase doit être assez petite pour être auditée, mais assez cohérente pour ne pas casser les invariants entre backend et UI.

## 7. Ordre de phases recommandé

### Phase 0 — Gates, scope, preuves sentinelles

Objectif : empêcher les mauvaises décisions d’architecture avant le code.

Sorties :

- gates humains listés en section 3.1 ;
- scope V1 exact des canaux et moyens de paiement ;
- tests sentinelles rouges ou au moins TODO testables ;
- preuve des frozen zones ouvertes ou non ;
- décision Graphiti/source mémoire ;
- décision Claude second opinion.

### Phase 1 — Contrats domaine

Objectif : figer la vérité métier avant endpoints et UI.

Sorties :

- contrat `OrderIntent`;
- contrat `OrderQuote`;
- contrat paiement minimal ou ledger ;
- contrat `KitchenRelease`;
- transitions `OrderStatus`;
- matrice branch/device/user.

### Phase 2 — Backend commande/paiement/KDS

Objectif : corriger la colonne vertébrale.

Sorties :

- quote-first obligatoire ;
- idempotence ;
- payment-confirm strict ;
- release cuisine after-commit ;
- events/outbox ;
- branch isolation ;
- tests feature.

### Phase 3 — POS caisse

Objectif : POS V1 fiable sans logique prix côté front.

Sorties :

- POS consomme quote serveur ;
- paiement cash/card conforme au scope ;
- retry safe ;
- permissions caisse ;
- ticket client/cuisine ;
- tests POS.

### Phase 4 — Kiosk

Objectif : borne connectée pilotée par backend.

Sorties :

- menu/prix backend ;
- promos serveur ;
- offline policy stricte ;
- payment policy conforme gate ;
- machine binding ;
- admin PIN sécurisé ;
- tests kiosk.

### Phase 5 — KDS

Objectif : cuisine reçoit uniquement des commandes autorisées et branch-scoped.

Sorties :

- expected_status corrigé ;
- transitions serveur ;
- listing volumétrique ;
- realtime robuste ;
- bump auditable ;
- tests KDS.

### Phase 6 — Fiscal, reporting, reconciliation

Objectif : rendre la V1 exploitable commercialement sans trous de caisse.

Sorties :

- Z/reporting ;
- audit logs ;
- refunds/voids ;
- reconciliation paiement/order ;
- export minimum.

### Phase 7 — Cutover legacy et durcissement

Objectif : éviter que les anciens chemins contournent les corrections.

Sorties :

- routes legacy identifiées ;
- web/table soit conforme soit désactivé ;
- POS v4/old kiosk cadrés ;
- feature flags ;
- docs opérationnelles.

### Phase 8 — Qualification V1

Objectif : prouver la V1.

Sorties :

- suite tests verte ;
- Playwright sur POS/kiosk/KDS ;
- hardware smoke ;
- branch isolation smoke ;
- fiscal smoke ;
- rapport final Claude ;
- go/no-go humain.

## 8. Prompt recommandé pour le second avis Claude

Fichier recommandé à créer avant appel :

`reports/audit/CLAUDE_SECOND_OPINION_PROMPT_CAISSE_V1_MEGA_PLAN_2026-04-25.md`

Prompt proposé :

```text
Tu es l'orchestrateur FoodKing (AGENTS.md), audit/plan, pas implémentation.

Lis dans cet ordre :
- AGENTS.md
- docs/orchestration/GLOBAL_SYSTEM_PRIMER.md
- reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md
- reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md
- reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md
- reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md
- reports/audit/MEGA_HANDOFF_CONTEXT_INTEGRATION_CAISSE_POS_KIOSK_KDS_2026-04-25.md
- reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md
- reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md
- plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md
- docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md

Tâche :
1. Dis si nous sommes prêts à produire un méga plan de correction V1, ou ce qui manque encore.
2. Conteste explicitement le diagnostic Codex quand il est trop optimiste ou insuffisamment prouvé.
3. Propose ton propre méga plan phase par phase pour V1 fonctionnelle.
4. Liste les gates humains indispensables avant code.
5. Donne une matrice P0/P1/P2.
6. Donne un protocole d'implémentation et audit par phase : Codex execute, tests, Claude audit, gate.
7. Termine par :
   CLAUDE_SECOND_OPINION_VERDICT: READY_TO_PLAN | NEEDS_MORE_EVIDENCE | NOT_READY

Réponse française, dense, avec chemins de fichiers cités quand la preuve vient du repo.
```

Commande recommandée :

```bash
bash scripts/foodking-claude-orchestrate.sh context
bash scripts/foodking-claude-orchestrate.sh audit "$(cat reports/audit/CLAUDE_SECOND_OPINION_PROMPT_CAISSE_V1_MEGA_PLAN_2026-04-25.md)" 2>&1 | tee reports/audit/CLAUDE_SECOND_OPINION_CAISSE_V1_MEGA_PLAN_2026-04-25.md
```

## 9. Comparaison attendue Codex vs Claude

Après le second avis Claude, créer un fichier de comparaison :

`reports/audit/CODEX_CLAUDE_MEGA_PLAN_COMPARISON_CAISSE_V1_2026-04-25.md`

Comparaison minimale :

| Axe | Plan Codex | Plan Claude | Décision finale |
| --- | --- | --- | --- |
| V1 definition | À remplir | À remplir | À trancher |
| Payment architecture | À remplir | À remplir | À trancher |
| Fiscal/Z | À remplir | À remplir | Gate humain |
| POS scope | À remplir | À remplir | À trancher |
| Kiosk scope | À remplir | À remplir | À trancher |
| KDS release | À remplir | À remplir | À trancher |
| Offline policy | À remplir | À remplir | À trancher |
| Test strategy | À remplir | À remplir | À trancher |
| Phase order | À remplir | À remplir | À trancher |
| Frozen zones | À remplir | À remplir | Gate humain |

Le but n’est pas de faire gagner un modèle. Le but est de forcer les désaccords utiles : si Claude demande plus de preuves, le méga plan doit intégrer cette demande en Phase 0 ; si Codex a une meilleure séquence d’implémentation, elle doit être conservée sous forme de lots.

## 10. Liste finale de ce qui manque avant code

### Bloquants avant implémentation P0

1. Décision scope paiement V1.
2. Décision cash kiosk.
3. Décision CB/TPE kiosk.
4. Décision fiscal/Z.
5. Décision web/table/Stripe actif ou non.
6. Gate frozen zones.
7. Preuve environnement queue/broadcast/scheduler.
8. Tests sentinelles pour paiement, quote, KDS, branch.
9. Confirmation Graphiti ou fallback mémoire disque.
10. Second avis Claude sur le méga plan.

### Non bloquant avant rédaction du méga plan

1. Tests déjà verts.
2. Accès hardware complet.
3. Implémentation ledger finale.
4. Migrations validées.
5. Audit final Claude.
6. Gate go-live.

## 11. Conclusion opératoire

On est prêt à préparer le méga plan de correction V1. Il ne faut pas attendre d’avoir tout le hardware ou toutes les décisions pour écrire le plan, parce que le plan peut justement organiser ces décisions dans une Phase 0.

On n’est pas prêt à exécuter le code P0 sans gates. Le premier livrable utile maintenant est donc un plan maître qui commence par les arbitrages humains, puis descend vers les contrats domaine, le backend, POS, kiosk, KDS, fiscal, legacy, tests, audit, et go/no-go.

`FINAL_READINESS_DECISION: WRITE_MEGA_PLAN_NOW_WITH_PHASE_0_GATES`

