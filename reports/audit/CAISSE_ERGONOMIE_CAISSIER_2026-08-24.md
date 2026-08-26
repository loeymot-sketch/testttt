# Audit ergonomie caissier — caisse FoodKing

Date : 2026-08-24 · Cycle : `CAISSE-SUPERVISOR-CONTROL-20260823`
Méthode : parcours navigateur réels, authentifié, sur `127.0.0.1:8766`, en me plaçant
à la place du caissier. Chaque ligne est une mesure, pas une impression.
Mesures brutes : `reports/audit/supervisor-loop-2026-08-24/ergonomie-caissier.json`
Captures : `reports/audit/supervisor-loop-2026-08-24/screenshots/E1..E7-*.png`

Deux de mes propres conclusions préliminaires étaient fausses ; elles sont corrigées
plus bas, avec ce qui les a démenties. Une mesure qu'on ne vérifie pas vaut une opinion.

---

## 1. Le défaut qui coûte le plus cher : la grille de vente est sous l'écran

**Mesure, viewport 1366×768 (écran de comptoir courant) :**

| Grandeur | Valeur |
| --- | --- |
| Haut de la première tuile catégorie | **y = 792 px** |
| Hauteur de la fenêtre | 768 px |
| Défilement nécessaire pour la révéler | **175 px** |
| Visible sans défiler | **non** |
| `scrollHeight` de la page | 768 px — la PAGE ne défile pas, le contenu vit dans un conteneur interne |

Ce que le caissier voit à l'ouverture (capture `E7-caisse-1366x768.png`) : l'en-tête, une
alerte « Article indisponible », le bloc d'actions « Commande rapide », puis **quatre
panneaux de supervision** — « Prêt à livrer (0) », « À encaisser — comptoir (2) »,
« Commandes web · 77 », « Web payées (0) » — et le ticket à droite.

La grille de vente, l'outil de chaque vente, n'apparaît nulle part. Le panneau de droite
affiche pourtant « Aucun article. **Sélectionnez un produit dans la grille.** » : l'écran
demande d'utiliser une grille qu'il ne montre pas.

Aggravant, mesuré séparément : **les tuiles de catégorie ne sont pas atteignables au
clavier** — 40 tabulations depuis le haut de page n'en touchent aucune. Les touches F sont
donc, aujourd'hui, la seule façon d'ouvrir une catégorie sans faire défiler un conteneur
interne. Ce constat rejoint et confirme celui déjà consigné le 2026-08-22 dans
`tests/e2e/goal-caisse-raccourcis-fkeys.spec.js`.

**Ce n'est pas un correctif que je peux prendre seul** : réordonner la page caisse est une
décision de produit sur un écran longuement réglé par le propriétaire. Je la remonte donc
avec la mesure et une recommandation.

**Recommandation** — par ordre de gain décroissant, sans rien supprimer :
1. Remonter la grille catégories/produits **au-dessus** des panneaux de supervision. La
   vente d'abord, la surveillance ensuite.
2. Rendre les quatre panneaux repliables, et mémoriser l'état par poste. « Commandes web ·
   77 » occupe la meilleure surface de l'écran pour une information de fond.
3. Rendre les tuiles de catégorie focalisables au clavier (`tabindex`), pour que les touches
   F cessent d'être l'unique échappatoire.

---

## 2. Le décalage F1 → catégorie : contre-intuitif, et je l'ai d'abord mal lu

**Mesure (événements clavier synthétiques) :**

| Touche | Effet réel |
| --- | --- |
| F1 | 0 tuile produit → on **reste sur la grille** (sentinelle « toutes catégories », id 0) |
| F2 | 5 tuiles → **Sandwichs**, la 1ʳᵉ catégorie affichée |
| F3 | 3 tuiles → **Galette**, la 2ᵉ |

La correspondance réelle est donc **tuile N ↔ F(N+1)**.

**Correction de ma propre mesure** : mon premier passage utilisait
`page.keyboard.press('F2')` et concluait que les touches F ne faisaient rien. C'est FAUX :
en navigateur sans interface, les touches F sont interceptées avant la page. Le piège est
documenté dans `tests/e2e/goal-caisse-raccourcis-fkeys.spec.js`, qui dispatche un
`KeyboardEvent` sur `document`. Corrigé, les raccourcis fonctionnent parfaitement.

