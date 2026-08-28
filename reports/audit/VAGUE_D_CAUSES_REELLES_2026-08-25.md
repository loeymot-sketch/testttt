# Vague D — rejouée sur le bon serveur : ce qu'elle échoue vraiment

- **Date** : 2026-08-25, vague W7 du GOAL `CONSOLIDATION_V1_PRODUCTION_20260825`
- **Commande** : `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 E2E_BACKEND_AVAILABLE=1 FOODKING_E2E_DEDICATED_DB=1 npx playwright test …wave-D…`
- **Résultat** : **échec** (2 tentatives), journal complet dans
  `reports/test-e2e/CONSOLIDATION_V1_PRODUCTION_20260825/round-1/W7-vague-D-bon-serveur.log`

---

## 1. Mon hypothèse était FAUSSE — et c'est réglé

J'avais posé que le rouge de la vague D pouvait venir du serveur `:8000`, qui sert un autre
worktree dont `KitchenDisplaySystemComponent.vue` diffère de 29 lignes.

**Vérification faite : non.**
- `data-kds-order-card="kiosk"` existe **à l'identique** dans les deux arbres (ligne 969) ;
- le diff de 29 lignes ne porte que sur `kdsExtraDisplayName(extra)` — l'affichage des extras,
  sans rapport avec le rangement en lane.

La vague D échoue **aussi** sur le bon serveur. L'hypothèse est close.

---

## 2. Ce qu'elle échoue, précisément

### 2.1 🔴 DÉFAUT DE SPEC — 422 sur les transitions KDS (corrigé)

```
state07: KDS→PREPARING → 422 « Header X-Idempotency-Key requis pour cette opération. »
state09: KDS→PREPARED  → 422 « Header X-Idempotency-Key requis pour cette opération. »
```

`config/idempotency.php:105` liste `api/admin/kds-order/change-status/*` dans `required_routes`,
**délibérément** : un double bump enverrait deux notifications client. La spec, elle, n'avait
jamais été mise à jour. `kdsAdvanceStatus()` postait sans en-tête.

✅ **Corrigé** — clé unique par transition, avec le motif du dépôt.

### 2.2 🔴 LA MÊME FAUTE, SUR 18 SPECS

Ce n'était pas isolé. **26 specs** appellent `kds-order/change-status` ; **18 en manquent l'en-tête**
sur au moins un site d'appel. Toutes prennent un 422.

Mode de panne particulièrement coûteux : **l'échec ressemble à un défaut de synchro cuisine** et
envoie chercher dans le KDS un problème qui n'y est pas. Une part importante du « pourrissement
E2E » documenté dans les cycles précédents s'explique probablement ainsi.

✅ **Sentinelle** : `tests/js/e2eRoutesIdempotentesEnTete.spec.js` (4 tests, cliquet à 18, prouvée
mordante). Elle lit `config/idempotency.php` comme source unique — la prochaine route ajoutée sans
mise à jour des specs fera monter le compteur, au lieu de produire des rouges inexplicables des
mois plus tard.

### 2.3 ⚠️ UNE AFFIRMATION FAUSSE DANS LA SPEC ELLE-MÊME

```
state05: … Pusher unreachable in dev (port 6001 down)
```

**Faux.** Vérifié au même moment : `:6001` est **ouvert** et répond **HTTP 200**. Cette phrase est
une narration codée en dur dans la spec, pas une mesure. Elle explique un échec par une cause qui
n'existe pas — exactement le piège d'instrument documenté dans `docs/PLAYWRIGHT_MCP_OPS.md §7`.

### 2.4 L'ÉCHEC RÉSIDUEL — et tout ce que j'ai pu éliminer

```
Error: SYNC-1 source-isolation: KDS card should land under
       [data-kds-order-card="kiosk"] for kiosk-placed order 6921
```

La carte est **absente**, pas mal rangée : `post-reload KDS card present=false`.

J'ai éliminé, une par une, toutes les explications plausibles :

