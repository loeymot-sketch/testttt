# Audit UX/UI — Configurateur POS (prise de commande caissier)

**Date:** 2026-03-10  
**Type:** Planification / Audit (Claude — raisonnement et plan)  
**Objectif:** Rendre la prise de commande **plus rapide** et **pensée pour le caissier**, en réduisant le scroll et en alignant l’UI sur les standards POS quick-service.

---

## 1. Contexte

- **Acteur cible:** Caissier (Manager authentifié), pas le client final.
- **Flux:** Ouverture du wizard sur un article (ex. sandwich « Le Terminator ») → une **seule page scrollable** avec toutes les sections → ajout au panier.
- **Fichiers concernés:** `public/js/pos-wizard.js` (`renderSinglePage()`), `public/css/pos-wizard.css` (bloc `.pos-wizard.single-page`).

---

## 2. État actuel (single-page)

Ordre des blocs dans `renderSinglePage()` :

| # | Section | Contenu typique | Hauteur estimée |
|---|---------|-----------------|-----------------|
| 0 | Header | Image + nom + prix de base | 1 bloc |
| 1 | Pain/Galette | 2 grandes cartes (Pain, Galette) | ~120px |
| 2 | Quantité | − / 1 / + | ~60px |
| 3 | Viandes | Liste complète + steppers + viande extra | ~200–350px |
| 4 | Crudités | 3 pills (Salade, Tomate, Oignon) | ~80px |
| 5 | Sauce | Grille 3 colonnes, toutes les sauces | ~180–250px |
| 6 | Suppléments | Grille 2 colonnes, tous les suppléments | ~150–250px |
| 7 | Formule | 4 cartes (Sans, Menu, Frites, Boisson) | ~140px |
| 8 | Options frites | Grande portion, Cheddar (si formule avec frites) | ~80px |
| 9 | Sauce frites | **Grille complète identique** à la section Sauce | ~180–250px |
| 10 | Instruction spéciale | Textarea | ~100px |
| 11 | Aperçu ticket + barre sticky | Total + bouton « Ajouter au panier » | fixe bas |

**Problèmes mesurés :**

- **Longueur:** 3 à 4 hauteurs d’écran pour un sandwich complexe (confirmé par capture et description).
- **Redondance:** La grille « Sauce » est dupliquée intégralement pour « Sauce frites » (même liste, même mise en page).
- **Choix binaires surdimensionnés:** Pain (2 options) et Formule (4 options) occupent des cartes larges alors que ce sont des choix simples.
- **Peu de regroupement horizontal:** Beaucoup de sections en pleine largeur, ce qui augmente le scroll.

---

## 3. Benchmarks concurrence / bonnes pratiques POS

- **Quick-service / fast-food:**  
  - Peu de scroll : choix essentiels visibles d’un coup ou en 2 colonnes.  
  - Total toujours visible (sticky) ou en haut à droite.  
  - Options secondaires (sauce frites, commentaire) compactes ou dépliables.

- **POS pro (Lightspeed, Square, etc.) :**  
  - Grilles d’options denses, libellés courts, icônes petites.  
  - Pas de répétition d’une même grille (ex. une seule zone « sauces » avec contexte « sandwich » vs « frites »).

- **Principes ergonomie caissier :**  
  - **Moins de clics = moins de scroll** pour les choix fréquents.  
  - **Above the fold:** Pain, quantité, viandes, formule devraient tenir sans scroll sur un écran standard.  
  - **Progressive disclosure:** Détails (sauce frites, instruction) affichés seulement quand pertinent ou en accordéon.

---

## 4. Problèmes identifiés (priorisés)

| Priorité | Problème | Impact |
|----------|----------|--------|
| **P0** | Page trop longue (3–4 écrans) | Temps de prise de commande élevé, fatigue, erreurs |
| **P0** | Grille « Sauce frites » = copie complète de « Sauce » | Double surface, double scroll, confusion visuelle |
| **P1** | Pain et Formule en grandes cartes | Gaspillage d’espace vertical pour 2–4 options |
| **P1** | Sauce + Suppléments en pleine largeur | Pourrait être en 2 colonnes (sauce à gauche, suppléments à droite) |
| **P1** | Crudités + Viande extra noyés au milieu | Peu visibles ; pourraient être regroupés avec Viandes ou en ligne compacte |
| **P2** | Instruction spéciale en grand textarea | Optionnel ; peut être replié par défaut |
| **P2** | Aperçu ticket en bas avant la barre | Pourrait être sticky ou réduit pour garder le total visible plus tôt |

