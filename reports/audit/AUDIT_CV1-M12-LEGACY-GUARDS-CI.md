# AUDIT — CV1-M12-LEGACY-GUARDS-CI

**DATE** : 2026-04-25
**AUDITOR** : Claude (foodking-planner-orchestrator)
**MISSION_ID** : M-12 (Wave A masterplay — Caisse V1)
**TASK_ID** : CV1-M12-LEGACY-GUARDS-CI
**PRIMARY_EXECUTION_MODEL** : codex-extension (gpt-5.5-pro / xhigh)
**AUDIT_CHANNEL** : cursor-session (foodking-planner-orchestrator subagent)
**AUDIT_FALLBACK_REASON** : Audit délégué par l'orchestrateur Claude session courante après recovery du faux REWORK initial (bug d'extraction JSON dans le wrapper, output GPT valide & fichiers écrits par Codex via workspace-write — confirmé par lecture directe `output_codex.json` + `ls -la` allowlist).
**TERMINAL_AUDIT_OK** : 0 (audit Cursor — terminal `claude` non sollicité ; recovery context, pas re-run masterplay)

---

## 1. Inputs lus

- `missions/CV1-M12-LEGACY-GUARDS-CI/execute_brief.md`
- `missions/CV1-M12-LEGACY-GUARDS-CI/input.json`
- `missions/CV1-M12-LEGACY-GUARDS-CI/output_codex.json`
- 8 livrables listés dans l'allowlist (lecture intégrale)
- `git status -s` ciblé sur `scripts/`, `.github/`, `docs/orchestration/`, `kiosk_implementation/`, `borne (Remix)/`, `app/`, `resources/`, `routes/`, `database/`
- Smoke-run local des 4 scripts lint depuis la racine du repo

---

## 2. Findings

### 2.1 Existence (8/8) — PASS

| # | Fichier | Taille | Permissions | Présent |
|---|---|---|---|---|
| 1 | `scripts/lint-fk-legacy-imports.sh` | 1901 B | `-rwxr-xr-x` | OUI |
| 2 | `scripts/lint-fk-legacy-routes.sh` | 1815 B | `-rwxr-xr-x` | OUI |
| 3 | `scripts/scan-bundle-legacy.sh` | 810 B | `-rwxr-xr-x` | OUI |
| 4 | `scripts/lint-fk-archive-banner.sh` | 719 B | `-rwxr-xr-x` | OUI |
| 5 | `.github/workflows/legacy-guards.yml` | 614 B | `-rw-r--r--` | OUI |
| 6 | `docs/orchestration/LEGACY_QUARANTINE_2026-04-25.md` | 2211 B | `-rw-r--r--` | OUI |
| 7 | `kiosk_implementation/ARCHIVE_BANNER.md` | 742 B | `-rw-r--r--` | OUI |
| 8 | `borne (Remix)/ARCHIVE_BANNER.md` | 720 B | `-rw-r--r--` | OUI |

Tous fichiers > 0 octet, contenu exploitable.

### 2.2 Scripts lint (qualité technique) — PASS

- **Shebang** : `#!/usr/bin/env bash` sur les 4 scripts — correct.
- **Bit exécutable** : présent (`chmod +x` confirmé).
- **`set -euo pipefail` absent** : **conforme** au brief — `execute_brief.md` §RÈGLES exige explicitement *« Pas de `set -e` dans les lints qui doivent continuer après findings (utiliser flag manuel) »*. GPT a respecté la consigne en utilisant les flags `FOUND`/`FAILED` + `exit "$FAILED"` final. Ne pas pénaliser ce point — la consigne audit générique a été remplacée par la consigne plus stricte de la mission.
- **Patterns ciblés** :
  - `lint-fk-legacy-imports.sh` : 4 patterns ETC (rg si dispo, fallback `find … -exec grep -nE`) couvrant `from '…/kiosk_implementation/'`, `from '…/borne (Remix)/'`, `from '…/pos-wizard'`, `require(…)` sur les 3 cibles ; cible `resources/js`, exclut `tests/`, `public/`, `node_modules/` — exactement ce que demande le brief.
  - `lint-fk-legacy-routes.sh` : awk scoped au bloc `Route::prefix('frontend')` de `routes/api.php` ; détecte les 3 routes legacy ; exit 0 toujours si lecture OK (monitor — conforme).
  - `scan-bundle-legacy.sh` : skip propre si `public/build/` absent ; sinon `find -name '*.js' -exec grep -lE` sur les 3 motifs ; exit 1 si match — conforme.
  - `lint-fk-archive-banner.sh` : présence + 1ʳᵉ ligne commence par `# ARCHIVE` ; exit code = somme des défauts — conforme.
- **macOS-compat** : `grep -E`, pas de `grep -P`. Conforme au brief.
- **Smoke-run depuis la racine** :
  ```
  [OK] archive banner present: kiosk_implementation/ARCHIVE_BANNER.md
  [OK] archive banner present: borne (Remix)/ARCHIVE_BANNER.md
  [OK] no legacy imports
  [INFO] Legacy frontend routes still present:
    routes/api.php:914 — frontend/item-category
    routes/api.php:910 — frontend/item/kiosk-upsell
    routes/api.php:904 — frontend/item
  [SKIP] no build present
  ```
  Tous exit 0 (attendu : monitor + skip + clean).

### 2.3 Workflow CI (`.github/workflows/legacy-guards.yml`) — PASS

