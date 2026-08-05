# V5 — RED-TEAM CYCLE 13 (lecture seule) — vitrine S7 « Le Cayenne »
Date : 2026-07-30 · HEAD site : `969a774` (heals du cycle 12)
Périmètre : `screens.jsx`, `components.jsx`, `screens-v3.jsx`, `styles-v6-brand.css`, `assets/**`, STYLE des pages légales.
Captures : `reports/goal-s7-vitrine/shots/red13/` · Scripts : `~/.claude/jobs/1269b1ef/tmp/r13-*.js`
Aucun fichier du site n'a été modifié (les variantes ont été mesurées par `addStyleTag` au runtime, en mémoire).

## 0. CONTRÔLE DE SANTÉ — OK, aucune récidive de page blanche
`r13-sante.js`, 3 routes, hauteur **stabilisée** (boucle de défilement jusqu'à 2 mesures identiques) :

| route | `#root` enfants | texte rendu | nav | hero | `h1` | `pageerror` | `console.error` | scrollWidth/innerWidth |
|---|---|---|---|---|---|---|---|---|
| `home` | 1 | 5498 car. | oui | oui | « FAIT MAISON, CHAQUE SOIR. » | 0 | 0 | 1440/1440 |
| `menu` | 1 | 3201 car. | oui | n/a | « TOUT CE QU'ON CUISINE. » | 0 | 0 | 1440/1440 |
| `loyalty` | 1 | 771 car. | oui | n/a | « CONNECTE-TOI POUR CUMULER. » | 0 | 0 | 1440/1440 |

Titre = « Le Cayenne — Site officiel ». Preuve lue : `shots/red13/sante-home.png`.

## A. ÉTAT DES 2 HEALS DU CYCLE 12

### A1 — `.lc-v6-familles` : l'orpheline est bien SUPPRIMÉE, mais le correctif choisi **rend la section interminable sur téléphone** → **P1-1** (voir §B)
`styles-v6-brand.css:298` = `grid-template-columns: 1fr`, `:303-305` = `@media (min-width: 520px) { repeat(3, …) }`, `:431-433` = `@media (min-width: 700px) { repeat(3, …); gap:16px }`.
Mesuré (`r13-fam.js`, hauteur stabilisée, `.lc-rv` révélés) :

| largeur | 320 | 360 | 480 | 520 | 600 | 768 | 1024 | 1440 |
|---|---|---|---|---|---|---|---|---|
| colonnes | **1** | **1** | **1** | 3 | 3 | 3 | 3 | 3 |
| rangées | 9×[1] | 9×[1] | 9×[1] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] | [3,3,3] |
| tuile orpheline | non | non | non | non | non | non | non | non |
| hauteur de la tuile | 358 | 398 | 501 | 255 | 262 | 311 | 373 | 491 |
| hauteur de la grille | **3 214** | **3 557** | **4 603** | 754 | 794 | 932 | 1 134 | 1 504 |
| texte rogné | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |

**Verdict du heal : l'orpheline est fermée sur les 12 largeurs (voir §C, recensement mécanique) — mais l'effet de bord est majeur.** Détail et preuves lues en **P1-1**.

### A2 — Impression de la galerie : **HEAL CONFIRMÉ**, l'écran n'est pas cassé
`styles-v6-brand.css:579-591` (dans `@media print`) : `display:grid !important; grid-auto-flow:row !important; grid-template-columns: repeat(5, minmax(0,1fr)) !important; overflow: visible !important`.
Mesuré (`r13-print.js`, largeur réellement visible de chaque tuile dans le conteneur) :

| largeur | média | `grid-auto-flow` | `overflow-x` | clientW / scrollW | largeurs visibles des 5 tuiles | 5 photos entières |
|---|---|---|---|---|---|---|
| **794 (A4)** | écran | column | auto | 731 / 1744 | [336, 336, **26**, **0**, **0**] | non (défilement) |
| **794 (A4)** | **print** | **row** | **visible** | 731 / **731** | **[133, 133, 133, 133, 133]** | **OUI** ✅ |
| 1024 | écran | column | auto | 942 / 2231 | [433, 433, 43, 0, 0] | non (défile ✅) |
| 1024 | **print** | row | visible | 942 / 942 | **[176, 176, 176, 176, 176]** | **OUI** ✅ |
| 360 | écran | column | auto | 328 / **786** | [151, 151, 10, 0, 0] | non (défile ✅) |
| 360 | **print** | row | visible | 328 / 328 | **[59, 59, 59, 59, 59]** | **OUI** ✅ |

