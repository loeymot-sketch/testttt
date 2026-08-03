# 3ᵉ passe d’audit — Validation des implémentations récentes

**Date** : 2026-04-22  
**Périmètre** : gouvernance multi-agents (AGENTS / Primer / règles), mémoire Graphiti (180 JSONL ↔ Neo4j), code prod hardening (FiscalArchive, PreflightProduction), CI invariants (`scripts/check-invariants.sh`), skills layer (`.cursor/skills/` + règle `skills-scoping.mdc`).  
**Méthode** : lecture diff + relecture règles + exécution **statique** (validation JSON, lint PHP, `bash -n` script CI) + exécution **dynamique** (`scripts/check-invariants.sh -v`).  
**Modèle d’audit** : « équipe dev hyper-power » → on **valide** ce qui est solide, on **isole** chaque drift réel avec preuve, on **classe** P0 → P4 sans gonfler ce qui marche.

---

## 1. Verdict synthétique (à lire en 30 s)

| Domaine | Statut | Preuve |
|---|---|---|
| Mémoire JSONL (SSOT git) | **VERT** — 180/180 lignes JSON valides, comptes par fichier conformes au rapport précédent | `wc -l memory/episodes/*.jsonl` + parse JSON ligne-à-ligne (0 erreur) |
| Mémoire Neo4j (index dérivé) | **JAUNE** — **124/180** (P0 inchangé) ; runtime non re-testé dans cette passe | Reports d’audit antérieurs `verify.py` |
| Primer + liens AGENTS | **VERT** — entrée unique opérationnelle, lien depuis `AGENTS.md` § *Global system primer* | `AGENTS.md` L7-9, `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` |
| Règles Graphiti / global / context-hygiene | **VERT** — politique tokens « zéro effet négatif » écrite ; obligation d’écriture mémoire écrite | `global.mdc` L32-37, `context-hygiene.mdc` §4, `graphiti-memory.mdc` |
| FiscalArchive PROD-1 (TOCTOU) | **VERT** — Cache::lock partagé `z_report_b{n}` 600s/30s, fail-fast structuré, manifest schema v3 | `app/Console/Commands/FiscalArchiveCommand.php` L71-153, 232-254 |
| Preflight production | **VERT** — 14 dimensions, mode `--strict`, exit code propre | `app/Console/Commands/PreflightProductionCommand.php` L31-61 |
| `scripts/check-invariants.sh` | **JAUNE attendu** — `filter_aftercommit_wrapped` correctement ajouté ; 3 fail (4/6 + 5/6 + 2/6) **explicitement documentés en attente** de `P11_DISPATCH_AFTER_COMMIT_REMEDIATION` | `bash -n` OK ; exécution -v ; commentaire L151-156 |
| Drift mémoire ↔ code (canal pusher) | **ROUGE mineur** — épisode 03 `03_domain_events_sync.jsonl` ligne 7 décrit `private-branch.{branchId}.{surface}` et `Broadcast::channel('branch.{branchId}.{surface}', …)`. **Code réel** = sans `.{surface}` | `routes/channels.php` L25, `app/Listeners/PersistOrder*ToOutbox.php` L31-32 |
| Skills layer (`.cursor/skills/` + `skills-scoping.mdc`) | **VERT** — 5 skills présents, garde-fou aligné AGENTS / human-gates / frozen zones | `ls .cursor/skills/`, `.cursor/rules/skills-scoping.mdc` |

---

## 2. Mémoire — fait mesuré dans cette passe

### 2.1 JSONL (SSOT versionné)

```
01_project_overview.jsonl       11 lignes / 11 JSON valides
02_architecture_invariants.jsonl 12 / 12
03_domain_events_sync.jsonl     14 / 14
04_pricing_ssot.jsonl           10 / 10
05_fiscal_nf525.jsonl           12 / 12
06_kiosk_features.jsonl         14 / 14
07_pos_features.jsonl           16 / 16
08_kds_features.jsonl           10 / 10
09_tasks_history.jsonl          24 / 24      ← INDEX.md écrit "~25" (cosmétique, tilde)
10_tests_coverage.jsonl         12 / 12
11_production_plan.jsonl        12 / 12
12_decisions_log.jsonl          15 / 15
13_agents_roles.jsonl            8 /  8
14_conventions.jsonl            10 / 10
TOTAL                          180 / 180     0 erreur JSON
```

