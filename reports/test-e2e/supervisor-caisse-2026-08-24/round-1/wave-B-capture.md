# VAGUE B — ÉCRAN DE CAISSE · Round 1 · phase CAPTURE

**Date** 2026-08-24 · **Équipe** GStack Vague B · **Rôle** capture et constat, AUCUNE correction
**Spec** `tests/e2e/audit-supervisor-waveB.spec.js` — 9 états, 9 verts, une seule exécution cohérente (1 min 42)
**Artefacts** `tests/e2e/__screenshots__/test-e2e-waveB/` — quartet complet par état (`.png` · `.dom.html` · `.console.json` · `.network.json`) + un `.mesures.json` par état
**Serveur** `http://127.0.0.1:8000` (worktree `goal-caisse-vision-2026-08-24`), déjà en ligne, ni relancé ni tué
**Zones gelées** `pos-wizard.js`, `pos-wizard.css`, `admin-pos-v4.blade.php`, `PaymentComponent.vue`, `v5/PosV5TrancheRow.vue` : LUES uniquement. Zéro octet écrit.
**Données** aucune semence, aucun préfixe `AUDB-` employé, aucune commande créée (le panier de l'état 5 n'est jamais encaissé). Rien à nettoyer.

---

## 0. Deux avertissements de méthode, à lire avant les chiffres

### 0.1 L'application était cassée au premier passage — les captures ont été jetées
Le `vendor/` du worktree était amputé de 1 244 fichiers ; l'application répondait **HTTP 200** en n'affichant
qu'un avertissement PHP. Une sonde de code de statut mentait donc. Le coordinateur a réparé par `rsync`.
**Toutes les captures antérieures ont été supprimées et refaites.** Le spec porte désormais une garde
(`garderContreAppCassee`, ligne 169) qui inspecte le CORPS de la réponse — `Warning: require`, `Fatal error`,
`Failed to open stream`, HTML anormalement court — et **refuse de photographier** une application cassée.
Elle est armée sur les cinq navigations du spec. Toutes les mesures ci-dessous ont été prises après réparation.

### 0.2 Deux unités de pixel coexistent en caisse — ne jamais les additionner
`resources/js/helpers/caisseZoom.js` applique **`body { zoom: 0.9 }`** au montage du POS (mandat propriétaire,
« un peu plus de contenu sans que tout devienne minuscule »). Conséquence mesurée : `devicePixelRatio = 1`,
`visualViewport.scale = 1`, mais `getComputedStyle(document.body).zoom === "0.9"`.

* `getBoundingClientRect()` rend des **pixels écran** — ce que le propriétaire voit.
* `clientHeight` / `scrollHeight` et toutes les règles `vh` sont en **pixels CSS**.
* Facteur mesuré : **0,901** (corps du panier : 179 px écran pour 199 px CSS).

**Le « plancher de 154 px » du corps du panier est donc 154 px CSS = 138 px réels à l'écran.**
Les tableaux ci-dessous précisent l'unité à chaque ligne. Le premier jet de ce rapport additionnait
`rect.top + scrollHeight` : le chiffre de recouvrement était surévalué de ~11 %. Il a été refait par
mesure du rectangle du dernier enfant de l'en-tête — pixels écran de bout en bout, rien à convertir.

---

## 1. Les trois mesures chiffrées demandées — réponse directe

### 1.1 Hauteur de l'en-tête du panier, et pixels cachés

| État | Gabarit | Boîte de l'en-tête (px écran) | Contenu réel (px CSS) | Pixels non visibles | Plafond CSS | `overflow-y` |
|---|---|---|---|---|---|---|
| Panier VIDE (chargement) | 1366×768 | **331** | 366 | **0** | `none` | `visible` |
| Panier VIDE (chargement) | 1024×600 | **108** | 366 | **247 px CSS masqués** | `120px` (20vh) | `auto` |
| **Panier À 2 ARTICLES** | 1366×768 | **187** | **438** | **207 px écran qui DÉBORDENT** | `none` | `visible` |

