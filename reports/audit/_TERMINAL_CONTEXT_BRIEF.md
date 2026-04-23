# Bref contexte FoodKing — alimentation terminal Claude Code

Généré : 2026-04-23T15:12:48+02:00

## .cursor/ACTIVE_CYCLE.md (extrait, 60 premières lignes)
# Active Cycle – FoodKing

> **ACTIVE_PRIMARY** : `CYCLE_W10_EXECUTION_CLOSEOUT` (un seul cycle peut être actif à la fois — voir B03 méga-checklist).
> Cycles plus anciens en lecture seule = archive ou monitoring CI uniquement.

## CYCLE_W10_EXECUTION_CLOSEOUT (IN_PROGRESS — mémoire 180 + MCP global + commit + CI + prod)

**TASK_ID** : `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22`  
**Plan SSOT** : `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md`  
**Ordre** : Piste A (POS+Centrale : PLAN-MEM-1) ∥ Piste B (humain : PLAN-MEM-3) → C (smoke) → D (commit sur « go commit ») → E (CI) → F (prod J-7→J+7).  
**Gate mémoire** : `python3 memory/verify.py` → count **≥ 175** (180 idéal) avant de considérer PLAN-MEM-1 **CLOSED**.

- **Vérif locale (2026-04-22)** : `python3 memory/verify.py` → **count = 182**, smoke `search_memory_facts` OK — gate **satisfaite** pour clôturer l’ingestion côté seuil d’épisodes (suite : commit / CI / prod selon plan `PLAN_EXECUTION_CLOSEOUT_*`).

**Gouvernance globale (2e passe 2026-04-22)** : primer multi-agents + Graphiti vivant + tokens « zéro effet négatif » → **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** + rapport **`reports/audit/AUDIT_SECOND_PASS_GLOBAL_GOVERNANCE_REPORT_2026-04-22.md`**.

---

## CYCLE_W9_AUDIT_GLOBAL_PROD_READY (COMPLETED PASSED — local 200%, pending commit + CI push)

TASK_ID: P_AUDIT_GLOBAL_W1_W9_PROD_READY_2026-04-21
PHASE: VERIFY 200% local PASSED ; pending commit + CI MySQL push + Playwright opt-in
PARENT: CYCLE_W9_NF525_HARDENING_RECEIPT_POLICY (audit final stage)
REPORTS:
- reports/audit/AUDIT_W1_W9_GLOBAL_2026-04-21.md (5 explore agents, 10 fixes, 10 accepted findings)
- reports/audit/AUDIT_W1_W9_PROD_READY_2026-04-21.md (4 prod-hardening fixes added on top)

DELIVERABLES PROD-HARDENING (4 ajouts code + 1 cycle doc) :

PROD-1 — TOCTOU FiscalArchive eliminated
- app/Console/Commands/FiscalArchiveCommand.php : verify+build sous Cache::lock('z_report_b{n}', 600s, wait 30s)
- Garantie : la fenêtre cryptographique vérifiée == la fenêtre exportée (aucun Z ne peut s'ouvrir/fermer pendant l'archive)
- Fail-fast structuré si lock indisponible (log channel `fiscal`, exit FAILURE)

PROD-2 — Idempotency lookup tenant-scoped (admin cross-tenant collision)
- app/Services/OrderService.php::posOrderStore : lookup `where idempotency_key = ? AND branch_id = ?`
- Empêche un Admin (branch_id=0) de récupérer la commande d'une autre branche via une clé identique
- Couvert par le test existant `IdempotencyBranchScoped` (2 scénarios déjà verts)

PROD-3 — CI migration drift guard
- .github/workflows/phpunit.yml : nouveau step `Migration drift check` (migrate --pretend + migrate + migrate:status) AVANT phpunit
- Détecte un .php migration cassé / non-autoloadable AVANT que les tests fail avec "table X not found"

PROD-4 — Pre-deployment gate
- app/Console/Commands/PreflightProductionCommand.php : `php artisan app:preflight-production [--strict]`
- Vérifie 14 dimensions : APP_ENV, APP_DEBUG, APP_KEY, TIMEZONE, CACHE_DRIVER, QUEUE_CONNECTION, BROADCAST_DRIVER, SESSION_DRIVER, LOG_LEVEL, LOG_CHANNEL, FISCAL_AUDIT_SECRET, FISCAL_Z_REPORT_SECRET, FISCAL_VERIFY_CHAIN_BEFORE_ARCHIVE, DB reachability, Cache round-trip
- Exit 0 = safe to flip symlink ; exit 1 = au moins un CRITICAL
- Mode `--strict` = warnings traités comme erreurs (CI/CD gate)