- **Papier** : les 5 photos sont **entières**. Preuve lue : `shots/red13/print-gal-794-crop.png` (les 5 vignettes alignées, aucune coupe) et le PDF réel `shots/red13/home-print.pdf` (A4, `printBackground:false`).
- **Écran non cassé** : la bande **défile toujours** sous 1100 px (`scrollWidth` > `clientWidth` à 360 / 794 / 1024), la dernière tuile reste coupée au bord (affordance voulue), `scroll-snap-align` intact. 0 `pageerror` sur les 6 passes.
- La branche est bien **enfermée dans `@media print`** : les `!important` n'atteignent jamais l'écran (vérifié : à 794 px en média `screen`, `grid-auto-flow` vaut toujours `column`).
- **Réserve** : ce que le correctif **RÉVÈLE** sur le papier → **P2-1** ci-dessous.

## B. DÉFAUT NOUVEAU — PROUVÉ

### P1-1 · Le heal du cycle 12 a échangé une demi-rangée vide contre **+2 000 à +3 600 px de défilement sur TOUS les téléphones** — forme « mon correctif casse ailleurs (autre largeur) »
**Le fait.** `styles-v6-brand.css:298` pose `grid-template-columns: 1fr` pour `.lc-v6-familles` sous 520 px. La tuile de famille contient un `.lc-v6-famille-art` en `width:100%` + `aspect-ratio: 1/1` (`styles-v6-brand.css:322-330`) : en 1 colonne, **la photo devient un carré de la largeur de l'écran**. Les 9 familles — qui ne sont que des **raccourcis de navigation vers la carte** — deviennent 9 photos plein cadre.

**Mesures comparatives** (`r13-famalt.js` — les 3 variantes mesurées dans le MÊME navigateur par `addStyleTag` au runtime ; **aucun fichier du site modifié**) :

| largeur | variante | rangées | tuile | hauteur de la grille | hauteur de la home | texte rogné |
|---|---|---|---|---|---|---|
| 320 | **actuel (1 col.)** | 9×[1] | 288×358 | **3 214 px** | **12 291 px** | 0 |
| 320 | avant heal (2 col.) | [2,2,2,2,**1**] | 138×241 | 1 187 px | 10 264 px | 0 |
| 320 | hypothèse 3 col. | [3,3,3] | 88×242 | 722 px | 9 799 px | 0 |
| 360 | **actuel (1 col.)** | 9×[1] | 328×398 | **3 557 px** | **12 562 px** | 0 |
| 360 | avant heal (2 col.) | [2,2,2,2,**1**] | 158×261 | 1 236 px | 10 242 px | 0 |
| 360 | hypothèse 3 col. | [3,3,3] | 101×255 | 746 px | 9 751 px | 0 |
| 480 | **actuel (1 col.)** | 9×[1] | 448×501 | **4 603 px** | **13 782 px** | 0 |
| 480 | avant heal (2 col.) | [2,2,2,2,**1**] | 218×304 | 1 486 px | 10 664 px | 0 |
| 480 | hypothèse 3 col. | [3,3,3] | 141×245 | 741 px | 9 919 px | 0 |
| **519** | **actuel (1 col.)** | 9×[1] | 478×530 | **4 869 px** | **14 271 px** | 0 |
| **520** | actuel (3 col.) | [3,3,3] | 151×255 | **754 px** | 10 162 px | 0 |

