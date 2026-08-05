# V5 — RED-TEAM CYCLE 12 (lecture seule) — vitrine S7 « Le Cayenne »
Date : 2026-07-30 · HEAD site : `5013738` (heals cycle 11)
Périmètre : `screens.jsx`, `components.jsx`, `screens-v3.jsx`, `styles-v6-brand.css`, `assets/**`, STYLE des pages légales.
Captures : `reports/goal-s7-vitrine/shots/red12/` · Scripts : `~/.claude/jobs/1269b1ef/tmp/r12-*.js`

## 0. CONTRÔLE DE SANTÉ — RENDU OK, aucune récidive de page blanche ✅
`r12-sante.js`, 4 routes, hauteur stabilisée :

| route | `#root` enfants | texte rendu | nav | hero | `pageerror` | `console.error` | débordement |
|---|---|---|---|---|---|---|---|
| `home` | 1 | 5481 car. | ✅ | ✅ | 0 | 0 | 1440/1440 |
| `menu` | 1 | 3184 car. | ✅ | n/a | 0 | 0 | 1440/1440 |
| `loyalty` | 1 | 5481 car. | ✅ | ✅ | 0 | 0 | 1440/1440 |

`h1` home = « FAIT MAISON, CHAQUE SOIR. » ; titre = « Le Cayenne — Site officiel ».
Le commentaire JS de `screens.jsx:305-315` (galerie) est bien formé : **0 erreur** sur 45 passes de navigateur.
Preuve lue : `shots/red12/sante-home.png`.
(`#fidelite` / `#infos` ne sont pas des routes — le routeur `index.html:133` connaît
`home | menu | orders | loyalty | checkout | payment | confirm | track` ; les 3 routes vitrine sont donc `home`, `menu`, `loyalty`.)

## A. ÉTAT DES 3 HEALS DU CYCLE 11

### A1 — Grille de stats : **HEAL CONFIRMÉ** ✅ (1 col. mobile, 3 col. desktop)
`styles-v6-brand.css:349` désormais dans `@media (max-width: 899px)` ; `:419` dans `@media (min-width: 900px)` — **plages disjointes**, plus de collision.
Mesuré (`r12-grids.js`, `grid-template-columns` calculé, hauteur stabilisée, `.lc-rv:not(.in)` = 0) :

| largeur | 320 | 360 | 480 | 600 | 700 | 768 | 900 | 1024 | 1100 | 1200 | 1440 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| colonnes | 1 | 1 | 1 | 1 | 1 | 1 | **3** | **3** | 3 | 3 | 3 |
| tuile orpheline | non | non | non | non | non | non | non | non | non | non | non |

Preuves lues : `stats-360.png` (3 bandes empilées), `stats-1024.png` et `stats-1440.png` (3 colonnes, compteur **38** stabilisé).
La régression P1-1 du cycle 11 est **fermée**.

### A2 — Tuiles orphelines : **HEAL CONFIRMÉ SUR LES 3 GRILLES VISÉES**, mais **une 4ᵉ grille du périmètre n'a pas été traitée** → P1-1 (voir §B)
Mesuré aux 11 largeurs (`r12-grids.js`) :

| grille | 320-600 | 700 | 768 | 900-1100 | 1200-1440 | orpheline ? |
|---|---|---|---|---|---|---|
| `.lc-why` (3 tuiles) | 1 col. | **3** | 3 | 3 | 3 | **jamais** ✅ (pas de borne haute, conforme) |
| `.lc-menu-grid` home (4 tuiles) | 1 col. | 1 col. | **2×2** | **2×2** | **4** | **jamais** ✅ |
| `.lc-gallery` (5 tuiles) | bande | bande | bande | bande (≤1099) | **5 col.** (≥1100) | **jamais** ✅ |
| **`.lc-v6-familles` (9 tuiles)** | **2 col. → [2,2,2,2,1]** | 3 | 3 | 3 | 3 | **OUI, 320→600 px** ⇒ **P1-1** |

Balayage **générique** (tout conteneur `display:grid|flex` ≥ 3 enfants dont la dernière rangée n'a qu'un enfant),
3 routes × 10 largeurs = 30 passes (`r12-orph.js`) : une seule grille éditoriale à compte fixe fautive, `.lc-v6-familles`.

### A3 — Bande défilante de la galerie : **UTILISABLE**, mais **deux effets de bord révélés** ✅/⚠️
`r12-band.js` — largeur de la 3ᵉ tuile réellement visible et amplitude de défilement :

