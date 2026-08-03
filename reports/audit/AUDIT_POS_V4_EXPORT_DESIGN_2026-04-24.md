# Audit design — export POS v4 (hors repo) + API Anthropic Design

**Date** : 2026-04-24  
**Périmètre** : lecture `EXPORT_v4_README.md`, `design-canvas.jsx`, `main.jsx`, `preview.html`, extraits `previews/pos_v4.jsx` ; **aucune implémentation** dans FoodKing.  
**URL design** : `https://api.anthropic.com/v1/design/h/NjOFu-LU_pnzSm-uu8zfOg` — **non accessible sans authentification** depuis l’environnement d’audit automatisé (**HTTP 403**). L’audit visuel « source » doit se faire **dans le produit Anthropic** (même compte) ou via **Claude Code** avec `--add-dir` vers le dossier local (voir §5).

---

## 1. Synthèse exécutive

| Critère | Verdict |
|--------|--------|
| Cohérence README ↔ previews JSX v4 | **Bon** — tokens `PV4_T` alignés sur le brief (`#0084FF`, dark `#0B1220`, etc.) |
| Cohérence README ↔ `preview.html` | **Partiel** — page mélange **primary historique `#FF006B`** (Tailwind inline) et **tokens v4 bleu** (`.fk-btn--primary` → `#0084FF`) : **décision produit requise** (déjà signalée README §Points d’attention) |
| `PosComponent.vue` dans le ZIP | **Attention** — le fichier lu ressemble au **SFC actuel** (clair, `bg-primary`, best sellers) ; ce n’est **pas** le rendu dark 3 colonnes de `pos_v4.jsx` tant que la refonte template n’est pas appliquée. Le README dit explicitement que les SFC finaux = **traduction JSX → template** avec **script gelé**. |
| Outil `design-canvas.jsx` | **Solide** — canvas Figma-like, persistance `.design-canvas.state.json`, sections/artboards ; **pas** du code prod FoodKing (React). |
| Risque frozen / invariants FoodKing | **Moyen** — merge « template + style seulement » est cohérent avec le brief, mais **tests** listés (Floorplan, Payment, etc.) doivent être **relancés** ; **aucun** calcul prix côté UI (invariant pricing SSOT). |

---

## 2. Analyse `EXPORT_v4_README.md`

**Forces**

- Split clair des **5 SFC** et des **artboards** (dimensions, features).
- **Gel du `<script>`** : limite les régressions métier si respecté strictement.
- **A11y** explicite : 44px, `aria-*`, skip links, `focus-visible` 3px.
- **Tokens CSS** documentés + snippet `.fk-btn` / `.fk-focus`.
- **Processus de merge** en 5 étapes (branche, copie SFC, `pos-v4.css`, tests, QA `preview.html`).
- **Tweaks panel** (palettes, densité, radius, font-scale) — excellent pour **QA visuelle** avant merge.

**Faiblesses / angles morts**

1. **Couleur primaire** : conflit `#FF006B` (repo) vs `#0084FF` (brief) — **décision gate produit** avant merge global.
2. **Dark vs admin** : README recommande `fk-dark` + toggle — sans spec **où** vit le toggle (layout admin, user pref, branche).
3. **Modals** : choix **sous-composants** vs **inline** — impact **maintenabilité** et **tests** (non tranché).
4. **Tests cités** : `ParkedOrdersStoreTest`, `ReceiptServiceTest` — noms à **vérifier** dans le vrai repo (peuvent différer).
5. **Emojis** : previews utilisent des placeholders — **binding** `thumb` / `image_url` est obligatoire en prod (noté).

---

## 3. `design-canvas.jsx` (aperçu)

- **Rôle** : calque de travail (grille, post-its, drag artboards, focus plein écran, persistance JSON).
- **Dépendances** : React ; **omelette** `window.omelette?.writeFile` pour l’édition — en simple `file://` ou serveur statique, l’**écriture** peut être no-op (OK pour lecture seule).
- **Recommandation** : conserver ce fichier **hors** build Laravel ; c’est de la **collaboration design**, pas un livrable runtime.

---

## 4. `previews/pos_v4.jsx` (extrait sémantique)

- **Layout** : 3 colonnes, rail catégories, grille catalogue, panier **live** — aligné marketing brief.
- **Tokens** : `PV4_T` centralisé (bonne base pour `pos-v4.css`).
- **Données** : maquettes (prix, images emoji) — **ne pas** copier de logique tarifaire ; le backend reste SSOT.
- **Accessibilité** : à valider sur le JSX complet (contraste, focus, live regions) lors du portage Vue.

---

## 5. Comment obtenir l’**avis Claude terminal** (même compte, design + fichiers)

Le script standard `foodking-claude-orchestrate.sh audit` n’ajoute que la **racine du dépôt** FoodKing. Pour inclure le dossier design **hors arbre** :