---

## 5. Recommandations UX/UI (actionnables)

### 5.1 Réduire la redondance (P0)

- **Sauce frites ≠ deuxième grille complète.**  
  - **Option A (recommandée):** Une seule zone « Sauces » avec deux sous-contextes :  
    - « Sauce sandwich » (1ère gratuite)  
    - « Sauce frites » (affiché seulement si formule avec frites), **en une ligne compacte** : chips/boutons (réutiliser la même liste de sauces) ou dropdown « Même que sandwich » + override.  
  - **Option B:** Garder deux zones mais **sauce frites en ligne horizontale scrollable** (style chips), pas en grille 3 colonnes identique.

- **Effet attendu:** Suppression d’environ 150–200px de hauteur et moins de répétition visuelle.

### 5.2 Compacter les choix binaires / peu nombreux (P1)

- **Pain (2 options):**  
  - Remplacer les 2 grandes cartes par **2 boutons horizontaux** (style segment control) ou **2 chips** sous le titre « Type de pain », même ligne que le titre si possible.

- **Formule (4 options):**  
  - Remplacer les 4 grandes cartes par **une ligne de 4 boutons compacts** (icône + libellé court + prix) ou **grille 2×2** avec cartes plus petites (padding réduit, police plus petite).

- **Quantité:**  
  - Déjà compacte ; possible de la mettre **sur la même ligne que le type de pain** (Pain | Quantité) sur desktop/tablette.

### 5.3 Mise en page 2 colonnes (P1)

- **Bloc « Sauce + Suppléments »:**  
  - Sur viewport suffisamment large (ex. ≥ 768px), afficher **Sauce (grille compacte)** à gauche et **Suppléments (grille compacte)** à droite dans un même `.wizard-section` ou `.wizard-split`.  
  - Sur mobile, garder l’empilement vertical mais avec grilles plus denses (`.sauce-grid.compact`, `.supplement-grid` déjà partiellement présents).

- **Viandes + Crudités:**  
  - Sur grand écran : **Viandes** à gauche (liste + viande extra), **Crudités** à droite (pills).  
  - Réduit le scroll et rapproche les choix « contenu du sandwich ».

### 5.4 Above the fold (P1)

- **Objectif:** Sur un écran type 768px de hauteur, avoir visible sans scroll : Header + Pain + Quantité + Viandes (au moins début) + Formule (ou au moins le début).  
- Moyens :  
  - Réduire marges/padding des sections (ex. `margin-bottom: 16px` au lieu de 24px pour `.wizard-section`).  
  - Réduire hauteur des cartes Pain et Formule (voir 5.2).  
  - Regrouper Sauce + Suppléments en 2 colonnes (voir 5.3).

### 5.5 Progressive disclosure (P2)

- **Sauce frites:** Déjà conditionnel à « formule avec frites ». Le garder mais en **affichage compact** (chips ou une seule ligne), pas grille complète.

- **Instruction spéciale:**  
  - Par défaut : **ligne unique** avec placeholder « Ex: Pas trop de sauce… » et icône « + » pour étendre.  
  - Au clic : textarea plus grand ou zone expand.  
  - Réduit la hauteur fixe pour les commandes sans commentaire.

### 5.6 Aperçu ticket et total (P2)

- **Sticky du total:** La barre `.wizard-sticky-bar` existe déjà ; s’assurer qu’elle reste visible dès le premier scroll (z-index, pas de overflow caché).
- **Aperçu ticket:** Option « résumé en 1 ligne » sticky sous le header (ex. « 1× Le Terminator – Pain, 2 viandes, Menu… ») avec tooltip ou modal au clic pour le détail, pour garder le contexte sans occuper trop de place.

---

## 6. Plan d’implémentation proposé

### Phase 1 — Quick wins (sans changer la structure des données)