**Recommandation** : le décalage est cohérent côté code mais illisible côté comptoir. Soit
étiqueter chaque tuile avec la touche qui l'ouvre réellement (Sandwichs → F2), soit faire
pointer F1 sur la première tuile et déplacer « toutes catégories » ailleurs. Choix produit ;
ne pas « corriger » l'un sans trancher l'autre.

---

## 3. La recherche produit est bonne — et j'avais annoncé le contraire

**Mesures finales, depuis la grille :**

| Saisie | Résultat |
| --- | --- |
| `Suprême` | 1 → Suprême |
| `supreme` (sans accent) | 1 → Suprême ✔ **insensible aux accents** |
| `SUPRÊME` | 1 ✔ **insensible à la casse** |
| `supr` | 1 ✔ **préfixe partiel** |
| `ayenne` | 2 → Cayenne, Galette Cayenne ✔ **sous-chaîne en milieu de mot** |
| `Cayenne` | 2 — portée menu entier |
| Délai avant filtrage | ~925 ms |

**Correction de ma propre mesure** : mon premier passage signalait « insensible à la casse :
non » et « tolère la saisie partielle : non ». C'étaient des artefacts — je comparais des
comptages pris sur un état qui avait changé entre deux saisies. Et mes contre-exemples
`poulet` / `creme` renvoyaient 0 parce qu'**aucun produit de ce nom n'existe** (vérifié en
base : 0 ligne). La recherche n'était pas en cause.

**Le seul vrai point, lui, tient — et il est net :**

| Contexte | Recherche `Cayenne` | Résultat |
| --- | --- | --- |
| Depuis la grille | menu entier | **2 produits** |
| Depuis la catégorie « Galette » ouverte | catégorie seule | **1 produit** (Galette Cayenne) |

La recherche se **restreint silencieusement à la catégorie ouverte**, alors que son champ
promet « Rechercher un article du menu ». Un caissier dans Galette à qui on commande un
tacos ne trouve rien, sans qu'aucun élément d'écran lui dise de revenir en arrière.

**NON CORRIGÉ — délibérément, et voici pourquoi.** `resources/js/helpers/posBrowseView.js`
porte en tête la mention « Owner direction (/goal): the cashier's FIRST POS screen must show
the grid of categories ». La portée de la recherche fait partie de ce flux « category-first »
que vous avez mandaté. Y toucher sans votre accord serait exactement le genre de décision
que je ne dois pas prendre seul.

Deux options, à vous de trancher :

- **Option A — la recherche efface la catégorie.** Dès que le caissier tape, on repasse sur
  tout le menu. C'est ce que promet le libellé du champ. Changement de comportement, simple,
  une ligne de logique.
- **Option B — un état vide qui explique.** On ne change rien au filtrage ; quand la
  recherche restreinte ne rend aucun résultat, on affiche « Aucun résultat dans *Galette* —
  chercher dans tout le menu » avec le bouton correspondant. Strictement additif, aucun
  comportement existant modifié.

Je recommande **B** : elle résout la confusion sans toucher au mandat category-first.

---

## 4. Focus clavier invisible sur plus de la moitié du parcours

**Mesure : 39 arrêts de tabulation réels sur la caisse.**

| Contrôle | Résultat |
| --- | --- |
| Arrêts sur un élément invisible | **0** ✔ (aucun piège à focus) |
| Arrêts sans nom accessible | 1 (une balise `<a>`) |
| **Arrêts sans anneau de focus visible** | **22 / 39** |
| Exemples | « Tableau de bord », « POS », « Produits & Stock », « Conso & Stock », « Ajustement stock » |

Toute la navigation latérale prend le focus sans le montrer. Un caissier qui tabule ne sait
pas où il est ; il tape Entrée à l'aveugle. Les champs de la caisse, eux, ont bien reçu
leur `focus-visible` dans ce cycle — le manque est concentré sur le menu latéral.

