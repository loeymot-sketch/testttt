## A) RÉSUMÉ EXÉCUTIF

Caisse V1 a franchi une vague majeure : Wave A est fermée (M-19, M-01, M-02, M-12, M-16, M-18, M-20, M-21a, M-03), et Wave B compte 8 missions CLOSED (M-09, M-06, M-05, M-04B, M-08, M-07, M-10, et le periphérique M-21a). Les invariants centraux (branch_id, OrderStatus authority, quote sealed, fiscal Z option B, KDS expected_status, payment pilot Option B) ont une trace Codex.

Cependant la solidité réelle est **incertaine** : (1) toutes les CLOSED le sont via `GPT_FINAL_AUDIT: PASS` sans `AUDIT_VERDICT: PASS` Claude opposable — le mode `FOODKING_GPT_ONLY=1` a court-circuité le double-audit imposé par `MULTI_AGENT_ORCHESTRATION.md` ; (2) `GATE_LOG.md` (lignes 39–47) liste **8 gates Wave B approuvés par "Codex (instruction humaine explicite)"** — c'est une **violation directe** des Absolute Prohibitions de `human-gates.mdc` (« no self-approval »), contradiction signalée par le handoff lui-même §2/§5.2 ; (3) M-17 est `EXECUTED` mais non `CLOSED` ; (4) M-11 est `BLOCKED` alors que sa dépendance M-08 est `CLOSED` (incohérence DAG/queue) ; (5) aucune campagne Playwright bout-en-bout post-Wave B n'est attestée pour la chaîne POS→KDS→fiscal ; (6) Pusher reconnect-storm + métriques (NEW-02/04) datent d'avant la masterplay — non re-vérifiés.

**Verdict global : HEAL — la file produit du résultat mais la chaîne d'attestation est faussée.** Un re-audit Claude ciblé sur les 8 missions CLOSED Wave B + une régularisation `GATE_LOG` (signature humaine réelle) est requis avant d'avancer M-13 / M-15.

Je n'ai PAS lu : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`, `missions/<TASK_ID>/output_codex.json` individuels, `reports/audit/GPT_FINAL_AUDIT_*.md`, ni `docs/PROJECT_CONTINUITY_AND_VISION.md` — leurs faits ne sont donc pas attestés ici.

---

## B) CARTE DES RISQUES

