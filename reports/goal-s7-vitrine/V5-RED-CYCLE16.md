# V5 · RED CYCLE 16 — vitrine « Le Cayenne » (lecture seule) — CYCLE DE CONFIRMATION

Périmètre S7 : `screens.jsx`, `components.jsx`, `screens-v3.jsx`, `styles-v6-brand.css`, `assets/**`, STYLE des pages légales.
Site : `http://127.0.0.1:8899` (React + Babel, hash routing). HEAD audité : `5afc90a`.
Captures : `reports/goal-s7-vitrine/shots/red16/` · sondes : `~/.claude/jobs/1269b1ef/tmp/r16-*.js`
Historique P1 : 5,2,2,2,3,2,3,2,1,1,3,2,1,2,**0**. Ce cycle décide de la convergence (2 cycles consécutifs à 0).

## 0. CONTRÔLE DE SANTÉ — VERT

Les 4 VRAIES routes chargées et **individuellement vérifiées** (sonde `r16-sante.js`, captures `sante-*.png` lues) :

| route demandée | `location.hash` obtenu | `h1` réel | hauteur stable | `pageerror` + `console.error` |
|---|---|---|---|---|
| `''` (home) | `""` | « FAIT MAISON, CHAQUE SOIR. » | 7 614 px | 0 |
| `#menu` | `"#menu"` | « TOUT CE QU'ON CUISINE. » | 3 781 px | 0 |
| `#loyalty` | `"#loyalty"` | « CONNECTE-TOI POUR CUMULER. » | 1 196 px | 0 |
| `#orders` | `"#orders"` | *(pas de `h1`)* — « // MES COMMANDES » | 1 196 px | 0 |

`#root` = 1 enfant monté partout, nav présente partout, hero présent sur la home seule (attendu).
Les 4 surfaces sont bien **distinctes** (hauteurs et `h1` différents) : pas de retombée silencieuse sur la home.

## A. ÉTAT DU CHANGEMENT DU CYCLE 15 (suppression de CSS mort) — **PAS DE RÉGRESSION, mais l'annonce est incomplète**

Diff réel de `5afc90a` (`git diff HEAD~1 -- styles-v6-brand.css`) : **10 insertions / 20 suppressions**, deux hunks seulement.

| bloc annoncé retiré | réellement retiré ? | vérification |
|---|---|---|
| `.lc-v6-neon` / `.lc-v6-neon--neon` | **OUI** | plus aucune déclaration ; reste une mention en commentaire (`:40`) et le sélecteur dans la liste `display:none` d'impression (`:555`) — inoffensif |
| `.lc-v6-eyebrow` (bloc propre) | **OUI** | `:238` remplacé par un commentaire ; le nom subsiste dans la liste de sélecteurs du D7 (`:234`), inoffensif |
| surcharges `.lc-hero h1` / `.lc-hero h1 em` | **NON — elles sont toujours là** | `styles-v6-brand.css:227-228` |

`.lc-hero` a **0 occurrence** dans tout le JSX/JS/HTML du site (`grep -roh "lc-hero[A-Za-z0-9_-]*" *.jsx *.js *.html` → aucune sortie ; DOM : `document.querySelectorAll('.lc-hero').length === 0` sur les 4 routes). Les deux déclarations `:227-228` sont donc **du CSS mort resté en place**, exactement du même genre que les deux blocs retirés. Ce n'est **pas un défaut visible** (rien ne les applique) — c'est une **inexactitude du compte-rendu du cycle 15**, consignée ici pour l'honnêteté du registre, pas au titre d'un P1.

