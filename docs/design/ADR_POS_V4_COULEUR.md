# ADR POS v4 — Choix d'identité couleur (DRAFT — signature humaine requise)

> **Statut** : DRAFT — proposé par Cursor session (cursor-claude) le 2026-04-26.
> **Action requise** : Tech Lead approuve, signe, déclare le statut FINAL.
> **Réf cycle** : POS_V4_W0_REMEDIATION ; cross-check `AUDIT_FINAL_W0_CROSSCHECK_2026-04-26.md` P0-CC #2.

---

## 1. Contexte

L'export design POS v4 (Anthropic) introduit une couleur primaire `#FF006B` (magenta/rose vif).
La codebase existante FoodKing utilise des accents `#0084FF` (bleu) côté admin et plusieurs accents secondaires.

Le risque sans ADR :
- Contamination CSS si namespace `.fk-pos-v4` non strict.
- Incohérence visuelle entre POS v4 et le reste de l'admin (KDS, Settings, Reports).
- Difficulté future à thématiser par branche (white-labeling SaaS).

---

## 2. Options envisagées

### Option A — Adopter `#FF006B` comme nouvelle couleur primaire POS uniquement

| + | − |
|---|---|
| Identité visuelle distinctive POS v4 | Contraste avec le reste de l'admin |
| Aligné sur design Anthropic original | Risque ergonomique (rose vif = associé à warning/alert dans certains contextes) |
| Strictement scopé `.fk-pos-v4` | Migration future vers WL difficile |

### Option B — Conserver `#0084FF` admin et adapter le design POS v4

| + | − |
|---|---|
| Cohérence visuelle FoodKing globale | Travail d'adaptation design (couleurs accents, hover, focus) |
| Migration WL plus simple (1 variable CSS) | S'écarte du design Anthropic original |
| Continuité visuelle pour utilisateurs actuels | Perte de l'identité "v4" forte |

### Option C — Theming par variable CSS `--fk-pos-primary` avec valeur par défaut au choix

| + | − |
|---|---|
| Maximum flexibilité (tenant override possible) | Effort initial plus élevé |
| Préparation white-label SaaS | Test multi-thème requis avant prod |
| Compatible A et B (juste la valeur change) | Doc design tokens à maintenir |

---

## 3. Recommandation Cursor (à valider par Tech Lead)

**Option C** — variable CSS avec **valeur par défaut Option B** (`#0084FF`) pour ne pas perturber l'existant, et **theming optionnel via tenant** pour `#FF006B` ou autre.

Justification :
- Préserve la cohérence FoodKing par défaut.
- Ouvre la voie au white-labeling sans cycle séparé.
- Coût marginal faible : 1 variable CSS dans `pos-v4.css` + 1 ligne dans le tenant config.

Implémentation proposée (à inscrire dans `resources/css/pos-v4.css`) :
```css
.fk-pos-v4 {
    --fk-pos-primary: #0084FF;        /* défaut FoodKing admin */
    --fk-pos-primary-hover: #0066CC;
    --fk-pos-primary-active: #004D99;
    /* tenant override possible: <body class="fk-pos-v4" style="--fk-pos-primary: #FF006B"> */
}
```

---

## 4. Impact sur invariants FoodKing

| Invariant | Impact | Note |
|---|---|---|
| `pricing_ssot` | Aucun | Couleur, pas logique métier |
| `OrderStatus` enum | Aucun | |
| `branch_id` isolation | Aucun (mais theming par branche → vecteur d'expression branding) | |
| `commit_before_dispatch` | Aucun | |
| `OrderService/FrontendOrderService symmetry` | Aucun | |
| Frozen zones | Aucun (pos-v4.css est nouveau) | |

---

## 5. Décision

> **À remplir par Tech Lead.**

- [ ] Option A — adopter `#FF006B`
- [ ] Option B — conserver `#0084FF`
- [x] Option C — variable CSS, défaut `#0084FF` (recommandation Cursor)
- [ ] Autre : _______________

Tech Lead : ____________________
Date : ____________________

---

## 6. Conséquences

Si Option C retenue :
- `resources/css/pos-v4.css` doit déclarer `--fk-pos-primary` et l'utiliser dans toutes les règles `.fk-pos-v4 *`.
- Le code dur `#FF006B` du design export ne doit JAMAIS apparaître dans les SFC `.vue` mergés.
- Lint guard à ajouter : `pos:lint:hardcoded-color` (CI scan pour codes hex dans POS SFCs).
- Documenter dans `BINDING_MAP_POS_V4.md` que les couleurs viennent du namespace.

---

## 7. Références

- HYPERREVIEW §3 (incohérence couleur primaire)
- AUDIT_FINAL_W0_CROSSCHECK_2026-04-26.md §2.A.4
- resources/css/pos-v4.css (stub W0)
