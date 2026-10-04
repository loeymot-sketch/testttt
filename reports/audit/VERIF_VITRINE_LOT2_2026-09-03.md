# Vitrine — lot 2 : les dix-sept tickets que personne n'avait mesurés

Dépôt : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` · branche `main` · base `5b599c8`
GOAL : `plans/goals-2026-09-03/G9_VITRINE_TICKETS_NON_TRANCHES.md`
Lot 1 (41 tickets tranchés) : `reports/audit/VERIF_CLAUDE_VITRINE_2026-09-03.md`

**Aucun `git push`. Aucun commit. Aucun déploiement.** Les fichiers touchés sont listés en fin de document.

---

## 1. Méthode réellement appliquée

Chaque ticket a été **reproduit avant d'être jugé**. Trois instruments :

| Instrument | Ce qu'il mesure |
|---|---|
| lecture de code (`fichier:ligne`) | l'existence du gabarit ou de la donnée fautive |
| navigateur Playwright sur `python3 -m http.server 8899` | ce que le visiteur voit réellement, après hydratation React |
| `curl` sur `https://www.lecayenne.fr` | l'en-tête et l'octet réellement servis en production |

Rien n'a été corrigé sans qu'un banc ou une mesure ait d'abord **rougi**. Les trois bancs écrits
cette nuit ont chacun été **exécutés sur l'état d'avant correctif** (instantané `git archive` pour
T24, fichier servi en production pour T36, arbre non corrigé pour T17) : un banc neuf est faux par
défaut tant qu'on ne l'a pas vu mordre.

**Ce que je n'ai pas pu mesurer, et pourquoi :** voir §5.

---

## 2. Les dix-sept, tranchés