VERIFY 200% (LOCAL) :
- ✅ vendor/bin/phpunit --testsuite Feature = 718/718 PASSED (2124 assertions, 8 skipped MySQL-only, 145s)
- ✅ vendor/bin/phpunit --filter "FiscalArchive|ZOpenChain|ReceiptPrint|PosOrder|Idempotency" = 61/61 PASSED (231 assertions, 9s)
- ✅ npx vitest run = 719/719 PASSED (93 fichiers, 9s)
- ✅ ReadLints (4 fichiers modifiés/créés) : no errors
- ✅ php artisan app:preflight-production (dry-run local) : détecte correctement secrets prod manquants + LOG_LEVEL debug + APP_ENV local — comportement attendu

INVARIANTS PRÉSERVÉS :
- NF525 : audit_logs INSERT-only chaîné, Z signature HMAC, fiscal_sequence_no monotone, archive J-1 verify+build atomique
- Multi-tenant : branch_id scope sur idempotency lookup (PROD-2), CleanupStalePendingKioskOrders (FIX-5 W9-AUDIT)
- Boot fail-fast : CACHE_DRIVER ≠ array|null en prod, secrets fiscal min 32 chars (FIX-2 W9-AUDIT + PROD-4)

## Dernières entrées — memory/episodes/12_decisions_log.jsonl
{"name": "Décision : E06 + A05 + B17 + C02 (2026-04-24) — CI Vitest, manifeste JSONL, rétro B17, matrice routage", "source": "message", "source_description": "Session orchestrateur 2026-04-24 — continuer méga-checklist après P11/terminal", "episode_body": "E06 : workflow .github/workflows/vitest.yml — on.push branches main + develop. A05 : phpunit.yml step Memory JSONL manifest exécute scripts/memory-jsonl-manifest.sh --check reports/memory/jsonl_manifest.json (tout changement memory/episodes exige regén + commit du manifeste). B17 : 3 rapports d'exécution RUN_* complétés (EXECUTE_DELEGATION) — RUN_V14_GLOBAL_AUDIT_REMEDIATION, RUN_P13_LOG_HYGIENE, RUN_P_MEGA_W6_A_A11Y_EXECUTE. C02 : doc docs/orchestration/ROUTING_MATRIX.md (1 page routine vs complex, lien .cursor/routing.md) ; liens dans AGENTS.md + GLOBAL_SYSTEM_PRIMER. MEGA_CHECKLIST A05 B17 C02 E06 cochés ; compteur total 34 [x] / 3 [~] / 143 [ ]."}
{"name": "Décision : pipeline post-supplémentation (mémoire + terminal + abonnement utile) — 2026-04-24", "source": "message", "source_description": "Clôture alimentations de base avant phase design POS — demande utilisateur", "episode_body": "Ajout scripts/after-execute-memory.sh (regén manifeste, --check .files, rappel graphiti-ingest par jsonl modifié git, rappel verify.py + chaine terminal). Fix scripts/memory-jsonl-manifest.sh : --check compare seulement la clé JSON files (exclut generated_at instable) ; write si .files change sinon skip (moins de bruit git). scripts/foodking-claude-orchestrate.sh : commande post-execute = after-execute-memory + _TERMINAL_CONTEXT_BRIEF ; context/post-execute utilisables sans binaire claude. Section Post-implémentation dans le bref disque. Objectif : après chaque livraison, enchaîner alimentation JSONL+manifest+ingest, puis optionnellement context puis audit-brief (crédits Anthropic ciblés) pour capitaliser l'abonnement."}
{"name": "Décision : Memory Matrix officielle (4 stores autorisés) + non-intégration OpenSpace et claude-mem — 2026-04-23", "source": "message", "source_description": "Demande utilisateur : décider intelligemment matrice mémoire vs fork de 2 dépôts externes ; priorité simplicité + clarté", "episode_body": "Création docs/orchestration/MEMORY_MATRIX.md : 4 stores autorisés A=Code/git (vérité de comportement), B=Graphiti+memory/episodes/*.jsonl (décisions durables, écrit en AUDIT seulement), C=missions/<TASK>/ (contexte de tâche éphémère, écrit en PLAN+EXECUTE), D=plans/ + reports/ + ACTIVE_CYCLE.md + docs/gates/ (trace procédurale). Règle : un type de fait → un seul propriétaire. Ajout d'un 5e store interdit sans gate docs/gates/GATE_MEMORY_*. Décisions outils tiers : OpenSpace (HKUDS) NON intégré (skills auto-évolutives, n'écrit dans aucun store, runtime Python+DB+cloud), claude-mem (thedotmack) NON intégré (continuité intra-session Claude Code, AGPL-3.0, hors notre canal codex-terminal+claude non-interactif). Pinning : AGENTS.md §primer ajoute ligne MEMORY_MATRIX, run-cycle.md Step 0 ajoute item 6 (memory discipline) + Step 5 AUDIT précise écriture B uniquement décisions durables avec ref 1-ligne dans D, GLOBAL_SYSTEM_PRIMER §1 insère MEMORY_MATRIX en position 6 (avant memory/INDEX.md). Anti-patterns explicites : pas de pseudo-store dans reports/, pas de décision uniquement dans commit message, pas de copie verbatim Graphiti↔reports."}
{"name": "Décision : Synchronisation multi-agents append-only (cross-conv, cross-terminal) — 2026-04-23", "source": "message", "source_description": "Demande utilisateur : éviter que deux agents en parallèle (Cursor convs, codex-terminal, claude-terminal, humain) modifient les mêmes fichiers à leur insu, sans gaspillage de tokens", "episode_body": "Création reports/AGENT_ACTIVITY_LOG.md (append-only) + scripts/agent-activity-log.sh (tail | start | done | collisions | active). Pairing par AGENT+TASK (CONV informatif, permet libération depuis nouveau shell après crash). Règle alwaysApply .cursor/rules/cross-agent-sync.mdc : tail 50 (~500 tokens) au démarrage de session ; start avant édition produit (refus exit 2 si collision) ; done à CLOSE (statuts done|blocked|abandoned). Extension du store D de MEMORY_MATRIX, pas un nouveau store (pas de gate). Épinglage : AGENTS.md §primer, run-cycle.md Step 0 item 7 + Step 2 (start) + Step 5 (done). Anti-patterns : réservation trop large (app/), oubli du done, forçage d'un start après refus. Audit boucle existante simultané : OUI loop PLAN→EXECUTE(codex-terminal PRIMARY)→VALIDATE→AUDIT(Claude cursor ou claude-terminal)→remediation jusqu'à 3 essais→CLOSED|GATE est obligatoire et enforced via global.mdc + auto-remediation.mdc + human-gates.mdc + run-cycle.md (EXECUTE_DELEGATION ligne mandatoire bloque VALIDATE). Le seul trou couvert par cette décision : sync entre conversations parallèles."}
{"name": "Décision : symétrie terminal-first — EXÉCUTE (codex-terminal) + AUDIT (claude terminal) avec fallback Cursor explicite — 2026-04-24", "source": "message", "source_description": "Clarification utilisateur : priorité abonnement terminal (Claude Anthropic + API GPT via proxy) ; sub-agents Cursor = repli seulement si terminal HS ; règles mises en cohérence + test script verify-orchestration-boucle", "episode_body": "Doctrine (symétrie) : (1) EXÉCUTE complexe — PRIMARY = codex-terminal (proxy CODEX_*, gpt-5.4) ; FALLBACK = foodking-complex-implementer après echec reprises avec EXECUTE_DELEGATION + FALLBACK_REASON. (2) AUDIT apres alimentation — PRIMARY = claude en terminal (scripts/foodking-claude-orchestrate.sh context then audit/audit-brief) ; trace AUDIT_CHANNEL: claude-terminal + TERMINAL_AUDIT_OK:1 ; FALLBACK = memem checklist en session Cursor avec AUDIT_CHANNEL: cursor-session + AUDIT_FALLBACK_REASON: obligatoire. PLAN reste le plus souvent en session Cursor (orchestrateur) — non soumis a la regle audit terminal. Fichiers mis a jour : .cursor/routing.md, .cursor/commands/run-cycle.md Step5, AGENTS.md roles + stop conditions, docs/orchestration/CODEX_API_DELEGATION section 0+diagram, GLOBAL_SYSTEM_PRIMER table terminal allies, .cursor/rules/global.mdc + auto-remediation re-audit + global-operating-principles. Outil : scripts/verify-orchestration-boucle.sh (default = check binaire; VERIFY_BILLING_FULL=1 = 1x claude smoketest + 1x npm run codex:smoke) ; package.json verify:boucle + verify:boucle:full. Test local 2026-04-24 : VERIFY_BILLING_FULL=1 => ALL GREEN (claude TERMINAL_OK, codex OK gpt-5.4)."}