**Trois faits qui font le P1 :**
1. **Falaise de 1 pixel** : à 519 px la section fait **4 869 px**, à 520 px elle fait **754 px** — un rapport de **6,5×** de part et d'autre d'un seul pixel de largeur. Ce n'est pas une adaptation, c'est une rupture.
2. **La bande 1 colonne couvre 100 % des téléphones réels** : iPhone SE 375, iPhone 14 390, Pixel 412, iPhone Pro Max 430 — **tous** sous 520 px. La grille compacte 3×3, seule version tenable, n'est servie qu'aux tablettes et aux ordinateurs. Le heal a donc optimisé la largeur où le défaut ne se voyait pas et dégradé celle où il se voit.
3. **Le prix payé est disproportionné** : la tuile orpheline supprimée mesurait **241 px de crème** (mesuré, 320 px) ; le remplacement ajoute **+2 027 px** (320), **+2 321 px** (360), **+3 117 px** (480), **+3 309 px** (519) — soit **2,3 à 3,7 écrans de 900 px** de défilement supplémentaire, dans une section qui n'est qu'un sommaire.

**Jugement à l'œil, sur captures LUES** (la question posée : « acceptable ou interminable ? ») :
- `shots/red13/fam-360.png` (section de **3 793 px** = 4,2 écrans) : neuf photos de plat de 328 px de côté empilées, chacune surmontant deux lignes de texte minuscules. Le sommaire « Neuf familles » se lit comme un **fil de catalogue produit** : rien ne distingue plus cette section de la vraie carte, et il faut la traverser entièrement pour atteindre le reste de la home. **Interminable, oui.**
- `shots/red13/fam-480.png` (section de **4 825 px** = 5,4 écrans) : même effet, amplifié — les photos font 448 px de côté.
- `shots/red13/fam-520.png` (section de **976 px**) : grille 3×3 nette, les 9 familles se lisent **d'un seul regard**, texte complet, aucune coupe. **C'est la version juste — et elle est refusée aux téléphones.**
- `shots/red13/hypo3col-360.png` : la variante 3 colonnes tient à 360 px (**746 px** de grille, **0** texte rogné mesuré sur les 9 noms et les 9 descriptions) — la preuve qu'une option compacte existait et n'a pas été essayée.

**Forme du méta-défaut** : « **mon correctif casse ailleurs (autre largeur)** ». Le cycle 12 a vérifié son correctif sur le seul critère qu'il s'était donné (« 0 orphelin vérifié sur 8 largeurs ») **sans mesurer la hauteur qu'il produisait** — exactement le reproche qu'il adressait au cycle 11 sur l'impression. La justification écrite dans le commit (« 9 ne tombe juste qu'en 1 ou 3 colonnes ») est arithmétiquement vraie et **conduit pourtant au pire des deux choix** : 1 colonne était l'option disponible la moins adaptée à une grille de vignettes carrées.

**Correctif minimal suggéré (non appliqué — lecture seule)** : passer à `repeat(3, minmax(0,1fr))` dès 360 px (mesuré : 0 rognage, grille de 746 px), ou garder 1 colonne mais casser le `aspect-ratio: 1/1` en dessous de 520 px (vignette en ligne à gauche du texte, ~96 px) — les deux ferment l'orpheline SANS la falaise.

## C. RECENSEMENT MÉCANIQUE EXHAUSTIF DES GRILLES — tableau complet
Méthode (`r13-census.js`, **aucun sélecteur écrit à la main**) : parcours de `document.querySelectorAll('*')` sur les **3 routes** du périmètre, conservation de tout élément dont le `display` calculé vaut `grid`/`inline-grid` **ou** `flex`/`inline-flex` avec `flex-wrap != nowrap`, ayant **≥ 3 enfants visibles** (hors `display:none`, hors `position:absolute/fixed`, hors boîte nulle). Les rangées réelles sont reconstruites par **regroupement des `getBoundingClientRect().top`** (tolérance 8 px), donc mesurées et non déduites du CSS. Hauteur **stabilisée** avant mesure, `.lc-rv` forcés révélés.
**12 largeurs × 3 routes = 36 passes.** Notation `enfants/colonnes` · `!ORPH` = dernière rangée à 1 seul enfant · `~` = enfants non divisibles par le nombre de colonnes.

**10 sélecteurs distincts trouvés** (le recensement précédent en annonçait moins) :

