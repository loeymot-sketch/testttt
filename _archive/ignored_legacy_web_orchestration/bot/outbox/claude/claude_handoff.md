# FoodKing — Claude handoff (bot v0)

## Cycle metadata
- **cycle_id**: `be85eab0-71c6-4131-840b-9fa86a877b31`
- **persisted_state**: `waiting_claude`
- **claude_round**: `plan`
- **task_id**: `REAL-CYCLE-002`

## Current phase
- **Orchestration state**: `waiting_claude`
- **Claude round**: `plan`
- **Meaning**: Waiting for **Claude plan** output. Next bot step: `register-claude-response --file <plan.json>`.

## Objective
Inspect OrderService::deliveryBoyOrderChangeStatus for pre-save notification dispatch, transaction safety, and required fix classification. Produce an execution-ready plan only.

## Critical zones (risk class)
`unknown` until a Claude plan JSON is registered.

## Surfaces touched (paths / files)
- *(none listed yet)*

## Registered Claude packet (from JSON, if any)
- *(no `claude_response.json` yet — run `register-claude-response` or `register-claude-review` after pasting JSON)*

## Repository documents tracked in intake JSON
Full captured bodies (bounded per section at intake build time) live in:
- `bot/state/handoffs/be85eab0-71c6-4131-840b-9fa86a877b31/claude_intake.json`

### Section index (paths only)
| section | source_path | truncated |
|---|---|---|
| `planning_latest` | `reports/planning/latest.md` | `False` |
| `execution_latest` | `reports/execution/latest.md` | `False` |
| `review_latest` | `reports/review/latest.md` | `False` |
| `antigravity_latest` | `reports/antigravity/latest.md` | `False` |
| `bugbot_latest` | `reports/review/bugbot-latest.md` | `False` |
| `claude_md` | `CLAUDE.md` | `False` |
| `memory_md` | `MEMORY.md` | `False` |
| `docs__ops__CLAUDE_CYCLE_INTAKE.md` | `docs/ops/CLAUDE_CYCLE_INTAKE.md` | `False` |
| `docs__ops__BOT_TO_CLAUDE_RUNTIME_CONTRACT.md` | `docs/ops/BOT_TO_CLAUDE_RUNTIME_CONTRACT.md` | `False` |
| `docs__ops__CLAUDE_CYCLE_OUTPUT.md` | `docs/ops/CLAUDE_CYCLE_OUTPUT.md` | `False` |
| `docs__ops__CLAUDE_SCORING_RUBRIC.md` | `docs/ops/CLAUDE_SCORING_RUBRIC.md` | `False` |
| `docs__ops__CURSOR_MODEL_ROUTING_POLICY.md` | `docs/ops/CURSOR_MODEL_ROUTING_POLICY.md` | `False` |
| `docs__ops__CYCLE_002b_LOCAL_VALIDATION_COMMAND_PACK.md` | `docs/ops/CYCLE_002b_LOCAL_VALIDATION_COMMAND_PACK.md` | `False` |
| `docs__roles__00_ORCHESTRATOR_ROLE.md` | `docs/roles/00_ORCHESTRATOR_ROLE.md` | `False` |
| `docs__roles__01_ARCHITECTURE_MEMORY_ROLE.md` | `docs/roles/01_ARCHITECTURE_MEMORY_ROLE.md` | `False` |
| `docs__roles__02_PRODUCT_VISION_UX_ROLE.md` | `docs/roles/02_PRODUCT_VISION_UX_ROLE.md` | `False` |
| `docs__roles__03_DEEP_AUDIT_ROLE.md` | `docs/roles/03_DEEP_AUDIT_ROLE.md` | `False` |
| `docs__roles__04_RESEARCH_BENCHMARK_ROLE.md` | `docs/roles/04_RESEARCH_BENCHMARK_ROLE.md` | `False` |

