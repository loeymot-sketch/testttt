# V5 · RED CYCLE 17 — vitrine « Le Cayenne » (lecture seule) — CYCLE DE CONFIRMATION #2

Périmètre S7 : `screens.jsx`, `components.jsx`, `screens-v3.jsx`, `styles-v6-brand.css`, `assets/**`, STYLE des pages légales (`legal/legal.css`).
Site : `http://127.0.0.1:8899` (React + Babel, hash routing). HEAD audité : `153b78c`.
Captures : `reports/goal-s7-vitrine/shots/red17/` · sondes : `~/.claude/jobs/1269b1ef/tmp/r17-*.js`
Historique P1 : 5,2,2,2,3,2,3,2,1,1,3,2,1,2,0,1. Ce cycle doit confirmer un second cycle vierge.

## 0. CONTRÔLE DE SANTÉ — VERT (sonde `r17-sante.js`, captures `sante-*.png`)

| route demandée | `location.hash` obtenu | `h1` réel | `#root` | nav | hauteur stable | `pageerror` + `console.error` |
|---|---|---|---|---|---|---|
| `''` (home) | `""` | « Fait maison, chaque soir. » | 1 enfant | oui | 7 825 px | 0 |
| `#menu` | `"#menu"` | « Tout ce qu'on cuisine. » | 1 enfant | oui | 3 984 px | 0 |
| `#loyalty` | `"#loyalty"` | « Connecte-toi pour cumuler. » | 1 enfant | oui | 1 243 px | 0 |
| `#orders` | `"#orders"` | *(aucun `h1`)* — « // MES COMMANDES » | 1 enfant | oui | 1 243 px | 0 |

4 surfaces **distinctes** (`h1` + hauteurs différents) : aucune retombée silencieuse sur la home.
`.lc-v6-hero` présent sur la home seule ; **`.lc-hero` = 0 nœud sur les 4 routes** (voir §A-2).

---

# A. ÉTAT DES 2 CORRECTIFS DU CYCLE 16 (HEAD `153b78c`)

## A-1 · Encadrés des pages légales — **CORRECTIF OPÉRANT, AUCUNE RÉGRESSION**

Source vérifiée : `legal/legal.css:146-148` — `.lc-legal-callout { max-width: 800px; margin-inline: auto; … }`.
Placement correct : `legal.css` est bien chargée **après** `styles-v6-brand.css` sur les 5 pages légales.

Mesure exhaustive (`r17-legal.js` / `r17-legal.json`) : **5 pages × 5 largeurs (360/768/840/1024/1440) = 25 passages**, tous les `.lc-legal-callout` mesurés, comparés à la `.lc-legal-section` de leur page.

| largeur | encadré `allergens:58` (le fautif) | ses jumeaux | `.lc-legal-section` | bord gauche identique ? |
|---|---|---|---|---|
| 360 | 328 | 328 | 328 | oui (16) |
| 768 | 706,6 | 706,6 | 706,6 | oui (30,7) |
| 840 | 772,8 | 772,8 | 772,8 | oui (33,6) |
| 1024 | 800 | 800 | 800 | oui (41) |
| 1440 | **800** (était **1325**) | 800 | 800 | oui (57,6) |