| Ticket | Verdict | Preuve (fichier:ligne ou mesure) | Effort si à corriger |
|---|---|---|---|
| **T09** photo unique pour les 2 frites | **ENCORE VRAI** | `data/menu.js:81-82` : `'petite-frites': 'frites.webp'` et `'grande-frites': 'frites.webp'`. Navigateur, grille du menu : les deux cartes servent le même `frites.webp` 800×800, à 2,50 € et 4,00 € | 1 photo à produire + 1 ligne. **Demande un fichier au propriétaire, pas un correctif** |
| **T15** trois identités visuelles | **ENCORE VRAI** | Mesuré au navigateur sur 5 pages — détail §4.1 : 3 en-têtes (`lc-nav` crème translucide / `sx-nav` noir / `lc-nav` crème à pastille), 9 · 10 · 4 liens, 2 fonds de page (nuit `rgb(10,8,7)` vs crème `rgb(251,247,240)`), logo PNG sur la seule SPA | ~1 jour + recapture de 43 pages. **Chantier, pas correctif de nuit** |
| **T17** carrousel : superposition + cartouche | **ENCORE VRAI** (diagnostic redressé) → **CORRIGÉ** | 25 relevés sur 335 où le cartouche nommait une photo visible à moins de 40 % — au pire **2 %**. Détail §3.1 | fait |
| **T19** « Click & collect » en anglais | **DÉCISION PROPRIÉTAIRE** (déjà prise) | Reproduit : `screens.jsx:808` (`.lcx-voie-note`), `:965` (`.lcx-oeil`), `:1177` (`.lcx-fait-v`), `legal/cgv.html:139`. Mais le terme est **celui du propriétaire**, cité dans le code : `screens.jsx:952` « [OWNER 2026-08-09] "quand tu descends en bas, ça montre click & collect" » | aucun — §4.4 |
| **T20** mot anglais peint dans le logo | **ENCORE VRAI (partiellement)** | `assets/brand/logo-cayenne.png` (438×120) lu à l'image, bande agrandie ×4 : « TACOS • BURGERS • **SANDWICHS** • **BOWLS** ». « SANDWICHES » n'existe pas — c'est l'orthographe française « SANDWICHS ». **Un seul mot est anglais : BOWLS**, alors que le site nomme la catégorie « Bols » (`data/menu.js:337`) | 1 PNG + 1 WebP à refaire. **Demande un fichier**, §4.5 |
| **T21** emojis dans le rail de catégories | **DÉCISION PROPRIÉTAIRE** · prémisse **réfutée** | Reproduit : `data/menu.js:332-340`, 9 emojis rendus (🥖🌯🍔🌮🥣🍟🍰🥤🧒). Mais « seul endroit du site » est **faux** : 15 emojis visibles mesurés sur l'accueil — filtres « 🍟 En menu », « 💪 Grande faim », bandeau prérendu `index.html:344` (🌙🥩🍟👨‍🍳🔥), nav « 🎡 Roue » | aucun — §4.2 |
| **T24** comparatif absurde sur les fiches | **ENCORE VRAI** (pire que décrit) → **CORRIGÉ** | Banc sur les 24 fiches : **35 constats sur 14 fiches**. Détail §3.2 | fait |
| **T25** numéro E.164 dans le texte des moteurs | **RÉFUTÉ** | `+33365678291` n'apparaît **jamais** en texte visible : extraction sans balises de `index.html`, `carte.html`, `plat/cayenne.html`, `a-propos.html`, `legal/cgv.html` → `False` partout. Les seules occurrences sont `href="tel:+33…"` et `"telephone"` du JSON-LD — les **deux formats canoniques** (RFC 3966 et schema.org) | — |
| **T26** « Savoureux » comme argument | **ENCORE VRAI** → **CORRIGÉ** | `tools/seo/generer.py:1539` → `index.html:348`, quatrième « preuve » à côté de trois faits vérifiables. Détail §3.3 | fait |
| **T30** « Pepper Club » nommé seulement dans les CGV | **DÉCISION PROPRIÉTAIRE** · prémisse **à moitié réfutée** | **Faux** pour « seulement dans les CGV » : le nom est aussi dans l'écran Compte (`screens.jsx:1776` « Fidélité · Pepper Club ») et dans le tunnel (`wizard-v2.jsx:953`, mesuré au navigateur : « 🎁 +49 pts Pepper Club » dans l'aperçu live). **Vrai** pour l'accueil : `screens.jsx:1073-1100` dit « Le programme », jamais le nom | aucun — §4.3 |
| **T31** mêmes 5 questions de FAQ partout | **RÉFUTÉ** | 11 pages SEO relevées : **56 questions, 54 distinctes**. Seules 2 réapparaissent, et sur des pages où elles sont légitimes (« Le Cayenne livre-t-il à domicile ? » sur `commander` et `livraison-henin-beaumont`). Tableau complet §4.6 | — |
| **T35** `sitemap.xml` : 41 URL figées au 07/08 | **ENCORE VRAI** → **CORRIGÉ** | `grep -o '<lastmod>' sitemap.xml \| uniq -c` → **41 × `2026-08-07`**, alors que `compiled/*.js` dataient du 02-03/09. Détail §3.4 | fait |
| **T36** `robots.txt` publie des notes internes | **ENCORE VRAI** → **CORRIGÉ** | Mesuré sur **le fichier servi** : `https://www.lecayenne.fr/robots.txt` → 200, 4 622 octets, byte-à-byte identique au dépôt, portant `[SEO 2026-08-07]`, `[MOTEURS DE RÉPONSE 2026-08-08]`, le récit d'une ancienne panne et le mécanisme de rewrite de l'hébergeur. Détail §3.5 | fait |
| **T39** `Access-Control-Allow-Origin: *` sur le HTML | **ENCORE VRAI** | Mesuré en production : `curl -D - https://www.lecayenne.fr/` → `access-control-allow-origin: *`. **L'en-tête n'est pas dans `vercel.json`** — il est ajouté par la plateforme. Analyse et correctif proposé §4.7 | 4 lignes de `vercel.json`, **non vérifiable sans mise en ligne** |
| **T40** deux redirections en cascade | **ENCORE VRAI** | Mesuré : `curl -sSL http://lecayenne.fr/` → `num_redirects=2`, chaîne `http://lecayenne.fr` → 308 → `https://lecayenne.fr` → `https://www.lecayenne.fr`. Depuis `https://` : 1 saut. Depuis `www` : 0 | **hors dépôt** (domaine Vercel + DNS), §4.8 |
| **T41** poids de l'accueil | **ENCORE VRAI** (chiffres) · impact **réfuté** | Chiffres confirmés au navigateur : **10 CSS = 327 001 o**, **18 JS = 1 045 949 o**, 51 requêtes, 7 scripts sans `defer`. Mais §4.9 : les 18 scripts sont **en fin de `<body>` (l.383-426)**, après le prérendu — ils ne bloquent aucun rendu ; et 1 340 Ko sur disque = **411 Ko réellement transférés** (brotli, mesuré en prod) | consolidation CSS ≈ ½ journée, §4.9 |
| **T57** menu enfant sans upsell | **RÉFUTÉ** | Mesuré au navigateur : le wizard du Menu Enfant Nuggets affiche « **ÉTAPE 1 · REQUIS · SAUCE** — 0 sélectionné · 1 minimum · 4 maximum », 13 sauces, et **un upsell payant dedans** (« sauce en plus +0,50 € »), puis un récap. Ce n'est ni une étape unique ni une absence d'upsell. `git log -L 616,619:data/menu.js` : l'étape sauce existe depuis `eee151a` du **2026-07-19**, soit **avant** l'audit du 28/08 | — |

---

## 3. Les ENCORE VRAI corrigés — banc, rougeur, correctif

