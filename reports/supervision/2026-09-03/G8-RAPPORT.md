# G8 — Vitrine Le Cayenne : les quatre défauts, mesurés

Dépôt traité : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`, branche `main`.
**Aucun `git push`. Aucun `git commit`.** L'arbre est laissé modifié, `main` est resté sur
`007bc75`, `## main...origin/main` sans écart. La porte propriétaire P3 n'a pas été franchie.

Site servi en local par `python3 -m http.server 8899`. Outillage : Playwright du dépôt back-end
(`testttt/node_modules`), Node v22.23.2 — le Node ambiant v18 casse Playwright sur cette machine.

Verdict par ticket : **T04 clos sans correctif** (non reproduit) · **T10 corrigé** ·
**T58 corrigé** · **T07 mesuré, non implémenté** (décision propriétaire).

---

## T04 — la barre « Confirmer » recouvre-t-elle les créneaux ? **NON. Clos sans correctif.**

### Ce qui a été mesuré

Banc de mesure : `tests-e2e/creneaux-recouvrement-2026-09-03.mesure.js`.
Parcours réel jusqu'à la page de paiement, puis balayage de toute la hauteur de page par pas de
20 px, aux deux formats. À chaque position : intersection des rectangles **et**
`document.elementFromPoint()` en trois points de chaque cible — un recouvrement de rectangles ne
prouve pas l'occultation, la barre porte un dégradé partiellement transparent.

Captures : `tests-e2e/creneaux-recouvrement-shots/`.

#### 1280 × 800 — barre `sticky`, hauteur **78 px**

| cible | croisement max (balayage complet) | points réellement pris par la barre | à l'arrivée (scrollY=0) | en bas de page | après `scrollIntoView` | après `focus()` clavier |
|---|---|---|---|---|---|---|
| créneau « Dès que prêt » | **0 px** | 0/3 | 0 px | 0 px | — | — |
| créneau « Dans 30 min » | **0 px** | 0/3 | 0 px | 0 px | — | — |
| créneau « Dans 40 min » | **0 px** | 0/3 | 0 px | 0 px | — | — |
| créneau « Choisir une heure » | **0 px** | 0/3 | 0 px | 0 px | **0 px** | **0 px** |
| bloc « Lieu de retrait » | 78 px à scrollY=240 | 2/3 | 0 px | **0 px** | — | — |

#### 390 × 844 — barre `fixed`, hauteur **89 px**

| cible | croisement max (balayage complet) | points pris | à l'arrivée | en bas de page | après `scrollIntoView` | après `focus()` |
|---|---|---|---|---|---|---|
| les 4 créneaux | 59 px à scrollY≈160-240 | 3/3 | 0 px | 0 px | **0 px** | **0 px** |
| bloc « Lieu de retrait » | 89 px à scrollY=600 | 2/3 | 0 px | **0 px** | — | — |

Mesuré aussi pour les quatre modes de paiement (à emporter, livraison, sur place, carte) :
recouvrement résiduel **0 px** partout. 0 erreur JS.

### Conclusion : le ticket est réfuté, et sa prémisse est fausse

**En 1280 × 800, les créneaux ne sont recouverts à AUCUN moment** — 0 px à toutes les positions
de défilement, 0 point sur 3 pris par la barre. L'affirmation « le client choisit son créneau à
l'aveugle » n'est pas reproduite. La capture
`creneaux-recouvrement-shots/1280x800-A-pire-cas-scroll230.png` montre les quatre créneaux
entièrement dégagés au pire moment du défilement.

**La prémisse du ticket est factuellement fausse.** T04 dit : « la compensation `padding-bottom`
n'existe qu'en mobile (`styles-mobile.css:155`) ». Non : `styles-v4.css:8-12` pose
`.lcf-page { padding: 32px 0 80px }` **hors de toute media query**, donc en desktop aussi.
`styles-mobile.css:155` ne fait que la porter de 80 à 120 px pour la barre `fixed`, plus haute.

Le seul recouvrement observé est **transitoire pendant le défilement** (78 px en desktop, 89 px
en mobile, sur le bloc « Lieu de retrait » et, en mobile, sur les créneaux). C'est le
comportement inhérent de toute barre d'action basse : elle recouvre ce qui passe dessous, et un
cran de défilement le dégage. En mobile c'est le patron assumé du dépôt depuis le 05/08. Aucun
contenu ne reste inatteignable.