- **Uniformité : 7 encadrés sur 7, aux 5 largeurs** — largeur ET bord gauche identiques à la colonne de mesure.
- **0 texte rogné** : `scrollWidth > clientWidth` et `scrollHeight > clientHeight` faux sur les 7 × 5 = 35 mesures.
- **0 débordement de document** sur les 25 passages ; **0 `pageerror`, 0 `console.error`**.
- **Aucun jumeau modifié** : `box-sizing: border-box`, donc `max-width: 800px` est exactement la largeur que les 4 encadrés déjà encolonnés avaient auparavant.
- **Reste de la mise en page intact** — captures **LUES** : `L-alg-top-1440.png` (l'encadré jaune s'arrête maintenant au même fer que le titre, l'intro INCO et la grille des 14 allergènes ; grille 4+4+4+2, reste complet), `L-alg-c0-840.png` (772,8 px, pile de largeur de section), `L-alg-c0-360.png`, `L-alg-c1-1440.png`, `L-cgv-c0-1440.png`, `L-ck-c0-1024.png`, `L-pv-c0-1440.png`.

### Deux inexactitudes du correctif — signalées, non retenues en défaut

1. **`margin-inline: auto` (`legal.css:147`) est INOPÉRANTE** : la déclaration `margin: 20px 0` de la **même règle**, écrite 5 lignes plus bas (`legal.css:153`), réinitialise le raccourci et remet `margin-left/right` à `0`. Mesuré : `margin-left: 0px`, `margin-right: 0px` sur les 7 encadrés aux 5 largeurs. Le message de commit annonce « max-width **+ centrage** » : le centrage n'existe pas.
   **Ce n'est pas un défaut visible — et c'est même heureux** : `.lc-legal-section` (`:59`) et `.lc-legal-intro` (`:53`) portent `margin: 0 0 Npx`, donc **tout le corps légal est ferré à gauche**. Si `margin-inline: auto` avait gagné, l'encadré serait **centré dans 1440 px alors que ses voisins sont ferrés à gauche à 800 px** — c'est-à-dire un désalignement pire que le défaut d'origine. Le raccourci qui l'écrase sauve le rendu par accident. Classe « correctif affirmé mais inopérant », **sans conséquence visible** : je ne le compte pas en P1, mais il est faux tel qu'écrit.
2. **Le compte de « 9 encadrés » est faux : il y en a 7.** `grep -n "lc-legal-callout" legal/*.html` → `cookies.html` 2, `allergens.html` 2, `cgv.html` 2, `privacy.html` 1, `mentions.html` 0 = **7**, confirmé dans le DOM aux 5 largeurs. Le message de commit et la consigne parlent de 9. Aucun encadré n'a donc été manqué — le dénominateur annoncé était simplement inexact.

## A-2 · CSS mort `.lc-hero h1` — **RETIRÉ**, mais la feuille v6 **n'est pas exempte de sélecteurs morts**

`.lc-hero h1` et `.lc-hero h1 em` : absentes de `styles-v6-brand.css` (`grep -n "lc-hero"` → seules 2 lignes de commentaire `:227-228` + `.lc-hero-art-tag:237`). `.lc-hero` = **0 nœud** sur les 4 routes. Correctif **confirmé**.

**Énumération MÉCANIQUE demandée** (`r17-sel.js`) : les **76 règles** de la feuille lues depuis le CSSOM réel, éclatées en **77 sélecteurs composés distincts**, chacun testé par `querySelectorAll` sur **10 contextes** — 4 routes + horloge 20 h (ouvert) + horloge 15 h (fermé) + fiche produit ouverte + tiroir panier ouvert + tiroir burger à 390 px + rupture réelle (API réécrite `is_available:false`), page défilée jusqu'à hauteur stable à chaque fois.

**Résultat : 7 sélecteurs à 0 nœud dans les 10 contextes.**

| sélecteur mort | `file:line` | statut |
|---|---|---|
| `.lc-hero-art-tag` | `styles-v6-brand.css:237` | 0 occurrence JSX/JS/HTML — **en périmètre** |
| `.lc-v6-eyebrow` | `styles-v6-brand.css:238` | 0 occurrence — **en périmètre**, et **le commentaire `:240-241` juste en dessous affirme qu'elle a été « retirée »** alors qu'elle est 2 lignes au-dessus |
| `.lc-v6-hero a:focus-visible` | `styles-v6-brand.css:481` | le hero ne contient **aucun `<a>`** (son CTA est un `<button>`, `screens.jsx:165`) |
| `.lc-v6-preuves a:focus-visible` | `styles-v6-brand.css:483` | `.lc-v6-preuves` existe (`screens.jsx:175`) mais ne contient **que des `<span>`** (`:181-183`) — aucun focusable |
| `.lc-v6-preuves button:focus-visible` | `styles-v6-brand.css:484` | idem ; de plus `.lc-v6-preuves` est **imbriquée dans `.lc-v6-hero`**, donc déjà couverte par l'arm vivante |
| `.lc-v6-neon` | `styles-v6-brand.css:555` (liste `display:none` de `@media print`) | résidu du bloc retiré au cycle 15 |
| `.lcf-cta-bar` | `styles-v6-brand.css:567` (`@media print`) | **hors périmètre** (barre du tunnel) |

**Jugement.** Les 3 arms de focus morts **ne perdent aucune intention** : l'intention (« anneau de focus visible sur fond sombre ») est intégralement servie par l'arm vivante `.lc-v6-hero button:focus-visible`, qui couvre tout le contenu focusable du hero **y compris les preuves** qui y sont imbriquées. `.lc-hero-art-tag`, `.lc-v6-eyebrow` et `.lc-v6-neon` sont des arms groupés à côté d'un sélecteur vivant (`.lc-eyebrow` ; la liste `display:none` d'impression) : **aucune règle autonome morte, aucun effet visible, aucune conséquence de rendu**.