### 3.1 T17 — le cartouche du carrousel nommait une photo qu'on ne voyait pas

**Banc** — `tests-e2e/carrousel-cartouche-suit-la-photo-2026-09-03.regression.js` (110 lignes).
Il lit la table `REEL` **dans `screens.jsx`** (jamais devinée), reconstruit la correspondance
photo → nom en régime établi, puis échantillonne 335 fois sur 33 s l'opacité calculée de chaque
`.lcx-reel-vue` et le texte du cartouche. Propriété vérifiée, sans imposer de formulation ni de
réglage : **la vue dont le cartouche porte le nom doit être visible à au moins 40 %.**

**Rougeur initiale** (arbre avant correctif) :

```
KO  le cartouche ne nomme jamais une photo visible à moins de 40 %
    — 25/335 relevés fautifs · ex. t=3473 « Le Méga » à 2 % (opacités 0.985, 0.015, 0, 0, 0)
5 OK · 1 KO
```

**Diagnostic — le ticket avait tort sur la cause.** La superposition existe, mais elle est
**voulue** : c'est un fondu croisé de 900 ms (`styles-v7-braise.css:241`, `.lcx-reel-vue {
transition: opacity .9s }`). Et le cartouche n'est pas « figé » — il est **en avance**. Le texte et
la classe `is-on` sortent du même état `i` de `HeroReel()` : le texte bascule instantanément, la
photo met 900 ms. Relevé brut de la première mesure :

```
t=3267  cartouche « Le Méga »   opacités [1.00, 0, 0, 0, 0]     ← photo du Cayenne encore pleine
t=3389  cartouche « Le Méga »   opacités [0.85, 0.15, 0, 0, 0]
```

**Correctif** — `screens.jsx` : un second état `iNomme` porte le nom affiché et suit `i` avec un
relais. Le réglage n'est pas choisi au jugé : le fondu utilise la courbe CSS `ease`
(`cubic-bezier(.25,.1,.25,1)`), **chargée en tête** — la moitié du *temps* n'est pas la moitié de
l'*opacité*. Le croisement réel à 50/50 a été calculé à **29,3 % de la durée, soit 264 ms sur 900**,
pas 450. Un premier essai à 450 ms est resté rouge (15/335 fautifs, au pire 33 %) : c'est le banc
qui a imposé 265 ms, pas l'intuition.

```
const REEL_RELAIS_MS = 265;
const [iNomme, setINomme] = wsS(0);
wsE(() => { const relais = setTimeout(() => setINomme(i), REEL_RELAIS_MS);
            return () => clearTimeout(relais); }, [i]);
const courant = REEL[iNomme];
```

**Après** : `6 OK · 0 KO` — 335/335 relevés conformes.
**Capture en plein fondu** (opacités 0.85 / 0.15) : le cartouche affiche « LE CAYENNE — La
signature maison » et la photo dominante *est* le Cayenne.

Chaîne de publication complète, les trois maillons : `screens.jsx` recompilé →
`compiled/screens.js` + `.source-sha256`, jeton `index.html` bumpé `20260903g8t58` →
`20260903g9t17b`, `tools/asset-versions.json` réenregistré.
**Le garde-fou de jeton a été prouvé mordant** sur le scénario réel « recompilé sans bumper »
(référence d'origine restaurée + ancien jeton) : `✗ contenu modifié SANS bump : compiled/screens.js`.

### 3.2 T24 — le comparatif de prix des fiches produit se contredisait

**Banc** — `tests-e2e/fiches-comparatif-coherent-2026-09-03.regression.js` (137 lignes).
Il lit les prix **dans `tools/seo/catalogue-extrait.json`** et vérifie huit propriétés sur les
24 fiches. Aucune formulation n'est imposée : une réécriture qui reste vraie le laisse vert.
`LC_RACINE` permet de le pointer sur un instantané.

**Rougeur initiale**, prouvée sur `git archive HEAD` : **35 KO sur 14 fiches**.

Le ticket disait « 26 fiches » — il y en a **24**, et le défaut est **plus grave** qu'annoncé.
`_situer_le_plat()` classait par `noms.index(nom)` dans une liste triée par prix : entre produits
**au même prix**, ce rang sortait du hasard du tri, et la phrase se contredisait dans la même ligne.

| Constat mesuré | Fiches |
|---|---|
| « le moins / le plus cher » **et** « au même prix que » dans la même phrase | 6 (`bol-frites`, `bol-riz`, `glace`, `tiramisu`, `menu-enfant-nuggets`, `menu-enfant-burger`) |
| rang de prix annoncé entre produits à prix **égal** (« 2ᵉ position sur 3 » alors que les 3 desserts sont à 3,50 €) | 3 (`tarte-daim`, `cheese-burger`, `fish-burger` — ces deux derniers reçoivent les rangs 2 et 3 à 6,00 € chacun) |
| article faux devant un nom féminin (« Par rapport **au** Petite Frites ») | 5 (`grande-frites`, `petite-frites`, `glace`, `tarte-daim`, `tiramisu`) |
| pronom masculin pour un produit féminin (« **il** apporte portion petite ») | 5 |
| dénombrement au singulier (« des 2 **galette** », « des 2 **menu enfant** ») | 4 |
| FAQ qui bégaie (« Combien coûte **le Cayenne au Cayenne** ? ») | 2 (`cayenne`, `galette-cayenne`) |
| « Nᵉ position sur M par le prix » | 8 |

**Correctif** — dans `tools/seo/generer.py`, quatre changements, tous dans le générateur (les
24 fiches sont régénérées, jamais éditées à la main) :

1. `_situer_le_plat()` réécrit. Le rang arbitraire disparaît : « le moins / le plus cher » ne se dit
   **que** si personne ne partage ce prix ; quand toute la catégorie est au même prix, la phrase le
   dit (« Les 3 desserts de la carte sont **au même prix** : 3,50 € ») ; au milieu, le rang est
   supprimé — les écarts en euros qui suivaient disaient déjà la même chose, en concret.
2. `_feminin()`, `_pluriel_cat()`, `_et()` : le genre et le nombre écrits **une seule fois**. Trois
   fonctions accordaient jusqu'ici chacune de leur côté, d'où « la Petite Frites » dans la FAQ et
   « au Petite Frites » dans le comparatif **de la même page**.
3. `_ce_qui_le_distingue()` : article et pronom accordés au produit.
4. La FAQ n'ajoute plus « au Cayenne » quand le nom le dit déjà.

Avant → après, trois exemples :

```
- Il arrive en 2ᵉ position sur 3 par le prix, au même prix que Glace et Tiramisu.
+ Les 3 desserts de la carte sont au même prix : 3,50 €.

