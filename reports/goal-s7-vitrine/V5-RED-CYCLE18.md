# V5 · RED CYCLE 18 — vitrine « Le Cayenne » (lecture seule) — CYCLE DE CONFIRMATION

Périmètre S7 : `screens.jsx`, `components.jsx`, `screens-v3.jsx`, `styles-v6-brand.css`, `assets/**`, STYLE des pages légales (`legal/legal.css`).
Site : `http://127.0.0.1:8899` (React + Babel, hash routing). HEAD audité : `6d6dd9d`.
Captures : `reports/goal-s7-vitrine/shots/red18/` · sondes : `~/.claude/jobs/1269b1ef/tmp/r18-*.js`
Historique P1 : 5,2,2,2,3,2,3,2,1,1,3,2,1,2,0,1,0. Séquence réelle : **15 propre / 16 sale (1 P1) / 17 propre**. Ce cycle est le candidat au 2ᵉ cycle vierge CONSÉCUTIF.

## 0. CONTRÔLE DE SANTÉ — VERT (`r18-sante.js` / `r18-sante.json`, captures `sante-*.png`)

| route demandée | `location.hash` obtenu | `h1` réel | `#root` | nav | hauteur stable | `pageerror` / `console.error` |
|---|---|---|---|---|---|---|
| `''` (home) | `""` | « Fait maison, chaque soir. » | 1 enfant | oui | 7 825 px | 0 / 0 |
| `#menu` | `"#menu"` | « Tout ce qu'on cuisine. » | 1 enfant | oui | 3 984 px | 0 / 0 |
| `#loyalty` | `"#loyalty"` | « Connecte-toi pour cumuler. » | 1 enfant | oui | 1 243 px | 0 / 0 |
| `#orders` | `"#orders"` | *(aucun `h1`)* — « // MES COMMANDES » | 1 enfant | oui | 1 243 px | 0 / 0 |

4 surfaces **distinctes** (`h1` + hauteurs) : aucune retombée silencieuse sur la home. `.lc-v6-hero` = 1 sur la home, 0 ailleurs.
Mesuré au même passage : `.lc-v6-hero .lc-btn` = **1**, `.lc-v6-hero button:not(.lc-btn)` = **0**, `.lc-v6-preuves button` = **0**, `.lc-v6-preuves a` = **0** sur les 4 routes.

---

# A. ÉTAT DU CHANGEMENT DU CYCLE 17 (dette fermée par `6d6dd9d`)

## A-2 · `styles-v6-brand.css` — 6 sélecteurs morts retirés

### (a) Énumération MÉCANIQUE de TOUS les sélecteurs — `r18-sel.js` / `r18-sel.json`

Les **76 blocs de déclarations** de `styles-v6-brand.css` lus depuis le CSSOM réel, éclatés en **72 sélecteurs composés distincts**, chacun interrogé par `querySelectorAll` (pseudo-états/pseudo-éléments retirés du sondage) sur **12 contextes** :
`route:home` · `route:menu` · `route:loyalty` · `route:orders` · `horloge:20h` (ouvert) · `horloge:15h` (fermé) · `fiche-produit` · `tiroir-panier` · `tiroir-burger-390` · `rupture-api` (`is_available:false` réécrit à la volée) · `print:home` · `print:menu` — page défilée jusqu'à hauteur STABLE à chaque passage.

**Résultat brut : 8 sélecteurs à 0 nœud. Après vérification, 6 sont des artefacts de sonde, 1 est hors périmètre, 1 est réellement mort.**

| sélecteur à 0 | `file:line` | verdict |
|---|---|---|
| `::before`, `::after` | `styles-v6-brand.css:547` (`*, *::before, *::after` du bloc `@media print`) | **ARTEFACT** — Chrome normalise `*::before` en `::before` dans `selectorText`, mon sondage retire le pseudo et obtient une chaîne vide. Le bras `*` de la même règle est vivant → règle **VIVANTE**. |
| `.lc-detail-art` | `:262` | **VIVANT** — 1 nœud dès que la fiche produit est réellement ouverte (`r18-modal.js` : `detailArt: 1`, `background-image` = le `radial-gradient` attendu). Mon premier passage n'avait pas ouvert la modale. |
| `.lc-modal-backdrop` | `:581` (`@media print`) | **VIVANT** — 1 nœud (`components.jsx:282`), `display: block` conservé en `print` avec la fiche ouverte. |
| `body:has(.lc-modal-backdrop) #main` | `:588` | **VIVANT** — 1 nœud, et `#main` passe bien à `display: none` en `print` fiche ouverte. |
| `body:has(.lc-modal-backdrop) .lc-footer` | `:589` | **VIVANT** — 1 nœud, `.lc-footer` → `display: none` en `print` fiche ouverte. |
| `.lcf-cta-bar` | `:571` (`@media print`) | **HORS PÉRIMÈTRE** (barre du tunnel) — déjà consigné au cycle 17. |
| **`.lc-v6-preuves button:focus-visible`** | **`:485`** | **RÉELLEMENT MORT** — 0 nœud sur les 12 contextes. `.lc-v6-preuves` ne contient que `DIV` + `SPAN` (`screens.jsx:181-183`), 0 focusable (`preuvesFocusables: 0`). Voir P2-1. |

