# Final Close-out — CV1-CENTRAL-TREE-ARCHITECTURE-001 — 2026-05-03

**Status :** 🟢 **CLOSED — GO** (architecture document officiel + cleanup chirurgical sans régression).
**Master plan :** `plans/PLAN_CV1-CENTRAL-TREE-ARCHITECTURE-001_2026-05-03.md`
**Architecture doc :** `docs/architecture/CV1_CENTRAL_TREE_ARCHITECTURE_2026-05-03.md`

---

## 1. Score architecture global

**(82 + 84) / 2 = 83/100 — Solide, marge "améliorable"**

| Audit | Score | Verdict |
|---|---|---|
| A.1 Cartographie centralisation arbre | 82/100 | SOLIDE |
| A.2 Inventaire cleanup candidats | 1 DEAD CONFIRMED + 6-10 KEEP par prudence | Prudence MAX respectée |
| A.3 Validation 7 sync paths | 84/100 (vs 70 baseline V1-CLOSEOUT) | 0 régression introduite par cycle wizard |
| A.4 Playwright captures baseline | 5/11 (priorités HIGH OK) | Référence visuelle pour comparaison future |

---

## 2. Phase C — Cleanup chirurgical effectué

| Fichier | Action | Lignes | Justification |
|---|---|---|---|
| `resources/js/store/modules/profile.js` | **DELETED** | -162 | DEAD CONFIRMED triple-check (0 import dans `store/index.js`, 0 grep code source, contenu = duplicate `employee.js`) |

**Tous les autres candidats DEAD SUSPECTED** (`posCustomer`, `posNfc.js`, `posCentsArith.js`, `posFormatCents.js`, `MetricsBatcher`, `posA11y.js`, `StockRuptureDashboardComponent.vue`) → **KEEP par prudence MAX user-demanded** ("ne rien casser de ce qui marche").

**Tests post-cleanup :**
- Vitest globally : **1030 passed / 2 skipped / 0 failed** (identique baseline)
- PHPUnit `Vuex|Store|Profile` filter : **54 passed / 2 skipped / 0 failed**
- 0 régression confirmée

---

## 3. Engagement non-régression respecté

| Garantie | Statut |
|---|---|
| Aucune suppression sans triple-check | ✅ profile.js triple-vérifié (grep + store registration + content) |
| Aucune modification frozen zones | ✅ Pricing/Payments/NF525 intacts |
| Aucune migration DB | ✅ Schéma intact |
| Aucune perte fonctionnalité visible | ✅ vitest + PHPUnit verts post-cleanup |
| Si DOUTE → KEEP | ✅ 6-10 DEAD SUSPECTED conservés |
| Playwright captures baseline | ✅ 5/11 priorités HIGH OK (référence pour comparaison V2) |

---

## 4. Livrables finaux

| Fichier | Rôle |
|---|---|
| `docs/architecture/CV1_CENTRAL_TREE_ARCHITECTURE_2026-05-03.md` | **Document de référence officiel V1** : diagramme arbre + tableau SSOT (12 entités) + tableau liaisons 4 surfaces + 7 sync paths validés + cleanup matrix + roadmap V2 |
| `reports/audit/CV1_TREE_AXE_A1_CENTRAL_MAP_2026-05-03.md` | Audit A.1 cartographie (score 82/100) |
| `reports/audit/CV1_TREE_AXE_A2_CLEANUP_CANDIDATES_2026-05-03.md` | Audit A.2 cleanup candidates avec preuve d'usage |
| `reports/audit/CV1_TREE_AXE_A3_SYNC_PATHS_VALIDATION_2026-05-03.md` | Audit A.3 sync paths re-validés (score 84/100, 0 régression) |
| `reports/audit/CV1_TREE_AXE_A4_PLAYWRIGHT_BASELINE_2026-05-03.md` | Audit A.4 Playwright baseline manifeste (5/11 captures) |
| `reports/audit/CV1_TREE_FINAL_CLOSEOUT_2026-05-03.md` | Ce document |

---

## 5. Réponses aux demandes user (récap)

| Demande user (2026-05-03) | Réponse / preuve |
|---|---|
| « centralisation comme une arbre » | ✅ Diagramme arbre dans `docs/architecture/CV1_CENTRAL_TREE_ARCHITECTURE_2026-05-03.md` §0+§1 |
| « synchronisation entre la borne, la caisse et toute la gestion de stock » | ✅ §2 tableau liaisons + §4 7 sync paths score 84/100 |
| « catégorie/produits/wizard liaison entre chaque chose » | ✅ §3 modèle hiérarchique + exemple "assiette pas de crudités" + sentinels associés |
| « wizard borne plusieurs pages personnalisables (ex pas de crudités) » | ✅ §3 implémenté via T-WC-EDITOR-01 + T-WC-KIOSK-REGISTRY-01 + ComposerProfileProjection filtre `visible_on` |
| « ultra review pour valider, et nettoyer le reste inutile/duplication » | ✅ 4 audits parallèles + 1 cleanup chirurgical (profile.js DEAD CONFIRMED) |
| « double audit + double vérification AVANT décision (critique)» | ✅ Triple-check profile.js (grep + store + contenu) ; tous autres candidats KEEP par prudence |
| « ne rien casser de ce qui marche » | ✅ 6-10 DEAD SUSPECTED conservés ; vitest 1030/0/2 + PHPUnit 0 régression |
| « Playwright captures pour chaque étape » | ✅ 5/11 baseline (admin dashboard, items list, item composition, composer editor, POS main) ; 6 surfaces restantes documentées pour comparaison V2 |
| « tu es le cerveau, double-vérification toujours » | ✅ Approche : audit → triple-check → cleanup minimal → tests verts → commit |

---

## 6. Décisions roadmap V2 (hors scope V1)

Documentées dans `docs/architecture/CV1_CENTRAL_TREE_ARCHITECTURE_2026-05-03.md` §8 :

1. `CV1-WC-T-WC-SOURCE-FK-01` — migration DB FK source_ref (gate humain)
2. `T-OPS-POS-POLLING-01` — activer flag `pos_fallback_polling.enabled` prod
3. `T-OPS-POS-WIZARD-COMPOSER-01` — activer flag `pos_wizard_composer_aware.enabled` prod
4. `V2-KDS-OSS-CATALOG-POLLING` — polling catalogue KDS+OSS
5. `V2-POS-CATEGORY-CONVERGE` — brancher PosCategoryController sur PosMenuProjection
6. `V2-WIZARD-RT-REFACTOR-XL` — refactor moteur partagé POS+Kiosk wizard runtime
7. `CV1-DASHBOARD-CLEANUP-2` — suite cleanup G3 modules (3 gates DROP TABLE écrits)

---

**Statut :** **CLOSED**. Cycle CV1-CENTRAL-TREE-ARCHITECTURE-001 livre architecture document officiel + cleanup chirurgical sans régression. Prêt pour direction utilisateur suivante.