Trois régimes différents, trois défauts différents :

* **1366×768 panier vide — sain.** L'en-tête tient dans sa boîte (331 px écran pour 366 px CSS de contenu, soit 366 × 0,901 = 330). Rien n'est caché.
* **1024×600 — l'en-tête est un hublot de 108 px.** Plafonné à 20 vh, `overflow-y: auto`, il masque **247 px CSS** de son propre contenu. Voir §2.2.
* **1366×768 avec 2 articles — l'en-tête déborde de 207 px écran par-dessus le reste.** Voir §2.5. C'est le défaut le plus grave de la vague.

### 1.2 Haut de la grille des catégories vs bas de la fenêtre

Mesuré au chargement, `window.scrollY === 0`, aucun geste :

| Gabarit | Haut de la grille (px écran) | Bas de la fenêtre | **Écart** | Tuiles entièrement visibles | Tuiles partiellement visibles |
|---|---|---|---|---|---|
| **1366×768** | **792** | 768 | **−24 px** (la grille commence 24 px SOUS le bord) | **0 / 10** | **0 / 10** |
| **1024×600** | **910** | 600 | **−310 px** | **0 / 10** | **0 / 10** |

**Aux deux gabarits, la grille des catégories est intégralement hors écran au chargement.**
Le caissier ne voit AUCUN produit avant d'avoir défilé. Il n'en voit pas un morceau : zéro tuile,
même partiellement. Les 10 catégories sont pourtant bien montées dans le DOM
(Sandwichs · Galette · Burgers · Tacos · Bols · Frites · Desserts · Boissons · Menu enfant · `E2E_PLAYWRIGHT_STUDIO_CATEGORY`).

La position dépend directement du volume de commandes en attente empilé au-dessus :

| Condition | Haut de la grille | Écart au bas de fenêtre |
|---|---|---|
| Panneaux peuplés (comptoir 2, web 77) | 792 | −24 px |
| Panneaux peuplés, bandeau « Article indisponible » retombé | 707 | +62 px |
| **Les 4 panneaux VIDES** (§2.4bis) | **576** | **+192 px** |

Autrement dit : **plus le service est chargé, plus les produits s'éloignent.** À l'heure de pointe —
le moment où le caissier a le plus besoin de la grille — elle descend de 216 px et disparaît.

### 1.3 Le corps du panier reste-t-il au-dessus de son plancher de 154 px ?

**Oui, à tous les états mesurés — mais la question ne mesure plus le bon symptôme.**

| État | Gabarit | Corps (px CSS) | Plancher CSS résolu | Au-dessus ? | Corps en px écran |
|---|---|---|---|---|---|
| Panier vide | 1366×768 | 199 | `153.6px` (20 vh) | **oui**, +45 | 179 |
| Panier vide | 1024×600 | 279 | `120px` (20 vh) | **oui**, +159 | 251 |
| **2 articles** | 1366×768 | **154** | `153.6px` | **oui, à 0,4 px près** | **138** |

Le plancher tient — c'est précisément ce qui casse le reste. `.pos-v5-cart__body` a `min-height: 20vh`
et `.pos-v5-cart__foot` a `flex: 0 0 auto` : les deux sont incompressibles. L'en-tête est le seul
`flex: 0 1 auto` du panneau, donc il absorbe **100 %** du déficit. Avec 2 articles, il lui faut 438 px CSS
et il n'en reçoit que 207 : il est comprimé de 231 px CSS. Et comme la règle `@media (min-height: 760px)`
lui donne `overflow: visible`, **ces 231 px ne sont pas rognés — ils sont peints par-dessus le panier.**

Le plancher a été respecté à la lettre. Le résultat est illisible.

---

## 2. Les neuf états, un par un

