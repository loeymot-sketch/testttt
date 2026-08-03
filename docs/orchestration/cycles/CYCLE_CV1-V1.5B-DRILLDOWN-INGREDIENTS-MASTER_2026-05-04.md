# CYCLE CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER — 2026-05-04 (CLOSED PASS)

## Résumé exécutif

Backlog V1.5b : drill-down ingrédients UX (admin restaurateur voit QUI utilise QUOI). Dette explicitement marquée différée V1.5 dans `IngredientUsageDrawer.vue:52` avant ce cycle. 2 cycles parallèles (E1 backend + E2 frontend) + audit fallback. AUDIT_VERDICT PASS via `foodking-planner-orchestrator` (terminal Claude quota encore down, reset 18h10).

- **Période** : 2026-05-04 (~17:15 → ~17:35 UTC+2)
- **MASTER_TASK_ID** : `CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER`
- **PARENT_CYCLE** : `CV1-V1.5-DEBT-CLEANUP-MASTER` CLOSED PASS (2026-05-04 ~16:05)
- **Plan** : `plans/PLAN_CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER_2026-05-04.md`
- **AUDIT_CHANNEL** : `cursor-session` (fallback)
- **AUDIT_FALLBACK_REASON** : `claude-anthropic-quota-still-down-2026-05-04-17h23-reset-18h10`
- **AUDIT_SUBAGENT_FALLBACK** : `foodking-planner-orchestrator`
- **AUDIT_VERDICT** : `PASS`

## 2 cycles + audit

| # | TASK_ID | Tier | EXECUTE_DELEGATION | Verdict |
|---|---------|------|-------------------|---------|
| E1 | `CV1-V1.5B-DRILLDOWN-BACKEND-001` | complex | foodking-complex-implementer | PASS (PHPUnit 1428, +7 tests) |
| E2 | `CV1-V1.5B-DRILLDOWN-FRONTEND-001` | routine | foodking-routine-implementer | PASS (Vitest 1162, +5 tests net) |
| E3 | `CV1-V1.5B-DRILLDOWN-AUDIT-CLOSE-001` | audit fallback | foodking-planner-orchestrator | PASS (file:line) |

## Livrables

### E1 — Backend usage drill-down

**Endpoint** : `GET /api/admin/ingredients/{globalId}/usage` (permission `ingredients_manage`, pattern route `[a-z]+:\d+`).

**Réponse** :
```json
{
    "data": {
        "global_id": "attribute:42",
        "type": "attribute",
        "id": 42,
        "name": "Bœuf",
        "is_available": true,
        "used_by": [
            {"owner_type": "category", "owner_id": 5, "owner_name": "Burgers", "step_key": "viande_choice", "step_label": "Choix viande", "wizard_profile_id": 8, "admin_url": "/admin/categories/5/composer"},
            {"owner_type": "item", "owner_id": 17, "owner_name": "Burger Maison Legacy", "step_key": "viande", "step_label": "Viande", "wizard_profile_id": 12, "admin_url": "/admin/items/17/composer"}
        ],
        "used_by_count": 2
    }
}
```

Tri `category` puis `item` puis alphabétique `owner_name`. 404 JSON `{"message":"Ingredient not found"}` si invalide.

**Fichiers** :
- `app/Services/Ingredients/IngredientService.php` — nouvelle méthode `usageDetailsForGlobalId` + helpers `usedByRowsForAttribute|Extra|Addon` + `mapStepsToUsedBy` + `sortUsedBy`
- `app/Http/Controllers/Admin/IngredientController.php` — méthode `usage(string $globalId): JsonResponse`
- `app/Http/Resources/IngredientUsageResource.php` (nouveau)
- `routes/api.php` — route `/usage` enregistrée AVANT `/{globalId}` (sinon Laravel matche la mauvaise)
- `tests/Feature/Ingredients/IngredientUsageDrillDownTest.php` — 7 tests PASS

**Détail technique notable** : `ItemWizardStep::profile()` est `belongsTo(ItemWizardProfile::class, 'profile_id')` (pas `item_wizard_profile_id` malgré le naming convention Laravel). Documenté dans le RUN E1 pour futurs sub-agents.

### E2 — Frontend drawer drill-down

**Composant** : `resources/js/components/admin/ingredients/IngredientUsageDrawer.vue` enrichi :
- Marqueur "Drill-down différé V1.5" (ancienne ligne 52) RETIRÉ
- En-tête nom ingrédient + badge "En rupture" si `!isAvailable`
- Empty state si `usedBy.length === 0`
- `<ul role="list">` avec entrées `<li>` cliquables `<a :href="entry.admin_url">` (focus visible Tailwind)
- Loading + error states avec `aria-live="polite"`
- 7 `data-testid` ajoutés pour E2E (`-loading`, `-error`, `-name`, `-count`, `-empty`, `-list`, `-entry-{type}-{id}`)

