# V5 — RED-TEAM CYCLE 11 (lecture seule) — vitrine S7 « Le Cayenne »
Date : 2026-07-30 · Périmètre : screens.jsx, components.jsx, screens-v3.jsx, styles-v6-brand.css, assets/**, STYLE des pages légales.
Captures : reports/goal-s7-vitrine/shots/red11/ · Scripts : ~/.claude/jobs/1269b1ef/tmp/r11-*.js

## VERDICT S7 (résumé)
**NON CONVERGÉ** — P0 = 0, **P1 = 3**. Heal A1 (heure charnière) confirmé 8/8 horloges ; heal A2 (grille de stats) à moitié seulement : la moitié desktop est cassée par le heal lui-même. Détail en fin de fichier.

## A. ÉTAT DES 2 HEALS DU CYCLE 10

### A2 — Grille de stats : **MOITIÉ MOBILE OK / MOITIÉ DESKTOP CASSÉE PAR LE HEAL** → P1
`styles-v6-brand.css:345` : `.lc-stats-grid { grid-template-columns: 1fr !important; }` — règle
**hors media query**, avec `!important`.
`styles-v6-brand.css:375` (dans `@media (min-width: 900px)`) : `.lc-stats-grid { grid-template-columns: repeat(3, minmax(0,1fr)); }` — **sans** `!important`.
Même sélecteur, même spécificité : `!important` gagne quelle que soit la largeur. Le heal a donc
neutralisé sa propre règle desktop.

Mesuré (`r11-grid.js`, hauteur stabilisée par défilement lent) — `grid-template-columns` calculé :
| largeur | 320 | 360 | 480 | 600 | 768 | 1024 | 1440 |
|---|---|---|---|---|---|---|---|
| colonnes | 1 | 1 | 1 | 1 | 1 | **1** | **1** |
| attendu | 1 | 1 | 1 | 1 | 1 | 3 | 3 |

Preuve lue : `shots/red11/grid-1440-settled.png` — trois bandes pleine largeur (1325 px chacune),
« 1€ = 10pts », « 7j/7 », « 38 » empilées. Aucune tuile orpheline (l'objectif énoncé est atteint),
mais la grille desktop 3 colonnes annoncée par le commentaire du heal n'existe plus à AUCUNE largeur.
Classe = « une couche annule mon intention », ici la couche est **le heal lui-même**.

### A1 — Heure charnière 00h00-00h59 : **HEAL CONFIRMÉ, 8/8 horloges cohérentes** ✅
Horloge figée (`r11-clock.js`, `Date` surchargé avant chargement, viewport 1280) :
| heure | hero (`screens.jsx:159`) | panneau horaires (`screens.jsx:332`) | verdict |
|---|---|---|---|
| 23h59 | Ouvert · SERVICE EN COURS | OUVERT MAINTENANT | ✅ |
| 00h00 | Ouvert · DERNIÈRES COMMANDES | DERNIÈRES COMMANDES | ✅ |
| 00h30 | Ouvert · DERNIÈRES COMMANDES | DERNIÈRES COMMANDES | ✅ |
| 00h59 | Ouvert · DERNIÈRES COMMANDES | DERNIÈRES COMMANDES | ✅ |
| 01h00 | Tous les soirs · 18h – minuit | HORAIRES 18H – 00H | ✅ |
| 18h00 | Ouvert · SERVICE EN COURS | OUVERT MAINTENANT | ✅ |
| 20h00 | Ouvert · SERVICE EN COURS | OUVERT MAINTENANT | ✅ |
| 15h00 | Tous les soirs · 18h – minuit | HORAIRES 18H – 00H | ✅ |
Preuve lue : `shots/red11/st-lastcall0030-hours.png` (pastille verte + « DERNIÈRES COMMANDES »,
plus aucun « OUVERT MAINTENANT »). Borne 00:59 intacte (`screens.jsx:94`).

**Surfaces affirmant encore une amplitude à 00h30 — recensement exhaustif (3, aucune nouvelle) :**
`screens.jsx:126-132` tableau des 7 jours « 18h — 00h » · `screens.jsx:415` tuile « 7j/7 · Ouvert 18h – 00h » ·
`components.jsx:201` pied de page « Ouvert 18h — 00h ».
`grep -rn "isOpenNow|isLastCallHour"` = **2 seules** surfaces d'état, toutes deux healées.
Les 3 amplitudes restantes relèvent du **fait owner déjà délégué** (« publier 18h — 01h » vs « fermer à minuit ») :
je les liste, je **ne les compte pas** au verdict, conformément au périmètre.

## B. DÉFAUTS NOUVEAUX — PROUVÉS

### P1-1 · Grille de stats : 1 colonne AUSSI en desktop (régression introduite par le heal du cycle 10)
- `styles-v6-brand.css:345` `.lc-stats-grid { grid-template-columns: 1fr !important; }` — **hors media query**.
- `styles-v6-brand.css:375` (dans `@media (min-width: 900px)`) `.lc-stats-grid { grid-template-columns: repeat(3, minmax(0,1fr)); }` — **sans** `!important`.
- Même sélecteur ⇒ `!important` gagne à toutes les largeurs. Mesuré 1 colonne à 320/360/480/600/768/**1024**/**1440**.
- Preuve : `shots/red11/grid-1440-settled.png`, `grid-1024.png`.
- Le commentaire du heal (`styles-v6-brand.css:341-344`) annonce « 1 colonne en mobile, **3 en desktop** » : l'intention est contredite par sa propre implémentation.
- Correctif minimal suggéré (non appliqué, lecture seule) : borner la règle `!important` à `@media (max-width: 899px)`, ou ajouter `!important` à la règle desktop.