| largeur | 320 | 360 | 480 | 600 | 700 | 768 | 900 | 1024 |
|---|---|---|---|---|---|---|---|---|
| tuile 3 visible | 7 px | 10 px | 20 px | 20 px | 20 px | 25 px | 34 px | 43 px |
| `scrollLeft` max | 406 | 458 | 614 | 766 | 901 | 982 | 1140 | 1289 |
| défilement réel | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

Défilement **réel** partout ; dernière tuile **coupée** partout (jamais 0 px) ; les 5 tuiles sont
des `<a href>` **tabulables**, `outline: 3px solid` ✅. Preuves lues : `gal-320.png`, `gal-768.png`, `gal-1024.png`.
⚠️ Deux effets de bord non vérifiés par le heal — voir **P1-2** (impression) et **P2-1** (clavier) / **P2-2** (survol clippé).

### A4 — Régime photo de la galerie : **HEAL CONFIRMÉ** ✅
Les 5 photos sont **entières** (`objectFit: contain`, `padding: 6%`), le dégradé nocturne
`styles-v6-brand.css:497` est **enfin visible** autour de chaque photo (il était mort sous `cover`).
Régimes relevés au runtime sur les 5 conteneurs de photo (`r12-audit.js`) :

| conteneur | `object-fit` | perte de bords |
|---|---|---|
| `.lc-v6-hero-media img` | cover | 0 % (ratio identique) |
| `.lc-featured-art img` | contain | 0 % |
| `.lc-card-item-thumb img` | contain | 0 % |
| `.lc-gallery-tile img` | **contain** | **0 %** |
| `.lc-v6-famille-art img` | contain | 0 % |

**Plus aucune divergence de régime.** Jugement à l'écran (`gal-1440.png`, `gal-1024.png`) : les photos
gardent leur fond studio sombre, quasi identique au dégradé de la tuile — la lettre-boîte ne se lit
pas comme un cadre vide mais comme la continuité de la scène nocturne. **Reste appétissant**, y compris
en bande à 1024 px où les tuiles font 433 px. Pas de « photo perdue dans son cadre ».

## B. DÉFAUTS NOUVEAUX — PROUVÉS

### P1-1 · `.lc-v6-familles` : la 9ᵉ tuile (« MENU ENFANT ») est SEULE sur sa ligne à 320-699 px — **4ᵉ occurrence non traitée du critère du cycle 10**
- `styles-v6-brand.css:293-297` : `.lc-v6-familles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }` — **2 colonnes pour 9 tuiles**.
- `styles-v6-brand.css:424-425` (`@media (min-width: 700px)`) : `repeat(3, minmax(0, 1fr))` ⇒ 3×3 parfait en ≥ 700 px.
- Mesuré (`r12-grids.js` + balayage générique `r12-orph.js`) : rangées = **[2, 2, 2, 2, 1]** à **320 / 360 / 480 / 600** px ; `[3,3,3]` à 700 px et au-delà.
- Preuve lue : `shots/red12/familles-360.png` — « MENU ENFANT » (nuggets) occupe la colonne de gauche de la 5ᵉ rangée, la colonne de droite est **vide sur toute la hauteur de la tuile** (≈ 180 px de crème). Rendu identique à `familles-600.png`.
- **C'est le méta-défaut « correctif appliqué à une occurrence sur N », 4ᵉ cycle consécutif.**
  Le cycle 10 a posé le critère pour `.lc-stats-grid` (3 tuiles / 2 colonnes) ; le cycle 11 l'a étendu
  à `.lc-why`, `.lc-menu-grid` et `.lc-gallery` en écrivant « **Trois** autres grilles le violaient »
  (`styles-v6-brand.css:356-359`) — **le recensement était incomplet** : `.lc-v6-familles` est
  également une grille du périmètre, à **compte fixe et contrôlé par l'auteur** (les 9 familles),
  également **créée par la session S7** (`screens.jsx:390`), et elle viole le critère aux quatre
  largeurs de téléphone les plus courantes. Le cycle 11 n'a pas relu SA propre section.
- Le balayage générique (tout conteneur grid/flex, 3 routes × 10 largeurs) ne trouve **aucune autre**
  grille éditoriale fautive : le recensement est cette fois clos (voir §D pour les catalogues à N variable).
- Correctif minimal suggéré (non appliqué, lecture seule) : `1fr` sous 700 px comme pour `.lc-stats-grid`, ou `repeat(3, ...)` dès 480 px.

