# Commandes Uber par PHOTO + frites des menus au bandeau CUISSON — 2026-08-10

> État : **livré, testé, non committé, non déployé.**
> Demande owner : « avec la tablette je photographie les commandes Uber, ça entre dans le flux de
> l'écran de cuisine ET de la caisse, ça s'imprime en cuisine avec **Uber** dessus et le **nom du
> client** ; tout traduit en symbole comme nos produits, **sauf les suppléments** qui restent
> complets » + « quand on prend un **menu**, il faut compter **une frite** pour la cuisson ».

---

## 1. La question posée par l'owner : « as-tu besoin d'une clé d'API ? »

**Oui, pour LIRE le texte de la photo — et c'est la clé qui existe déjà.**

| | sans clé (défaut) | avec clé |
|---|---|---|
| Photographier, plusieurs clichés → 1 commande | ✅ | ✅ |
| Aperçu cuisine, validation humaine | ✅ | ✅ |
| Commande sur l'écran cuisine + la caisse | ✅ | ✅ |
| Impression cuisine « UBER EATS » + nom client | ✅ | ✅ |
| **Lecture automatique du ticket** | ❌ — l'écran le DIT et invite à saisir depuis la caisse | ✅ |

- Clé : `OPENAI_API_KEY` — **la même que le scan de factures fournisseur**, rien de nouveau à ouvrir.
- Interrupteur dédié : `UBER_VISION_ENABLED=true` (permet d'activer l'un sans l'autre : lire un
  ticket client toutes les 5 minutes n'est pas le volume d'une facture de temps en temps).
- **Piège fermé** : sans clé, la doublure locale aurait rendu le ticket d'EXEMPLE face à une vraie
  photo. Le personnel aurait vu une commande plausible — un client, des produits, un total — et
  aurait pu l'envoyer en cuisine. Une commande **inventée** serait partie en préparation. Le repli
  sur l'exemple n'existe désormais **qu'en test** ; ailleurs, l'absence de lecteur se dit.

---

## 2. Défaut n°2 — les frites des menus, PROUVÉ EN BASE avant correction

Le bandeau CUISSON ne comptait la frite que si le menu arrivait par le canal **addon**. Or le menu
n'arrive pas par le même canal selon la surface de vente :

| surface | comment le menu arrive | comptait ? |
|---|---|---|
| BORNE / WEB | **addon** du produit (`menu_full` / `menu_frites`) | oui |
| **CAISSE** | **ligne de commande DÉDIÉE** (« Menu (Frites + Boisson) ») ; le parent n'en garde qu'un **écho** « + Menu (…) » dans son texte libre | **NON → 0F** |
| profil composé (bols) | **texte libre seul** : « Formule : Avec frites » | **NON → 0F** |

Mesure sur les commandes réelles : `#5303 → 0F`, `#5106 → 0F`. Après : `1F` chacun, **et le parent
porteur de l'écho reste à `0F`** (aucun double comptage).

**Ordre qui rend le double comptage impossible** — jumeaux PHP (`MeatPortionCalculator`) ET JS
(`kdsSymbolic.js`) modifiés ensemble :
(A) l'article EST le conteneur de menu → (B) frite vendue seule → (C) canal addon →
(D) repli texte libre « Formule : … frites » **SEULEMENT si (C) n'a rien donné**.
L'écho de la caisse ne comporte jamais « Formule : » : il ne peut pas doubler la ligne dédiée.

⚠️ **`Sauce frites : …` n'est PAS une preuve de frites** — elle figure sur des tickets sans aucune
frite. Jamais utilisée comme signal.

**15 cas ajoutés au golden partagé** `tests/fixtures/cuisson/parity_cases.json` (43 cas × 2 moteurs).
**Anti-test-creux prouvé** : moteurs révoqués → **3 cas rougissent sur chaque moteur**, les 40
autres restent verts.

---

## 3. Le canal « Uber par photo »

Deux temps — **rien ne part en cuisine sans validation humaine** :

1. `POST /api/admin/uber/photo/scan` — 1..6 photos → stockage → lecture → **aperçu cuisine calculé
   par les services de la cuisine eux-mêmes**. Aucune commande, aucune impression, aucun stock.
2. `POST /api/admin/uber/photo/{id}/confirm` — validation (éventuellement corrigée) → commande
   réelle → écran cuisine + caisse + impression.

Écran tablette : `/admin/uber-photo` (entrée « Commande Uber (Photo) » dans la barre latérale,
porte `pos-orders|pos` — la même que « Commandes Caisse »).

**Idempotence double** : empreinte sha256 du contenu des photos (UNIQUE par branche) + une capture
déjà confirmée rend SA commande. Deux appuis ne font pas deux plats.