| grille (chemin réel) | 320 | 360 | 480 | 520 | 600 | 700 | 768 | 900 | 1024 | 1100 | 1200 | 1440 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `section.lc-v6-hero > .lc-container > .lc-v6-preuves` | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/2 | 4/4 | 4/4 | 4/4 | 4/4 | 4/4 |
| `section.lc-section > .lc-container > .lc-menu-grid` (home, 4 produits) | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/2 | 4/2 | 4/2 | 4/2 | 4/4 | 4/4 |
| `section.lc-section > .lc-container > .lc-gallery` (5 photos) | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 | 5/5 |
| `section.lc-section > .lc-container > .lc-v6-familles` (9 familles) | **9/1** | **9/1** | **9/1** | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 | 9/3 |
| `section.lc-section > .lc-container > .lc-why` (3 tuiles) | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/3 | 3/3 | 3/3 | 3/3 | 3/3 | 3/3 | 3/3 |
| `.lc-container > .lc-rv > .lc-stats-grid` (3 stats) | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/1 | 3/3 | 3/3 | 3/3 | 3/3 | 3/3 |
| `footer.lc-footer > .lc-container > .lc-footer-grid--4col` (3 routes) | 4/1 | 4/1 | 4/1 | 4/1 | 4/1 | 4/4 | 4/4 | 4/4 | 4/4 | 4/4 | 4/4 | 4/4 |
| `.lc-show-mobile > .lc-cat-tabs` (menu, 10 onglets) | – | – | – | – | – | – | 10/5 | – | – | – | – | – |
| `.lc-menu-layout > div > .lc-menu-grid` (menu, **23 items — N serveur**) | 23/1 | 23/1 | 23/1 | 23/1 | 23/1 | 23/1 | **23/2 !ORPH~** | **23/2 !ORPH~** | **23/2 !ORPH~** | **23/2 !ORPH~** | 23/4 ~ | 23/4 ~ |
| `div > div > .lc-menu-grid` (menu, **5 boissons — N serveur**) | 5/1 | 5/1 | 5/1 | 5/1 | 5/1 | 5/1 | **5/2 !ORPH~** | **5/2 !ORPH~** | **5/2 !ORPH~** | **5/2 !ORPH~** | **5/4 !ORPH~** | **5/4 !ORPH~** |

**Lecture du tableau :**
- **Grilles à compte FIXE contrôlé par l'auteur (8 sur 10) : ZÉRO orpheline sur les 12 largeurs.** `.lc-v6-preuves` [2,2] puis [4] · `.lc-menu-grid` home [1]×4 / [2,2] / [4] · `.lc-gallery` bande puis [5] · `.lc-v6-familles` [1]×9 puis [3,3,3] · `.lc-why` [1]×3 puis [3] · `.lc-stats-grid` [1]×3 puis [3] · `.lc-footer-grid--4col` [1]×4 puis [4] · `.lc-cat-tabs` [5,5]. Le critère posé au cycle 10 est **enfin appliqué partout** : le heal A1 est **arithmétiquement confirmé**, et le recensement du cycle 12 (« le recensement est cette fois clos ») **tient** — je n'ai pas trouvé de 5ᵉ occurrence.
- **Les 2 seules grilles fautives sont les catalogues à N serveur**, déjà délégués (voir §E) : 23 items et 5 boissons. Mon balayage **étend** le constat du cycle 12, qui n'avait relevé le groupe des 5 boissons qu'à 1200/1440 px : il est **aussi** orphelin à 768/900/1024/1100 px (2 colonnes ⇒ `[2,2,1]`), et le catalogue de 23 items l'est aux 4 mêmes largeurs (`[2×11, 1]`). Aucun nombre de colonnes ne peut garantir l'absence d'orpheline pour un N quelconque ⇒ **hors critère, non compté**.
- **Nouveauté du balayage** : `.lc-cat-tabs` n'apparaît qu'à 768 px (au-dessus, `.lc-show-mobile` passe en `display:none` ; en dessous, `flex-wrap: nowrap` ⇒ hors critère). C'est une bande à défilement volontaire, `[5,5]`, sans orpheline.

## B2. DÉFAUTS NOUVEAUX — P2 (recensés, non comptés au verdict)