1. **Sauce frites en compact**  
   - Remplacer la grille complète « Sauce frites » par une **ligne de chips** (même liste `sauceVariations`, même state `sauceFrites` / `sauceFritesOrder`).  
   - Fichiers : `pos-wizard.js` (bloc sauce-frites dans `renderSinglePage`), `pos-wizard.css` (nouvelle classe `.sauce-frites-chips` ou réutilisation `.garniture-toggle` en lecture seule visuelle).

2. **Pain en segment control**  
   - Remplacer `.pain-grid` (2 grandes cartes) par une ligne de 2 boutons (Pain / Galette).  
   - JS : même `data-type="pain"` et `data-id`, changement de markup. CSS : nouveau style `.pain-segment` ou `.pain-inline`.

3. **Formule en ligne compacte**  
   - Remplacer les 4 grandes `.formule-card` par une grille 2×2 plus serrée ou 4 boutons en ligne (responsive : wrap sur petit écran).  
   - Réduire padding et font-size dans `.pos-wizard.single-page .wizard-formule-cards`.

### Phase 2 — Mise en page 2 colonnes

4. **Section Sauce + Suppléments en 2 colonnes** (viewport ≥ 768px)  
   - Dans `renderSinglePage`, envelopper Sauce et Suppléments dans un conteneur `.wizard-split` (déjà utilisé ailleurs dans le wizard).  
   - CSS : `.pos-wizard.single-page .sauce-section + .supplements-section` ou regroupement explicite en un seul bloc avec `.wizard-col` pour sauce et suppléments.

5. **Section Viandes + Crudités en 2 colonnes** (viewport ≥ 768px)  
   - Même idée : un `.wizard-split` avec Viandes (et viande extra) à gauche, Crudités à droite.  
   - Sur mobile, garder l’ordre actuel en colonne unique.

### Phase 3 — Affinements

6. **Marges et espacements**  
   - Réduire `margin-bottom` des sections en single-page (ex. 24px → 16px) et padding des cartes pour gagner encore du vertical.

7. **Instruction spéciale repliable**  
   - Par défaut : input une ligne ou bouton « + Commentaire ». Au clic : afficher le textarea.  
   - Conserver la valeur dans `instructionText` et la soumettre comme aujourd’hui.

8. **Aperçu ticket condensé** (optionnel)  
   - Ajouter une ligne « résumé 1 ligne » sous le header, avec détail au clic si besoin.

---

## 7. Risques et tests

- **Risques:**  
  - Changement uniquement en **single-page** (POS caissier) : pas d’impact sur le flux par étapes ni sur le kiosk si les deux sont bien séparés.  
  - Les `data-type` / `data-id` / `data-action` doivent rester identiques pour que `bindSinglePageEvents()` continue de fonctionner.  
  - Responsive : bien tester 768px (tablette) et mobile pour les 2 colonnes.

- **Tests suggérés:**  
  - Ajout au panier d’un sandwich avec pain, 2 viandes, crudités, 1 sauce, 1 supplément, formule Menu, options frites, sauce frites, commentaire : vérifier que le ticket et le total sont corrects.  
  - Vérifier que « Sans formule » masque bien options frites et sauce frites.  
  - Vérifier que le sticky total reste visible pendant tout le scroll.

---

## 8. Synthèse

| Action | Priorité | Gain estimé | Phase |
|--------|----------|-------------|--------|
| Sauce frites en chips / 1 ligne au lieu de grille complète | P0 | −150 à −200px, moins de redondance | 1 |
| Pain en 2 boutons (segment) | P1 | −60 à −80px | 1 |
| Formule en cartes compactes (2×2 ou ligne) | P1 | −40 à −60px | 1 |
| Sauce + Suppléments en 2 colonnes | P1 | −100 à −150px équivalent scroll | 2 |
| Viandes + Crudités en 2 colonnes | P1 | −80 à −120px équivalent | 2 |
| Instruction repliable | P2 | −60 à −80px quand fermé | 3 |
| Réduction marges/padding | P2 | −50 à −80px | 3 |

**Objectif:** Passer d’environ **3–4 écrans** de scroll à **1,5–2 écrans** pour un sandwich complet, avec une expérience plus alignée sur un POS rapide et orienté caissier.

---

*Document conforme au workflow : planification dans `reports/planning/`, exécution à tracer dans `reports/execution/`, pas de modification du domaine métier (prix, auth, flux de commande).*