## memory/INDEX.md (début)
# FoodKing — Index de la mémoire d'intelligence

> Table des matières navigable des épisodes Graphiti.
> Chaque fichier = un domaine. Chaque ligne JSONL = un fact atomique.

| # | Fichier | Domaine | Épisodes | Pour qui |
|---|---------|---------|----------|----------|
| 01 | `01_project_overview.jsonl` | Vision, business, stack, surfaces | ~10 | Tout LLM/dev qui découvre le projet |
| 02 | `02_architecture_invariants.jsonl` | Invariants techniques, frozen zones, multi-tenant | ~16 | Avant toute modification backend |
| 03 | `03_domain_events_sync.jsonl` | Outbox, DispatchableAfterCommit, Echo, dédup | ~14 | Travail sur sync borne↔POS↔KDS |
| 04 | `04_pricing_ssot.jsonl` | Single Source of Truth pricing, formules, edge cases | ~10 | Avant toute modif PricingService |
| 05 | `05_fiscal_nf525.jsonl` | Conformité fiscale FR, chain hash, Z, audit_log | ~12 | Conformité, compta, fiscaliste |
| 06 | `06_kiosk_features.jsonl` | Wizard tacos, multi-quantité, allergens, offline, a11y | ~14 | Dev frontend Kiosk |
| 07 | `07_pos_features.jsonl` | Park orders, multi-tender, refund, floorplan, ESC/POS, NFC | ~16 | Dev frontend POS |
| 08 | `08_kds_features.jsonl` | Bump/recall, station filter, timers, item availability | ~10 | Dev KDS |
| 09 | `09_tasks_history.jsonl` | 22 tasks V14 + Vague D + cross-wave findings (G-1, G-2, G-3, SYNC-001/002) | 24 | Audit, planning, debug régression |
| 10 | `10_tests_coverage.jsonl` | Sentinels Vitest 707 + PHPUnit 825, par domaine | ~12 | Avant tout refactor |
| 11 | `11_production_plan.jsonl` | Sync-first rollout phases 0-5, monitoring, V2 plan | ~12 | Préparation prod, ops |
| 12 | `12_decisions_log.jsonl` | ADRs, gates passed/blocked, choix d'architecture | 25 | Comprendre POURQUOI |
| 13 | `13_agents_roles.jsonl` | Multi-agents (Claude/GPT-5.4/Composer), orchestration | ~20 | Reprendre orchestration |
| 14 | `14_conventions.jsonl` | Naming, scope, safety, paths critiques, hooks | ~10 | Tout dev |

> Voir aussi : `memory/JSONL_SCHEMA.md` (schéma strict), `memory/POLICIES.md` (clear_graph + duplicates).

## Recherche typique par cas d'usage

### "Reprendre le projet sans contexte (nouveau LLM)"
```
search_memory_facts query="FoodKing project overview surfaces stack"
search_nodes query="frozen zone OrderService PaymentService"
```


## Rappel
- Neo4j/Graphiti : **pas** branché sur ce script ; lire `memory/INDEX.md` + JSONL, ou le MCP `search_memory_facts` dans **Cursor**.
- Ce fichier évite de recoller tout le chat : **réutilisé** par `audit-brief`.

## Post-implémentation (ordre — alimentation base + abonnement utile)
1) `bash scripts/after-execute-memory.sh` — manifeste + rappel `graphiti-ingest` (si JSONL touchés).
2) `bash /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/scripts/foodking-claude-orchestrate.sh context` (ce bref) ; option utile 3) `audit-brief` = audit claude -p ciblé.