- Par rapport au Petite Frites (2,50 €), il apporte portion grande, et il n'a pas portion petite.
+ Par rapport à la Petite Frites (2,50 €), elle apporte portion grande, et elle n'a pas portion petite.

- Il arrive en 2ᵉ position sur 4 par le prix, 0,40 € de plus que Suprême, 0,60 € de moins que Méga.
+ Parmi les 4 sandwichs de la carte, il coûte 0,40 € de plus que Suprême, 0,60 € de moins que Méga.
```

**Après** : `108 OK · 0 KO`.

**Sécurité de la régénération, vérifiée avant d'y toucher** : `python3 tools/seo/generer.py` sur
l'arbre propre produisait un `git status` **vide** — le générateur est déterministe et les pages
commitées lui correspondaient à l'octet. Deux exécutions successives après correctif donnent le
même md5. Les 37 blocs JSON-LD des pages ont été re-validés (`json.loads`) : 0 invalide.

### 3.3 T26 — « Savoureux » au milieu de trois faits

`tools/seo/generer.py:1539` alimentait `index.html:348` :
« Viande fraîche — livrée le matin · Frites maison — coupées et cuites ici · Fait maison — sauces
sur place · **Savoureux** — cuit à la commande, sur la plancha ». Les trois premiers sont
vérifiables, le quatrième est un adjectif.

**Mesure secondaire** : le bloc est **absent du DOM après hydratation** (recherche de « Savoureux »
sur `li,p,h3` de l'accueil rendue → `[]`). C'est donc bien, comme le disait le ticket, du texte
**destiné aux moteurs**, pas au visiteur.

**Correctif** : « Savoureux » → « **Cuit minute** ». Ce n'est pas une invention : c'est le mot que
le site emploie déjà dans le bandeau du hero (`index.html:344`, `screens.jsx:365`), et il dit
exactement ce que la ligne explique déjà.

### 3.4 T35 — les 41 URL du plan du site figées au 7 août

`grep -o '<lastmod>' sitemap.xml | sort | uniq -c` → **41 × `2026-08-07`**, alors que
`compiled/funnel.js` et `compiled/account-v2.js` dataient du 02/09 et `compiled/screens.js` du 03/09.
Source : `tools/seo/generer.py:24`, `LASTMOD = "2026-08-07"` — une constante avec, en commentaire,
« à mettre à jour au prochain vrai changement de contenu ». Elle ne l'a pas été.

**Correctif** — la date n'est plus une constante. `resoudre_lastmod()` compare la page **qu'on
vient de produire** à celle qui est commitée, dates neutralisées : si rien d'autre n'a bougé, la
page garde la date de son dernier commit ; sinon elle change aujourd'hui. Aucune dépendance à
l'ordre des appels, rien à mettre à jour à la main.

Un premier essai (interrogation de `git status` au moment de l'appel) donnait un résultat
**dépendant de l'ordre** : le JSON-LD, construit avant l'écriture des pages, mémorisait une date
périmée que le plan du site reprenait ensuite. Corrigé par un jeton `@@LASTMOD@@` résolu à
l'écriture.

Résultat : **22 URL au 2026-08-28** (inchangées) et **19 au 2026-09-03** (les 18 fiches réécrites
+ `index.html`). Deux exécutions successives donnent le même fichier. `dateModified` des 9 blocs
JSON-LD suit la même source ; `datePublished` reste la constante — c'est bien une date de première
publication.

### 3.5 T36 — `robots.txt` publiait le journal de bord du dépôt

**Banc** — `tests-e2e/robots-groupes-complets-2026-09-03.regression.js` (85 lignes), capable de
mesurer **le fichier servi** (`LC_ROBOTS=https://…`) et non seulement celui du dépôt.