### P1-2 · La bande défilante de la galerie **ampute 3 photos sur 5 à l'impression** — effet de bord du heal du cycle 11 jamais vérifié
- `styles-v6-brand.css:375-386` (`@media (max-width: 1099px)`) pose `overflow-x: auto` + `grid-auto-flow: column` + `grid-auto-columns: 46%`.
- Le bloc `@media print` (`styles-v6-brand.css:521-…`) **ne contient aucune règle pour `.lc-gallery`**
  (`grep -n "lc-gallery" styles-v6-brand.css` ⇒ 270, 376, 385, 388, 497 — **aucune ≥ 521**).
- Une feuille A4 fait ≈ 794 px CSS : la requête `max-width: 1099px` **s'applique toujours à l'impression**.
- Mesuré sous `emulateMedia({ media: 'print' })` à 794 px (`r12-print.js`) :
  `overflow-x: auto`, `grid-auto-flow: column`, `clientWidth = 731`, `scrollWidth = 1744`,
  largeur réellement visible des 5 tuiles = **[336, 336, 26, 0, 0]**.
- Preuve lue : `shots/red12/print-gal-clip.png` — **2 photos et un liseré ; les tuiles 4 et 5 (Terminator, Chicken Burger) n'apparaissent pas du tout**. PDF réel : `shots/red12/home-print.pdf`.
- Le régime d'impression est un **livrable explicite de cette session** (cycle 4 : « un client qui imprime
  la carte ou les horaires doit obtenir une feuille lisible », `styles-v6-brand.css:481-486`), maintenu et
  retesté au cycle 11 (fiche produit). Le heal du cycle 11 a introduit un conteneur à débordement
  **sans ajouter la branche `@media print` correspondante** ⇒ forme **« mon correctif casse ailleurs »**,
  exactement le défaut que ce cycle traque, et exactement la forme du `!important` hors media query du cycle 11.
- Correctif minimal suggéré (non appliqué) : dans `@media print`, remettre `.lc-gallery { display: grid; grid-auto-flow: row; grid-template-columns: repeat(5, 1fr); overflow: visible; }`.

## B2. DÉFAUTS NOUVEAUX — P2 (recensés, non comptés au verdict)

### P2-1 · Clavier : la 3ᵉ tuile de la galerie reçoit le focus **quasi hors de la bande**
`r12-kb.js` — vraies frappes `Tab` depuis la 1ʳᵉ tuile, largeur de l'élément focalisé réellement visible dans la bande :

| Tab | tuile | `scrollLeft` | visible | entièrement visible |
|---|---|---|---|---|
| 1 | Sandwich Le Cayenne | 0 | 151 / 151 px | ✅ |
| 2 | Cheese Burger | 0 | 151 / 151 px | ✅ |
| 3 | **Sandwich Méga** | **0** | **10 / 151 px** | **❌** |
| 4 | Sandwich Terminator | 458 (max) | 151 / 151 px | ✅ |
| 5 | Chicken Burger | 458 | 151 / 151 px | ✅ |

Identique à 1024 px (43 / 433 px). Chromium ne défile pas la bande pour la tuile 3, puis saute
directement au `scrollLeft` maximal pour la tuile 4 : **la tuile 3 n'est jamais amenée à l'écran au clavier.**
Testé sans `scroll-snap-type` (surcharge au runtime, aucun fichier modifié) : comportement inchangé ⇒
la cause n'est **pas** l'accrochage. Preuve lue : `shots/red12/kb-focus-tile3-1024.png` — seul un **liseré
orange de 3 px** au bord droit de la bande signale le focus. WCAG 2.4.11 (« Focus Not Obscured ») n'est pas
formellement échoué (l'élément n'est pas *entièrement* masqué) et un indicateur reste visible : **P2, pas P1**.

