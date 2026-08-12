# « Trop de requêtes » sur la caisse — cause, correctif, et ce qui reste entre vos mains

**2026-08-12 · aucun commit, aucune poussée**

---

## 1. Ce que je n'ai PAS fait, et pourquoi

Vous avez demandé à ne plus voir le message. Le masquer aurait été le geste le plus nuisible possible.

Ce message a été **ajouté délibérément** (`resources/js/bootstrap.js:52-64`), après un défaut classé **P0** :

> « POS shell silently swallowed 7+ HTTP 429 … Cashier had no signal at all. »

Avant lui, la caisse **avalait les refus en silence** : les commandes ne se rafraîchissaient plus, et le caissier n'en savait rien. Le supprimer restaurerait exactement ce P0.

**On retire donc les requêtes, jamais l'alerte.**

---

## 2. La cause, mesurée — pas supposée

Protocole : caisse ouverte via Playwright sur l'origine correspondant à `APP_URL`, comptage de toutes les requêtes `/api/`.

| Mesure | Valeur |
|---|---|
| Coût d'**ouverture** de la caisse | **35 requêtes** en 10 s |
| Cadence **au repos** (60 s sans rien toucher) | **5 req/min** — la caisse est sobre |
| Dont **doublons** à l'ouverture | **7 endpoints appelés DEUX FOIS**, à **0-1 ms d'écart** |

Les 7 doublons : `/api/frontend/setting` · `/api/admin/default-access` · `/api/admin/setting/company` · `/api/admin/pos/counter-collect/pending` · `/api/admin/pos/web-orders/pending` · `/api/admin/pos/web-orders/paid` · `/api/admin/users/address/2`

**Le mur** : `throttle:api` vaut **120/min en production** et il est **PAR COMPTE**, pas par écran — `RouteServiceProvider.php:57` → `by($request->user()?->id ?: $request->ip())`.

En local il est à **1000** (`.env API_THROTTLE_PER_MINUTE`), ce qui **masque complètement le défaut au développement**. C'est pourquoi vous le voyez en service et pas ici.

### Une fausse piste écartée en route
Ma première mesure annonçait **81** requêtes dont **47 rapports de violation CSP** — soit 58 % de bruit. **C'était un artefact de mon propre harnais** : Playwright chargeait `localhost:8000` alors que l'application est configurée sur `127.0.0.1:8766`, donc chaque appel devenait hors-origine et générait un rapport. Sur l'origine correcte : **zéro rapport CSP**. Je ne vous vends pas ce chiffre.

---

## 3. Le correctif — fusion des GET identiques **en vol**

`resources/js/shared/inflight-dedupe.js` (neuf), installé aux deux entrées (`app.js`, `pos-app.js`).

Quand deux appels **identiques** partent **au même instant**, un seul part sur le réseau ; les deux appelants reçoivent la même réponse.

**Ce que ce module n'est pas**, et c'est essentiel :
- il **ne met RIEN en cache** — dès qu'une réponse est rendue, la clé est libérée ; un appel ultérieur repart. Une caisse qui afficherait des commandes périmées serait bien pire que le défaut ;
- il ne touche **jamais** une mutation (POST/PUT/DELETE) — fusionner deux encaissements serait une **commande perdue** ;
- il propage l'erreur à **tous** les appelants — jamais de succès fantôme.

### Résultat mesuré, même protocole

| | avant | après |
|---|---|---|
| Ouverture de la caisse | **35** requêtes | **29** requêtes (**−17 %**) |
| Doublons simultanés | **7 paires** | **1** (à 213 ms — pas de chevauchement, donc non fusionnable, à raison) |
| Au repos | 5/min | **5/min** — inchangé |

### Ce que ça change concrètement

| Écrans ouverts sur **le même compte** | avant | après | mur à 120 |
|---|---|---|---|
| 3 écrans | 105 | 87 | ok |
| **4 écrans** | **140 → dépasse** | **116 → passe** ✅ | |
| 5 écrans | 175 | 145 → dépasse | |

**Le mur recule de 4 à 5 écrans.** Si votre configuration est caisse + cuisine + écran client + suivi, vous étiez juste au-dessus ; vous passez maintenant en dessous.

---

## 4. Une faute de méthode que je signale

Ma **première** version du module était **inerte** et je l'ai livrée sans le voir : la garde testait `typeof adapter !== 'function'`, or en **axios 1.16** `defaults.adapter` est un **tableau** (`["xhr","http","fetch"]`). L'installation était donc sautée en silence.

**Mes 7 bancs unitaires passaient quand même** — ils utilisaient un faux axios avec une fonction. Le test ne reflétait pas la réalité.

Ce qui l'a attrapé : la **re-mesure**. Après « correctif », la rafale était toujours à 35, strictement inchangée. J'ai ajouté deux bancs qui s'appuient sur le **vrai axios** pour que le piège ne puisse pas revenir.

---

## 5. Bancs et preuves

| Banc | Contenu |
|---|---|
| `tests/js/inflightGetDedupe.spec.js` | **9 verts** — dont 2 sur le **vrai axios** (anti-banc-creux) |
| `tests/e2e/pos-request-budget.spec.js` | **2 verts** — garde permanente : budget d'ouverture ≤ 32, aucun doublon simultané, repos ≤ 12/min |

**Mutations, toutes détectées** : fusion désactivée · fusion transformée en **cache** (données périmées) · fusion appliquée aux **POST** (commande perdue) · garde fautive `typeof !== 'function'` restaurée.

La garde de budget n'est pas creuse : la même logique de mesure donnait **35 requêtes et 6 paires simultanées** avant le correctif — elle aurait échoué sur les deux critères.

---

## 6. Ce qui reste entre vos mains — deux leviers, aucun n'est technique

Mon correctif retire 17 %. Si vous ouvrez **5 écrans ou plus** sur le même compte, vous reverrez le message. Deux leviers, à vous de choisir :

### Levier A — un compte par écran (gratuit, et c'est la bonne réponse)
Le plafond est **par compte**. Trois comptes existent déjà : `admin@lecayenne.fr`, `pos@lecayenne.fr`, `chef@lecayenne.fr`.

Si la caisse, la cuisine et l'écran client sont connectés **sous le même login**, ils se partagent **un seul** budget de 120/min. Avec un compte par écran, chacun a le sien — et le problème disparaît quasiment.

**C'est le levier que je recommande** : rien à déployer, et il est architecturalement juste.

### Levier B — relever le plafond
`API_THROTTLE_PER_MINUTE` dans le `.env` de production (défaut **120**). Le passer à 300 donnerait de l'air immédiatement.

**Mais c'est un pansement** : le plafond protège aussi contre une boucle folle qui martèlerait le serveur. Le relever sans raison réduit cette protection. À n'utiliser que si le levier A ne suffit pas.

**Je n'ai touché à aucun des deux** — ce sont vos décisions d'exploitation, pas les miennes.

---

## Fichiers touchés

| Fichier | Nature |
|---|---|
| `resources/js/shared/inflight-dedupe.js` | **neuf** |
| `tests/js/inflightGetDedupe.spec.js` | **neuf** |
| `tests/e2e/pos-request-budget.spec.js` | **neuf** |
| `resources/js/app.js` | modifié — 2 lignes additives |
| `resources/js/pos-app.js` | modifié — 2 lignes additives |

**Aucun composant de la caisse touché** (voie de la session parallèle, laissée intacte). **Frozen-zones : 0 fichier.**