`.lc-hero-art-tag`, `.lc-v6-eyebrow`, `.lc-v6-neon`, `.lc-v6-hero a:focus-visible`, `.lc-v6-preuves a:focus-visible` : **absents de la feuille** (`grep` → 0 occurrence). Retrait **confirmé**.
Le nouveau `.lc-v6-hero .lc-btn:focus-visible` (`:484`) est **VIVANT** : 1 nœud.
0 `pageerror`, 0 `console.error` sur les 12 contextes.

### (b) Focus clavier du bouton du hero — **TOUJOURS VISIBLE, correctif OPÉRANT** (`r18-focus2.js`)

Vrai parcours clavier depuis le début du document : le bouton du hero est atteint au **9ᵉ `Tab`**.
`activeElement` = `BUTTON.lc-btn.lc-btn--orange` « Commander maintenant », `:focus-visible` = **true**,
`outline: 3px solid rgb(255, 184, 0)` (`--v6-neon`), `outline-offset: 3px` — **exactement la déclaration `:484-488`**.
Captures **LUES** : `focus-hero-ring.png` (anneau jaune franc et continu sur les 4 côtés, sur fond nuit) vs témoin `focus-hero-noring.png` (aucun anneau). Le changement de sélecteur **n'a rien cassé**.
Témoin : `.lc-v6-hero button` = 1, dont `.lc-btn` = 1 → l'ancien bras `button` et le nouveau bras `.lc-btn` couvrent **le même et unique** focusable du hero. Aucune perte de couverture.

### (c) Eyebrows en grotesque — **CONFIRMÉ** (`r18-focus.json`)

`font-family` calculée sur **tous** les `[class*="eyebrow"]` des 4 routes + fiche produit :
`Inter, system-ui, -apple-system, sans-serif` — **0 occurrence de police mono**, `letter-spacing: 1.8432px` (= `0.16em`).
Home 6 `.lc-eyebrow` (« Le service », « Menu », « Facebook », « La carte », « La différence », « FAQ »), `#menu` 1 (« Menu complet »), fiche produit : `.lc-detail-eyebrow` incluse. Capture LUE `eyebrow-fiche.png`.

## A-1 · `legal/legal.css` — `margin-inline: auto` retiré, `max-width: 800px` conservé — **CORRECTIF OPÉRANT, 0 RÉGRESSION**

Source vérifiée : `legal/legal.css:146-161` — `max-width: 800px` présent, `margin-inline` **absent** (`grep -n "margin-inline" legal/legal.css` → 0), `margin: 20px 0` (`:157`), `box-sizing: border-box` hérité.
`legal.css` toujours chargée **après** `styles-v6-brand.css` sur les 5 pages (`allergens.html:19-20`).

**Compte exact des encadrés — 7, pas 9.** `r18-legal.json` mesure le DOM réel de chaque page :
`mentions.html` **0** · `cgv.html` **2** · `privacy.html` **1** · `cookies.html` **2** · `allergens.html` **2** = **7**.
(Le cycle 16 en annonçait 9 ; le commit `6d6dd9d` a lui-même rectifié à 7. Confirmé indépendamment ici.)

**5 pages × 4 largeurs (360 / 768 / 1024 / 1440) = 20 passages, 7 encadrés × 4 largeurs = 28 mesures. `anomalies: []`.**

| largeur | `.lc-legal-section` gauche / largeur | encadrés : gauche / largeur | `margin-left` | rognage | débordement doc |
|---|---|---|---|---|---|
| 360 | 16 / 328 | **16 / 328** (7/7) | `0px` | non | non |
| 768 | 30,7 / 706,6 | **30,7 / 706,6** (7/7) | `0px` | non | non |
| 1024 | 41 / 800 | **41 / 800** (7/7) | `0px` | non | non |
| 1440 | 57,6 / 800 | **57,6 / 800** (7/7) | `0px` | non | non |