### 2.2 Neo4j (index dérivé)

Pas re-testé live dans cette 3ᵉ passe (la 2ᵉ passe rapportait **124/180**). **P0 inchangé** : exécution avec `DRAIN_TIMEOUT=7200` + `DRAIN_STALL_ITERS=120` (ou `clear_graph` puis full ingest) reste à faire **côté machine** avec MCP chargé. Voir `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md`.

### 2.3 Outils

- `memory/verify.py` — docstring **réalignée** sur `get_episodes(group_ids, max_episodes=500)` + heuristique `count('"uuid"')` (correction du diff utilisateur : OK).
- `memory/ingest.py` — drain configurable (`DRAIN_TIMEOUT`, `DRAIN_STALL_ITERS`, `SKIP_DRAIN`), buffer stdout 8 MiB, lock asyncio sur stdin → **bon**.

---

## 3. Gouvernance — validation point par point

| Vérification | OK / KO | Détail |
|---|---|---|
| `AGENTS.md` ouvre par lien clair vers Primer | **OK** | L7-9 « Global system primer (multi-agents, Graphiti, tokens — lecture clé) » |
| Primer décrit ordre de lecture obligatoire (7 fichiers) | **OK** | Primer §1 — `AGENTS.md` → `routing.md` → `run-cycle.md` → `graphiti-memory.mdc` → `global.mdc` + `context-hygiene.mdc` → `memory/INDEX.md` → `tasks/[TASK_ID].md` |
| Sub-agents Task `foodking-routine-implementer` / `foodking-complex-implementer` documentés | **OK** | Primer §2 + `run-cycle.md` Step 2 (délégation obligatoire avec `EXECUTE_DELEGATION:`) |
| Terminal allies `claude` / `codex` cadrés (compléments, pas SSOT gates) | **OK** | Primer §3 + `AGENTS.md` § Terminal allies |
| Politique « tokens — quality-first, zero negative optimization » | **OK** | `global.mdc` L32-37, `context-hygiene.mdc` §4 entête « handoff only — not dumbing down » |
| `graphiti-memory.mdc` — lecture début + écriture après décision durable | **OK** | L17-22 ; renvoi explicite Primer §4.2 |
| `project-continuity.mdc` pointe vers Primer | **OK** | L25 |
| `memory/README.md` — checklist mise à jour continue | **OK** | §« Mise à jour continue (obligatoire pour une mémoire robuste) » |
| `ACTIVE_CYCLE.md` mentionne 2ᵉ passe gouvernance | **OK** | L10 |
| `docs/orchestration/AGENT_ROLES.md` lien Primer | **OK** | L5 |

---

## 4. Code prod — modifs récentes (PROD-1, PROD-4)

### 4.1 `FiscalArchiveCommand` — TOCTOU + verify+build atomique (PROD-1 + W9.A)

**Validation statique** : `php -l` → no syntax errors.

**Validation logique** :
- ✔ Verrou `Cache::lock('z_report_b' . $branchId, 600)` **partagé** avec `ZReportService::open()/close()` (clé identique) → garantit qu’aucun Z ne s’ouvre/ferme pendant verify+build.
- ✔ `block(30)` court côté archive → tolère un Z close en flight (~2s) sans faux positif. Échec lock = log `fiscal.archive.lock_timeout` + `FAILURE` (ops re-run propre).
- ✔ `verifyZChainOrFail` : non-strict, gère `Throwable` (DB down → log `verify_chain.crash` + abort), gère `valid=false` (log `verify_chain.failed` avec `errors`/`first_z_id`/`last_z_id`/`count`).
- ✔ Manifest schema v2 → **v3** : ajout de `z_chain_verified` (bool) + `z_chain_verify_meta { count, first_z_id, last_z_id, verified_at }`. **Non destructif** pour anciens lecteurs (clés additives).
- ✔ Flag `--no-verify` documenté ops-recovery only ; config `fiscal.verify_chain_before_archive` (défaut `true`).
- ✔ `release()` toujours appelé via `try/finally` + `optional($lock)` (idempotent si jamais acquis).

