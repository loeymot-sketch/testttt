# MASTER PLAN — Pivot V1 FoodKing — CV1-V1-PIVOT-*

| Champ | Valeur |
|---|---|
| MASTER_TASK_ID | `CV1-V1-PIVOT-MASTER` |
| Date | 2026-05-04 |
| Source | `reports/audit/ULTRA_REVIEW_PIVOT_V1_2026-05-04.md` (Claude Opus 4.7 effort max, terminal) |
| Owner | Cursor Claude (orchestrator + executor via sub-agents) |
| Approbation humaine | User message 2026-05-04 11:51 UTC+2 : « Là c'est toi le boss c'est toi qui décides, on va si le retour c'est à toi maintenant de tout implémenter et de tourner en boucle avec tes Sub agent selon difficulté ». **Délégation explicite d'autorité** sur les 3 questions humaines + 2 schema migrations. |
| **STATUT** | **CLOSED PASS** (2026-05-04 ~13:05 UTC+2) |
| **AUDIT_VERDICT** | `PASS` via Claude terminal (Opus 4.7 high) après 1 round REWORK healing-only |
| **Archive cycle** | `docs/orchestration/cycles/CYCLE_CV1-V1-PIVOT-MASTER_2026-05-04.md` |
| **Episode mémoire** | `memory/episodes/12_decisions_log.jsonl` (append 2026-05-04) |

---

## 1. 3 décisions humaines tranchées (Cycle 0)

### Q1 — Multi-filiale : V2 (mono-filiale en pratique V1)

**Décision** : conformément à `[H1]` du livrable Claude.
- 1 seule filiale active (`branch_id=1`).
- Le code conserve l'isolation `branch_id` partout (queries filtrées, events broadcast `private-branch.{id}`).
- **Pas** de table `ingredient_branch_availability` en V1 — le `is_available` global sur `item_attributes` / `item_extras` suffit pour la rupture mono-filiale.
- V2 ajoutera la table per-branch sans rien casser.

**Justification** : éviter d'augmenter le scope Cycles 1-4 de 30% pour une fonctionnalité multi-filiale qui ne sera pas utilisée avant V2. Pragmatisme V1.

### Q2 — Vitesse : plan complet 8 cycles, parallélisé

**Décision** : on exécute les **8 cycles complets**, mais on **parallélise** dès que les dépendances le permettent (Cycle 2 = 2 sub-agents en // ; Cycles 4-5-6 partiellement en // sur fichiers disjoints).

**Justification** : le concept Ingrédient est *au cœur* de la vision V1 utilisateur ; le sortir = saboter V1. Compression via parallélisme = gain ~40% sur le chemin critique.

### Q3 — Demo V2 : invisible (URL directe seulement)

**Décision** : pas d'entrée dans le menu sidebar. Accès uniquement via URL `/admin/demo/wizard-advanced/:itemId` derrière flag env `FEATURE_WIZARD_PER_ITEM_DEMO=false` (default).

**Justification** : zéro confusion restaurateur, zéro surface d'erreur UX. Power-user / dev qui en a besoin connaît l'URL. Réversible : on peut exposer dans le menu V1.5 si besoin.

---

## 2. Architecture retenue (rappel)

- **Voie A** (FK `item_wizard_profiles.item_category_id` + `item_id` nullable + check XOR).
- **Option I.2** (vue agrégée `IngredientService` sur `item_attributes` ∪ `item_extras` ∪ `item_addons.addonItem`, zéro destruction).
- Code Composer per-item **préservé** (utilisé par Demo V2 + fallback runtime legacy).

---

## 3. Séquence des 8 cycles

| # | TASK_ID | Tier | Effort | Sub-agents | Dépendance |
|---|---|---|---|---|---|
| 0 | `CV1-V1-PIVOT-PRE-DECISIONS-001` | meta | S | (orchestrateur) | — |
| 1 | `CV1-V1-PIVOT-FOUNDATIONS-001` | complex | M | 1 | C0 |
| 2 | `CV1-V1-PIVOT-BACKEND-CATEGORY-WIZARD-001` | complex | L | 2 // | C1 |
| 3 | `CV1-V1-PIVOT-RUPTURE-PROPAGATION-001` | complex | M | 1 | C2 |
| 4 | `CV1-V1-PIVOT-INGREDIENTS-UI-001` | complex | L | 1 | C2 + C3 |
| 5 | `CV1-V1-PIVOT-CATALOG-STUDIO-CATEGORY-WIZARD-001` | complex | M | 1 | C2 |
| 6 | `CV1-V1-PIVOT-SIDEBAR-DEMO-V2-001` | routine | S | 1 | C5 |
| 7 | `CV1-V1-PIVOT-E2E-VERIFICATION-001` | complex | M | 1 | C1-C6 |
| 8 | `CV1-V1-PIVOT-CLOSEOUT-001` | meta | S | (orchestrateur) | C7 |

**Audit après chaque cycle** : Vitest + PHPUnit + (Playwright si C7+). Sur REWORK : sous-cycle correctif piloté par sub-agent dédié, max 5 itérations avant escalade humaine.

---

## 4. Definition of Done globale (V1 livrable)

- [ ] Sidebar V1 : 4 piliers Gestion + Opérations.
- [ ] Création produit hérite automatiquement du wizard catégorie (0 clic personnalisation).
- [ ] Page Ingrédients : tabs par type, toggle rupture, drawer usage.
- [ ] Toggle rupture → propagation runtime POS/Kiosk en <5s.
- [ ] Page Stock : produits + ingrédients en rupture unifiés.
- [ ] Demo V2 invisible (flag OFF).
- [ ] Vitest + PHPUnit + Playwright **tous verts** (zéro régression).
- [ ] Invariants 6/6 respectés (audit Cycle 8).
- [ ] Migrations rollback testées.
- [ ] Graphiti facts ajoutés (5 facts).

---

## 5. Status (mis à jour à chaque cycle)

| Cycle | Status | Verdict | Date |
|---|---|---|---|
| 0 | IN_PROGRESS | — | 2026-05-04 |
| 1 | PENDING | — | — |
| 2 | PENDING | — | — |
| 3 | PENDING | — | — |
| 4 | PENDING | — | — |
| 5 | PENDING | — | — |
| 6 | PENDING | — | — |
| 7 | PENDING | — | — |
| 8 | PENDING | — | — |