**Aucun correctif appliqué.** Corriger ici aurait été corriger un défaut non reproduit.

### Ce qui reste vrai et mérite d'être surveillé : 2 px de marge

En desktop, `padding-bottom` = 80 px contre une barre de **78 px** : **2 px** de marge. Un
libellé de CTA qui passe à la ligne, un bouton de portefeuille plus haut, une police système
différente — et T04 devient vrai pour de bon, en silence. Banc posé :
`tests-e2e/creneaux-non-recouverts-2026-09-03.spec.js` (**12 vert / 0 rouge**).

**Ce que ce banc mord — vérifié, pas supposé.** J'ai servi une copie mutée de l'arbre sur un
second port avec `padding-bottom` ramené à 0 aux deux formats :

- l'assertion « `padding-bottom` ≥ hauteur de barre » **est passée au rouge aux deux formats**.
  C'est elle, et elle seule, qui tient le garde-fou ;
- les deux assertions de recouvrement résiduel **sont restées vertes**. Elles ne peuvent pas
  mordre : au défilement maximal en 390 × 844, le bas de `.lcf-card` se trouve **506 px
  au-dessus** du bas de l'écran. Rien du tunnel ne peut rester piégé sous la barre, quel que soit
  le padding.

C'est écrit dans l'en-tête du banc. Un banc vert sous mutation ne prouve rien, et je ne veux pas
qu'on lui fasse dire plus que ce qu'il dit. Le critère littéral du ticket (« ne croise la barre
en aucun point ») aurait d'ailleurs rendu ce banc rouge sans qu'aucun défaut n'existe : il est
faux, et n'a pas été retenu.

---

## T10 — un code promo refusé parlait des coordonnées. **Corrigé.**

### Le banc, et sa rougeur initiale

`tests-e2e/promo-message-precis-2026-09-03.spec.js`. Backend intégralement bouchonné, parcours
réel jusqu'au champ « Code promo », lecture du message affiché **dans la section du champ promo**
(`.lcf-field-error` sert aussi au bloc coordonnées : le lire globalement aurait fait mentir le
banc).

Rouge avant correctif, sur la cause exacte :

```
── code inexistant (message backend anglais, 422)
   backend  : 422 « The coupon does not exist »
   affiché  : « Certaines informations sont incomplètes ou invalides.
                Vérifie tes coordonnées et ton panier, puis réessaie. »
❌ NE parle PAS des coordonnées ni du panier
❌ parle bien du sujet (/code|promo|coupon/i)
```

Le message anglais n'est pas une hypothèse : `lang/en/all.php:345` du back-end dit exactement
« The coupon does not exist », et `config/app.php:203` pose `fallback_locale => 'en'`.

### Le correctif

`api.js` — une seule branche remplacée, plus un bloc de fonctions au-dessus de `req()`.
La règle FR (aucun anglais visible, ADR-007) est **conservée**. Ce qui change :

1. **on rend la cause quand on la reconnaît** — table de 12 motifs adossés aux chaînes
   réellement émises par le back-end (`lang/en/all.php` pour les coupons et les codes,
   `lang/en/validation.php` pour le bag Laravel, `lang/en/auth.php` pour le throttle) ;
2. **le bag de validation Laravel nomme son champ** — `The <champ> field is required` devient
   « Il manque ton prénom / ton adresse e-mail / … ». C'est le cas précis que le commentaire
   d'origine citait (« The first name field is required » affiché sans champ « nom » à l'écran)
   sans le traiter ;
3. **le générique de dernier recours est choisi selon l'appel qui a échoué** — promo, fidélité,
   authentification, adresse, paiement, commande, compte. La phrase d'origine est conservée
   **mot pour mot** là où elle était juste : la commande ;
4. **le conseil « règle ta commande en caisse » disparaît des requêtes `GET`** — il n'a aucun
   sens sur un historique en lecture seule.

Le message brut reste dans `body`, comme avant.

Après correctif — **26 vert / 0 rouge** :

