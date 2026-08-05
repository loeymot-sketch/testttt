# V5 · RED CYCLE 15 — vitrine « Le Cayenne » (lecture seule)

Périmètre S7 : `screens.jsx`, `components.jsx`, `screens-v3.jsx`, `styles-v6-brand.css`, `assets/**`, STYLE des pages légales.
Site servi : `http://127.0.0.1:8899` (React + Babel, hash routing). HEAD audité : `fe81ffb`.
Captures : `reports/goal-s7-vitrine/shots/red15/` · sondes : `~/.claude/jobs/1269b1ef/tmp/`

## 0. CONTRÔLE DE SANTÉ — vert
`#root` : 1 enfant monté · nav présente · hero présent · hauteur stabilisée 7 614 px à 1280 px · **0 `pageerror`, 0 `console.error`** (sonde `health.js`, capture `health-home.png`). Texte réel lu : « TOUS LES SOIRS · 18H – MINUIT / FAIT MAISON, CHAQUE SOIR. »
Second passage 5 routes × print : **0 erreur JS** (`cprint.js`).

## A. LES 2 HEALS DU CYCLE 14 — **CONFIRMÉS tous les deux**

### A-1 · Le bloc `@media (max-width: 400px)` déplacé après les règles de base — **OPÉRANT, 5 déclarations sur 5**
`styles-v6-brand.css:359-364` (bloc), règles de base `:311-357`. Valeurs **calculées** dans le navigateur, page défilée jusqu'à hauteur stable (`healA.js`) :

| largeur | `gap` grille | `padding` tuile | `border-radius` | `font-size` nom | `display` desc | rangées (compte) | hauteurs de rangée |
|---|---|---|---|---|---|---|---|
| 320 | **7px** | **9px 8px 12px** | **15px** | **13px** | **none** | 3 · 3 · 3 | **[119,119,119] [119,119,119] [119,119,119]** |
| 360 | **7px** | **9px 8px 12px** | **15px** | **13px** | **none** | 3 · 3 · 3 | **[133,133,133] ×3** |
| 400 | **7px** | **9px 8px 12px** | **15px** | **13px** | **none** | 3 · 3 · 3 | **[146,146,146] ×3** |
| 401 | 12px | 14px 14px 18px | 20px | 16px | block | 3 · 3 · 3 | [252,252,252] [218,218,218] [218,218,218] |
| 430 | 12px | 14px 14px 18px | 20px | 16px | block | 3 · 3 · 3 | [245,245,245] [228,228,228] [228,228,228] |

Les 3 déclarations qui ne s'appliquaient jamais (`padding`, `border-radius`, `font-size`) **s'appliquent désormais**, aux 3 largeurs sous le palier. **Les 3 rangées sont ÉGALES sous 400 px** (une seule hauteur de tuile : 119 / 133 / 146), le résidu de crème morte de 24 px a disparu, « MENU ENFANT » tient sur une ligne. Au-dessus du palier les règles de base reprennent, non contaminées. Preuves lues : `fam-320.png`, `fam-360.png`, `fam-400.png`, `fam-401.png`, `fam-430.png`.
La falaise 400→401 (+248 px, rangée 1 à 252 contre 218) subsiste — **déjà déposée au jugement de l'owner** (connus/délégués).

