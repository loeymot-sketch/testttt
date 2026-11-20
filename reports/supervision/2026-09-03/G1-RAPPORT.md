# G1 — La caisse voit toute la journée de service

Rapport d'exécution — 2026-09-03, branche `pos/category-first-caisse-2026-06-23`, HEAD `28cd79d5a`.
GOAL : `plans/goals-2026-09-03/G1_CAISSE_JOURNEE_COMPLETE.md` (défauts V-01 P1, V-13 P2).

**Rien n'est commité.** L'arbre est laissé modifié.

---

## 1. Ce que j'ai vérifié AVANT de coder (le GOAL demandait de ne rien supposer)

| Ancre | Ce que j'y ai réellement lu |
|---|---|
| `app/Services/OrderService.php:137-138` | `$method = $request->get('paginate',0)==1 ? 'paginate' : 'get'` et `$methodValue = … ? per_page : '*'`. **Sans `per_page` (donc sans `paginate`), le serveur fait `->get('*')`** : toute la fenêtre, non bornée. |
| `app/Services/OrderService.php:139-140` | tri par défaut `order_column = id`, `order_by = desc`. **C'est ce tri qui décide QUI tombe** quand une page de cent est demandée : les plus anciennes. |
| `app/Http/Requests/PaginateRequest.php:27` | `'per_page' => ['numeric','min:1','max:1000']`. Seule borne serveur, et elle ne s'applique que si le client envoie `per_page`. **Il n'existait aucun plafond serveur.** |
| `PosComponent.vue:4782` (avant) | `per_page: 100` — le cent était un choix purement client, invisible. |
| `PosOrdersTrackerComponent.vue:1856` (avant) | même plafond de 100, avec un commentaire affirmant que « seules les DELIVERED périmées au-delà de 100 tombent » — **c'était faux**, le tri est `id desc` toutes voies confondues. |

---

## 2. Discipline : le banc d'abord, et il devait rougir

Sortie rouge intégrale : **`reports/supervision/2026-09-03/G1-bancs-mordent.txt`**.
(Le GOAL nommait `G1-banc-mord.txt` ; le prompt d'exécution nommait `G1-bancs-mordent.txt`. J'ai suivi le prompt.)

| Banc | Rougeur prouvée | Vert après correctif |
|---|---|---|
| `tests/js/posControlDrawerAuDelaDeCent.spec.js` (T1.1 + T1.4) | **4 échecs / 5** au premier jet, puis **5 échecs / 6** sur la forme finale du banc | **6/6** |
| `tests/Feature/Pos/PosControlDrawerJourneeCompleteTest.php` (T1.2) | **7 échecs / 7** (l'endpoint n'existait pas : le repli SPA rendait du HTML en 200) | **7/7** |
| `tests/Feature/Sentinels/EnumsJsPhpConvergenceSentinelTest.php` (T1.5) | **2 échecs / 2** | **2/2** |

### Deux honnêtetés à signaler sur ces rougeurs

**(a) Le premier jet du banc JS rougissait partiellement pour une mauvaise raison.**
Le tiroir est `Teleport`é dans `<body>` ; sans le stub `teleport`, `wrapper.find()` ne trouve rien
et le §4 aurait rougi même avec le correctif en place. J'ai ajouté le stub (patron identique à
`tests/js/posControlDrawer.spec.js`), puis **rejoué le banc dans sa forme FINALE contre le code
d'avant** — les 4 fichiers de production du frontend remis à l'état `HEAD` par
`git show HEAD:<f> > <f>` (l'index de cet arbre partagé n'est jamais touché), puis restaurés
depuis une copie. Résultat : **5 échecs / 6**. C'est cette exécution-là qui fait preuve.

**(b) Le §1 de la sentinelle d'enums était VERT d'emblée, et c'est normal.**
V-13 est une **dette**, pas une panne : la parité JS/PHP tient aujourd'hui. Pour prouver que
l'instrument mesure quelque chose, j'ai muté `orderStatusEnum.PREPARED` de 8 à 9, capturé la
rougeur, et restauré (`git diff` vide sur ce fichier, vérifié). Le §2, lui, rougissait tel quel :
les treize recopies existaient.

