# CONTRE-AUDIT ADVERSE — GOAL CAISSE VISION 2026-08-24

Lecture seule. Base `43b120c7d`, HEAD `35c53efca`. Tout constat ci-dessous a été
rejoué (`php`, `node`, `npx vitest`, requêtes SQL en lecture) — les hypothèses non
vérifiables ont été supprimées, pas atténuées.

---

## [P1] tests/Feature/Pos/TrackerCompositionPayloadTest.php:180-222 — le test « budget d'octets » ne mesure pas ce que le commit affirme, et le budget est DÉJÀ dépassé en base

**reproduction** : `php /tmp/perf_probe.php` (100 dernières commandes + les 20
commandes les plus composées de la base, `SimpleOrderResource::resolve()` avec et
sans les clés `options/extras/addons`).

**preuve** :
```
PIRE CAS REEL (base 3617 commandes) :
  order #5368  lignes=5  delta_payload=+680 o
  order #5564  lignes=4  delta_payload=+610 o
  order #5563  lignes=4  delta_payload=+610 o
=> PIRE DELTA MESURE : +680 o — budget annonce 600 o

Fixture du test « budget d'octets » : 303 o pour UNE ligne (seuil du test : 600 o)
  => le test tolère 2,0× la fixture qu'il mesure.
```
Le commit écrit « La commande la plus composée reste sous 600 o (budget vérifié par
test) ». Le test crée **une seule ligne** et mesure **cette ligne** : il ne peut pas
prouver l'énoncé au niveau *commande*. Trois commandes déjà en base le violent.

**correctif** : mesurer `json_encode` de l'enrichissement de TOUTES les lignes de la
commande, avec une fixture à ≥4 lignes composées, et fixer le seuil sur la pire
commande réelle (≥ 800 o) — ou reformuler l'énoncé en « par ligne ».

---

## [P1] tests/Feature/Pos/TrackerCompositionPayloadTest.php — 6 tests sur 8 restent VERTS si on supprime intégralement la fonctionnalité

**reproduction** : retirer `+ CompositionCompactor::forLine($line)` de
`SimpleOrderResource.php:255` et relire chaque assertion.