### A-2 · Libellé de la stat — **CORRIGÉ**
Compteur lu **après stabilisation** (`healB.js` boucle jusqu'à 7 lectures identiques) : la 3ᵉ tuile affiche « **38 / RÉFÉRENCES AU MENU** ». `screens.jsx:430`. Plus aucune occurrence de « Plats au menu » dans le périmètre. Capture lue : `stats-1024.png`.

## A-bis · CORRECTION MÉTHODOLOGIQUE QUI CHANGE LA PORTÉE DES CYCLES PRÉCÉDENTS
Le brief donne `#/home`, `#/menu`… Or l'application route sur **`#menu` / `#loyalty` / `#orders`** (`index.html:181` : `routeUrl = r => r === 'home' ? pathname+search : '#' + r` ; `index.html:132` : `RESTORE_ROUTES = { home, menu, orders, loyalty }`). **`#/menu` retombe silencieusement sur la home** : `location.hash` est réécrit à vide et le `h1` reste « FAIT MAISON, CHAQUE SOIR. » (prouvé : 5 pseudo-routes → hauteur identique 7 397 px, même `h1`, mêmes 3 badges). Il n'existe **pas** de route `contact` ni `faq` (ce sont des sections de la home).
⇒ Toutes les mesures de ce cycle ont été **rejouées** sur les vraies routes `''` (home), `#menu`, `#loyalty`. C'est ce qui a fait apparaître les grilles `#menu` et le badge `TOP`, invisibles au premier passage.

## B. LIVRABLE PRINCIPAL — **chaque déclaration de `styles-v6-brand.css` gagne-t-elle ?**

### Méthode (mécanique, reproductible)
1. `parse.js` extrait les **248 déclarations écrites** du fichier (parseur d'accolades sur le texte source, commentaires neutralisés, `file:line` conservé). On teste l'**intention écrite**, pas les longhands que le CSSOM invente en dépliant `background:` ou `font:`.
2. `cascade2.js` : pour chaque déclaration, on retrouve sa `CSSRule` (même `conditionText`, même `selectorText`), on lit la valeur **calculée** sur les éléments réellement présents, puis on **mute la déclaration** (valeur numérique ×2+37, alternatives par propriété, `unset`/`initial`/`inherit`/`revert`) et on relit. **Si le calculé bouge, la déclaration gagne** ; s'il ne bouge sous aucune mutation, elle n'a aucun effet propre. La règle est restaurée par `cssText` complet après chaque essai (le premier jet de la sonde corrompait la feuille en réécrivant des longhands vides — corrigé).
3. Contextes : 3 routes × 7 largeurs (360/430/760/950/1050/1150/1440) + média `print`, page défilée jusqu'à hauteur stable.
4. Les déclarations restées sans effet ont été **rejugées une par une** par sonde dédiée dans leur état réel : survol réel, focus clavier réel, horloge 20h (ouvert), rupture simulée, modale ouverte, `prefers-reduced-motion: reduce`, média `print`.

### Bilan
| verdict | nb | sens |
|---|---|---|
| **GAGNE** (intention = valeur calculée, mutation suivie) | **225** | — |
| **REDONDANT / NO-OP** (intention honorée, mais par une autre couche à valeur identique, ou propriété non implémentée) | **5** | aucune intention perdue |
| **SÉLECTEUR MORT** (0 occurrence de la classe dans tout le JSX/JS/HTML) | **17** | CSS mort, invisible |
| **HORS PÉRIMÈTRE** (`.lcf-cta-bar`, tunnel) | **1** | — |
| **PERDANTE** (écrasée par la cascade, par un `!important` v1→v5, par un style inline JSX, ou par un ordre de bloc fautif) | **0** | — |

> **Aucune déclaration v6 ne perd son intention.** Le défaut du cycle 14 (« le correctif affirmé mais inopérant ») ne se reproduit **nulle part ailleurs** dans la feuille. Un seul cas approche la forme — `:463 .lc-hours .lc-hours-status { color:#6EE7A0 }` est bien battu par un style **inline** de `screens.jsx:341` — mais l'inline ne s'applique **que dans l'état FERMÉ**, où il pose délibérément une autre couleur ; dans l'état OUVERT, seul état que la règle v6 vise, elle gagne (`rgb(110,231,160)` mesuré à 20h). Contraste des deux états composités : **10,4:1** (ouvert) et **9,46:1** (fermé). Pas un défaut.

### Les 5 déclarations sans effet propre (intention néanmoins honorée)
| L | déclaration | calculé | pourquoi |
|---|---|---|---|
| 185 | `.lc-v6-hero .lc-btn--orange { box-shadow: 0 10px 30px -8px rgba(244,80,30,.75) }` | `rgba(244, 80, 30, 0.75) 0px 10px 30px -8px` | **identique** à la valeur voulue, servie par une règle plus prioritaire |
| 336 | `.lc-v6-famille-art img { display: block }` | `block` | idem |
| 408 | `@media (max-width:1099px) .lc-gallery { -webkit-overflow-scrolling: touch }` | *(vide)* | propriété non implémentée par Chromium ; utile au seul Safari iOS ancien |
| 434 | `@media (min-width:900px) .lc-v6-hero-text { margin-top: 0 }` | `0px` | idem 185 |
| 604 | `@media print .lc-gallery { grid-auto-flow: row !important }` | `row` | `row` est déjà la valeur initiale |

### Les 17 déclarations sur sélecteur mort (`grep` : 0 occurrence dans `*.jsx *.js *.html`)
- `:44-49` **`.lc-v6-neon`** (6 décl.) et `:51` **`.lc-v6-neon--neon`** (2 décl.) — le « filet néon » n'est jamais rendu.
- `:243-248` **`.lc-v6-eyebrow`** (6 décl.) — jamais rendu (les eyebrows réels portent `.lc-eyebrow`, qui **gagne** via la règle `:237-241`).
- `:231-232` **`.lc-hero h1`** / `.lc-hero h1 em` (3 décl.) — le correctif **D3** (« le contour de GALETTE. touchait la ligne du dessus », `:229-230`) cible l'ancien hero `.lc-hero` de `styles.css`, **remplacé** par `.lc-v6-hero`/`.lc-v6-hero-titre`. Le commentaire décrit donc un correctif dont le sujet **n'existe plus** : ni faux ni vérifiable. Aucun effet visible (le hero actuel n'a pas de `-webkit-text-stroke` fixe).
Aucun impact utilisateur ⇒ **P3, dette de propreté**, pas un défaut de rendu.

### Table complète — 248 lignes
| L | @media | sélecteur | propriété | intention écrite | valeur calculée | verdict | preuve |
|---|---|---|---|---|---|---|---|
| 27 |  | :root | --v6-nuit | #0B0A09 | #0B0A09 | GAGNE | mutation suivie (`#37.000B37.000A55.000` → le calculé change) sur 24 contextes |
| 29 |  | :root | --v6-nuit-2 | #16120E | #16120E | GAGNE | mutation suivie (`#32277.000E` → le calculé change) sur 24 contextes |
| 30 |  | :root | --v6-braise | #F4501E | #F4501E | GAGNE | mutation suivie (`#F9039.000E` → le calculé change) sur 24 contextes |
| 30 |  | :root | --v6-neon | #FFB800 | #FFB800 | GAGNE | mutation suivie (`#FFB1637.000` → le calculé change) sur 24 contextes |
| 31 |  | :root | --v6-creme | #FBF7F0 | #FBF7F0 | GAGNE | mutation suivie (`#FBF51.000F37.000` → le calculé change) sur 24 contextes |
| 33 |  | :root | --v6-sur-nuit | rgba(255, 250, 245, 0.94) | rgba(255, 250, 245, 0.94) | GAGNE | mutation suivie (`rgba(547.000, 537.000, 527.000, 38.880)` → le calculé change) sur 24 contextes |
| 34 |  | :root | --v6-sur-nuit-doux | rgba(255, 250, 245, 0.62) | rgba(255, 250, 245, 0.62) | GAGNE | mutation suivie (`rgba(547.000, 537.000, 527.000, 38.240)` → le calculé change) sur 24 contextes |
| 35 |  | :root | --v6-filet | rgba(255, 250, 245, 0.14) | rgba(255, 250, 245, 0.14) | GAGNE | mutation suivie (`rgba(547.000, 537.000, 527.000, 37.280)` → le calculé change) sur 24 contextes |
| 44 |  | .lc-v6-neon | display | block | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 45 |  | .lc-v6-neon | width | 64px | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 46 |  | .lc-v6-neon | height | 3px | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 47 |  | .lc-v6-neon | border-radius | 999px | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 48 |  | .lc-v6-neon | background | var(--v6-braise) | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 49 |  | .lc-v6-neon | box-shadow | 0 0 12px 1px rgba(244, 80, 30, 0.75), 0 0 34px 4px rgba(244, 80, 30, 0.35) | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 51 |  | .lc-v6-neon--neon | background | var(--v6-neon) | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 51 |  | .lc-v6-neon--neon | box-shadow | 0 0 12px 1px rgba(255, 184, 0, 0.7), 0 0 34px 4px rgba(255, 184, 0, 0.3) | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 62 |  | .lc-v6-hero | position | relative | relative | GAGNE | mutation suivie (`static` → le calculé change) sur 8 contextes |
| 63 |  | .lc-v6-hero | background | var(--v6-nuit) | rgb(11, 10, 9) none repeat scroll 0% 0% / au | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 64 |  | .lc-v6-hero | color | var(--v6-sur-nuit) | rgba(255, 250, 245, 0.94) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 65 |  | .lc-v6-hero | overflow | hidden | hidden | GAGNE | mutation suivie (`visible` → le calculé change) sur 8 contextes |
| 66 |  | .lc-v6-hero | isolation | isolate | isolate | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 71 |  | .lc-v6-hero::before | content | '' | "" | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 72 |  | .lc-v6-hero::before | position | absolute | absolute | GAGNE | mutation suivie (`static` → le calculé change) sur 8 contextes |
| 73 |  | .lc-v6-hero::before | z-index | 0 | 0 | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 74 |  | .lc-v6-hero::before | inset | -20% -10% auto -10% | -197.844px -36px 0px | GAGNE | mutation suivie (`-3.000% 17.000% auto 17.000%` → le calculé change) sur 8 contextes |
| 75 |  | .lc-v6-hero::before | height | 120% | 1187.06px | GAGNE | mutation suivie (`277.000%` → le calculé change) sur 8 contextes |
| 76 |  | .lc-v6-hero::before | background | radial-gradient(60% 55% at 72% 45%, rgba(244, 80, 30, 0.22), transparent 70%),
    radial-gradient(40% 40% at 12% 8%, rgba(255, 184, 0, 0.10), transparent 70%) | radial-gradient(60% 55% at 72% 45%, rgba(244 | GAGNE | mutation suivie (`radial-gradient(157.000% 147.000% at 181.000% 127.000%, rgba(525.000, 197.000, 97.000, 37.440), transparent 177.000%),
    radial-gradient(117.000% 117.000% at 61.000% 53.000%, rgba(547.000, 405.000, 37.000, 37.200), transparent 177.000%)` → le calculé change) sur 7 contextes |
| 79 |  | .lc-v6-hero::before | pointer-events | none | none | GAGNE | mutation suivie (`auto` → le calculé change) sur 8 contextes |
| 84 |  | .lc-v6-hero-scene | position | relative | relative | GAGNE | mutation suivie (`static` → le calculé change) sur 8 contextes |
| 90 |  | .lc-v6-hero-media | position | relative | relative | GAGNE | mutation suivie (`static` → le calculé change) sur 3 contextes |
| 91 |  | .lc-v6-hero-media | z-index | 1 | 1 | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 92 |  | .lc-v6-hero-media | width | 100% | 360px | GAGNE | GAGNE (effet) · `focus1.js` : calculé **360px = 100 % du parent (360px)**. Inerte à la sonde seulement parce que `styles-mobile.css` `* { max-width:100vw }` plafonne la valeur mutée |
| 93 |  | .lc-v6-hero-media | aspect-ratio | 3 / 2 | 3 / 2 | GAGNE | mutation suivie (`43.000 / 41.000` → le calculé change) sur 3 contextes |
| 93 |  | .lc-v6-hero-media | overflow | hidden | hidden | GAGNE | mutation suivie (`visible` → le calculé change) sur 8 contextes |
| 97 |  | .lc-v6-hero-media img | width | 100% | 494px | GAGNE | mutation suivie (`inherit` → le calculé change) sur 5 contextes |
| 98 |  | .lc-v6-hero-media img | height | 100% | 240px | GAGNE | mutation suivie (`237.000%` → le calculé change) sur 7 contextes |
| 99 |  | .lc-v6-hero-media img | object-fit | cover | cover | GAGNE | mutation suivie (`fill` → le calculé change) sur 7 contextes |
| 100 |  | .lc-v6-hero-media img | display | block | block | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 100 |  | .lc-v6-hero-media img | object-position | center 58% | 50% 58% | GAGNE | mutation suivie (`center 153.000%` → le calculé change) sur 8 contextes |
| 109 |  | .lc-v6-hero-media::after | content | '' | "" | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 110 |  | .lc-v6-hero-media::after | position | absolute | absolute | GAGNE | mutation suivie (`static` → le calculé change) sur 8 contextes |
| 111 |  | .lc-v6-hero-media::after | inset | 0 | 0px | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 112 |  | .lc-v6-hero-media::after | background | linear-gradient(to bottom, rgba(11, 10, 9, 0) 52%, rgba(11, 10, 9, 0.88) 86%, var(--v6-nuit) 100%) | rgba(0, 0, 0, 0) linear-gradient(rgba(11, 10 | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 3 contextes |
| 113 |  | .lc-v6-hero-media::after | box-shadow | inset 0 0 70px 30px var(--v6-nuit) | rgb(11, 10, 9) 0px 0px 70px 30px inset | GAGNE | mutation suivie (`none` → le calculé change) sur 7 contextes |
| 114 |  | .lc-v6-hero-media::after | pointer-events | none | none | GAGNE | mutation suivie (`auto` → le calculé change) sur 8 contextes |
| 119 |  | .lc-v6-hero-text | position | relative | relative | GAGNE | mutation suivie (`static` → le calculé change) sur 8 contextes |
| 120 |  | .lc-v6-hero-text | z-index | 2 | 2 | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 121 |  | .lc-v6-hero-text | padding | 0 0 40px | 0px 0px 40px | GAGNE | mutation suivie (`unset` → le calculé change) sur 3 contextes |
| 122 |  | .lc-v6-hero-text | margin-top | -28px | -28px | GAGNE | mutation suivie (`-19.000px` → le calculé change) sur 3 contextes |
| 126 |  | .lc-v6-hero-statut | display | inline-flex | inline-flex | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 127 |  | .lc-v6-hero-statut | align-items | center | center | GAGNE | mutation suivie (`stretch` → le calculé change) sur 8 contextes |
| 128 |  | .lc-v6-hero-statut | gap | 9px | 9px | GAGNE | mutation suivie (`55.000px` → le calculé change) sur 8 contextes |
| 129 |  | .lc-v6-hero-statut | font-size | 12px | 12px | GAGNE | mutation suivie (`61.000px` → le calculé change) sur 8 contextes |
| 130 |  | .lc-v6-hero-statut | font-weight | 700 | 700 | GAGNE | mutation suivie (`100` → le calculé change) sur 8 contextes |
| 131 |  | .lc-v6-hero-statut | letter-spacing | 0.14em | 1.68px | GAGNE | mutation suivie (`37.280em` → le calculé change) sur 8 contextes |
| 132 |  | .lc-v6-hero-statut | text-transform | uppercase | uppercase | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 133 |  | .lc-v6-hero-statut | color | var(--v6-sur-nuit-doux) | rgba(255, 250, 245, 0.62) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 134 |  | .lc-v6-hero-statut | border | 1px solid var(--v6-filet) | 1px solid rgba(255, 250, 245, 0.14) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 8 contextes |
| 135 |  | .lc-v6-hero-statut | border-radius | 999px | 999px | GAGNE | mutation suivie (`2035.000px` → le calculé change) sur 8 contextes |
| 136 |  | .lc-v6-hero-statut | padding | 8px 15px | 8px 15px | GAGNE | mutation suivie (`53.000px 67.000px` → le calculé change) sur 8 contextes |
| 137 |  | .lc-v6-hero-statut | background | rgba(255, 255, 255, 0.03) | rgba(255, 255, 255, 0.03) none repeat scroll | GAGNE | mutation suivie (`rgba(547.000, 547.000, 547.000, 37.060)` → le calculé change) sur 7 contextes |
| 139 |  | .lc-v6-hero-statut b | color | var(--v6-neon) | — | GAGNE | GAGNE · état OUVERT `focus5.js` : `rgb(255,184,0)`, mutation suivie |
| 139 |  | .lc-v6-hero-statut b | font-weight | 800 | — | GAGNE | GAGNE · état OUVERT : `800`, mutation suivie |
| 141 |  | .lc-v6-hero-pastille | border-radius | 999px | — | GAGNE | GAGNE · état OUVERT, mutation suivie |
| 141 |  | .lc-v6-hero-pastille | height | 7px | — | GAGNE | GAGNE · état OUVERT : `7px`, mutation suivie |
| 141 |  | .lc-v6-hero-pastille | width | 7px | — | GAGNE | GAGNE · état OUVERT : `7px`, mutation suivie |
| 142 |  | .lc-v6-hero-pastille | background | #35D06A | — | GAGNE | GAGNE · état OUVERT : `rgb(53,208,106)`, mutation suivie |
| 143 |  | .lc-v6-hero-pastille | box-shadow | 0 0 8px 1px rgba(53, 208, 106, 0.9) | — | GAGNE | GAGNE · état OUVERT : `rgba(53,208,106,.9) 0 0 8px 1px`, mutation suivie |
| 149 |  | .lc-v6-hero-titre | margin | 18px 0 0 | 18px 0px 0px | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 150 |  | .lc-v6-hero-titre | font-family | var(--font-display) | Anton, "Bebas Neue", Inter, sans-serif | GAGNE | mutation suivie (`monospace` → le calculé change) sur 8 contextes |
| 151 |  | .lc-v6-hero-titre | font-size | clamp(52px, 13vw, 132px) | 52px | GAGNE | mutation suivie (`clamp(141.000px, 63.000vw, 301.000px)` → le calculé change) sur 8 contextes |
| 152 |  | .lc-v6-hero-titre | line-height | 1 | 52px | GAGNE | mutation suivie (`39.000` → le calculé change) sur 8 contextes |
| 157 |  | .lc-v6-hero-titre | letter-spacing | -0.04em | -2.08px | GAGNE | mutation suivie (`36.920em` → le calculé change) sur 8 contextes |
| 158 |  | .lc-v6-hero-titre | text-transform | uppercase | uppercase | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 159 |  | .lc-v6-hero-titre | color | #FFFFFF | rgb(255, 255, 255) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 162 |  | .lc-v6-hero-titre em | font-style | normal | normal | GAGNE | mutation suivie (`italic` → le calculé change) sur 8 contextes |
| 163 |  | .lc-v6-hero-titre em | display | block | block | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 164 |  | .lc-v6-hero-titre em | color | var(--v6-braise) | rgb(244, 80, 30) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 168 |  | .lc-v6-hero-sous | margin | 20px 0 0 | 20px 0px 0px | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 169 |  | .lc-v6-hero-sous | max-width | 30ch | 302.812px | GAGNE | mutation suivie (`97.000ch` → le calculé change) sur 8 contextes |
| 170 |  | .lc-v6-hero-sous | font-size | clamp(16px, 1.15vw, 19px) | 16px | GAGNE | mutation suivie (`clamp(69.000px, 39.300vw, 75.000px)` → le calculé change) sur 8 contextes |
| 171 |  | .lc-v6-hero-sous | line-height | 1.5 | 24px | GAGNE | mutation suivie (`40.000` → le calculé change) sur 8 contextes |
| 172 |  | .lc-v6-hero-sous | color | var(--v6-sur-nuit-doux) | rgba(255, 250, 245, 0.62) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 174 |  | .lc-v6-hero-sous b | color | var(--v6-sur-nuit) | rgba(255, 250, 245, 0.94) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 174 |  | .lc-v6-hero-sous b | font-weight | 600 | 600 | GAGNE | mutation suivie (`100` → le calculé change) sur 8 contextes |
| 176 |  | .lc-v6-hero-cta | margin-top | 30px | 30px | GAGNE | mutation suivie (`97.000px` → le calculé change) sur 8 contextes |
| 185 |  | .lc-v6-hero .lc-btn--orange | box-shadow | 0 10px 30px -8px rgba(244, 80, 30, 0.75) | rgba(244, 80, 30, 0.75) 0px 10px 30px -8px | REDONDANT / NO-OP | **REDONDANT** — calculé = *exactement* la valeur voulue `rgba(244,80,30,.75) 0 10px 30px -8px`, servie par une règle de priorité supérieure. Intention honorée, déclaration sans effet propre. |
| 193 |  | .lc-v6-preuves | position | relative | relative | GAGNE | mutation suivie (`static` → le calculé change) sur 8 contextes |
| 194 |  | .lc-v6-preuves | z-index | 1 | 1 | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 195 |  | .lc-v6-preuves | display | grid | grid | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 196 |  | .lc-v6-preuves | grid-template-columns | repeat(2, minmax(0, 1fr)) | 163.5px 163.5px | GAGNE | mutation suivie (`none` → le calculé change) sur 3 contextes |
| 197 |  | .lc-v6-preuves | gap | 1px | 1px | GAGNE | mutation suivie (`39.000px` → le calculé change) sur 8 contextes |
| 198 |  | .lc-v6-preuves | background | var(--v6-filet) | rgba(255, 250, 245, 0.14) none repeat scroll | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 199 |  | .lc-v6-preuves | border-top | 1px solid var(--v6-filet) | 1px solid rgba(255, 250, 245, 0.14) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 8 contextes |
| 200 |  | .lc-v6-preuves | border-bottom | 1px solid var(--v6-filet) | 1px solid rgba(255, 250, 245, 0.14) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 8 contextes |
| 203 |  | .lc-v6-preuve | background | var(--v6-nuit) | rgb(11, 10, 9) none repeat scroll 0% 0% / au | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 204 |  | .lc-v6-preuve | padding | 22px 18px | 22px 18px | GAGNE | mutation suivie (`81.000px 73.000px` → le calculé change) sur 3 contextes |
| 205 |  | .lc-v6-preuve | display | flex | flex | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 206 |  | .lc-v6-preuve | flex-direction | column | column | GAGNE | mutation suivie (`row` → le calculé change) sur 8 contextes |
| 207 |  | .lc-v6-preuve | gap | 8px | 8px | GAGNE | mutation suivie (`53.000px` → le calculé change) sur 8 contextes |
| 209 |  | .lc-v6-preuve-ico | font-size | 22px | 22px | GAGNE | mutation suivie (`81.000px` → le calculé change) sur 8 contextes |
| 209 |  | .lc-v6-preuve-ico | line-height | 1 | 22px | GAGNE | mutation suivie (`39.000` → le calculé change) sur 8 contextes |
| 211 |  | .lc-v6-preuve-t | font-family | var(--font-display) | Anton, "Bebas Neue", Inter, sans-serif | GAGNE | mutation suivie (`monospace` → le calculé change) sur 8 contextes |
| 212 |  | .lc-v6-preuve-t | font-size | clamp(17px, 1.6vw, 21px) | 17px | GAGNE | mutation suivie (`clamp(71.000px, 40.200vw, 79.000px)` → le calculé change) sur 8 contextes |
| 213 |  | .lc-v6-preuve-t | letter-spacing | -0.01em | -0.17px | GAGNE | mutation suivie (`36.980em` → le calculé change) sur 8 contextes |
| 214 |  | .lc-v6-preuve-t | text-transform | uppercase | uppercase | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 215 |  | .lc-v6-preuve-t | color | #FFFFFF | rgb(255, 255, 255) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 218 |  | .lc-v6-preuve-d | font-size | 13px | 13px | GAGNE | mutation suivie (`63.000px` → le calculé change) sur 8 contextes |
| 219 |  | .lc-v6-preuve-d | line-height | 1.45 | 18.85px | GAGNE | mutation suivie (`39.900` → le calculé change) sur 8 contextes |
| 220 |  | .lc-v6-preuve-d | color | var(--v6-sur-nuit-doux) | rgba(255, 250, 245, 0.62) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 231 |  | .lc-hero h1 | letter-spacing | -0.035em | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 231 |  | .lc-hero h1 | line-height | 0.98 | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 232 |  | .lc-hero h1 em | -webkit-text-stroke-width | 0.028em | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 239 |  | .lc-eyebrow, .lc-hero-art-tag, .lc-v6-eyebrow | font-family | var(--font-sans, 'Inter', system-ui, sans-serif) | Inter, system-ui, -apple-system, sans-serif | GAGNE | mutation suivie (`monospace` → le calculé change) sur 24 contextes |
| 240 |  | .lc-eyebrow, .lc-hero-art-tag, .lc-v6-eyebrow | letter-spacing | 0.16em | 1.76px | GAGNE | mutation suivie (`37.320em` → le calculé change) sur 24 contextes |
| 243 |  | .lc-v6-eyebrow | display | block | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 244 |  | .lc-v6-eyebrow | font-size | 12px | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 245 |  | .lc-v6-eyebrow | font-weight | 800 | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 246 |  | .lc-v6-eyebrow | text-transform | uppercase | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 247 |  | .lc-v6-eyebrow | color | var(--v6-braise) | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 248 |  | .lc-v6-eyebrow | margin-bottom | 14px | — | SÉLECTEUR MORT | 0 occurrence de cette classe dans **tout** le code JSX/JS/HTML (grep vérifié) — CSS mort |
| 271 |  | .lc-card-item-thumb, .lc-detail-art, .lc-featured-art, .lc-gallery-tile | background | radial-gradient(72% 68% at 50% 44%, #241B15 0%, #100C0A 58%, var(--v6-nuit) 100%) | rgba(0, 0, 0, 0) radial-gradient(72% 68% at  | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 14 contextes |
| 279 |  | .lc-card-item-badge | background | var(--orange-text, #C2410C) | rgb(194, 65, 12) none repeat scroll 0% 0% /  | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 14 contextes |
| 280 |  | .lc-card-item-badge | color | #FFFFFF | rgb(255, 255, 255) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 14 contextes |
| 284 |  | .lc-card-item-badge--top | background | var(--v6-neon) | rgb(255, 184, 0) none repeat scroll 0% 0% /  | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 285 |  | .lc-card-item-badge--top | color | #12100E | rgb(18, 16, 14) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 294 |  | .lc-v6-familles | display | grid | grid | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 295 |  | .lc-v6-familles | grid-template-columns | repeat(3, minmax(0, 1fr)) | 104.656px 104.672px 104.656px | GAGNE | mutation suivie (`none` → le calculé change) sur 2 contextes |
| 306 |  | .lc-v6-familles | gap | 12px | 12px | GAGNE | mutation suivie (`61.000px` → le calculé change) sur 1 contextes |
| 307 |  | .lc-v6-familles | margin-top | 26px | 26px | GAGNE | mutation suivie (`89.000px` → le calculé change) sur 8 contextes |
| 310 |  | .lc-v6-famille | display | flex | flex | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 311 |  | .lc-v6-famille | flex-direction | column | column | GAGNE | mutation suivie (`row` → le calculé change) sur 8 contextes |
| 312 |  | .lc-v6-famille | align-items | flex-start | flex-start | GAGNE | mutation suivie (`stretch` → le calculé change) sur 8 contextes |
| 313 |  | .lc-v6-famille | gap | 4px | 4px | GAGNE | mutation suivie (`45.000px` → le calculé change) sur 8 contextes |
| 314 |  | .lc-v6-famille | text-align | left | left | GAGNE | mutation suivie (`right` → le calculé change) sur 8 contextes |
| 315 |  | .lc-v6-famille | padding | 14px 14px 18px | 14px 14px 18px | GAGNE | mutation suivie (`65.000px 65.000px 73.000px` → le calculé change) sur 7 contextes |
| 316 |  | .lc-v6-famille | border | 1px solid var(--gray-1, #ECE8DE) | 1px solid rgb(236, 232, 222) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 8 contextes |
| 317 |  | .lc-v6-famille | border-radius | 20px | 20px | GAGNE | mutation suivie (`77.000px` → le calculé change) sur 7 contextes |
| 318 |  | .lc-v6-famille | background | var(--paper, #FFFFFF) | rgb(255, 255, 255) none repeat scroll 0% 0%  | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 319 |  | .lc-v6-famille | cursor | pointer | pointer | GAGNE | mutation suivie (`unset` → le calculé change) sur 8 contextes |
| 320 |  | .lc-v6-famille | font | inherit | 16px Inter, system-ui, -apple-system, sans-s | GAGNE | mutation suivie (`initial` → le calculé change) sur 8 contextes |
| 321 |  | .lc-v6-famille | color | inherit | rgb(10, 10, 10) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 322 |  | .lc-v6-famille | transition | border-color 0.18s ease, box-shadow 0.18s ease | border-color 0.18s, box-shadow 0.18s | GAGNE | mutation suivie (`border-color 37.360s ease, box-shadow 37.360s ease` → le calculé change) sur 8 contextes |
| 324 |  | .lc-v6-famille:hover | border-color | var(--v6-braise) | rgb(236, 232, 222) | GAGNE | GAGNE · survol RÉEL `focus3.js` : `rgb(236,232,222)` → **`rgb(244,80,30)`** |
| 324 |  | .lc-v6-famille:hover | box-shadow | 0 10px 26px -14px rgba(244, 80, 30, 0.6) | none | GAGNE | GAGNE · survol RÉEL : `none` → **`rgba(244,80,30,.6) 0 10px 26px -14px`** |
| 325 |  | .lc-v6-famille:focus-visible | outline | 3px solid var(--v6-braise) | rgb(10, 10, 10) none 0px | GAGNE | GAGNE · focus clavier RÉEL (Tab) : **`rgb(244,80,30) solid 3px`** |
| 325 |  | .lc-v6-famille:focus-visible | outline-offset | 2px | 0px | GAGNE | GAGNE · focus clavier RÉEL : **`2px`** |
| 328 |  | .lc-v6-famille-art | width | 100% | 86.6562px | GAGNE | mutation suivie (`237.000%` → le calculé change) sur 8 contextes |
| 329 |  | .lc-v6-famille-art | aspect-ratio | 1 / 1 | 1 / 1 | GAGNE | mutation suivie (`39.000 / 39.000` → le calculé change) sur 8 contextes |
| 330 |  | .lc-v6-famille-art | display | flex | flex | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 331 |  | .lc-v6-famille-art | align-items | center | center | GAGNE | mutation suivie (`stretch` → le calculé change) sur 8 contextes |
| 332 |  | .lc-v6-famille-art | justify-content | center | center | GAGNE | mutation suivie (`flex-start` → le calculé change) sur 8 contextes |
| 333 |  | .lc-v6-famille-art | background | radial-gradient(58% 58% at 50% 58%, rgba(244, 80, 30, 0.10), transparent 72%) | rgba(0, 0, 0, 0) radial-gradient(58% 58% at  | GAGNE | mutation suivie (`radial-gradient(153.000% 153.000% at 137.000% 153.000%, rgba(525.000, 197.000, 97.000, 37.200), transparent 181.000%)` → le calculé change) sur 7 contextes |
| 336 |  | .lc-v6-famille-art img | display | block | block | REDONDANT / NO-OP | **REDONDANT** — calculé `block` = valeur voulue, fournie ailleurs. Intention honorée. |
| 336 |  | .lc-v6-famille-art img | height | 100% | 86.6562px | GAGNE | mutation suivie (`237.000%` → le calculé change) sur 7 contextes |
| 336 |  | .lc-v6-famille-art img | object-fit | contain | contain | GAGNE | mutation suivie (`fill` → le calculé change) sur 7 contextes |
| 336 |  | .lc-v6-famille-art img | width | 100% | 281.328px | GAGNE | mutation suivie (`unset` → le calculé change) sur 1 contextes |
| 337 |  | .lc-v6-famille-emoji | font-size | 44px | 44px | GAGNE | mutation suivie (`125.000px` → le calculé change) sur 8 contextes |
| 339 |  | .lc-v6-famille-nom | font-family | var(--font-display) | Anton, "Bebas Neue", Inter, sans-serif | GAGNE | mutation suivie (`monospace` → le calculé change) sur 8 contextes |
| 340 |  | .lc-v6-famille-nom | font-size | clamp(16px, 1.5vw, 20px) | 16px | GAGNE | mutation suivie (`clamp(69.000px, 40.000vw, 77.000px)` → le calculé change) sur 7 contextes |
| 341 |  | .lc-v6-famille-nom | letter-spacing | -0.01em | -0.13px | GAGNE | mutation suivie (`36.980em` → le calculé change) sur 8 contextes |
| 342 |  | .lc-v6-famille-nom | text-transform | uppercase | uppercase | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 343 |  | .lc-v6-famille-nom | color | var(--ink, #0A0A0A) | rgb(10, 10, 10) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 346 |  | .lc-v6-famille-desc | font-size | 12px | 12px | GAGNE | mutation suivie (`61.000px` → le calculé change) sur 8 contextes |
| 347 |  | .lc-v6-famille-desc | line-height | 1.4 | 16.8px | GAGNE | mutation suivie (`39.800` → le calculé change) sur 8 contextes |
| 348 |  | .lc-v6-famille-desc | color | var(--gray-3, #6F6A60) | rgb(111, 106, 96) | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 360 | (max-width: 400px) | .lc-v6-familles | gap | 7px | 7px | GAGNE | mutation suivie (`51.000px` → le calculé change) sur 1 contextes |
| 361 | (max-width: 400px) | .lc-v6-famille | border-radius | 15px | 15px | GAGNE | mutation suivie (`67.000px` → le calculé change) sur 1 contextes |
| 361 | (max-width: 400px) | .lc-v6-famille | padding | 9px 8px 12px | 9px 8px 12px | GAGNE | mutation suivie (`55.000px 53.000px 61.000px` → le calculé change) sur 1 contextes |
| 362 | (max-width: 400px) | .lc-v6-famille-nom | font-size | 13px | 13px | GAGNE | mutation suivie (`63.000px` → le calculé change) sur 1 contextes |
| 363 | (max-width: 400px) | .lc-v6-famille-desc | display | none | none | GAGNE | mutation suivie (`block` → le calculé change) sur 1 contextes |
| 374 | (max-width: 899px) | .lc-stats-grid | grid-template-columns | 1fr !important | 328px | GAGNE | mutation suivie (`39.000fr` → le calculé change) sur 3 contextes |
| 390 | (min-width: 700px) | .lc-why | grid-template-columns | repeat(3, minmax(0, 1fr)) | 222.406px 222.406px 222.406px | GAGNE | mutation suivie (`none` → le calculé change) sur 6 contextes |
| 394 | (min-width: 900px) and (max-width: 1199px) | .lc-menu-grid | grid-template-columns | repeat(2, minmax(0, 1fr)) | 427px 427px | GAGNE | mutation suivie (`none` → le calculé change) sur 8 contextes |
| 402 | (max-width: 1099px) | .lc-gallery | display | grid | grid | GAGNE | mutation suivie (`none` → le calculé change) sur 5 contextes |
| 403 | (max-width: 1099px) | .lc-gallery | grid-auto-flow | column | column | GAGNE | mutation suivie (`unset` → le calculé change) sur 5 contextes |
| 404 | (max-width: 1099px) | .lc-gallery | grid-auto-columns | 46% | 46% | GAGNE | mutation suivie (`129.000%` → le calculé change) sur 6 contextes |
| 405 | (max-width: 1099px) | .lc-gallery | grid-template-columns | none | 150.875px 150.875px 150.875px 150.875px 150. | GAGNE | mutation suivie (`repeat(7,13px)` → le calculé change) sur 5 contextes |
| 406 | (max-width: 1099px) | .lc-gallery | overflow-x | auto | auto | GAGNE | mutation suivie (`visible` → le calculé change) sur 5 contextes |
| 407 | (max-width: 1099px) | .lc-gallery | scroll-snap-type | x mandatory | x mandatory | GAGNE | mutation suivie (`unset` → le calculé change) sur 6 contextes |
| 408 | (max-width: 1099px) | .lc-gallery | -webkit-overflow-scrolling | touch |  | REDONDANT / NO-OP | **NO-OP navigateur** — propriété non implémentée par Chromium (calculé vide) ; utile au seul Safari iOS ancien. Aucune intention perdue. |
| 410 | (max-width: 1099px) | .lc-gallery-tile | scroll-snap-align | start | start | GAGNE | mutation suivie (`unset` → le calculé change) sur 6 contextes |
| 413 | (min-width: 1100px) | .lc-gallery | grid-template-columns | repeat(5, minmax(0, 1fr)) | 198.797px 198.797px 198.797px 198.797px 198. | GAGNE | mutation suivie (`none` → le calculé change) sur 2 contextes |
| 422 | (min-width: 900px) | .lc-v6-hero-scene | align-items | center | center | GAGNE | mutation suivie (`stretch` → le calculé change) sur 5 contextes |
| 422 | (min-width: 900px) | .lc-v6-hero-scene | display | flex | flex | GAGNE | mutation suivie (`none` → le calculé change) sur 5 contextes |
| 422 | (min-width: 900px) | .lc-v6-hero-scene | min-height | 74vh | 666px | GAGNE | mutation suivie (`185.000vh` → le calculé change) sur 5 contextes |
| 422 | (min-width: 900px) | .lc-v6-hero-scene | padding | 40px 0 | 40px 0px | GAGNE | mutation suivie (`unset` → le calculé change) sur 5 contextes |
| 423 | (min-width: 900px) | .lc-v6-hero-scene > .lc-container | width | 100% | 950px | GAGNE | mutation suivie (`unset` → le calculé change) sur 5 contextes |
| 425 | (min-width: 900px) | .lc-v6-hero-media | position | absolute | absolute | GAGNE | mutation suivie (`static` → le calculé change) sur 5 contextes |
| 426 | (min-width: 900px) | .lc-v6-hero-media | right | 0 | 0px | GAGNE | mutation suivie (`unset` → le calculé change) sur 5 contextes |
| 427 | (min-width: 900px) | .lc-v6-hero-media | top | 50% | 410px | GAGNE | mutation suivie (`137.000%` → le calculé change) sur 5 contextes |
| 428 | (min-width: 900px) | .lc-v6-hero-media | transform | translateY(-50%) | matrix(1, 0, 0, 1, 0, -164.664) | GAGNE | mutation suivie (`translateY(-63.000%)` → le calculé change) sur 5 contextes |
| 428 | (min-width: 900px) | .lc-v6-hero-media | width | 52% | 494px | GAGNE | mutation suivie (`141.000%` → le calculé change) sur 5 contextes |
| 430 | (min-width: 900px) | .lc-v6-hero-media | aspect-ratio | 3 / 2 | 3 / 2 | GAGNE | mutation suivie (`43.000 / 41.000` → le calculé change) sur 5 contextes |
| 430 | (min-width: 900px) | .lc-v6-hero-media | max-width | 800px | 800px | GAGNE | mutation suivie (`1637.000px` → le calculé change) sur 5 contextes |
| 434 | (min-width: 900px) | .lc-v6-hero-text | margin-top | 0 | 0px | REDONDANT / NO-OP | **REDONDANT** — calculé `0px` = valeur voulue à ≥900 px, fournie ailleurs. Intention honorée. |
| 435 | (min-width: 900px) | .lc-v6-hero-text | padding | 0 | 0px | GAGNE | mutation suivie (`inherit` → le calculé change) sur 5 contextes |
| 436 | (min-width: 900px) | .lc-v6-hero-text | max-width | 46% | 46% | GAGNE | mutation suivie (`129.000%` → le calculé change) sur 5 contextes |
| 440 | (min-width: 900px) | .lc-v6-hero-media::after | background | linear-gradient(to right, var(--v6-nuit) 0%, rgba(11, 10, 9, 0.55) 22%, rgba(11, 10, 9, 0) 42%) | rgba(0, 0, 0, 0) linear-gradient(to right, r | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 4 contextes |
| 442 | (min-width: 900px) | .lc-v6-preuves | grid-template-columns | repeat(4, minmax(0, 1fr)) | 217.75px 217.75px 217.75px 217.75px | GAGNE | mutation suivie (`none` → le calculé change) sur 5 contextes |
| 443 | (min-width: 900px) | .lc-v6-preuve | padding | 30px 26px | 30px 26px | GAGNE | mutation suivie (`97.000px 89.000px` → le calculé change) sur 5 contextes |
| 444 | (min-width: 900px) | .lc-stats-grid | grid-template-columns | repeat(3, minmax(0, 1fr)) | 282px 282px 282px | GAGNE | mutation suivie (`none` → le calculé change) sur 5 contextes |
| 450 | (min-width: 700px) | .lc-v6-familles | gap | 16px | 16px | GAGNE | mutation suivie (`69.000px` → le calculé change) sur 6 contextes |
| 450 | (min-width: 700px) | .lc-v6-familles | grid-template-columns | repeat(3, minmax(0, 1fr)) | 222.406px 222.406px 222.406px | GAGNE | mutation suivie (`none` → le calculé change) sur 6 contextes |
| 463 |  | .lc-hours .lc-hours-status | color | #6EE7A0 | rgba(255, 255, 255, 0.72) | GAGNE | GAGNE · horloge **20h / état OUVERT** `focus2.js` : **`rgb(110,231,160)`**. Inerte la nuit car `screens.jsx:341` pose un style INLINE dans l'état FERMÉ (contraste composité de cet état : **9,46:1**) |
| 478 | (max-width: 400px) | .lc-nav-brand > span:not(.lc-nav-brand-mark) | display | none | none | GAGNE | mutation suivie (`block` → le calculé change) sur 3 contextes |
| 479 | (max-width: 400px) | .lc-nav-actions | gap | 6px | 6px | GAGNE | mutation suivie (`49.000px` → le calculé change) sur 3 contextes |
| 480 | (max-width: 400px) | .lc-nav-btn-cart, .lc-nav-btn-account | padding-left | 12px | 12px | GAGNE | mutation suivie (`61.000px` → le calculé change) sur 3 contextes |
| 480 | (max-width: 400px) | .lc-nav-btn-cart, .lc-nav-btn-account | padding-right | 12px | 12px | GAGNE | mutation suivie (`61.000px` → le calculé change) sur 3 contextes |
| 491 |  | .lc-v6-hero a:focus-visible, .lc-v6-hero button:focus-visible, .lc-v6-preuves a:focus-visible, .lc-v6-preuves button:focus-visible | outline | 3px solid var(--v6-neon) | rgb(255, 255, 255) none 0px | GAGNE | GAGNE · focus RÉEL du bouton du hero `focus4.js` : **`rgb(255,184,0) solid 3px`** |
| 492 |  | .lc-v6-hero a:focus-visible, .lc-v6-hero button:focus-visible, .lc-v6-preuves a:focus-visible, .lc-v6-preuves button:focus-visible | outline-offset | 3px | 0px | GAGNE | GAGNE · focus RÉEL : **`3px`** |
| 493 |  | .lc-v6-hero a:focus-visible, .lc-v6-hero button:focus-visible, .lc-v6-preuves a:focus-visible, .lc-v6-preuves button:focus-visible | border-radius | 14px | 16px | GAGNE | GAGNE · focus RÉEL : **`14px`** (bat `styles.css {…:focus-visible} 6px` ET `styles.css .lc-btn 16px`) |
| 499 | (prefers-reduced-motion: reduce) | .lc-v6-hero, .lc-v6-hero * | animation | none !important | — | GAGNE | GAGNE · `reducedMotion:reduce` RÉEL `focus6.js` : `animation-name` `none` → `all` sous mutation |
| 500 | (prefers-reduced-motion: reduce) | .lc-v6-hero, .lc-v6-hero * | transition | none !important | — | GAGNE | GAGNE · `reducedMotion:reduce` RÉEL : `transition-duration` `0s` → `0.001s` sous mutation |
| 523 |  | .lc-gallery-tile:nth-child(n) | background | radial-gradient(72% 68% at 50% 44%, #241B15 0%, #100C0A 58%, var(--v6-nuit) 100%) | rgba(0, 0, 0, 0) radial-gradient(72% 68% at  | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 529 |  | .lc-v6-galerie-repli | height | 100% | 100% | GAGNE | mutation suivie (`237.000%` → le calculé change) sur 8 contextes |
| 529 |  | .lc-v6-galerie-repli | width | 100% | 100% | GAGNE | mutation suivie (`237.000%` → le calculé change) sur 8 contextes |
| 530 |  | .lc-v6-galerie-repli | align-items | center | center | GAGNE | mutation suivie (`stretch` → le calculé change) sur 8 contextes |
| 530 |  | .lc-v6-galerie-repli | justify-content | center | center | GAGNE | mutation suivie (`flex-start` → le calculé change) sur 8 contextes |
| 531 |  | .lc-v6-galerie-repli | font-size | 34px | 34px | GAGNE | mutation suivie (`105.000px` → le calculé change) sur 8 contextes |
| 532 |  | .lc-v6-galerie-repli | background | radial-gradient(72% 68% at 50% 44%, #241B15 0%, #100C0A 58%, var(--v6-nuit) 100%) | rgba(0, 0, 0, 0) radial-gradient(72% 68% at  | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 7 contextes |
| 537 |  | .lc-card-item.is-soldout .lc-card-item-thumb | opacity | 1 | — | GAGNE | GAGNE · état RUPTURE simulé (`is-soldout` posée au DOM) : `1`, mutation suivie |
| 543 |  | .lc-card-item.is-soldout .lc-card-item-thumb img | opacity | 0.5 | — | GAGNE | GAGNE · état RUPTURE simulé : **`0.5`**, mutation suivie |
| 544 |  | .lc-card-item.is-soldout .lc-card-item-body | opacity | 1 | — | GAGNE | GAGNE · état RUPTURE simulé : `1`, mutation suivie |
| 553 | print | *, *::before, *::after | background | transparent !important | — | GAGNE | GAGNE · print. NORULE = artefact : le CSSOM sérialise `*, ::before, ::after`. Calculé en print : fond hero `rgba(0,0,0,0)` |
| 554 | print | *, *::before, *::after | background-image | none !important | — | GAGNE | GAGNE · print : `background-image` du hero = `none` |
| 555 | print | *, *::before, *::after | color | #000000 !important | — | GAGNE | GAGNE · print : `h1` **et** `body` calculés `rgb(0,0,0)` |
| 556 | print | *, *::before, *::after | box-shadow | none !important | — | GAGNE | GAGNE · print : `.lc-v6-famille` calculé `none` |
| 557 | print | *, *::before, *::after | text-shadow | none !important | — | GAGNE | GAGNE · print (même règle `!important`) |
| 558 | print | *, *::before, *::after | border-color | #999999 !important | — | GAGNE | GAGNE · print (même règle `!important`) |
| 560 | print | html, body | background | #FFFFFF !important | rgb(255, 255, 255) none repeat scroll 0% 0%  | GAGNE | mutation suivie (`rgb(1, 2, 3)` → le calculé change) sur 3 contextes |
| 569 | print | .lc-v6-hero::before, .lc-v6-hero-media::after, .lc-v6-neon, .lc-marquee, .lc-v6-hero-pastille, .lc-v6-hero-cta, .lc-card-item-add | display | none !important | none | GAGNE | mutation suivie (`block` → le calculé change) sur 2 contextes |
| 574 | print | .lc-nav | display | none !important | none | GAGNE | mutation suivie (`block` → le calculé change) sur 3 contextes |
| 577 | print | .lcf-cta-bar | display | none !important | — | HORS PÉRIM. | **HORS PÉRIMÈTRE** — `.lcf-cta-bar` n'existe que dans `funnel.jsx` (tunnel). |
| 588 | print | .lc-modal-backdrop | position | static !important | — | GAGNE | GAGNE · print AVEC modale fiche ouverte `focus6.js` : `static` |
| 589 | print | .lc-modal-backdrop | display | block !important | — | GAGNE | GAGNE · print modale ouverte : `block` |
| 590 | print | .lc-modal-backdrop | overflow | visible !important | — | GAGNE | GAGNE · print modale ouverte : `visible` |
| 591 | print | .lc-modal-backdrop | height | auto !important | — | GAGNE | GAGNE · print modale ouverte : hauteur utilisée 776px, fiche imprimée ENTIÈRE sur 1 page (`print-modale.png` lue) |
| 595 | print | body:has(.lc-modal-backdrop) #main, body:has(.lc-modal-backdrop) .lc-footer | display | none !important | — | GAGNE | GAGNE · print modale ouverte : `#main` **et** `.lc-footer` calculés `none` |
| 603 | print | .lc-gallery | display | grid !important | grid | GAGNE | mutation suivie (`none` → le calculé change) sur 1 contextes |
| 604 | print | .lc-gallery | grid-auto-flow | row !important | row | REDONDANT / NO-OP | **REDONDANT** — `row` est déjà la valeur initiale ; le `!important` ne change rien. |
| 605 | print | .lc-gallery | grid-template-columns | repeat(5, minmax(0, 1fr)) !important | 180.391px 180.406px 180.391px 180.406px 180. | GAGNE | mutation suivie (`none` → le calculé change) sur 1 contextes |
| 606 | print | .lc-gallery | overflow | visible !important | visible | GAGNE | mutation suivie (`hidden` → le calculé change) sur 1 contextes |
| 610 | print | .lc-v6-hero-media | max-height | 8cm !important | 302.362px | GAGNE | mutation suivie (`53.000cm` → le calculé change) sur 1 contextes |
| 613 | print | .lc-v6-hero-media img, .lc-card-item-thumb img, .lc-v6-famille-art img | max-height | 6cm !important | 226.772px | GAGNE | mutation suivie (`49.000cm` → le calculé change) sur 2 contextes |
| 613 | print | .lc-v6-hero-media img, .lc-card-item-thumb img, .lc-v6-famille-art img | object-fit | contain !important | contain | GAGNE | mutation suivie (`fill` → le calculé change) sur 1 contextes |
| 620 | print | .lc-v6-preuve, .lc-v6-famille, .lc-card-item, .lc-hours-row, .lc-faq-item | break-inside | avoid | avoid | GAGNE | mutation suivie (`auto` → le calculé change) sur 2 contextes |
| 620 | print | .lc-v6-preuve, .lc-v6-famille, .lc-card-item, .lc-hours-row, .lc-faq-item | page-break-inside | avoid | avoid | GAGNE | mutation suivie (`auto` → le calculé change) sur 2 contextes |

## C. AUTRES CLASSES — les 4 formes du méta-défaut

### C-1 · Recensement mécanique des grilles — **3ᵉ cycle consécutif sans nouvelle grille**
Balayage de **tous** les éléments en `display:grid`, 3 routes × 12 largeurs (320→1440) :
- **home : 10 grilles**, aux 12 largeurs, sur les 3 routes. Aucune 11ᵉ n'apparaît.
- **`#menu` : 4 grilles** — jamais recensées avant ce cycle (les cycles précédents mesuraient la home en croyant mesurer `#menu`, cf. A-bis).
- **`#loyalty` : 1 grille**.
**Orphelines** (dernière rangée à 1 tuile) : `0` sur la home aux 12 largeurs. Sur `#menu` : `.lc-menu-grid` **2 col. / 23 items** (768→1100) et **2 col. / 5 items**, puis **4 col. / 5 items** (1200-1440). Ce sont les **catalogues à N serveur** déjà déposés au jugement de l'owner (connus/délégués) — non comptés.

### C-2 · Hauteurs de section par largeur — aucune falaise nouvelle
| largeur | 320 | 360 | 400 | **401** | 430 | 600 | 768 | 900 | 1024 | 1100 | 1200 | 1440 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| home | 9 449 | 9 418 | 9 445 | **9 705** | 9 773 | 10 492 | 8 614 | 7 556 | 7 918 | 7 896 | 7 397 | 7 825 |
| `#menu` | 12 877 | 13 608 | 14 319 | 14 321 | 14 876 | 18 279 | **7 749** | 7 832 | 7 000 | 7 359 | 3 727 | 3 984 |
| `#loyalty` | 1 546 | 1 530 | 1 530 | 1 530 | 1 530 | 1 514 | 1 057 | 1 081 | 1 103 | 1 130 | 1 168 | 1 243 |
Les deux grosses marches (`home` 600→768 : −1 878 ; `#menu` 600→768 : −10 530 ; `#menu` 1100→1200 : −3 632) **RÉTRÉCISSENT au franchissement du palier** = fonctionnement normal d'une grille responsive (1 → 2 → 4 colonnes). La seule marche **montante** est 400→401 (+248 px), déjà déposée.

### C-3 · `opacity` sur du TEXTE + contraste AA sur fonds `rgba` **composités** — 0 échec sur 6 passes
Sonde `states.js` : 168/182/147/154/30/35 nœuds de texte mesurés (3 routes × 360 et 1 200 px), **opacité CUMULÉE** remontée sur toute la chaîne d'ancêtres, fond `rgba` **composité** récursivement jusqu'au premier fond opaque, couleur `rgba` **compositée** sur ce fond.
- **0 échec AA** sur les 6 passes. Plus faible ratio du site : **4,85:1** (« LE SERVICE », « MENU COMPLET », « CUISINE. ») pour un seuil de 4,5 — marge mince mais conforme.
- **Textes à alpha < 1** (15 par route) : tous **très au-dessus** du seuil après compositage — `TOUS LES SOIRS · 18H – MINUIT` α .62 ⇒ **7,36:1** ; les 4 descriptions de preuves α .62 ⇒ **7,51:1** ; les 12 liens du pied de page α .70 ⇒ **9,76:1** ; le trio légal α .60 ⇒ **7,30:1** ; la baseline du pied α .55 ⇒ **6,28:1** ; `HORAIRES 18H – 00H` α .72 sur `rgba(255,255,255,.06)` ⇒ **9,56:1**.
- **Piège du cycle : évité.** Un premier passage donnait 200+ « échecs » à ratio exactement 1,00 : c'étaient des nœuds encore **non révélés** (`opacity: 0` de l'animation `Reveal`). La sonde a été corrigée pour défiler toute la page, attendre 1,6 s, puis **exclure** les nœuds d'opacité cumulée < 0,99 (aucun ne reste dans cet état après révélation).

### C-4 · `<img>` et replis sous 404 — vert
Toutes les requêtes `**/assets/**` renvoyées en **404** : **20 images cassées, 0 visiblement cassée** (aucune icône brisée d'aire > 2 px), **5 replis `.lc-v6-galerie-repli`** affichant 🌶️, familles toujours en rangées égales (`[143 ×9]` à 390 px), page **9 386 px** (contre 9 445 px en régime normal — pas de section interminable), **0 erreur JS**. Captures lues : `img404-fam-390.png`, `img404-1200.png`. En régime normal : **0 image cassée, 0 `<img>` sans attribut `alt`** sur les 3 routes × 12 largeurs.

### C-5 · Impression — vert sur les 3 routes et sur la modale
Le reset universel `@media print { *, *::before, *::after { background:none transparent !important; color:#000 !important; box-shadow:none; text-shadow:none; border-color:#999 } }` (`:552-559`) **gagne** : en média print le `h1` et le `body` calculent `rgb(0,0,0)`, le fond du hero `rgba(0,0,0,0)`, son `background-image` `none`, l'ombre des tuiles `none`.
**Modale de fiche produit imprimée** (obtenue par clic réel sur une carte de `#menu`) : `.lc-modal-backdrop` calculé `position:static / display:block / overflow:visible`, `#main` **et** `.lc-footer` calculés `display:none`. Capture lue `print-modale.png` + PDF A4 : **fiche entière sur une page**, titre, composition, allergènes, **prix 7,40 €** et le bouton « Personnaliser » tous lisibles en noir sur blanc. Seule réserve : la photo studio imprime son fond noir cuit (les deux directions artistiques des PNG — **déjà déléguée**).

### C-6 · États conditionnels — 7 horloges cohérentes, 3 modes a11y respectés, recherche vide correcte
| horloge | pastille du hero | bandeau horaires |
|---|---|---|
| 15:00 | TOUS LES SOIRS · 18H – MINUIT | HORAIRES 18H – 00H |
| 18:00 | OUVERT · SERVICE EN COURS | OUVERT MAINTENANT |
| 20:00 | OUVERT · SERVICE EN COURS | OUVERT MAINTENANT |
| 23:59 | OUVERT · SERVICE EN COURS | OUVERT MAINTENANT |
| 00:30 | OUVERT · DERNIÈRES COMMANDES | DERNIÈRES COMMANDES |
| 00:59 | OUVERT · DERNIÈRES COMMANDES | DERNIÈRES COMMANDES |
| 01:00 | TOUS LES SOIRS · 18H – MINUIT | HORAIRES 18H – 00H |
Les deux surfaces sont **toujours d'accord**. L'écart « affiché 18h–00h / ouvert jusqu'à 01h » est l'**heure réelle de fermeture, déjà déléguée à l'owner**.
`prefers-reduced-motion: reduce` : les 2 déclarations `:499-500` gagnent, le bandeau défilant a `animation-name: none`. `prefers-contrast: more` : page servie, `h1` blanc sur `rgb(11,10,9)`. `forced-colors: active` : `h1` `rgb(0,0,0)` sur hero `rgb(255,255,255)`, page complète (7 397 px). **Rupture** simulée : `is-soldout` → vignette à `opacity 1`, photo à **0,5**, corps à 1 (les 3 déclarations gagnent). **Recherche vide** (`zzzzqqq`) : « **0 résultat · RÉINITIALISER · 🔍 Rien trouvé · Essaye avec d'autres mots-clés ou retire un filtre.** » — état correct.

### C-7 · Affirmations, chiffres et promesses — recomptés contre le SSOT
| affirmation affichée | où | vérification |
|---|---|---|
| « **38** · RÉFÉRENCES AU MENU » | home, compteur stabilisé | exact — `W_ITEMS.length = 38` ✔ (heal du cycle 14) |
| « **9 catégories · 38 références** » | `#menu` | exact — 9 onglets hors « Tout » ; **4+2+6+2+2+2+3+15+2 = 38** relevé sur les pastilles réelles ✔ |
| « **38** résultats » / onglet « ✦ Tout **38** » | `#menu` | cohérent ✔ |
| « 🥤 Boissons · **15** au frais » | `#menu` (`screens.jsx:602`) | exact — `drinkItems.length`, catégorie 10 = 15 articles ✔ |
| « 1 € = 10 pts » / « 1 € dépensé = 10 points. **100 points = 1 € de réduction, utilisables dès 50 points** » | home + `#loyalty` | **les deux surfaces disent la même chose** ✔ (le barème CGV et la modale de compte « 1 € → 1 PT » restent délégués) |
| « À emporter » / « Livré · on est aussi sur Uber Eats » / FAQ « Vous livrez ? → tout est à emporter… **si tu préfères être livré, on est sur Uber Eats** » / pied de page « en livraison via Uber Eats » | home, FAQ, pied | **4 surfaces cohérentes** ✔ (`screens-v3.jsx:102` documente le heal) |
| « Prêt en ~10-15 min » (home/`#menu`) vs « Prêt en **10 min** » (fiche produit) | home + modale | non contradictoire : la fiche affiche le temps **de l'article**, la page la fourchette du service. Pas retenu. |
| Téléphone `03 65 67 82 91`, `437 Rue Élie Gruyelle, 62110 Hénin-Beaumont` | pied de page | identiques partout ✔ |
**Jargon d'atelier : 0.** Les seules occurrences de la chaîne « POS » sont « TU TE **POS**ES DES QUESTIONS ? » et « qu'on nous **pos**e chaque semaine » — faux positifs de casse. Aucune de « NF525 », « borne », « KDS », « SSOT », « frozen », « branch_id », « wizard », « idempot », « sanctum ».

### C-8 · Débordements et erreurs JS
- **0 débordement horizontal de page** : `documentElement.scrollWidth == clientWidth` sur les 3 routes × 12 largeurs.
- **`0 pageerror` / `0 console.error`** sur ~110 passes (sonde de santé, 36 passes de balayage, 21 passes de cascade, 7 horloges, 3 modes a11y, 2 passes 404, print × 4).
- Deux « débordements internes » relevés puis **RÉFUTÉS** : (a) `.lc-marquee-track` `scrollWidth` 4 840-5 926 contre 288-1 325 — débordement **voulu** du bandeau, coupé sous `reduced-motion` ; (b) sur `#menu` à 320-600 px, `.lc-menu-layout` / `.lc-show-mobile` / `.lc-app` mesurent **+16 px** (+8 à 600) de `scrollWidth`. Vérifié `ovf2.js` : la bande d'onglets **saigne volontairement de 16 px de chaque côté** (`left = 0`, `right = 360` sur un écran de 360) ; le `scrollWidth` du parent, qui n'additionne que le dépassement **droit** à partir de son bord gauche à x=16, vaut mécaniquement 344 pour un contenu qui s'arrête **pile au bord de l'écran**. Rien n'est coupé, rien n'est inatteignable. Capture lue `menu-top-360.png` : bande d'onglets correcte, « Galette » tronqué au bord = affordance normale de défilement. **Artefact de mesure, pas un défaut.**

### C-9 · Jumeaux desktop/mobile
Les 3 chiffres, les 9 familles, le bandeau, les preuves, la FAQ, les horaires et le pied de page sont **le même DOM** aux deux régimes (aucun couple `lc-hide-*` / `lc-show-*` divergent en contenu sur la home). Sur `#menu`, `.lc-show-mobile` (bande d'onglets) et `.lc-menu-side` (colonne latérale) portent la **même liste de 9 catégories + les mêmes compteurs** — vérifié par lecture des libellés. Pas de jumeau désynchronisé.

## D. DÉFAUTS NOUVEAUX — **P0 = 0, P1 = 0**
Aucun défaut de niveau P0 ou P1 n'a été trouvé sur le périmètre S7. **Je le dis franchement.**

### P3-1 · 17 déclarations sur 3 sélecteurs morts (dette de propreté)
`styles-v6-brand.css:44-49` + `:51` (`.lc-v6-neon`, `.lc-v6-neon--neon`), `:243-248` (`.lc-v6-eyebrow`), `:231-232` (`.lc-hero h1`, `.lc-hero h1 em`). `grep` sur `*.jsx *.js *.html` : **0 occurrence**. Aucun effet visible ; le seul point notable est que le commentaire **D3** (`:229-230`) décrit une collision typographique sur un hero (`.lc-hero`) **remplacé depuis** par `.lc-v6-hero` — une affirmation devenue **invérifiable** faute de sujet. Classé P3 (aucune conséquence utilisateur), pas P1 : contrairement au P1-1 du cycle 14, aucun texte du dépôt n'affirme ici un résultat mesurable qui serait faux.

### P3-2 · Le brief et les rapports antérieurs désignent les routes en `#/menu`, l'application route en `#menu`
Conséquence réelle : les cycles qui ont annoncé des mesures « sur 3 / 5 routes » via `#/…` ont **mesuré la home autant de fois**. Prouvé (A-bis). Aucune conséquence pour l'utilisateur (aucun lien du site n'utilise cette forme) — mais c'est une **fausse couverture** dans les rapports, donc à corriger dans la méthode des cycles suivants, non dans le site.

## E. HORS PÉRIMÈTRE / DÉLÉGUÉS — recensés, non comptés
Heure réelle de fermeture (owner ; reconfirmée : ouvert jusqu'à 01:00 alors que le site annonce 18h–00h) · CGV (barème fidélité, art. 5 livraison, art. 7 horaires) · `legal/allergens.html` · `legal/privacy.html` · FAQ « Pas de débit en ligne » · tunnel / compte / commandes / wizard / backend (`.lcf-cta-bar` de `funnel.jsx` inclus) · modale de compte « 1 € → 1 PT » · `cat-tacos.png` · absence de valeurs nutritionnelles · absence de 404 de marque · jargon « V1 » légal · pastille panier comptant les lignes · panneau droit vide de la fiche · saut `h2→h4` du comparatif · double bouton « effacer » · divergence du pied de page légal · tiroir panier qui s'imprime · `→` du tiroir burger à 3,68:1 · saut `h1→h3` de la page Fidélité · tiroir burger à ~50 % de vide · absence de repli sur la photo du hero · focus 3ᵉ tuile de galerie · survol `scale(1.03)` rogné · **catalogues à N serveur orphelins** (reconfirmés sur les vraies grilles `#menu` : `[2×11,1]` et `[4,1]`) · deux directions artistiques des PNG sur papier · **description des familles masquée sous 400 px** · **falaise familles 400→401 px (+248 px, rangée 1 à 252 contre 218)** · hero demandé ×1,87 à 1440 @dsf2 · clic produit qui ouvre le tiroir panier (non reproduit ici : le clic a bien ouvert la fiche).

## F. NON TESTÉ
- Route **`#orders`** (commandes) : hors périmètre.
- **Rupture réelle** venue du serveur (`unavail`) : seulement **simulée** au DOM ici.
- **Impression physique** réelle (vérifiée par `emulateMedia` + PDF A4 Chromium) ; navigateurs autres que Chromium ; **geste tactile réel** sur la bande d'onglets et la galerie.
- **Pages légales** : style validé aux cycles 11-12, **non rejoué** (aucune modification depuis `fe81ffb`).
- **Densités** `dsf 2/3` : non rejouées (le hero ×1,87 à 1440 @dsf2 reste déposé au cycle 14).
- Page Fidélité **connectée**, `#menu` avec catalogue serveur vide ou en erreur.

## VERDICT S7 — **CONVERGÉ : P0 = 0, P1 = 0**

Les **deux heals du cycle 14 sont confirmés au calculé** : le bloc `@media (max-width:400px)` applique désormais ses **5 déclarations sur 5** (padding `9px 8px 12px`, rayon `15px`, nom `13px`, gap `7px`, description masquée) et les **3 rangées de familles sont égales** aux trois largeurs sous le palier — `[119,119,119]` à 320, `[133]×9` à 360, `[146]×9` à 400 ; au-dessus du palier les règles de base reprennent intactes. Le compteur stabilisé affiche « **38 · RÉFÉRENCES AU MENU** ».

Le livrable de ce cycle — l'audit **déclaration par déclaration** de la feuille v6 — répond non à la question posée : **aucune** des 248 déclarations écrites ne perd son intention. 225 gagnent (mutation suivie dans leur état réel, y compris survol, focus clavier, horloge ouverte, rupture, `reduced-motion`, modale ouverte, média print), 5 sont redondantes ou no-op **avec l'intention néanmoins honorée**, 17 pointent des sélecteurs morts (P3 de propreté), 1 est hors périmètre. Le cas qui ressemblait le plus au défaut du cycle 14 — la couleur verte du statut horaires battue par un style inline JSX — est un **partage d'états** légitime, et les deux états passent AA (10,4:1 et 9,46:1).

Le reste tient : **0 échec AA composité** sur 6 passes (168+182+147+154+30+35 nœuds, opacité cumulée et fonds `rgba` compositès ; plancher du site 4,85:1), **0 texte atténué hors seuil**, **0 erreur JS** sur ~110 passes, **0 débordement horizontal de page**, **0 image cassée / 0 `alt` manquant**, replis 404 fonctionnels (20 images tuées, 0 visiblement cassée, 5 replis, page qui ne s'emballe pas), impression lisible sur les 3 routes **et sur la modale de fiche** (prix et allergènes en noir sur blanc, 1 page), **7 horloges cohérentes** entre les deux surfaces, `reduced-motion` / `prefers-contrast: more` / `forced-colors` respectés, recherche vide correcte, **0 jargon d'atelier**, et tous les chiffres affichés recomptés exacts contre le SSOT (38 références, 9 catégories, 15 boissons, barème 10 pts/€ identique sur home et Fidélité).

Deux réserves de méthode, non des défauts du site : les routes réelles sont `#menu`/`#loyalty` et non `#/menu` (les cycles antérieurs ont mesuré la home en croyant couvrir 3 à 5 routes — corrigé et rejoué ici), et la sonde de cascade doit restaurer les règles par `cssText` complet sous peine de corrompre la feuille qu'elle mesure.

Tendance des cycles 5→15 : 5, 2, 2, 2, 3, 2, 3, 2, 1, 1, 3, 2, 1, 2, **0**.