### P2-2 · Le survol des tuiles est désormais **clippé** par le conteneur à débordement (≤ 1099 px)
`styles-v2.css:126` : `.lc-gallery-tile:hover { transform: scale(1.03) }`. Le conteneur devenu
`overflow-x: auto` calcule `overflow-y: auto` (règle CSS : un axe `auto` force l'autre hors de `visible`).
Mesuré (`r12-hover.js`) à 1024 px : au survol, la tuile est rognée de **7 px en haut, en bas et à gauche**,
et `scrollHeight` (440) dépasse `clientHeight` (433) — la bande acquiert un **défilement vertical parasite**.
À 1440 px (vraie grille, `overflow: visible`) : 0 px de rognage. Preuve lue : `shots/red12/hover-gal-1024.png`
— les coins arrondis gauche de la tuile survolée sont coupés net, les autres tuiles gardent leur rayon.
Cosmétique ⇒ **P2**.

## C. TABLEAU D'EXHAUSTIVITÉ PAR CLASSE

| classe traquée | méthode / volume | résultat |
|---|---|---|
| `!important` v1→v5/mobile battant une intention v6 | parseur CSS maison sur les 7 feuilles : **21 déclarations `!important` recensées**, croisées avec les 100 % des classes stylées en v6 (`r12-important` §static) | **1 collision : `styles-mobile.css:66` (`.lc-stats-grid`, `max-width:600px`) ↔ v6:349** — désormais **résolue** (v6 chargé en dernier, même `!important`, même spécificité ⇒ v6 gagne ; mesuré 1 colonne). `styles-mobile.css:321` (`.lc-menu-grid`, `max-width:700px`) bat `styles.css:429` (2 col. ≥600) : **effet voulu, pas d'orpheline**. Aucune autre. |
| `!important` v6 battant une règle v6 sous media query | même analyse, v6 sur lui-même | **0 conflit.** `:349` est en `max-width:899px`, `:419` en `min-width:900px` ⇒ **plages disjointes**. La régression P1-1 du cycle 11 est fermée. |
| `!important` restants : sont-ils tous justifiés ? | 21 déclarations relues une par une | 5 `display:none` + 8 remises à plat = bloc `@media print` (intentionnel) ; 2 `animation/transition: none` = `prefers-reduced-motion` (intentionnel) ; 4 `grid-template-columns` = les collisions ci-dessus ; 2 (`align-items`, `gap`) sur le sélecteur d'attribut fragile `styles-mobile.css` `.lc-section .lc-container > div[style*="display: grid"]…` ⇒ **0 élément apparié au runtime** (mesuré) = **code mort**, sans effet. |
| `:nth-child` / `:nth-of-type` décoratif sous surcharge v6 | grep exhaustif des 7 feuilles | `styles-v2.css:127-129` (galerie, dont `:nth-child(5n)` jaune vif) neutralisées par `styles-v6-brand.css:497` `:nth-child(n)`, et **la règle v6 est désormais réellement visible** (le `contain` du cycle 11 découvre le dégradé) — vérifié à l'écran. `styles-v2.css:415-416, 424` (`.lc-value`) = code mort (0 rendu). |
| `opacity` atténuant du TEXTE (opacité **cumulée** des ancêtres) | produit des opacités de tous les ancêtres de chaque nœud texte ; 45 passes (3 routes × 5 largeurs + 11 états à 1024) | **0 texte à opacité cumulée < 1.** Rupture simulée : `opacity 0.5` sur l'`<img>` seule, `1` sur la vignette et le corps (mesuré) ⇒ **0 échec AA**. |
| `<img>` et replis sous 404 forcé | interception `**/assets/**` → 404, home 1024 | **20 images, 0 image cassée visible.** Galerie : 5 replis 🌶️ `display:flex` ✅ (le repli survit au nouveau régime `contain`+`padding`). Familles : 9 emojis catégorie ✅. Hero : `<img>` masquée, bande sombre de 355 px sans substitut ⇒ **P2 déjà assumé**. Preuve : `404-gal-1024.png`. |
| Régimes photo par conteneur | `object-fit` + `padding` calculés au runtime sur les 5 conteneurs | **0 divergence** — 4 conteneurs produit en `contain`, hero en `cover` (ratio identique, 0 % de perte). Voir §A4. |
| Contraste AA, fonds translucides **composités** | compositing rgba complet sur la pile d'ancêtres × opacité cumulée ; 45 passes (home/menu/loyalty × 320/360/768/1024/1440 + 11 états à 1024) **+ fiche produit écran + fiche produit impression + rupture simulée + recherche vide ×2 + 5 pages légales ×2** = **60 passes** | **0 échec AA sur les 60 passes.** |
| Impression de chaque modale | `emulateMedia('print')` sur la fiche produit | `#main` → `display:none`, backdrop → `position:static`, `body.scrollHeight = 900` (**1 page**), **0 échec AA**. Preuve : `detail-print.png`. **Mais** l'impression de la **home** perd 3 photos de galerie ⇒ **P1-2**. |
| Jumeaux desktop/mobile | 8 grilles × 11 largeurs (`r12-grids.js`) + balayage générique 3 routes × 10 largeurs (`r12-orph.js`) | **1 grille fautive** ⇒ **P1-1**. `.lc-stats-grid`, `.lc-why`, `.lc-menu-grid`, `.lc-gallery`, `.lc-v6-preuves` : conformes aux **11** largeurs. |
| Jargon d'atelier côté client | `NF525\|POS\|KDS\|borne\|backend\|frozen\|SSOT\|snapshot\|payload\|idempot\|branch_id\|wizard\|V1\|undefined\|NaN\|null\|[object\|Label.` sur le texte **rendu** des 3 routes | **0 occurrence.** Seul appariement : « POS » dans « TU TE **POS**ES DES QUESTIONS ? » (faux positif). |
| Affirmations contradictoires entre surfaces | horaires (3 surfaces d'amplitude) + fidélité + livraison | Horaires : **fait owner délégué**, non compté. Fidélité « 1 € = 10 pts » cohérent hero ↔ tuile ↔ FAQ. Livraison cohérente. Aucune **nouvelle** contradiction. |
| États conditionnels | 7 horloges figées (15h / 18h / 20h / 23h59 / 00h30 / 00h59 / 01h) + `reduced-motion` + `prefers-contrast: more` + `forced-colors: active` + rupture simulée + recherche vide × 2 largeurs | Tous cohérents, **0 échec AA, 0 erreur JS**. `reduced-motion` : **0 animation infinie active** (le marquee `lc-marquee-run` 30 s est bien coupé ; il tourne en `no-preference`) ✅. Recherche vide : « 🔍 Rien trouvé — Essaye avec d'autres mots-clés ou retire un filtre. » ✅ |
| Erreurs JS et débordements | 45 passes navigateur + 10 passes légales + impression + 404 + survol | **0 `pageerror`, 0 `console.error`** partout. `documentElement.scrollWidth == innerWidth` sur les 3 routes × 5 largeurs. Les seuls débordements internes sont **volontaires** : `.lc-marquee-track` (marquee) et `.lc-cat-tabs` (`overflow-x:auto`, plein-cadre en négatif, propage 16 px à ses parents `overflow:visible` **sans rien rogner ni créer de défilement de page** — mesuré à 320 et 360). |
| Focus clavier visible | 24 tabulations depuis le haut de la home à 1024 px | **24/24 avec indicateur** (`outline: 2-3 px solid rgb(255,90,31)` ou `box-shadow`). Réserve : tuile 3 de la galerie non défilée à l'écran ⇒ **P2-1**. |
| STYLE des pages légales | 5 pages (`allergens`, `cgv`, `cookies`, `mentions`, `privacy`) × 360 et 1024 px | **0 échec AA, 0 débordement, 0 erreur.** Police `Inter`, fond `rgb(250,247,242)` (crème de marque) — **cohérent sur les 5** ; lien de retour présent partout. |
| Compteur animé « 38 plats au menu » | lecture après hauteur stabilisée (`.lc-rv:not(.in)` = 0, 2 passes stables) | **38**, conforme au SSOT. ⚠️ vaut 0 avant déclenchement de l'`IntersectionObserver` — piège de mesure, pas un défaut. |

## D. SUPPOSÉ / non retenu au verdict
- **Catalogues à N variable** : `.lc-menu-grid` de la page `menu` = **23 items en 2 colonnes** ⇒ dernière rangée [1] à 768/900/1024 px, et le groupe **5 boissons en 4 colonnes** ⇒ [4,1] à 1200/1440 px (mesuré `r12-orph.js`). **Non compté** : le nombre d'items est une donnée serveur, pas un choix éditorial de l'auteur — aucun nombre de colonnes ne peut garantir l'absence d'orpheline pour un N quelconque. Le critère du cycle 10 ne vaut que pour les blocs de tuiles à **compte fixe**.
- **Bande de galerie à 320/360 px** : la tuile 3 n'est visible que sur **7 / 10 px** — l'affordance annoncée par le commentaire du heal (« la dernière tuile coupée au bord pour signaler la suite ») est **présente mais très faible** sur téléphone. Jugé à l'écran (`gal-320.png`) : lisible comme un bord de tuile, `scroll-snap` opérant. Pas un défaut prouvable.
- **Encre à l'impression** : les règles `@media print` neutralisent tous les fonds, mais les photos produit sont des `<img>` à **fond studio noir cuit dans le fichier** : la carte imprimée sort avec de grands rectangles sombres (`print-gal-clip.png`). L'intention du cycle 4 (« ne pas vider une cartouche ») n'est donc atteinte qu'en partie. Jugement éditorial owner.
- **`.lc-v6-hero-media` sans repli sous 404** — P2 déjà assumé au cycle 11 (bande sombre de 355 px, ni casse ni icône brisée).
- **Sélecteur d'attribut fragile** `styles-mobile.css` `.lc-section .lc-container > div[style*="display: grid"][style*="gap: 12px"] > div > div` : **0 élément apparié** au runtime ⇒ code mort. Hygiène, pas un défaut visible.

## E. HORS PÉRIMÈTRE / DÉLÉGUÉS — recensés, non comptés
Heure réelle de fermeture (3 surfaces d'amplitude : `screens.jsx:126-132`, `:415`, `components.jsx:201`) ·
CGV (barème fidélité, art. 5, art. 7) · `legal/allergens.html` · `legal/privacy.html` (« NF525/POS/KDS ») ·
FAQ « Pas de débit en ligne » (`screens-v3.jsx:127`) · tunnel / compte / commandes / wizard / backend ·
modale de compte « 1 € → 1 PT » · `cat-tacos.png` · valeurs nutritionnelles · 404 de marque · jargon « V1 » légal ·
pastille panier comptant les lignes · panneau droit de la fiche · **saut h2→h4 du comparatif** (« LES CHAÎNES », reconfirmé aux 15 passes home) ·
double « effacer » de la recherche · divergence du pied de page légal · tiroir panier qui s'imprime ·
`→` du tiroir burger à 3,68:1 · **saut h1→h3 de la page Fidélité** (reconfirmé aux 15 passes loyalty) ·
tiroir burger à ~50 % de vide · absence de repli sur la photo du hero.

## F. NON TESTÉ
- **Rupture réelle** (`unavail` renvoyé par le serveur) : la maquette locale n'expose aucun item épuisé. J'ai **simulé** l'état (`is-soldout` + pastille injectés au runtime) et mesuré les opacités et le contraste (verts) — mais le chemin de données réel n'est pas exercé.
- **Page Fidélité connectée** (contenu derrière authentification) — hors périmètre (compte).
- Navigateurs autres que Chromium ; **impression physique réelle** (vérifiée par `emulateMedia` + PDF Playwright).
- Défilement tactile réel de la bande de galerie sur un appareil (mesuré par `scrollLeft`, pas par un geste).

## VERDICT S7 — **NON CONVERGÉ** : P0 = 0, **P1 = 2**
1. **P1-1** `.lc-v6-familles` (`styles-v6-brand.css:293-297`) : 9 tuiles en 2 colonnes ⇒ « MENU ENFANT » seule sur sa ligne à 320-699 px. **4ᵉ occurrence du critère posé au cycle 10**, oubliée par un recensement du cycle 11 qui s'annonçait exhaustif (« Trois autres grilles »).
2. **P1-2** Bande défilante de la galerie (`styles-v6-brand.css:375-386`) : **3 photos sur 5 n'apparaissent pas à l'impression** (aucune branche `@media print`). Régression introduite par le heal du cycle 11 contre un contrat d'impression établi au cycle 4.

**Les 3 heals du cycle 11 sont eux-mêmes confirmés** : grille de stats 1 col./3 col. sur 11 largeurs ; les
3 grilles visées sans orpheline sur 11 largeurs ; galerie en `contain`, photos entières, dégradé de marque
enfin visible, régime unifié sur les 5 conteneurs de photo.

Tendance : cycles 5→12 = 5, 2, 2, 2, 3, 2, 3, 2, 1, 1, 3, **2**.
Les deux P1 sont, une nouvelle fois, les **deux mêmes formes** du méta-défaut : *un recensement annoncé
exhaustif qui rate la N+1ᵉ occurrence* (P1-1) et *un correctif qui casse une couche voisine non revérifiée*
(P1-2, ici l'impression au lieu du desktop). Toutes les autres classes sont converged et le restent :
**0 échec AA sur 60 passes composités, 0 erreur JS, 0 débordement de page, 0 texte atténué, 0 image sans
repli, 0 jargon, 0 régime photo divergent, `reduced-motion` respecté, 5 pages légales homogènes.**
