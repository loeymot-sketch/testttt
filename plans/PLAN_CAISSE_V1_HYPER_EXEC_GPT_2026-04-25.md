# PLAN_CAISSE_V1_HYPER_EXEC_GPT_2026-04-25

**Rôle** : couche d’**exécution massive** pour **GPT-5.5-pro / Codex CLI** (`codex-extension`), orchestrée par **Claude** (PLAN, AUDIT, clôture).  
**Ne remplace pas** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` (DAG autoritaire) — c’est le **playbook d’implémentation** avec tâches lourdes, invariants, tests et discipline.

**Verdict exécution** (inchangé)  
- `READY_FOR_PHASE_0: YES`  
- `READY_FOR_PRODUCT_CODE: NO` tant que `SUPER_MASTER_BLOCKER: HUMAN_GATES_FIRST`  
- Toute tâche « code produit » listée ici ne démarre qu’**après** `PLAN-03` (gates résolus) + allowlist par `TASK_ID`.

**Mémoire** : si Graphiti MCP actif, `search_memory_facts(group_ids=["foodking"])` avant chaque gros lot — sinon `memory/INDEX.md` (règle dépôt).

---

## 1. Doctrine d’orchestration (intelligence + discipline)

| Principe | Application |
| --- | --- |
| **Séquence FoodKing** | `PLAN (Claude) → PLAN_REVIEW GPT (codex) → EXECUTE GPT → self-audit GPT → VALIDATE → AUDIT Claude → GPT_FINAL_AUDIT` — voir `AGENTS.md` + `.cursor/commands/run-cycle.md`. |
| **Exécuteur produit** | **PRIMARY** : CLI `codex` (`npm run codex:complex -- <TASK_ID>`), modèle `gpt-5.5-pro`, `xhigh`. **FALLBACK** : `Task → foodking-complex-implementer` avec `FALLBACK_REASON` documenté. **Composer** : validation/rapport, pas finition produit. |
| **Rôles parallèles** | Avant la signature des gates : lots **no-code / tests-only / doc / CI design / hardware** (liste §6 super master). Après gates : tronc **quote + payment + branch + KDS + fiscal + kiosk** en respectant le DAG. |
| **Sous-agents Cursor** | Utiliser l’**explorateur** (`subagent_type: explore`) pour cartographier un sous-domaine *avant* d’écrire le `execute_brief.md` ; **shell** pour `verify:boucle`, tests, `agent-activity-log.sh`. **Pas** d’implémentation produit en doublon du canal Codex PRIMARY. |
| **Invariants (gate si violation)** | Prix **SSOT backend** ; `OrderStatus` **enum** partout ; `branch_id` **isolation** ; `dispatch` **après** `DB::commit` / équivalent ; symétrie **OrderService / FrontendOrderService** ; **frozen zones** seulement avec gate tracé dans `docs/gates/GATE_LOG.md`. |
| **Traçabilité** | Chaque P0 mappe vers `PLAN-XX` + `TASK_ID` + test — compléter `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` (statut `INITIAL_NOT_FINAL` → `COMPLETE`). |

**Arbitrage déjà tranché** (ne pas re-débattre en EXECUTE) : *« **Concepts Codex, séquence Claude** »* — primitives (`OrderIntent`, `OrderQuote`, preuve paiement, `KitchenRelease`) **dans l’ordre** : sentinels + gates → **sécurité / branches / POS** → **quote** → **paiement** → **fiscal** → **KDS / release** → **kiosk runtime** → **ops / rollout** → **UX finitions** (`reports/audit/CODEX_CLAUDE_MEGA_PLAN_COMPARISON_CAISSE_V1_2026-04-25.md`).

---

## 2. Phases d’exécution (vagues)

### Vague A — Immédiat (sans code frozen)

| PLAN-ID | But | Tâches GPT / humain lourdes |
| --- | --- | --- |
| **PLAN-01** | Matrice P0 → `TASK_ID`, owner, preuve | Script ou mission Codex : normaliser en table machine les findings des 3 audits + dispute + super master review ; id stable `FK-###` ; colonne `PREUVE_MANQUANTE`. |
| **PLAN-02** | 18 sentinels fail-first | Missions `CAISSE_V1_SENTINEL_*` : un fichier test par sentinelle ; baseline rouge/vert documentée ; lien finding → test. |
| **PLAN-03** | 10 gates | Humain : briefs prêts, décision, `GATE_LOG.md` ; GPT peut *rédacter* propositions d’options, pas *approuver*. |
| **PLAN-12** | Legacy guards (design) | Codex : design règles `eslint`/`phpcs`/`ripgrep` CI pour imports legacy, bundle scan, routes — PR « config only » si hors frozen. |
| **PLAN-16** | Hardware | Runbook TPE, imprimante, tiroir, scannette — checklist exécutable. |
| **PLAN-19** | Mémoire | `after-execute-memory.sh` + épisodes ciblés `memory/episodes/*` ; preuve `memory/verify.py` si actif. |
| **PLAN-20** | Runbook TOC | Squelette ORDER_FLOW, BUSINESS_RULES, opérations — pas de contenu métier inventé. |
| **PLAN-18** | Architecture tests | Grille de couverture : PHPUnit / Vitest / Playwright / charge — liée au §8 du super master. |