| Hypothèse | Vérification | Verdict |
|---|---|---|
| Statut hors des statuts visibles | `visibleStatuses()` = ACCEPT/PREPARING/PREPARED ; la commande était ACCEPT | ❌ écartée |
| Non libérée pour le board | `payment_status = 15` (PENDING_COUNTER) → `isReleasedForBoard()` = **true** | ❌ écartée |
| Mauvaise lane (nom de champ) | API expose `source_surface`, composant lit `source_surface` — **identiques** | ❌ écartée |
| Article sans station cuisine | résolveur a pris **#12 « Cheddar », `cuisine_chaude`** | ❌ écartée |
| Socket injoignable | `:6001` ouvert, HTTP 200 | ❌ écartée |
| Mauvaise branche | commande `branch_id=1` | ❌ écartée |
| Autre worktree | markup identique dans les deux | ❌ écartée |

⚠️ **Piège que j'ai failli rapporter comme cause** : les commandes portent `status=16` (CANCELED)
quand on les inspecte après coup. Ce n'est **pas** la cause : le nettoyage de fin de test **annule**
au lieu de supprimer (NF525 — on ne détruit pas une trace fiscale), via `OrderService::changeStatus`.
Les horodatages le confirment (créée 17:08:00, annulée 17:09:41).

**Conclusion** : toutes les conditions backend de visibilité KDS sont satisfaites, et la carte ne
rend pas — ni en temps réel (16 s mesurées), ni après rechargement. Le défaut est donc bien dans le
**chemin de rendu/hydratation** du KDS, ce qui **confirme** la conclusion du cycle précédent — mais
cette fois avec toutes les alternatives éliminées, et sur le bon serveur.

---

## 3. Ce qui reste à faire

1. **Corriger les 18 specs** privées d'en-tête (chacune a une forme d'appel propre — à vérifier
   une par une, pas en masse).
2. **Rejouer la vague D** une fois le 422 levé : les états 07 à 10 n'ont jamais pu s'exécuter, et
   l'échec SYNC-1 pourrait en masquer d'autres en aval.
3. **Instrumenter le rendu KDS** : la commande est en base, libérée, bien typée, et n'apparaît pas.
   La prochaine étape est de capturer ce que le composant **reçoit** (`visibleRows`) plutôt que ce
   qu'il affiche.
4. **Retirer l'affirmation fausse** sur le port 6001 de la spec — elle induit en erreur.

---

# ✅ APRÈS CORRECTIF — quatre états débloqués, et une révélation sur la « synchro »

Vague D rejouée le 2026-08-25 après ajout de l'en-tête d'idempotence
(`W8-vague-D-apres-correctif.log`) :

| État | AVANT | APRÈS |
|---|---|---|
| `state07` KDS→PREPARING | **422** en-tête manquant | **202** ✅ |
| `state08` OSS preparing | `pickedUp=false` — **12 114 ms** | `pickedUp=TRUE` — **13 ms** ✅ |
| `state09` KDS→PREPARED | **422** en-tête manquant | **202** ✅ |
| `state10` OSS prepared | `pickedUp=false` — **12 600 ms** | `pickedUp=TRUE` — **3 ms** ✅ |
| `state11` idempotence | OK | OK — rejeu dédoublonné, 1 seule ligne en base |

## Ce que ces chiffres disent vraiment

**La « synchro cuisine → écran de statut » n'a jamais été lente.** Elle mettait 12 secondes et
n'aboutissait pas — parce que **la transition qu'elle devait propager n'avait jamais eu lieu**.
Le 422 bloquait le bump ; l'OSS attendait un événement qui ne viendrait pas.

Une fois l'en-tête posé : **13 ms et 3 ms**. Trois ordres de grandeur.

C'est le mode de panne le plus coûteux d'un harnais : il désigne le mauvais coupable avec
insistance. Les cycles précédents ont cherché une lenteur de synchro qui n'existait pas.

## Ce qui reste, seul

```
Error: SYNC-1 source-isolation: KDS card should land under
       [data-kds-order-card="kiosk"] for kiosk-placed order 6925
```

**Un seul état échoue désormais** : la prise en charge INITIALE de la commande borne par le KDS
(`state05`/SYNC-1). Tout le reste de la chaîne — bump, propagation OSS, idempotence — est vert.