**Risques résiduels** (non bloquants) :
- ⚠ `LOCK_TTL = 600s` : si l’archive d’une grosse branche dépasse ce TTL, le lock expire. Le commentaire dimensionne pour ~500k orders + 1M audit rows ; à monitorer en prod (alerte si `archive.duration > 540s` = 90 % TTL).
- ⚠ Si la planif J-1 coïncide avec un autre cron `archive` long → le second attendra `LOCK_WAIT=30s` puis `FAILURE` propre. Souhaitable : ajouter `withoutOverlapping()` côté Kernel si pas déjà fait.

### 4.2 `PreflightProductionCommand` — gate de déploiement

- ✔ 14 checks (APP_ENV / DEBUG / KEY / TIMEZONE / CACHE / QUEUE / BROADCAST / SESSION / LOG_LEVEL / LOG_CHANNEL / fiscal secrets / verify_chain_before_archive / DB reachable / Cache round-trip).
- ✔ `--strict` traite WARNING comme erreur → bonne option CI/CD avant flip symlink.
- ✔ Exit code unique 0/1 propre.

---

## 5. CI invariants — `scripts/check-invariants.sh`

### 5.1 Bonus implémenté

- ✔ **`filter_aftercommit_wrapped`** : pour chaque hit, inspecte les 5 lignes au-dessus dans le fichier ; si `DB::afterCommit(` apparaît → considéré wrappé → retiré du résultat. Élégant car ça évite la pollution `// allow:` site par site sur les listeners catalog.
- ✔ Pattern 4/6 enrichi : FQN (`\App\Events\X::dispatch`) + short-name (`X::dispatch` avec `use`) + helpers Laravel (`event(new X(…))`, `Event::dispatch(new X(…))`).
- ✔ Scope étendu (`AvailabilityService`, `ItemService`, `ItemCategoryService`, `AvailabilityController`).
- ✔ `bash -n` propre.

### 5.2 État réel exécution (3ᵉ passe)

```
1/6 SSOT pricing                 OK
2/6 branch_id server-side only   FAIL (1)
3/6 status via OrderStateMachine OK
4/6 App\Events\* afterCommit     FAIL (8)   ← attendu (P11 remédiation pas encore livrée)
5/6 EventContract envelope       FAIL (1)   ← faux positif sur DispatchableAfterCommit::broadcast() trait override
6/6 audit log                    OK
```

**Diagnostic** :
- **4/6** : 8 hits réels dans `OrderService.php` (L549, 991, 1305, 1462, 1517, 1614) et `FrontendOrderService.php` (L837, 843). Le commentaire L151-156 du script dit explicitement « WILL fail until P11_DISPATCH_AFTER_COMMIT_REMEDIATION ». **Statut conforme à la doc**.
- **5/6** : un seul hit dans `app/Events/Concerns/DispatchableAfterCommit.php:70` qui est précisément le **mécanisme** de wrap après-commit (pattern `broadcast(...$arguments)` sur le trait). Faux positif structurel — mérite un `// allow:` ciblé ou un exclude spécifique sur `Concerns/DispatchableAfterCommit.php`.
- **2/6** : à investiguer (1 hit unique non détaillé en mode -v dans cette passe — peut être un site où `branch_id` admin est légitime). À prendre en charge dans une boucle CI dédiée si pas déjà tracké.

---

## 6. Drifts détectés (preuves)

### 6.1 ROUGE mineur — Épisode 03 ligne 7 vs code

**Mémoire** (`memory/episodes/03_domain_events_sync.jsonl` épisode « Channels Pusher — autorisation et scoping ») dit :

> Tous les channels métier sont **`private-branch.{branchId}.{surface}`**. Authorization (routes/channels.php) : **`Broadcast::channel('branch.{branchId}.{surface}', …)`** …

**Code réel** :

```25:39:routes/channels.php
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
        $machine = \App\Models\KioskMachine::where('user_id', $user->id)->first();
        return $machine && (int) $machine->branch_id === (int) $branchId;
    }
    if ((int) $user->branch_id === 0) {
        return true;
    }
    return (int) $user->branch_id === (int) $branchId;
});
```