**Service axios** : `resources/js/services/ingredientService.js` — nouvelle fonction `getIngredientUsage(globalId)` (préserve `showIngredient`).

**i18n 4 nouvelles clés × 5 langues** (FR référence) :
- `label.ingredient.status_unavailable` : "En rupture"
- `label.ingredient.usage_empty` : "Aucun produit ni catégorie n'utilise cet ingrédient."
- `label.ingredient.owner_category` : "Catégorie"
- `label.ingredient.owner_item` : "Article (legacy)"

Présentes dans `fr.json`, `en.json`, `de.json`, `bn.json`, `ar.json`.

**Tests** : `tests/js/ingredientUsageDrawer.spec.js` — 8 tests PASS (5 requis : 2 entrées + URL, vide, badge rupture, loading, error + 3 préservés : titre, Escape, backdrop).

## Métriques

| Métrique | Avant V1.5b | Après V1.5b | Delta |
|---|---|---|---|
| PHPUnit passed | 1421 | **1428** | **+7** (E1 IngredientUsageDrillDownTest) |
| PHPUnit skipped | 24 | 24 | 0 |
| Vitest passed | 1157 | **1162** | **+5** net (E2 IngredientUsageDrawer 8 tests dont 5 nouveaux) |
| Vitest skipped | 2 | 2 | 0 |
| Build `npm run dev` | PASS | PASS | — |

**Aucune régression**.

## Invariants FoodKing

| # | Invariant | Statut V1.5b |
|---|-----------|--------------|
| I1 | Backend Pricing SSOT | ✅ read-only métadonnées owners |
| I2 | OrderStatus enum | ✅ non touché |
| I3 | branch_id isolation | ✅ V1 catalogue global mono-filiale, IngredientService PHPDoc maintenu |
| I4 | Dispatch après commit | ✅ non touché |
| I5 | OrderService symmetry | N/A |
| I6 | Frozen zones | ✅ aucune édition |

## Risques résiduels

1. **Disque poste local 99% (211 Mi libre)** — point d'attention ops user. ENOSPC reproduit puis résolu via cleanup cache Vitest.
2. **Drill-down addon limité à 1 owner item** — acceptable structure ItemAddon = 1 addon/item.
3. **Tri alphabétique sur `owner_name` mais pas sur `step_label`** — acceptable V1.
4. **GPT final audit cumulé V1 (4 masters)** — non exécuté car cycles V1.5/V1.5b sans setup mission codex-extension. Possible après reset terminal Claude 18h10 pour double PASS officiel.

## Cohérence avec parcours V1

- Disjoints fichiers : aucune collision avec V1-PIVOT/V1-FINISH/V1.5
- A11y H3 V1-FINISH préservé (focus trap + esc + aria-modal drawer inchangés)
- i18n parity H2 V1-FINISH élargie 5 dossiers reste verte avec +4 clés × 5 langues
- Migrations Pivot V1 non touchées
- Sécurité Demo V2 H1 V1-FINISH non impactée

## Conclusion

V1.5b livre la **feature UX restaurateur la plus visible du backlog** : voir QUI utilise QUOI quand on consulte un ingrédient → décision éclairée avant de toggler une rupture (impact sur N catégories/produits visible immédiatement avec lien direct vers édition wizard). Cumulé avec D1 V1.5 (propagation runtime ingredient_rupture), le boucle de gestion des ruptures côté admin est désormais complet UX + runtime.

**Cumulé V1-PIVOT + V1-FINISH + V1.5 + V1.5b** : V1 prêt prod en termes de fonctionnalités attendues, modulo gate humain prod-cutover (Q1-Q4 + smoketest staging humain) — voir `docs/gates/GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER_2026-05-04.md` § Orchestrator pre-recommendations.

## Backlog V1.5c+ ouvert

- Lock optimiste toggle ingrédient (concurrence multi-admin)
- N+1 surveillance eager loading itemAttribute (D1 héritée)
- R-T3 cache invalidation branch-scoped granulaire
- R-T7 preview admin studio sans branchId
- Spec Playwright cross-surface live (E2E real-browser)
- GPT final audit cumulé V1 (4 masters) après reset terminal Claude
- FiscalArchive flaky CI (si logs futurs)
