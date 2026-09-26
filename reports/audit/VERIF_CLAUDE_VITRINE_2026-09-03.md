# Vérification en lecture seule — tickets Grok T01–T58 contre le SOURCE actuel

- **Dépôt vérifié** : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`, branche `main`, HEAD `007bc75`, remote `github.com/loeymot-sketch/Site-lecayenne.git`, arbre de travail propre.
- **Date de l'audit Grok** : 2026-08-28. **Date de cette vérification** : 2026-09-03.
- **Méthode** : lecture du source uniquement, aucun navigateur (MCP Playwright et Chrome DevTools ont échoué à se connecter). Aucun fichier source modifié.

## Préalable 1 — les correctifs `.jsx` sont bien compilés

Les 11 artefacts `compiled/*.js` portent une empreinte `compiled/<nom>.js.source-sha256`. Recalcul SHA-256 des `.jsx` : **11/11 concordent**. Il n'existe donc **aucun correctif présent en `.jsx` mais absent du déployé**.

Les jetons de cache ont suivi dans `index.html` — nécessaire, puisque `vercel.json:77-83` sert tout `.js`/`.css` en `immutable` un an : `styles.css?v=20260828aud1`, `compiled/wizard-v2.js?v=20260828aud1`, `compiled/screens.js?v=20260829aud2`, `compiled/upsell.js?v=20260829aud2`, `compiled/funnel.js?v=20260902mail2`.

## Préalable 2 — le miroir `/Users/1millnonstop/Downloads/web` DIVERGE, ne pas s'en servir

Dépôt distinct, HEAD `4d1dfcb`, dernière synchro ~12 juillet 2026. `sw.js` et `404.html` y sont **absents** ; `vercel.json`, `styles.css`, `upsell.jsx`, `wizard-v2.jsx`, `screens.jsx`, `data/menu.js` **diffèrent tous**. Un verdict rendu là serait faux sur au moins T01, T02, T03, T08, T49, T54.

---

# P0

## T01 — `sw.js` servi en `immutable` — **CORRIGÉ**

`vercel.json:77` exclut explicitement `sw.js` de la règle d'un an, et `:86-91` lui donne la sienne :

```
77:      "source": "/((?!sw\\.js$).*)\\.(js|css|png|…|ico)",
81:          "value": "public, max-age=31536000, immutable"
86:      "source": "/sw.js",
90:          "value": "public, max-age=0, must-revalidate"
```

Le fichier prend la main immédiatement : `sw.js:60` `await self.skipWaiting();`, `sw.js:68` `await self.clients.claim();`, `sw.js:67` purge toute clé `lecayenne-*` obsolète. Version : `sw.js:34` `const VERSION = 'lc-v1-2026-08-19';`.

## T02 — page 404 à liens relatifs — **CORRIGÉ**

`404.html` ne contient **plus aucun** `href`/`src` relatif : 23 `href` distincts tous absolus, zéro attribut `src`. Extraits : `href="/carte.html"`, `href="/assets/brand/favicon-32.png?v=20260811ui2"`, `href="/legal/cgv.html"`. Depuis `/plat/…` ils résolvent correctement.

## T03 — grille des sauces à 2 colonnes dès 600 px — **CORRIGÉ**

`styles.css:736-740` (commentaire daté `[AUDIT 2026-08-28]`, lignes 708-735) :

```
736:.lc-wiz-options {
739:  grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
```

La media query fenêtre a disparu ; `min-width: 0` est posé (`styles.css:763` `.lc-wiz-choice-body { flex: 1; min-width: 0; }`) ; mobile forcé à une colonne (`styles-mobile.css:200`). Aucune feuille v6/v7/v8 ne redéfinit `grid-template-columns` ici (seuls `styles-v7-braise.css:1458` et `:1469`). `.lc-wiz { overflow: hidden }` subsiste (`styles.css:679`) mais est délibéré et documenté (`styles.css:669-674`). Banc : `tests-e2e/wizard-cartes-immobiles-2026-08-28.regression.js`.

## T04 — `.lcf-cta-bar` recouvre les créneaux du checkout — **ENCORE VRAI**

Code **inchangé** depuis l'audit : dernier commit touchant `styles-v4.css` = `750d846`, 2026-08-08. Aucun correctif, aucun banc.

```
styles-v4.css:8:.lcf-page {
styles-v4.css:11:  padding: 32px 0 80px;
styles-v4.css:364:/* CTA bottom (sticky on mobile) */
styles-v4.css:365:.lcf-cta-bar {
styles-v4.css:366:  position: sticky; bottom: 0;
styles-v4.css:371:  z-index: 10;
```

Le commentaire dit « sticky on mobile », mais la règle n'est dans **aucune** media query : elle s'applique aussi en desktop. Or créneaux et barre sont dans le **même bloc** — `funnel.jsx:1639` `<div className="lcf-card">` contient `:1642` `<CheckoutSections …/>` (qui rend `:480` `'lcf-pickup-slot'`) **et** `:2002` `<div className="lcf-cta-bar">`. Un sticky opaque en `z-index: 10` se colle donc au bas de la fenêtre par-dessus ce qui le précède, et le `padding-bottom: 80px` de `.lcf-page` ne compense qu'en fin de page. La compensation n'existe qu'en mobile : `styles-mobile.css:155` `.lcf-page { padding-bottom: 120px; }`, dans le `@media (max-width: 700px)` ouvert ligne 149.

**Réserve honnête** : la condition de recouvrement est prouvée dans le code ; l'ampleur exacte du chevauchement n'a pas été re-mesurée au navigateur (MCP indisponible).

**Correctif minimal** : scoper la règle sticky au mobile (la déplacer sous `@media (max-width: 700px)`) ; ou, si la barre doit rester collée en desktop, ajouter un `padding-bottom` desktop sur `.lcf-card` égal à sa hauteur (~68 px). Effort : **S**.

---

# P1

## T06 — étape « Faire un menu ? » marquée REQUIS — **CORRIGÉ**

```
wizard-v2.jsx:655:{step.required && step.defaultValue === undefined && <span …>· requis</span>}
```

L'étape porte `required: true` (`wizard-v2.jsx:196`) **et** `defaultValue: 'none'` (`:204`) : « · requis » ne s'affiche plus. `required` reste car il alimente `canAdvance` (`:509`). Déployé : `compiled/wizard-v2.js:1050` gouverne bien le `"· requis"`.

## T07 — trois murs d'upsell successifs — **ENCORE VRAI**

`upsell.jsx:62-80`, trois `steps.push` indépendants, aucune fusion :

```
64:    if (!hasDrink && boissons.length)   steps.push({ id: 'drinks', title: 'Une boisson ?', …});
65:    if (!hasDessert && desserts.length) steps.push({ id: 'desserts', title: 'Un petit dessert ?', …});
80:    if (sugg.length) steps.push({ id: 'extras', title: 'Et avec ça ?', …});
```

Catégories 10 (`:62`), 9 (`:63`), 7 (`:75`) exactement comme décrit. Seul garde : « catégorie déjà au panier » (`:22`). Un client sortant du wizard en « Sans formule », panier sans boisson ni dessert, reçoit donc **3 écrans plein cadre** avant de payer. Aucun commit ne réduit ce nombre.

**Correctif minimal** : décision produit — fusionner les trois en un écran à sections, ou plafonner (`steps.slice(0, 1)`). Effort : **S** (plafond) à **M** (écran unique).

## T08 — l'upsell ne filtre pas les produits épuisés — **CORRIGÉ**

`upsell.jsx:17` `const dispo = (it) => !(unavail || {})[it.id];`, puis :

```
62:    const boissons = M.itemsForCategory(10).filter(dispo).filter(i => !isComposable(i));
63:    const desserts = M.itemsForCategory(9).filter(dispo)…
78:      .filter(dispo)      // garde dure : jamais un 86
```

Plus un filtrage au rendu (`upsell.jsx:114`). Banc : `tests-e2e/upsell-epuise-2026-08-28.regression.js`.

## T10 — message d'erreur promo générique — **ENCORE VRAI** (mécanisme prouvé)

`funnel.jsx:347` relaie le message de l'API — le repli « Code invalide » ne se déclenche que si le message est vide :

```
347:      setCtx(c => ({ ...c, promoErr: (e && e.message) || 'Code invalide', … }));
```

Or `api.js` **réécrit** systématiquement les messages anglais du backend avant de les jeter :

```
api.js:344:      } else if (/^[\x00-\x7F]*$/.test(msg) && /\b(the|field|…|invalid|…|not|found|…|expired|token)\b/i.test(msg)) {
api.js:349:        msg = (res.status === 422 || res.status === 400)
api.js:350:          ? 'Certaines informations sont incomplètes ou invalides. Vérifie tes coordonnées et ton panier, puis réessaie.'
api.js:353:      throw { kind: 'http', status: res.status, message: msg, body: json };
```

Un coupon refusé (422/400, message backend contenant `invalid` / `not found` / `expired`) affiche donc sous le champ promo un texte parlant des **coordonnées et du panier** — pas du code. `checkCoupon` (`api.js:1261-1266`) passe par ce même `req`, sans exemption. Dernière modification de `funnel.jsx:347` : `eca6bda`, 2026-07-09, antérieure à l'audit.

**Correctif minimal** : dans le `catch` de `applyPromo` (`funnel.jsx:346-348`), ignorer le message réécrit sur les 4xx et poser un texte propre au coupon (« Ce code promo n'est pas valide ou a expiré. »). Effort : **XS**.

## T11 — e-mail non validé au blur — **CORRIGÉ**

```
1452:    email: v => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(v || '').trim())
1453:      ? null : "Cet e-mail ne ressemble pas à une adresse — le code de confirmation y est envoyé.",
1469:  const blurCoord = (k) => {   // ne juge QUE le champ quitté
1672:                        onBlur={() => blurCoord('email')}
```

`aria-invalid` en regard (`:1670`), erreur effacée à la frappe (`:1476`). Déployé : 5 occurrences de `blurCoord` dans `compiled/funnel.js`. Banc : `tests-e2e/coordonnees-erreurs-2026-08-28.regression.js`.

## T14 — horaires sur l'horloge du visiteur — **CORRIGÉ**

```
116:function heureParis() {
118:  return T ? T.parties().heures : new Date().getHours();
126:function isOpenNow() { const h = heureParis(); return h >= 18 || h === 0; }
```

Fuseau nommé dans le helper : `api.js:1367` `timeZone: 'Europe/Paris', hourCycle: 'h23',` et `api.js:1399` `FUSEAU: 'Europe/Paris',`. `minutesParis` (`screens.jsx:120-125`) suit la même règle et alimente `FERMETURE_H` (`:158`, `:218`). Le `getHours()` restant n'est qu'un repli documenté (`screens.jsx:113-115`). Banc : `tests-e2e/horaire-fuseau-paris-2026-08-28.regression.js`.

## T49 — Galette Cayenne annoncée 7,00 € — **CORRIGÉ**

`sandwichs-galettes.html` affiche 7,40 € partout pour ce produit : `:572-618` (JSON-LD + FAQ), `:683` `<p class="sx-plat-prix">7,40 €</p>`, `:691`, `:708`, `:730`. Les « 7,00 € » restants sont ceux du **Suprême**, son vrai prix (`:679`, `:703`). Source alignée : `data/menu.js:493` `mkItem(202, 'galette-cayenne', 2, 'Galette Cayenne', 7.40,`.

## T50 — `@font-face` sans `../` dans les fiches `/plat/` — **CORRIGÉ**

Sur les **24** fiches `plat/*.html`, les trois `@font-face` pointent en chemin **absolu** :

```
plat/cayenne.html:32:  src: url('/assets/fonts/anton.woff2') format('woff2');
plat/cayenne.html:40:  src: url('/assets/fonts/inter.woff2') format('woff2');
```

24/24 fiches en `url('/assets/fonts`, **0** en relatif. Le `preload` (`plat/cayenne.html:26`) utilise `../assets/fonts/inter.woff2`, correct depuis `/plat/`. Commit `0ab2c5b`, banc `tests-e2e/polices-sous-repertoire-2026-08-29.regression.js`.

## T54 — pastille « Incluse » sur une sauce non sélectionnée — **CORRIGÉ**

Fait sous-jacent exact : `data/menu.js:493-497` définit l'item 202 **sans** `sauce_default` (le Cayenne 101 en a un, `:466`). Mais le badge est conditionné à la sélection réelle :

```
wizard-v2.jsx:749:  const showIncl = on && step.extraFrom != null && selIdx > -1 && selIdx < step.extraFrom;
```

`on` (`:745` `sel.includes(opt.id)`) est faux tant que rien n'est coché : sans `sauce_default`, Barbecue ne peut plus porter « Incluse ». Déployé identique : `compiled/wizard-v2.js:1199`.

## T58 — produit épuisé cliquable — **ENCORE VRAI**

`screens.jsx:88`, dans `ItemCard` :

```
88:        <button type="button" className="lc-card-item-name" aria-label={soldOut ? `${item.name} — épuisé` : `Voir ${item.name}`} onClick={e=>{ e.stopPropagation(); onClick(); }} style={{ … }}>{item.name}</button>
```

Aucun `disabled`, `onClick` actif. Le seul signal est `aria-disabled` sur le **conteneur** (`screens.jsx:56-57`), qui porte lui aussi un `onClick` non gardé. Déployé confirmé : `compiled/screens.js:152-159`, props du `button` = `type`, `className`, `"aria-label"`, `onClick`, `style` — **pas** `disabled`. Même motif vitrine : `screens.jsx:897-899`.

Nuance : ouvrir la fiche d'un produit épuisé peut être voulu. Le défaut réel est la **contradiction** entre un `aria-disabled` sur l'ancêtre + un libellé « épuisé », et un contrôle pleinement actionnable.

**Correctif minimal** : poser `disabled={soldOut}` sur le `<button>` et retirer le `onClick` du conteneur quand `soldOut` ; ou, si la consultation reste voulue, retirer `aria-disabled` et dire « épuisé, voir la fiche ». Effort : **XS**.

---

# P2 / P3

Aucun document listant T01–T58 n'existe dans le dépôt (la branche `audit/grok-2026-08-28` est un marqueur sans commit propre). Les énoncés viennent des **corps de commit** des 8 correctifs qui citent les tickets par numéro. Ceux marqués « énoncé introuvable » n'ont laissé aucune trace (`git log --all`, `git fsck`, tous les `.md/.html/.jsx/.js`, dépôts adjacents) — **non vérifiables**.

| Ticket | Verdict | Preuve fichier:ligne | Effort |
|---|---|---|---|
| T05 clé API en clair | **ENCORE VRAI** | `index.html:66` `<meta name="api-key" content="lc-hLKQXseYsjLIuuLkNIMI86wPqgneWg67CWaXIuyciYGT5">` ; `:63-65` l'assume (« PLACEHOLDER faible », « ⚠️ GO-LIVE : remplacer ») | L |
| T12 pastilles collées | RÉFUTÉ par mesure | corps de `b7a50a2` : « mesuré 6 px de boîte à boîte et 12 px jusqu'au texte, pas ~2 px » | — |
| T16 deux H1 | CORRIGÉ | `index.html:344` == `screens.jsx:751` `<h1 …>Le sandwich <em>autrement.</em></h1>` ; commit `c3ef4e3` | — |
| T27 CGV « sur place » | **ENCORE VRAI** | `legal/cgv.html:87` « commande à emporter ou à consommer sur place », `:139` « la consommation au comptoir » — contredit `index.html:347` « Tout se prend à emporter » et `:352` | XS |
| T28 halal | CORRIGÉ | `legal/allergens.html:184` et `a-propos.html:609` disent tous deux « Aucune certification halal n'est revendiquée » | — |
| T29 Google Fonts sur /legal/ | CORRIGÉ | `legal/cgv.html:12-19` « [T29 2026-08-28] POLICES AUTO-HÉBERGÉES » + `href="../fonts.css?v=20260828aud1"` ; 0 `fonts.g*.com` actif | — |
| T33 slugs légaux 404 | CORRIGÉ | `vercel.json:25-44` : 301 vers `/legal/mentions.html`, `/legal/cgv.html` (2 casses), `/legal/privacy.html` | — |
| T38 CSP unsafe-eval/unpkg | CORRIGÉ | `vercel.json:67` `script-src 'self' 'unsafe-inline' https://js.mollie.com` ; aucun `<meta http-equiv>` CSP | — |
| T44 nav SEO mobile | CORRIGÉ | commit `bd29586`, mesuré « 196 px / 23,2 % → 118 px » ; media query 700px dans `seo.css`, 36 pages | — |
| T51 manifest.json | NON REPRODUCTIBLE | 0 occurrence de `manifest.json` ; `index.html:124` `href="/manifest.webmanifest"`, `sw.js:45` le met en cache | — |
| T52 CSS légal ?v=20260729d | CORRIGÉ | `grep 20260729 legal/*.html` = 0 ; `legal/cgv.html:19` en `?v=20260828aud1` | — |
| T53 favicon.ico | CORRIGÉ par redirect | `favicon.ico` absent, mais `vercel.json:45-48` → `/assets/brand/favicon-32.png` en 307 délibéré (`d934bfe`) | — |
| T56 « 38 prix » | CORRIGÉ | `grep "38 prix"` = 0 ; « 39 produits » partout (`carte.html:7`, `index.html:350`, `llms.txt:23`). Reste 2 commentaires internes périmés : `data/menu.js:449`, `:771` | XS |
| T09, T15, T17, T19, T20, T21, T24, T25, T26, T30, T31, T35, T36, T39, T40, T41, T57 | ÉNONCÉ INTROUVABLE | aucune trace dans le dépôt — non vérifiables | — |

---

# Synthèse

Deux ENCORE VRAIS hors P0/P1 : **T05** (clé API en clair, assumée jusqu'au go-live) et **T27** (CGV contredisant le « tout à emporter » du reste du site). Corroboration : le corps du commit `b7a50a2` note lui-même T07 « CONFIRMÉ, non corrigé — décision commerciale propriétaire requise ».

`ENCORE_VRAIS_P0: 1` (T04)
`ENCORE_VRAIS_P1: 3` (T07, T10, T58)
`DEJA_CORRIGES: T01, T02, T03, T06, T08, T11, T14, T16, T28, T29, T33, T38, T44, T49, T50, T52, T53, T54, T56` (+ T12 réfuté par mesure, T51 non reproductible)