---

## 3. Voie retenue — B, sans repli

La voie **B** du GOAL a été tenue : endpoint serveur borné au jour de service et aux quatre files,
`total` renvoyé **et affiché**. Aucun repli sur la voie A.

Une précision que le GOAL ne tranchait pas et qu'il fallait trancher : **quel plafond de sécurité,
et sur quoi**. « Sans borne » était rejeté par le GOAL lui-même (payload non borné). J'ai donc
posé deux familles, avec un plafond chacune, parce qu'un plafond unique force un tri unique et
qu'aucun tri unique n'est bon :

- **ACTIFS** (à encaisser / cuisine / prêtes / en livraison), tri **plus ancienne d'abord**,
  plafond 300. Si le plafond mordait, il tomberait sur les dernières arrivées, jamais sur les
  traînardes — qui sont la raison d'être de cet écran.
- **LIVRÉES**, tri **plus récente d'abord**, plafond 300. C'est exactement ce que dit déjà la file
  correspondante (`filesControle.js::fileLivrees` : « on ouvre cette file pour vérifier ce qu'on
  VIENT de servir, pas pour relire le début du service »).

Les statuts terminaux non livrés (annulée / rejetée / rendue) restent dehors du tiroir : aucune des
quatre files ne les montre, et `meta.total` doit compter ce que l'écran affiche. Le tableau de suivi
plein écran, dont le compteur « X aujourd'hui » les compte, les demande par `avec_terminales=1`.

**Preuve visuelle que le tri des actifs est le bon** : sur la capture `04-bandeau-troncature.png`,
plafonds abaissés à 5, la commande affichée est `N°G000` — la doyenne du service, celle de 11h42.
C'est précisément celle que la page de cent jetait.

---

## 4. Fichiers de production modifiés

| Fichier | Lignes | Ce qui change |
|---|---|---|
| `app/Http/Controllers/Admin/PosOrderController.php` | +157 / -0 | `serviceDay()` (l. 372), `fenetreDuService()` (l. 456), constantes `CAP_ACTIFS`/`CAP_LIVREES`/`HEURE_BASCULE_SERVICE`/`STATUTS_ACTIFS`/`STATUTS_TERMINAUX` (l. 22-49) |
| `routes/api.php` | +7 / -0 | `GET admin/pos-order/service-day` (l. 1465-1467), `throttle:60,1`, aligné sur `/stale` |
| `resources/js/store/modules/posOrder.js` | +21 / -0 | action `serviceDay` (l. 208), aucun commit Vuex |
| `resources/js/components/admin/pos/PosComponent.vue` | +44 / -12 | `_fetchServiceOrdersOnce` → `posOrder/serviceDay` (l. 4793) ; `_retenirMetaService` (l. 4816) appelé par les deux chargeurs (l. 4837, 5344) ; état `serviceOrdersMeta` (l. 2562) ; prop `:troncature` (l. 1973) |
| `resources/js/components/admin/pos/PosControlDrawer.vue` | +63 / -0 | prop `troncature` (l. 437), calcul `troncatureAAvouer` (l. 485), bandeau `data-testid="pos-control-troncature"` (l. 110-117), style `.pos-ctrl__troncature` (l. 856) — jetons `--pos-v5-warning*` **réels**, vérifiés dans `resources/css/foundations/pos-v5-tokens.css` |
| `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` | +58 / -21 | `fetchOrders` → `posOrder/serviceDay` + `avec_terminales:1` (l. 1882) ; `_retenirTroncature` (l. 1977) ; état `troncatureService` (l. 1122) ; pilule `tracker-troncature-pill` (l. 55-63) |
| `resources/js/support/filesControle.js` | +13 / -12 | six littéraux d'enum remplacés par les enums JS canoniques |
| `resources/js/support/fileCuisine.js` | +17 / -14 | sept littéraux d'enum remplacés de même |
| `resources/js/languages/fr.json` | +2 / -1 | clé `pos.controle.troncature` |