| backend | avant | après |
|---|---|---|
| 422 « The coupon does not exist » | Vérifie tes coordonnées et ton panier | **Ce code promo n'existe pas. Vérifie les lettres et les chiffres saisis.** |
| 422 « The minimum order amount is 15.00 » | Vérifie tes coordonnées et ton panier | **Le montant minimum de commande n'est pas atteint pour ce code promo.** |
| 422 « Les codes promo sont désactivés… » (déjà FR) | intact | **intact** — le correctif ne réécrit pas ce qui était bon |
| 500 « Server Error » | erreur technique + caisse | inchangé |

Capture : `tests-e2e/promo-message-shots/1-code-inexistant.png`.

### Inventaire des autres endroits où ce réécrivain masquait une cause

Le réécrivain était global : **25 fonctions d'API** passent par `req()`. Inventaire vérifié
fichier par fichier — **12 points de sortie** de la même phrase :

| # | Point de sortie | Où le client la lit | Trompeur ? | Traité |
|---|---|---|---|---|
| 1 | `funnel.jsx:347` → `:522` | sous le champ **Code promo** | oui | **oui** |
| 2 | `funnel.jsx:1136` | bandeau global paiement / sous les champs carte | partiellement — « vérifie ton panier » sur un refus de carte | **oui** (famille paiement) |
| 3 | `funnel.jsx:1218` | bandeau paiement (Apple Pay) | oui | **oui** (famille paiement) |
| 4 | `funnel.jsx:1423` | bandeau paiement — `placeOrder` **et** `saveAddress` | non pour la commande, **oui** pour l'adresse | **oui** (adresse séparée) |
| 5 | `funnel.jsx:1520` | bloc « Tes coordonnées » | oui — parle du panier sur un écran sans panier | **oui** (famille auth) |
| 6 | `funnel.jsx:1544` | sous le **code à 4 chiffres** | oui | **oui** — dit « Ce code n'est pas valide. » |
| 7 | `account-v2.jsx:334` | sous le champ **e-mail** ou **prénom** | oui — le champ est bien choisi, le texte non | **oui** |
| 8 | `account-v2.jsx:534` | sous le champ **téléphone** | oui | **oui** (famille auth, mentionne le numéro) |
| 9 | `screens.jsx:1660` | carte « Historique indisponible » (points) | oui — proposait la caisse sur un `GET` | **oui** (règle GET) |
| 10 | `orders.jsx:64` | bandeau « Mes commandes » | oui — idem | **oui** (règle GET) |
| 11 | `loyalty-v2.jsx:164` | « Zone dangereuse » (suppression de compte) | **le plus trompeur** — le commentaire du code affirme rendre « le motif du serveur tel quel » | **oui** — famille `compte` posée **avant** `/auth/` |
| 12 | `components.jsx:92` | légende sous le QR de fidélité | mineur | **oui** |

Non concernés, vérifiés : `funnel.jsx:379` (adresse de livraison — passe par Nominatim, jamais
par `req()`), `funnel.jsx:972` et `:1332`, `account-v2.jsx:503` (SDK Mollie / portefeuilles),
`account-v2.jsx:455` (texte en dur). Les points qui lisaient déjà `e.body` / `e.status` pour
récupérer la vraie cause (`account-v2.jsx:333`, `:376-391`, `:531`) continuent de fonctionner.

Verrouillé par le volet 2 du banc, qui interroge `LC.api` directement — la couche que ces douze
écrans consomment tous :

```
commande  : « Certaines informations sont incomplètes ou invalides. Vérifie tes coordonnées
             et ton panier, puis réessaie. »        ← préservée, elle est juste ici
adresse   : « Il manque ton adresse. »
code      : « Ce code n’est pas valide. »
histoire  : « Une erreur technique est survenue (500). Réessaie dans un instant. »   ← plus de caisse
fidelite  : « Une erreur technique est survenue (500). Réessaie dans un instant. »   ← plus de caisse
compte    : « Ta demande n’a pas pu être traitée. Si une commande est encore en cours… »
```

### Un défaut voisin, trouvé en route, NON corrigé

L'heuristique « message manifestement anglais » repose sur une **liste fermée de 17 mots**.
Une phrase anglaise qui n'en contient aucun n'est pas réécrite du tout et **sort telle quelle à
l'écran**. Mesuré : `An order is still in progress` traverse intact.

