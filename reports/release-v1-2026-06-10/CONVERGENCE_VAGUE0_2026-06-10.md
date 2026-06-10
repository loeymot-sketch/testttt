# CONVERGENCE — Ultraplan Vague 0 (release/v1) 2026-06-10

> Owner /goal « lance le plan max smart + adversaires + test screenshots en boucle jusqu'à tout validé deeply ». Vague 0 = quick-wins non-frozen, 0 gate. Frozen §7 = 0 ligne (vérifié). NF525 = 0 prix sur étape (prouvé projection + diff + borne live).

## Lots livrés + intégrés dans release/v1 (tous testés)
| Lot | Quoi | Preuve |
|---|---|---|
| **T-COMPO-3** | Projection résout `item_attribute` par FK `source_item_attribute_id` (anti-homonyme + anti-rename) | test FK-first homonyme vert, projection 9/9 |
| **T-COMPO-4** | Idempotency sur route publish composer + config required_routes | IdempotencyRequiredRoutesCoverageTest vert |
| **T-COMPO-6** | Sentinel render-contract POS (verrouille GAP#1 : taille+generic_choices non-rendus) | posWizardComposerAware 11/11 |
| **CMS-UX-1** | Dirty-guard « Modifications non sauvegardées » + confirm sortie | live :8768 badge OK, **confirm count 1** (double-confirm healé) |
| **CMS-UX-2** | Diff de publication valeur-par-valeur, price-free (NF525) | 64 specs, diff sans € live, source_type humanisé |
| **BU-01** | Densité grille steps borne (canvas portrait) + step générique centré | Vitest kiosk 107+82, CSS mirroring Pain prouvé |
| **BU-02** | Affordance sélection (bordure+ring+badge) | live borne pilote, contraste AA |

## Boucle adversariale (mandat test-e2e)
- Pilotes CMS + BU : chacun 2 cycles + revue adversariale sur surfaces dédiées.
- **Adversaire intégré V0 : EXHAUSTED — 0 P0/P1**, frozen 0, NF525 0-prix-sur-étape, i18n parité 5/5. T-COMPO-3 (pas de fuite cross-item), T-COMPO-4 (opt-in préservé), T-COMPO-6 (vrai verrou) réfutés comme problèmes.
- **4 P2/P3 trouvés → tous healés + re-vérifiés** :
  - BU-01 centrage omis sur step générique (le plus utilisé) → CSS ajouté.
  - CMS-UX-1 double-confirm → markClean avant push (live : 1 prompt).
  - CMS-UX-2 vert #1ab759/blanc 2.63:1 → #15803d AA (live : rgb(21,128,61)).
  - P3 diff source_type token brut → humanisé ; nit clé test dupliquée → supprimée.
- Hors-scope divulgué : 500 `group-by-attribute` (contrôleur NON touché par V0, dette pré-existante).

## Technique (arbre intégré)
PHPUnit affecté 305 passed · **Vitest 2123/0** · frozen 0 · build prod OK.

## RESTE
- **Wizard-best frozen (T-COMPO-1/2)** : ACCEPTÉ+validé mais **gate owner explicite** pour merge dans la release (hook+classifier l'exigent). Sur `goal/cms-gestion-2026-06-10-spine`, flag FALSE.
- Ultraplan Vagues 1-3 (robustesse, profondeur, structurel) : non lancées (V0 = quick-wins).