| ID | Zone | Risque | Sévérité | Preuve / absence | Mitigation orchestration |
|----|------|--------|----------|------------------|--------------------------|
| R-01 | Gouvernance gates | 8 gates Wave B signés par "Codex (instruction humaine explicite)" — self-approval modèle | **P0** | `docs/gates/GATE_LOG.md` L39–47 contre `human-gates.mdc` Absolute Prohibitions L79–86 | Geler la queue ; obtenir signature humaine nominative + commit SHA ; ré-écrire les 8 lignes ou les marquer `PENDING_HUMAN_GATE` |
| R-02 | Audit chain | `FOODKING_GPT_ONLY=1` a sauté l'`AUDIT_VERDICT: PASS` Claude pour toutes les CLOSED Wave B | **P0** | Notes queue toutes en "GPT final PASS" ; aucun rapport `AUDIT_VERDICT` Claude post-2026-04-25 nommé dans handoff | Lancer `audit-brief` Claude rétrospectif sur M-05/06/07/08/09/10/04B/17 avant tout nouveau PENDING |
| R-03 | M-17 web/Stripe | EXECUTED non CLOSED — pas de final audit ni audit Claude | **P1** | Queue ligne 19 status `EXECUTED` | Mission obligatoire avant M-13 : `codex:final-audit M-17` + audit Claude ciblé scope (web payment off + stripe guard) |
| R-04 | M-11 kiosk | `BLOCKED` alors que M-08 est CLOSED — incohérence DAG/queue | **P1** | Queue L46 vs L43 | Vérifier "policy evidence" (offline gate + fiscal Z) ; si présente → `BLOCKED → PENDING`, sinon documenter quelle preuve manque |
| R-05 | Symétrie OS/FOS | Multiples reworks M-05/06/09/10 — risque divergence `OrderService` / `FrontendOrderService` non couverte | **P1** | Handoff §5.1 ; M-10 CLOSED sans rapport intégration cité | Audit ciblé : grep `SYMMETRY_NOTE` sur diffs Wave B ; tests d'intégration POS+kiosk sur la même quote |
| R-06 | Idempotence paiement | M-04B "pilote restreint" Option B — scope flou en absence de ledger full | **P1** | `GATE_PAYMENT_LEDGER` Option B | Documenter le périmètre exact (méthodes/surfaces autorisées) dans `docs/PAYMENT_PILOT_SCOPE.md` ; sentinel test cas hors scope = 4xx |
| R-07 | Dispatch after commit | Pas de re-vérif transversale post-Wave B | **P1** | Handoff §5.2 mentionne le risque ; aucun rapport cité | Mission d'audit dédiée : grep `dispatch.*Event` hors `DB::afterCommit` / `DispatchableAfterCommit` |
| R-08 | Pricing SSOT | Backend = source de vérité — pas de re-vérif après refactor M-21b en attente | **P1** | `04_pricing_ssot.jsonl` + invariants ; M-21b BLOCKED | M-21b doit inclure sentinel "client never sets total" |
| R-09 | E2E réel | Aucune campagne Playwright Caisse V1 (POS→KDS→fiscal Z) post-Wave B | **P1** | Aucune trace `reports/antigravity/` post-2026-04-25 dans handoff | Bloquer M-15 jusqu'à 1 run E2E vert (golden + 3 edge cases) |
| R-10 | Migrations | M-13 PENDING avec gate Schema Option A "rehearsal + backup" | **P2** | Queue L48 | Imposer dry-run sur snapshot prod avant exec ; rollback script obligatoire |
| R-11 | Graphiti | Tentatives MCP cancelled pendant Codex runs — mémoire désynchronisée possible | **P2** | Handoff §1 | `bash scripts/after-execute-memory.sh` + `python3 memory/verify.py` (cible ≥ 175) avant M-13 |
| R-12 | Stop-on-rework | REWORK non clos sur M-17 ou M-13 peut laisser queue incohérente | **P2** | Handoff §5.3 | `MASTERPLAY_STOP_ON_REWORK=1` confirmé ; ajouter check pre-LOOP sur `EXECUTED` orphelins |
| R-13 | Verify boucle | `verify:boucle` exit 1 parfois ignoré | **P2** | Handoff §5.4 | Faire échouer le runner si exit ≠ 0 trois fois consécutives |
| R-14 | Pusher / WS | NEW-02 reconnect-storm validé le 2026-04-23 — pas re-vérifié post-masterplay | **P3** | `12_decisions_log.jsonl` 2026-04-23 | Régression spot dans M-22 (observability) |
| R-15 | Branch leak | M-09 CLOSED, mais NEW-04 a montré une fuite cross-branch corrigée tardivement | **P2** | `12_decisions_log.jsonl` NEW-04 audit-T G2 | Ajouter sentinel d'isolation dans M-14 ops-preflight |

---

## C) GAPS vs « V1 tout fonctionnel et synchronisé »

Manques transverses encore non couverts par les missions CLOSED :

