# REFUTER n°1 — D-B1-01 (Ingrédients: liste vs drawer contradiction extras sans group_label)

Date: 2026-06-12 — harnais :8767 / foodking_e2e — agent adversarial indépendant.

## Verdict: NON RÉFUTÉ (finding CONFIRMÉ, reproduit intégralement)

### 1. file:line vérifiés (Read complet du service)
- `app/Services/Ingredients/IngredientService.php:98` — liste extras: `'used_by_count' => $group->count()` (count de rows ItemExtra groupées par name||group_label). CONFIRMÉ.
- `app/Services/Ingredients/IngredientService.php:209-212` — `usedByRowsForExtra()`: `if (! $extra || ! $extra->group_label) { return []; }` → drawer vide pour tout extra sans group_label. CONFIRMÉ (le finding citait 210-214, off-by-2, non matériel).
- Même asymétrie dans `usageCountForExtra()` (lignes 325-336, return 0 si !group_label) — utilisée nulle part par la liste, ce qui scelle la divergence.

### 2. Repro API (exécutée moi-même, token frais 2794|…)
- `GET /api/admin/ingredients?type=extra` → 26 extras, dont **6 avec group_label=null, TOUS used_by_count=8** :
  extra:1 Jambon de dinde, extra:2 Boursin, extra:3 Fromage a raclette, extra:4 Œuf, extra:5 Fromage, extra:6 Galette pommes de terre.
- `GET /api/admin/ingredients/extra:1/usage` → `{"used_by":[],"used_by_count":0}`. Contradiction 8 vs 0 REPRODUITE.

### 3. Repro UI (Playwright, /admin/ingredients/extra)
- Ligne « Jambon de dinde | Suppléments | Utilisé dans 8 produit(s) | Voir les détails » (innerText exact).
- Clic « Voir les détails » → drawer affiche « Non utilisé » + « Aucun produit ni catégorie n'utilise cet ingrédient. »
- Capture: `refuter1-D-B1-01-drawer.png` (drawer mensonger superposé à la liste). 0 erreur console, 0 HTTP>=400.
- Frontend rend bien les 2 champs divergents: `IngredientListComponent.vue:108-109` (used_by_count) vs `IngredientUsageDrawer.vue:159-160` + labels fr.json:1351-1352/1362.

### 4. Blast radius re-mesuré (tinker e2e)
- 212 item_extras total, **48** group_label NULL, **6** noms distincts = 6 lignes UI affectées (finding disait 49/230 → 7 lignes ; écart mineur d'état de DB, ordre de grandeur confirmé — 6/26 lignes Suppléments = ~23 % de l'onglet mensongères).

### 5. Dedup check
- `petits-systemes-2026-06-11/ABUSE_RESULTS.md` a testé le drawer avec « Viande 1 » (type attribute → chemin sain) et conclu « drawer fonctionne » — la divergence extra-sans-groupe n'a JAMAIS été rapportée. Pas un doublon des lots release/v1 A-H ni dashboard-deep 06-08.

### 6. Sévérité
- P2 JUSTE : information contradictoire dans une feature de gestion admin (le drawer existe précisément pour répondre « où est utilisé cet ingrédient » et répond faux à 100 % sur 6 lignes), reproductible déterministe, mais aucun impact fiscal/monétaire/NF525, aucune action destructive directe, V1 LOCAL mono-poste. Pas de sur-cote multi-tenant/cloud. Ni P1 (pas de perte de fonction critique), ni P3 (pas cosmétique : donnée fausse).

corrected_sev=P2 ; refuted=false.