**Rougeur initiale, sur la production** :

```
KO  robots.txt ne publie pas de étiquette datée d'audit — trouvé : [SEO 2026-08-07]
KO  robots.txt ne publie pas de nom du fichier d'hébergement — trouvé : vercel.json
KO  robots.txt ne publie pas de mécanique interne du serveur — trouvé : React
26 OK · 3 KO
```

Le fichier public racontait une ancienne panne (« Avant ce fichier, /robots.txt n'existait PAS : la
règle attrape-tout de vercel.json renvoyait l'application React »), deux dates d'audit, et le
mécanisme de rewrite de l'hébergeur.

**Correctif** — en-tête réécrit. **Aucune directive n'a bougé** : le script de réécriture
comparait les 45 lignes `User-agent` / `Allow` / `Disallow` / `Sitemap` avant et après, et
refusait d'écrire en cas d'écart. Ce qui reste : la politique (pourquoi les robots d'IA sont
autorisés, ce que les quatre `Disallow` protègent) et l'avertissement opératoire — un robot
n'obéit qu'au groupe le plus spécifique, les `Disallow` doivent être recopiés dans chaque groupe.
Ce qui part : les étiquettes datées, le récit de la panne, le nom de l'hébergeur.

Le ticket parlait aussi de « noms de robots » : ils **restent**, ce ne sont pas des notes internes,
ce sont les directives elles-mêmes.

**Le banc verrouille en plus le piège que le commentaire décrivait** — un commentaire ne protège
rien. Il vérifie que **chacun des 6 groupes** porte bien ses quatre `Disallow` : si un groupe nommé
les perd, les URL de suivi de commande redeviennent explorables **pour ce robot-là**, en silence.
**Après** : `29 OK · 0 KO`, sur le dépôt comme sur la production pour la partie « groupes ».

---

## 4. Décisions propriétaire, et ce qui n'est pas corrigeable ici

### 4.1 T15 — trois identités visuelles : la mesure, et le coût

Mesuré au navigateur (1280×900) sur 5 pages :

| | SPA (`index.html`) | SEO (`carte.html`, `plat/*`) | Légal (`legal/*`) |
|---|---|---|---|
| en-tête | `header.lc-nav` | `header.sx-nav` | `header.lc-nav` |
| fond de l'en-tête | `rgba(250,247,242,.85)` crème | `rgb(11,10,9)` noir | `rgba(250,247,242,.85)` crème |
| fond de page | `rgb(10,8,7)` **nuit** | `rgb(251,247,240)` **crème** | `rgb(250,247,240)` **crème** |
| liens visibles | **9** | **10** | **4** |
| marque | **logo PNG** (mascotte) | texte « LE CAYENNE » | pastille « LC » + texte |
| bandeau secondaire | rail de découverte (Accueil/Menu/Avis/Réseaux/Itinéraire) | barre adresse + horaires + téléphone | aucun |
| police du `h1` | Anton | Anton | Anton |

La typographie est le seul élément déjà unifié.

**Coût d'une unification, chiffré.** L'en-tête existe en **trois implémentations dans trois
langages** : React (`components.jsx`, 24 occurrences de `lc-nav`, 65 règles CSS réparties sur les
feuilles `styles*.css`), Python (`tools/seo/generer.py:296-302`, 15 règles dans `seo.css`, appliqué
à 36 pages générées) et HTML écrit à la main (`legal/*.html:37-46` × 5 fichiers, `legal/legal.css`).
Unifier suppose un en-tête unique propagé aux **trois** chaînes de rendu et **43 pages** à
re-valider visuellement. **Estimation : une journée pleine + une passe de recapture.** Ce n'est pas
un correctif de nuit ; rien n'a été touché.

**Recommandation** : commencer par le moins cher et le plus visible — aligner le fond des pages
légales et SEO (déjà identiques) et **donner le logo PNG aux deux**, qui ne l'ont pas. ~1 h, sans
refonte.

### 4.2 T21 — les emojis du rail : un choix, et une prémisse fausse

Les 9 emojis existent bien (`data/menu.js:332-340`, rendus et mesurés). Mais l'argument du ticket
— « seul endroit du site qui en porte » — est **faux** : 15 emojis sont visibles sur l'accueil,
dont les chips de filtre (« 🍟 En menu », « 💪 Grande faim »), le bandeau prérendu
(`index.html:344` : 🌙 🥩 🍟 👨‍🍳 🔥) et « 🎡 Roue » dans la barre de navigation.

**Recommandation** : les retirer du seul rail **aggraverait** l'incohérence au lieu de la réduire.
Deux options cohérentes, au choix du propriétaire :
- **les garder** (coût 0) — c'est le repère le plus rapide dans une grille de 39 produits ;
- **les remplacer partout par les pictogrammes maison** déjà présents dans `WC_I` — coût ≈ 3 h
  (9 catégories + 3 filtres + 5 forces + 1 nav), plus 9 dessins si la série n'est pas complète.

### 4.3 T30 — « Pepper Club » : la moitié du ticket est fausse

Le nom **n'est pas** confiné aux CGV : il est dans l'écran Compte (`screens.jsx:1776`, « Fidélité ·
Pepper Club ») et dans le tunnel de commande — mesuré au navigateur, l'aperçu live du wizard
affiche « 🎁 +49 pts Pepper Club ». Ce qui est vrai : la section fidélité de **l'accueil**
(`screens.jsx:1073-1100`) dit « Le programme », « Chaque euro te revient », jamais le nom.

**Recommandation** : c'est une décision de marque, pas un défaut. Si le propriétaire veut que le nom
prenne, il doit apparaître **à l'endroit où le visiteur découvre le programme** — remplacer le
sur-titre « Le programme » par « Pepper Club » coûte **une ligne** (`screens.jsx:1077`) + une
recompilation + un bump de jeton, soit ~15 min. S'il préfère parler de « fidélité » à des clients
qui ne connaissent pas encore la marque, c'est défendable et il n'y a rien à faire.

### 4.4 T19 — « Click & collect » : la décision est déjà écrite dans le code

Le terme est visible à trois endroits de l'accueil. Mais `screens.jsx:952` conserve la demande
d'origine : « [OWNER 2026-08-09] "quand tu descends en bas, ça montre click & collect : il va
commander, il va venir chercher" ». **C'est le mot du propriétaire.** Le remplacer par « commande
et retrait » coûterait 4 remplacements + recompilation (~20 min), mais irait contre une décision
déjà prise. Rien n'a été touché ; à rouvrir seulement si le propriétaire le demande.

### 4.5 T20 — le mot est peint dans l'image : il faut un fichier

`assets/brand/logo-cayenne.png` (438×120) et son jumeau `.webp` portent
« TACOS • BURGERS • SANDWICHS • BOWLS ». Un seul mot est en anglais : **BOWLS**, alors que la
catégorie s'appelle « Bols » partout ailleurs. Aucun correctif de code n'est possible — le texte
est de la peinture.

**Recommandation** : demander au graphiste une variante avec « **BOLS** ». À produire :
`logo-cayenne.png` (438×120) **et** `logo-cayenne.webp`, plus `logo-mark.png` s'il porte la même
bande. Utilisé par `components.jsx:232` et `roue.html:550`. Coût côté code : **0** (même nom de
fichier), plus un bump de jeton.

### 4.6 T31 — réfuté, avec le relevé

11 pages SEO, 56 questions relevées, **54 distinctes**. Extrait :

| Page | Ses questions (distinctes de toutes les autres, sauf mention) |
|---|---|
| `carte.html` | prix de la carte · nombre de viandes · sauces payantes · plats sans viande · moins de 10 € ᴬ · menu enfant |
| `burgers.html` | burger le moins cher · le plus copieux · fromage/œuf · burger au poisson · burgers en menu |
| `tacos.html` | prix d'un tacos · M/L/XL · plus de 3 viandes · crudités · temps de préparation |
| `horaires.html` | dimanche · heure de fermeture · le midi · où · réserver |
| `livraison-…` | livraison ᴮ · Uber Eats vs retrait · délai · commander/venir chercher · jusqu'à quelle heure |

ᴬ partagée avec `manger-pas-cher` · ᴮ partagée avec `commander`. **2 recouvrements sur 56.**

### 4.7 T39 — `Access-Control-Allow-Origin: *` : réel, et je ne peux pas prouver le correctif

Mesuré : `curl -D - https://www.lecayenne.fr/` renvoie `access-control-allow-origin: *` sur le HTML,
et l'en-tête **n'est pas dans `vercel.json`** — c'est un défaut de la plateforme sur les fichiers
statiques.

**Portée réelle, mesurée et bornée** : avec `*` et **sans** `Access-Control-Allow-Credentials`, le
navigateur **refuse** toute requête cross-origin porteuse de cookies ; aucune session ne fuit. Le
contenu servi est public. Reste l'hygiène : n'importe quel site peut lire ce HTML en JavaScript.
`strict-transport-security: max-age=63072000` est bien servi (sans `preload` ni `includeSubDomains`).

**Correctif proposé, non appliqué** — dans le bloc `headers` de `vercel.json`, source `/(.*)` :

```json
{ "key": "Access-Control-Allow-Origin", "value": "https://www.lecayenne.fr" }
```

**Pourquoi je ne l'ai pas appliqué** : rien dans le dépôt ne permet de vérifier qu'une déclaration
dans `vercel.json` **écrase** l'en-tête que la plateforme ajoute d'elle-même. Seule une mise en
ligne le dirait, et elle n'est pas autorisée. Appliquer un correctif dont je ne peux pas mesurer
l'effet, c'est exactement la faute que ce lot devait éviter. **Décision propriétaire : à passer sur
un déploiement de prévisualisation, puis à mesurer au `curl`.** Coût : 5 min + 1 déploiement.

### 4.8 T40 — deux redirections : hors du dépôt

```
http://lecayenne.fr/   → 308 → https://lecayenne.fr/ → https://www.lecayenne.fr/   (2 sauts)
https://lecayenne.fr/  → https://www.lecayenne.fr/                                  (1 saut)
https://www.lecayenne.fr/                                                           (0 saut)
```

Le premier saut (`http` → `https`) est imposé par l'edge et **ne peut pas** être fusionné avec le
second : la plateforme met à niveau vers `https` **sur le même hôte** avant d'appliquer la
redirection de domaine. `vercel.json` n'a aucune prise dessus.

**Recommandation** : le seul levier réel est la **préinscription HSTS** (`hstspreload.org`) — le
navigateur saute alors directement en `https` et il ne reste qu'un saut. Elle exige d'ajouter
`includeSubDomains; preload` à l'en-tête `strict-transport-security` (aujourd'hui
`max-age=63072000` seul) puis de soumettre le domaine. **C'est un engagement difficile à défaire**
(retrait de la liste : plusieurs mois). Décision propriétaire. Gain : ~100-200 ms, pour les seuls
visiteurs qui tapent l'adresse sans `https`.