**`UberOrderIngestor` = chemin de création UNIQUE.** Le webhook Uber a été **refondu dessus** plutôt
que dupliqué (23 tests Uber existants restés verts). Dupliquer aurait fait perdre à l'une des deux
copies l'anti-doublon au niveau commande, la boucle anti-collision de numéro d'appel, l'ancrage de
l'utilisateur technique ou la pierre tombale d'annulation-avant-création.

**Où va quoi** (règle owner : « tout en symbole, sauf les suppléments ») :

| lu sur le ticket | rangé dans | rendu cuisine |
|---|---|---|
| viande, sauce, pain/galette | `lines` | ligne 1 symbolique (`G \| TAC \| P \| ST \| ALG`) |
| crudités gratuites | `extras` (prix 0) | repliées en « STO » |
| **suppléments payants** | `extras` | **« + Cheddar », EN TOUTES LETTRES** |
| boissons | `addons` (`drink`) | « 1 Coca-Cola 33cl » |
| formule avec frites | `addons` (`menu_full`) | « MENU » + 1 frite au bandeau |
| sauce des frites | instruction | « MENU : KTP » |
| note client | instruction, **entre crochets** | conservée même en MAJUSCULES |

Le classement interroge **les tables de la cuisine elles-mêmes** — jamais une copie. Ce qui n'est
reconnu par personne devient un supplément écrit en toutes lettres : le cuisinier lit le texte
d'origine et décide, plutôt que de voir un symbole faux ou rien du tout.

---

## 4. Défauts trouvés par la CAPTURE, pas par les tests

1. **« Sauce frites : Ketchup » atterrissait dans la sauce du SANDWICH** → du ketchup dans le tacos.
   Canal dédié créé (écrit au format exact que lit `fritesSauceSymbol`).
2. **La carte de cuisine annonçait « Uber Eats 📞 0000000042 »** — l'identité et le numéro de
   l'utilisateur **TECHNIQUE** d'ancrage (`orders.user_id` est NOT NULL) — alors que le prénom du
   client était déjà scellé sur la commande et **déjà imprimé sur le ticket**.
3. **Le MÊME défaut sur la CAISSE** (`SimpleOrderResource`), que le premier correctif n'avait pas
   touché : le jumeau oublié, encore. Les deux ressources sont désormais verrouillées par un test
   de **parité**. *Un numéro factice affiché à côté d'une commande finit par être composé.*
4. **La sauce des frites disparaissait** quand les frites sont le PRODUIT (`#5810` : trois sauces
   choisies, aucune visible en cuisine). Le badge qui devait les porter n'existait que pour les
   menus, et le nettoyeur d'instruction supprime la ligne d'origine. Règle du badge extraite dans
   `KitchenTicketSymbolicFormatter::menuBadge()` — une seule règle PHP au lieu de trois copies.
5. **L'impression cuisine Uber n'avait JAMAIS fonctionné** : une commande Uber naît directement au
   statut ACCEPTÉ (prépayée), elle ne franchit donc jamais le changement de statut sur lequel repose
   le déclencheur d'impression ; et la surface `uber_eats` manquait au déclencheur à la création.
   Vérifié en base : `kitchen_ticket_printed_at` désormais horodaté.

---

## 5. Preuves

- **Frozen zones : zéro ligne touchée.** **NF525 : `CHAIN OK` sur les 4 branches actives.**
- PHP : Uber 54 · Hardware 136 · Kitchen 96+ · KDS 76+ · Pos 198 · Order 88 · Purchasing 36 — verts.
- JS : 103 fichiers / 873 tests verts (dont les 58 fichiers sentinelles) après `npm run production`
  — les sentinelles de fraîcheur de bundle avaient bien rougi AVANT recompilation.
- Playwright `uber-photo-2026-08-10` 2/2, spec rendue **rejouable** (ticket au numéro unique :
  une spec qui ne passe que sur une base vierge ne prouve rien).
- Captures **lues et analysées** dans `tests/captures/uber-photo-2026-08-10/` : écran vide, aperçu
  cuisine, envoyée, portrait, carte KDS, suivi caisse.
- **Anti-test-creux prouvé deux fois par révocation** : 3 cas rougissent sur chaque moteur cuisson ;
  le test d'impression Uber rougit sans sa surface.

### Suite Feature complète : 4103 passés, 35 ignorés, **7 échecs — triage intégral**