### Vague B — Après `PLAN-03` (code produit autorisé selon allowlist)

Ordre logique imposé par le DAG (sécurité d’abord) :

1. **PLAN-04A xor PLAN-04B** — Ledger complet **ou** pilote restreint (décision gate).  
2. **PLAN-05** — `OrderQuote` : signature, TTL, anti-replay, **total serveur = seul payable**.  
3. **PLAN-06** — Garde-fous POS : `payment-confirm`, route cash dédiée, course cleanup/confirm, effets de bord no-op, anti-forge discount — **souvent frozen** : gate.  
4. **PLAN-09** — Isolation `branch_id` (listes, show, transactions, KDS, Z, exports).  
5. **PLAN-07** — KDS : whitelist transitions, `expected_status`, prédicat de release, overflow.  
6. **PLAN-08** — Fiscal : politique borne A/B/C, Z, HMAC, refunds — gates fiscal + schéma.  
7. **PLAN-10** — Symétrie OS / FOS **après** 06+09.  
8. **PLAN-11** — Kiosk : offline, enum, machine, file d’attente offline **selon** `GATE_OFFLINE_SCOPE_V1`.  
9. **PLAN-17** — Web / Stripe : **selon** `GATE_WEB_PAYMENT_SCOPE_V1` + `GATE_STRIPE_CENTS_ACTIVE`.  
10. **PLAN-13 → PLAN-14 → PLAN-15** — Migrations sèches, observabilité, canary/rollback.  
11. **PLAN-21** — UX finitions (subordonné au super master) — intégré aux lots ci-dessous.  
12. **PLAN-22** — Post-lancement : anomalies, dashboards, on-call.

---

## 3. Mégâches GPT (grains « max intelligence ») par PLAN

Chaque bloc est une **mission Codex** potentielle : `missions/<TASK_ID>/input.json` + `execute_brief.md` + `plan_excerpt.md` + `graphiti_context.md` (si dispo). Avant toute exécution : `bash scripts/agent-activity-log.sh start <AGENT> <TASK_ID> execute "<fichiers CSV>" "<note>"`.

### PLAN-01 / TASK proposé : `CAISSE_V1_TRACEABILITY_COMPLETE_2026-04-25`

- **Objectif** : 0 P0 sans `PLAN-ID`, 0 P0 sans test ou `PREUVE_MANQUANTE` explicite.  
- **Livrable** : mise à jour du fichier de matrice + éventuel CSV `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.csv`.  
- **Génie GPT** : regrouper les doublons inter-audits ; assigner `FK-###` stable ; lier chaque ligne aux sections des rapports source.  
- **Validation** : revue humaine + Claude AUDIT sur cohérence des gates.

### PLAN-02 / Tâches : `CAISSE_V1_SENTINEL_BASE_001` … (une par sentinelle super master §7.2)

Pour **chaque** sentinelle listée (ex. `PaymentConfirmAbilitySentinelTest`, `KdsTransitionWhitelistSentinelTest`, …) :  
- **Logique** : test minimal qui **échoue** si l’invariant disparaît (fail-first).  
- **Preuve** : commande `php artisan test --filter=…` ou `npx vitest run …` enregistrée dans le rapport d’exécution.  
- **Contrainte** : pas toucher la prod métier au-delà de hooks de test / doubles — si ça requiert frozen, **stop** + gate.

