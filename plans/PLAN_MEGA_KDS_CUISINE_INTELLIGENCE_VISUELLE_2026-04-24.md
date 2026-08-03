# Méga plan — KDS cuisine : intelligence visuelle, réduction d’erreurs, alignement QSR

**TASK_ID** : `PLAN_MEGA_KDS_2026-04-24`  
**Type** : design d’écran + orchestration d’implémentation + audits terminal  
**SSOT écran** : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` + helpers `resources/js/helpers/kdsDisplay.js`  
**Contrainte** : ne pas lourdement modifier `OrderService` / snapshot commande côté domaine **sans** gate explicite (invariants FoodKing) ; priorité **F/E** + interprétation sûre des champs **déjà** présents (`item_variations`, `item_extras`, `instruction`, `kds_station`).

**Objectif principal** : à **distance de lecture** (1,5–3 m) et en **contexte de stress** (chaleur, bruit, file), réduire les fautes de **composition** (viande, sans X, suppléments, instructions) en rendant l’**information saillante** sans alourdir la surface cognitive.

**Préambule scientifique (léger) — types d’erreur en production restauration**

- **Omission** : l’ouvrier n’a **pas vu** l’info (trop basse, noyée, même couleur que le reste).  
- **Mauvaise intégration** : l’info était là mais **mal groupée** (l’instruction « sans oignon » loin de la viande, etc.).  
- **Confusion de similarité** : deux commandes côte à côte **trop semblables** (numéro peu visible, pas de bord ancré).  
- **Délai** : l’ouvrier a regardé l’**ancien état** (bump/état non lisible).

➡️ Le design doit maximiser : **sillance visuelle ciblée**, **regroupement sémantique**, **différenciation des cartes**, **cohérence temporelle** (bump, timer, statut). Pas seulement « de belles couleurs » : une **grille de décision** pour le cerveau en 2 s.

---

## 1. Référence concurrence (QSR / KDS — enseignements, pas copie)

*Les produits varient (Toast, Square, Lightspeed, Kitchen Display de chains US/EU) ; les **principes** convergents :*

| Principe | Ce que font souvent les leaders | Enjeu FoodKing |
|----------|-----------------------------------|----------------|
| **Gros n° de commande** | 48–120 px visibles sur fond contrasté | `#` déjà en bleu ; renforcer le **groupe sémantique** ligne article |
| **Ligne = unité de production** | Une **carte par ligne** ou **bloc** très séparé par article | Aujourd’hui liste dense ; manque **parsing visuel** des *exceptions* (sans, allergènes) |
| **Code couleur limité** | 3–4 couleurs max (statut, urgence) | Trop de gris = tout se vaut — il faut une **légende** ancrée |
| **Exceptions en premier** | *Hold*, *allergy*, *no* en bandeau ou puce haute | Les **instructions** libre sont en bas aujourd’hui — scinder **auto-détectées** |
| **Station / poste** | Filtre par flattop / froid / bar | Déjà : `kds_station` + filtre — manque visibilité sur **chaque** ligne quand on est un poste ciblé |

➡️ **Différenciation FoodKing** : on a déjà **variations** structurées + **extras** + `instruction` texte. Le plan exploite le **même payload** : hiérarchiser **viandes / pain / suppléments** et **déduire** les mots d’exclusion (sans, no, exclure, *).

---

## 2. Système sémantique (couleurs, formes) — cible design

*À figer en **tokens** (CSS variables ou `tailwind` cohérent) pour ne pas devenir le « thème mûre ».*

| Sémantique | Rôle | Couleur cible (ex.) | Règle |
|------------|------|---------------------|--------|
| **Règle « sans X » / retrait** | Faute si ignorée | Fond `amber-50` + bordure `amber-500` + pictogramme « − » | Toujours **au-dessus** du reste de la ligne ou bandeau compact |
| **Allèrgène / avertissement** | Faute = santé | Fond `rose-50` / texte + contraste (WCAG AA) | Réservé mots clés (allergène listé) — aligné ressource `KDSAllergenVisibility` existante |
| **Suppléments payants (extras)** | Erreur de marge, pas de vie | Puce `slate-100` bordure `slate-400` ou `emerald-600` (positif) | Distinguer **+ suppl** vs **prix inclus** (libellé court) |
| **Viande / protein** | Cœur du produit (sandwich, tacos) | Texte `font-extrabold` + **première** ligne de variation ciblée | *Priorité lecture* : « Viande: … » en premier bloc, pas noyé dans le gris |
| **Conforme / base** | Pas d’action spéciale | `neutral-500` / blanc | Ne pas crier partout : seulement le **défaut** |
| **Attente (SLA)** | Délai d’engagement | Existant : `kds-wait-*` (vert / orange / rouge + pulse) | Conserver, documenter le seuil (5/10 min) + **a11y** (pas *pulse* seul) |