### État 1 — `/admin/pos` au chargement, 1366×768
* **Fichier** `01-pos-chargement-1366x768.png` (+ `.dom.html`, `.console.json`, `.network.json`, `.mesures.json`)
* **Attendu** l'écran de caisse prêt à prendre une commande.
* **Observé** panneau panier propre et lisible. En-tête 331 px, corps 179 px, pied 122 px, marge de 78 px sous le pied. Le champ « Nom du client (imprimé sur le ticket cuisine) » est à y = 298–334, **visible et cliquable sans aucun geste** (`elementFromPoint` sur son centre renvoie bien `pos-customer-name`). Aucun libellé brut. Aucun débordement horizontal.
* **Mais** : la moitié basse de l'écran est occupée par les 4 panneaux de suivi, et **aucune tuile produit n'est visible** (§1.2). Un bandeau rouge « Article indisponible : Coca-Cola 33cl » occupe la première ligne utile.
* **Mesures** en-tête 331 px écran / 366 px CSS / 0 caché · corps 199 CSS ≥ 154 · pied 122 px, bas à 690 < 768.

### État 2 — `/admin/pos` à 1024×600, le champ « Nom du client »
* **Fichiers** `02-pos-chargement-1024x600.png`, `02b-pos-1024x600-apres-defilement-vers-champ-nom.png`, `02-champ-nom-client.json`
* **Attendu (mandat propriétaire)** le champ visible SANS geste.
* **Observé — MANDAT NON TENU.** Preuve en trois mesures indépendantes, au chargement, `scrollY = 0` :
  * rectangle du champ : haut **159**, bas **195** ; boîte visible de l'en-tête : haut 59, bas **167** → **28 px des 36 px du champ sont hors de la zone non rognée** ;
  * `elementFromPoint` sur son centre renvoie **un `div`**, pas le champ : il n'est **pas cliquable** ;
  * `defilement_entete = 154` : l'en-tête est déjà défilé de 154 px CSS **sans qu'aucun geste ait été fait**, et le champ reste malgré tout coupé.
* **Ce qui est perdu avec lui** l'en-tête est plafonné à 120 px CSS pour 366 px de contenu : **247 px CSS masqués**. Le titre « TICKET CAISSE / Commande en cours », le sélecteur client, « Mettre en attente » et « En attente » sont tous hors du hublot. Sur la capture on ne lit que « SÉLECTIONNER LE TYPE DE COMMANDE », les deux boutons, l'étiquette du nom — et le haut coupé des deux champs de saisie.
* **Après défilement volontaire** (`02b`) le champ devient cliquable (`test_au_pixel: true`). Il existe et il fonctionne : il est simplement **indécouvrable**, exactement le défaut que le correctif du 2026-08-05 avait traité.

### État 3 — la grille des catégories
* **Fichiers** `03-grille-categories-1366x768.png`, `03-grille-categories-1024x600.png`, `03-grille-categories.json`
* **Attendu** les catégories atteignables sans défiler (direction « category-first » du 2026-06-23).
* **Observé** 0 tuile visible sur 10, aux DEUX gabarits. Chiffres complets en §1.2.
* **Note** la 10ᵉ catégorie s'appelle **`E2E_PLAYWRIGHT_STUDIO_CATEGORY`** — donnée de test visible sur l'écran de production.

### État 4 — les 4 panneaux de suivi (données réelles)
* **Fichiers** `04-panneaux-suivi-1366x768.png`, `04-panneaux-suivi.json`
* **Observé** les 4 panneaux sont montés et dans la fenêtre. Libellés RÉELS relevés dans le DOM :