```31:32:app/Listeners/PersistOrderCreatedToOutbox.php
            'channel' => json_encode(['private-branch.' . $order->branch_id]),
```

```31:37:app/Listeners/PersistItemAvailabilityChangedToOutbox.php
            $channels = ['private-branch.' . $event->branchId];
            …
                ->map(fn (int $branchId): string => 'private-branch.' . $branchId)
```

→ **Pattern réel** : `private-branch.{branchId}` (sans suffixe `.kds` / `.pos`). Le routage par surface se fait au **niveau event/listener** (KDS écoute `OrderCreated`, POS écoute `OrderStatusChanged`, etc.), **pas** au niveau du nom de canal.

**Impact** : un LLM qui interroge la mémoire et n’a pas le code en contexte peut **inventer** un canal `.kds` qui ne s’authentifiera pas. C’est exactement le drift décrit dans la 1ʳᵉ passe — **toujours présent**.

**Action P4** : éditer la ligne 7 de `03_domain_events_sync.jsonl` pour aligner la formulation, puis `bash bin/graphiti-ingest.sh 03_domain` ciblé.

### 6.2 JAUNE cosmétique — `INDEX.md` annonce ~25 épisodes pour `09_tasks_history.jsonl`

Réel : 24. Le tilde `~25` reste défendable comme arrondi, mais à l’avenir préférer le compte exact pour que `verify.py` puisse comparer mécaniquement.

### 6.3 JAUNE structurel — `5/6 EventContract envelope` faux positif

Le hit unique est sur `DispatchableAfterCommit.php:70` qui **est** le wrap. Suggestion : ajouter `Concerns/DispatchableAfterCommit.php` à l’exclude du check 5/6 ou mettre un `// allow:` ciblé sur la méthode.

### 6.4 VERT (rien à corriger) — Skills layer

`.cursor/skills/` contient 5 SKILL.md (`project-handoff`, `frontend-design`, `web-design-guidelines`, `systematic-debugging`, `foodking-vue-best-practices`) ; `.cursor/rules/skills-scoping.mdc` rappelle l’ordre d’autorité (AGENTS / human-gates / frozen zones > skills). Aucun conflit avec le Primer.

---

## 7. Lacunes orchestration repérées (additif aux 2ᵉ et 1ʳᵉ passes)

### 7.1 Symétrie « tests verts ↔ mémoire à jour »

Aucun **hook** ne force aujourd’hui un commit avec modification d’`OrderService` / `FiscalArchive` à mettre à jour un épisode JSONL. **Risque** : on ajoute la `LOCK_WAIT` PROD-1 sans nouvel épisode `12_decisions_log.jsonl`. La règle Primer §4.2 est **textuelle** ; un check CI minimal (« si diff touche `app/Services/Orders/` ou `app/Console/Commands/Fiscal*`, alors présence d’un nouveau lien dans `12_decisions_log.jsonl` recommandée ») la rendrait **opérationnelle**.

### 7.2 `verify.py` — robustesse sémantique

Couverture actuelle : 8 requêtes dans `verify.py`. Trous **fonctionnels** non détectés :
- pas de requête sur `12_decisions_log` (ADR / gates) ;
- pas de requête sur `13_agents_roles` (sub-agents) ;
- pas de requête sur `11_production_plan` autre que la générique « rollout ».

Implémentation triviale (déjà détaillée P1 du rapport précédent), à coupler avec une sortie JSON dans `reports/memory/verify_snapshot.json` pour pouvoir diffuser l’état à un humain sans relancer.

### 7.3 `EXECUTE_DELEGATION:` — preuve d’audit

Le `run-cycle.md` Step 2/4 l’exige déjà. Aucun **lint** vérifie la présence dans les rapports passés. Une grep CI sur `reports/` (ex. `if grep -L "EXECUTE_DELEGATION:" reports/execution/RUN_*.md`) en mode WARN serait un filet utile.

---

## 8. Plan priorisé (additif, non destructif)