- YAML valide (indentation 2 espaces).
- Triggers : `pull_request` avec `paths` ciblés (`resources/js/**`, `routes/**`, `public/build/**`, `kiosk_implementation/**`, `borne (Remix)/**`) + `push: main`.
- Job `legacy` runs-on `ubuntu-latest`, checkout v4, exécute les 4 scripts dans l'ordre logique : banner → imports → routes (monitor) → bundle.
- Aucune dépendance superflue (pas de setup Node/PHP — non requis pour ces guards bash).

### 2.4 Banners (`kiosk_implementation/ARCHIVE_BANNER.md` & `borne (Remix)/ARCHIVE_BANNER.md`) — PASS

- En-tête `# ARCHIVE — <dossier>/` reconnu par `lint-fk-archive-banner.sh`.
- Statut explicite : « LEGACY ARCHIVE — non runtime depuis 2026-04-25 ».
- Justification + référence au runtime web (Laravel + Vue) sous `resources/js/`.
- Interdictions : gate humain explicite, no-import, no-bundle.
- Lints associés cités + référence vers `LEGACY_QUARANTINE_2026-04-25.md`.

### 2.5 Quarantine doc (`docs/orchestration/LEGACY_QUARANTINE_2026-04-25.md`) — PASS

Contient les 5 sections attendues : Statut (owner + date + mission), Périmètre legacy (4 cibles), Garde-fous (4 scripts + workflow CI), Processus d'exception (gates `GATE_LEGACY_*`), Invariants associés (4 lignes claires). Cite explicitement la délégation suppression routes legacy à M-11 — bonne traçabilité.

### 2.6 Scope discipline (allowlist stricte) — PASS

- 8 fichiers de l'allowlist : tous présents (cf. §2.1), tous untracked (`??`) en git status — créations propres.
- Hors allowlist (`app/`, `resources/`, `routes/`, `database/`, `bootstrap/`, `config/`, `composer.json`) : vérifié `git status -s` → seul `app/Services/FrontendOrderService.php` est modifié, mais il **préexiste** la mission M-12 (présent dans le snapshot git initial de la conversation, sans rapport avec ce cycle ; aucune des 5 `implementation_steps` GPT n'y touche). **Pas une violation M-12.**
- `routes/api.php` : intact (le brief interdit explicitement toute modif — lint routes est monitor-only ; GPT respecte). ✓
- `eslint.config.*` / `phpcs.xml` : non touchés (hors allowlist input.json). ✓ — note GPT dans `risks` correctement signalée : parent plan évoque ces fichiers mais input.json est plus strict, GPT a suivi la consigne stricte (bonne décision).

### 2.7 Invariants FoodKing — PASS

- **Pricing SSOT** : aucun fichier touché dans la chaîne pricing (services/controllers Laravel non modifiés).
- **OrderStatus enum** : non touché.
- **branch_id isolation** : aucune query/mutation modifiée.
- **Dispatch after DB commit** : non concerné (pas de couche event/job touchée).
- **OrderService / FrontendOrderService symmetry** : non concerné par cette mission (la modif `FrontendOrderService.php` visible en git status est antérieure et non imputable à M-12).
- **Frozen zones** : aucune édition de zone gelée.

### 2.8 Self-audit GPT (`output_codex.json` §risks/notes) — VALIDÉ

- Risque 1 (parent plan vs input plus strict) : assumé correctement, allowlist input prime. ✓
- Risque 2 (routes legacy par design, monitor-only ce cycle) : conforme au brief, suppression M-11. ✓
- Note 1 (no product code modified) : confirmé par audit indépendant. ✓
- Note 2 (`public/build/` absent → skip propre) : conforme au design des scripts. ✓
- Note 3 (validations exécutées) : reproduit avec succès en audit (smoke-run §2.2).

---

## 3. AUDIT_VERDICT

**AUDIT_VERDICT : PASS**

## 4. REASON

Livraison conforme à l'allowlist stricte (8/8 fichiers présents, contenu non vide), scripts lint exécutables et fonctionnels (smoke-run propre depuis la racine, exit codes attendus, monitor routes détecte exactement les 3 endpoints legacy ciblés), workflow CI YAML valide qui déclenche les 4 scripts sur PR + push main, banners et quarantine doc complets et cohérents avec le processus de gate humain. Aucun code produit modifié (`app/`, `resources/`, `routes/`, `database/`), aucun invariant FoodKing à risque (fiscal/pricing/OrderStatus/branch_id intacts). Les choix d'écart par rapport au parent plan (omission `eslint.config.*` / `phpcs.xml`) sont justifiés et tracés dans `risks` — l'allowlist input.json est plus stricte et fait foi. Le faux REWORK initial est imputable au wrapper d'extraction JSON, non à la livraison Codex.

## 5. REWORK_ITEMS

Aucun.

## 6. CLOSE_RECOMMENDATION

GO masterplay close M-12. Prochaine étape conforme au DAG Wave A : passage à `GPT_FINAL_AUDIT` (`npm run codex:final-audit -- CV1-M12-LEGACY-GUARDS-CI`) puis ingest mémoire `memory/episodes/caisse_v1_legacy_guards_2026-04-25.jsonl` (déjà listé en `memory_episode_to_write_on_close` dans input.json). Suppression effective des routes frontend legacy reste planifiée pour M-11 — le monitor M-12 fournira l'evidence avant/après.
