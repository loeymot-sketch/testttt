# V5 — RED-TEAM CYCLE 14 (lecture seule) — vitrine S7 « Le Cayenne »
Date : 2026-07-30 · HEAD site : `b2a92d3` (heal du cycle 13)
Périmètre : `screens.jsx`, `components.jsx`, `screens-v3.jsx`, `styles-v6-brand.css`, `assets/**`, STYLE des pages légales.
Captures : `reports/goal-s7-vitrine/shots/red14/` · Scripts : `~/.claude/jobs/1269b1ef/tmp/r14-*.js`
Aucun fichier du site n'a été modifié. Les variantes sont mesurées par `addStyleTag` au runtime, en mémoire.

## 0. CONTRÔLE DE SANTÉ — OK

`r14-sante.js`, 3 routes @1440, hauteur **stabilisée** (boucle de défilement jusqu'à 2 mesures identiques) :

| route | `#root` enfants | texte rendu | nav | hero | `h1` | `pageerror` | `console.error` | scrollWidth/innerWidth |
|---|---|---|---|---|---|---|---|---|
| `home` | 1 | 5498 car. | oui | oui | « FAIT MAISON, CHAQUE SOIR. » | 0 | 0 | 1440/1440 |
| `menu` | 1 | 3201 car. | oui | n/a | « TOUT CE QU'ON CUISINE. » | 0 | 0 | 1440/1440 |
| `loyalty` | 1 | 771 car. | oui | n/a | « CONNECTE-TOI POUR CUMULER. » | 0 | 0 | 1440/1440 |

Titre = « Le Cayenne — Site officiel ». Preuve lue : `shots/red14/sante-home.png`.

## A. ÉTAT DU HEAL DU CYCLE 13 — la falaise de 6,5× est bien tuée, mais **3 des 5 déclarations du correctif sont MORTES**

`styles-v6-brand.css:306` = `grid-template-columns: repeat(3, minmax(0, 1fr))` (plus aucun palier), `:310-315` = bloc `@media (max-width: 400px)` du « resserrement ».

### A1 — 3 colonnes à toutes les largeurs : CONFIRMÉ, plus de falaise de structure
`r14-fam.js`, 13 largeurs, hauteur stabilisée, `.lc-rv` révélés :

| largeur | 320 | 360 | 375 | 390 | 400 | **401** | 412 | 430 | 480 | 520 | 768 | 1024 | 1440 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| colonnes | 3 | 3 | 3 | 3 | 3 | 3 | 3 | 3 | 3 | 3 | 3 | 3 | 3 |
| rangées | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] |
| orpheline | non ×13 | | | | | | | | | | | | |
| gouttière | 7 | 7 | 7 | 7 | 7 | **12** | 12 | 12 | 12 | 12 | 16 | 16 | 16 |
| description | none | none | none | none | none | **block** | block | block | block | block | block | block | block |
| tuile | 91×123 | 105×137 | 110×142 | 115×147 | 118×150 | 115×252 | 119×255 | 125×245 | 141×245 | 151×255 | 225×311 | 303×373 | 431×491 |
| **hauteur grille** | **408** | **448** | **439** | **454** | **464** | **712** | **723** | **724** | **741** | **754** | **932** | **1 134** | **1 504** |
| hauteur home | 9 485 | 9 453 | 9 419 | 9 433 | 9 457 | 9 705 | 9 715 | 9 773 | 9 919 | 10 162 | 8 614 | 7 918 | 7 825 |
| texte rogné | 0 ×13 | | | | | | | | | | | | |
| débordement de page | 0 ×13 (scrollWidth = innerWidth partout) | | | | | | | | | | | | |

- **La falaise de 6,5× du cycle 13 est morte.** 0 orpheline, 0 rognage, 0 débordement aux 13 largeurs. Le critère du cycle 10 tient.
- **Agrandissement au-delà de la source : NÉGATIF sur téléphone.** `r14-dsf.js`, `deviceScaleFactor: 3` sur 320 / 375 (SE) / 390 (14) / 412 (Pixel) / 430 (Pro Max) : les 9 `cat-*.png` (640×640, `cat-menu-enfant` 800×800) sont demandés à **184 / 239 / 254 / 266 / 284 px appareil** — soit ×0,29 à ×0,44. **Aucun agrandissement.** La réserve du cycle 13 (×1,54) est **levée**. Preuve lue : `shots/red14/dsf3-390.png`.
- **Lisibilité à 320 px : OUI.** Capture lue `shots/red14/fam-320.png` : grille 3×3 nette, les 9 visuels sont **reconnaissables** (baguette, galette, burger, tacos, bol, frites, tiramisu, canette Coca, nuggets), les 9 libellés tiennent en capitales lisibles à 16 px. Rien de rogné, rien d'orphelin.
- **Description masquée sous 400 px : perte ACCEPTABLE et SYMÉTRIQUE.** `display: none` (`:314`) retire le texte de l'arbre d'accessibilité **comme** de l'écran : le lecteur d'écran et l'œil reçoivent exactement la même chose. Le nom de famille reste, et le bouton porte `aria-label="<Famille> — ouvrir le menu complet"` (`screens.jsx:393`), vérifié sur les 9 tuiles. **Réserve honnête** : cet `aria-label` ne **reprend pas** la description — ce n'est donc pas un équivalent, c'est une suppression assumée. Pas un défaut d'a11y (aucune asymétrie), une décision éditoriale.