*Légende* : 4–5 lignes en **haut d’écran (sticky)** en mode KDS, masquable au clic — réduit l’**erreur d’interprétation** d’une couleur.

---

## 3. Règles d’**intelligence d’affichage** (sans back lourd, priorité règles côté client)

*Implémenter en **phases** dans l’ordre (helpers purs + tests Vitest d’abord).*

1. **Détecteur d’exclusion** sur `item.instruction` (et champs de variation si clés) : regroupe mots (sans, excl, no, hold) — dictionnaires **i18n** dès l’implémentation (même fichier que les listes, pas en dur FR).  
2. **Surlignage viande** : heuristique `variation_name` / `name` (viande, steak, poulet, kefta, etc.) + ordre d’affichage **protein d’abord** (déjà test `kdsStationFilter` pour structure).  
3. **Déduplication** : ne pas afficher 2× la même info. **Règle de priorité** : toute donnée structurée **B/E** (allergènes, extras) **> heuristique** sur texte libre ; en cas de doublon, afficher la source structurée et **résumer** le texte libre restant.  
4. **Quantité** : `quantity` déjà gros côté liste — rapprocher du **n°** commande en **cordon visuel** (bord gauche épais = même lot).  
5. **Basse vision** : taille de police minimale cible (par breakpoint) : **jamais < 12 px** sur champs « risque » (sans / allergènes).  
6. **Tests négatifs i18n** (obligatoires) : ex. *« No. 5 spécial »*, *« Burrito n°3 »* pour ne pas classer *no* / *n°* comme retrait.  

**Gate allergène** : si le payload B/E contient un champ allergène **structuré** → **priorité visuelle absolue** (pas contredite par l’heuristique) ; l’heuristique sur l’`instruction` reste un **aide** avec **badge d’incertitude** (ex. bordillons `?` ou légende « vérifier ») si conflit ou seulement texte libre.

*Anciennement* : toute règle qui **décide** d’un allergène médical **définitif** = **B/E** ; ici c’est l’**affichage** qui se **plie** à la source structurée quand elle existe (voir ressource API / `KDSAllergenVisibility` tests existants).

---

## 4. Découpage d’**implémentation** (ordre, livrable, preuve, audit)

*Après chaque **phase** :*  
1) tests Vitest ciblés + invariants `check-invariants.sh` ;  
2) `bash scripts/foodking-claude-orchestrate.sh audit "KDS — Phase N : [résumé]. VERDICT + 5 risques. Français."`  
3) ligne dans `memory/episodes/12_decisions_log.jsonl` si choix d’ADR couleur.  
*Si le terminal indique **quota** / erreur API :* pause planifiée (2–5 h) puis reprise.

### Phase K0 — Cadrage & légende (1–2 h design)

- **Livrable** : maquette (Figma) ou `docs/design/KDS_TOKENS.md` + capture du **before** (screens actuel).  
- **Test** : revue product + chef d’exploitation.  
- **Audit** : bref, pas de code.

### Phase K1 — Helpers d’analyse (pure JS, TDD) **+ sémantique d’exclusion / i18n dès le départ**

- Fichier type `resources/js/helpers/kdsLineSemantics.js` : `parseExclusionHints(text, locale)`, `sortVariationsForKds(item)`, `kdsLineRiskFlags(item)` (réconciliant **B/E** structuré vs heuristique) → `tests/js/kdsLineSemantics.spec.js` avec **cas négatifs** (No., noms, false positives *no* / *sans*).  
- Inclut les **mots d’exclusion** par langue (mini table, pas 50 regex) : pas de **Phase K5 séparée** (fusion audit terminal).  
- **Gate** : 0 requête réseau, 0 prix ; affichage priorise données structurées (voir §3).  
- **Audit terminal** : oui (cohérence invariants + pièges i18n).