### P2-1 · Ce que le heal d'impression **RÉVÈLE** : les 5 photos de galerie sortent en **deux directions artistiques** sur le papier
- Le heal du cycle 12 rend enfin visibles les tuiles 3, 4 et 5 à l'impression. Or `@media print` neutralise tous les fonds CSS (`styles-v6-brand.css:528+`), y compris le dégradé nocturne de `.lc-gallery-tile` (`:270-272`) qui **unifiait** les 5 photos à l'écran.
- Vérifié dans les fichiers (type de couleur PNG lu à l'octet 25 de l'IHDR) : `sandwich-cayenne.png`, `cheese-burger.png`, `sandwich-mega.png`, `sandwich-terminator.png` = **colortype 2 (RGB, sans canal alpha ⇒ fond studio noir cuit dans le fichier)** ; `chicken_burger.png` = **colortype 6 (RGBA, détouré)**.
- Preuve lue : `shots/red13/print-gal-794-crop.png` — sur la même rangée imprimée, **4 vignettes sont des rectangles noirs** et **la 5ᵉ flotte sans cadre sur le blanc**. Elle se lit comme un défaut d'impression.
- **C'est exactement la forme « je n'ai pas vérifié ce que mon correctif RÉVÈLE »** : avant le heal, les tuiles 3-5 n'étaient tout simplement pas imprimées, donc la divergence n'existait pas sur papier. Et c'est le **même défaut d'art direction** que la session s'était elle-même reproché au cycle 3 (`styles-v6-brand.css:262-267` : « Deux directions artistiques dans le même composant »), cette fois réalisé à l'impression.
- **P2 et non P1** : rien n'est illisible, rien n'est masqué, aucun échec de contraste, et l'écran est intact. Le cycle 12 avait déjà déposé « fonds studio noirs à l'impression » au jugement éditorial de l'owner.

### P2-2 · En 1 colonne, l'état de **repli d'image** produit 3 557 px de cartes quasi vides
`r13-extras.js`, 404 forcé sur `**/assets/**` à 360 px : **20 images, 0 image cassée visible, 14 replis affichés** (5 🌶️ galerie + 9 emojis familles) — **le repli fonctionne**. Mais `.lc-v6-familles` conserve ses **3 557 px** : neuf cartes blanches de 398 px contenant chacune un emoji de 44 px flottant dans un carré vide de 328 px. Preuve lue : `shots/red13/404-360.png`. Aggravant de **P1-1**, pas un défaut autonome (le repli existe et tient).

### P1-1 — deux faits aggravants mesurés (après-coup, sur appareil réaliste)
1. **Les photos passent au-dessus de leur résolution native sur un vrai téléphone.** `r13-dsf.js`, viewport iPhone 14 (390 CSS px, `deviceScaleFactor: 3`) :

| variante | boîte CSS | pixels appareil demandés | source native | facteur |
|---|---|---|---|---|
| **actuel (1 col.)** | 328 px | **984 px** | 640 px | **×1,54 (agrandissement)** |
| 3 colonnes | 81 px | 244 px | 640 px | ×0,38 (réduction, net) |

Les 9 visuels `assets/menu/cat-*.png` font 640×640 (`cat-menu-enfant.png` 800×800) : en 1 colonne, l'appareil doit **inventer 54 % de pixels**. À `dsf=1` le facteur reste < 1 (0,47 à 360 px, 0,65 à 480 px) — c'est pourquoi une mesure à `deviceScaleFactor: 1` **ne voit pas** ce défaut. Preuve lue : `shots/red13/dsf3-1col.png`.

2. **La carte est majoritairement VIDE.** Même capture : `.lc-v6-famille-art` porte `aspect-ratio: 1/1`, mais les visuels de catégorie ont un **sujet en format paysage centré dans un carré**. En 1 colonne le carré vaut 328 px de côté : le sandwich n'en occupe qu'environ le tiers médian, laissant ≈ **200 px de blanc par carte, ×9**. Une seule tuile occupe **47 % de la hauteur d'écran** d'un iPhone 14 — et l'essentiel de cette tuile est du vide. En 3 colonnes le même carré fait 81-151 px et le vide devient un simple respirat.

## D. TABLEAU D'EXHAUSTIVITÉ PAR CLASSE

