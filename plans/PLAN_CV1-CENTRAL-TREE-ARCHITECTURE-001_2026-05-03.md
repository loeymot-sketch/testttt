# PLAN — CV1-CENTRAL-TREE-ARCHITECTURE-001
## Architecture en arbre + cleanup chirurgical (MAX prudence + double audit)
## 2026-05-03

**Auteur :** Claude in-session orchestrator
**Demande user (2026-05-03 01:38) :** « centralisation comme une arbre qui donne synchronisation entre la borne, la caisse et toute la gestion de stock, ainsi que catégorie/produits/wizard. Liaison entre chaque chose. Ultra review + nettoyage du reste inutile/duplication. Double audit + double vérification AVANT toute décision (critique : ne rien casser). Playwright captures pour chaque étape. »

**Strategy:** ULTRA prudence. Aucune suppression sans **3 conditions remplies** : (1) preuve d'usage NULL via grep + audit dynamique, (2) sentinel verrouille le comportement BEFORE deletion, (3) tests verts AFTER deletion sans régression.

**Test strategy declared :** `playwright-mcp` (captures baseline + après-cleanup pour preuve visuelle).

---

## §0 — Engagement non-régression (CRITIQUE)

| Garantie | Mécanisme |
|---|---|
| Aucune suppression de code utilisé | Triple-check : grep usage + sentinel BEFORE + tests AFTER |
| Aucune modification frozen zones | `.cursor/rules/project-invariants.mdc` invariant #6 |
| Aucune migration DB | Schéma intact (Cycle séparé `CV1-WC-T-WC-SOURCE-FK-01` pour FK) |
| Aucune perte de fonctionnalité visible | Playwright captures baseline vs after = aucune différence visuelle |
| Tests verts AVANT et APRÈS chaque commit | PHPUnit + Vitest globaux à chaque step |

**Si DOUTE → KEEP.** Mieux preserver un code mort qu'effacer du code vivant.

---

## §1 — Décomposition de la demande user

User demande **2 livrables principaux** :

### Livrable A — Diagramme arbre central → POS / Kiosk / KDS / OSS / Stock
Architecture document avec :
- **Centralisation** : où vit la SSOT (Items/Categories/Variations/Extras/Addons/Attributes/StockLevels/ItemBranchAvailability/ItemWizardProfile/ItemWizardStep)
- **Liaisons** : comment chaque branche consomme la SSOT
  - Catégorie → contient Produits
  - Produit → wizard composer profile (steps personnalisables)
  - Stock central → propagation surface (POS+Kiosk+KDS+OSS)
  - Wizard kiosk multi-pages personnalisables (ex : pas de crudités → page absente)
- **Mermaid diagram** pour visualisation
- **Liens cliquables** dans le doc vers code source (file:line)

### Livrable B — Cleanup intelligent du système
- Identifier dead code (Vuex modules non registrés, routes orphelines, composants Vue non importés, controllers Laravel sans route, services sans appel)
- Identifier doublures (logique dupliquée entre POS et Kiosk si pas justifiée par UX différentiée)
- **MATRICE DÉCISIONS** : pour chaque candidat, double-audit + verdict (KEEP / REFACTOR / DELETE) avec preuve

---

## §2 — Phases d'exécution

### Phase Π — 4 audits parallèles (sub-agents `explore` very-thorough, read-only)

| Axe | Périmètre | Sortie |
|---|---|---|
| A.1 | Cartographie SSOT + liaisons surface (arbre central) | `reports/audit/CV1_TREE_AXE_A1_CENTRAL_MAP_2026-05-03.md` |
| A.2 | Inventaire candidats cleanup (dead code + doublures) **AVEC PREUVE D'USAGE** | `reports/audit/CV1_TREE_AXE_A2_CLEANUP_CANDIDATES_2026-05-03.md` |
| A.3 | Validation sync paths existants (audit Axe 1+2 du cycle CV1-V1-CLOSEOUT était PARTIEL — re-vérifier après les fixes wizard composable) | `reports/audit/CV1_TREE_AXE_A3_SYNC_PATHS_VALIDATION_2026-05-03.md` |
| A.4 | Playwright captures baseline (POS, Kiosk wizard, dashboard, OSS) | `reports/screenshots/baseline-2026-05-03/*.png` + manifeste `reports/audit/CV1_TREE_AXE_A4_PLAYWRIGHT_BASELINE_2026-05-03.md` |

### Phase B — Architecture document + matrice cleanup

| Document | Contenu |
|---|---|
| `docs/architecture/CV1_CENTRAL_TREE_ARCHITECTURE_2026-05-03.md` | Diagramme Mermaid arbre + tableaux liaisons + file:line refs |
| `reports/audit/CV1_TREE_CLEANUP_DECISIONS_MATRIX_2026-05-03.md` | Pour chaque candidat A.2 : verdict KEEP/REFACTOR/DELETE + preuves audit + plan de remplacement si DELETE |

### Phase C — Implementation cleanup chirurgical

Pour chaque candidat à supprimer :
1. **Triple-check** :
   - `rg -t vue -t js -t php "<symbol>"` → preuve usage NULL
   - Sentinel verrouille comportement attendu BEFORE
   - Tests verts AVANT (baseline)
2. **Suppression**
3. **Tests verts APRÈS** (PHPUnit + Vitest globaux)
4. **Playwright capture** comparée baseline → 0 diff visuel
5. **Commit unique** par groupe logique

### Phase D — Playwright captures APRÈS

Comparaison baseline vs après-cleanup :
- POS dashboard
- POS pose commande (catégorie → item → wizard → encaissement)
- Kiosk parcours complet (idle → catégorie → wizard 4 étapes → paiement → confirmation)
- Admin dashboard refonté

### Phase E — Final close-out

- Verdict GO / REWORK
- Compte total cleanup (lignes supprimées, fichiers nettoyés)
- 0 régression confirmée

---

## §3 — Tier-routing

| Phase | Tier | Délégation |
|---|---|---|
| Π audits A.1-A.3 | explore very-thorough × 3 (parallèle) | sub-agents `explore` |
| Π audit A.4 Playwright | browser-use ou Playwright MCP direct | sub-agent `browser-use` ou MCP tool |
| B synthèse + matrice | orchestration | Claude in-session |
| C cleanup | routine S/M (par groupe) | `generalPurpose (routine sub)` ou Claude in-session si surgical |
| D Playwright captures après | browser-use / MCP | sub-agent `browser-use` ou MCP tool |
| E close-out | orchestration | Claude in-session |

---

## §4 — Doctrine appliquée

- `.cursor/rules/global.mdc` § Token Discipline + Tier-Routing
- `.cursor/rules/project-invariants.mdc` (6 invariants)
- `.cursor/rules/scope.mdc` (allowlist par tâche)
- `.cursor/rules/playwright.mdc` (E2E déclaré ici → MCP load OK)
- `CLAUDE.md` §10 Anti-Drift Rules (si contradiction → block ou escalade)

---

**Statut :** PLAN écrit. Phase Π (4 audits parallèles) à lancer immédiatement.