1. **Audit Claude rétrospectif** des 8 CLOSED Wave B (sans cela, la chaîne d'attestation est invalide selon `routing.md` + `MULTI_AGENT_ORCHESTRATION.md`).
2. **Régularisation GATE_LOG** : les 8 gates Wave B doivent porter une signature humaine vérifiable (pas "Codex ... instruction humaine explicite").
3. **M-13 migrations safety** : pas de rehearsal documentée — backup + rollback + dry-run staging absents.
4. **M-11 kiosk runtime** : offline read-only, désactivation paiement, transitions de connectivité, queue de menus — non implémenté.
5. **M-17 final audit** : web payment OFF + Stripe inactive doivent être prouvés par sentinels (route 4xx/feature flag) et tests E2E.
6. **M-21b refactor paiement FE** : prop mutation cleanup POS ↔ kiosk — non démarré, gate Option A approuvé.
7. **M-14 ops preflight** : `app:preflight-production`, Horizon, Queue topology multi-lane (NEW-03) — pas re-validé en condition Caisse V1.
8. **M-15 rollout canary** : phases 0–5 (`11_production_plan.jsonl`) — pas de canary plan exécutable.
9. **M-22 post-launch observability** : `sync-overview` (NEW-04) appliqué à Caisse V1 — pas re-instrumenté.
10. **Endpoints non audités** : aucun inventaire des routes ajoutées/modifiées Wave B (pas de `routes:list` diff cité) → risque de surface non documentée.
11. **Events / Outbox** : pas de re-vérif `dispatch after commit` + dédup post-M-05/06/07.
12. **Idempotence multi-tender** : POS multi-tender + refund + park — non re-validé Caisse V1.
13. **KDS station filter + bump expected_status** : sentinel d'intégrité (pas de double-bump) absent du dossier evidence.
14. **Fiscal Z chain hash** : symétrie OS/FOS sur fin de service — pas de test de bout en bout (commande POS finalisée → ligne audit_log → Z signé).
15. **Web public scope** : Option B "web payment off V1" — guard runtime (feature-flag + test 403) à attester.
16. **Graphiti / mémoire** : alignement post-masterplay (`memory/verify.py` ≥ 175) — pas attesté post-Wave B.

---

## D) PLAN D'ORCHESTRATION POUR CODEX (mission par mission)

> **Principe transversal pour TOUTES les missions ci-dessous** : (a) refus de toute modification hors `missions/<TASK_ID>/input.json.allowlist` ; (b) pas de self-approval de gate ; (c) `SYMMETRY_NOTE` exigé pour tout diff dans `OrderService.php` ou `FrontendOrderService.php` ; (d) tout dispatch d'event encadré par `afterCommit` / `DispatchableAfterCommit` (sinon REWORK) ; (e) backend reste autorité prix (cf. invariants §3) ; (f) `branch_id` LIKE strict ; (g) `OrderStatus` littéral numérique ; (h) `SCOPE_PRESSURE` = log dans `notes` du `output_codex.json` toute tentation d'ajouter un fichier hors allowlist.

---

### D.0 PRÉ-REQUIS GLOBAL — Régulariser la chaîne d'audit (BLOQUANT pour la suite)

Avant tout nouveau `PENDING → RUNNING`, **deux travaux non-Codex** doivent être faits côté humain/Claude :

- **Action H-1 (humain)** : éditer `docs/gates/GATE_LOG.md` lignes 39–47 → champ Approver = nom humain réel + commit SHA. Sinon les CLOSED Wave B sont contestables (R-01).
- **Action C-1 (Claude terminal)** : `bash scripts/foodking-claude-orchestrate.sh audit-brief CV1-M0X` pour chaque mission CLOSED Wave B sans `AUDIT_VERDICT` Claude (M-04B, M-05, M-06, M-07, M-08, M-09, M-10), résultat dans `reports/audit/AUDIT_REVERIFY_<TASK_ID>_2026-04-26.md`. Sinon double-audit invariant (R-02) violé.

Pas de M-13 / M-17 final / M-11 unlock tant que H-1 + C-1 ne sont pas exécutés.

---

### D.1 M-17 — WEB / STRIPE SCOPE (EXECUTED → à clore en priorité)

- **Prérequis** : C-1 fait ; `missions/CV1-M17-WEB-STRIPE-SCOPE/output_codex.json` lu ; `GATE_WEB_PAYMENT_SCOPE_V1` + `GATE_STRIPE_CENTS_ACTIVE` confirmés Option B.
- **Objectif produit** : Caisse V1 ne propose **aucun** paiement web public ; Stripe désactivé en prod (guard runtime + flag de config).
- **Périmètre autorisé** : `routes/web.php` (route paiement publique → 410/403 ou supprimée), `config/services.php` ou `config/payment.php` (flag `web_payment_enabled=false`, `stripe_active=false`), tests Feature/Unit dédiés. Hors-scope : `OrderService`, `FrontendOrderService`, schéma DB, vues kiosk/POS.
- **Stratégie de preuve** : 
  - `php artisan test --filter=WebPaymentDisabledTest`
  - `php artisan test --filter=StripeInactiveGuardTest`
  - sentinel route : `php artisan route:list | grep payment` (capture dans rapport)
  - tests symétriques : un payload public POST/paiement → 403/410 attesté.
- **Critère GOLDEN** : (i) AUDIT_VERDICT Claude PASS, (ii) GPT_FINAL_AUDIT_VERDICT PASS, (iii) un test échoue si on ré-active web payment sans gate, (iv) `GATE_LOG` affiche signature humaine réelle.
- **Stop & humain** : si Codex propose d'activer Stripe ou d'ouvrir une route publique → escalade immédiate (gate Option A pas signé).
- **Anti-dérive** : pas de modification du `PaymentService` pilote (M-04B est CLOSED) ; aucune migration ; `SYMMETRY_NOTE` non requis (web ≠ POS/kiosk).

---

### D.2 M-13 — MIGRATIONS SAFETY (PENDING — prochain gros bloc)

- **Prérequis** : D.0 fait ; M-17 CLOSED ; `GATE_SCHEMA_MIGRATIONS_CAISSE_V1` Option A signé (humain réel) ; backup snapshot staging prêt.
- **Objectif produit** : sécuriser **toutes** les migrations Caisse V1 (paiement pilote, order quotes, KDS releases, fiscal Z, idempotency) avec rehearsal + script rollback + lock-strategy non bloquante.
- **Périmètre autorisé** : `database/migrations/2026_*` Caisse V1, `app:preflight-production` (extensions migration check), `docs/operations/MIGRATIONS_SAFETY.md`, `tests/Feature/Migrations/*`. Hors-scope : services métier, contrôleurs, vues.
- **Stratégie de preuve** :
  - `php artisan migrate --pretend` (capture diff)
  - `php artisan migrate --pretend --database=staging`
  - Test rollback : `php artisan migrate:rollback --step=N` puis `migrate` ré-applique sans erreur.
  - Test concurrent-write (idempotence) : insertions parallèles pendant migration, aucune corruption.
  - `php artisan test --filter=MigrationsSafetyTest`.
- **Critère GOLDEN** : rehearsal staging documentée (`reports/migrations/REHEARSAL_2026-04-XX.md`), rollback script committé pour chaque migration, downtime estimé ≤ X seconds par migration documenté.
- **Stop & humain** : toute migration nécessitant `ALTER TABLE` bloquant > 5s → escalade (humain doit accepter la fenêtre de maintenance).
- **Anti-dérive** : pas de `down()` vide (R-10) ; pas d'index hors fenêtre concurrent ; pas de `DROP COLUMN` sans étape `nullable + backfill + drop` documentée.

---

### D.3 M-11 — KIOSK RUNTIME (BLOCKED — débloquer si M-08 evidence présente)

- **Prérequis** : D.0 fait ; vérifier que M-08 (`Z policy sealed`) a livré la preuve "kiosk ne finalise pas Z" (Option B fiscal). Si oui → `BLOCKED → PENDING` ; sinon, écrire dans queue : `BLOCKED note=missing-fiscal-evidence-from-M08`. Gate `GATE_OFFLINE_SCOPE_V1` Option A signé (humain réel).
- **Objectif produit** : Kiosk supporte mode dégradé offline = menu read-only + paiement **désactivé** + bandeau UI + reprise propre à la reconnexion.
- **Périmètre autorisé** : `resources/js/helpers/kioskOfflineQueue.js`, `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`, store kiosk cart/menu, sentinels Vitest ; **aucun** backend.
- **Stratégie de preuve** :
  - `npx vitest run tests/js/kioskOffline*.spec.js`
  - test : déconnexion réseau pendant wizard → boutons paiement disabled + message i18n (fr/en/ar)
  - test : reconnexion → re-fetch menu sans perte de panier
  - capture console : zéro erreur non-gérée pendant l'oscillation online/offline.
- **Critère GOLDEN** : double PASS + sentinel "paiement kiosk impossible offline" (refuse même si on force le clic).
- **Stop & humain** : si Codex tente d'activer une queue de paiement offline (Option B refusé) → REWORK + ESCALATION.
- **Anti-dérive** : pas de modification PaymentService ; pas d'écriture frontend qui bypass le `pricing` backend ; aucun helper côté kiosk ne calcule le total.

---

### D.4 M-21b — PAYMENT PROP REFACTOR (BLOCKED — débloquer si M-06/M-10 stabilisés)

- **Prérequis** : C-1 sur M-06 et M-10 confirme PASS Claude réel ; gate `GATE_PAYMENT_PROP_MUTATION_2026-04-26` Option A signé (humain réel — ligne actuelle = Codex auto-signed, à régulariser via H-1).
- **Objectif produit** : éliminer les mutations de props Vue côté `PaymentComponent.vue` / `PosComponent.vue` / `KioskPaymentComponent.vue`, sans casser le contrat backend OS/FOS.
- **Périmètre autorisé** : 3 composants Vue cités dans le brief, tests Vitest associés. Backend `OrderService` / `FrontendOrderService` **read-only** : la mission peut les *lire* pour vérifier le contrat, pas les modifier.
- **Stratégie de preuve** :
  - `npx vitest run tests/js/PaymentComponent.spec.js tests/js/PosComponent.spec.js tests/js/KioskPaymentComponent.spec.js`
  - lint Vue : règle `vue/no-mutating-props` activée + 0 violation.
  - test contrat : payload émis identique avant/après refactor (snapshot test).
- **Critère GOLDEN** : double PASS + sentinel ESLint "no-mutating-props" en CI ; payload backend identique octet pour octet (snapshot).
- **Stop & humain** : si la rétro-compatibilité du payload casse → REWORK + escalade (impacte M-06 déjà CLOSED).
- **Anti-dérive** : `SYMMETRY_NOTE` exigé sur diff entre PosComponent et KioskPaymentComponent ; pas de `OrderService.php` modifié.

---

### D.5 M-14 — OPS PREFLIGHT (BLOCKED — attend M-13 CLOSED)

- **Prérequis** : M-13 CLOSED ; Horizon configuré (NEW-03 queue topology vérifiée).
- **Objectif produit** : `php artisan app:preflight-production` couvre Caisse V1 (DB schema, migrations à jour, queues high/notifications/default opérationnelles, Pusher config, Stripe désactivé, fiscal Z signing key présente, branch_id index présents).
- **Périmètre autorisé** : `app/Console/Commands/AppPreflightProduction*`, `tests/Feature/Preflight/*`, `docs/operations/PREFLIGHT_CAISSE_V1.md`. Hors-scope : services métier.
- **Stratégie de preuve** :
  - `php artisan app:preflight-production` exit 0 sur env staging.
  - `php artisan test --filter=PreflightProductionTest` ≥ 12 cas (1 par check, 1 par négation).
  - injection d'erreur (queue down, branch_id absent…) → exit ≠ 0 + message clair.
- **Critère GOLDEN** : runbook `docs/operations/RUN_PREFLIGHT.md` reproductible humain + double PASS.
- **Stop & humain** : si la commande nécessite des credentials prod → ESCALATION (humain seul injecte).
- **Anti-dérive** : pas de modif schéma DB (M-13 a déjà clos ce volet) ; pas de désactivation de checks existants.

---

### D.6 M-15 — ROLLOUT CANARY (BLOCKED — attend M-04*+M-08+M-14)

- **Prérequis** : M-04B, M-08, M-14 CLOSED ; **1 campagne E2E Playwright vert** sur scénarios canary (R-09) — sinon REWORK pour evidence.
- **Objectif produit** : plan canary documenté + scripts (1 branche pilote, monitoring KPI, rollback ≤ 5 min, gate go/no-go par phase).
- **Périmètre autorisé** : `docs/operations/CANARY_PLAN_CAISSE_V1.md`, `scripts/canary/*`, `config/feature.php` (feature flags), `tests/Feature/Canary/*`. Pas de service métier modifié.
- **Stratégie de preuve** :
  - simulation rollback < 5 min sur staging (`scripts/canary/rollback.sh` + chronomètre).
  - sentinel : 1 feature flag par surface (POS / kiosk / KDS) toggleable par branche.
  - dashboard observability listant 5 KPI (sync.dispatch_p99, kds.fallback_p50, payment.failure_rate, fiscal.Z_chain_break_count, ws.auth_failures).
- **Critère GOLDEN** : double PASS + run E2E Playwright vert + sign-off humain explicite (pas Codex) dans `GATE_LOG` `GATE_GO_NO_GO_CAISSE_V1`.
- **Stop & humain** : décision GO/NO-GO **toujours humaine**. Codex ne signe **jamais** ce gate.
- **Anti-dérive** : aucun déploiement réel piloté par Codex ; tout `kubectl`/`deploy` reste humain.

---

### D.7 M-22 — POST-LAUNCH OBSERVABILITY (BLOCKED — attend M-14, M-15)

- **Prérequis** : M-14, M-15 CLOSED ; canary en cours d'observation.
- **Objectif produit** : appliquer `sync_metrics` (NEW-04) à Caisse V1 — dashboards `sync-overview` filtrés par phase canary, alertes (Sentry / paging), runbook on-call.
- **Périmètre autorisé** : `app/Services/Observability/*`, `app/Http/Controllers/Admin/Observability/*`, vues admin `sync-overview`, `tests/Feature/Observability/*`. Pas de modif service métier.
- **Stratégie de preuve** :
  - `php artisan test --filter=Observability` → tous verts.
  - alerte synthétique : injection ws.auth_failure → page on-call ≤ 1 min (test simulation).
  - dashboard renvoie p50/p95/p99 dispatch latency + filtre branch_id avec gate Spatie permission (rappel R-15 / NEW-04 G2).
- **Critère GOLDEN** : double PASS + 1 incident-drill documenté dans `reports/incident-drills/`.
- **Stop & humain** : si l'alerte requiert intégration Sentry prod → ESCALATION (clés API).
- **Anti-dérive** : pas de leak cross-branch (R-15) ; tests T-CLA-1..4 (NEW-04) maintenus verts.

---

## E) RÔLE CLAUDE vs GPT (politique pro-quota actuelle du dépôt)

**Politique en vigueur** (`docs/orchestration/MULTI_AGENT_ORCHESTRATION.md`, `routing.md`, `CODEX_API_DELEGATION.md`) :

- **Claude (terminal `foodking-claude-orchestrate.sh`)** = cerveau orchestration, plan, `AUDIT_VERDICT`. Quota Anthropic — chemin primaire pour audit. Fallback : sub-agent Cursor `foodking-planner-orchestrator` avec `AUDIT_FALLBACK_REASON`.
- **GPT-5.5-pro / xhigh (CLI codex, Pro)** = exécution PRIMARY (`codex:complex`), plan-review (`codex:plan-review`), final audit (`codex:final-audit`). Pas via l'orchestrateur Cursor — facturation OpenAI directe.
- **Composer / Cursor sub-agents** = report/validation only depuis Caisse V1 ; **ne fait plus d'édition produit**.
- **Pas de CLOSED sans double PASS (Claude `AUDIT_VERDICT` + GPT `GPT_FINAL_AUDIT_VERDICT`).** `FOODKING_GPT_ONLY=1` est une **dérogation tactique** (quota Claude saturé), pas une règle — toute mission close en GPT-only doit être re-auditée Claude rétrospectivement (action C-1 ci-dessus).

**Quand intervention humaine requise (gate vrai)** :
1. Approbation gate Frozen Zone, Schema, Payment Ledger, Fiscal, Web Payment, Stripe — **signature nominative** dans `GATE_LOG` (jamais "Codex").
2. Décision canary GO / NO-GO (`GATE_GO_NO_GO_CAISSE_V1`).
3. Migration prod, rollback, accès credentials prod.
4. Conflit `AUDIT_VERDICT` Claude vs `GPT_FINAL_AUDIT_VERDICT` GPT après 5 cycles REWORK.
5. Modification d'un invariant code (`project-invariants.mdc`) ou suppression d'un sentinel.
6. Interprétation d'evidence ambiguë (test passe mais comportement produit douteux — cas explicitement listé `CLAUDE.md` §7).

**Quand exécution autonome Codex acceptable** :
1. Mission `CV1-MXX-…` avec `input.json.allowlist` strict + `execute_brief.md` + dépendances CLOSED.
2. Diff dans le scope allowlist + tests `mandatory_tests` verts + `output_codex.json` valide.
3. ≤ 5 cycles REWORK (au-delà → escalade humain).

**Quand intervention Claude (orchestration, hors humain)** :
- Replan après PLAN_REVIEW REWORK GPT.
- AUDIT cycle Step 5 (`audit-brief`).
- Détection contradiction stable memory ↔ exécution courante (CLAUDE.md §10).
- Demande de gate (rédaction du brief, jamais signature).

---

## F) 10 PROCHAINES ACTIONS ORDONNÉES

> Une action = une tête de ligne, vérifiable par fichier ou commande. À traiter en série sauf indication.

1. **[Humain — H-1]** Régulariser `docs/gates/GATE_LOG.md` lignes 39–47 : remplacer Approver `Codex (instruction humaine explicite, 2026-04-25)` par signature humaine nominative + commit SHA. **Sortie attendue** : diff sur `docs/gates/GATE_LOG.md` + commit `chore(gates): human signature regularization`.
2. **[Claude terminal — C-1]** Lancer `bash scripts/foodking-claude-orchestrate.sh audit-brief CV1-M04B` puis idem pour M-05, M-06, M-07, M-08, M-09, M-10. **Sortie attendue** : 7 fichiers `reports/audit/AUDIT_REVERIFY_<TASK_ID>_2026-04-26.md` avec `AUDIT_VERDICT: PASS` ou `REWORK`.
3. **[Codex — M-17 final]** `npm run codex:final-audit -- CV1-M17-WEB-STRIPE-SCOPE` puis `bash scripts/foodking-claude-orchestrate.sh audit-brief CV1-M17`. **Sortie attendue** : `reports/audit/GPT_FINAL_AUDIT_CV1-M17.md` PASS + `AUDIT_VERDICT` Claude PASS + statut queue `EXECUTED → CLOSED`.
4. **[Humain — vérif DAG]** Inspecter `docs/gates/GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25.md` + evidence M-08 et décider : M-11 reste `BLOCKED` (avec note précisée) ou passe `PENDING`. **Sortie attendue** : édition de `plans/masterplay/MASTERPLAY_QUEUE.md` ligne 18 avec note explicite + commit.
5. **[Codex — M-13 prep]** Créer `missions/CV1-M13-MIGRATIONS-SAFETY/input.json` + `execute_brief.md` (allowlist migrations + preflight + docs). **Sortie attendue** : 2 fichiers présents, `bash scripts/run-masterplay.sh --dry-run` ne renvoie plus `BLOCKED note=missing-mission-files`.
6. **[Codex — M-13 exec]** `npm run codex:complex -- CV1-M13-MIGRATIONS-SAFETY` après dépendance D.0 + M-17 CLOSED. **Sortie attendue** : `reports/migrations/REHEARSAL_2026-04-XX.md` + `php artisan test --filter=MigrationsSafety` vert + `output_codex.json` valid + double PASS.
7. **[Playwright — campagne E2E V1]** Exécuter scénarios canary : POS commande dine-in payée → KDS bump → fiscal Z fin de service. **Sortie attendue** : `reports/antigravity/E2E_CAISSE_V1_2026-04-XX.md` avec runs vidéo + screenshots + console clean.
8. **[Humain — relire]** Lire et signer `docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md` (corriger l'auto-signature actuelle). **Sortie attendue** : ligne `GATE_LOG` mise à jour, M-21b `BLOCKED → PENDING` autorisé.
9. **[Codex — M-11 ou M-21b]** Premier des deux dont prérequis sont satisfaits (#4 ou #8) : `npm run codex:complex` + audit Claude + final audit. **Sortie attendue** : double PASS + queue mise à jour.
10. **[Claude — verdict transversal V1]** Après M-13/M-17/M-11 ou M-21b CLOSED : `bash scripts/foodking-claude-orchestrate.sh context && audit`. **Sortie attendue** : `reports/audit/CLAUDE_TRANSVERSAL_VERDICT_CAISSE_V1_2026-04-XX.md` avec verdict GO/HOLD/NO-GO préparant `GATE_GO_NO_GO_CAISSE_V1` (M-14/M-15/M-22 dépendent de ce verdict).

---

**Limites de cet audit** : je n'ai pas relu les `output_codex.json` individuels, ni `PLAN_CAISSE_V1_SUPER_MASTER`, ni les rapports `GPT_FINAL_AUDIT_*` ; les preuves de PASS GPT sont reprises depuis `MASTERPLAY_QUEUE.md` et le handoff. Les actions 2 et 3 servent précisément à fermer ce trou.