### PLAN-04A — `CAISSE_V1_PAYMENT_LEDGER_FULL_2026-04-25` (si gate = A)

- **Implémenter** : machine d’état paiement, idempotence callback, journal audit, cohérence avec `OrderStatus`, pas de double capture.  
- **Vérifications** : `PaymentLedgerStateMachineTest` ; tests de concours ; preuve **commit-before-dispatch** sur nouveaux jobs.  
- **SYMMETRY_NOTE** : toute logique d’ordre partagé kiosk/POS : auditer l’autre service.

### PLAN-04B — `CAISSE_V1_PAYMENT_PILOT_RESTRICT_2026-04-25` (si gate = B)

- **Implémenter** : refus serveur explicite pour chemins hors pilote, UI désactivée, journal des tentatives, **aucun** branchement silencieux par `.env`.  
- **Tests** : `PaymentMethodRestrictedTest` + tests d’attaque (client qui POST hors scope).

### PLAN-05 — `CAISSE_V1_ORDER_QUOTE_V1_2026-04-25`

- **Contrat** : HMAC (ou équivalent) sur empreinte intent ; TTL 60s par défaut ; rejeu = même réponse idempotente ; altération = 403.  
- **Génie GPT** : couverture edge — fuseaux, arrondi monnaie, remises, items indisponibles, multi-branch.  
- **Tests** : `QuoteExpirationTest`, `QuoteTamperTest`, `QuoteReplayIdempotencyTest` (noms super master).

### PLAN-06 — `CAISSE_V1_POS_REVENUE_GUARDS_2026-04-25`

- Hardening `payment-confirm` (ability, machine, méthode, preuve de branche).  
- Route collecte cash POS **non** liée à endpoint cuisine.  
- Course `CleanupVsConfirm` : stratégie documentée (verrou, statut, preuve) + test de concours.  
- `OrderStatus` no-op sans effet monétaire (cashback) — **pointer vers frozen OrderService** : gate P0.  
- **GATE** : `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` + règles routes API.

### PLAN-07 — `CAISSE_V1_KDS_RELEASE_TRANSITIONS_2026-04-25`

- Whitelist `OrderStatus` / transitions ; **expected_status** obligatoire sur bump.  
- Release vers cuisine seulement si prédicat `KitchenRelease` (aligné rapports).  
- Pagination / overflow : pas de commande « invisible ».  
- **Gate** : `GATE_KDS_BUMP_V1` + feature flag documenté.

### PLAN-08 — `CAISSE_V1_FISCAL_Z_NF525_2026-04-25`

- Implémenter **la** politique retenue (gate `GATE_FISCAL_KIOSK_V1`).  
- Z, pré/post remboursement, chaîne HMAC, exports — tests dédiés + preuve légale (docs NF525).  
- Aucun montant client-side pour le fiscal.

### PLAN-09 — `CAISSE_V1_BRANCH_ISOLATION_2026-04-25`

- Passer en revue **toutes** les surfaces listées super master : requêtes **toujours** `branch_id` + policy staff branch=0.  
- Tests : `OrderListBranchExactnessTest`, `OrderShowBranchGuardTest`, `TransactionBranchExactnessTest`, `FiscalZBranchExactnessTest`, etc.

### PLAN-10 — `CAISSE_V1_OS_FOS_SYMMETRY_2026-04-25`

- Tableau de correspondance des méthodes (création, statut, annulation, paiement).  
- Tests de contrat / golden responses ; `SYMMETRY_NOTE` **vide** ou résolu pour close.

### PLAN-11 — `CAISSE_V1_KIOSK_RUNTIME_2026-04-25`

- Offline = cash only si gate A ; refus CB/TR offline avec message et log côté serveur.  
- Préfixe ID offline, enum : plus de `16` en dur.  
- Parité preview promo (tests sentinelles).  
- **Ne pas** implémenter web payment ici si gate web = off.

### PLAN-12/13/14/15 — lots « prod-ready »

- CI guards legacy ; dry-run migration + backup ; preflight queue/worker/scheduler/broadcast ; feature flags + canary + critères de rollback **mesurables**.

### PLAN-17 — `CAISSE_V1_WEB_STRIPE_SCOPE_2026-04-25`