Balayage `r13-audit.js` : **28 passes** = 3 routes × 6 largeurs (320 / 360 / **520** / 768 / 1024 / 1440) + **10 états conditionnels** à 1024 px. Hauteur stabilisée avant chaque mesure, `.lc-rv` forcés révélés.

| classe traquée | méthode / volume | résultat |
|---|---|---|
| **Recensement mécanique des grilles** | 36 passes, énumération programmatique de tout `display:grid`/`flex-wrap≠nowrap` à ≥3 enfants, rangées reconstruites par `getBoundingClientRect().top` | **10 sélecteurs**, tableau complet en **§C**. 8 grilles à compte fixe : **0 orpheline sur 12 largeurs**. 2 catalogues à N serveur fautifs (délégués). |
| Contraste AA, fonds translucides **composités** | compositing rgba sur toute la pile d'ancêtres × **opacité cumulée**, sur chaque nœud-texte ; 28 passes | **0 échec AA sur les 28 passes.** |
| `opacity` atténuant du TEXTE (cumulée) | produit des `opacity` de tous les ancêtres | **0 texte à opacité cumulée < 1**, sur les 28 passes. |
| Régimes photo par conteneur | `object-fit` + `padding` calculés au runtime | **0 divergence** : hero `cover` (ratio identique), les 4 conteneurs produit en `contain`. `lc-v6-famille-art > img` = `contain`, `pad=0`. |
| `<img>` et replis sous **404 forcé** | interception `**/assets/**` → 404, home @360 (= mode 1 colonne) | **20 images, 0 image cassée visible, 14 replis affichés** (5 🌶️ galerie + 9 emojis familles). Le repli **survit** au mode 1 colonne. Réserve de mise en page ⇒ **P2-2**. |
| `alt` manquants | 9 signalements bruts par la sonde sur la home | **FAUX POSITIFS, rejetés** : `screens.jsx:398` écrit `alt=""` **délibérément** (visuel décoratif) et la tuile porte `aria-label={c.name + ' — ouvrir le menu complet'}` (`:393`). Ma sonde traitait `alt=""` comme absent. Usage **correct**. |
| Boutons / liens sans nom accessible | `a, button, [role=button]` sur 28 passes | **0.** |
| Erreurs JS | 28 passes + 6 passes d'impression + 6 passes de recensement de rythme + 404 + `dsf=3` | **0 `pageerror`, 0 `console.error`** (hors les 404 que J'AI provoqués). |
| Débordement de page | `documentElement.scrollWidth` vs `innerWidth`, 28 passes | **égal partout** (320/320 … 1440/1440). Seuls débordements **internes** : `.lc-marquee-track` (marquee volontaire) et la bande `.lc-cat-tabs` de `menu` — voir §E. |
| Impression de **chaque route** | `emulateMedia('print')` @794 sur `home`, `menu`, `loyalty` | `menu` : `docH=7753`, **0 conteneur qui rogne**, 0 erreur — preuve lue `print-menu.png` (carte lisible, filtres et prix intacts). `loyalty` : `docH=1100`, 0 rognage. `home` : galerie **réparée** (§A2), mais art direction révélée ⇒ **P2-1**. |
| Sauts de niveau de titre | 28 passes | 2 sauts, **tous deux déjà délégués** : `h2→h4` « LES CHAÎNES » (home) et `h1→h3` « LE CAYENNE » (loyalty). Aucun **nouveau**. |
| États conditionnels | `reduced-motion` · `prefers-contrast: more` · `forced-colors: active` · horloges figées **15h / 18h / 20h / 23h59 / 00h30 / 00h59 / 01h** | **10/10 : 0 échec AA, 0 texte atténué, 0 erreur JS, 0 débordement de page.** |
| Jargon d'atelier rendu | `NF525|POS|KDS|borne|backend|frozen|SSOT|snapshot|payload|idempot|branch_id|wizard|V1|undefined|NaN|null|[object|Label.` sur le texte **rendu** des 3 routes | **0 occurrence** (le faux positif « TU TE **POS**ES » des cycles précédents n'apparaît plus dans mon `\bPOS\b`). |
| `!important` v1→v5/mobile contre l'intention v6 | grep des 7 feuilles croisé au runtime pour `.lc-v6-familles` (nouvelle règle du cycle 12) | `.lc-v6-familles` n'existe **que** dans `styles-v6-brand.css` (`:293`, `:304`, `:432`) — **aucune couche du dessous ne peut l'annuler**. Les 3 déclarations sont sur des plages **compatibles** (`1fr` base, `≥520px` 3 col., `≥700px` 3 col. + `gap:16px`) : mesuré 1/1/1/3/3/3/3/3 aux 8 largeurs, conforme à l'intention écrite. |
| Les `!important` du bloc `@media print` fuient-ils à l'écran ? | `.lc-gallery` mesuré à 794 px en média `screen` **après** avoir chargé la page | `grid-auto-flow: column`, `overflow-x: auto`, `scrollWidth 1744 > clientWidth 731` ⇒ **la branche print reste enfermée**, aucune fuite. |
| `:nth-child` décoratif | rappel du cycle 9/12 : `styles-v2.css:127-129` (dont `:nth-child(5n)` jaune vif) neutralisé par `styles-v6-brand.css:504` `:nth-child(n)` | Toujours neutralisé ; vérifié **aussi à l'impression** (`print-gal-794-crop.png` : aucune tuile jaune). |
| Compteur animé | lu **après** stabilisation (`.lc-rv` révélés, 2 mesures de hauteur identiques) | **38 plats**, conforme. (Vaut 0 avant déclenchement — piège de mesure, pas un défaut.) |
| Jumeaux desktop/mobile | 12 largeurs × 10 grilles | Cohérents. Le seul écart de **rythme** relevé est en §E (paliers à 520/700/900). |