| # | test | verdict |
|---|---|---|
| 1-2 | landing client (`AntiGravity…`, `AuthComprehensive…`) — **500 au login** | **PRÉEXISTANT**, prouvé par `git stash` : échoue sans aucune de mes modifications |
| 3 | `BranchScopeCoverageSentinelTest` → `WheelStepProgress` | travail ROUE (`2894d78aa`) |
| 4 | `AdminRoutePermissionFloorTest` → `observability/interrupteurs` | travail PILOTAGE |
| 5 | `ClaudeMdBranchScopeCountSentinelTest` | **HEALÉ** — CLAUDE.md §9 : 22 → **24 models** |
| 6-7 | `WithoutGlobalScopesAuditSentinelTest` | **ma part HEALÉE** ; reste 10 sites, tous roue/promo |

**Défaut d'AUTH réel découvert (non touché — zone partagée, 2ᵉ session active) :**
`AppLibrary::defaultPermission()` (`app/Libraries/AppLibrary.php:347`) rend un `stdClass` **vide**
quand le rôle ne porte aucune permission `access=true` (cas d'un rôle Client). `defaultMenu()`
(`:168`) lit ensuite `$defaulPermission->url` → *Undefined property* → **500 au login**.
Garde suffisante : `isset($defaulPermission->url)`. Deux tests le couvrent déjà.

**Mes 2 infractions `withoutGlobalScopes()` pluriel, healées :** `UberOrderIngestor:47` allowlisté
Cat A (le dédup au niveau commande doit voir la commande cross-branch — l'appel peut venir d'un job
sans utilisateur — **et** soft-deleted : une commande tombstonée prouve que le ticket a déjà été
ingéré, la recréer enverrait le même plat deux fois en cuisine) ; entrée webhook recalée `158→167`
après l'extraction. Sémantique **reprise telle quelle**, aucune règle changée.

---

## 5bis. 2ᵉ passe owner — lisibilité cuisine (même jour)

> « la cuisine se trompe entre CHEESE et CHICKEN, écris les deux en complet ; le menu enfant
> chicken burger, on ne voit pas que c'est un menu enfant ; et les sauces, s'il en a pris
> plusieurs, affiche-les au bon endroit — si pour les frites ou pour le sandwich »

### (1) Le code produit 3 lettres ne DÉSIGNAIT plus

Collisions **prouvées** en rendant tout le catalogue actif par le vrai moteur :

| code | produits réels | verdict |
|---|---|---|
| `CHE` | **Cheddar** + **Cheese Burger** | collision franche |
| `CHI` | Chicken Burger | à une lettre de `CHE` |
| `DOU` | Double Cheese | ne dit rien de ce qu'il faut préparer |
| `ENF CHI` | Menu Enfant Chicken Burger | marqueur trop discret |
| `GAL` | **Galette Cayenne + Normale + pommes de terre** | 3 produits, 1 code |

Livré : `CODE_ECRIT_EN_ENTIER = ['cheese','chicken','menu enfant']` → **CHEESE BURGER ·
CHICKEN BURGER · DOUBLE CHEESE · MENU ENFANT CHICKEN BURGER · MENU ENFANT NUGGETS** ; et
`galette` ajouté à `CODE_BASE_WORDS` (même mécanisme éprouvé que `bol`) → **GAL CAY / GAL NOR /
GAL POM**. Le nom rendu est le nom **normalisé** en majuscules : ASCII pur, sinon « SUPRÊME » ne
survivrait pas à toutes les pages de code d'imprimante.

**Largeur vérifiée** (42 et 48 colonnes) : le ticket **enveloppe**, il ne tronque pas
(`3 x MENU ENFANT / CHICKEN BURGER | S / | ALG`). Contrepartie assumée : un menu enfant occupe
3 lignes imprimées au lieu d'une.

### (2) La sauce FANTÔME

Les wizards FROZEN facturent un extra **générique et sans nom** (« Sauce supplémentaire ») ;
l'identité de la sauce ne vit que dans le texte libre, sur **deux** canaux :

* « Sauces en plus : … » → sauces du **produit** → repliées dans la ligne 1 ;
* « Sauce frites : A, B » → sauces des **frites** → la 1ʳᵉ offerte, les suivantes payantes,
  affichées sur le badge (« MENU : KTP MAY »).

**Seul le premier était compté.** Sur les commandes réelles **#5835 / #5810 / #5755**, le client
prend 1 sauce sandwich + 2 sauces frites : le ticket affichait la bonne ligne 1, le bon badge…
**plus un « + Sauce supplémentaire » anonyme** — une sauce de plus, sans nom ni destination.

Remède : un **budget** de sauces payantes déjà expliquées ailleurs ; on masque ce nombre d'unités,
et **tout surplus reste visible** (« + Sauce supplémentaire ×2 »). ⛔ Une sauce **facturée** que
rien n'explique ne disparaît jamais en silence.

### (2bis) Tous les chemins par lesquels des frites arrivent

Le badge qui porte la sauce des frites n'existait que derrière un **menu/formule**. Or les frites
arrivent aussi :

* comme **PRODUIT** (« Grande Frites ») — aucun menu, donc aucun badge (#5810, 3 sauces perdues) ;
* par la **RECETTE d'un MENU ENFANT** (`RECETTES_FIXES` F:1) — le bandeau de cuisson les compte,
  mais rien n'affichait leur sauce ;
* écrites par la **CAISSE sur le produit lui-même**, sans aucun addon — vérifié sur `#4926`
  (Tacos M, « ↳ Sauce frites: Harissa ») : la sauce disparaissait purement et simplement.

Règle retenue, volontairement large : **une sauce CHOISIE ne disparaît jamais.** Dès qu'une sauce
frites est lisible et qu'aucun autre badge ne la porte, elle s'affiche (`FRITES : HAR`).
`#4926` rend désormais `G | TAC | P | ALG` + `FRITES : HAR`.

⚠️ Un test que j'avais écrit plus tôt dans la session scellait l'ANCIEN comportement (« un produit
ordinaire ne reçoit pas de badge ») — c'est-à-dire qu'il **verrouillait la perte de la sauce**. Il a
été corrigé, avec la preuve terrain en commentaire.

### (3) Résidu de note

**#5896** imprimait « · . » en note client : le retrait des segments « Viandes/Sauces en plus » ne
nettoyait que le **début** de ligne. Toute ligne sans lettre ni chiffre est désormais écartée ; les
vraies notes (dont `[ALLERGIE ARACHIDE …]`) survivent.

### Preuve de non-régression la plus forte

Les **220 lignes réelles** de `tests/fixtures/parity_php.json` repassées dans le moteur **actuel**
avec la **signature exacte du générateur** (`mainLine($name, $snap)`, sans instruction — la passer
crée 4 faux positifs de sauces repliées) : **43 lignes changent, toutes dans les familles visées,
zéro effet collatéral**. L'empreinte a ensuite été régénérée
(`php artisan tinker --execute="require 'tools/audit/gen-parity-fixture.php';"`) et
`kitchenParityRealData` est verte (7/7).
⚠️ Le corpus est **ré-échantillonné** à chaque génération : un diff brut mélange « changement de
code » et « changement d'échantillon ».

**Anti-test-creux par révocation** : 7 tests PHP + 6 tests JS rougissent sans les correctifs. Deux
tests portaient l'**ancienne** règle (`ENF BUR`, `CHI`) — mis à jour en conservant la propriété
qu'ils protégeaient (*un menu enfant ne rend jamais la même ligne que l'adulte*).
`sidebarV1Cleanup` remonté 15→16 **dans la session qui livre l'entrée**.

### Piège de sentinelle à connaître

`appBundleFreshnessSentinel` / `posApp…` comparent des **dates**. Un ré-enregistrement de
`resources/js/languages/fr.json` **à contenu identique** (linter) les fait rougir — et webpack ne
réécrit **pas** un bundle inchangé, donc recompiler n'y change rien. Vérifier le **contenu servi**
avant tout :
`grep '"cheese","chicken","menu enfant"' public/js/admin-kds.*.js` et
`grep 'Commande Uber (photo)' public/js/app.js`, **puis** aligner la date.
⛔ Jamais aligner sans cette preuve. Corollaire : le rendu cuisine vit dans **`admin-kds` /
`admin-shell`**, pas dans `app.js`.

**Suites** : Hardware 146 · Kitchen 99 · KDS 77 · Uber 54 · RawMaterials 63 — vertes.
**JS complet : 395 fichiers / 2860 tests, 0 échec.**

## 6. À l'attention de l'owner

- **Une autre session travaille sur cette branche** (`app/Mail/WheelPrizeMail.php`,
  `tests/Feature/Wheel/`, `wave-b-audit/`, `roue-reel-tmp.mjs`). Aucun de ces fichiers n'a été
  touché ici. **HOLD deploy** jusqu'à convergence des deux voies.
- **Rouge non imputable** : `BranchScopeCoverageSentinelTest` échoue sur `App\Models\WheelStepProgress`
  (commit `2894d78aa`, travail roue) — modèle portant `branch_id` sans `BranchScope`.
- Portée volontairement laissée de côté : les photos d'origine sont **conservées** (preuve) mais ne
  sont pas re-consultables depuis l'historique de l'écran. À ajouter si le besoin apparaît.