C'est le même défaut que le commentaire d'`api.js:330-334` avait déjà relevé pour
« Too Many Attempts. » — corrigé au cas par cas, jamais à la racine. **Hors du mandat de T10**,
qui traite le réécrivain qui *écrase* une cause, pas celui qui en *laisse passer* une. Je ne l'ai
pas corrigé de ma propre initiative. Il est consigné dans le banc en tant que **constat, pas
assertion**, pour ne pas être redécouvert une troisième fois. Ma première version du banc passait
au vert dessus par accident ; l'assertion a été resserrée pour ne plus le couvrir.

---

## T58 — un produit épuisé restait cliquable. **Corrigé.**

### Le banc, et sa rougeur initiale

`tests-e2e/produit-epuise-inerte-2026-09-03.spec.js`. Le catalogue est bouchonné avec Glace,
Bol Riz et Terminator en rupture (Terminator est en vedette : sans un produit vedette épuisé, le
volet « carrousel d'accueil » ne mesurerait rien). Le banc vérifie d'abord qu'une carte épuisée
**et** une carte disponible sont rendues — sinon il serait vert pour la mauvaise raison.

Rouge avant correctif, sur les deux surfaces :

```
   épuisée (menu)    : {"nom":"Terminator","ariaLabel":"Terminator — épuisé",
                        "boutonDisabled":false,"conteneurAriaDisabled":"true"}
❌ [menu] le bouton du produit épuisé porte `disabled`
   épuisée (accueil) : {"etiquette":"Terminator — épuisé","disabled":false,"ariaDisabled":"true"}
❌ [accueil] la carte épuisée porte `disabled`
```

### Correction d'échelle du ticket : le clic n'ajoutait déjà rien

T58 dit « la Glace et le Bol Riz restent cliquables ». **Mesuré : le clic est déjà sans effet.**
`racine.jsx:405` intercepte (`if (unavail[item.id]) { setSoldOutToast(item.name); return; }`) :
ni assistant, ni fiche, ni ligne de panier — une notification. Vérifié par six assertions du
banc, vertes **avant comme après**.

Le défaut réel est donc **sémantique et d'accessibilité**, pas commercial : un `aria-disabled`
posé sur un conteneur qui réagit, et un bouton qui annonce « — épuisé » tout en restant dans
l'ordre de tabulation avec un `onClick` vivant. Une aide technique lit « désactivé » sur un
élément qui répond. Aucun risque de vendre un produit en rupture. Je le dis parce que la
priorité du ticket s'en trouve changée.

### Le correctif

`screens.jsx`, deux endroits :

- **grille du menu** (`ItemCard`) — `disabled={!!soldOut}` sur le bouton du nom,
  `cursor: not-allowed`, et le conteneur ne porte plus d'`onClick` quand le produit est épuisé
  (`onClick={soldOut ? undefined : onClick}` — il le garde sinon, cliquer la photo reste le
  geste naturel sur un produit disponible) ;
- **carrousel d'accueil** (`screens.jsx:910`, la carte `.lcx-carte`) — **même défaut, corrigé du
  même geste**. Le ticket ne nommait que la grille ; fermer T58 en laissant la moitié des
  surfaces fautives aurait été malhonnête.

Après correctif — **15 vert / 0 rouge**, dont la contre-épreuve « un produit **disponible**
s'ouvre toujours ». Capture : `tests-e2e/produit-epuise-shots/menu-apres-clic-conteneur.png`
(nom à contraste plein, photo grisée, pastille ÉPUISÉ, motif « Épuisé pour le moment »).

### Un changement de comportement à signaler

Le conteneur épuisé ne déclenche plus la notification « X est épuisé pour le moment », puisqu'il
ne réagit plus du tout. Aucune information n'est perdue : la carte affiche en permanence la
pastille ÉPUISÉ **et** le motif à la place de la description — c'est plus durable qu'un toast de
2,8 s. Le ticket tolérait « au plus une notification » ; zéro entre dans ce cadre. À signaler
tout de même : c'est un changement visible.

---

## T07 — trois murs d'upsell : mesure de friction. **Aucune implémentation.**

Décision commerciale propriétaire (porte P3-a). `upsell.jsx` n'a pas été touché.
Banc de mesure : `tests-e2e/upsell-friction-2026-09-03.mesure.js`.

### Premier résultat : ce n'est pas trois murs, c'est **deux**

Parcours réel depuis l'assistant produit, formule « Sans formule » :

```
 1. assistant · choix de formule    — « Sans formule / Juste le plat »
 2. assistant · bouton principal    — « Ajouter au panier 7,40 € »
 3. ouvrir le panier                — « Panier 1 »
 4. panier                          — « Passer commande »
 5. mur d’upsell #1                 — « Non merci, continuer »     [Une boisson ?]
 6. mur d’upsell #2                 — « Non merci, régler »        [Un petit dessert ?]
→ MURS PLEIN ÉCRAN : 2      → CLICS de « Sans formule » au paiement : 6
```

Le troisième `steps.push` (`upsell.jsx:80`, catégorie 7 « Et avec ça ? ») **ne se déclenche
jamais avec le catalogue actuel**. Vérifié en évaluant la logique de `buildSteps()` sur les
données réelles : le garde de composabilité du 2026-08-29 exclut les deux frites
(`has_sauce` + `has_frites_style` → `custom`), et les 9 produits en vedette sont tous
composables. `sugg` est **vide**. Le troisième mur est du code mort aujourd'hui — et il
ressuscitera dès qu'un produit vedette simple entrera au catalogue.

### Coût unitaire d'un mur, observé à panier contrôlé

| panier | murs | clics « Passer commande » → paiement |
|---|---|---|
| sandwich seul | 2 (*Une boisson ?*, *Un petit dessert ?*) | **4** |
| sandwich + boisson | 1 (*Un petit dessert ?*) | **3** |
| sandwich + boisson + dessert | 0 | **2** |

**Un mur = exactement un clic.** Ce n'est pas une extrapolation : c'est mesuré sur trois paniers,
au navigateur. C'est ce qui permet de chiffrer les options sans les implémenter.

### Les trois options, chiffrées

Base : 6 clics depuis « Sans formule », dont 2 murs.

| option | murs | clics | écart |
|---|---|---|---|
| **(a)** un seul écran « Un extra ? » groupant boisson, dessert et frites | 1 | **5** | −1 clic (−17 %) |
| **(b)** aucun mur si la formule a déjà été refusée à l'étape 4 | 0 | **4** | −2 clics (−33 %) |
| **(c)** statu quo | 2 | **6** | — |

**Ce que la mesure ne dit pas** : aucune de ces options n'a été mesurée en taux de conversion ni
en panier moyen. L'écart de friction est chiffré ; le manque à gagner de l'option (b) ne l'est
pas. C'est précisément ce qui rend la décision commerciale et non technique.

Le GOAL recommandait (a). La mesure la nuance : (a) économise **un seul** clic, pas deux, parce
que le troisième mur n'existe déjà plus. Si le but est de supprimer la friction du dernier
mètre, (b) fait le double d'effet. Si le but est de garder l'occasion de vente, (a) la conserve
entièrement pour un gain deux fois moindre.

**Aucune option implémentée. Le propriétaire tranche.**

---

## État des 11 empreintes `.source-sha256`

`node tools/compile-jsx.mjs --check` → **✓ 11 fichiers : tous les compilés sont à jour.**
Vérifié aussi à la main, `shasum -a 256` de chaque `.jsx` contre son `.source-sha256` :

| fichier | empreinte | recompilé |
|---|---|---|
| account-v2.jsx | `851165038cae…` | non |
| components.jsx | `e544b8aab640…` | non |
| flows.jsx | `64e90342d943…` | non |
| funnel.jsx | `a675d26d21dc…` | non |
| loyalty-v2.jsx | `b4fed122c0ab…` | non |
| orders.jsx | `538a4410975f…` | non |
| racine.jsx | `1fa27871e373…` | non |
| screens-v3.jsx | `c99dbea1a0f5…` | non |
| **screens.jsx** | `d2cd89cabc3d…` | **oui (T58)** |
| upsell.jsx | `9834dd1affa1…` | non |
| wizard-v2.jsx | `07ef34f91a54…` | non |

Le diff de `compiled/screens.js` est **confiné à deux hunks** (`@@ ItemCard @@` et `@@ WebHome @@`)
et contient exactement les changements attendus : `disabled: !!soldOut`,
`onClick: soldOut ? undefined : _onClick`, `cursor: soldOut ? 'not-allowed' : 'pointer'`,
`disabled: epuise`.

**Jetons de cache** — `node tools/check-asset-versions.mjs --check` →
**✓ 27 ressources versionnées : aucun bump oublié.**

| ressource | avant | après |
|---|---|---|
| `api.js` | `?v=20260902mail2` | `?v=20260903g8t10b` |
| `compiled/screens.js` | `?v=20260829aud2` | `?v=20260903g8t58` |

`tools/asset-versions.json` a suivi. Sans ces deux bumps, `vercel.json:77-83` servant tout `.js`
en `immutable` pendant un an, les correctifs seraient restés invisibles pour tout visiteur ayant
déjà ouvert le site.

---

## Non-régression

Aucun banc du dépôt ne citait la phrase générique remplacée (vérifié par `grep`).
15 bancs existants rejoués sur l'arbre final, **tous verts, 0 rouge** :

`nav-smoke.local.js` (11/11) · `one-page-checkout-2026-08-03` (17) ·
`coordonnees-erreurs-2026-08-28` (7) · `upsell-epuise-2026-08-28` (5) ·
`filtres-menu-2026-08-09` (6) · `filtres-balayage-2026-08-09` (4) ·
`compteurs-articles-2026-08-06` (PASS) · `wizard-cartes-immobiles-2026-08-28` (27) ·
`panier-modifier-abus-2026-08-26` (PASS) · `compte-email-dabord-2026-09-02` (24/24) ·
`crudites-tacos-legumes` · `titre-hero-2026-08-27` · `residus-honnetete-2026-08-07`.

Les trois bancs neufs : **12 + 26 + 15 = 53 assertions, 0 rouge**. 0 erreur JS partout.

---

## Ce que je n'ai pas pu faire, ou pas fait

1. **T07 n'est pas implémenté** — c'est une décision propriétaire, et le mandat était explicite.
   La mesure est livrée, la décision reste ouverte.
2. **T05 et T27 non traités** — hors priorité par le GOAL. T05 (clé d'API en clair dans
   `index.html:66`) relève de la mise en production. T27 (contradiction « sur place » /
   « au comptoir » entre `legal/cgv.html:87,139` et `index.html:347`) est une question
   contractuelle que seul le propriétaire tranche.
3. **La fuite d'anglais de l'heuristique à 17 mots-clés n'est pas corrigée** — hors mandat T10,
   documentée ci-dessus et dans le banc.
4. **Rien n'est déployé, rien n'est commité, rien n'est poussé.** Les correctifs sont vérifiés
   sur le **contenu servi** en local (compilé + jeton), pas sur la production.
5. **Les bancs `app/android/…/public/index.html` et `app/ios/…/public/index.html` n'ont pas été
   régénérés.** Ils embarquent leur propre copie d'`api.js` et de `compiled/screens.js`, figées
   à `?v=20260808v7` — donc **antérieures** aux correctifs. Elles se régénèrent par
   `tools/build-app-www.mjs`, hors périmètre G8 (vitrine web). À faire avant toute livraison de
   l'application native, sinon T10 et T58 y resteront présents.
6. **Le serveur d'une session voisine a été interrompu 4 minutes.** En nettoyant mon banc de
   mutation, j'ai tué le processus du port 8898, qui appartenait à une autre session et servait
   `/private/tmp/lc-avant`. Constaté et **remis en service immédiatement** (vérifié : 200 sur
   `/index.html`). Le port était déjà pris quand j'ai voulu m'en servir — c'est ce qui m'a fait
   basculer sur 8891, et c'est là que j'ai confondu les deux processus. Aucun contenu touché.

---

## Fichiers modifiés (aucun commit)

```
 M api.js                            (+ ~115 lignes : T10)
 M screens.jsx                       (+22 : T58)
 M compiled/screens.js               (recompilé, 2 hunks)
 M compiled/screens.js.source-sha256
 M index.html                        (2 jetons de cache)
 M tools/asset-versions.json
?? tests-e2e/creneaux-recouvrement-2026-09-03.mesure.js      (T04 — mesure)
?? tests-e2e/creneaux-non-recouverts-2026-09-03.spec.js      (T04 — garde-fou)
?? tests-e2e/promo-message-precis-2026-09-03.spec.js         (T10 — banc)
?? tests-e2e/produit-epuise-inerte-2026-09-03.spec.js        (T58 — banc)
?? tests-e2e/upsell-friction-2026-09-03.mesure.js            (T07 — mesure)
```