- Désactiver chemins publics **ou** sécuriser `PaymentIntent` (pas d’ID brut, signature, scoping) ; tests cents Stripe **si** gate active.

### PLAN-21 + sous-plan finitions

**Référence** : `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` (subordonné au Caisse V1 super master).  
Mapper les **LOT-x** en cycles `run-cycle` distincts (ex. `POS_KDS_FINITIONS_LOT0_QUICKWINS_2026-04-26`).  
**Règle** : `PaymentComponent` mutation de props = **`GATE_PAYMENT_PROP_MUTATION_2026-04-26`** — pas de refactor avant **Approved** ; LOT-0/2 restent sur périmètre autorisé (focustrap sans logique métier, RTL Swiper, etc.).

**Note routing dépôt** : pour les cycles de **finition** récents, l’**EXÉCUTEur PRIMARY** des changements **produit** est **`codex-extension`**, pas le sous-agent *routine* — mettre à jour le champ `PRIMARY` dans le plan de lot si on aligne sur `AGENTS.md` (éviter dérive d’exécution).

---

## 4. *Prompt discipline* (à coller dans `execute_brief.md` pour chaque mission GPT)

> Tu es l’exécuteur FoodKing sur le `TASK_ID` {TASK_ID}.  
> Lis `AGENTS.md`, `missions/{TASK_ID}/input.json`, `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` (section {PLAN-ID}).  
> **Respecte** : prix backend seul ; `OrderStatus` via enum ; `branch_id` partout ; `dispatch` seulement après commit ; symétrie OS/FOS si tu touches l’un.  
> **Fichiers** : uniquement l’allowlist du plan ; sinon `SCOPE_PRESSURE` + stop.  
> **Livrable** : diff minimal, tests exigés passent, `SYMMETRY_NOTE` rempli, pas d’approbation de gate.  
> **Sortie** : `missions/{TASK_ID}/output_codex.json` + résumé fichiers + commandes lancées.

---

## 5. Rôle de Claude (orchestrateur + **audit final**)

| Moment | Action Claude |
| --- | --- |
| **Avant** EXECUTE | Valider le plan `plans/PLAN_*`, `SUBSYSTEMS_TOUCHED`, `INVARIANTS_AT_RISK`, `GATE_CONDITIONS`. |
| **Après** VALIDATE | Checklist `audit-context.md` + invariants : `bash scripts/foodking-claude-orchestrate.sh context` puis `audit` (ou **fallback** `foodking-planner-orchestrator` + `AUDIT_FALLBACK_REASON` si terminal HS). |
| **Après** Claude AUDIT | `npm run codex:final-audit -- {TASK_ID}` — `GPT_FINAL_AUDIT_VERDICT: PASS` requis. |
| **Fermeture** | `CLOSED` seulement si double PASS + gates résolus + trace `EXECUTE_DELEGATION` + pas d’`ESCALATION` / `SYMMETRY_NOTE` ouvert. |
| **Audit programme Caisse V1** (fin de trajectoire) | Revue transversale : chaîne *borne → centrale → POS → KDS → fiscal* ; relecture de `TRACEABILITY_MATRIX` ; décision `GO/NO-GO` avec `GATE_GO_NO_GO_CAISSE_V1` humain. |

---

## 6. État explicite du cycle actif (ne pas mélanger)

Dans **`.cursor/ACTIVE_CYCLE.md`**, le `TASK_ID` courant pointe vers un autre plan (`P_EXEC_CLOSEOUT_…`). Avant d’enchaîner un `run-cycle` Caisse V1 : **clôturer** ou **archiver** le cycle W10, puis initialiser un nouveau `TASK_ID` Caisse V1 via `run-cycle` Step 0.

---

## 7. Résumé une ligne

**Phase 0** : matrice complète, sentinels, gates humains, guards/ops/mémoire sans toucher le frozen. **Phase produit** : après signature des gates, Codex exécute le DAG **dans l’ordre** ci-dessus avec tests nominaux et discipline invariants ; **Claude audite** chaque tranche et **l’ensemble** en fin de programme.

`TRACEABILITY_STATUS` cible : `COMPLETE` | `GATES: SIGNED` | `SUPER_MASTER_READY_FOR_PRODUCT_CODE: YES` (humain + preuves).