## E. SUPPOSÉ / non retenu au verdict
- **Catalogues à N serveur** (`menu`) : 23 items ⇒ `[2×11, 1]` à 768-1100 px et `[4×5, 3]` à 1200-1440 px ; 5 boissons ⇒ `[2,2,1]` à 768-1100 px et `[4,1]` à 1200-1440 px. **Non compté** (N vient du serveur, aucun nombre de colonnes ne peut le rattraper) — mais mon balayage **corrige l'étendue** annoncée au cycle 12, qui n'avait vu le cas qu'à 1200/1440 px.
- **Trois paliers différents sur la même page** : `.lc-v6-familles` bascule à **520**, `.lc-why` à **700**, `.lc-stats-grid` à **900**, `.lc-menu-grid` à 768 puis 1200, `.lc-gallery` à 1100. Mesuré à 600 px : familles en **3 colonnes** au milieu de `.lc-why`, `.lc-menu-grid`, `.lc-stats-grid` et le pied de page **tous en 1 colonne**. Jugé sur capture lue (`home-600.png`) : le bloc 3×3 se lit comme une **respiration** volontaire, pas comme une rupture — et l'écart existait déjà avant le heal (2 colonnes contre 1). **Pas un défaut prouvable**, mais une dette de cohérence si un futur cycle veut un rythme unique.
- **Débordement interne de 5 px à `menu@520`** : la bande `.lc-cat-tabs` (`.lc-show-mobile`, plein cadre en marge négative) propage `scrollWidth 525 > clientWidth 520` jusqu'à `#root`. **`documentElement.scrollWidth` reste égal à `innerWidth` (520/520)** : aucune barre de défilement horizontale de page, rien de rogné. Même nature que le cas 320/360 déjà qualifié de volontaire au cycle 12.
- **Photos imprimées = grands aplats sombres** (4 des 5 vignettes de galerie, `colortype 2`) : consommation d'encre, jugement éditorial owner, déjà déposé au cycle 12.
- **Vignettes de 133 px sur A4** après le heal d'impression (≈ 3,5 cm) : petites mais entières et identifiables (`print-gal-794-crop.png`). Compromis acceptable.
- **`.lc-marquee-track`** : `scrollWidth` 4840-5926 contre 288-1325 de conteneur — **débordement voulu** (marquee), coupé sous `reduced-motion` (0 animation infinie active, vérifié).