Le périmètre du défaut résiduel est donc bien plus étroit qu'il n'y paraissait : ce n'est pas
« la chaîne borne→KDS→OSS est cassée », c'est **« la carte n'apparaît pas au premier affichage »**,
avec les sept hypothèses déjà éliminées plus haut.

---

# W9 — Tentative d'instrumentation : ce que je n'ai PAS réussi à établir

## Ce que j'ai tenté

Écrire une sonde en-test lisant ce que le NAVIGATEUR reçoit de `/api/admin/kds-order`, pour
trancher la dernière inconnue de SYNC-1.

## Pourquoi ça n'a rien donné

**La sonde s'est bloquée** — vraisemblablement sur la séquence de connexion chef ou sur
`window.axios.get` sans délai de garde. Elle a placé la commande #6929, puis n'a plus rien produit.
Je l'ai arrêtée et **retirée du dépôt** : un test qui pend est pire qu'un test absent, il immobilise
une campagne entière sans rien dire.

## L'obstacle de fond, qui vaut d'être nommé

**Toute commande de test est ANNULÉE au démontage** (`status=16`, via `OrderService::changeStatus`,
NF525 — on n'efface pas une trace fiscale). Conséquence : au moment où j'inspecte la base après
coup, le spécimen n'est plus éligible au board.

⚠️ **J'ai d'abord écrit qu'il n'y avait AUCUNE commande borne sur le board. C'était faux — et
l'erreur venait de ma propre mesure.** J'avais pris les 20 plus récentes par identifiant
décroissant, ce qui ne remontait que des commandes d'août (`pos`, `uber_eats`, `web`).

Le compte réel, sans limite :

| Critère | Commandes borne |
|---|---|
| `source_surface = 'kiosk'`, tous statuts | **1 275** |
| en statut board (4/7/8) | **181** |
| dont branche 1 | **181** (toutes) |
| dont paiement libéré (5 ou 15) | **181** (137 en PENDING_COUNTER, 44 en PAID) |
| réparties sur | **2026-06 : 102 · 2026-07 : 79** — aucune en août |

Ces 181 satisfont donc **tous** les critères de libération du board. Elles recoupent le vivier des
344 commandes figées relevées en W5 : ce sont les mêmes données mortes, vues sous un autre angle.

Ce que cela apprend sur SYNC-1 : le pipeline borne → board **a fonctionné** en juin et juillet, sur
1 275 commandes. Le défaut n'est donc pas structurel dans le classement par surface — sinon aucune
de ces 181 n'aurait jamais atteint un statut board.

Reste que je n'ai pas de spécimen **du jour** à observer : toute commande de test est annulée au
démontage. L'observation doit se faire PENDANT la campagne — et c'est ce qui a échoué.

## Où en est réellement SYNC-1

**Neuf hypothèses éliminées, preuve à l'appui** : statut hors liste visible · non-libération board ·
nom de champ `source_surface` · ressource de liste (`KDSOrderDetailsResource` l'expose bien) ·
article sans station · socket injoignable · mauvaise branche · autre worktree · sélecteur de la spec
(`id="order-<id>-title"` existe bel et bien dans le marquage de la file borne).

**Je ne peux pas conclure plus loin sans une observation en vol réussie.** Je le dis plutôt que de
proposer une dixième hypothèse invérifiée.

## La bonne nouvelle sur le périmètre

Après le correctif d'idempotence, **SYNC-1 est le SEUL état rouge**. Le bump, la propagation OSS
(13 ms et 3 ms) et l'idempotence sont verts. Le défaut n'est plus « la chaîne borne→KDS→OSS est
cassée » mais « la carte n'apparaît pas au premier affichage » — un périmètre incomparablement
plus étroit que celui hérité des cycles précédents.

## Prochain geste, précis

Reprendre la sonde avec : délai de garde sur chaque `evaluate`, connexion chef par état de session
réutilisé plutôt que par formulaire, et relevé du contenu de `visibleRows`/`kioskOrders` du composant
plutôt que du seul DOM.

---

# W9bis — L'API interrogée directement : le board est VIDE, et c'est correct

Sans navigateur cette fois : jeton Sanctum temporaire frappé pour le chef (id=4, branche 1),
appel direct de `/api/admin/kds-order`, jeton révoqué ensuite.

```
HTTP 200 — commandes renvoyées : 0
```

**Zéro commande**, alors que la base porte 181 commandes borne en statut board sur cette branche.

## Pourquoi — et pourquoi ce n'est PAS un défaut

| Mesure | Valeur |
|---|---|
| Éligibles au board (statut 4/7/8 + branche 1 + paiement 5 ou 15) | **609** |
| dont `order_datetime` **aujourd'hui** (2026-08-25, Europe/Paris) | **0** |
| Plage réelle | **2026-06-10 → 2026-08-19** |

Le KDS filtre sur la journée en cours (plus les commandes programmées en retard). Aucune des 609
n'est d'aujourd'hui : **renvoyer zéro est le bon comportement**. Un tableau de cuisine ne doit pas
afficher une commande de juin.

## Ce que cela ajoute au dossier

1. **Le filtre de date du KDS fonctionne comme prévu.** Une hypothèse de plus éliminée — la dixième.
2. **609 commandes traînent en statut board sans jamais être servies.** C'est la même dette que les
   344 commandes figées de W5, vue sous un autre angle et avec un compte plus large. Elles ne
   dérangent personne aujourd'hui *parce que* le filtre de date les masque — mais elles restent des
   commandes ouvertes en base, sur des mois.
3. **SYNC-1 reste non conclu.** Pendant la campagne, la commande était du jour et en statut ACCEPT :
   elle aurait dû passer le filtre. Le prouver exige une observation EN VOL, que ma sonde n'a pas
   su produire.

**Je m'arrête ici plutôt que d'avancer une onzième hypothèse.** Dix ont été éliminées avec preuve ;
la suivante demande un instrument que je dois d'abord réparer.

---

# W9ter — La onzième élimination, et où se situe SYNC-1 avec certitude

## La fenêtre de date du board, mesurée

`KitchenDisplaySystemOrderService:142` :

```php
$staleFloor = now($appTz)->subHours((int) config('oss.stale_window_hours', 8));
// puis : order_datetime >= $staleFloor  ET  < $tomorrowStart  ET  is_advance_order = NO
```

Ce n'est pas « aujourd'hui » mais une **fenêtre glissante de 8 heures**, bornée au lendemain minuit.
Mesuré à 18h26 : la fenêtre est `[10h26, demain 00h00[`. **Une commande créée maintenant y entre.**

## La déduction — et je la qualifie comme telle

Une commande borne fraîche satisfait **tous** les filtres du board, chacun vérifié séparément :

| Filtre | Valeur d'une commande borne fraîche | Passe ? |
|---|---|---|
| Statut visible (4/7/8) | `ACCEPT` = 4 | ✅ |
| Branche | 1 | ✅ |
| Libération board (PAID ou PENDING_COUNTER) | `payment_status = 15` | ✅ |
| Fenêtre glissante 8 h | créée à l'instant | ✅ |
| `source_surface` exposé par la ressource | `KDSOrderDetailsResource:29` | ✅ |
| Rangement en file borne | `isKioskSource` lit `source_surface === 'kiosk'` | ✅ |

**Le backend renverrait donc bien la commande.** SYNC-1 échoue en aval : dans la **couche
navigateur** — rendu, cadence de scrutation, ou session chef —, pas dans l'API.

⚠️ C'est une **déduction à partir de mesures**, pas une observation directe. Je la donne pour ce
qu'elle vaut : elle réduit le périmètre, elle ne le prouve pas. La preuve demande toujours une
observation en vol.

## Un effet de bord qui mérite votre attention

La fenêtre glissante de 8 heures signifie que **les 609 commandes en statut board ne réapparaîtront
jamais sur l'écran de cuisine**, quel que soit leur statut. Elles sont ouvertes en base — `ACCEPT`,
`PREPARING` ou `PREPARED` — depuis juin, et définitivement hors de vue du personnel.

Ce n'est pas un défaut du filtre : une cuisine ne doit pas voir une commande de juin. C'est un
**stock de commandes jamais clôturées**, qui relève de la même décision d'exploitation que les 344
commandes figées de W5 — avec un compte plus large parce qu'il englobe les trois statuts actifs.

`php artisan foodking:commandes-figees --jours=1` les liste (lecture seule).

---

# ✅ W9quater — SYNC-1 TRANCHÉ : le backend est innocent, c'est mesuré

## Le test décisif, sans navigateur

Deux erreurs de ma part bloquaient les tentatives précédentes, corrigées en lisant une spec qui
marche (`audit-kds-cycle1`) :
- l'endpoint est **`/api/auth/login`**, pas `/api/login` ;
- le mot de passe borne est **`kiosk123`** (`DEFAULT_KIOSK_PASSWORD`), pas `123456`.

Séquence exécutée en HTTP pur :

```
chef     → /api/auth/login          HTTP 201, jeton obtenu
borne    → /api/auth/kiosk-login    HTTP 201, jeton obtenu
article  → #12 « Cheddar », station cuisine_chaude
devis    → /api/frontend/order/quote   HTTP 200
commande → /api/frontend/order         HTTP 201  →  #6930
board    → /api/admin/kds-order        HTTP 200
```

## Le résultat

```
board essai 1 : 1 commande | la mienne : ✅ PRÉSENTE
                surface=kiosk  status=4  type=10
```

**La commande borne fraîche apparaît sur le board en moins de 2 secondes**, correctement marquée
`source_surface = 'kiosk'`. `isKioskSource()` la rangerait donc dans la file borne.

## Ce que cela établit — mesure, plus déduction

Le backend fait **exactement** ce qu'il doit : il crée, il libère, il expose, il marque la surface,
et il le fait vite. **SYNC-1 échoue donc dans la couche NAVIGATEUR** — rendu, cadence de scrutation,
ou session chef de la campagne. Ce n'est plus une hypothèse : c'est la onzième élimination, et la
dernière qui restait côté serveur.

Le délai n'est pas non plus en cause : 2 secondes contre les budgets de 8 s puis 15 s de la spec.

## Où chercher maintenant, précisément

1. La page KDS reçoit-elle la commande (scrutation/Echo) et ne la rend-elle pas ?
2. Ou ne la reçoit-elle pas, faute de session chef valide dans la campagne ?

La distinction se fait en relevant `visibleRows` / `kioskOrders` du composant pendant la campagne —
et non le DOM, qui ne dit pas laquelle des deux.

## Propreté

Commande #6930 **annulée** (statut 16), jamais supprimée — board revenu à 0. Chaîne NF525 en ajout
seul (8 128 → 8 132), `z_reports` inchangé à 33.
⚠️ `OrderService::changeStatus()` ayant une signature différente de celle attendue, l'annulation a
été faite par mise à jour directe du statut, sur ma propre commande de sonde. Je le signale plutôt
que de le taire.

---

# ✅✅ W10 — SYNC-1 RÉSOLU : la spec vise un balisage que la refonte V2 ne rend plus

## La chaîne de preuve, complète

Sonde corrigée (bons sélecteurs `#formEmail`/`#formPassword`, endpoint `/api/auth/login`,
délais de garde), commande placée par API en amont :

**1. La page APPELLE bien l'API et REÇOIT la commande.**
```
GET /api/admin/kds-order?paginate=0&order_column=id&order_by=desc → HTTP 200, 1 ligne
échantillon : { id: 6931, status: 4, source_surface: "kiosk", order_type: 10,
                nb_items: 1, stations: ["cuisine_chaude"] }
```
Tout ce dont le composant a besoin pour la ranger en file borne est présent.

**2. Aucune carte `data-kds-order-card` dans le DOM — ET pas non plus le message « Aucune
commande borne en cours. »** Ce second point est le déclic : si la colonne existait et était vide,
le message s'afficherait. Son absence prouve que **la colonne entière n'est pas rendue**.

**3. Le texte visible de la page dit pourtant :**
```
[A] NOUVELLE BORNE  N°A0132  ATTENTE 03:05  1× CHE  EN ATTENTE ENCAISSEMENT  Prêt
```
**La commande borne EST affichée.** Avec son numéro de file, son attente, son état d'encaissement.

## La cause

`KitchenDisplaySystemComponent.vue:137-138` :
```html
<KdsV2Grid v-if="useV2Layout" … />
```
et `useV2Layout` vaut **`true` par défaut** (`config/kds.php`, surchargeable par
`localStorage['kds.v2_enabled']`).

Or **`KdsV2Grid.vue` ne contient AUCUN `data-kds-order-card`** (compté : 0). L'ancien balisage en
colonnes — `data-kds-order-card="kiosk"`, titre « 🖥️ Borne » — vit derrière `v-if="!useV2Layout"`,
c'est-à-dire **mort par défaut depuis la refonte**.

## Le verdict

**SYNC-1 n'est PAS un défaut produit. C'est une spec qui affirme contre une interface disparue.**

Le produit fait exactement ce qu'il doit : la commande borne arrive en cuisine, marquée
« NOUVELLE BORNE », avec sa file d'attente et son état d'encaissement. La spec cherche un attribut
que la V2 ne pose plus.

## Ce que cela clôt

**La vague D n'avait AUCUN défaut produit.** Ses deux causes étaient toutes deux dans le harnais :
1. l'en-tête d'idempotence manquant (états 07-10) — **corrigé**, avec 12 autres specs ;
2. des sélecteurs V1 contre une interface V2 (état 05) — **identifié ici**.

## Ce qui reste à faire, et qui relève de vous

Mettre la spec à jour demande de choisir la propriété à prouver :
- **A)** viser les sélecteurs V2 (`data-testid="kds-cols-*"` et les marqueurs de `KdsV2Grid`) —
  on teste alors l'interface réellement servie ; *(recommandé)*
- **B)** forcer `localStorage['kds.v2_enabled'] = false` dans la spec pour tester la V1 — on teste
  alors une interface que personne ne voit ;