### P1-2 · Trois grilles laissent une tuile SEULE sur sa ligne à des largeurs courantes (même critère que le heal cycle 10)
Mesuré par `r11-grids.js` à 320/360/480/600/700/768/900/1024/1440 (hauteur stabilisée, `.lc-rv:not(.in)` = 0) :

| grille | rendu | colonnes | orphelin |
|---|---|---|---|
| `.lc-why` « Comment on est servi » (`screens.jsx:221`, 3 tuiles) | `styles-v2.css:25` 2 col ≥700 · `:26` 4 col ≥1100 | 2 | **700→1099 px : 2 + 1** (et à ≥1100 : 4 pistes pour 3 tuiles ⇒ 1 case vide) |
| `.lc-menu-grid` « Les envies du moment » (`screens.jsx:268`, 4 tuiles) | `styles.css:430` 3 col ≥900 · `:431` 4 col ≥1200 | 3 | **900→1199 px : 3 + 1** |
| `.lc-gallery` Facebook (`screens.jsx:296`, 5 tuiles) | `styles-mobile.css:100` 3 col · `styles-v2.css:114` 4 col ≥700 · `:115` 6 col ≥1100 | 2/4 | **600 px : 2+2+1** · **700→1099 px : 4 + 1** |

- Preuves lues : `shots/red11/orph-why-768.png` (« FIDÉLITÉ » seule, moitié de rangée vide),
  `orph-menu-1024.png` (« Terminator » seul, 2/3 de rangée vide), `orph-gal-1024.png` (5ᵉ tuile seule, 3 cases vides).