### Fichiers de test

| Fichier | Nature |
|---|---|
| `tests/js/posControlDrawerAuDelaDeCent.spec.js` | **créé** (6 cas) |
| `tests/Feature/Pos/PosControlDrawerJourneeCompleteTest.php` | **créé** (7 cas) |
| `tests/Feature/Sentinels/EnumsJsPhpConvergenceSentinelTest.php` | **créé** (2 cas) |
| `tests/js/posTrackerStaleness.spec.js` | +46 / -6 — **contrat remplacé**, voir §7 |
| `tests/js/sentinels/posServiceFetchCoalesceSentinel.spec.js` | +25 / -24 — **contrat remplacé**, voir §7 |

---

## 5. Ce que chaque banc prouve

### `tests/js/posControlDrawerAuDelaDeCent.spec.js` — 6/6

Il ne monte pas le tiroir avec 137 commandes déjà en main (ça ne prouverait rien : le tiroir affiche
ce qu'on lui donne, il l'a toujours fait). Il monte **la caisse**, derrière un faux serveur qui se
comporte comme le vrai — `paginate=1` fait honorer `per_page`, tri `id desc` — et regarde ce qui
arrive dans `serviceOrders`, source unique des quatre files, des deux pastilles et du rang cuisine.

1. la doyenne à encaisser (id 1 sur 137) **arrive** jusqu'à la caisse ;
2. la file « à encaisser » compte le service entier (13), et la doyenne est **en tête** ;
3. `loadReadyOrders` (second chemin de chargement) ne **retronque pas** la journée ;
4. **T1.4** : le tiroir **avoue** la troncature, avec les deux nombres, clé i18n résolue ;
5. …et se **tait** quand la journée est complète ;
6. la caisse **retient** ce que le serveur dit de sa réponse (`meta` → `serviceOrdersMeta`) — sans ce
   maillon, le §4 prouverait seulement qu'un composant sait afficher un objet qu'on lui tend.

### `tests/Feature/Pos/PosControlDrawerJourneeCompleteTest.php` — 7/7

137 commandes semées sur la journée de service : la charge en rend **137**, `meta.total` = 137,
`meta.truncated` = false, la doyenne à encaisser est présente ; les quatre files sont complètes
(12 / 20 / 15 / 90) ; une annulée/rejetée/rendue n'entre **dans aucune** file et n'est **pas comptée**
dans le total ; une commande d'une **autre branche** n'entre pas ; une commande **hors journée de
service** n'entre pas ; avec `plafond=5`, `meta.truncated` est **vrai** et `meta.total` reste 137 ;
un compte sans droit caisse reçoit **403**.

### `tests/Feature/Sentinels/EnumsJsPhpConvergenceSentinelTest.php` — 2/2

§1 : les quatre modules `resources/js/enums/modules/*` valent **exactement** `App\Enums\OrderStatus`,
`PaymentStatus`, `OrderType`, `PosPaymentMethod` (comparaison des tables complètes, dans les deux
sens). §2 : `filesControle.js` et `fileCuisine.js` **importent** ces enums et ne contiennent plus
aucune recopie `const STATUT_… = <nombre>;`.

---

## 6. Non-régressions

### PHPUnit (ciblé uniquement, jamais la suite complète)

| Filtre | Résultat |
|---|---|
| `PosControlDrawerJourneeCompleteTest` | 7 ✓ |
| `EnumsJsPhpConvergenceSentinelTest` | 2 ✓ |
| `PosOrderListLeanPaginationTest` (liste du GOAL) | 4 ✓ |
| `PosStaleOrdersTest` | 7 ✓ |
| `AdminRoutePermissionFloorTest` + `SettingsHardeningTest` + `PosCashEndpointSentinelTest` | 11 ✓ |
| `IdempotencyRequiredRoutesCoverageTest` | 1 ✓ |
| `PosOrderDestroyTest` + `PosOrderTaxTest` + `BranchIsolationTest` + `POSComprehensiveTest` + `OrderHistoryUnifiedTest` | 41 ✓ |

