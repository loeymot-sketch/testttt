# MASTER PLAN — V1.5b Drill-down Ingrédients — CV1-V1.5B-DRILLDOWN-INGREDIENTS-*

| Champ | Valeur |
|---|---|
| MASTER_TASK_ID | `CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER` |
| Date | 2026-05-04 |
| Source | Dette V1.5 documentée dans `docs/orchestration/cycles/CYCLE_CV1-V1.5-DEBT-CLEANUP-MASTER_2026-05-04.md` § Backlog V1.5b. Marqueur explicite ligne 52 de `IngredientUsageDrawer.vue` : « Drill-down produits/catégories impactés différé V1.5. » |
| PARENT_CYCLE | `CV1-V1.5-DEBT-CLEANUP-MASTER` CLOSED PASS (2026-05-04 ~16:05) |
| Owner | Cursor Claude (orchestrator + sub-agents) |
| Approbation humaine | User message 2026-05-04 17:08 UTC+2 : « you are the orchestrator, And it's you who decides. prends à ma place les bonnes décisions et continue » → délégation décisions techniques bornées (hors gate humain prod-cutover où doctrine reste appliquée) |

---

## 1. Contexte

Le drawer Ingrédients (`IngredientUsageDrawer.vue`) affiche actuellement seulement le `usedByCount` (un nombre). Le restaurateur ne peut pas voir QUELS produits/catégories utilisent un ingrédient — frustration UX quand il faut comprendre l'impact d'une rupture (ex : "si je toggle Bœuf en rupture, quels produits seront affectés ?").

**Valeur produit** : haute. C'est une feature utilisateur visible attendue (cf. message pivot user 2026-05-04 « gestion des ingrédients »).

**Scope strict V1.5b** : afficher les noms catégorie/produit + IDs avec liens admin. PAS de filtrage avancé, PAS de bulk operations, PAS de stats. Juste savoir QUI utilise QUOI.

---

## 2. 2 cycles + audit

### Cycle E1 — Backend endpoint usage drill-down

- **TASK_ID** : `CV1-V1.5B-DRILLDOWN-BACKEND-001`
- **EXECUTION_TIER** : `complex` (nouveau endpoint, jointures multi-tables, signature service)
- **EXECUTE_DELEGATION** : `foodking-complex-implementer`
- **SUBSYSTEMS_TOUCHED** :
  - `app/Services/Ingredients/IngredientService.php` (write — nouvelle méthode `usageDetailsForGlobalId`)
  - `app/Http/Controllers/Admin/IngredientController.php` (write — nouvelle méthode `usage`)
  - `app/Http/Resources/IngredientUsageResource.php` (nouveau)
  - `routes/api.php` (write — nouvelle route `GET /admin/ingredients/{globalId}/usage`)
  - `tests/Feature/Ingredients/` (write — nouveau test contrôleur + service)
- **SUBSYSTEMS_OFF_LIMITS** : tout frontend (E2 le fait), tout pricing/order/stock logic
- **GATE_CONDITIONS** : None anticipated
- **INVARIANTS_AT_RISK** :
  - I3 branch_id : préservé (V1 catalogue global mono-filiale)
  - I1 pricing : non touché (read-only data)

#### Contrat API (à respecter par E2 frontend)

`GET /api/admin/ingredients/{globalId}/usage`

Response 200 :
```json
{
    "data": {
        "global_id": "attribute:42",
        "type": "attribute",
        "id": 42,
        "name": "Bœuf",
        "is_available": true,
        "used_by": [
            {
                "owner_type": "category",
                "owner_id": 5,
                "owner_name": "Burgers",
                "step_key": "viande_choice",
                "step_label": "Choix viande",
                "wizard_profile_id": 8,
                "admin_url": "/admin/categories/5/composer"
            },
            {
                "owner_type": "item",
                "owner_id": 17,
                "owner_name": "Burger Maison Legacy",
                "step_key": "viande",
                "step_label": "Viande",
                "wizard_profile_id": 12,
                "admin_url": "/admin/items/17/composer"
            }
        ],
        "used_by_count": 2
    }
}
```

Response 404 si globalId invalide ou ingrédient inexistant.

### Cycle E2 — Frontend drawer drill-down

- **TASK_ID** : `CV1-V1.5B-DRILLDOWN-FRONTEND-001`
- **EXECUTION_TIER** : `routine` (UI Vue scoped, contrat API stable défini)
- **EXECUTE_DELEGATION** : `foodking-routine-implementer`
- **SUBSYSTEMS_TOUCHED** :
  - `resources/js/services/ingredientService.js` (write — méthode `getIngredientUsage`)
  - `resources/js/components/admin/ingredients/IngredientUsageDrawer.vue` (write — ajout liste détaillée)
  - `resources/js/languages/{fr,en,de,bn,ar}.json` (write — 6 nouvelles clés i18n × 5 langues)
  - `tests/js/ingredientUsageDrawer.spec.js` (nouveau ou étendu — tests Vitest)
- **SUBSYSTEMS_OFF_LIMITS** : tout backend (E1 le fait), Catalog Studio, autres composants Vue
- **GATE_CONDITIONS** : None
- **INVARIANTS_AT_RISK** :
  - i18n parity (sentinelle élargie H2 V1-FINISH scanne `admin/ingredients/`)
  - A11y WCAG 2.1 AA (drawer existant a déjà focus trap + aria-live)

### Cycle E3 — Audit consolidé + CLOSE

- **TASK_ID** : `CV1-V1.5B-DRILLDOWN-AUDIT-CLOSE-001`
- **AUDIT_CHANNEL** : tentative `terminal-claude` après reset 18h10 ; sinon fallback `cursor-session` `foodking-planner-orchestrator`
- **GATE_CONDITIONS** : sur REWORK → boucle healing max 3 rounds, sinon HUMAN_GATE
- **INVARIANTS_AT_RISK** : audit sur les changements

---

## 3. Stratégie d'exécution

- **Parallélisme** : E1 + E2 lancés en // — fichiers disjoints (backend ≠ frontend), contrat API stable défini ci-dessus.
- **Audit final** : E3 = audit consolidé.
- **GPT final audit cumulé V1** : tentative `npm run codex:final-audit` sur les 3 masters V1 (PIVOT, FINISH, V1.5) après reset terminal Claude 18h10 — exécution séparée, hors scope de ce master V1.5b.

---

## 4. Definition of Done globale

- ✅ E1 livré avec endpoint + 2 nouveaux tests PHPUnit.
- ✅ E2 livré avec drawer enrichi + 6 clés i18n × 5 langues + tests Vitest.
- ✅ Vitest baseline ≥ 1157 + nouveaux tests E2 (probable 1160+).
- ✅ PHPUnit baseline ≥ 1421 + nouveaux tests E1 (probable 1424+).
- ✅ Build `npm run dev` PASS.
- ✅ Audit E3 `AUDIT_VERDICT: PASS`.
- ✅ Episode mémoire append.
- ✅ Archive cycle.

---

## 5. Statut

| Cycle | Statut |
|---|---|
| E1 Backend usage endpoint | EXECUTE in progress |
| E2 Frontend drawer drill-down | EXECUTE in progress |
| E3 Audit close | PENDING |