| Prio | Action | Effort | Bénéfice |
|---|---|---|---|
| **P0** | Re-ingestion Graphiti `DRAIN_TIMEOUT=7200` `DRAIN_STALL_ITERS=120` jusqu’à `verify.py count ≥ 175` (idéal 180) | 45–120 min machine humaine | Mémoire **complète** runtime |
| **P1** | Refresh épisode 03 ligne 7 → canal `private-branch.{branchId}` + `Broadcast::channel('branch.{branchId}', …)` ; `bash bin/graphiti-ingest.sh 03_domain` | 5 min | Élimine drift sémantique (peut induire un mauvais canal côté LLM) |
| **P1** | Ajouter `Concerns/DispatchableAfterCommit.php` à l’exclude du check **5/6** dans `scripts/check-invariants.sh` | 2 min | Élimine 1 faux positif structurel pérenne |
| **P2** | Étendre `verify.py` à 12-15 requêtes + sortie `reports/memory/verify_snapshot.json` (option `--json`) | 30 min | Détecter trous **par domaine** (12/13/14) |
| **P2** | Ajouter ligne `12_decisions_log.jsonl` pour PROD-1 (TOCTOU lock partagé) + PROD-4 (preflight) ; ingest 12 | 10 min | Tracer la décision dans la mémoire vivante |
| **P3** | CI WARN `grep -L EXECUTE_DELEGATION reports/execution/RUN_*.md` | 10 min | Filet de sécurité audit traceability |
| **P3** | `2/6` : élucider le 1 hit puis soit fixer code soit `// allow:` motivé | 15 min | Score CI propre |
| **P4** | Snapshot manifest mémoire dans `reports/memory/` pour comparer hashes JSONL ↔ snapshot ingéré (memory-drift CI optionnel) | 1 h | Anti-dérive long terme |

---

## 9. Ce qui est **prêt** dans cette passe

- Lecture / validation des nouveaux artefacts gouvernance et code récents.
- Validation statique mémoire (180/180), lint PHP FiscalArchive, syntaxe bash CI script.
- Exécution dynamique `check-invariants.sh -v` pour confirmer les hits attendus / inattendus.
- Identification chirurgicale des drifts vs code réel.

## 10. Ce qui n’est **pas** fait dans cette passe (et pourquoi)

- **Re-test live `verify.py` Neo4j** : le serveur Graphiti met ~30s à démarrer + queue async ; choix volontaire de ne pas redéclencher (état documenté 124/180 dans 2 rapports précédents).
- **Modifications de code** : la mission était audit + validation, pas patching. Les drifts P1 sont identifiés et chiffrés mais laissés à l’itération suivante (souvent un ingest ciblé ou un `// allow:` ciblé : 5–15 min).

---

## 11. Pointers (fichiers cités dans ce rapport)

- `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`
- `reports/audit/AUDIT_GRAPHITI_SYSTEM_DEEP_2026-04-22.md`
- `reports/audit/AUDIT_SECOND_PASS_GLOBAL_GOVERNANCE_REPORT_2026-04-22.md`
- `app/Console/Commands/FiscalArchiveCommand.php`
- `app/Console/Commands/PreflightProductionCommand.php`
- `scripts/check-invariants.sh`
- `routes/channels.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
- `memory/episodes/03_domain_events_sync.jsonl`
- `memory/ingest.py`, `memory/verify.py`, `memory/README.md`, `memory/INDEX.md`
- `.cursor/rules/global.mdc`, `context-hygiene.mdc`, `graphiti-memory.mdc`, `skills-scoping.mdc`
- `.cursor/commands/run-cycle.md`
- `.cursor/ACTIVE_CYCLE.md`

---

## 12. Phrase de clôture

L’**orchestration repo** est désormais **structurellement robuste** : un point d’entrée unique (Primer), des règles always-on alignées, une politique tokens explicite « quality-first », un cycle borné (run-cycle) avec délégation tracée, une mémoire SSOT versionnée à 100 %, et un cycle de hardening prod (W9 + W10 closeout) qui clôt les findings W8.

La **dernière mile runtime** reste le **rattrapage Neo4j** (P0) et un **refresh chirurgical** des 1–2 épisodes en drift (P1) ; tout le reste est de l’optimisation incrémentale (P2-P4) sans urgence opérationnelle.