### Vitest — **suite complète**

`520 fichiers, 4297 tests passés, 3 ignorés, 0 échec` (après `npm run production`).
Les **5 sentinelles de fraîcheur** (`*BundleFreshness*`) sont vertes ; elles rougissaient avant le
rebuild (`fr.json` plus récent que `public/js/pos-app.js`), et j'ai relancé `npm run production`.

### E2E

`tests/e2e/goal-caisse-controle-2026-09-02.spec.js` → **11/11 verts** sur `http://127.0.0.1:8766`.

---

## 7. Ce que j'ai dû casser, et pourquoi (à lire, ce sont des contrats supprimés)

Deux bancs **épinglaient le défaut lui-même**. Les laisser verts aurait verrouillé la borne cliente.

1. **`tests/js/posTrackerStaleness.spec.js`** — un cas exigeait
   `dispatch('posOrder/lists', { paginate: 1, per_page: 100, lean: 1 })`. Remplacé par : la requête
   part sur `posOrder/serviceDay` et **ne porte ni `per_page` ni `paginate`**. Deux cas ajoutés sur
   la pilule de troncature du tableau (elle parle / elle se tait).
2. **`tests/js/sentinels/posServiceFetchCoalesceSentinel.spec.js`** — sentinelle de source qui
   miroitait verbatim le corps de `_fetchServiceOrdersOnce`, plafond compris. Miroir et assertions
   mis à jour ; les assertions « plus de plafond client » sont **ancrées en début de ligne**
   (`/^\s*per_page\s*:/m`) pour ne pas se déclencher sur le mot cité dans un commentaire — ce piège
   m'a fait rougir une fois, ce qui prouve au passage que la sentinelle lit bien la source réelle.

Dans les deux cas, le commentaire du fichier explique le remplacement plutôt que de l'effacer.

---

## 8. Vérification visuelle (CLAUDE.md §6)

137 commandes semées dans la base locale (insertion brute, préfixe `G1CAP-`), captures lues et
analysées, **puis les 137 commandes et leurs lignes supprimées** (`reste: 0` vérifié).

Captures : `reports/supervision/2026-09-03/captures/`

| Capture | Ce que j'y lis |
|---|---|
| `02-tiroir-encaisser.png` | Onglets **13 / 54 / 42 / 41**. En tête : `N°G000`, **11h41**, « 1ᵉʳ sur 54 en cuisine » — la doyenne du service est là. Pastilles de la barre : `13 💶` / `42 🛎️`. Aucun libellé brut, aucun débordement, aucun bandeau de troncature (correct : 137 < 300). |
| `03-tiroir-livrees.png` | 41 livrées, **plus récente d'abord** (G134 31 min → G113 2h16). |
| `04-bandeau-troncature.png` | Plafonds temporairement abaissés à 5 : bandeau ambre **« Liste écourtée : 10 commandes affichées sur 137 dans le service »**. Plafonds restaurés à 300 juste après (vérifié). |

**Un faux signalement que j'ai failli écrire** : la première capture montrait le tiroir décalé de
~170 px vers la droite et coupé au bord de l'écran. Ce n'est pas un défaut : `.pos-ctrl` entre par
la droite en 220 ms (`@keyframes pos-ctrl-entree`) et la capture partait avant la fin. Avec 900 ms
d'attente, la position est exacte et identique à la capture de référence du 2026-09-02.

Erreurs console : **uniquement** `ws://127.0.0.1:6001` refusé (Echo/Reverb non démarré en local) —
bruit d'environnement, pas de produit. Aucune erreur applicative.

---

## 9. Zone gelée et garde-fous