Donc : la réponse littérale à la question posée est **NON, il reste 6 sélecteurs v6 en périmètre absents du DOM** — mais aucun n'est un défaut de rendu, et le cycle 16 avait lui-même classé cette forme comme « inexactitude du registre, pas un P1 ». Je la classe pareil, pour ne pas gonfler : **dette cosmétique consignée, 0 P1**. Le seul point réellement fautif est le **commentaire `:240` qui contredit le code `:238`** — troisième annonce non vérifiée de la série.

---

# 0bis. SWEEP MÉCANIQUE — 4 routes × 15 largeurs = **60 passages** (`r17-sweep.js` / `r17-sweep.json`)

Largeurs : 320 · 360 · 390 · 400 · 401 · 430 · 520 · 600 · 601 · 768 · 900 · 1024 · 1100 · 1280 · 1440. Page défilée jusqu'à hauteur STABLE à chaque passage. **7 532 nœuds-texte** examinés.

| contrôle | résultat |
|---|---|
| `pageerror` / `console.error` | **0 sur 60** |
| débordement horizontal du document | **0 sur 60** |
| contraste AA (fonds `rgba` **composités** + opacité **cumulée** traitée comme alpha du texte) | **0 échec réel** — 274 « échecs » bruts à ratio exactement 1,00 sont des nœuds à opacité cumulée **0** (contenu non encore révélé), donc invisibles, donc pas des textes à contraster (voir ci-dessous) |
| `<img>` cassées (`naturalWidth === 0`) | **0** |
| liens / boutons sans nom accessible | **0 sur 60** |
| conteneurs à débordement | uniquement les intentionnels : `.lc-marquee-track` (bandeau), `.lc-gallery` (bande volontaire sous 1100 px), `.lc-menu-layout`, `.lc-cat-tabs` |
| orphelines (rangées reconstruites par position réelle `top`, tolérance 8 px) | **0**, hors `.lc-menu-grid` à 1280/1440 (`[4,1]`) = **catalogue à N serveur, délégué** |
| jargon d'atelier | **0 réel** — 3 correspondances = **faux positifs de ma propre regex** : « mainte**nan**t » contient `NaN`, « Tu te **pos**es » et « on nous **pos**e » contiennent `POS`. Aucun terme d'atelier rendu |

### `opacity` sur du TEXTE — **0 défaut, et le piège du révélateur désamorcé**

Le sweep a relevé 286 nœuds à opacité cumulée < 1 : **274 à exactement 0** et 12 à 0,974–0,985. Contre-épreuve (`r17-rev.js`) avec **défilement lent de 300 px en 300 px sur toute la page puis 3 s de repos**, home à 320 / 400 / 768 / 1440 : **0 nœud-texte sous 0,99, 0 `<details>` dans le DOM**. Idem `r17-two.js` sur `#menu` à 360/768/1024/1440 : **28 cartes, 0 à opacité < 0,99**. Les 286 étaient donc intégralement des **révélations non déclenchées** par mon défilement rapide (dont les 5 réponses de la FAQ et les dernières cartes boisson) — artefact de sonde, pas défaut de page. Les 274 « échecs de contraste » à ratio 1,00 tombent avec eux : **contraste réel = 0 échec sur 60 passages**.