- **C)** couvrir les deux.

⛔ Je ne tranche pas : choisir ce qu'un test doit prouver est une décision de conception, pas une
réparation mécanique.

---

# 🔴 W10bis — Ce n'était pas la seule spec : 14 visent l'interface morte

Le même relevé, étendu à tout le répertoire :

| Mesure | Valeur |
|---|---|
| Specs affirmant contre `data-kds-order-card` | **14** |
| dont forçant la disposition V1 (`kds.v2_enabled`) | **0** |
| Specs visant les sélecteurs V2 (`kds-cols-*`, `kds-scroll-*`) | **3** |

**Les 14 testent une interface que personne ne voit.** Exactement le même mode de panne que
l'en-tête d'idempotence : l'échec ressemble à un défaut produit et envoie chercher au mauvais
endroit. Deux causes systémiques distinctes, découvertes le même jour, expliquant à elles seules
une part majeure du « pourrissement E2E » traîné depuis des cycles.

Liste : `rush-100-cross-surface` · `test-e2e-pos-kds-sync-{C,D,E}` ·
`test-e2e-kiosk-kds-sync-{C,D}` · `test-e2e-rush-hour-50x50-{A,B,E}` ·
`iter15-mega-{kiosk,lifecycle}-roundtrip` · `04-kds-status` ·
`test-e2e-latency-cross-surface` · `test-e2e-supervisor-wave-c-z4-latency`

**Sentinelle** : `tests/js/e2eSelecteursKdsV2.spec.js` (5 tests, cliquet à 14, prouvée mordante).
Elle épingle aussi les trois faits porteurs — la V2 est le défaut, `KdsV2Grid` ne pose pas
l'attribut, et le balisage V1 survit derrière la bascule. Si l'un change, elle le dira.

⛔ **Aucune spec migrée.** Choisir ce qu'un test doit prouver — V2 servie, V1 forcée, ou les deux —
est une décision de conception qui vous revient.