### 4.9 T41 — les chiffres sont exacts, le grief ne l'est pas

Mesuré au navigateur sur l'accueil : **51 requêtes**, 10 CSS = **327 001 o**, 18 JS = **1 045 949 o**,
16 images, 3 polices, **2 403 024 o** au total ; et exactement **7 scripts sans `defer`**
(`react`, `react-dom`, `data/menu.js`, `data/loyalty.js`, `api.js`, `app-native.js`, `qrcode.js`).

Deux corrections au ticket :

1. **Les 7 scripts ne bloquent aucun rendu.** Ils sont aux lignes **383-396 d'`index.html`,
   c'est-à-dire en fin de `<body>`**, après le prérendu SEO (l.316-382). Un script en fin de corps
   n'est pas bloquant pour la peinture ; `defer` n'y changerait presque rien. FCP mesuré en local :
   **168 ms**.
2. **1,3 Mo sur disque ≠ 1,3 Mo sur le réseau.** Mesuré en production avec `Accept-Encoding: br` :
   CSS **97 232 o**, JS **323 734 o**, **total 411 Ko réellement transférés** — soit 31 % du poids
   disque, sur HTTP/2 où 28 requêtes coûtent une connexion.

Ce qui reste vrai et coûte quelque chose : **10 feuilles de style bloquantes dans le `<head>`**,
héritage de 8 générations (`styles.css` → `styles-v8-vitrine.css`). Chacune est une requête
bloquante avant la première peinture.