## Report excerpts (verbatim, deterministic trim)
### `planning_latest` → `reports/planning/latest.md`
```
# Plan de Handoff Claude — Bugs Complexes Restants

**Date** : 2026-03-27  
**Auteur** : Kimi  
**Type de test** : local-validation (PHPUnit ciblé login borne)  
**Priorité** : HAUTE

---

## 0. Identifiants / auth — corrigé par Kimi + pièges pour Claude

### 0.1 Ce qui bloquait en pratique (captures utilisateur)

| Symptôme | Cause probable | Correctif Kimi |
|----------|----------------|----------------|
| Connexion borne avec `chef@example.com` dans « Identifiant machine » | Confusion : le champ attend le **`username` de `kiosk_machines`**, pas un compte staff | UI : texte d’aide + placeholder `kiosk-lecayenne` + refus immédiat si `@` (front + API 422) |
| Admin / démo : boutons rapides remplissaient `@demo.foodking.app` | Defaults `config('app.demo_credentials')` ne correspondaient pas au `UserTableSeeder` Le Cayenne | Defaults alignés `admin@lecayenne.fr` / `123456`, etc. + fallbacks JS `LoginComponent.vue` |
| « Invalid Api Key » sur toute l’SPA | `.env` avec seulement `API_KEY` alors que Laravel lit **`MIX_API_KEY`** (`config/app.php`) | `MIX_API_KEY` documenté dans `.env.example` + repli `API_KEY` si `MIX_API_KEY` vide |
| En-tête catalogue « NOS NOS BURGERS » | Préfixe UI `NOS` + libellé catégorie déjà « Nos … », ou double « Nos » en base | `stripLeadingNos` en **boucle** + même logique sur **sidebar** (`displayCategoryName`) |

### 0.2 Référence seed local (hors `APP_ENV=production`)

- **Admin** : `admin@lecayenne.fr` / `123456`
- **POS** : `pos@lecayenne.fr` / `123456`
- **Borne** : utilisateur machine `kiosk-lecayenne` / `kiosk123` (`KioskMachineTableSeeder`, branche 1)
- **Important** : `chef@example.com` n’existe que si `DEMO=true` au moment du seed utilisateurs ; ce n’est **jamais** un identifiant borne.

### 0.3 Check-list bugs / risques résiduels (à traiter par Claude si besoin)

- [ ] **P0 prod** : refuser démarrage ou log clair si `config('app.api_key')` est vide en `production` (aujourd’hui `'' === ''` peut être ambigu selon clients HTTP).
- [ ] **P1** : document d’onboarding unique (`README` + `docs/AUDIT_LOGIN_ACCOUNTS.md`) synchronisé après chaque changement de seeder.
- [ ] **P1** : flux « première borne » sans seed (création machine uniquement admin) — message d’erreur métier si aucune ligne `kiosk_machines` pour la branche.
- [ ] **P2** : internationaliser `kiosk_username_not_email` pour `ar`, `bn`, `de` (seuls `en` / `fr` ajoutés).

---

## 1. Compréhension architecture actuelle

- FoodKing repose sur Laravel côté backend et Vue 3 côté surfaces client/admin.
- La borne, la caisse, le KDS et l’écran client partagent le même noyau métier commande/prix/statuts.
- Le temps réel principal passe par `OrderCreated` / `OrderStatusChanged` sur `private-branch.{branch_id}`.
- Le push secondaire passe par FCM avec des topics par surface (`kitchen_branch_X`, `pos_branch_X`, etc.).
- Le tunnel borne est maintenant beaucoup plus proche de GUR visuellement, mais les invariants critiques restent backend/synchro.
- La numérotation de file, les états de paiement et l’apparition KDS doivent rester parfaitement cohérents entre POS et borne.
- Le projet n’a toujours pas de vrai modèle “rupture / stock bloquant” exploité de bout en bout.
- Les prochains sujets demandent une décision d’architecture, pas juste un patch local.

---

## 2. Ce que Kimi a corrigé facilement

- Nettoyage du header catalogue pour éviter `NOS NOS ...` quand le nom de catégorie contient déjà `Nos`.
- Badge produit changé de `MENU` vers `PERSONNALISER` pour 

<!-- bot v0: truncated at SNIPPET_CHAR_LIMIT chars -->

```