### A2 — Ce que le heal du cycle 13 **n'a pas vérifié** : 3 de ses 5 déclarations n'atteignent jamais l'élément → **P1-1** (voir §B)

## B. DÉFAUT NOUVEAU — PROUVÉ

### P1-1 · Le « resserrement sous 400 px » du cycle 13 est **inopérant à 60 %** : 3 de ses 5 déclarations sont annulées par des règles **plus bas dans le même fichier** — forme « une couche annule mon intention »

**Le fait.** Le bloc `@media (max-width: 400px)` occupe `styles-v6-brand.css:310-315`. Les règles de base qu'il prétend surcharger sont écrites **APRÈS lui** dans le même fichier, à **spécificité identique** (une classe simple ; une media-query n'ajoute aucune spécificité). Le CSS donne donc la victoire à la dernière déclaration :

| déclaration du heal | ligne | règle qui l'écrase | ligne | valeur **calculée** à 320-400 px | état |
|---|---|---|---|---|---|
| `.lc-v6-familles { gap: 7px }` | `:311` | `.lc-v6-familles { gap: 12px }` (**avant**, `:307`) | — | `gap: 7px` | **VIVANTE** |
| `.lc-v6-famille { padding: 9px 8px 12px }` | `:312` | `.lc-v6-famille { padding: 14px 14px 18px }` | `:322` | **`14px 14px 18px`** | ⛔ **MORTE** |
| `.lc-v6-famille { border-radius: 15px }` | `:312` | `.lc-v6-famille { border-radius: 20px }` | `:321` | **`20px`** | ⛔ **MORTE** |
| `.lc-v6-famille-nom { font-size: 13px }` | `:313` | `.lc-v6-famille-nom { font-size: clamp(16px, 1.5vw, 20px) }` | `:347` | **`16px`** | ⛔ **MORTE** |
| `.lc-v6-famille-desc { display: none }` | `:314` | (`:352-356` ne déclare pas `display`) | — | `none` | **VIVANTE** |

**Preuve mesurée** (`r14-dead.js`, `getComputedStyle` sur la 1ʳᵉ tuile, 9 largeurs) — la valeur calculée est identique de 320 à 410 px : `padding: 14px 14px 18px`, `border-radius: 20px`, `font-size: 16px`. **Aucune** des 3 ne change au franchissement de 400 px, ce qui est la signature d'une déclaration jamais appliquée.

**Conséquence visible n° 1 — une grille aux rangées inégales, c'est-à-dire du vide mort, exactement le défaut que la session traque depuis le cycle 10.** À 16 px dans une colonne de texte de 61 px (320) / 75 px (360), « MENU ENFANT » **passe à 2 lignes** (hauteur du libellé mesurée : **48 px** = 2 lignes, contre 24 px pour les 8 autres). La 3ᵉ rangée grandit donc seule :

| largeur | rangées **réelles** (actuel) | rangées avec les 3 déclarations **appliquées** (variante en mémoire) | vide mort |
|---|---|---|---|
| 320 | **[123, 123, 147]** | [119, 119, 119] | **+24 px** sous DESSERTS et BOISSONS |
| 360 | **[137, 137, 161]** | [133, 133, 133] | **+24 px** sous DESSERTS et BOISSONS |
| 375 | [142, 142, 142] | [138, 138, 138] | 0 |
| 390 | [147, 147, 147] | [143, 143, 143] | 0 |

Preuves lues, côte à côte : `shots/red14/fam-320.png` (« MENU / ENFANT » sur deux lignes, la rangée 3 dépasse ses voisines, deux tuiles portent une bande de crème vide) contre `shots/red14/intent-320.png` (mêmes 3 déclarations forcées par `addStyleTag`, **aucun fichier modifié**) : « MENU ENFANT » sur **une** ligne, **3 rangées strictement égales**, coins plus serrés, et les 9 photos **plus grandes**.

**Conséquence visible n° 2 — la photo produit est 16 à 20 % plus petite que voulu**, là où la place manque le plus :

| largeur | `.lc-v6-famille-art` actuel | avec le `padding: 8px` voulu | manque |
|---|---|---|---|
| 320 | **61 px** | 73 px | **−16 %** |
| 360 | **75 px** | 87 px | −14 % |
| 375 | **80 px** | 92 px | −13 % |
| 390 | **85 px** | 97 px | −12 % |
| 400 | **88 px** | 100 px | −12 % |

**Affirmation contradictoire.** Le commentaire du code (`:309` « on resserre plutôt que de casser la grille ») et le message de commit `b2a92d3` (« un resserrement sous 400 px (**gouttiere, padding**, description masquee) ») affirment tous deux un resserrement du **padding**. Le padding calculé ne bouge **pas d'un pixel**. La documentation décrit un correctif qui n'existe pas.