### Non-régression visuelle — confirmée, captures LUES
- **Eyebrows en grotesque** : les 6 eyebrows de la home (`LE SERVICE`, `MENU`, `FACEBOOK`, `LA CARTE`, `LA DIFFÉRENCE`, `FAQ`) calculent `font-family: Inter, system-ui, -apple-system, sans-serif`, `letter-spacing: 1.76px`, `font-size: 11px`. **Aucune n'est en mono.** La règle D7 (`:232-236`) porte encore `.lc-eyebrow`, seul sélecteur vivant des trois. Sonde `r16-A.js`, capture `A-home-s0.png` / `A-home-s2.png`.
- **Titre du hero, aucune collision** : le `h1` réel est `h1.lc-v6-hero-titre` (pas `.lc-hero h1`) — `Anton` 132 px, `line-height: 132px` (= 1.0, `styles-v6-brand.css:153`), `letter-spacing: -5.28px`, `-webkit-text-stroke-width: 0px`. Les 4 lignes se succèdent à pas constant de 132 px ; la **virgule de « MAISON, »** descend sous la ligne de base sans toucher le « CHAQUE » du dessous (zoom ×10 lu : `A-chaines-max.png` pour le pire cas d'accent, `A-home-s0.png` pour le hero). L'accent circonflexe de « CHAÎNES » (Anton + tracking serré) surplombe l'apex du « A » **au-dessus** de la hauteur de capitale : pas de chevauchement d'encre, pas de rognage par le conteneur.
- **Aspect des 5 surfaces inchangé et cohérent** : home (6 tranches lues), `#menu`, fiche produit, pied de page, pages légales — détail aux sections suivantes.

## 0bis. SWEEP MÉCANIQUE — 4 routes × 14 largeurs = **56 passages** (`r16-audit.js`, `r16-audit.json`)
Largeurs : 320 · 360 · 390 · 400 · 401 · 430 · 520 · 660 · 768 · 900 · 1024 · 1100 · 1280 · 1440. Page défilée jusqu'à hauteur STABLE à chaque passage.

| contrôle | résultat |
|---|---|
| `pageerror` / `console.error` | **0 sur 56** |
| débordement horizontal du document (`documentElement.scrollWidth > innerWidth`) | **0 sur 56** |
| contraste AA (fonds `rgba` **composités** + opacité **cumulée** des ancêtres) | **0 échec sur 56** |
| texte à opacité cumulée < 1 | 3 nœuds à **0,998–0,999** (dernière carte `Sprite 33cl` de `#menu`, animation de révélation non encore terminée) — pas un défaut |
| `<img>` cassées (`naturalWidth === 0`) | **0** |
| liens/boutons sans nom accessible | **0 sur 56** |
| jargon d'atelier dans le texte rendu (`NF525|POS|KDS|borne|backend|frozen|SSOT|payload|wizard|V1|undefined|NaN|null|[object|Label.`) | **0 occurrence sur 56** |

## B-1 · CHAQUE DÉCLARATION DE `styles-v6-brand.css` GAGNE-T-ELLE SON INTENTION ? — **CONFIRMÉ : 0 PERDANTE**

Le fichier compte désormais **234 déclarations écrites** (248 − 14 retirées au cycle 15 : 8 de `.lc-v6-neon*` + 6 de `.lc-v6-eyebrow`). Sonde `parse.js` → `decls.json`, puis `cascade2.js` (mutation de chaque déclaration : valeur ×2+37, alternatives par propriété, `unset`/`initial`/`inherit`/`revert`, `cssText` restauré) sur **3 routes × 7 largeurs + média `print`** — 0 `pageerror`.

| verdict brut de la sonde | nb |
|---|---|
| GAGNE au moins dans un contexte | 193 |
| INERTE partout (éléments présents) | 14 |
| aucun élément dans aucun contexte | 19 |
| règle non appariable par la sonde (sélecteur universel) | 6 |
| jamais évaluée (média non activé dans la sonde) | 2 |

Les **41 non-gagnantes brutes ont été rejugées une par une dans leur état réel** (`r16-judge.js`) :

| L | déclaration | verdict rejugé | preuve mesurée |
|---|---|---|---|
| 314 | `.lc-v6-famille:hover { border-color }` | **GAGNE** | survol réel : `rgb(236,232,222)` → `rgb(244,80,30)` |
| 314 | `.lc-v6-famille:hover { box-shadow }` | **GAGNE** | `none` → `rgba(244,80,30,.6) 0 10px 26px -14px` |
| 315 | `.lc-v6-famille:focus-visible { outline }` | **GAGNE** | Tab clavier réel : `rgb(244,80,30) solid 3px` |
| 315 | `… { outline-offset: 2px }` | **GAGNE** | `2px` mesuré |
| 481-483 | `.lc-v6-hero/​.lc-v6-preuves :focus-visible { outline / offset / radius }` | **GAGNE ×3** | Tab réel sur « Commander maintenant » : `3px solid rgb(255,184,0)`, offset `3px`, radius `14px` |
| 135 | `.lc-v6-hero-statut b { color / font-weight }` | **GAGNE ×2 (état OUVERT)** | horloge 20h : `<b>OUVERT</b>` `rgb(255,184,0)` / `800` — **le `<b>` n'existe pas en état fermé**, d'où le faux « sélecteur mort » |
| 137-139 | `.lc-v6-hero-pastille` (5 décl.) | **GAGNE ×5 (état OUVERT)** | horloge 20h : `7×7 px`, `border-radius 999px`, `bg rgb(53,208,106)`, `box-shadow rgba(53,208,106,.9) 0 0 8px 1px` — capture `judge-pastille.png` |
| 453 | `.lc-hours .lc-hours-status { color:#6EE7A0 }` | **GAGNE (état OUVERT)** | horloge 18h/20h/23h59/00h30/00h59 : `rgb(110,231,160)` mesuré (`r16-states.js`) |
| 527 / 533 / 534 | `.lc-card-item.is-soldout …` (3 décl.) | **GAGNE ×3** | rupture réelle injectée (`/api/frontend/item` → `is_available:false`) : 5 cartes `is-soldout`, thumb `opacity 1`, **img `opacity .5`**, body `opacity 1`, `filter: grayscale(1)` — capture `judge-soldout.png` |
| 489-490 | `@media (prefers-reduced-motion) .lc-v6-hero, .lc-v6-hero *` | **GAGNE ×2** | 85 éléments : `animation-name` non-`none` = **0**, `transition-duration` = `0s` |
| 543-548 | `@media print *, *::before, *::after` (6 décl.) | **GAGNE ×6** | média `print` : `color` = `rgb(0,0,0)` partout, fonds `rgba(0,0,0,0)` + `background-image:none`, `box-shadow/text-shadow: none`, `border-color rgb(153,153,153)` |
| 578-581, 585 | `@media print .lc-modal-backdrop / body:has(...)` (5 décl.) | **GAGNE ×5** | fiche imprimée : backdrop `position:static`, `display:block`, `overflow:visible`, `height:auto` ; `#main` **et** `.lc-footer` `display:none` → **1 page** (`print-modal-fiche.png` lue) |
| 181 | `.lc-v6-hero .lc-btn--orange { box-shadow }` | REDONDANT | valeur identique servie par une règle plus prioritaire |
| 326 | `.lc-v6-famille-art img { display:block }` | REDONDANT | `block` déjà calculé |
| 398 | `@media (max-width:1099px) .lc-gallery { -webkit-overflow-scrolling }` | NO-OP CHROMIUM | propriété non implémentée (utile à Safari iOS) |
| 424 | `@media (min-width:900px) .lc-v6-hero-text { margin-top:0 }` | REDONDANT | déjà `0px` |
| 594 | `@media print .lc-gallery { grid-auto-flow: row !important }` | REDONDANT | `row` = valeur initiale |
| 88 | `.lc-v6-hero-media { width:100% }` | REDONDANT | div de flux normal : la largeur du conteneur est déjà la largeur utilisée (mesuré 360 px à 360 px de viewport) |
| **227** | `.lc-hero h1 { line-height / letter-spacing }` | **SÉLECTEUR MORT** | `.lc-hero` : 0 occurrence JSX, 0 dans le DOM (cf. §A) |
| **228** | `.lc-hero h1 em { -webkit-text-stroke-width }` | **SÉLECTEUR MORT** | idem |
| 567 | `@media print .lcf-cta-bar { display:none }` | HORS PÉRIMÈTRE | barre du tunnel |

> **Bilan : 0 déclaration PERDANTE sur 234.** 228 gagnent (dont 20 seulement dans un état conditionnel : survol, focus clavier, heures d'ouverture, rupture, `reduced-motion`, `print`), 6 sont redondantes ou non implémentées, 3 portent un sélecteur mort (`.lc-hero`, cf. §A), 1 est hors périmètre. Le résultat du cycle 15 est **confirmé**.

## B-2 · TABLEAU PAR CLASSE DE DÉFAUT

| classe reprise du registre | méthode de ce cycle | résultat |
|---|---|---|
| déclarations `styles-v6-brand.css` qui perdent leur intention | 234 déclarations mutées, 3 routes × 7 largeurs + `print`, puis **41 rejugées une par une** dans leur état réel (survol, focus clavier, 20h, rupture, `reduced-motion`, `print`, modale) | **0 PERDANTE** — cf. §B-1 |
| tuiles orphelines (grilles reconstruites par position réelle) | conteneurs `display:grid` + `flex-wrap` sur **4 routes × 14 largeurs** | **0 orpheline**, hors `.lc-menu-grid` (catalogue à N serveur, délégué) : `[2,2,…,1]` à 768–1100 et `[4,1]` à 1280/1440 |
| falaises de hauteur MONTANTES | encadrement à **±1 px** de 10 paliers (400/520/600/660/700/768/900/1100/1200/1280) sur home, `#menu`, `#loyalty` | **2 seules falaises à 1 px** : 400→401 **+260 px** (familles — **déjà déléguée**) et 600→601 **+44 px** (bande de chiffres). La 2ᵉ est la libération volontaire du bloc `styles-mobile.css:65-67` (`gap 10→14`, `padding 18/14→24`) : rien de rogné, rien d'orphelin, la grille reste **1 colonne** de 320 à 899 px (l'intention v6 `:359-364` bat bien le `repeat(2,1fr) !important` de v1). `#menu` et `#loyalty` : **aucune** falaise montante à 1 px |
| `opacity` sur du TEXTE (opacité cumulée des ancêtres) | 56 passages | **0** sous 0,99 — sauf 3 nœuds à **0,998–0,999** (révélation en cours sur la dernière carte de `#menu`) |
| `<img>` : repli présentable sous 404 forcé | toutes les images interceptées en 404 sur home (20/20) et `#menu` (28/28) | **1 seule zone vide** : `.lc-v6-hero-media` 666×444 (**déjà déléguée**). Tous les autres conteneurs affichent un emoji **pertinent** et dimensionné à la boîte (🌶️ 🥖 🍔 🐟 🌯 🍰 🥤 🧒 …) — captures `img404-home.png`, `img404-fam.png` lues |
| régimes photo par conteneur | 5 conteneurs porteurs d'images sur la home | cohérents et distincts : hero `cover`/`pad 0` ; `lc-featured-art`, `lc-card-item-thumb`, `lc-gallery-tile` `contain` + halo radial + padding proportionnel ; `lc-v6-famille-art` `contain` + halo propre. Aucun mélange de régime dans un même conteneur |
| contraste AA exhaustif, fonds `rgba` **composités** | 56 passages SPA + 15 passages légaux, opacité cumulée traitée comme alpha du texte | **0 échec** |
| impression : chaque route ET chaque modale | média `print` + **PDF A4 réel**, comptage de pages, débordement > 794 px, texte blanc, opacité < 0,4 | **fiche = 1 page**, **compte = 1 page**, rien d'amputé, rien d'illisible (0 texte blanc, 0 texte pâle sur les 7 impressions). Routes : home 9 p., `#menu` 9 p., `#loyalty` 1 p., `#orders` 1 p. (longueur naturelle). `.lc-cart-drawer` est hors page (x 794→1214) donc **non imprimé** ; ouvert il ramène les 9 pages de la page derrière — **déjà délégué** (« tiroir panier qui s'imprime ») |
| jumeaux desktop / mobile | tous les nœuds texte visibles, 1440 vs 390 | **0 divergence réelle**. Les 10 chaînes « desktop seulement » = les 9 descriptions de familles masquées sous 400 px (**déléguée**) + le mot « Commandes », dont les 3 voisins de nav n'apparaissent sur mobile que par coïncidence ailleurs dans la page : les **4 liens sont bien dans le tiroir burger** (`components.jsx:118-121, 155-162`, mesuré : les 4 `.lc-nav-link` sont à `0×0` à 390 px, les 4 `.lc-mobile-link` les remplacent) |
| jargon d'atelier côté client | regex `NF525|POS|KDS|borne|backend|frozen|SSOT|payload|wizard|V1|undefined|NaN|null|[object|Label.` sur les 56 passages SPA | **0 occurrence** |
| chiffres et promesses affichés | recomptés dans le DOM et croisés avec la source | **exacts** : « 38 références » = 4+2+6+2+2+2+3+15+2 = **38** ; « 9 catégories » / « Neuf familles » = **9** tuiles ; « les 5 questions » = **5** accordéons ; Méga 8,00 € identique en vedette et en carte ; « 1 € = 10 pts / 100 pts = 1 € dès 50 pts » **identique** sur la home, le comparatif et `#loyalty` ; **28 prix rendus = 28 prix source, 0 divergence** |
| états conditionnels | 15h / 18h / 20h / 23h59 / 00h30 / 00h59 / 01h + rupture réelle + recherche vide + `reduced-motion` + `prefers-contrast:more` + `forced-colors` | **tous corrects**. 15h et 01h → « TOUS LES SOIRS · 18H – MINUIT » / « HORAIRES 18H – 00H » ; 18h→23h59 → « OUVERT · SERVICE EN COURS » + pastille verte ; 00h30 et 00h59 → « DERNIÈRES COMMANDES ». Rupture : 5 cartes `is-soldout`, badge « ÉPUISÉ », motif « Rupture frigo », prix toujours pleinement lisible. Recherche vide : « 0 résultat », « Rien trouvé », « Essaye avec d'autres mots », bouton RÉINITIALISER. Préférences : hauteur inchangée, 0 texte à opacité 0, 0 erreur |
| erreurs JS et débordements horizontaux 320/360/768/1024/1440 | inclus dans les 56 passages + 15 passages légaux | **0 `pageerror`, 0 `console.error`, 0 débordement du document**. Seuls `overflow`-scrollables intentionnels : `.lc-marquee-track` (bandeau défilant) et la bande `.lc-gallery` sous 1100 px (`grid-auto-flow: column` + `overflow-x: auto` à 1099, `row` à 1100 — **conforme à la note du brief**) |
| STYLE des pages légales | 5 pages × 3 largeurs + mesure des colonnes + orphelines + `print` | contraste 0 échec, 0 débordement, 0 saut de titre, 0 orpheline, impression noir sur blanc. **1 DÉFAUT** → §C |

## C. DÉFAUT NOUVEAU — **1 P1** (PROUVÉ)

### C-1 · P1 — L'encadré d'ouverture de la page ALLERGÈNES échappe à la colonne de mesure des pages légales : **1 encadré sur 5** est 65 % plus large que ses 4 jumeaux

**Ce que le design déclare.** Les pages légales tiennent leur texte dans une colonne de mesure :
- `legal/legal.css:49-54` — `.lc-legal-intro { max-width: 760px }`
- `legal/legal.css:57-60` — `.lc-legal-section { max-width: 800px }` — c'est ce conteneur qui borne **tous** les `h2`, `p`, `ul`, `table` et encadrés du corps.
- `legal/legal.css:137-147` — `.lc-legal-callout` **ne déclare aucune `max-width`** : sa largeur vient entièrement de son parent.

**Ce qui se passe.** Les 4 autres encadrés du périmètre légal sont imbriqués **dans** une `.lc-legal-section` (`legal/cgv.html:123` et `:164`, `legal/privacy.html`, `legal/cookies.html`, et le **second** encadré d'allergènes `legal/allergens.html:143`). Le **premier** encadré d'allergènes, lui, est écrit au niveau supérieur, frère de `.lc-legal-intro` et enfant direct de `.lc-container` : **`legal/allergens.html:58`**. Il n'a donc aucune borne et prend toute la largeur du conteneur de page.

**Mesures (`r16-num.js`, largeurs réelles en px) :**

| largeur de fenêtre | encadré #1 (`allergens.html:58`) | encadré #2 (`allergens.html:143`) | `.lc-legal-intro` | `.lc-legal-section` |
|---|---|---|---|---|
| 800 | 736 | 736 | 736 | 736 |
| 900 | **828** | 800 | 760 | 800 |
| 1000 | **920** | 800 | 760 | 800 |
| 1100 | **1012** | 800 | 760 | 800 |
| 1440 | **1325** | 800 | 760 | 800 |

Parent direct mesuré de l'encadré #1 : `lc-container` (et non `lc-legal-section`).

**Ce que ça donne à l'œil** (capture **lue** : `legal-allergens-top.png`, 1440 px) : le pavé jaune « **Disponibilité de l'information :** … » barre la page d'un bord à l'autre tandis que le paragraphe INCO juste au-dessus s'arrête à 760 px et que tout ce qui suit (titres, listes des 14 allergènes, tableaux, second encadré) s'arrête à 800 px. Ses lignes font ~165–190 caractères, contre ~100 pour le reste du site. C'est le **seul** élément des 5 pages légales à sortir de la colonne de mesure, et c'est le premier encadré que lit un client venu chercher une information d'allergie.

**Forme du méta-défaut :** « **occurrence sur N** » — la règle de mesure gagne sur 4 encadrés sur 5 ; la 5ᵉ occurrence lui échappe non par choix de style mais par **imbrication de balise**. Aucune déclaration ne revendique une pleine largeur pour cet encadré ; l'écart est un accident, pas une intention.

**Statut :** en périmètre (« STYLE des pages légales »), **absent de la liste des connus/délégués** — laquelle ne couvre que le **contenu** de `legal/allergens.html`, pas sa mise en page. Reproduit à 4 largeurs, capture lue, `file:line` vérifié.

**Sévérité — argument des deux côtés, pour que l'owner puisse trancher.** Contre le P1 : rien n'est rogné, illisible, ni cassé ; le contraste passe ; c'est une page légale, pas la vitrine. Pour le P1 : c'est une intention de mise en page écrite dans la feuille et **provablement perdue sur 1 occurrence sur 5**, exactement la forme de défaut que ce registre a classée P1 aux cycles précédents (les eyebrows en mono du cycle 15 n'étaient pas davantage « cassées »). **Je le retiens en P1** ; l'owner peut le rétrograder, mais je ne l'arrondis pas pour préserver un verdict propre.

**Correctif d'une ligne (non appliqué — audit en lecture seule) :** envelopper `legal/allergens.html:58-63` dans une `.lc-legal-section`, ou ajouter `max-width: 800px` à `.lc-legal-callout` (`legal/legal.css:137`), ce qui aligne du même coup les 4 autres sans les changer.

## D. SUPPOSÉ (non retenu, non prouvé au niveau requis)
- Aucun. Toutes les pistes ouvertes ce cycle ont été soit prouvées (§C), soit **réfutées par la mesure** et refermées ci-dessus.

## E. RÉFUTATIONS DE CE CYCLE (pistes qui ressemblaient à des défauts et n'en sont pas)
1. **Falaise 600→601 px sur la bande de chiffres (+44 px)** — MONTANTE, donc suspecte. Réfutée : `styles-mobile.css:65-67` compacte volontairement la bande sous 600 px (`gap 10`, `padding 18/14`) ; au-delà, les valeurs nominales reprennent. Grille **1 colonne** de 320 à 899 px, `repeat(3)` à partir de 900 : aucune orpheline, rien de rogné. L'intention v6 (`styles-v6-brand.css:359-364`) **bat** bien le `repeat(2,1fr) !important` de la couche v1.
2. **19 « sélecteurs morts » signalés par la sonde de cascade** — 16 sont en réalité **conditionnels** : `.lc-v6-hero-statut b` et `.lc-v6-hero-pastille` n'existent qu'en heures d'**ouverture** (prouvé à 20h), `.lc-card-item.is-soldout` n'existe qu'en **rupture** (prouvé par injection API), `.lc-modal-backdrop` du bloc `print` n'existe qu'avec une **modale ouverte** (prouvé par l'impression de la fiche). Seuls `.lc-hero h1` / `.lc-hero h1 em` (3 déclarations, `:227-228`) sont réellement morts.
3. **« Commandes » visible en desktop et pas en mobile** — réfutée : les 4 liens de nav sont à `0×0` à 390 px et remplacés par les 4 `.lc-mobile-link` du tiroir burger ; « Accueil », « Menu » et « Fidélité » ne « survivaient » en mobile que parce que ces trois mots apparaissent ailleurs dans la page (pied de page, eyebrow, titre de bloc).
4. **Accent circonflexe de « CHAÎNES » qui semblait mordre le « A »** — réfutée au zoom ×10 (`A-chaines-max.png`) : l'accent flotte **au-dessus** de la hauteur de capitale, aucun chevauchement d'encre, aucun rognage par le conteneur.
5. **3 nœuds texte à opacité cumulée 0,998** — réfutée : révélation en cours sur la dernière carte de `#menu`, valeur cible 1.

## F. HORS PÉRIMÈTRE (à part, hors verdict)
- `legal/allergens.html:60` emploie « **wizard de personnalisation** » dans un texte destiné au client : jargon d'atelier. Le **contenu** de `legal/allergens.html` est explicitement délégué par le brief ; seul son STYLE relevait de mon verdict. Signalé pour information.
- Modale de compte : « `// BON RETOUR` » (jargon `//` du tunnel/compte — délégué).
- `.lc-cart-drawer` ouvert → impression de 9 pages de la page derrière (délégué).
- Pas de repli sur la photo du hero sous 404 (délégué).
- Falaise familles 400→401 px (+260 px), saut `h2→h4` du comparatif, saut `h1→h3` de `#loyalty`, `alt=""` sur les 9 vignettes de familles avec titre visible adjacent, `.lc-menu-grid` à N serveur, panneau droit vide de la fiche (délégués).

## G. NON TESTÉ
- Rendu réel sur Safari / iOS et Firefox (moteur Chromium seul) — la déclaration `-webkit-overflow-scrolling: touch` (`:398`) reste donc non prouvée sur sa cible.
- Impression papier physique (PDF A4 Chromium comme substitut).
- Lecteur d'écran réel (VoiceOver / NVDA) — seuls les noms accessibles et l'ordre de tabulation ont été mesurés.
- Tunnel, compte, commandes, wizard, backend, contenu contractuel légal : hors périmètre par consigne.

---

# VERDICT S7 : **NON CONVERGÉ**

P0 = **0**. P1 = **1** (§C-1, mise en page d'une page légale). Le compteur de convergence **repart** : le cycle 15 (0 P1) n'est pas confirmé par un second cycle à 0.

Historique mis à jour : 5, 2, 2, 2, 3, 2, 3, 2, 1, 1, 3, 2, 1, 2, 0, **1**.

Tout le reste est **confirmé vert** : 0 déclaration perdante sur 234, 0 orpheline hors catalogues serveur, 0 nouvelle falaise montante, 0 échec de contraste sur 71 passages, 0 erreur JS, 0 débordement de document, impression des modales à 1 page, chiffres affichés exacts au comptage, 11 états conditionnels corrects. Le défaut retenu est **isolé, hors de la vitrine elle-même, et corrigible en une ligne** — un 17ᵉ cycle qui ne fait que cela devrait rendre CONVERGÉ.

**Aucun fichier du site n'a été modifié.**