# B. RE-VÉRIFICATION DES CLASSES QUI ONT PRODUIT DES P1

## Chiffres et promesses affichés — **exacts, après désamorçage du compteur animé**

Première lecture de la bande de chiffres de la home : « **36** RÉFÉRENCES », alors que `#menu` annonce « **38** références ». Divergence apparente entre deux surfaces sur le même chiffre.
**RÉFUTÉ** — c'est un **compteur animé** lu en cours de course. Avec défilement lent + 3,5 s de repos (`r17-two.js`), la bande calcule « **38** Références au menu » à **1440, 768 et 360 px** — identique à `#menu`. Le piège annoncé dans la consigne (« un compteur animé vaut 0 avant déclenchement ») s'est bien présenté, sous la forme d'une valeur intermédiaire et non de zéro.

Autres recomptages dans le DOM (`r17-num.js`) : **9** tuiles `.lc-v6-famille` = « Neuf familles » ; **5** accordéons FAQ = « les 5 questions » ; **4** preuves ; **5** tuiles de galerie ; barème fidélité **identique sur les 3 emplacements** de la home (« 1 € = 10 pts · 100 pts = 1 € de réduction, dès 50 pts » / « 1€ = 10pts » / « 1 € dépensé = 10 points »).

## `<img>` : repli présentable sous 404 forcé — **inchangé** (`r17-img404.js`)