- **Largeurs uniformes** : un seul `width` distinct par page-largeur sur les 20 passages.
- **Calage à GAUCHE aligné sur le corps** : bord gauche de l'encadré = bord gauche de `.lc-legal-section` à ±0,0 px aux 4 largeurs. `margin-left: 0px` / `margin-right: 0px` — l'intention déclarée dans le commentaire `:148-152` est bien celle qui s'applique.
- **0 rognage** : `scrollWidth > clientWidth` et `scrollHeight > clientHeight` faux sur les 28 mesures.
- **0 débordement**, **0 `pageerror`**, **0 `console.error`** sur les 20 passages.
- **16 captures produites, 4 LUES** : `L18-allergens-1440.png` (l'encadré jaune s'arrête au même fer que le `h1`, l'intro INCO et la grille des 14 allergènes — grille 4+4 complète, rien de coupé), `L18-cookies-768.png` (encadré §2 pleine colonne, liseré jaune à gauche au fer du corps), `L18-privacy-1024.png` (encadré « Exercice des droits » au fer des lignes « Droit d'accès… », lien `rgpd@lecayenne.fr` intact), `L18-cgv-360.png` (encadré art.5 en colonne étroite, texte entier, aucun débordement).

**Verdict A-1 : la dette du cycle 17 est fermée proprement. Le fichier ne prétend plus rien qu'il ne fasse.**

---

# B. RE-VÉRIFICATION DES CLASSES AYANT PRODUIT DES P1

## B-0 · Sweep mécanique — 4 routes × 14 largeurs = **56 passages** (`r18-sweep.js` / `r18-sweep.json`)

Largeurs : 320 · 360 · 390 · 400 · 401 · 430 · 520 · 600 · 768 · 900 · 1024 · 1100 · 1280 · 1440. Hauteur STABLE atteinte à chaque passage. **7 028 nœuds-texte** examinés.

| contrôle | résultat |
|---|---|
| `pageerror` | **0** sur 56 passages |
| `console.error` | **0** sur 56 passages |
| débordement de document (`scrollWidth > innerWidth`) | **0** sur 56 passages |
| `opacity` cumulée < 1 sur du TEXTE | **0** sur 7 028 nœuds |
| jargon d'atelier rendu (`TODO`, `undefined`, `NaN`, `[object`, `Label.`, `lorem`, …) | **0** |
| nœuds débordant du viewport | 7 par passage, **tous** = le tiroir panier FERMÉ hors-écran (`lc-cart-drawer`/`-head`/`-title`/`-body` à `left = vw`), le `lc-acc-form-back` du panneau compte fermé, et le `lc-marquee-track` (défilement volontaire, ancêtre `overflow-x`) — **aucun n'agrandit le document** (`docOverflow` faux partout). `lc-cat-tabs` à 520/600 = débordement **réfuté** au cycle 17. |

## B-1 · Grilles — recensement mécanique, rangées par POSITION RÉELLE

| grille | 320 → 600 | 768 → 1100 | 1280 / 1440 | orpheline |
|---|---|---|---|---|
| `.lc-v6-familles` (9) | `[3,3,3]` | `[3,3,3]` | `[3,3,3]` | non |
| `.lc-menu-grid` home (4) | `[1,1,1,1]` | `[2,2]` | `[4]` | non |
| `.lc-stats-grid` (3) | `[1,1,1]` | `[1,1,1]` puis `[3]` dès 900 | `[3]` | non |
| `.lc-compare-grid` (2) | `[1,1]` | `[2]` | `[2]` | non |
| `.lc-footer-grid--4col` (4), 4 routes | `[1,1,1,1]` | `[4]` | `[4]` | non |
| `.lc-hours-grid` (2) | `[1,1]` | voir B-2 | 2 colonnes | non |
| `.lc-v6-famille in` (descriptions) | 2 → **3** à 401 px | 3 | 3 | connu/délégué (falaise familles 400→401) |
| `.lc-menu-grid` de `#menu` | — | — | `[4,1]` | **connu/délégué** (« catalogues à N serveur orphelins `.lc-menu-grid` ») |

## B-2 · `.lc-hours-grid` — anomalie de bucketing **RÉFUTÉE** (`r18-hours.js`)

Le sweep grossier annonçait `[2]` à 1280 mais `[1,1]` à 1440 — non-monotonie apparente (plus large ⇒ moins de colonnes), qui aurait été un P1.
Mesure exacte à 768/900/1024/1100/1200/1280/1360/1440 : `grid-template-columns` = **1 colonne à 768** (`645.125px`, enfants empilés, Δtop = 305,8 px) puis **2 colonnes de 900 à 1440** (`398/326` → `658/539`), enfants **côte à côte** (`left` 72 vs 502 … 105,6 vs 795,8) aux 7 largeurs ≥ 900.
Les `Δtop` de −18,1 à +4,7 px sont des **hauteurs de cartes différentes**, pas un retour à la ligne : mon arrondi de rangée à 4 px les séparait. Capture **LUE** `hours-1440.png` : bloc adresse à gauche, tableau des 7 jours à droite, « Jeudi · Aujourd'hui » surligné en jaune, rien de coupé. **FAUX POSITIF, rejeté.**