### `execution_latest` → `reports/execution/latest.md`
```
# Execution report — REAL-CYCLE-001

**cycle_id:** `bfebb694-c71d-4310-9731-4a9e6f7053fd`  
**task_id:** `REAL-CYCLE-001`  
**Date:** 2026-04-12  
**Scope:** Documentation-only alignment of `OrderStatus` integers with `app/Enums/OrderStatus.php` (P1-01).

## Source of truth (read-only)

Enum `App\Enums\OrderStatus` (interface `app/Enums/OrderStatus.php`):

| Constant | Integer |
|----------|---------|
| PENDING | 1 |
| ACCEPT | 4 |
| PREPARING | 7 |
| PREPARED | 8 |
| OUT_FOR_DELIVERY | 10 |
| DELIVERED | 13 |
| CANCELED | 16 |
| REJECTED | 19 |
| RETURNED | 22 |

**No PHP, test, migration, or route files were modified.**

## Per-file verification

### `docs/BUSINESS_RULES.md`

- **Checked:** §4 pipeline and terminal states already match the enum (PENDING(1) through DELIVERED(13), plus CANCELED/REJECTED/RETURNED).
- **Changed:** no (already correct).

### `docs/DATABASE_SCHEMA_CORE.md`

- **Checked:** Mermaid `ORDER.status` annotation lists all nine statuses with correct integers.
- **Changed:** no (already correct).

### `.cursor/rules/safety.mdc`

- **Before:** Pipeline listed main flow; terminal states referred to as “(+ états terminaux enum)” without explicit integers.
- **After:** Same pipeline plus explicit `CANCELED (16)`, `REJECTED (19)`, `RETURNED (22)` and pointer to `app/Enums/OrderStatus.php`.

### Other docs (out of write scope)

- Searched `docs/` for legacy wrong order-status patterns (e.g. PENDING(5), DELIVERED(17), PREPARED(14) as **order** status). `docs/CONTRIBUTING_QA_BOTS.md` mentions “14 pour PREPARED” only as a **warning against** wrong docs — no change required in allowed files.
- `docs/ARCHITECTURE_TECHNIQUE.md`, `docs/roles/*`, etc. still contain simplified flow text without `OUT_FOR_DELIVERY`; **not edited** (outside `files_allowed` for this cycle).

## Validation

- Command: `php artisan test --filter=Order`
- **Result:** 61 passed (exit 0).

## Files changed (this execution)

1. `.cursor/rules/safety.mdc` — explicit terminal `OrderStatus` integers + file reference.
2. `reports/execution/latest.md` — this report.
3. `bot/inbox/cursor_result/cursor_done.json` — cycle completion signal.

```

### `review_latest` → `reports/review/latest.md`
```
# Rapport de Review — Phase 49 + Audit Phase 50 (Claude Architect)

**Date**: 2026-03-24  
**Agent**: Claude (Architect & Reviewer)  
**Verdict**: NEEDS_FIX (Phase 50 requise)

---

## Verdict Phase 49

Phase 49 correctement implémentée par Kimi (8/8 bugs). Aucune régression Vue/PHP détectée.

**MAIS** : L'audit Phase 50 révèle que la correction BUG-P49-6 (idempotence POS) est **silencieusement inopérante** car :
- `idempotency_key` absent du `$fillable` de `Order` → jamais sauvegardé
- `PosComponent.vue` n'envoie pas le header `X-Idempotency-Key`

---

## Audit Phase 50 — Nouveaux bugs détectés

Après lecture complète de :
- `app/Models/Order.php` + `FrontendOrder.php`
- `app/Services/OrderService.php` (posOrderStore)
- `app/Http/Requests/OrderRequest.php` + `PosOrderRequest.php`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/store/modules/kioskCart.js`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `app/Http/Controllers/Frontend/LoyaltyController.php`
- `app/Listeners/AwardLoyaltyPointsOnDelivery.php`

### Bugs identifiés

| ID | Priorité | Description |
|----|----------|-------------|
| BUG-P50-1 | 🔴 CRITIQUE | `Order::$fillable` manque `idempotency_key` → idempotence POS jamais sauvegardée |
| BUG-P50-2 | 🔴 CRITIQUE | `PosComponent.vue` n'envoie pas `X-Idempotency-Key` → idempotence POS inopérante |
| BUG-P50-3 | 🟠 IMPORTANT | `FrontendOrder::$fillable` manque `source_surface` → risque futur |
| BUG-P50-4 | 🟠 IMPORTANT | `OrderRequest.total` sans `min:0` → total négatif accepté |
| BUG-P50-5 | 🟠 IMPORTANT | Points fidélité calculés sur total client, pas total serveur → divergence possible |
| BUG-P50-7 | 🟡 MOYEN | `KioskWaiting` : orderId invalide → poll en boucle sur `/show/undefined` |
| BUG-P50-8 | 🟡 MOYEN | `LoyaltyController.register()` : email doublon → 500 non gérée |
| BUG-P50-9 | 🟡 MOYEN | `kioskCart.idempotencyKey` non réinitialisé après commande → hit idempotence sur nouvelle commande |
| BUG-P50-10 | 🟡 MOYEN | Points attribués sur commande PREPARED puis CANCELED → perte financière |

---

## Score global

| Domaine | Score |
|---------|-------|
| Sécurité | 9.5/10 |
| Synchronisation queue | 9.8/10 |
| Idempotence | 6.0/10 (POS inopérant) |
| Fidélité | 9.3/10 |
| UX kiosk | 9.5/10 |
| KDS/OSS | 9.7/10 |
| **Global** | **9.4/10** |

---

## Verdict final

**NEEDS_FIX** — Phase 50 requise.

Après Phase 50 + configuration Redis + tests E2E manuels : **APPROVED pour production**.

```


## Requested output type
Claude **plan** JSON → save then run register-claude-response with `.\bot-cli.ps1` (Windows, repo root) or `python bot/cli.py` with `PYTHONPATH=.`: `register-claude-response --file …`.

## Decision expectation
- Obey FoodKing invariants: server-side pricing, authz, order lifecycle (see `CLAUDE.md` / `docs/`).
- If scope is unsafe or ambiguous, respond with `human_decision: STOP` or `suggested_next_actor: human_gate` in the plan JSON.
- Output must be **actionable** for the next bot step (plan JSON or review JSON), not prose-only unless paired with structured fields.