- **1024 px est un viewport de premier plan** (iPad paysage, portables 13"). Le cycle 10 a qualifié
  ce motif de défaut pour `.lc-stats-grid` : le même motif subsiste sur 3 autres grilles, dont une
  (`.lc-why`) **créée par la session S7 elle-même** en réduisant la section de 4 arguments à 3
  (`screens.jsx:118-122`) sans adapter les colonnes prévues pour 4 — forme « je n'ai pas vérifié
  ce que mon correctif RÉVÈLE ».

### P1-3 · Galerie : `object-fit: cover` ampute 33 % de 4 photos sur 5, seul régime divergent de la page
- `screens.jsx:316` : `objectFit: 'cover'` sur les 5 `<img>` de la galerie.
- Mesuré (`r11-crop.js`, 390/1024/1440 px) — perte de bords calculée sur le ratio réel :

| conteneur | fichier:ligne | `object-fit` | perte |
|---|---|---|---|
| `.lc-gallery-tile img` | `screens.jsx:316` | **cover** | **33 %** (4 photos 3:2 dans une tuile carrée) · 15 % (chicken_burger) |
| `.lc-card-item-thumb img` | `screens.jsx:52` | contain | 0 % |
| `.lc-featured-art img` | `screens.jsx:251` | contain | 0 % |
| `.lc-v6-famille-art img` | `styles-v6-brand.css:326` | contain | 0 % |
| `.lc-v6-hero-media img` | `styles-v6-brand.css` | cover (ratio identique) | 0 % |

- Preuve lue : `shots/red11/orph-gal-1024.png` — pains coupés net aux deux bords sur les tuiles 1, 3 et 4 ;
  les deux burgers (photos plus carrées) passent, les sandwichs sont tronqués. Résultat visuellement incohérent
  dans une même rangée.
- Conséquence secondaire : la tuile étant couverte à 100 % par la photo, le dégradé nocturne
  `styles-v6-brand.css:453` (`.lc-gallery-tile:nth-child(n)`, posé au cycle 7 pour neutraliser
  `styles-v2.css:127-129`) **n'est jamais visible** hors 404 — la règle décorative v6 est morte dans le cas nominal.
- Classe visée par la consigne « tout conteneur de photo produit : régime cohérent avec son fond ».

## C. TABLEAU D'EXHAUSTIVITÉ PAR CLASSE

| classe traquée | méthode | résultat |
|---|---|---|
| `!important` v1→v5/mobile battant une intention v6 | analyse statique croisée (toutes les règles `!important` des 6 fichiers × classes stylées en v6) | **1 seule collision : `styles-mobile.css:65` ↔ `.lc-stats-grid`** — celle du cycle 10. Aucune autre. La caractérisation « le SEUL endroit » du cycle 10 est **exacte**. |
| `!important` v6 battant une règle v6 sous media query | même analyse, appliquée à v6 sur lui-même | **1 conflit : `:345` bat `:375`** ⇒ **P1-1**. Aucun autre. |
| `:nth-child` / `:nth-of-type` décoratif sous surcharge v6 | grep exhaustif des 7 CSS | 5 occurrences. `styles-v2.css:127-129` (galerie) neutralisées par `styles-v6-brand.css:453` — mais règle v6 elle-même invisible sous `cover` (**P1-3**). `styles-v2.css:415-416, 424` (`.lc-value`) = **code mort** (`.lc-value` rendu nulle part, grep 0 hit). |
| `opacity` atténuant du TEXTE (opacité cumulée des ancêtres) | sondage runtime, produit des opacités de tous les ancêtres, sur 3 routes × 5 largeurs + 5 états | **0 texte à opacité cumulée < 1** après stabilisation. |
| `<img>` : repli sous 404 forcé | interception `**/assets/**` → 404, home + menu | 48 images cassées, **47 replis présentables** (emoji catégorie / 🌶️). 1 sans repli : `.lc-v6-hero-media` (`screens.jsx:155`, `onError` masque sans substitut) ⇒ bande sombre de 260-444 px, lue en capture `404-hero-390.png` : **vide nocturne, pas de casse ni d'icône brisée** ⇒ **P2, pas P1**. |
| Conteneur de photo produit : régime cohérent | mesure `object-fit` + perte de bords, 3 largeurs | **1 divergence** ⇒ **P1-3**. |
| Contraste AA exhaustif, fonds translucides **composités** | compositing rgba complet sur la pile d'ancêtres + opacité cumulée ; home/menu/fidélité × 320/360/768/1024/1440 ; états 20h, 00h30, `reduced-motion`, `contrast:more`, `forced-colors` ; modale fiche écran + impression ; 5 pages légales × 2 largeurs | **0 échec AA sur les 26 passes.** |
| Modales : impression | `emulateMedia('print')` sur la fiche produit | 1 page, encre noire sur blanc, `#main` masqué (`display:none`), 0 échec AA. Preuve `detail-print.png`. |
| Jumeaux desktop/mobile | chaque grille mesurée aux 9 largeurs | **P1-1** (heal mobile-seulement) et **P1-2** (3 grilles desktop/tablette). |
| Jargon d'atelier côté client | grep `NF525|POS|KDS|borne|backend|frozen|SSOT|snapshot|payload|idempot|branch_id|wizard|V1` sur les 3 JSX | **0 occurrence rendue** (100 % en commentaires). |
| Affirmations contradictoires entre surfaces | recensement horaires + fidélité + livraison | Horaires : 3 amplitudes, **fait owner délégué**. Fidélité : « 1 € = 10 pts / 100 pts = 1 € » cohérent hero ↔ tuile ↔ FAQ ↔ page Fidélité. Livraison : « à emporter + Uber Eats » cohérent hero ↔ FAQ. |
| États conditionnels | 8 horloges figées + recherche vide (2 largeurs) + `reduced-motion` + `contrast:more` + `forced-colors` | Tous cohérents. Recherche vide : « 🔍 Rien trouvé — Essaye avec d'autres mots-clés ou retire un filtre. » ✅ `forced-colors` lisible (`st-forcedcolors-hours.png`). |
| Erreurs JS + débordements | 320/360/768/1024/1440 × 3 routes + 5 états + 5 pages légales | **0 `pageerror`, 0 `console.error`, 0 débordement horizontal** (`scrollWidth == innerWidth` partout). Les seuls dépassements de boîte sont les scrollers volontaires (marquee, `.lc-cat-tab`). |
| Focus clavier | 14 tabulations depuis le haut de la home | outline visible sur les 14 (2-3 px, orange/jaune). |
| Compteur animé « 38 plats au menu » | lecture après stabilisation | 38, conforme au SSOT `W_ITEMS`. ⚠️ vaut 0 si l'on mesure avant que l'`IntersectionObserver` ait déclenché — piège de mesure, **pas** un défaut. |

## D. SUPPOSÉ (non retenu au verdict, faute de preuve suffisante ou de gravité)
- `screens.jsx:290-296` : la section « Suis-nous sur Le Cayenne · Photos du jour, coulisses de la plancha » affiche 5 **rendus catalogue** (`assets/menu/*.png`), pas des photos Facebook. Les visuels sont authentiques ; la promesse éditoriale est plus large que le contenu. Jugement éditorial owner, pas un défaut prouvable.
- `.lc-v6-hero-media` sans repli sous 404 (voir tableau) — P2.
- `screens.jsx:126-132` : à 00h30 la ligne « Aujourd'hui » saute au jour civil suivant, dont le service n'a pas commencé. Dépend de la décision owner sur l'amplitude ⇒ non tranchable ici.

## E. HORS PÉRIMÈTRE / DÉLÉGUÉS — recensés, non comptés
Heure réelle de fermeture (3 surfaces d'amplitude : `screens.jsx:126-132`, `:415`, `components.jsx:201`) ·
CGV (barème fidélité, art.5, art.7) · `legal/allergens.html` · `legal/privacy.html` · FAQ « Pas de débit en ligne »
(`screens-v3.jsx:127`, handoff S4 déjà émis) · tunnel / compte / commandes / wizard / backend ·
`cat-tacos.png` · valeurs nutritionnelles · 404 de marque · pastille panier · panneau droit de la fiche ·
saut h2→h4 du comparatif · double « effacer » de la recherche · pied de page légal · tiroir panier à l'impression ·
`→` du tiroir burger 3,68:1 · saut h1→h3 Fidélité · vide du tiroir burger.

## F. NON TESTÉ
- Rupture réelle (`unavail`) sur données serveur : la maquette locale n'expose aucun item en rupture ;
  le code (`screens.jsx:44-84`) porte déjà le heal du cycle 6 (opacité retirée du texte, `grayscale` sur la photo seule)
  et n'a **pas** été exercé sur un item réellement épuisé.
- Page Fidélité connectée (contenu derrière authentification) — hors périmètre (compte).
- Navigateurs autres que Chromium ; impression physique réelle.

## VERDICT S7 — **NON CONVERGÉ** : P0 = 0, **P1 = 3**
1. **P1-1** régression du heal cycle 10 : grille de stats à 1 colonne en desktop (`styles-v6-brand.css:345` bat `:375`).
2. **P1-2** 3 grilles avec tuile orpheline à 600-1199 px (`.lc-why`, `.lc-menu-grid`, `.lc-gallery`) — même critère que le heal cycle 10.
3. **P1-3** galerie : `object-fit: cover` ampute 33 % de 4 photos sur 5 ; seul régime divergent de la page.

Le heal A1 (heure charnière) est **confirmé sur 8/8 horloges**. Le heal A2 est **à moitié seulement**.
Tendance : cycles 5→11 = 5, 2, 2, 2, 3, 2, 3, 2, 1, 1, **3**. Les 3 P1 de ce cycle sont tous de la même
famille — la **grille et le cadrage**, la seule classe encore non traitée systématiquement à TOUTES les
largeurs. Aucune régression de contraste, d'opacité, de JS, de débordement ni d'impression :
ces classes-là sont, elles, converged (26 passes vertes).