## F. HORS PÉRIMÈTRE / DÉLÉGUÉS — recensés, non comptés
Heure réelle de fermeture (owner) · CGV (barème fidélité, art. 5, art. 7) · `legal/allergens.html` · `legal/privacy.html` (« NF525/POS/KDS ») · FAQ « Pas de débit en ligne » · tunnel / compte / commandes / wizard / backend · modale de compte « 1 € → 1 PT » · `cat-tacos.png` (trous de détourage) · valeurs nutritionnelles · absence de 404 de marque · jargon « V1 » légal · pastille panier comptant les lignes · panneau droit vide de la fiche · **saut h2→h4 du comparatif** (reconfirmé aux 16 passes home) · double « effacer » de la recherche · divergence du pied de page légal · tiroir panier qui s'imprime · `→` du tiroir burger à 3,68:1 · **saut h1→h3 de la page Fidélité** (reconfirmé aux 6 passes loyalty) · tiroir burger à ~50 % de vide · absence de repli sur la photo du hero · focus de la 3ᵉ tuile de galerie quasi hors bande (P2-1 du cycle 12) · survol `scale(1.03)` rogné de 7 px (P2-2 du cycle 12) · catalogues à N variable.

## G. NON TESTÉ
- **Rupture réelle** (`unavail` serveur) : la maquette locale n'expose aucun item épuisé ; le cycle 12 l'a simulé (verts), je ne l'ai **pas rejoué** ce cycle.
- **Recherche vide** et **modale fiche produit** : couvertes aux cycles 11-12 (0 échec AA, impression sur 1 page) ; non rejouées ici — mon effort est allé au recensement mécanique et aux 2 heals.
- **Pages légales** : style validé aux cycles 11-12 sur 5 pages × 2 largeurs ; non rejoué (aucune modification depuis).
- **Impression physique réelle** (vérifiée par `emulateMedia` + PDF Chromium), navigateurs autres que Chromium, **geste tactile réel** sur la bande de galerie.
- Page Fidélité **connectée** (hors périmètre).

## VERDICT S7 — **NON CONVERGÉ** : P0 = 0, **P1 = 1**

**P1-1** — `styles-v6-brand.css:298` (`.lc-v6-familles { grid-template-columns: 1fr }`) : le heal du cycle 12 supprime bien la tuile orpheline, mais échange **241 px de crème vide** contre **+2 027 à +3 309 px de défilement** sur toute la bande 320-519 px, c'est-à-dire **sur tous les téléphones réels**. Falaise de **6,5×** entre 519 px (grille de 4 869 px) et 520 px (754 px). Sur un iPhone 14 (`dsf=3`) les visuels sont en plus **agrandis ×1,54** au-delà de leur source, dans une carte dont ~60 % est du vide. Forme : « **mon correctif casse ailleurs (autre largeur)** ». Preuves lues : `fam-360.png`, `fam-480.png`, `fam-520.png`, `hypo3col-360.png`, `dsf3-1col.png`, `404-360.png`.

**Les 2 heals du cycle 12 sont fonctionnellement confirmés** :
- **A1** l'orpheline est fermée aux **12** largeurs, et le **recensement mécanique de §C (10 sélecteurs, 36 passes) ne trouve aucune 5ᵉ occurrence** — pour la première fois de la session, le recensement des grilles **tient**. Mais le moyen employé est le défaut ci-dessus.
- **A2** l'impression est **réparée** : 5 photos entières `[133,133,133,133,133]` à 794 px, `[176×5]` à 1024, `[59×5]` à 360 — et **l'écran n'est pas cassé** (la bande défile toujours sous 1100 px, dernière tuile coupée, `scroll-snap` intact, aucun `!important` qui fuit hors de `@media print`). Réserve **P2-1** : le correctif **révèle** deux directions artistiques sur le papier (4 PNG `colortype 2` à fond noir cuit + 1 PNG `colortype 6` détouré).

Tendance des cycles 5→13 : 5, 2, 2, 2, 3, 2, 3, 2, 1, 1, 3, 2, **1**.
Tout le reste est **convergé et le reste** : 0 échec AA composité sur 28 passes, 0 texte atténué, 0 erreur JS, 0 débordement de page, 0 image cassée, 14 replis fonctionnels, 0 jargon, 0 régime photo divergent, impression lisible sur les 3 routes, `reduced-motion` / `prefers-contrast: more` / `forced-colors` respectés, 7 horloges cohérentes.