**Recommandation chiffrée** : concaténer les 10 en une seule feuille versionnée, sans toucher au
contenu — 9 requêtes bloquantes en moins, ~89 Ko compressés inchangés. **≈ ½ journée** (ordre de
cascade à préserver, `@font-face` déjà en chemin absolu — cf. le banc
`polices-sous-repertoire-2026-08-29`), plus une recapture des 43 pages. Non fait : le GOAL demandait
de mesurer, et une consolidation CSS non déployable ne se vérifie pas en une nuit.

---

## 5. Ce que je n'ai pas pu mesurer

- **Les captures `tests-round2/*.png` du rapport Grok d'origine sont introuvables.** `find` sur le
  dépôt vitrine et sur `testttt/reports/` ne renvoie que le marqueur de branche
  `audit/grok-2026-08-28`, qui ne porte aucun commit propre. Chaque ticket visuel a donc été
  **re-mesuré au navigateur**, ce qui est de toute façon la bonne méthode — mais je ne peux pas
  confirmer que je regarde le même écran que l'auditeur.
- **T39** : l'effet du correctif proposé n'est pas vérifiable sans mise en ligne (§4.7).
- **T40** : le second saut dépend de la configuration de domaine et du DNS, hors dépôt (§4.8).
- **Les mesures de production** (T36, T39, T40, T41) portent sur le **déploiement actuel**, qui est
  en retard sur mon arbre (il sert encore `compiled/screens.js?v=20260903g8t58`). Elles restent
  valables : aucun des quatre ne dépend de ce que j'ai modifié.