| Demandé | Libellé réel | `data-testid` | Contenu | Horodatage |
|---|---|---|---|---|
| « Prêts » | **🛎️ Prêt à livrer (0)** | `pos-shortcuts-ready` | vide | « Mis à jour à l'instant » |
| « À encaisser borne » | **💰 À encaisser — comptoir (2)** | `pos-shortcuts-cash` | 2 lignes (N°A0010 11,10 € · N°A0011 8,30 €) | idem |
| « Commandes web » | **🌐 Commandes web · 77** | `pos-shortcuts-web` | 4 lignes affichées sur **77** + « Voir les 77 commandes web » | idem |
| « Web payées » | **💳 Web payées · 0** | `pos-shortcuts-web-paid` | vide | idem |

* **Écart de vocabulaire à signaler** : le panneau n'est plus intitulé « À encaisser **borne** » mais « À encaisser — **comptoir** ». Le `data-testid` reste `cash`. À trancher : le libellé ou la spécification.
* **77 commandes web en attente** dans cet environnement. Ce n'est pas un défaut de rendu, mais c'est ce qui pousse la grille produits hors écran (§1.2).

### État 4bis — les 4 états VIDES
* **Fichiers** `04b-panneaux-suivi-etats-vides.png`, `04b-etats-vides.json`
* **Méthode** deux panneaux étaient peuplés. Plutôt que de supprimer une ligne en base, les réponses de `admin/pos/counter-collect/pending`, `admin/pos/web-orders/pending` et `admin/pos/web-orders/paid` ont été **interceptées côté navigateur** et rendues vides, puis l'interception retirée. **Lentille sur le transport, zéro écriture.**
* **Observé** les 4 états vides simultanés, cohérents et bien rédigés :
  * « Aucune commande prête à livrer pour le moment. »
  * « Aucune commande borne à encaisser pour le moment. » *(le mot « borne » survit ici, alors que le titre dit « comptoir » — incohérence interne au même panneau)*
  * « Aucune commande web en attente. »
  * « Aucune commande web payée en cuisine. »
  * chacun avec « Mis à jour à l'instant » : la distinction « calme » / « sonde morte » est bien rendue.
* **Effet de bord mesuré** panneaux vides ⇒ grille des catégories remonte de 792 à **576 px** (§1.2).
* **Bruit observé** un bandeau « Trop de requêtes — patientez 15s avant de réessayer. » (HTTP 429) apparaît sur cette capture et sur `06`. Il provient de la cadence du banc d'essai, mais il prouve que **le POS montre le 429 au caissier en toutes lettres**.

### État 5 — un panier composé (2 produits avec options) — **LE DÉFAUT MAJEUR**
* **Fichiers** `05-panier-compose-1366x768.png` (écran entier), **`05b-panneau-panier-plein.png` (gros plan du panier)**, `05a-assistant-produit-ouvert.png`, `05-panier-compose.json`
* **Ce qui a été composé** deux produits, chacun via l'assistant produit (zone gelée, pilotée uniquement par des clics réels) :
  * **Cayenne** — viande *Poulet mariné*, sauce *Algérienne*, crudités par défaut — 7,40 €
  * **Galette Normale** — viande *Poulet mariné*, sauce *Mayonnaise* — 6,50 €
  * Total 13,90 €. L'assistant a correctement REFUSÉ l'ajout tant que le quota viande affichait « 0/1 incluse » : la garde produit est saine.
* **Observé — le panneau panier devient illisible.** Mesures en **pixels écran** :

| Grandeur | Valeur |
|---|---|
| Boîte allouée à l'en-tête | **187 px** |
| Hauteur réelle de son contenu | **394 px** |
| **Débordement de l'en-tête** | **207 px** |
| Bas du contenu de l'en-tête | y = **453** |
| Corps du panier | y = 246 → 384 (138 px) |
| **Recouvrement sur le corps** | **138 px — soit 100 % du corps** |
| Pied du panier | y = 384 → 690 (306 px) |
| **Recouvrement sur le pied** | **69 px** |
| `overflow` / `max-height` de l'en-tête | `visible / visible` · `none` |