**preuve** (analyse assertion par assertion) :
| test | survit à la suppression ? |
|---|---|
| `la_composition_de_l_instantane_nf525_arrive_au_suivi` | non — vraie assertion |
| `la_composition_de_l_ancienne_forme_arrive_aussi` | non — vraie assertion |
| `une_ligne_heritee_sans_aucun_nom_est_ecartee` | **OUI** (`assertArrayNotHasKey`) |
| `une_commande_sans_personnalisation_n_ajoute_aucune_cle` | **OUI** (3 × `assertArrayNotHasKey` + `assertSame` des 4 clés d'origine) |
| `la_commande_la_plus_composee_tient_le_budget_d_octets` | **OUI** — `array_intersect_key` renvoie `[]`, `strlen('[]')` = 2 ≤ 600 |
| `la_composition_ne_coute_aucune_requete_supplementaire` | **OUI** — 0 requête dans les deux cas |
| `sans_eager_load_le_contrat_reste_un_tableau_vide` | **OUI** — `[]` dans les deux cas |
| `une_entree_sans_nom_ne_produit_pas_de_puce_fantome` | **OUI** (2 × `assertArrayNotHasKey`) |

Seuls 2 tests sur 8 épinglent réellement le code livré. Le fichier compte 298 lignes
et une éponge de commentaires ; sa capacité de détection est de 25 %.

**correctif** : ajouter au test « budget » une assertion positive
(`assertGreaterThan(0, $enrichissement)`), et au test « N+1 » une assertion que
`options` est bien présent — deux lignes qui rendent 2 tests mortels.

---

## [P2] app/Support/Order/CompositionCompactor.php:62 — le « port fidèle » a la préférence de clés INVERSÉE sur les extras

**reproduction** : `php /tmp/cc_probe.php` vs `node /tmp/js_probe.mjs`, cas F.

**preuve** :
```
PHP  F. extras name ET extra_name divergents => {"extras":[{"name":"Tomate"}]}
JS   F. extras name ET extra_name divergents => [{"name":"Salade",...}]
```
PHP : `['extra_name', 'name']`. Canonique JS `posReceiptBuilder.js:219` :
`String(e.name || e.extra_name || '')`. Deux noms différents pour la MÊME entrée.
Le dépôt est incohérent sur ce point (`kdsSymbolic.js:293` = extra_name d'abord,
`kdsCustomization.js:212` = name d'abord). **Non atteignable aujourd'hui** :
`SELECT COUNT(*) … composition_snapshot LIKE '%"extra_name"%' AND LIKE '%"name"%'`
= **0**. Défaut latent, mais la phrase « port fidèle du normaliseur canonique » du
commit et du docblock est fausse.

**correctif** : aligner l'ordre sur le canonique (`['name', 'extra_name']`) ou
trancher l'ordre au niveau dépôt et corriger les 4 sites.

---

## [P2] app/Support/Order/CompositionCompactor.php:105-112 — `??` là où le JS fait `||` : une chaîne VIDE ne déclenche pas le repli

**reproduction** : cas A des deux sondes.

**preuve** :
```
entrée : {"variation_id":5,"attribute_name":"Sauce","variation_name":"","name":"Algerienne"}
JS  (ticket) => [{"label":"Sauce","name":"Algerienne","quantity":1}]
PHP (suivi)  => []            ← la ligne DISPARAÎT du suivi
```
Idem cas K (`variation_name: 0`) : PHP affiche `"0"`, le JS écarte l'entrée.
**Non atteignable aujourd'hui** : 0 snapshot en base porte `variation_name` ou
`attribute_name` vide/null. Latent — mais c'est exactement la classe de divergence
que le docblock promet d'avoir éliminée.

**correctif** : un `premierNonVide(...$candidats)` privé, appliqué aux 4 replis.

---

## [P2] app/Http/Resources/SimpleOrderResource.php:255 — l'enrichissement n'est PAS réservé au suivi

**reproduction** : `grep -rn "SimpleOrderResource" app routes` + `php /tmp/report_probe.php`.

**preuve** : 5 appelants — `SalesReportController:53`, `OnlineOrderController:49`,
`OrderHistoryController:61`, `PosOrderController:257` et `:372`.
`OrderService::list()` charge `orderItems.orderItem` dans **les deux** jeux de
relations (lean et non-lean, `OrderService.php:149-167`) → `relationLoaded` est vrai
partout, donc la composition part aussi vers le rapport de ventes et l'historique,
qui ne l'affichent pas. Mesure sur la base entière (`paginate=0` → `get('*')`) :
```
3 260 commandes : AVEC = 3 088 849 o | SANS = 3 028 617 o | delta = +60 232 o (+1,99 %)
```
Impact modeste, mais c'est un octet payé sur un endpoint non borné pour zéro usage.
Aucune donnée sensible ajoutée (ni prix, ni PII) — ce n'est pas une fuite.

**correctif** : conditionner l'appel au compacteur à `$request->boolean('lean')`
(le drapeau que le suivi envoie déjà) — une ligne.

---

## [P2] tests/js/posTrackerCompositionVisible.spec.js:70-82 — la garantie « aucun libellé brut » est prouvée contre un dictionnaire écrit à la main

**reproduction** : lire le harnais ; le confronter à `node` sur `fr.json` / `en.json`.

**preuve** : `$t: (key) => ({ 'label.deleted_item': …, 'pos.tracker.source_phone': …, … }[key] ?? key)`
— 10 clés recopiées dans le spec. Si une clé disparaissait de `fr.json`, les 17 tests
resteraient verts. La garantie n° 4 annoncée en tête du fichier (« aucun libellé brut,
jamais ») n'est donc pas gardée.
Vérification manuelle faite à la place : les 13 clés traversées existent bien dans
`fr.json`. En revanche `en.json` manque `label.deleted_item`,
`pos.tracker.source_phone`, `source_platform`, `source_delivery` — sans effet en V1
(`i18n.js:89` force `fr` sur `/admin/*`), mais le filet `fallbackLocale: ['fr','en']`
ne rattraperait rien si `fr` régressait.

**correctif** : `import fr from '../../resources/js/languages/fr.json'` et résoudre
`$t` dessus.

---

## [P3] resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:2306 — le panneau est FIGÉ à l'ouverture

**reproduction** : `ouvrirContenu(o)` stocke `contenuDialog = { open: true, order: o }` ;
`fetchOrders()` fait `this.orders = Array.isArray(data) ? data : []` (ligne **1723**)
— tableau intégralement remplacé. La référence détachée n'est jamais rafraîchie.

**preuve** : code, lignes 2306 et 1723. Une commande annulée / encaissée pendant que
le caissier lit le panneau continue de s'afficher telle quelle (contenu et total).
Portée limitée : le panneau ne porte aucune action mutante.

**correctif** : conserver `contenuDialog.orderId` et recalculer l'ordre depuis
`this.orders` dans un `computed`.

---

## [P3] tests/e2e/wave-q1-suivi-commandes.spec.js:181 — assertion objectivement plus permissive

**preuve** : `toContainText('Sandwich Cayenne')` → `toContainText('Cayenne')`. Le nom
réel est bien « Cayenne » (`Item::find(22)->name` = `Cayenne`, vérifié) donc le
réalignement est justifié — mais « Cayenne » est une sous-chaîne : un renommage en
« Cayenne XL » ou « Sauce Cayenne » passerait encore. `Item::find(26)->name` =
`Tacos M`, donc `toContainText('Tacos')` a la même faiblesse (préexistante).

**correctif** : `toHaveText(/^Cayenne$/)` sur `.pos-tracker-card-name`.

---

## [P3] app/Support/Order/CompositionCompactor.php:181 — quantité fractionnaire tronquée

**preuve** : cas C — `quantity: 2.5` → PHP `2`, JS `2.5`. Aucun écrivain connu ne
produit de fraction ; divergence théorique, consignée pour complétude.

> **Note de concurrence** : pendant ce contre-audit, `CompositionCompactor.php` a été
> modifié sur disque (docbloc `quantity()` étendu, lignes 169-180) pour documenter
> exactement cette divergence. Le constat est donc désormais consigné dans le code ;
> le comportement, lui, est inchangé. Les deux constats [P2] du même fichier
> (`['extra_name','name']` ligne 62, `??` lignes 109-114) sont **toujours valides**
> sur la version relue à la fin de l'audit.

---

# CE QUI TIENT

J'ai essayé de casser ceci et je n'y suis pas arrivé :

- **Forme OBJET au lieu de tableau.** Le JS fait `Object.values(raw)` ; j'ai supposé
  que le PHP planterait ou ignorerait. Faux : `pick()` fait `array_values()` et le
  repli `foreach` itère les valeurs. Cas G exécuté :
  `{"7":{"variation_name":"Sauce","name":"Blanche"}}` → PHP et JS rendent tous deux
  `label=Sauce, value=Blanche`. **Identiques.**
- **Entrées non-objet, quantités 0 / −3 / `"2"` / vides / non numériques.**
  Cas D, E, I, N exécutés des deux côtés : sorties **identiques** (0 et −3 retombent
  tous deux sur 1 ; les scalaires dans `lines` sont écartés).
- **Forme héritée imbriquée `{id, variation:{…}}`** : les deux rendent `[]`.
- **Coût SQL.** Mesuré : **5 requêtes / 81 ms** pour 100 commandes (annoncé 6 / 64 ms).
  Aucune requête supplémentaire imputable au compacteur — les colonnes sont déjà
  rapatriées. Le payload mesuré (102 969 o, +50,3 o/commande, pire du lot +390 o)
  reproduit les chiffres annoncés (105 741 o, +52,8 o, 394 o) à la dérive de données près.
- **Isolation de branche.** Aucun `withoutGlobalScope`, aucune requête, aucune
  relation nouvelle : lecture de colonnes déjà chargées. Rien à contourner.
- **Fuite de données.** Aucun prix, aucun identifiant, aucune PII nouvelle dans le
  payload compact. `instruction` était déjà expédiée avant ce GOAL.
- **« : » orphelin / `undefined` / `[object Object]`.** Le gabarit garde
  `v-if="o.label"` et le PHP garantit `value` non vide (entrée rejetée sinon).
  Toutes les valeurs sont des chaînes côté serveur. Les 13 clés `$t()` traversées
  existent dans `fr.json` (vérifié en node).
- **Panneau « partiel présenté comme complet ».** Quand `order_items` vaut `[]`,
  `aDuContenuAVoir()` est faux → le bouton n'apparaît pas, et le panneau porte de
  toute façon l'état explicite « Le détail de cette commande n'a pas encore été
  chargé. » Recherche en base des lignes ayant de la composition mais rendues
  muettes par le compacteur : **2 sur ~3 900**, et toutes deux sont des tableaux
  d'IDs nus (`[43,311]`, `{}`) — rien à nommer. Le suivi ne ment pas.
- **5 couloirs.** Vérifié : `columns()` en déclare 5 (lignes 1330/1341/1350/1367/1376),
  rendus par un `v-for` sans `v-if`. `toHaveCount(5)` est un réalignement exact, pas
  un maquillage.
- **wave-s4, sous-titre.** `/borne.*paiement.*comptoir/` → `/paiement\s+au\s+comptoir/ && /borne/`.
  La nouvelle version exige la **locution exacte** au lieu de trois jetons séparés par
  `.*` : elle est **plus stricte**, pas plus faible.
- **KDS `kdsExtraDisplayName`.** 226 `order_items` portent des `item_extras` hérités ;
  aucun ne produit le repli fantôme « Supplément » (l'instantané prime et porte
  toujours `extra_name`). Le repli n'efface aucune entrée facturée. La garde de
  non-retour relit bien le source réel du composant.
- **Zones gelées §7.** `git diff --stat 43b120c7d..HEAD` sur les 15 fichiers : **vide**.
- **Suites JS.** `npx vitest run` sur les 3 specs : **32 verts**.