**Forme du méta-défaut** : « **une couche du dessous annule mon intention** » — au cycle 12 elle s'était manifestée entre deux *fichiers* (`styles-mobile.css` battant `styles-v6-brand.css`, d'où le `!important` assumé de `:359`). Ici elle se manifeste **à l'intérieur du même fichier**, par simple ordre d'écriture : le heal a inséré son bloc `@media` **au-dessus** des règles de base au lieu d'en-dessous. Et c'est aussi « **je n'ai pas vérifié ce que mon correctif produit** » : le cycle 13 a validé son heal sur les seuls critères « 3 colonnes / 0 orpheline / 0 rognage / hauteur régulière » — tous quatre satisfaits — **sans jamais lire une valeur calculée**, ce qui aurait pris une ligne de `getComputedStyle`.

**Correctif minimal suggéré (non appliqué — lecture seule)** : déplacer le bloc `@media (max-width: 400px)` **après** la règle `.lc-v6-famille-desc` (soit après `:356`), ou lui ajouter les `!important` nécessaires. Variante mesurée en mémoire : rangées [119,119,119] à 320 px, grille 372 px au lieu de 408, art 73 px au lieu de 61 — **0 rognage, 0 orpheline, 0 débordement**.

## C. RECENSEMENT MÉCANIQUE DES GRILLES — 13 largeurs × 3 routes = 39 passes

Méthode (`r14-census.js`, **aucun sélecteur écrit à la main**) : parcours de `document.querySelectorAll('*')`, conservation de tout élément dont le `display` calculé vaut `grid`/`inline-grid` **ou** `flex`/`inline-flex` avec `flex-wrap != nowrap`, ayant **≥ 3 enfants visibles** (hors `display:none`, `visibility:hidden`, `position:absolute/fixed`, boîte nulle). Rangées reconstruites par regroupement des `getBoundingClientRect().top` (tolérance 8 px). Hauteur **stabilisée**, `.lc-rv` révélés. Notation `enfants/colonnes` · `!ORPH` = dernière rangée à 1 seul enfant **et ≥ 2 colonnes** · `~` = enfants non divisibles par les colonnes.

| grille (chemin réel) | 320 | 360 | 375 | 390 | 400 | 401 | 412 | 430 | 480 | 520 | 768 | 1024 | 1440 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `section.lc-v6-hero > .lc-container > .lc-v6-preuves` | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/4 | 4/4 |
| `section.lc-section > .lc-container > .lc-why` (3) | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/3 | 3/3 | 3/3 |
| `section.lc-section > .lc-container > .lc-menu-grid` (home, 4) | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/2 | 4/2 | 4/4 |
| `section.lc-section > .lc-container > .lc-gallery` (5) | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 |
| `section.lc-section > .lc-container > .lc-v6-familles` (9) | **9/3** | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 |
| `.lc-container > .lc-rv > .lc-stats-grid` (3) | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/3 | 3/3 |
| `footer.lc-footer > .lc-container > .lc-footer-grid--4col` (**3 routes**) | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/4 | 4/4 | 4/4 |
| `.lc-show-mobile > .lc-cat-tabs` (menu, 10) | – | – | – | – | – | – | – | – | – | – | 10/5 | – | – |
| `.lc-menu-layout > div > .lc-menu-grid` (menu, **23 — N serveur**) | 23/1 | 23/1 | 23/1 | 23/1 | 23/1 | 23/1 | 23/1 | 23/1 | 23/1 | 23/1 | **23/2 !ORPH ~** | **23/2 !ORPH ~** | 23/4 ~ |
| `div > div > .lc-menu-grid` (menu, **5 boissons — N serveur**) | 5/1 | 5/1 | 5/1 | 5/1 | 5/1 | 5/1 | 5/1 | 5/1 | 5/1 | 5/1 | **5/2 !ORPH ~** | **5/2 !ORPH ~** | **5/4 !ORPH ~** |