**CORRIGÉ le 2026-08-24.** Règle `:focus-visible` ajoutée sur `.db-sidebar-nav-menu` et
`.db-sidebar-nav-dropdown-menu` dans `resources/css/pos-a11y.css`, avec le même anneau que
le reste du fichier (#1d4ed8, 3 px, offset 2 px) — on n'introduit pas un second langage
visuel pour le focus.

Vérifié au navigateur après reconstruction, avec un **témoin** pour valider l'instrument :
5/5 boutons (déjà couverts par une règle existante) montrent leur anneau, et **20/20 liens
du menu latéral** montrent désormais le leur. `:focus-visible` se déclenche bien sur 38 des
39 arrêts. Sans ce témoin je n'aurais pas pu distinguer « le correctif ne marche pas » de
« mon instrument de mesure est faux » — et mon tout premier relevé après build lisait
effectivement un CSS encore en cache.

---

## 5. Démarrage de la caisse : 5,3 s avant le premier geste possible

| Grandeur | Mesure |
| --- | --- |
| Premier rendu | 4 028 ms |
| DOM prêt | 4 045 ms |
| **Première catégorie cliquable** | **5 350 ms** |
| Seuil métier retenu | 3 000 ms |
| Poids du bundle caisse | `js/pos-shell.*.js` = **833 Kio** |

**Honnêteté sur la mesure** : elle est prise en environnement de développement, avec
`php artisan serve` (mono-processus) et la debugbar active (`APP_DEBUG=true`). Une partie du
délai lui est imputable et disparaîtra en production. Ce qui ne disparaîtra pas, c'est le
**bundle de 833 Kio** à parser avant le premier geste. Je ne présente donc pas 5,3 s comme
le chiffre de production ; je présente le poids du bundle comme la cause structurelle, et je
recommande de refaire la mesure en conditions de production avant d'arbitrer.

---

## 6. Ce qui fonctionne bien, et qu'il faut préserver

- **Parcours de vente court** : 1 geste pour ouvrir une catégorie (1 273 ms), 1 geste pour
  ouvrir la fiche produit (1 832 ms). Deux gestes jusqu'au ticket, c'est bon.
- **Encaissement à un geste** depuis l'écran de suivi : le bouton « Encaisser » est sur la
  carte, sans navigation ni écran intermédiaire.
- **Comportement en panne exemplaire** — sonde de santé coupée par le test : la pastille
  passe en ambre, affiche « Contrôle indisponible · Aucun contrôle récent », et propose un
  bouton « Réessayer » actif. Elle ne ment pas et ne laisse pas le caissier sans recours.
- **Recherche produit** : accents, casse et milieu de mot gérés (cf. §3).
- **Aucune fuite i18n, aucun `NaN`, aucun `0undefined`, aucune erreur JavaScript** sur les
  16 surfaces balayées.

---

## 7. Synthèse pour arbitrage

| # | Sujet | Gravité | Décision |
| --- | --- | --- | --- |
| 1 | Grille de vente sous la ligne de flottaison en 1366×768 | **Élevée** | Propriétaire — réordonnancement de la page caisse |
| 2 | Tuiles de catégorie non atteignables au clavier | Élevée | Propriétaire (lié au 1) |
| 3 | Recherche restreinte à la catégorie ouverte, sans le dire | Moyenne | **Propriétaire** — touche le mandat category-first (options A/B au §3) |
| 4 | Anneau de focus absent sur les liens du menu latéral | Moyenne | ✅ **CORRIGÉ et vérifié au navigateur** |
| 5 | Décalage F1 → « toutes catégories » | Moyenne | Propriétaire — choix d'étiquetage |
| 6 | 833 Kio de bundle avant le premier geste | À confirmer | Re-mesurer en production d'abord |

Un seul point a été corrigé : le n° 4, purement CSS, sans effet fonctionnel, vérifié au
navigateur avec témoin. Tous les autres touchent la disposition ou le flux d'un écran réglé
par le propriétaire, ou demandent un arbitrage : ils sont documentés, mesurés, chiffrés, et
laissés à votre décision. Le n° 3 a une option strictement additive (B) prête à être lancée
sur un mot.