### Phase K2 — Composant `KdsOrderLine.vue` (bloc unifié)

- Encapsule : titre article, **bandeau exclusions**, variations **reordonnées**, extras, instruction résiduelle.  
- Remplace progressivement le `v-for` inline dans les colonnes dine-in / emporter / kiosque (refactor en petit PR).  
- **Tests** : `mount` + storybook optionnel, Vitest.  
- **Audit** : oui (symétrie multi-canaux).

### Phase K3 — Cohérence `items board` (colonne gauche) + aujourd’hui (droite)

- Même composant de ligne (une source de vérité visuelle). **Risque** (audit) : aujourd’hui les colonnes n’ont pas le même `v-for` / niveau de détail → prévoir **sous-PR** ou tâche dédiée (pas bloquer toute la chaîne ici).  
- Vérifier **groupement table** + **station** sur la **même** carte.

### Phase K4 — A11y & distance

- Cibles 44px bump, `aria` sur toggle statut, alternative au **seul** pulse (texte d’attente explicite).  
- **Audit** : WCAG ciblé opérateur.  
- **Test** : `tests/js/posComponentA11y.spec.js` pattern, équivalent KDS.

*Note : l’ancienne « phase i18n » a été **fusionnée en K1** (voir ci-dessus).*

### Phase K5 — E2E KDS (opt-in) + fumée

- `tests/e2e/04-kds-status.spec.js` + scénario **ligne** avec `instruction` « sans oignon ».  
- **GATE** : E2E comme ailleurs (`workflows/qa-loop.md`).

### Phase K6 — Clôture

- `RUN` dans `reports/execution/RUN_MEGA_KDS_*.md` + entrée `12_` + audit final terminal **audit-brief** (5 points restants seulement).

---

## 5. Mesure du succès (KPI, même si qualitatif au début)

| Métrique | Cible v1 |
|----------|----------|
| **Temps pour identifier « retrait d’ingrédient »** (lab interne) | < 2 s, 90 % scénarios |
| **Fautes signalées** sur **la même période** (support / tickets) | baisse attendue ; baseline à prendre J0 |
| **Bumps accidentels** (même ligne relue) | stable ou ↓ (mesure atelier) |
| **Tests** | + suite Vitest ; pas de baisse couverture PHP |

---

## 6. Risques & non-objectifs (transparence)

- **Risque** : surdéclencher l’**alarme** (trop d’ambers) → toute l’écran crie, plus rien ne ressort ; **K0** tranche.  
- **Risque** : regex « sans oignon » vs « avec oignon cuit **sans** laitue » : helpers **défensifs**, tests **négatifs** dans Vitest.  
- **Non-objectif** : remplacer KDS par un **mur vidéo** 4K, ou re-prix côté cuisine (SSOT = toujours côté Laravel pour la caisse, pas ici).  
- **Risque quota** terminal : si `claude -p` en erreur, le plan n’est **pas** bloqué — **reprendre l’étape d’audit** quand l’abonnement revient, sans re-coder.

---

## 7. Première action concrète (dès aujourd’hui)

1. **Valider** le tableau couleur §2 (product + 1 opérateur).  
2. Démarrer **K1** (helpers + tests) — petit, mesurable, sans risque domain.  
3. Lancer l’**audit** terminal post-K1 (prompt court, une phase à la fois).

*Fin — ce document sert d’orchestrateur de finition : il peut être découpé en tâches `tasks/execute-…` au besoin, sans remplacer `AGENTS.md` sur les *frozen zones*.*

---

## 8. Journal d’audit terminal (rétroactif, à compléter à chaque phase)

| Date | Commande (résumé) | Verdict |
|------|--------------------|---------|
| 2026-04-24 | `foodking-claude-orchestrate.sh audit` sur v1 plan | **AJUSTE** (fusion i18n→K1, gate B/E > heuristique, K3 double-colonne, tests négatifs) — **intégré** dans le présent texte. |
| (à venir) | idem post-K0 | — |
| … | post-K1, K2, … | — |