* **Ce que ça donne à l'écran** (`05b`) : « Cayenne 7,40 € » écrit par-dessus « SÉLECTIONNER LE TYPE DE COMMANDE » ; « Poulet mariné · STO » par-dessus le même texte ; le sélecteur « À emporter / Livraison » par-dessus le compteur de quantité `− 1 +` et la corbeille ; « Galette Normale 6,50 € » par-dessus « …R LE TICKET CUISINE) » ; les champs Nom et Téléphone par-dessus la seconde ligne du panier ; « REMISE » et « Programmer » par-dessus « Sous-total 13,90 € ». **Les deux lignes de la vente sont entièrement ensevelies.**
* **Et le champ « Nom du client » est de nouveau inatteignable** — autrement qu'à l'état 2. Il est à y = 374–410, `elementFromPoint` sur son centre renvoie **`footer`** : le pied du panier le recouvre. Un caissier ne peut pas saisir le nom d'un client sur une commande à 2 articles.
* **Cause mécanique, lisible dans le code** (`resources/css/pos-v5.css:842-880`) : le correctif du 2026-08-22 a levé le plafond de l'en-tête (`max-height: none; overflow: visible`) au-dessus de 760 px de hauteur, en s'appuyant sur une mesure — « le pied fait 122 px ». **Cette mesure a été prise sur un panier VIDE.** Avec 2 articles le pied fait **306 px** (il gagne remise, sous-total, total, bouton d'encaissement, commande téléphone, annuler). Le calcul de non-régression n'a jamais été rejoué à panier plein. Troisième correctif d'affilée sur cette zone, troisième fois que le champ « Nom du client » redevient inutilisable.
* Le corps du panier doit défiler (`corps_doit_defiler: true`, 221 px CSS de contenu pour 154 px visibles) — mais on ne peut pas le lire de toute façon.

### État 6 — `/admin/pos-v4` au chargement
* **Fichiers** `06-pos-v4-1366x768.png`, `06-pos-v4.json`
* **Observé** HTTP **200**, titre `POS V4 — Le Cayenne`, URL conservée. Le wizard gelé est bien chargé (`/js/pos-wizard.js?v=9-…`). Le rendu **visuel** est identique à `/admin/pos` : même panier, mêmes 4 panneaux, mêmes mesures au pixel (en-tête 331 / corps 199 / pied 122). Pas de débordement horizontal. Tous les défauts des états 1 à 5 s'y appliquent tels quels.
* **MAIS les deux entrées ne sont PAS équivalentes sous le capot.** La console de cet état — et **de cet état seul** — porte **8 `pageerror`** :
  `No match for {"name":"frontend.menu","query":{"s":""},"params":{}}`, plus 1 `error` `createRouterError`, plus 12 avertissements Vue (`<RouterLink to={name: frontend…}>` et `<FrontendNavbarComponent>`) et 1 `[Vue Router warn]: uncaught error during route navigation`. Soit **21 entrées de console** sur une seule surface. `/admin/pos` en produit **zéro**.
* **Lecture** la page Blade autonome `admin-pos-v4.blade.php` monte un `FrontendNavbarComponent` qui pointe vers une route `frontend.menu` **absente du routeur**. L'erreur est levée à chaque montage et à chaque mise à jour du composant. Ce n'est pas un artefact de banc d'essai : c'est un défaut propre à l'entrée POS dédiée. Vérification : `06-pos-v4-1366x768.console.json`.

### État 7 — `/admin/pos/floorplan`
* **Fichiers** `07-floorplan-1366x768.png`, `07-floorplan.json`
* **Observé** HTTP 200, la page rend. Trois anomalies visibles à l'œil nu :
  * **« 1 tables »** — pluriel non accordé au singulier.
  * **« 0 seats »** — anglais non traduit sur une V1 FR-only (ADR-007).
  * la seule table du plan s'appelle **`ABUSE-T1`** : résidu d'un spec d'abus antérieur, exposé sur l'écran de service.
* `CaisseSecondaryNav` n'est **pas** monté ici : seul un bouton « ← Retour ». Depuis le plan de salle, le caissier n'a pas accès à Encaissement / Suivi / Historique / Écran client / La roue.

### État 8 — `CaisseSecondaryNav`
* **Fichiers** `08-caisse-secondary-nav-1366x768.png`, `08-caisse-secondary-nav.json`
* **Surface** `/admin/encaissement` — la barre n'est montée que sur `EncaissementComponent.vue` et `HistoriqueListComponent.vue`, jamais sur `/admin/pos`.
* **Observé** barre saine : haut 146, bas 179, 33 px de haut, entièrement dans la fenêtre, `aria-label = "Encaissement — navigation"`. Les 6 liens répondent :

| `data-testid` | Libellé | `href` | Cible | État |
|---|---|---|---|---|
| `csn-encaissement` | 💶 Encaissement | `/admin/encaissement` | — | **actif**, `aria-current="page"` |
| `csn-suivi` | 📋 Suivi commandes | `/admin/pos-orders-tracker` | — | — |
| `csn-historique` | 🗂️ Historique | `/admin/historique` | — | — |
| `csn-oss` | 🖥️ Écran client | `/admin/order-status-screen` | `_blank` | — |
| `csn-roue` | 🎡 La roue | `/admin/roue` | `_blank` | — |
| `csn-back-caisse` | ← Retour caisse | `/admin/pos` | — | — |

* **Aucun défaut relevé sur cet état.** `aria-current` correct, cible `_blank` correcte sur les deux écrans à ouvrir hors caisse.

---

## 3. Console et réseau — relevé agrégé sur les 9 états

| Occurrences | Niveau | Message | Où |
|---|---|---|---|
| 8 + 1 | pageerror / error | `No match for {"name":"frontend.menu",…}` — **route Vue inexistante**, levée depuis `FrontendNavbarComponent` | **`/admin/pos-v4` UNIQUEMENT** |
| 6 + 5 + 1 | warning | `[Vue warn]` sur `<RouterLink to={name: frontend…}>` et `<FrontendNavbarComponent>`, plus `[Vue Router warn]`, conséquences du précédent | **`/admin/pos-v4` UNIQUEMENT** |
| 18 | error | `ERR_CONNECTION_REFUSED` — sonde matérielle `127.0.0.1:9101/health` (TPE simulé absent, dégradation attendue, CSP en report-only) | toutes surfaces |
| 13 | error `404` | **`/storage/1/english.png`** — le drapeau du sélecteur de langue, manquant sur **chaque** surface de caisse (1 à 4 requêtes par état) | toutes surfaces |
| 8 | error `404` | **`/storage/7/frites.png`** et **`/storage/8/coca.png`** — images produit manquantes, redemandées à répétition par l'assistant | états 5 et 5a |
| 2 | error | `429 Too Many Requests` (cadence du banc d'essai) | états 4bis et 6 |

* **`No match for frontend.menu` est un vrai défaut, et il est LOCALISÉ** : `/admin/pos-v4` en lève 9 (+12 avertissements), `/admin/pos` en lève zéro. Ce n'est pas un artefact de test.
* Aucun libellé brut (`label.x`, `message.y`, `0undefined`) sur aucun état : `libelles_bruts: []` partout.
* Aucun débordement horizontal sur aucun état : `debordement_horizontal: false` partout.
* Laravel Debugbar est actif (`APP_DEBUG=true`) : il occupe ~25 px au bas de chaque capture. Environnement de développement — à ne pas confondre avec un défaut d'interface.

---

## 4. Synthèse pour la phase de correction — par gravité

| # | Défaut | Preuve | Gabarit |
|---|---|---|---|
| **B-1** | **Le panneau panier devient illisible dès 2 articles** : l'en-tête déborde de 207 px, recouvre 100 % du corps et 69 px du pied | `05b-panneau-panier-plein.png`, `05-panier-compose.json` → `chevauchement` | 1366×768 |
| **B-2** | **Le champ « Nom du client » est inatteignable dans deux situations distinctes** : à 1024×600 il est rogné par l'en-tête (`elementFromPoint` → `div`) ; à 1366×768 avec 2 articles il est recouvert par le pied (`elementFromPoint` → `footer`) | `02-champ-nom-client.json`, `05-panier-compose.json` | les deux |
| **B-3** | **La grille des catégories est intégralement hors écran au chargement** : −24 px à 1366×768, −310 px à 1024×600, 0 tuile visible sur 10 | `03-grille-categories.json` | les deux |
| **B-4** | **`/admin/pos-v4` lève 9 erreurs Vue non rattrapées** (`No match for frontend.menu`) + 12 avertissements, que `/admin/pos` ne lève PAS — les deux entrées ne sont pas équivalentes | `06-pos-v4-1366x768.console.json` | 1366×768 |
| **B-5** | À 1024×600, l'en-tête masque 247 px CSS : titre, sélecteur client, « Mettre en attente », « En attente » sont hors du hublot de 108 px | `02-pos-chargement-1024x600.mesures.json` | 1024×600 |
| **B-6** | Plan de salle : « 1 tables », « 0 seats » (anglais sur V1 FR-only), table `ABUSE-T1` résiduelle | `07-floorplan-1366x768.png` | 1366×768 |
| **B-7** | Incohérence de libellé dans le même panneau : titre « À encaisser — **comptoir** », état vide « Aucune commande **borne** à encaisser » ; la spécification dit « À encaisser borne » | `04-panneaux-suivi.json`, `04b-etats-vides.json` | — |
| **B-8** | Catégorie `E2E_PLAYWRIGHT_STUDIO_CATEGORY` visible sur l'écran de caisse | `03-grille-categories.json` | — |
| **B-9** | 404 en boucle sur `/storage/8/coca.png` et `/storage/7/frites.png` depuis l'assistant produit (8 requêtes) | `05a-assistant-produit-ouvert.network.json` | — |
| **B-10** | 404 sur `/storage/1/english.png` — drapeau du sélecteur de langue absent sur **toutes** les surfaces de caisse (13 requêtes) | tous les `*.network.json` | — |

**B-1, B-2 et B-3 partagent une seule cause** : dans `.pos-v5-cart`, l'en-tête est le seul élément
compressible face à un corps à plancher garanti et un pied incompressible, et le pied a été mesuré
**à panier vide** (122 px) alors qu'il en fait **306** à panier plein. Toute correction devra être
vérifiée à panier PLEIN, aux deux gabarits, et pas seulement au chargement.

**Note pour la phase de correction** : `resources/css/pos-v5.css` porte trois correctifs successifs
sur cette même zone (2026-08-19, RED-team 2026-08-19, 2026-08-22), chacun juste au regard de sa
propre mesure, chacun annulant le précédent. Un quatrième correctif calibré sur un seul état
reproduira le cycle. Le banc de mesure est désormais dans `audit-supervisor-waveB.spec.js` :
il rend `chevauchement`, `champ_nom.test_au_pixel` et `grille_categories.px_disponibles_sous_le_haut`
à chaque exécution, aux deux gabarits, panier vide ET panier plein.

---

## 5. Hygiène de la vague

* **Fichiers touchés** : le spec, ses artefacts, ce rapport. Rien d'autre.
* **Zones gelées** : lues (`pos-wizard.js` pour identifier le rendu S25-SinglePage et la garde `singlePageCanAddToCart`), jamais écrites.
* **Données** : aucune commande créée, aucun préfixe `AUDB-` utilisé, aucune ligne supprimée. Les états vides de l'état 4bis ont été obtenus par interception réseau côté navigateur, retirée en `finally`.
* **Serveur** : ni relancé ni arrêté.
