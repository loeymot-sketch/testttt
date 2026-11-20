# C1 — Affichage des suppléments à la caisse (enquête d'incident, 2026-09-05)

Agent C1. Lecture seule. Aucun fichier du produit modifié. Toute affirmation ci-dessous
est adossée à un `fichier:ligne` lu ou à une requête SQL exécutée sur la production
(`ssh lecayenne`, `mysql --defaults-extra-file=/tmp/.dbcnf foodking`, SELECT uniquement).

Contexte technique vérifié d'abord :
- `public/js/pos-wizard.js` servi en production est **identique** au dépôt (330 216 octets,
  mêmes numéros de ligne — `ls -la` + `grep -n "sup.currency_price"` → ligne 3201).
- Le lot Vue servi est à jour : `/js/pos-shell.618d1c8c.js` contient déjà le correctif
  Cheddar du jour (`includes("cheddar")&&i.includes("fondu")`). Pas de défaut fantôme
  par asset périmé.
- `NumberFormatter('fr_FR', CURRENCY)` rend bien `0,90 €` sur la machine de production
  (`php -r` → `302c3930c2a0e282ac`, NBSP U+00A0). Réglages `settings` : symbole `€`,
  position `10`, `site_digit_after_decimal_point = "2"`.

---

## Symptôme (1) — « ça affiche un chiffre 90 » → **PISTE NON PROUVÉE**

Je n'ai trouvé **aucun chemin** qui transforme `0.90` en la chaîne nue `"90"`.
Ce que j'ai lu et écarté, avec preuve :

- `public/js/pos-wizard.js:345-357` `fmtPrice()` → `Intl.NumberFormat('fr-FR', currency EUR)`,
  repli `toFixed(2).replace('.', ',') + ' €'`. Jamais d'entier nu.
- `resources/js/helpers/formatPrice.js:40-54` → idem, `"0,90 €"`.
- `resources/js/services/appService.js:87-111` `currencyFormat()` → `toFixed(digits)` puis
  virgule + symbole ; défaut 2 décimales explicite (commentaire PRIX-AFFICHÉ-DÉCIMALES).
- `resources/js/helpers/posFormatCents.js:31-60` `formatCents()` rend `"0,90 €"` ou `"0.90"`
  (variant `plain`) — **jamais** l'entier de centimes.
- Le seul endroit du produit où un montant devient un entier nu est
  `resources/js/helpers/posCentsArith.js:46-59` et `posSplitPayment.js:57-60`
  (`toCents(0.90) === 90`). Grep exhaustif des consommateurs : uniquement
  `PaymentComponent.vue:381` (paiement fractionné). **Aucune surface d'affichage de
  supplément ne consomme cette couche.** Conformément à la mémoire projet
  (« mesurer la couche que la surface consomme »), je ne peux pas l'imputer.

**Lecture alternative, elle prouvée** — « un 90 qui n'a rien à voir » = un `+0,90 €`
affiché là où le propriétaire n'attend pas un supplément :

`public/js/pos-wizard.js:3126-3128` place dans le bac **crudités** uniquement
`extra.convert_price === 0 && isCruditeName(...)`. `public/js/pos-wizard.js:3148-3156`
place dans le bac **suppléments** tout `extra.convert_price > 0`.
SQL production : `SELECT DISTINCT name, price FROM item_extras WHERE group_label='crudite'
AND price > 0` → **`Maïs`, `Olives`, `Poivrons cuits`, toutes à 0,900000**.
Ces trois crudités sortent donc du bac crudités et s'affichent dans la grille
« ➕ Suppléments » avec l'étiquette `+0,90 €` (`pos-wizard.js:3205`).

**À VÉRIFIER par un second moyen** : extraction DOM sur la caisse réelle
(`.supplement-opt .option-price`) au moment où le propriétaire voit le « 90 ».
Sans cette mesure, je ne signale pas de correctif sur le formatage : ce serait viser
un défaut non reproduit.

---

## Symptôme (2) — « Américaine s'affiche comme un supplément » → **CAUSE PROUVÉE**

`Américaine` est une **variation** de l'attribut 5 « Sauce (1ère Gratuite) »
(SQL : `item_variations` → ids 817, 818, 820… `price = 0.000000`).
Le groupe `sauce` d'`item_extras` (45 lignes à 0,50 €) ne contient **qu'un seul nom** :
`Sauce supplémentaire` (SQL vérifié). Il n'existe donc pas d'extra « Américaine ».

Chaîne réelle, lue ligne à ligne :
1. `pos-wizard.js:4201-4223` — dès la 2ᵉ sauce, le wizard coche l'extra **générique**
   `Sauce supplémentaire` et transmet la quantité (`data-wizard-qty`).
2. Le nom réel de la sauce ne survit que dans le texte libre `instruction`
   (`pos-wizard.js:3984-4002`, « Sauce : Hannibal, Andalouse »).
3. À l'affichage, `resources/js/helpers/kdsSymbolic.js:240-251` `extraDisplayName()`
   récupère les noms via `extraSauceNames()` (`:201-213`, `.slice(1)`) et rend
   **`Sauce supplémentaire : Américaine`**, dans le bloc **Extras** —
   `ReceiptComponent.vue:537-549` (reçu client) et `KitchenTicketSymbolicFormatter.php:376`
   (ticket cuisine).

Donc : la 2ᵉ sauce EST, structurellement, un supplément facturé, et elle est affichée
sous l'intitulé « Extras ». Ce n'est pas un bug de classification : c'est le modèle.