## B-3 · Contraste AA exhaustif, fonds `rgba` COMPOSITÉS (`r18-contrast.js` / `r18-contrast.json`)

Empilement réel des fonds d'ancêtres (`source-over`) jusqu'au premier fond opaque, opacité cumulée appliquée à la couleur du texte, seuil 3:1 pour le grand texte / 4,5:1 sinon. Cibles : 4 routes + fiche produit ouverte, à **390 et 1440 px**, plus les **5 pages légales**. Page défilée jusqu'à hauteur STABLE (sans quoi les révélations non déclenchées produisent des centaines de faux échecs).

**2 033 nœuds-texte testés — 5 échecs, tous le MÊME motif, rejeté :**
le séparateur de fil d'Ariane `›` (`allergens.html:43` et jumeaux), 1,41:1, `<span aria-hidden="true">` — **purement décoratif et explicitement retiré aux technologies d'assistance**, teinté à dessein en `--gray-2` (`legal/legal.css:28`). Même nature que le `→` du tiroir burger déjà classé connu/délégué. **0 échec de contraste réel** sur les 4 routes, la fiche produit et les 5 pages légales.

## B-4 · `<img>` : repli présentable sous 404 forcé (`r18-states.js`, section `img`)

Toutes les requêtes `*.{png,jpg,jpeg,webp,gif,svg}` renvoyées en **404**.

| cible | `<img>` | cassées | cassées **visibles** | `alt` absent |
|---|---|---|---|---|
| home | 20 | 20 | **0** | 0 |
| `#menu` | 28 | 28 | **0** | 0 |

