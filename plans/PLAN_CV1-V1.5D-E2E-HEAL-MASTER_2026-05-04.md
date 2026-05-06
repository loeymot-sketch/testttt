# PLAN MASTER — CV1-V1.5D-E2E-HEAL-MASTER (orchestration des 6 sous-plans)

**Date** : 2026-05-04
**Auteur** : Claude (PLAN orchestrator)
**TASK_ID** : `CV1-V1.5D-E2E-HEAL-MASTER`
**Statut** : **PLAN ONLY** — aucune exécution. Ce master récapitule, séquence et gouverne les 6 sous-plans nés du run massif Playwright `2026-05-04`.

---

## 1. Source d’entrée

- Rapport SSOT : `reports/e2e-massive/RAPPORT_CONSOLIDE_E2E_COMPLET_2026-05-04.md`
- Log brut : `reports/e2e-massive/FINAL_2026-05-04/logs/playwright_full.log`
- Run massif : 76 tests / 63 PASS / 11 FAIL / 1 flaky / 1 skip (~17.9 min)
- 2 spécifications **déjà corrigées** dans la session précédente (sidebar + rupture ingrédient ; preuve : 2/2 PASS en reprise ciblée).
- 11 → **9 échecs** restants à traiter via les sous-plans ci-dessous + 1 flaky.

## 2. Inventaire des sous-plans

| Code | Sous-plan | Périmètre | Tier | Reasoning |
|---|---|---|---|---|
| **A** | `PLAN_CV1-V1.5D-E2E-HEAL-A-A11Y-COLOR-CONTRAST_2026-05-04.md` | WCAG color-contrast (Ingredients header + tabs + Studio nœud unique) — **4 specs** | complex | xhigh |
| **B** | `PLAN_CV1-V1.5D-E2E-HEAL-B-POS-V5-CART-ARIA_2026-05-04.md` | `aria-required-children` sur `.pos-v5-cart__body[role="list"]` — **1 spec** | complex | xhigh |
| **C** | `PLAN_CV1-V1.5D-E2E-HEAL-C-POS-TACOS-CART-TOTAL_2026-05-04.md` | Locator total panier POS V5 (DOM drift) — **1 spec** | complex | xhigh |
| **D** | `PLAN_CV1-V1.5D-E2E-HEAL-D-CATALOG-STUDIO-CREATE-FLOW_2026-05-04.md` | Studio create-product → drawer composer — **1 spec** | complex | xhigh |
| **E** | `PLAN_CV1-V1.5D-E2E-HEAL-E-CENTRAL-MANAGEMENT-SYNC_2026-05-04.md` | Central admin → POS/Kiosk/KDS/stock projection — **2 specs** (couplées) | complex | xhigh |
| **F** | `PLAN_CV1-V1.5D-E2E-HEAL-F-INGREDIENT-A11Y-KEYBOARD-FLAKY_2026-05-04.md` | Toggle clavier `Space` + `window.prompt` flaky — **1 spec** | complex | xhigh |

> **Couverture totale** : 4 + 1 + 1 + 1 + 2 + 1 = **10** items (4 sont des sous-cas de la même spec ingrédients a11y → 9 specs distinctes traitées + 1 flaky).

## 3. Dépendances inter-plans (DAG)

```
A (color-contrast) ─┬─► E (central management) [si Studio nœud Studio = couplé]
                    │
B (POS aria) ───────┘ (indépendant des autres)
C (POS tacos) ──────► (indépendant)
D (Studio flow) ────► E (couplé : drawer composer testid)
F (a11y keyboard) ──► (indépendant — peut tourner en parallèle)
```

**Ordre d'exécution recommandé** (séquentiel, un cycle par plan) :

1. **A** — corrige 4 violations a11y (low blast radius UI).
2. **F** — flaky a11y (touche spec uniquement) — peut être parallélisé avec A.
3. **D** — Studio create-product (testid manquant probable).
4. **E** — Central management (dépend de D pour `admin-composer-root`).
5. **B** — POS V5 cart aria (indépendant ; faire avant ou après).
6. **C** — POS tacos cart total (peut dépendre de A si `text-primary` retiré).

## 4. Gouvernance & gates communs

| Type de gate | Déclencheur (n’importe quel sous-plan) |
|---|---|
| **Frozen zone** | Tout fichier touché listé dans `docs/gates/` ou marqué frozen → STOP + brief. |
| **Pricing SSOT** | Tentation de calculer un total côté JS → STOP + escalade. |
| **branch_id** | Endpoint admin sans filtre `branch_id` détecté → GATE Hard. |
| **Dispatch après commit** | Service dispatch hors `DB::afterCommit` → GATE Hard. |
| **Symmetry OrderService** | Modif d’un côté sans audit de l’autre → SYMMETRY_NOTE en plan. |
| **Schema migration** | Aucune attendue. Si requise → STOP. |
| **Auth/Permission** | Aucune attendue. Si requise → security review. |

## 5. Discipline cross-agent

Chaque sous-plan, à son tour :

1. `bash scripts/agent-activity-log.sh tail 50`
2. `bash scripts/agent-activity-log.sh start codex-extension <TASK_ID> execute "<files>" "<note>"` (ou `cursor-claude` selon canal).
3. EXECUTE selon plan.
4. VALIDATE (`reports/post_execute_latest.log`).
5. AUDIT terminal Claude (PRIMARY) + GPT_FINAL_AUDIT (Codex).
6. `bash scripts/agent-activity-log.sh done … done "<résumé>"`.

Pas de close sans **double PASS** (`AUDIT_VERDICT: PASS` + `GPT_FINAL_AUDIT_VERDICT: PASS`).

## 6. Contrats de réussite

- **Run massif final** : `E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test --config=playwright.config.js`
  → cible **76 PASS / 0 FAIL / 0 flaky** (le 1 skipped historique reste si `test.fixme` doctrine).
- PHPUnit : non-régression (1433 actuels au minimum).
- Vitest : non-régression (1162 actuels au minimum).
- Mémoire Graphiti : ajouter un épisode décision par cycle clos sous `memory/episodes/12_decisions_log.jsonl` puis `bash bin/graphiti-ingest.sh`.

## 7. Hors scope (référence explicite)

- 2 specs déjà corrigées (`v1-sidebar-cleanup` + `v1-ingredient-rupture-propagation`) ne sont **PAS** ré-implémentées ici. Re-runner le massif après PLAN-A pour confirmer leur PASS persistant.
- Aucun changement i18n (sauf nouveau libellé Vue dialog en PLAN-F Cas 2 — gate UX).
- Aucun changement schema, auth, pricing.

## 8. Ressources Graphiti à interroger AVANT chaque sous-plan EXECUTE

```
search_memory_facts("ingredients availability toggle V1 UI flow", group_ids=["foodking"])
search_memory_facts("POS V5 cart accessibility role list", group_ids=["foodking"])
search_memory_facts("catalog studio composer drawer testid", group_ids=["foodking"])
search_memory_facts("central management projection menu sync", group_ids=["foodking"])
search_memory_facts("primary color FF006B contrast accessibility", group_ids=["foodking"])
```

(Tomber sur Graphiti non chargé = log + repli `memory/episodes/*.jsonl` ciblé.)

## 9. Livrables maître

- Les 6 fichiers `plans/PLAN_CV1-V1.5D-E2E-HEAL-{A..F}_*.md` (présents).
- Ce master.
- Aucun code modifié.

---

**FIN PLAN MASTER. AUCUNE EXÉCUTION.**