**Cas frère, lui aussi PROUVÉ** : `resources/js/components/admin/pos/ItemComponent.vue:1521-1532`
— une crudité **payante** (`isFree = parseFloat(extra.convert_price) <= 0` faux) qui ne
contient ni `tomate`/`oignon`/`salade`/`cornichon` tombe dans le `else` final
`restore.supplements['p_' + extra.id] = true`. `Maïs`, `Olives`, `Poivrons cuits` sont
donc classées **suppléments** à la réouverture d'une ligne. Confirmé par une commande
réelle : `order_items.id = 2250`, instruction = « … Supplément : Olives (+0,90 €) ».

**Correctif scope-minimal proposé** (aucune zone gelée) : décider en base, pas en code.
Soit les 3 extras passent en `group_label = 'supplement'` (la vérité tarifaire), soit
leur prix passe à 0. Toute autre option demande de toucher `pos-wizard.js` → **LOCK**.

⚠️ Divergence latente relevée, à ne pas « corriger » sans mesure :
`ItemComponent.vue:1565` teste encore `extraLower.includes('cheddar')` seul, alors que la
branche primaire `:1500` exige désormais `cheddar` **et** `fondu` (correctif du jour).
Le miroir n'est plus strict. Sans effet aujourd'hui (un Cheddar payant sort de toute façon
par `isGarniture === false` ligne 1567), mais le commentaire du bloc promet un miroir exact.

---

## Symptôme (3) — « sur le ticket ça ne s'affiche même pas »

### Ticket imprimé et cuisine → **NON REPRODUIT**
`composition_snapshot` porte bien les prix. SQL sur 30 jours :
`SELECT COUNT(*), SUM(composition_snapshot IS NULL), SUM(JSON_LENGTH(...'$.extras')=0)`
→ **506 / 0 / 0**. Exemple réel `order_items.id = 2064` :
`{"extra_name":"Oignons frits","line_total":0.9,"unit_price":0.9}`.
Le garde de repliement `KitchenTicketSymbolicFormatter.php:268-277` (`isFreeExtra`,
`:? unit_price ?? line_total`) et son jumeau `kdsSymbolic.js:477-480` voient donc bien
un prix > 0 et **n'escamotent pas** le supplément. `OrderReceiptEscPosRenderer.php:616-632`
l'imprime en `+ Nom … 0,90 €`.
*Risque latent seulement* : si l'instantané venait à ne pas porter d'extras, le repli sur
`item_extras` brut (sans `unit_price`) ferait disparaître « Oignons frits » (0,90 €,
`cruditeSymbol` → `O`) dans les lettres de crudités.

### Panier de la caisse → **CAUSE PROUVÉE**
`public/js/pos-wizard.js:3026-3038` : le bloc « Suppléments » de `buildCartDisplay()`
ne résout les noms qu'à travers `steps` → `(s.paidItems || []).concat(s.items || [])`.
Or la grille de suppléments affichée à l'écran vient, elle, directement de
`lastItemData.extras` (`:3148`) — les deux sources sont **découplées**.

Deux populations où `steps` ne porte aucun supplément, donc où le choix est facturé,
imprimé, mais **absent de la ligne du panier** :
1. **Composer** : `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true` dans le `.env` de production.
   `buildStepsFromComposerProfile` (`:701-747`, `options:` en `:739`) produit des étapes avec la clé `options`,
   **jamais** `items`/`paidItems`. `NormalItemResource.php:186-207` (`composerProfilePayload`) filtre
   `->where('item_id', $this->id)` : seuls **41 (Bol Frites)** et **45 (Bol Riz)** ont un
   profil publié par item (SQL : 8 publiés, 6 sont par catégorie donc ignorés). Ces deux
   articles portent **11 suppléments payants chacun** (`supplement_bol`, 0,90–2,50 €).
2. **Catégorie « Frites » (id 7, `wizard_template = snacking`)** : `getAllowedSteps` ne
   renvoie que `['sauce_single','recap']` (`pos-wizard.js:495-530`, `case 'snacking'` en `:528`)
   → aucune étape porteuse de `paidExtras`. Les 6 articles frites ont chacun 1 extra payant.

**Correctif scope-minimal** : faire lire au bloc « Suppléments » de `buildCartDisplay()`
la même source que la grille (`lastItemData.extras`, filtré par `selections.supplements`),
exactement comme le fait déjà `buildTicketInstruction()` en `:4026-4037`.
⛔ **Ce fichier est en ZONE GELÉE STRICTE — ce correctif exige un LOCK propriétaire.**

## Q3 — Les 52 extras sans `group_label` → **inclus, pas exclus**
`ComposerProfileProjection.php:250-270` `matchesExtraGroup()` : `$label = ''` ne matche que
si `$sourceRef === 'default'` **ou** si `'default'` figure dans
`ComposerTemplateService::EXTRA_GROUP_ALIASES[$sourceRef]` — ce qui est le cas de
`supplement` et `supplements` (`ComposerTemplateService.php:28-29`). Un extra sans groupe
est donc **rattaché aux étapes « Suppléments »**, et jamais à toutes les autres
(`$sourceRef === ''` → `return false`, `:252-255`). Ce n'est **pas** la cause de (3).
Deux étapes publiées sur trois utilisent cependant `source_ref = 'supplement_bol'`, qui
n'a **aucun alias** : les 52 extras à 1,00 € y sont exclus — comportement voulu, à confirmer.