Toutes les images interceptées en 404. Home : 20/20 ; `#menu` : 28/28. **1 seule zone vide** : `.lc-v6-hero-media` 749×499 (l'`onError` de `screens.jsx:154` met l'`<img>` en `display:none` et le conteneur n'a ni texte ni fond) — **déjà déléguée** (« absence de repli sur la photo du hero »). Les 4 autres conteneurs porteurs d'images (`lc-featured-art`, `lc-card-item-thumb`, `lc-gallery-tile`, `lc-v6-famille-art`) affichent tous un emoji de repli dimensionné. Captures `I404-home.png`, `I404-home-s2.png`, `I404-menu.png` **lues**.

## États conditionnels — **11 sur 11 corrects** (`r17-states.js`, captures `S-*.png`)

| horloge | statut du hero | `<b>` | pastille | `.lc-hours-status` |
|---|---|---|---|---|
| 15 h | « TOUS LES SOIRS · 18H – MINUIT » | absent (normal) | absente (normal) | « HORAIRES 18H – 00H » `rgba(255,255,255,.72)` |
| 18 h / 20 h / 23 h 59 | « OUVERT · SERVICE EN COURS » | `rgb(255,184,0)` / 800 | 7 px `rgb(53,208,106)` r=999px | « OUVERT MAINTENANT » `rgb(110,231,160)` |
| 00 h 30 / 00 h 59 | « OUVERT · DERNIÈRES COMMANDES » | idem | idem | « DERNIÈRES COMMANDES » |
| 01 h | « TOUS LES SOIRS · 18H – MINUIT » | absent | absente | « HORAIRES 18H – 00H » |

`prefers-reduced-motion: reduce` → sur les **85** nœuds de `.lc-v6-hero` : `animation-name` non-`none` = **0**, `transition-duration` > 0 = **0**. `prefers-contrast: more` et `forced-colors: active` : hauteur **identique** (7 825 px), **0** texte à opacité 0, **0** débordement, **0** erreur. Rupture réelle (API réécrite `is_available:false`) rejouée dans l'énumération de sélecteurs §A-2.

## Impression — 4 routes + 3 modales + 5 pages légales (`r17-print.js`, viewport **794 px = A4**)

| cible | pages | texte blanc | texte pâle (< 0,4) | débordement > 794 px |
|---|---|---|---|---|
| home | 8 | 0 | 0 | 0 |
| `#menu` | 9 | 0 | 0 | 0 |
| `#loyalty` | 1 | 0 | 0 | 0 |
| `#orders` | 1 | 0 | 0 | 0 |
| modale **fiche** | **1** | 0 | 0 | 0 |
| modale **compte** | **1** | 0 | 0 | 0 |
| tiroir **panier** ouvert | 8 | 0 | (révélations non déclenchées) | 0 |
| `allergens` / `cgv` / `cookies` / `privacy` / `mentions` | 4 / 5 / 3 / 5 / 3 | **0** | **0** | **0** |

> ⚠️ **Correction de méthode par rapport au cycle 16** : mesurer le débordement d'impression avec un viewport de 1440 px est invalide — les `getBoundingClientRect` restent en 1440 et signalent 8 faux débordements. Re-joué à 794 px : **0 débordement réel** partout. Le seul élément au-delà de 794 px est le `.lc-cart-drawer` **fermé** (x 794→1190), hors page donc non imprimé.
> Capture **lue** `PS-fiche.png` (média `print`, 794 px) : fiche produit complète sur une page, noir sur blanc, photo présente, prix 7,40 € et « Personnaliser » intacts, rien d'amputé. Le tiroir panier ouvert qui ramène les 8 pages de la page derrière est **déjà délégué**.

## Déclarations v6 qui gagnent leur intention — **confirmé, par argument de diff + re-mesure des états**

Le seul changement de `styles-v6-brand.css` depuis le cycle 16 est `git diff 5afc90a..153b78c` : **retrait de 2 déclarations mortes** (`.lc-hero h1`, `.lc-hero h1 em`) + commentaire. **Aucune déclaration vivante n'a été touchée.** Le jeu de déclarations est donc exactement celui du cycle 16 **moins deux mortes** ; son résultat (« 0 PERDANTE sur 234 », obtenu par mutation de chaque déclaration puis re-jugement individuel des 41 non-gagnantes brutes) se transporte tel quel sur **232 déclarations**.

Ce que j'ai **re-mesuré moi-même** ce cycle, sur les états conditionnels qui piègent la sonde de cascade (`r17-fin.js`, `r17-states.js`, `r17-sel.js`) :

| déclaration / état | mesure de ce cycle | verdict |
|---|---|---|
| `.lc-v6-famille:hover` (border + shadow) | survol réel : `rgb(236,232,222)` → `rgb(244,80,30)` ; `none` → `rgba(244,80,30,.6) 0 10px 26px -14px` | **GAGNE** (capture `F-famille-hover.png`) |
| `.lc-v6-hero button:focus-visible` | 12 `Tab` réels → « Commander maintenant » : `outline: rgb(255,184,0) solid 3px`, `offset 3px`, `radius 14px` | **GAGNE** (capture `F-hero-focus.png`) |
| `.lc-v6-hero-statut b` + `.lc-v6-hero-pastille` | n'existent qu'en état **OUVERT** — prouvés à 18 h / 20 h / 23 h 59 / 00 h 30 / 00 h 59 | **GAGNENT** |
| `.lc-hours .lc-hours-status` (couleur ouvert) | `rgb(110,231,160)` mesuré en état ouvert | **GAGNE** |
| `@media (prefers-reduced-motion)` sur `.lc-v6-hero *` | 85 nœuds : 0 animation, 0 transition | **GAGNE** |
| `@media print` (fonds, couleurs, modales) | 12 impressions à 794 px : 0 texte blanc, 0 texte pâle, fiche et compte sur 1 page | **GAGNENT** |
| **3 arms de focus** (`.lc-v6-hero a`, `.lc-v6-preuves a`, `.lc-v6-preuves button`) | 0 nœud dans 10 contextes | **morts, sans perte d'intention** (§A-2) |

## Grilles et falaises de hauteur — **0 falaise MONTANTE nouvelle** (`r17-cliff.js` / `r17-cliff.txt`)

Encadrement à **±1 px de 19 paliers** (400/480/520/560/600/640/700/720/768/800/860/900/960/1000/1024/1100/1200/1280/1360) sur home et `#menu`, hauteur stabilisée à chaque mesure, colonnes de grille relevées par position réelle.

- **home** : toutes les variations montantes à 1 px valent **+3 à +6 px** (croissance fluide des photos à ratio constant), **sauf 767→768 : +16 px** — un simple palier de gouttière, invisible sur une page de 8 600 px. Les descentes (−273 à −1 000 px) sont les passages de colonnes attendus : `.lc-v6-preuves` 2→4 col à 900, `.lc-gallery` `column`→`row` à **1100** (conforme à la note de la consigne), `.lc-menu-grid` 2→4 col à 1200. **Aucune falaise montante nouvelle** ; les 2 connues (400→401 familles, 600→601 bande de chiffres) restent déléguées/réfutées.
- **`#menu`** : ramp **parfaitement linéaire à +21 px par pixel de largeur** de 400 à 699 px, sans aucune discontinuité — c'est une grille à **1 colonne** de 23 cartes dont les photos gardent leur ratio. Pas une falaise : une pente. Passage à 2 colonnes à 700 px (−520 px), à 4 colonnes à 1200 px (−4 100 px). Au-delà de 700 px, la plus forte montée à 1 px vaut **+35 px** (899→900), les autres **+1 à +18 px** : paliers de gouttière, pas de falaise. Rangées relevées : `[2,2,2,2,2,2,2,1,1,2,2,2,1]` à 800/1100 — les « 1 » sont les intitulés de catégorie qui occupent toute la largeur, pas des tuiles orphelines — et `[4,4,4,4,4,3]` à 1200, dernière rangée à **3** sur 4 donc pas d'orpheline.
- **Orphelines** : 0 sur les 60 passages du sweep, hors `.lc-menu-grid` `[4,1]` à 1280/1440 (**délégué**).

## Régimes photo, jumeaux, recherche vide — **conformes**

- **Régimes photo par conteneur** : 5 conteneurs porteurs d'images sur la home, chacun homogène (`lc-v6-hero-media`, `lc-featured-art`, `lc-card-item-thumb`, `lc-gallery-tile`, `lc-v6-famille-art`) ; aucun mélange de régime dans un même conteneur. Captures **lues** `V-home-hero.png`, `V-home-familles.png`, `V-menu.png`, `V-menu-660.png`, `V-menu-390.png`.
- **Jumeaux desktop (1440) / mobile (390)**, 4 routes, tout le texte visible comparé (`r17-misc.js`) : **0 divergence réelle**.
  - home : 9 descriptions de familles (**déléguée**, masquées sous 400 px) + « Commandes » (**réfuté au cycle 16** : les 4 liens passent dans le tiroir burger) + un « 7 » / « 5 » qui étaient **deux instantanés du compteur animé** — vérifié : `.lc-counter` calcule **38** à 1440 **et** à 390 après repos (`r17-twin2.js`).
  - `#menu` : seuls « Catégories » et « Filtres » manquent en mobile — ce sont les **intitulés** de la colonne desktop ; les **commandes elles-mêmes sont toutes présentes en mobile** (9 onglets de catégorie avec leurs compteurs, champ « Cherche un plat, un ingrédient… », filtres « ⭐ Top » et « 🥬 Veggie »). Pas de perte de fonction.
  - Compteurs de catégories recomptés : 4+2+6+2+2+2+3+15+2 = **38** = l'onglet « Tout 38 ». **Exact.**
- **Recherche vide** (`zzzzqqqq` sur `#menu`) : 0 carte, « 0 résultat », « Rien trouvé », « Essaye… », bouton « RÉINITIALISER », 0 erreur. Capture `M-search-empty.png`.

## STYLE des pages légales — 5 pages × 3 largeurs (`r17-fin.js`)

| contrôle | résultat |
|---|---|
| contraste AA composité | **1 seule occurrence sous le seuil, non retenue** : le chevron `›` de fil d'Ariane à **1,41** — `legal.css:28` le cible par `span[aria-hidden]`, c'est un séparateur **décoratif explicitement `aria-hidden`**, donc hors champ de WCAG 1.4.3, et **antérieur au correctif du cycle 16** |
| débordement de document | **0** sur les 15 passages |
| sauts de niveau de titre | **0** sur les 5 pages |
| impression | **0** texte blanc, **0** texte pâle, **0** débordement A4 |
| erreurs JS | **0** |

---

# C. DÉFAUTS NOUVEAUX — **AUCUN (P0 = 0, P1 = 0)**

Rien de ce que j'ai mesuré ce cycle n'atteint le seuil P1 : aucun texte rogné, aucun débordement, aucun échec de contraste réel, aucune orpheline hors délégué, aucune falaise montante nouvelle, aucune erreur JS, aucun chiffre faux, aucun jargon rendu, aucune image sans repli hors délégué, 11 états conditionnels corrects, impression saine sur 12 cibles.

## C-bis. DETTE CONSIGNÉE (réelle, prouvée, **sous le seuil P1** — je ne l'arrondis pas vers le haut)

1. **`margin-inline: auto` mort** — `legal/legal.css:147` annulé par `margin: 20px 0` (`:153`) de la même règle. `margin-left/right` mesurés à `0px` sur 7 encadrés × 5 largeurs. Le commit annonce « max-width **+ centrage** » : le centrage n'existe pas. **Sans conséquence visible, et son échec est bénéfique** (tout le corps légal est ferré à gauche ; centrer l'encadré l'aurait désaligné de ses voisins). Classe « correctif affirmé mais inopérant ».
2. **« 9 encadrés » annoncés, 7 réels** — dénominateur inexact dans le message de commit et la consigne. Aucun encadré manqué.
3. **6 sélecteurs v6 morts en périmètre** — `.lc-hero-art-tag:237`, `.lc-v6-eyebrow:238`, 3 arms de focus `:481/483/484`, `.lc-v6-neon:555`. Tous groupés à côté d'un sélecteur vivant, **0 effet de rendu**. Dont un point réellement fautif : **le commentaire `:240-241` affirme que `.lc-v6-eyebrow` « a été retirée » alors qu'elle est écrite 2 lignes au-dessus**.
4. **Chevron `›` de fil d'Ariane à 1,41 de contraste** — `aria-hidden`, décoratif, WCAG-exempt, antérieur.
5. **`#menu` reste à 1 colonne jusqu'à 699 px**, d'où une page de ~20 000 px à 660 px de large (photos de 628×471). **Présentable** (capture `V-menu-660.png` lue : cartes complètes, prix et bouton `+` intacts, rien de rogné, pente linéaire sans discontinuité) et situé dans `.lc-menu-grid`, conteneur **délégué**. Observation de densité, pas défaut.

# D. SUPPOSÉ (non prouvé au niveau requis)
- Aucun. Toutes les pistes ouvertes ont été soit refermées par la mesure (§E), soit consignées en dette (§C-bis).

# E. RÉFUTATIONS DE CE CYCLE (pistes qui ressemblaient à des P1)
1. **« 36 RÉFÉRENCES » sur la home contre « 38 références » sur `#menu`** — la plus sérieuse piste du cycle : deux surfaces annonçant deux chiffres pour la même chose. **RÉFUTÉE** : `.lc-counter` est **animé**, 36 était une valeur en cours de course. Après défilement lent + 3,5 s de repos il calcule **38** à 1440, 768 et 390 px, et 38 = 4+2+6+2+2+2+3+15+2 au recomptage des onglets. Piège de la consigne rencontré sous une forme inattendue (valeur intermédiaire, non zéro).
2. **274 échecs de contraste + 286 textes à opacité < 1** — **RÉFUTÉS** : intégralement des révélations non déclenchées par mon défilement rapide. Défilement lent 300 px par 300 px + 3 s de repos → **0 nœud sous 0,99** sur home (320/400/768/1440) et **0 carte sur 28** sur `#menu` (360/768/1024/1440).
3. **45 correspondances de jargon d'atelier** — **RÉFUTÉES** : faux positifs de ma propre regex (`NaN` dans « mainte**nan**t », `POS` dans « **pos**es »/« **pos**e »). 0 terme d'atelier rendu.
4. **8 débordements à l'impression** — **RÉFUTÉS** : artefact de méthode (rects mesurés à 1440 px en média `print`). Re-joué à 794 px = A4 : **0 débordement**.
5. **« 7 » / « 5 » présents d'un côté seulement (jumeaux)** — **RÉFUTÉS** : deux instantanés du même compteur animé.
6. **« Catégories » / « Filtres » absents en mobile** — **RÉFUTÉS** : intitulés de colonne desktop ; les 9 onglets, la recherche et les 2 filtres sont bien présents à 390 px.
7. **Cartes `#menu` invisibles (opacité 0)** — **RÉFUTÉES** : 28/28 à opacité 1 après défilement lent.
8. **Falaise montante `#menu` 400→700 px (+5 400 px)** — **RÉFUTÉE comme falaise** : pente **linéaire à +21 px/px**, sans aucune discontinuité, due à une grille 1 colonne de photos à ratio constant.

# F. HORS PÉRIMÈTRE (à part, hors verdict)
- `legal/allergens.html:60` : « **wizard** de personnalisation » dans un texte client (contenu légal **délégué**).
- `legal/cookies.html` : « en **V1** » (jargon légal **délégué**).
- Modale de compte : « `// Bon retour` » (jargon `//` du compte — **délégué**).
- Tiroir panier ouvert → impression des 8 pages de la page derrière (**délégué**).
- Absence de repli sur la photo du hero sous 404 (**délégué**).
- Falaise familles 400→401 px, `.lc-menu-grid` à N serveur, panneau droit vide de la fiche, sauts `h2→h4` du comparatif et `h1→h3` de `#loyalty`, `alt=""` des vignettes de familles, tiroir burger à ~50 % de vide, `cat-tacos.png` (**tous délégués**).
- Tunnel, compte, commandes, wizard, backend, contenu contractuel légal : hors périmètre par consigne.

# G. NON TESTÉ
- Safari / iOS et Firefox (moteur Chromium seul) — `-webkit-overflow-scrolling: touch` (`:398`) reste non prouvée sur sa cible.
- Impression papier physique (PDF A4 + média `print` à 794 px comme substituts).
- Lecteur d'écran réel (VoiceOver / NVDA) — seuls noms accessibles et ordre de tabulation mesurés.
- Rendu des PDF en image (`pdftoppm` absent de la machine) — le comptage de pages a été fait sur la structure du PDF et le contrôle visuel via captures en média `print` à 794 px.
- Mutation des 232 déclarations rejouée intégralement : transportée du cycle 16 par argument de diff (aucune déclaration vivante modifiée), avec re-mesure directe des 7 familles d'états conditionnels.

---

# VERDICT S7 : **CONVERGÉ**

**P0 = 0. P1 = 0.** Deuxième cycle consécutif vierge après le 15, le 16 ayant trouvé et fait corriger son unique P1 — **correctif vérifié opérant ce cycle** (7 encadrés sur 7 uniformes aux 5 largeurs, l'encadré fautif ramené de 1 325 px à 800 px, aucun jumeau altéré, reste de la mise en page intact).

Historique : 5, 2, 2, 2, 3, 2, 3, 2, 1, 1, 3, 2, 1, 2, 0, 1, **0**.

Preuves de ce cycle : 60 passages de sweep (4 routes × 15 largeurs, 7 532 nœuds-texte), 25 passages légaux + 15 de contraste légal, 77 sélecteurs v6 testés sur 10 contextes, 19 paliers encadrés à ±1 px sur 2 routes, 12 impressions à A4, 11 états conditionnels, 48 images forcées en 404. **0 erreur JS, 0 débordement de document, 0 échec de contraste réel, 0 orpheline hors délégué, 0 falaise montante nouvelle, 0 jargon rendu, chiffres exacts au recomptage.**

Les 5 points de **dette consignée** (§C-bis) sont réels et prouvés mais tous **sans conséquence de rendu** : une déclaration `margin-inline` morte dont l'échec est bénéfique, un dénominateur annoncé inexact, 6 sélecteurs morts groupés, un chevron décoratif `aria-hidden`, une densité mobile délégée. Aucun ne justifie de retenir la convergence — et je ne les gonfle pas pour justifier un 18ᵉ cycle.

**Aucun fichier du site n'a été modifié.**