```bash
claude -p "Tu es lead design + audit FoodKing. Lis en entier:
- /Users/1millnonstop/Downloads/POS (4)/EXPORT_v4_README.md
- /Users/1millnonstop/Downloads/POS (4)/preview.html
- /Users/1millnonstop/Downloads/POS (4)/main.jsx
- extraits: design-canvas.jsx, previews/pos_v4.jsx
Compare aux invariants AGENTS.md (pricing SSOT, pas de logique prix UI). URL design Anthropic: https://api.anthropic.com/v1/design/h/NjOFu-LU_pnzSm-uu8zfOg — je n'ai pas accès API ici; infère les risques de merge des 5 SFC.
Sortie: (1) verdict design vs brief (2) risques a11y/brand (3) checklist merge (4) P0 à corriger avant production. Aucun code." \
  --add-dir "/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt" \
  --add-dir "/Users/1millnonstop/Downloads/POS (4)" \
  </dev/null
```

Ouvre aussi le lien design dans **l’app Anthropic** (Claude / projets) si le rendu y est plus riche qu’un export ZIP.

---

## 6. Second avis **GPT-5.5 (Codex / proxy)** — orchestration

**But** : avis ciblé **merge design ↔ repo** (pas d’implémentation).

1. Créer / utiliser : `missions/POS_V4_DESIGN_AUDIT_001/input.json` (dans ce dépôt).
2. Lancer : `npm run codex:complex -- POS_V4_DESIGN_AUDIT_001` (ou `CODEX_MODEL_COMPLEX=gpt-5.5-pro` si budget.)
3. Consigner : `EXECUTE_DELEGATION: codex-terminal` dans le rapport de cycle.

Le fichier `input.json` répète le brief : README + contraintes invariants + **sortie uniquement** recommandations / risques (pas de patch).

---

## 7. Synthèse « que corriger / améliorer » (avant code)

| Priorité | Action |
|----------|--------|
| **P0** | Trancher **primary** : `#FF006B` vs `#0084FF` (produit + charte) |
| **P0** | Valider **parité** bindings : tout `v-model` / `@click` / `$refs` des SFC actuels mappés dans les templates v4 **avant** PR |
| **P1** | **Stratégie dark** : scope `fk-dark` + règles sur le reste de l’admin (sidebar, header) |
| **P1** | **Plan de tests** : lancer les filtres PHPUnit/JS listés dans le README + smoke `npm run build` |
| **P2** | Découper **modals** Payment v4 en sous-composants si le fichier devient > seuil de review |
| **P2** | Remplacer emojis produits par **vrais** assets branchés sur l’API |

---

## 8. Liens et fichiers générés

| Fichier | Rôle |
|---------|------|
| Ce rapport | `reports/audit/AUDIT_POS_V4_EXPORT_DESIGN_2026-04-24.md` |
| Mission Codex (second avis) | `missions/POS_V4_DESIGN_AUDIT_001/input.json` |

**Graphiti** : non requis pour un audit design export ; option : épisode `12_decisions` si la **couleur primaire** est tranchée en ADR.

---

## 9. Second avis exécuté (2026-04-24)

### 9.1 Claude Code (`claude -p` + `--add-dir` vers le dossier `POS (4)`)

Aperçu (résumé) : points forts = layout 3 colonnes, Payment 60/40 + modes, tokens CSS ; risques = conflit primaire `#FF006B` / `#0084FF`, modals inline vs norme SFC, non-traduction JSX→Vue si l’on ne considère que les previews ; **verdict bref** = ne pas fusionner en prod tant que la **décision marque** + **parité bindings** SFC + structure modals ne sont pas stables. *(Le ZIP contient aussi des `*.vue` : calibrer ce verdict avec le contenu exact des SFC, pas seulement `pos_v4.jsx`.)*

### 9.2 Codex API (`npm run codex:complex -- POS_V4_DESIGN_AUDIT_001`)

- **Sortie structurée** : `missions/POS_V4_DESIGN_AUDIT_001/output_codex.json` (champ `notes` = Markdown : Verdict, P0, P1, merge checklist, SYMMETRY).
- **Délégation** : `execution_trace.delegation` = `codex-terminal` (analyse seule, `files_to_modify: []`).

**En tête d’arbitrage** : le modèle rappelle de ne pas traiter l’export comme contrat d’implémentation ; passage obligatoire par **mapping** entités FoodKing, **pricing SSOT**, **énum statuts** et **branche** avant merge.

---

*Aucun fichier `resources/js/components/admin/pos/*.vue` du dépôt FoodKing n’a été modifié pour ce rapport.*

---

## 10. Addendum (2026-04-25) — audit du rapport + plan d’exploitabilité

- **Revue croisée** (Claude Code sur ce fichier + rappel Codex `POS_V4_DESIGN_AUDIT_001`) : compléter les **gaps** listés (bindings outillés, DoD par SFC, règles JSX→Vue, perfs caisse, tests au vrai noms) — voir synthèse dans le plan.
- **Roadmap d’exécution** (phases 0–4, définition d’*exploitable*, gates) : `plans/PLAN_POS_V4_EXPORT_READINESS_2026-04-25.md`.
- **Tentative** `codex:complex -- POS_V4_READINESS_PLAN_001` : le runner a refusé une mission *plan seul* (rôle implémentation) — la feuille de route est consignée dans le plan + audit terminal ci-dessus.