- **T09 et T20** demandent des fichiers image que je ne peux pas produire ; je les ai décrits
  précisément plutôt que d'inventer un correctif de code.
- Les serveurs MCP `playwright` et `graphiti` n'ont pas démarré cette session ; j'ai utilisé
  Playwright en direct depuis `testttt/node_modules` avec Node v22 (le v18 ambiant casse
  Playwright), comme le font les bancs existants de `tests-e2e/`.

---

## 6. Non-régression

| Contrôle | Résultat |
|---|---|
| `tools/compile-jsx.mjs --check` | **✓ 11 fichiers : tous les compilés sont à jour** (les 11 empreintes concordent, comme au départ) |
| `tools/check-asset-versions.mjs --check` | ✓ 27 ressources versionnées, aucun bump oublié |
| garde-fou de jeton, **prouvé mordant** | ✗ `contenu modifié SANS bump : compiled/screens.js` sur le scénario réel |
| `tests-e2e/pages-seo-navigateur-2026-08-07` | 84/84 |
| `tests-e2e/h1-prerendu-vs-react-2026-08-28` | 7 vert / 0 rouge |
| `tests-e2e/polices-sous-repertoire-2026-08-29` | 10 vert / 0 rouge |
| `tests-e2e/titre-hero-2026-08-27` | 16/16 |
| `tests-e2e/residus-honnetete-2026-08-07` | 11/11 |
| `tests-e2e/vue-moteurs-reponse` | 22/22 |
| bancs G9 (les 3 nouveaux) | 108/0 · 29/0 · 6/0 |
| `sitemap.xml` | XML valide · 37 blocs JSON-LD re-validés, 0 invalide |
| régénération SEO | déterministe : deux exécutions, même md5 |

---

## 7. Fichiers touchés (aucun commit, aucun push)

**Sources — 3 fichiers**
- `tools/seo/generer.py` (+221/-… ) — T24 (comparatif, genre, nombre, FAQ), T26, T35
- `screens.jsx` (+24) — T17
- `robots.txt` (réécriture des commentaires, **0 directive modifiée**) — T36

**Artefacts régénérés — 36 fichiers**
- `index.html`, `carte.html`, `a-propos.html`, `bols-et-frites.html`, `burgers.html`,
  `commander.html`, `horaires.html`, `livraison-henin-beaumont.html`,
  `manger-pas-cher-henin-beaumont.html`, `manger-tard-henin-beaumont.html`,
  `sandwichs-galettes.html`, `tacos.html`
- `plat/*.html` — les 24 fiches
- `sitemap.xml`

**Chaîne de publication — 3 fichiers**
- `compiled/screens.js`, `compiled/screens.js.source-sha256`, `tools/asset-versions.json`
- (jeton de cache bumpé **dans** `index.html` : `20260903g8t58` → `20260903g9t17b`)

**Bancs ajoutés — 3 fichiers**
- `tests-e2e/fiches-comparatif-coherent-2026-09-03.regression.js` (137 l.)
- `tests-e2e/robots-groupes-complets-2026-09-03.regression.js` (85 l.)
- `tests-e2e/carrousel-cartouche-suit-la-photo-2026-09-03.regression.js` (110 l.)

Rien n'a été touché dans `/Users/1millnonstop/Downloads/web`. Le commit `5b599c8` est intact.

---

`ENCORE_VRAIS: 11` · `CORRIGÉS: T17, T24, T26, T35, T36` · `RÉFUTÉS: T25, T31, T57` · `DÉCISION_PROPRIÉTAIRE: T19, T21, T30`

*Les 6 ENCORE VRAI non corrigés — T09, T15, T20, T39, T40, T41 — sont chiffrés au §4 : deux
demandent un fichier image (T09, T20), un demande un déploiement pour être vérifié (T39), un est
hors dépôt (T40), deux sont des chantiers d'une demi-journée à une journée (T15, T41). Aucun n'est
ignoré ; aucun n'a été « corrigé » sans preuve.*