**Lecture :**
- **10 sélecteurs distincts, les mêmes qu'au cycle 13 — aucune 11ᵉ grille, aucune nouvelle occurrence.** Le recensement du cycle 13 **tient** à son tour, ré-établi indépendamment et étendu à 5 largeurs de plus (375 / 390 / 400 / 401 / 430, les téléphones réels).
- **Les 8 grilles à compte FIXE : ZÉRO orpheline aux 13 largeurs.** `.lc-v6-familles` est désormais `[3,3,3]` **partout**, sans palier — le heal est arithmétiquement confirmé.
- **Les 2 seules grilles fautives restent les catalogues à N serveur**, déjà délégués : 23 items ⇒ `[2×11, 1]` à 768-1024, `[4×5, 3]` à 1440 ; 5 boissons ⇒ `[2,2,1]` à 768-1024, `[4,1]` à 1440. Aucun nombre de colonnes ne garantit l'absence d'orpheline pour un N quelconque ⇒ **hors critère, non compté**.
- Écart mineur de mesure avec le cycle 13 : `.lc-v6-preuves` passe à 4 colonnes à **1024** (le cycle 13 l'annonçait à 900) et `.lc-stats-grid` de même — sans conséquence, 0 orpheline dans les deux cas.
- **0 débordement de page sur les 39 passes** : `documentElement.scrollWidth == innerWidth` partout (320/320 … 1440/1440). **0 `pageerror`, 0 `console.error`.**

## D. DIMENSION JAMAIS TESTÉE — hauteur de CHAQUE section en fonction de la largeur

Méthode (`r14-census.js` puis `r14-cliff.js`) : énumération programmatique de tout `section`, `footer.lc-footer`, `header.lc-nav` et enfant direct de `.lc-app` visible, hauteur relevée par `getBoundingClientRect()` après stabilisation. **Une falaise = un rapport de hauteur > 1,08 entre deux largeurs distantes de ≤ 2 px.**

### D1 — Balayage large (13 largeurs × 3 routes)

| section (`home`, dans l'ordre du document) | 320 | 360 | 375 | 390 | 400 | **401** | 412 | 430 | 480 | 520 | 768 | falaise 1 px |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `header.lc-nav` | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 60 | 72 | — |
| `.lc-app > div` (enveloppe) | 8397 | 8382 | 8348 | 8361 | 8385 | 8633 | 8643 | 8701 | 8847 | 9090 | 8069 | ×1,03 |
| `section.lc-v6-hero` | 981 | 989 | 999 | 1009 | 997 | 998 | 1008 | 1025 | 1071 | 1090 | 1311 | — |
| `#3 section.lc-section` | 888 | 842 | 822 | 823 | 824 | 824 | 804 | 805 | 807 | 818 | 469 | — |
| `#4 section.lc-section` | 2624 | 2698 | 2736 | 2793 | 2831 | 2835 | 2858 | 2903 | 3090 | 3203 | 2075 | — |
| `#5 section.lc-section` | 385 | 383 | 391 | 399 | 405 | 405 | 411 | 421 | 445 | 459 | 608 | — |
| `#6 section.lc-section` | 785 | 761 | 761 | 702 | 702 | 702 | 702 | 702 | 677 | 677 | 739 | — |
| **`#7 section.lc-section` = « CE QU'ON CUISINE » (les 9 familles)** | 643 | 684 | 676 | 693 | **703** | **951** | 942 | 945 | 963 | 976 | 1117 | **×1,35** |
| `#8 section.lc-section` | 402 | 402 | 402 | 402 | 402 | 402 | 402 | 402 | 402 | 402 | 467 | — |
| `#9 section.lc-section` | 993 | 954 | 897 | 897 | 878 | 878 | 878 | 858 | 751 | 775 | 591 | — |
| `#10 section.lc-section` | 694 | 668 | 664 | 644 | 645 | 639 | 640 | 641 | 642 | 690 | 692 | — |
| `footer.lc-footer` | 948 | 932 | 932 | 932 | 932 | 932 | 932 | 932 | 932 | 932 | 393 | — |
| `menu` : `div.lc-section` | 11789 | 12536 | 12776 | 13055 | 13247 | 13249 | 13444 | 13804 | 14837 | 15640 | 7204 | — |
| `loyalty` : `div.lc-section` | 458 | 458 | 458 | 458 | 458 | 458 | 458 | 458 | 458 | 458 | 512 | — |

**Une seule discontinuité sur 1 pixel dans tout le périmètre : la section des familles, 703 → 951 px entre 400 et 401 px (×1,35, +248 px)** — c'est la réapparition de `.lc-v6-famille-desc`. Toutes les autres sections progressent régulièrement. Voir **P2-1**.

### D2 — Chasse aux falaises à TOUS les points de rupture (`r14-cliff.js`)
28 largeurs encadrant chaque palier (**399/400/401/402 · 519/520/521 · 599/600/601 · 699/700/701 · 767/768/769 · 899/900/901 · 1023/1024/1025 · 1099/1100/1101 · 1199/1200/1201**) × 2 routes. Seuil = rapport > 1,08 sur ≤ 2 px d'écart.

`home` — hauteur du document : 399:9451 · 400:9457 · **401:9705** · 402:9711 · 519:10156 · 520:10162 · 521:10167 · 599:10486 · 600:10492 · 601:10542 · 699:11171 · 700:10171 · **701:8467** · 767:8598 · 768:8614 · 769:8619 · 899:8177 · **900:7556** · 901:7560 · 1023:7914 · 1024:7918 · 1025:7921 · 1099:8169 · **1100:7896** · 1101:7900 · 1199:8157 · **1200:7397** · 1201:7399.

| section | falaise(s) détectée(s) | sens | jugement |
|---|---|---|---|
| `header.lc-nav` | 767→768 : 60→72 ×1,20 | grandit | palier de nav assumé, +12 px — **normal** |
| `section.lc-v6-hero` | 899→900 : 1440→1050 ×1,37 | **rétrécit** | passage empilé → côte-à-côte — **normal** |
| `#2 .lc-section` | 699→700 : 796→465 ×1,71 | **rétrécit** | 1 col → 3 col (`.lc-why`) — **normal** |
| `#3 .lc-section` | 700→701 : 3773→1992 ×1,89 · 1199→1200 : 1919→1156 ×1,66 | **rétrécit** | paliers de la grille produits — **normal** |
| `#4 .lc-section` | 1099→1100 : 721→446 ×1,62 | **rétrécit** | palier de la galerie — **normal** |
| **`#6 .lc-section` (les 9 familles)** | **400→401 : 703→951 ×1,35** | ⚠️ **GRANDIT** | seule falaise **croissante** notable → **P2-1** |
| `#7 .lc-section` | 600→601 : 402→446 ×1,11 · 899→900 : 491→257 ×1,91 | grandit (+44) / rétrécit | +44 px de padding, puis palier `.lc-stats-grid` — **normal** |
| `#8 .lc-section` | 699→700 : 756→623 ×1,21 | **rétrécit** | palier — **normal** |
| `footer.lc-footer` | 699→700 : 916→375 ×**2,44** · 700→701 : 375→415 ×1,11 | **rétrécit** | 1 col → 4 col, la plus grosse amplitude du site — **normal** (voir §E) |
| `menu` | uniquement `header.lc-nav` ×1,20 et `footer` ×2,44 | | rien de propre à la route |

**Principe de lecture retenu** : à un palier de colonnes, une section **rétrécit** quand l'écran s'élargit — c'est la définition d'une grille responsive, pas un défaut, quelle que soit l'amplitude. Le signal pathologique est l'inverse : une section qui **grandit** quand l'écran s'élargit. Sur les 28 largeurs et 2 routes, il n'y en a que **trois** : les familles (+248 px, ×1,35), et deux paliers de padding à +44 et +40 px (×1,11), négligeables. **0 débordement de page sur les 56 passes, 0 erreur JS.**

## E. TABLEAU D'EXHAUSTIVITÉ PAR CLASSE

`r14-audit.js` : **43 passes** = 3 routes × 11 largeurs (320/360/**375**/**390**/**400**/**401**/**430**/520/768/1024/1440) + **10 états conditionnels** à 1024 px. Hauteur stabilisée, `.lc-rv` révélés.

| classe traquée | méthode / volume | résultat |
|---|---|---|
| **Recensement mécanique des grilles** | 39 passes, énumération programmatique | **10 sélecteurs**, §C. 8 grilles à compte fixe : **0 orpheline sur 13 largeurs**. 2 catalogues à N serveur (délégués). |
| **Hauteur des sections × largeur** (dimension neuve) | 39 passes larges + 56 passes d'encadrement de palier | §D. **1 seule falaise croissante notable** : familles 400→401 ⇒ **P2-1**. Toutes les autres sont des rétrécissements de palier. |
| Contraste AA, fonds translucides **composités** | compositing rgba sur toute la pile d'ancêtres × opacité **cumulée**, sur chaque nœud-texte, 43 passes | **0 échec AA sur les 43 passes.** |
| `opacity` atténuant du TEXTE (cumulée) | produit des `opacity` de tous les ancêtres | 3 signalements à `cum = 0,997-0,998` (`.lc-card-item-name/desc/price` « Sprite 33cl ») sur 3 passes de `menu` — **transition `Reveal` encore en vol**, pas une atténuation de style. Conséquence de contraste : **nulle**. Aux 40 autres passes : **0**. |
| Régimes photo par conteneur | `object-fit` + `padding` calculés | **0 divergence** : hero `cover`, les 4 conteneurs produit en `contain` (`lc-featured-art` pad 25,8 · `lc-card-item-thumb` pad 22,1 · `lc-gallery-tile` pad 10,1 · `lc-v6-famille-art` pad 0). |
| `<img>` et replis sous **404 forcé** | interception de tous les `png/jpg/webp/svg` → 404, home @360 **et** @1440 | **20 images, 0 image cassée visible, 20 masquées, 9 replis emoji affichés.** Le repli tient aux deux largeurs. Grille = 448 px (360) / 1504 px (1440) : **plus de section interminable en repli** — la réserve P2-2 du cycle 13 est **levée** par le passage à 3 colonnes. |
| `alt` manquants | 9 signalements bruts sur la home | **FAUX POSITIFS, rejetés** : `screens.jsx:398` écrit `alt=""` délibérément (visuel décoratif) et le bouton porte `aria-label` (`:393`), vérifié sur les 9 tuiles. |
| Boutons / liens sans nom accessible | 43 passes | **0.** |
| Erreurs JS | 43 + 56 + 39 + 13 + 7 + 8 passes | **0 `pageerror`, 0 `console.error`** partout (hors les 404 provoqués). |
| Débordement de page | `documentElement.scrollWidth` vs `innerWidth` | **égal sur 100 % des passes.** Débordements **internes** seuls : `.lc-marquee-track` (4840→5926 px, marquee volontaire) et la bande `.lc-cat-tabs` de `menu` (+5 px à 520, +16 px sous 430) — déjà qualifiés de volontaires au cycle 12. |
| **Impression de chaque route** | `emulateMedia('print')` @794 + PDF A4 réel | `home` docH 8048 · `menu` 7753 · `loyalty` 1123, **0 erreur**. `.lc-hours` signalé `scrollWidth 811 > clientWidth 731` : **REJETÉ** — valeur **identique en média `screen`** (donc pas une régression d'impression) et **aucun texte coupé** (preuve lue `shots/red14/hours-print.png` : les 7 jours et les 7 plages `18h — 00h` sont **entiers et lisibles**). Idem `.lc-why-card` 263>233 (halo décoratif). |
| Impression de la **modale** | clic sur une carte produit @1024 puis média print | Le clic ouvre le **tiroir panier** (`.lc-cart-drawer`), qui reste `display:flex` à l'impression → **défaut déjà DÉLÉGUÉ** (« tiroir panier qui s'imprime »). La **fiche produit** n'a pas pu être ouverte par ce chemin ⇒ **non testé** (§H). |
| **Recherche vide** | `menu` @1024, saisie « zzzzqqq » | **État vide CORRECT** : « 0 résultat » + « 🔍 Rien trouvé » + « Essaye avec d'autres mots-clés ou retire un filtre. » + bouton « RÉINITIALISER », 0 carte, 0 erreur. (Un premier sondage cherchant « Aucun… » avait conclu à tort à l'absence de message — **faux positif rejeté**.) Preuve lue : `shots/red14/search-empty.png`. |
| Sauts de niveau de titre | 43 passes | 2 sauts, **tous deux déjà délégués** : `h2→h4` « LES CHAÎNES » (home, 11 largeurs) et `h1→h3` « LE CAYENNE » (loyalty, 11 largeurs). Aucun **nouveau**. |
| États conditionnels | `reduced-motion` · `prefers-contrast: more` · `forced-colors: active` · horloges figées **15h / 18h / 20h / 23h59 / 00h30 / 00h59 / 01h** | **10/10 : 0 échec AA, 0 texte atténué, 0 erreur JS, 0 débordement de page.** |
| Jargon d'atelier rendu | `NF525\|POS\|KDS\|borne\|backend\|frozen\|SSOT\|snapshot\|payload\|idempot\|branch_id\|wizard\|V1\|undefined\|NaN\|null\|[object\|Label.` sur le texte **rendu**, 43 passes | **0 occurrence.** |
| `!important` v1→v5/mobile contre l'intention v6 | 25 `!important` dans `styles-v6-brand.css` ; grep croisé des 7 feuilles pour `.lc-v6-famille*` | `.lc-v6-familles`, `.lc-v6-famille`, `-nom`, `-desc`, `-art` n'existent **que** dans `styles-v6-brand.css` — **aucune feuille du dessous ne peut les annuler**. ⚠️ Mais **le fichier s'annule LUI-MÊME** ⇒ **P1-1**. |
| `:nth-child` décoratifs | grep des 7 feuilles | `styles-v2.css:127-129` (dont `:nth-child(5n)` jaune vif) toujours neutralisé par `styles-v6-brand.css:514` `:nth-child(n)`. `styles-v2.css:415-424` (`.lc-value:nth-child(2/3)`, dont un `opacity: 0.7` sur un chiffre) = **code mort** : `.lc-value` n'apparaît dans **aucun** `.jsx` du site (grep vide) ⇒ hors sujet. |
| Compteur animé | lu **après** stabilisation | affiche **38**. ⚠️ Le **libellé** de ce 38 est faux ⇒ **P1-2**. |
| Jumeaux desktop/mobile | 13 largeurs × 10 grilles + 28 largeurs d'encadrement | Cohérents ; les seuls écarts sont les paliers, tous décroissants (§D2). |
| Densité d'écran | `deviceScaleFactor: 3` sur 320/375/390/412/430 et `dsf: 2` sur 768/1440 | Familles : **jamais agrandies** sur téléphone (×0,29 à ×0,44). À **1440 @ dsf 2** en revanche, les 9 `cat-*.png` (640 px) sont demandés à **802 px appareil (×1,25)** et la photo du hero (800×533) à **1498 px (×1,87)** ⇒ **P2-2**. |

## F. SECOND DÉFAUT NOUVEAU — PROUVÉ

### P1-2 · La home affiche « **38 · PLATS AU MENU** » alors qu'il y a **23 plats** : 15 des 38 sont des **canettes**. Le cycle 2 avait corrigé cette exacte affirmation — sur **une** occurrence sur deux
**Le fait.** `screens.jsx:425` : `{ n: W_ITEMS.length, s: '', l: 'Plats au menu' }`. `W_ITEMS.length = 38`. Comptage mécanique du SSOT (`node` sur `data/menu.js`, aucune saisie manuelle) :

| catégorie | 1 Sandwichs | 2 Galette | 4 Burgers | 5 Tacos | 6 Bols | 7 Frites | 9 Desserts | **10 Boissons** | 11 Menu enfant | total |
|---|---|---|---|---|---|---|---|---|---|---|
| articles | 4 | 2 | 6 | 2 | 2 | 2 | 3 | **15** | 2 | **38** |

**38 − 15 = 23 plats.** Preuve lue : `shots/red14/stats-1024.png` — la 3ᵉ tuile de chiffres affiche « **38** » sous-titrée « **PLATS AU MENU** ».

**Ce qui en fait un P1 et non une nuance.** Le codebase **documente lui-même** que cette affirmation est fausse. `screens.jsx:509-511` :
> `/* [S7 · RED cycle 2] « Tous faits maison » portait sur les 38 références, dont 15 sodas en canette : l'affirmation ne vaut que pour les plats. */`
> `<p>{W_CATS.length-1} catégories · {W_ITEMS.length} références. …</p>`

Le cycle 2 a donc **identifié le problème, l'a nommé, et a corrigé le mot sur la page `menu`** (« références ») — **en laissant intact le compteur de la home** qui continue d'appeler ces 38 articles des « plats ». Vérifié au runtime sur les deux routes : `menu` = « 9 catégories · **38 références** » ; `home` = « 38 / **PLATS AU MENU** ».

**Forme du méta-défaut** : « **correctif appliqué à une occurrence sur N** » — la forme n° 1 de la liste, celle que la session traque depuis le cycle 7. Et : « **je n'ai pas vérifié ce que mon correctif révèle** » du côté du cycle 13, qui a mesuré ce compteur (« Compteur animé : **38 plats**, conforme ») et l'a déclaré conforme **en recopiant le libellé fautif** au lieu de le confronter au comptage.

**Correctif minimal suggéré (non appliqué — lecture seule)** : soit le libellé (`'Références au menu'`), soit le nombre (`W_ITEMS.filter(i => i.category_id !== 10).length` ⇒ 23, avec « Plats au menu »). La seconde option est la seule qui reste vraie si la carte des boissons change.

## G. DÉFAUTS P2 — recensés, non comptés au verdict

### P2-1 · La bascule de `.lc-v6-famille-desc` à 401 px fait **grandir** la section de 248 px sur un pixel (×1,35), et rend la rangée 1 inégale
- Mesuré : section 703 → **951 px** entre 400 et 401 px ; grille 464 → **712 px** ; rangées `[150,150,150]` → **`[252, 218, 218]`** (la description de SANDWICHS tient sur **5 lignes** dans une colonne de 85 px, contre 3-4 pour les autres). Preuves lues : `shots/red14/fam-400.png` (grille compacte et régulière) contre `shots/red14/fam-401.png` (colonnes de texte étroites et déchiquetées, rangée 1 plus haute que les deux autres).
- **P2 et non P1** : l'amplitude est de **+248 px sur une page de 9 500** (×1,35), soit **1/26ᵉ** de la falaise que le cycle 13 avait à juste titre classée P1 (+3 309 px, ×6,5) ; le sens est certes le mauvais (la page grandit quand l'écran s'élargit) mais aucun contenu n'est perdu, rogné ni illisible, et un seuil de `display:none` produit **mécaniquement** une discontinuité — la supprimer demanderait de renoncer au masquage, pas de corriger un bug.
- **Réserve de jugement** : 401 px est un seuil **trop bas**. À 401-430 px la description est servie dans 85-95 px de large, ce qui la rend plus pénible à lire que son absence. Un seuil à ~520 px (colonne de 121 px) supprimerait à la fois la falaise sur les téléphones et la colonne déchiquetée.

### P2-2 · Sur un portable Retina (1440 px @ `dsf 2`), la photo du hero est demandée à **×1,87** de sa source et les 9 visuels de famille à **×1,25**
- Mesuré : `sandwich-cayenne.png` = **800×533** natif, boîte 749×499 CSS ⇒ **1 498 px appareil** ; les 9 `cat-*.png` = 640 px natifs, boîte 401 px ⇒ **802 px appareil**. Preuves lues : `shots/red14/hero2-1440-dsf2.png` (léger flou visible sur la mie et la salade) et `shots/red14/fam-tile-1440-dsf2.png` (tuile nette, l'agrandissement ×1,25 est imperceptible).
- **P2** : ×1,25 est invisible ; ×1,87 sur le hero est perceptible mais reste une **photo utilisable**, et le défaut **préexiste au heal du cycle 13** (le hero n'a jamais dépendu du nombre de colonnes). Classe « autre densité » du méta-défaut, jamais mesurée avant ce cycle du côté desktop. Correctif = fournir un `@2x` ou un `srcset`, décision d'assets ⇒ owner.

## H. SUPPOSÉ / non retenu au verdict
- **Catalogues à N serveur** (`menu`) : 23 items ⇒ `[2×11, 1]` à 768-1024 et `[4×5, 3]` à 1440 ; 5 boissons ⇒ `[2,2,1]` à 768-1024 et `[4,1]` à 1440. **Non compté** (N vient du serveur).
- **Le pied de page fait 932-948 px sur TOUS les téléphones** (contre 375-415 px dès 700 px) : c'est la plus grosse amplitude de palier du site (×2,44). Même schéma que l'ancien défaut des familles en 1 colonne, mais sur un pied de page — où l'empilement est l'usage universel, et où le contenu (4 blocs de liens) ne se prête pas à 3 colonnes sur 320 px. **Pas un défaut prouvable**, dette de rythme.
- **Paliers hétérogènes** : familles = aucun (3 col. partout), `.lc-why` + pied de page = 700, `.lc-menu-grid` = 768 puis 1200, `.lc-stats-grid` = 1024, `.lc-v6-preuves` = 1024, `.lc-gallery` = 1100, nav = 768. Sept seuils différents sur une même page. Aucun n'est fautif isolément ; la cohérence de rythme reste une dette éditoriale (relevée dès le cycle 13).
- **Description masquée sous 400 px** : perte d'information réelle mais **symétrique** (écran et lecteur d'écran reçoivent la même chose) et non compensée par l'`aria-label`. Décision éditoriale, pas un défaut d'a11y.
- **`.lc-marquee-track`** : `scrollWidth` 4 840-5 926 contre 288-1 325 de conteneur — débordement **voulu**, coupé sous `reduced-motion`.
- **`.lc-hours` 811 > 731 et `.lc-why-card` 263 > 233** en `overflow: hidden` : identiques en `screen` et en `print`, **aucun texte coupé** (captures lues). Rejetés.
- **3 textes à opacité cumulée 0,997-0,998** sur `menu` : transition `Reveal` en vol au moment de la mesure. Rejeté (artefact de mesure).
- **Deux directions artistiques des PNG de galerie sur papier** (4 en `colortype 2` fond noir cuit + 1 en `colortype 6` détouré) : déjà déposé aux cycles 12-13 au jugement éditorial de l'owner.

## I. HORS PÉRIMÈTRE / DÉLÉGUÉS — recensés, non comptés
Heure réelle de fermeture (owner) · CGV (barème fidélité, art. 5 livraison, art. 7 horaires) · `legal/allergens.html` · `legal/privacy.html` (« NF525/POS/KDS ») · FAQ « Pas de débit en ligne » · `//` du tunnel / compte / commandes / wizard / backend · modale de compte « 1 € → 1 PT » · `cat-tacos.png` (trous de détourage) · absence de valeurs nutritionnelles · absence de 404 de marque · jargon « V1 » légal · pastille panier comptant les lignes · panneau droit vide de la fiche · **saut `h2→h4` du comparatif** (reconfirmé sur 11 passes home) · double bouton « effacer » de la recherche · divergence du pied de page légal · **tiroir panier qui s'imprime** (reconfirmé : `display:flex` en média print) · `→` du tiroir burger à 3,68:1 · **saut `h1→h3` de la page Fidélité** (reconfirmé sur 11 passes loyalty) · tiroir burger à ~50 % de vide · absence de repli sur la photo du hero · focus de la 3ᵉ tuile de galerie quasi hors bande · survol `scale(1.03)` rogné · catalogues à N serveur · deux directions artistiques des PNG sur papier.

## J. NON TESTÉ
- **Fiche produit (modale)** : le clic programmatique sur une carte a ouvert le tiroir panier ; la modale n'a pas été atteinte par ce chemin. Couverte aux cycles 11-12 (0 échec AA, impression sur 1 page), **non rejouée ici**.
- **Rupture réelle** (`unavail` serveur) : simulée au cycle 12 (verts), **non rejouée**.
- **Pages légales** : style validé aux cycles 11-12 sur 5 pages × 2 largeurs, **non rejoué** (aucune modification depuis).
- **Impression physique réelle** (vérifiée par `emulateMedia` + PDF A4 Chromium), navigateurs autres que Chromium, **geste tactile réel** sur la bande de galerie.
- Page Fidélité **connectée** (hors périmètre).

## VERDICT S7 — **NON CONVERGÉ** : P0 = 0, **P1 = 2**

**P1-1** — `styles-v6-brand.css:310-315` : le bloc `@media (max-width: 400px)` du heal du cycle 13 est écrit **au-dessus** des règles de base qu'il prétend surcharger (`:321`, `:322`, `:347`), à spécificité identique. **3 de ses 5 déclarations ne s'appliquent jamais** : `padding: 9px 8px 12px` (calculé `14px 14px 18px`), `border-radius: 15px` (calculé `20px`), `font-size: 13px` (calculé `16px`). Conséquences mesurées : « MENU ENFANT » passe à 2 lignes à 320 et 360 px ⇒ rangées **[123,123,147]** et **[137,137,161]** au lieu de trois rangées égales (24 px de crème morte sous deux tuiles) ; la photo produit est **12 à 16 % plus petite** que voulu. Et le commentaire `:309` comme le commit `b2a92d3` **affirment** un resserrement du padding qui n'existe pas. Forme : « **une couche annule mon intention** », ici à l'intérieur du même fichier. Preuves lues : `fam-320.png` vs `intent-320.png`, `fam-360.png`.

**P1-2** — `screens.jsx:425` : la home affiche « **38 · PLATS AU MENU** » alors que le SSOT `data/menu.js` contient **15 boissons sur 38 articles**, soit **23 plats**. Le cycle 2 avait diagnostiqué exactement cette sur-promesse et **n'a corrigé que la page `menu`** (« 38 références ») — son propre commentaire `screens.jsx:509-510` le documente. Forme : « **correctif appliqué à une occurrence sur N** ». Le cycle 13 a validé ce compteur comme « conforme » en recopiant son libellé. Preuve lue : `stats-1024.png`.

**Le heal du cycle 13 est fonctionnellement CONFIRMÉ sur son objectif principal** : `.lc-v6-familles` est en `[3,3,3]` aux **13** largeurs, **0 orpheline, 0 rognage, 0 débordement**, la falaise de **6,5×** est morte (progression 408 → 1 504 px), les visuels ne sont **plus agrandis** sur téléphone (×0,29 à ×0,44 à `dsf 3`), la lisibilité à 320 px est **bonne** sur capture lue, et le mode repli 404 ne produit plus de section interminable. Le recensement mécanique des grilles **tient pour la 2ᵉ fois de suite** (10 sélecteurs, aucune 11ᵉ). Le moyen employé, lui, est aux trois cinquièmes inopérant.

Tendance des cycles 5→14 : 5, 2, 2, 2, 3, 2, 3, 2, 1, 1, 3, 2, 1, **2**.
Tout le reste est **convergé et le reste** : 0 échec AA composité sur 43 passes, 0 texte atténué de style, 0 erreur JS sur ~160 passes, 0 débordement de page, 0 image cassée, 9 replis fonctionnels aux 2 largeurs, 0 jargon, 0 régime photo divergent, impression lisible sur les 3 routes (horaires entiers sur papier), état de recherche vide correct, `reduced-motion` / `prefers-contrast: more` / `forced-colors` respectés, 7 horloges cohérentes.