```
$ bash .cursor/hooks/safety-check.sh
[safety-check] Checking 15 frozen zones...
[safety-check] Frozen zones: OK
[safety-check] Checking PHP syntax...
[safety-check] No staged PHP files.
[safety-check] Passed. Proceed with execution.
```

Diff sur les 15 fichiers gelés (`pos-wizard.js`, `pos-wizard.css`, `admin-pos-v4.blade.php`,
`PaymentComponent.vue`, `PosV5TrancheRow.vue`, les 3 kiosk, les 3 fiscaux, `BranchScope`,
`IdempotencyKeyMiddleware`, `PricingService`, `OrderStateMachine`) :

```
=== DIFF ZONE GELÉE : 0 ligne(s) ===
```

Les lots `public/js/*` et `public/mix-manifest.json` sont ignorés par `public/.gitignore` : le
rebuild n'apparaît pas dans le diff.

---

## 10. Ce que je n'ai PAS fait, et pourquoi

1. **Aucun commit** — demandé explicitement.
2. **Aucun `git add`** — arbre partagé, et l'index porte le travail d'autres sessions.
3. **Pas de suite PHPUnit complète** — interdite par le prompt ; j'ai ciblé par `--filter`.
4. **Pas de capture du bandeau de troncature en conditions naturelles.** Il faudrait plus de 300
   commandes actives ou 300 livrées dans une seule journée de service. Je l'ai obtenu en abaissant
   temporairement les plafonds à 5, puis restauré. Le comportement est par ailleurs verrouillé par
   deux bancs (`plafond=5` côté PHP, prop `troncature` côté JS).
5. **`per_page` reste accepté par `admin/pos-order`** (l'ancien endpoint). Je ne l'ai pas borné : il
   sert encore l'historique, l'export et le rapport de ventes, pour qui la pagination est légitime.
   Le défaut visé était l'usage qu'en faisait la caisse, et cet usage a disparu.
6. **Le scoping de `User` par branche reste absent** (CLAUDE.md §9). Hors périmètre G1 ; mon banc
   d'isolation porte sur `Order`, qui est bien scopé.
7. **La deuxième ronde de la « condition de sortie » du GOAL n'a pas été jouée.** J'ai une ronde
   complète (bancs + non-régressions + E2E 11/11 + captures analysées). Une seconde ronde identique
   demande un nouveau semis de 137 commandes dans la base partagée ; je ne l'ai pas refait pour ne
   pas perturber davantage les autres sessions qui travaillent dans cet arbre.

## 11. Effets de bord à connaître

- **`reports/goal-caisse-controle-2026-09-02/captures-apres/*.png` et `mesure-pos-v4.json` sont
  modifiés** : l'E2E d'acceptation réécrit ses propres captures à chaque exécution. C'est le banc
  qui les produit, pas moi.
- **Le fichier `storage/backups/db-daily/not-sql.gz` et une dizaine d'autres fichiers modifiés
  (`HealthController.php`, `SyncOverviewController.php`, `SystemHealthComponent.vue`,
  `BackupVerifyRestoreCommand.php`, `RestoreDrillResult.php`, `tests/Feature/Backup/`,
  `tests/Feature/Observability/`, `tests/Feature/Pilotage/`, `tests/Feature/Grok/`,
  `tests/js/outbox*`, `tests/js/systemHealth*`, `reports/grok/`) ne sont PAS de moi** — ils étaient
  déjà dans l'arbre au démarrage ou appartiennent à une autre session.

---

## Verdict

**GREEN** sur le périmètre G1. T1.1 à T1.5 livrées, chacune précédée d'un banc rouge dont la
rougeur est archivée. Zone gelée intacte, `safety-check.sh` PASS, 5 sentinelles de fraîcheur vertes,
Vitest complet vert, E2E d'acceptation 11/11.

Réserves assumées et listées au §10 : bandeau de troncature prouvé par abaissement temporaire des
plafonds plutôt qu'en conditions naturelles, et seconde ronde non jouée.