Aucune icône d'image brisée ne subsiste : le `onError` masque l'`<img>` et révèle l'emoji de repli — `screens-v3.jsx:132` (`display:none` sur l'image **+ `display:block` sur le frère suivant**) pour les vignettes et la fiche, `screens.jsx:155` (`display:none` seul) pour la photo du hero — le conteneur gardant son **régime photo** (`radial-gradient` de `styles-v6-brand.css:262`).
Captures **LUES** : `state-img404-menu.png` — les 28 vignettes affichent l'emoji du produit sur la scène nocturne, badges SIGNATURE/XL, prix et boutons `+` intacts, mise en page identique à la normale ; `state-img404-fiche.png` — le panneau `.lc-detail-art` conserve ses 680 px de haut, son dégradé et l'emoji piment en `display: block`. **Repli présentable, 0 défaut.** (L'absence de repli sur la photo du hero reste connue/déléguée.)

## B-5 · États conditionnels — **11/11 corrects** (`r18-states.js` / `r18-states.json`)

| état | résultat observé | verdict |
|---|---|---|
| horloge 15:00 | « Tous les soirs · 18h – minuit », pas de pastille | fermé, cohérent |
| horloge 18:00 | « Ouvert · service en cours » + pastille | ouvert |
| horloge 20:00 | « Ouvert · service en cours » + pastille | ouvert |
| horloge 23:59 | « Ouvert · service en cours » + pastille | ouvert |
| horloge 00:30 | « Ouvert · **dernières commandes** » + pastille | dernier créneau (heure réelle de fermeture = connue/déléguée owner) |
| horloge 00:59 | « Ouvert · dernières commandes » + pastille | idem |
| horloge 01:00 | « Tous les soirs · 18h – minuit », pas de pastille | fermé, bascule nette |
| rupture réelle (API `is_available:false`) | 28 cartes, **28 marques de rupture**, **0 bouton `+`** restant, 179 mentions « rupture/indisponible » | dégradation correcte, 0 débordement |
| recherche vide (`zzzzqqqxyz`) | 0 carte, état vide **« Rien trouvé »** rendu | correct |
| `prefers-reduced-motion: reduce` | `.lc-marquee-track` → `animation: none / 0s` | mouvement neutralisé |
| `prefers-contrast: more` | rendu intact, 0 débordement, 0 jargon | correct |
| `forced-colors: active` | `h1` `rgb(0,0,0)` sur `body` `rgb(255,255,255)`, bouton hero texte noir sur fond blanc | lisible, rien d'effacé |

0 `pageerror` sur les 11 états. 0 débordement de document. 0 jargon.

## B-6 · Impression — 4 routes + 3 modales + 5 pages légales, mesurées à **794 px** (`r18-print.js`)

| cible | `scrollWidth` | débordement > 794 | texte invisible (< 2:1) |
|---|---|---|---|
| route home / menu / loyalty / orders | **794** | 0 réel | 0 |
| modale fiche produit (`backdrop:1`, `detail:1`) | **794** | 0 réel | 0 |
| tiroir panier **ouvert** (`is-open`, `left: 374`) | **794** | **0** | 0 |
| modale compte (`backdrop:1`) | **794** | 0 réel | 0 |
| `legal/mentions`, `cgv`, `privacy`, `cookies`, `allergens` | **794** | **0** | 0 |

Les 7 nœuds « hors 794 » relevés sur 6 des 12 cibles sont le **tiroir panier FERMÉ** hors-écran (`left: 794`) et le bouton retour du panneau compte fermé : `scrollWidth` reste à 794 sur les 12 cibles, donc **aucune page imprimée ne déborde**. (Le tiroir panier qui s'imprime est connu/délégué.)
Captures **LUES** : `print-modale_fiche.png` (fiche seule sur la page, photo + titre + prix + « Personnaliser », `#main` et le pied de page bien masqués par `body:has(.lc-modal-backdrop)`), `print-legal_allergens.png` (fil d'Ariane, titre, encadré avec son liseré, les **14** allergènes en 3 colonnes 3+3+3+3+2, rien de rogné).
**1 219 nœuds-texte imprimés, 0 texte invisible.**

## B-7 · Chiffres et promesses au recomptage

| affirmation rendue | recompte réel | verdict |
|---|---|---|
| `#menu` : « **9 catégories** · **38 références** » | 9 entrées de catégorie ; 4+2+6+2+2+2+3+15+2 = **38** ; pastille « Tout **38** » ; « **38** résultats » ; `.lc-card-item` = **28** visibles (grille paginée par catégorie) | exact |
| home : « **Neuf familles**. » | `.lc-v6-familles` = **9** tuiles, rangées `[3,3,3]` | exact |
| `allergens` : « les **14** substances » | 14 fiches (3+3+3+3+2) | exact |
| hero : « **~10-15 min** » / fiche « Prêt en 10 min » | cohérent avec `item.time` | exact |
| horaires « 18h – 00h » sur 7 jours | 7 lignes Lundi→Dimanche, jour courant surligné | exact |

## B-8 · « Une couche annule mon intention » — chaque déclaration v6 gagne-t-elle ? (`r18-cascade.js`)

Chaque déclaration de `styles-v6-brand.css` comparée **déclaré vs calculé** sur les éléments qu'elle cible, 6 contextes (390 et 1440 px × home / `#menu` / fiche produit ouverte), blocs `@media` évalués par `matchMedia`. Après élimination des artefacts de résolution d'unités (`em`/`ch`/`vh`/`%` → px, expansion des raccourcis) : **25 écarts distincts**, dont **24 sont des surcharges de v6 PAR v6 lui-même** :

| écart | rule gagnante | verdict |
|---|---|---|
| `.lc-v6-familles { gap: 12px }` → 7px, `.lc-v6-famille { padding/border-radius }` → 9/8/12px, 15px (à 390) | `styles-v6-brand.css:354-357` (`@media (max-width: 400px)`) | v6 gagne — bloc mobile de v6 |
| `.lc-v6-preuve { padding: 22px 18px }` → 30px 26px (à 1440) | `:437` (`@media (min-width: 900px)`) | v6 gagne |
| `.lc-v6-hero-media { position: relative }` → absolute, `.lc-v6-hero-text { padding-bottom/margin-top }` → 0 (à 1440) | `:418`, `:427` (`@media (min-width: 900px)`) | v6 gagne |

**Le 25ᵉ méritait l'enquête et il est SAIN** — `styles-v6-brand.css:457` `.lc-hours .lc-hours-status { color: #6EE7A0; }` calculait `rgba(255,255,255,0.72)`.
Cause : la variante **FERMÉ** porte un `style` **inline** (`screens.jsx:341`) qui bat toute feuille. Or c'est exactement l'intention documentée en `:449-455` : le correctif du cycle 5 visait la variante **OUVERTE**, qui n'avait aucune surcharge.
Sonde dédiée `r18-hours-status.js` avec horloge truquée :

| état | heure | texte | `color` calculée | fond composité | contraste |
|---|---|---|---|---|---|
| **ouvert** | 20:00 | « Ouvert maintenant » | **`rgb(110, 231, 160)`** = `#6EE7A0` | `rgb(14,38,23)` | **10,39:1** |
| dernières commandes | 00:30 | « Dernières commandes » | **`rgb(110, 231, 160)`** | `rgb(14,38,23)` | **10,39:1** |
| fermé | 15:00 | « Horaires 18h – 00h » | `rgba(255,255,255,0.72)` (inline, voulu) | `rgb(25,25,25)` | 9,54:1 |

La déclaration v6 **gagne son intention** aux deux états où elle doit gagner, à ~10:1 comme annoncé dans son propre commentaire. Capture **LUE** `hstatus-ouvert.png` : pastille verte lisible, point pulsant. **FAUX POSITIF, rejeté** — et cela ferme au passage une lacune de ma section B-3, qui mesurait par défaut l'état FERMÉ.

## B-9 · Jumeaux desktop/mobile & régimes photo par conteneur (`r18-twins.js`)

**Jumeaux : aucun.** Aucun couple de composants `--desktop` / `--mobile` servant le même contenu (`jumeaux: []` sur les 4 routes ; l'unique nœud `[class*="-mobile"]` de `#menu` a **0 visible**).
**Aucun texte présent à 390 px et absent à 1440 px** sur les 4 routes (0/0/0/0) — rien n'est réservé au mobile puis perdu au desktop.
Textes présents à 1440 et absents à 390 : les **9 descriptions de familles** (connu/délégué : masquées sous 400 px) et les entrées de nav repliées dans le tiroir burger (« Menu », « Fidélité », « Commandes », « Catégories », « Filtres »). Rien d'autre.

**Régimes photo — 1 seul régime distinct par type de conteneur**, exactement l'intention de `styles-v6-brand.css:262` :

| conteneur | visibles | régimes distincts |
|---|---|---|
| `.lc-card-item-thumb` (home 4 / `#menu` **28**) | 4 / 28 | **1** |
| `.lc-v6-famille-art` | 9 | **1** |
| `.lc-gallery-tile` | 5 | **1** |
| `.lc-featured-art` | 1 | **1** |
| `.lc-v6-hero-media` | 1 | **1** |

Aucune « deuxième direction artistique » dans un même composant à l'écran. (Les deux directions des PNG *sur papier* restent connues/déléguées.)

## B-10 · Falaises de hauteur au PIXEL — **0 falaise MONTANTE nouvelle** (`r18-cliff.js` / `r18-cliff.json`)

Le sweep grossier (pas de 20 à 90 px) signalait 24 « montées ». Vérification au **pixel** dans 13 fenêtres (132 chargements) pour distinguer **pente** et **falaise** :

| fenêtre | série de hauteurs | Δ par pixel | lecture |
|---|---|---|---|
| home 396→406 | 9439 … 9445 **9705** … 9697 | −13 +7 +6 +6 **+260** +6 −14 +6 −12 +6 | l'unique montée = **400→401**, la falaise des familles **connue/déléguée** ; tout le reste ≤ +7 |
| home 896→906 | 8187 8192 8173 8177 **7556** … | +5 −19 +4 **−621** +4 +4 +4 +3 +4 +4 | falaise **DESCENDANTE** à 900 (`.lc-v6-preuves` 2→4 col) = normal |
| home 1020→1030 | 7903 → 7939 | +4 +3 +4 +4 +3 +4 +3 +4 +3 +4 | pente lisse |
| home 1276→1286 | 7603 → 7630 | +3 +2 +3 +3 +2 +3 +3 +2 +3 +3 | pente lisse |
| home 1435→1441 | 7813 → 7825 | +2 +3 +2 +2 +3 +0 | pente lisse |
| `#menu` 318→328 | 12835 → 13029 | **+21** ×9 (+5 une fois) | **pente parfaitement linéaire** |
| `#menu` 425→435 | 14771 → 14981 | **+21** ×10 | pente linéaire, 0 discontinuité |
| `#menu` 515→525 | 16606 → 16816 | **+21** ×10 | pente linéaire |
| `#menu` 595→605 | 18174 → 18356 | +21 ×8, +11, +3 | pente linéaire |
| `#menu` 1096→1104 | 7337 → 7381 | +5 +6 +5 +6 +5 +6 +5 +6 | pente lisse |
| `#menu` 1196→1204 | 7811 7816 7822 7827 **3727** … | +5 +6 +5 **−4100** +2 +1 +2 +1 | falaise **DESCENDANTE** à 1200 (1→4 col) = normal |
| `#loyalty` 396→406 | **1530** ×11 | +0 ×10 | plat |
| `#orders` 396→406 | **1530** ×11 | +0 ×10 | plat |

Les « +1836 / +1584 / +747 » du sweep grossier sont **le même échantillonnage de la pente à +21 px/px** de `#menu` (grille à 1 colonne, photos à ratio constant), déjà réfutée au cycle 17. **La seule montée > +7 px au pixel est celle de 400→401 px, connue et déléguée.** 0 `pageerror` sur les 132 chargements.

---

# C. DÉFAUTS

## C-0 · P0 = **0** · P1 = **0**

Rien de ce que j'ai mesuré ce cycle n'atteint le seuil P1 : aucun texte rogné, aucun débordement de document ni d'impression, aucun échec de contraste réel, aucune orpheline hors délégué, aucune falaise montante nouvelle, aucune erreur JS, aucun chiffre faux, aucun jargon rendu, aucune image sans repli présentable hors délégué, 11 états conditionnels corrects, focus clavier du hero intact, 5 encadrés légaux uniformes sur 7 encadrés × 4 largeurs.

## C-1 · P2 (sous le seuil, consigné) — **1 sélecteur mort SUBSISTE, et le commit affirme le contraire**

**`styles-v6-brand.css:485` — `.lc-v6-preuves button:focus-visible` est MORT et n'a pas été retiré.**

- Preuve DOM : **0 nœud** sur les **12 contextes** de `r18-sel.json` ; `.lc-v6-preuves` ne contient que `DIV` + `SPAN` (`screens.jsx:181-183`), `preuvesFocusables: 0`, `preuvesButtons: 0`, `preuvesAnchors: 0` (`r18-sante.json`, 4 routes).
- Le commit `6d6dd9d` annonce avoir retiré « trois bras de focus (`.lc-v6-hero a`, **`.lc-v6-hero button`**, `.lc-v6-preuves a`) ». Or le cycle 17 avait établi que les bras MORTS étaient `.lc-v6-hero a`, `.lc-v6-preuves a` et **`.lc-v6-preuves button`**, et que **`.lc-v6-hero button` était le bras VIVANT**. Le correctif a donc **retiré le bras vivant et conservé un bras mort**, tout en annonçant « 6 sélecteurs morts retirés » — il en reste 1 des 6 nommés.
- **Conséquence de rendu : NULLE, et vérifiée comme telle.** Le bras de remplacement `.lc-v6-hero .lc-btn:focus-visible` (`:484`) est vivant (1 nœud) et couvre le seul focusable du hero : `.lc-v6-hero button` = 1, dont `.lc-btn` = 1. Anneau jaune `3px #FFB800` mesuré et **capturé** (`focus-hero-ring.png` vs `focus-hero-noring.png`). Aucune perte d'accessibilité, aucun pixel changé.
- **Classement.** Une règle CSS morte groupée à côté d'une règle vivante, à effet de rendu nul, a été classée « dette cosmétique, 0 P1 » aux cycles 15, 16 et **17** ; je conserve ce barème pour ne pas gonfler le compteur sur un critère que je viens de durcir. **P2.** Ce qui est en revanche à noter sans l'arrondir : c'est la **quatrième annonce de commit non vérifiée** de la série (après « max-width + centrage », « `.lc-v6-eyebrow` retirée », « 9 encadrés »). Le motif de fond de cette session n'est pas dans le rendu du site — il est dans l'écart entre ce que les commits affirment et ce que le code fait.

## C-2 · Observation (hors verdict) — jeton de cache-buster non incrémenté

`index.html:40` et `legal/allergens.html:19-20` chargent `styles-v6-brand.css?v=20260729d` et `legal.css?v=20260729d`, alors que **les deux fichiers ont changé le 2026-07-30** (`6d6dd9d`). Un visiteur revenant peut servir l'ancienne feuille depuis son cache. Sans conséquence ici (les deux modifications sont neutres au rendu), mais c'est exactement le mécanisme du « chunk périmé » déjà rencontré sur la borne. **Relève du déploiement, hors périmètre S7 ; signalé sans être compté.**

---

# D. FAUX POSITIFS REJETÉS (reproduits puis réfutés, non remontés)

1. **`.lc-hours-grid` non monotone** (2 colonnes à 1280, empilée à 1440) — artefact de mon arrondi de rangée à 4 px ; mesure exacte : 2 colonnes côte à côte de 900 à 1440. Capture LUE.
2. **7 sélecteurs morts** (`::before`, `::after`, `.lc-detail-art`, `.lc-modal-backdrop`, `body:has(…) #main`, `body:has(…) .lc-footer`, et le comptage de `.lc-v6-hero .lc-btn:focus-visible`) — 2 artefacts de normalisation CSSOM (`*::before` → `::before`), 4 vivants dès que la modale est réellement ouverte, 1 artefact d'ordre d'alternance dans mon retrait de pseudo-classes.
3. **5 échecs de contraste** — le `›` du fil d'Ariane, `aria-hidden="true"`, purement décoratif.
4. **`.lc-hours-status` : déclaration v6 perdante** — perd uniquement face au style inline de la variante FERMÉE, ce qui est l'intention documentée ; gagne à 10,39:1 dans les deux états OUVERTS.
5. **24 « falaises montantes »** du sweep grossier — échantillonnage d'une pente linéaire à +21 px/px sur `#menu` et à +2 à +7 px/px sur la home ; au pixel, la seule montée > +7 est celle de 400→401, connue/déléguée.
6. **~40 nœuds « débordants »** par passage — tiroir panier et panneau compte FERMÉS, positionnés hors-écran, plus le bandeau de galerie à défilement volontaire ; `scrollWidth` du document jamais dépassé, ni à l'écran ni à 794 px.
7. **10 textes « perdus sur mobile »** — les 9 descriptions de familles (connu/délégué sous 400 px) et les entrées de nav repliées dans le tiroir burger.

---

# E. HORS PÉRIMÈTRE / CONNUS-DÉLÉGUÉS RENCONTRÉS (à part, hors verdict)

`.lcf-cta-bar` (barre du tunnel, `:571`) · tiroir panier qui s'imprime · falaise familles 400→401 px et descriptions masquées sous 400 px · orphelines de `.lc-menu-grid` à N serveur (`[4,1]` sur `#menu` à 1280/1440) · absence de repli sur la photo du hero · heure réelle de fermeture (« Ouvert · dernières commandes » à 00:30) · `→` du tiroir burger · débordement de `.lc-cat-tabs` (réfuté) · contenu rédactionnel/contractuel des pages légales · tunnel, compte, commandes, wizard, backend.

# F. NON TESTÉ

- Navigateurs autres que Chromium (Safari/WebKit, Firefox) — pas d'accès dans cette session.
- Impression **papier réelle** : mesurée par émulation `@media print` à 794 px, pas par PDF imposé.
- Lecteurs d'écran réels (VoiceOver/NVDA) — l'arbre d'accessibilité n'a été vérifié que par attributs et parcours `Tab`.
- Le contenu rédactionnel légal, hors périmètre par consigne.
- `#loyalty` / `#orders` connectés (contenu authentifié) : mesurés en état déconnecté.

---

# VERDICT S7 — **CONVERGÉ**

**P0 = 0 · P1 = 0.** C'est bien le **2ᵉ cycle vierge CONSÉCUTIF** : le cycle 17 (HEAD `153b78c`) et ce cycle 18 (HEAD `6d6dd9d`) sont tous deux à P0 = 0 et P1 = 0. La séquence complète est 15 propre / 16 sale (1 P1) / **17 propre** / **18 propre** — la condition « deux cycles consécutifs à P0=0 et P1=0 » est satisfaite. Historique P1 : 5,2,2,2,3,2,3,2,1,1,3,2,1,2,0,1,0,**0**.

**État du changement du cycle 17** (`6d6dd9d`) : **opérant sur les deux volets, sans régression.**
- `legal/legal.css` : `margin-inline: auto` bien retiré, `max-width: 800px` conservé — 7 encadrés (et non 9) uniformes et ferrés à gauche au fer du corps de texte sur 5 pages × 4 largeurs, 0 rognage, 0 débordement.
- `styles-v6-brand.css` : 5 des 6 sélecteurs annoncés effectivement retirés ; le focus clavier du hero est **toujours visible** (anneau `3px #FFB800`, capturé) via le nouveau bras `.lc-v6-hero .lc-btn:focus-visible` ; les eyebrows sont bien en grotesque (`Inter`) sur les 4 routes + la fiche produit. **1 sélecteur mort subsiste** (`.lc-v6-preuves button:focus-visible`) — P2, effet de rendu nul, mais 4ᵉ annonce de commit non vérifiée.

## Tableau par classe du méta-défaut

| classe | résultat |
|---|---|
| « occurrence sur N » | **0** — 7/7 encadrés légaux, 9/9 familles, 28/28 vignettes, 5/5 tuiles galerie, 1 seul régime photo par conteneur |
| « ce que le correctif révèle » | **0 P1** — le retrait des bras morts révèle 1 bras mort restant (P2), aucun effet visible |
| « une couche annule mon intention » | **0** — 25 écarts examinés, 24 = v6 surchargeant v6 par ses propres `@media`, 1 = style inline voulu de l'état FERMÉ |
| « le correctif casse ailleurs » | **0** — focus du hero intact ; encadrés légaux uniformes aux 4 largeurs ; 0 régression sur 56 passages |
| « le correctif affirmé mais inopérant » | **1 P2** — `.lc-v6-preuves button:focus-visible` toujours là malgré l'annonce contraire |

## Preuves de ce cycle

56 passages de sweep (4 routes × 14 largeurs, **7 028** nœuds-texte) · **72** sélecteurs v6 énumérés depuis le CSSOM et testés sur **12** contextes · **132** chargements de mesure de falaise au pixel · **2 033** nœuds-texte en contraste composité sur 10 surfaces + 5 pages légales · 20 passages légaux (7 encadrés × 4 largeurs) · **12** cibles d'impression à 794 px (**1 219** nœuds-texte, 0 invisible) · **11** états conditionnels · 48 images forcées en 404 sur 3 surfaces · parcours clavier réel jusqu'au 9ᵉ `Tab` · **~40 captures produites, 12 LUES**.
**0 `pageerror`, 0 `console.error`, 0 débordement de document, 0 échec de contraste réel, 0 falaise montante nouvelle, 0 jargon rendu, chiffres exacts au recomptage. Aucun fichier du site modifié.**
