# G5 — Couverture du tableau de bord · rapport d'exécution

Date : 2026-09-03 · Branche : `pos/category-first-caisse-2026-06-23`
GOAL : `plans/goals-2026-09-03/G5_DASHBOARD_COUVERTURE.md` (T5.1 → T5.4)
Preuve rouge : `reports/supervision/2026-09-03/G5-bancs-mordent.txt`

---

## 0. Le résultat en une phrase

Six bancs créés, **100 tests JS verts** sur le périmètre du tableau de bord, **12 verts** sur le
contrat de dates — et surtout : **cinq des six composants faisaient réellement passer une panne
pour une journée sans vente**. Ce n'était pas une hypothèse de cadrage, c'est mesuré, et la
contre-épreuve du §3 le prouve.

---

## 1. Re-vérification du constat de code mort — **JE CONFIRME**

Commande et résultat :

```
grep -rn "CustomerStats"  resources/js  →  CustomerStatsComponent.vue:33   (sa propre `name:`)
grep -rn "TopCustomers"   resources/js  →  TopCustomersComponent.vue:29    (sa propre `name:`)
```

Une seule occurrence chacun, dans son propre fichier, sur la ligne `name:` de l'objet composant.
Aucun `import`, aucun enregistrement dans `components: {}`, aucune balise.

J'ai poussé la vérification plus loin que le grep, parce qu'un grep négatif ne suffit pas à
prouver l'absence de montage :

- **Pas d'enregistrement global automatique.** J'ai cherché `require.context` et
  `import.meta.glob` dans tout `resources/js` : la seule occurrence est un **commentaire** dans
  `resources/js/i18n.js:104` qui explique qu'on les évite justement. Il n'existe donc aucun
  mécanisme qui monterait ces composants sans les nommer.
- **`DashboardComponent.vue:73-87`** liste ses imports un par un : les six vivants y sont, ces
  deux-là n'y sont pas.
- **Le dépôt le savait déjà.** `DashboardComponent.vue:67` porte un commentaire sur un autre
  widget : « mais n'était monté nulle part (grep repo-wide : aucun import) ». Et
  `plans/GOAL_ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS_2026-08-26.md:143` (T-3.1.2) avait déjà
  identifié ces deux composants comme orphelins, il y a une semaine, sans trancher.

**Conclusion : constat confirmé, sans réserve.** Les deux composants sont morts.
Je ne les ai **ni touchés ni supprimés** — T5.4 reste une porte propriétaire ouverte.

Une précision qui a son importance pour la décision : `dashboardDatePresetAccessibility.spec.js`
teste et valide `CustomerStatsComponent.vue` (3 tests verts, visibles dans le fichier de preuve).
Du code mort est donc déjà maintenu par une sentinelle vivante — c'est exactement le coût que le
GOAL décrit.

---

## 2. Ce que chaque banc prouve, et s'il a rougi

Tous dans `tests/js/dashboard/`. Chacun couvre les six cas exigés :
succès · liste vide · 403 · 500 · délai dépassé · nettoyage du minuteur.

| Banc | Tests | A rougi ? | Défaut réel trouvé |
|---|---|---|---|
| `channelStatsComponent.spec.js` | 9 | **oui, 8/9** | **Aucun `.catch` du tout** |
| `featuredItemsComponent.spec.js` | 9 | **oui, 7/9** | `catch` muet + état initial `{}` |
| `mostPopularItemsComponent.spec.js` | 9 | **oui, 7/9** | idem (patron copié) |
| `orderStatisticsComponent.spec.js` | 9 | **oui, 8/9** | `catch` muet → dix tuiles blanches |
| `salesSummaryComponent.spec.js` | 9 | **oui, 8/9** | `catch` muet sur le chiffre d'affaires |
| `realtimeReportComponent.spec.js` | 9 | **oui, 7/9** | **drapeau levé, écran inchangé** |

### 2.1 `ChannelStatsComponent` — le plus franc des six

`fetchData()` n'avait **aucun `.catch`**. Une 403/500/coupure laissait `stats` à `[]`, c'est-à-dire
l'écran exact d'une journée sans commande, **et** laissait filer un rejet de promesse non traité.

Ce n'est pas une déduction de lecture : Vitest a remonté, sur la passe rouge, **4 « Unhandled
Rejection »** provenant de ce seul fichier (visibles en fin de `G5-bancs-mordent.txt`, première
section). C'est la mesure indépendante que le §3ter de CLAUDE.md réclame.

Ce que le banc prouve en plus : la barre est une vraie `role="progressbar"` avec
`aria-valuenow/min/max`, et la clé de boucle est le canal — prouvé **par le comportement**, pas
par un grep sur le fichier : on permute les canaux et on vérifie que le nœud DOM de « Web » a
voyagé avec sa donnée au lieu d'être recyclé.

### 2.2 `FeaturedItems` / `MostPopularItems` — le même défaut, copié

Deux défauts cumulés, identiques dans les deux fichiers :

1. état initial `{}` — un **objet** là où le template lit `.length`, qui vaut donc `undefined` ;
   la carte se rendait vide avant même toute réponse ;
2. `.catch` qui ne faisait que couper le voile de chargement, sans rien conserver.

Résultat : 403, 500 et « aucun article » produisaient **le même pixel**. Sur le classement des
ventes, c'est la carte qu'on regarde pour décider quoi mettre en avant.

### 2.3 `OrderStatisticsComponent` — pire qu'indiscernable

Les dix compteurs partent à `null`, que Vue rend comme une **chaîne vide**. Sur échec : dix tuiles
avec leur libellé et du blanc dessous.

Ce n'est pas seulement indiscernable d'une journée à zéro — c'est **pire**. Une vraie journée sans
commande renvoie `0` et affiche « 0 ». Le blanc n'appartient à aucune journée réelle : c'est un
état que le produit ne savait pas nommer, et que l'exploitant lit comme un zéro. Le banc épingle
les deux : la journée réelle à zéro **doit** afficher « 0 », l'échec **doit** afficher « — ».

Le banc couvre aussi le second chemin d'échec, qui manquait au raisonnement initial : un échec
survenant **après un changement de période** (`handleDate`) était tout aussi muet.

### 2.4 `SalesSummaryComponent` — le chiffre d'affaires

Même `catch` muet ; `options` restait `null`, donc la courbe disparaissait entièrement. La carte
du chiffre d'affaires vide se lit « mauvaise journée », pas « tableau de bord aveugle ».

Aggravant, épinglé par le banc : au changement de période, l'échec laissait **la courbe
précédente affichée**. On croyait lire le mois dernier, on lisait encore le mois courant.

### 2.5 `RealtimeReportComponent` — le correctif qui avait l'air appliqué

Le plus instructif des six. Un correctif antérieur avait ajouté un drapeau `failed` pour qu'une
403 ne laisse plus « 0,00 € ». Bien vu. Mais le rendu retenu était :

```js
if (this.failed) return '—';
...
return this.report.average_ticket ?? '—';
```

**Les deux branches rendent le même caractère.** Le drapeau était correctement levé et ne
changeait **rien** à ce que l'exploitant voit. C'est la forme la plus coûteuse du défaut : un
correctif présent, testé nulle part, et sans effet.

Le banc mesure donc l'**écran** et pas le drapeau : il monte deux instances (panne et journée
vide) et exige que leurs textes diffèrent.

---

## 3. La contre-épreuve — le banc mord-il sur le défaut, ou sur mes crochets ?

C'est le piège que ce dépôt a déjà rencontré quatre fois en une nuit (`MEMORY` :
« sentinelle au mauvais périmètre », « prouver qu'un banc mord »). **Une partie des 45 rouges de
la première passe venait simplement de l'absence de `data-testid`, pas du défaut.** Un banc qui ne
rougit que pour ses propres crochets ne prouve rien.

J'ai donc fait la contre-épreuve, et elle est consignée dans la seconde moitié de
`G5-bancs-mordent.txt` :

> Les six composants remis dans leur état **corrigé** — crochets, `aria-hidden`, clés stables,
> tableaux au lieu d'objets — **sauf une seule chose** : `fetchError = true` neutralisé en
> `false` (et `failed = true` en `false`).

**Résultat : 23 tests rouges, 77 verts.** Les 23 rouges sont exactement les cas
403 / 500 / délai dépassé / « l'erreur s'efface au rechargement » des six composants. Tout ce qui
tenait aux crochets est passé au vert ; seul le défaut rougit.

Les six composants ont ensuite été restaurés à l'octet près depuis une copie prise avant la
neutralisation, et `grep -rn "NEUTRALISE"` ne remonte plus rien.

---

## 4. T5.2 — accessibilité et contraste

- **`aria-hidden="true"`** posé sur **13 pictogrammes décoratifs** : 10 dans
  `OrderStatisticsComponent` (les tuiles), 3 dans `SalesSummaryComponent`. Comptés par le script
  de transformation, pas estimés.
- **Clés stables** : `stat.name` (canal), `featured_item.id`, `popular_item.id`. Plus aucun
  `index`, plus aucun objet comme clé. Vérifié **par le comportement** (permutation → identité du
  nœud DOM), pas par lecture du fichier.
- **`role="progressbar"` + `aria-valuenow/min/max` + `aria-label`** sur les barres de
  `ChannelStatsComponent`.
- **Contraste du Ticket Moyen** : le correctif du 2026-09-02 (`text-white` sur le libellé **et**
  sur la valeur, parce que `public/css/app.css` porte `h1..h6 { color: rgb(31 31 57) }` qui bat la
  couleur héritée) a **enfin une assertion** —
  `realtimeReportComponent.spec.js`, test « le Ticket Moyen porte `text-white` … (WCAG 2.1 AA) ».

  **Je suis explicite sur ce que ce test vaut** : il vérifie les **classes**, pas les pixels. Il a
  rougi uniquement parce que les `data-testid` manquaient — les classes, elles, étaient déjà
  correctes. C'est donc de la **couverture pure d'un correctif déjà juste**, pas la découverte d'un
  défaut. La mesure de contraste réelle en navigateur que demandait le GOAL n'a **pas** été faite ;
  voir §7.

---

## 5. T5.3 — les bords du contrat de dates, nommés

`tests/Feature/Dashboard/DashboardDateContractMatrixTest.php` — **12 tests verts** (6 existants
+ 6 ajoutés), sur les 4 points d'entrée.

Le test existant « plus de 366 jours » passait une période de **six ans** : il prouve qu'un abus
grossier est refusé, il ne dit rien de l'endroit exact où le refus commence.

**La métrique est le sujet.** `DashboardService::assertSalesDateWindow()` compare
`$first->diffInDays($last)` — un nombre d'**intervalles** — à 366, tandis que son message parle de
« 366 jours ». Les deux comptages diffèrent toujours d'exactement un. Bords mesurés en PHP réel
avant d'écrire quoi que ce soit :

| Période | `diffInDays` | Jours **inclusifs** | Comportement réel |
|---|---|---|---|
| 2026-01-01 → 2027-01-01 | 365 | **366** | 200 — la limite annoncée |
| 2026-01-01 → 2027-01-02 | 366 | **367** | **200** — un jour au-delà du message |
| 2026-01-01 → 2027-01-03 | 367 | **368** | 422 — premier refus réel |

Et `first_date=0&last_date=0` : `empty('0')` vaut `true` en PHP, donc les deux bornes sont jugées
**absentes** avant d'atteindre `jourCivilParisStrict()` qui les aurait refusées → **200** avec la
période par défaut. Corollaire cohérent, également épinglé : « 0 » sur une **seule** borne
redevient une borne isolée → 422.

**Je n'ai rien corrigé, conformément à la consigne.** Aucune conséquence produit n'est démontrée :
le sélecteur de dates n'émet jamais « 0 » (`dashboardDateEnvoyeeEnJourCivil.spec.js`), aucun
préréglage ne dépasse l'année, et une période d'un jour de trop rend un résultat juste. Les bancs
**consignent** l'écart pour qu'il cesse d'être invisible — et pour qu'on ne le « corrige » pas par
accident en croyant ne rien changer.

J'ai ajouté un bord d'en bas non demandé mais évident : période d'**un seul jour** (`first == last`),
qui est le préréglage « Aujourd'hui », le plus utilisé de l'écran.

---

## 6. Résultats de vérification

| Suite | Résultat |
|---|---|
| `tests/js/dashboard` (6 bancs + 3 existants) | **100 / 100 verts** |
| Non-régression JS ciblée (22 fichiers) | **199 / 199 verts** |
| `DashboardDateContractMatrixTest` | **12 / 12 verts** |
| `DashboardRoutesAuthzMatrixTest` + `PopularItemsFailClosedTest` | **9 / 9 verts** |
| Sentinelle clés françaises · icônes fantômes · axe-core | **verts** |
| **Suite Vitest complète** | **4369 verts · 3 ignorés · 2 rouges** (voir ci-dessous) |

### `bash .cursor/hooks/safety-check.sh` → **PASS**

```
[safety-check] Checking 15 frozen zones...
[safety-check] Frozen zones: OK
[safety-check] Checking PHP syntax...
[safety-check] No staged PHP files.
[safety-check] Passed. Proceed with execution.
```

### Les 2 rouges de la suite complète — je ne les masque pas

`appBundleFreshnessSentinel` et `posAppBundleFreshnessSentinel` échouent : le paquet compilé
`public/js/app.js` (02:10:26) est plus ancien que ses sources.

C'est **attendu et voulu** — la consigne interdit `npm run production`. Ces deux sentinelles
disent précisément la chose que j'ai vérifiée à la main au §7. Attribution honnête : **les sources
plus récentes que le paquet ne sont pas toutes les miennes** —

```
02:33:49  mes 6 composants du tableau de bord
02:29:13  resources/js/languages/en.json          (moi)
02:28:15  admin/observability/OutboxOverviewComponent.vue     (autre session)
02:36:40  admin/observability/SystemHealthComponent.vue       (autre session)
```

Ces deux sentinelles passeront au vert à la compilation.

---

## 7. Ce que je n'ai PAS pu faire

**La campagne visuelle du GOAL n'a pas eu lieu, et une capture aurait été un mensonge.**

Deux raisons, toutes deux vérifiées et non supposées :

1. **Le paquet servi ne contient pas mon correctif.** `public/js/app.js` date de 02:10:26, mes
   composants de 02:33:49, et `grep -c "channel-stats-error" public/js/app.js` → **0**. Le serveur
   de `:8766` répond bien 200, mais il sert l'**ancien** code. Une capture aurait mesuré la couche
   d'avant le correctif et n'aurait rien prouvé — exactement le piège « mesurer la couche que la
   surface consomme ». La compilation m'étant explicitement interdite, je préfère l'absence de
   preuve à une preuve fausse.
2. **Les serveurs MCP Playwright / Chrome DevTools n'ont pas démarré** dans cette session
   (`CONNECTION_CLOSED` sur `playwright`, `plugin:playwright:playwright` et
   `plugin:chrome-devtools`). C'est une panne de connexion, pas une capacité absente.

**Conséquence directe : la mesure de contraste réelle en navigateur du Ticket Moyen (T5.2) reste
à faire.** Ce que j'apporte est l'assertion sur les classes ; le rapport de contraste
`rgb(255,255,255)` sur `rgb(26,26,26)` n'a pas été relu dans un navigateur par moi.

**À faire après compilation** (`npm run production` par le propriétaire) :
`/admin/dashboard` en 390×844, 768×1024, 1366×768 ; vérifier que les nouveaux messages d'erreur et
d'état vide s'affichent en français, sans libellé brut, et mesurer le contraste du Ticket Moyen.

**T5.4 non exécutée** — c'est une porte propriétaire, et le GOAL le dit. Les deux composants morts
sont intacts.

**Seconde ronde identique (condition de sortie du GOAL) : non tenue.** J'ai une passe verte et une
contre-épreuve, pas deux rondes séparées.

---

## 8. Incident à signaler — mes clés `fr.json` ont été emportées par un autre commit

Je n'ai **rien commité**, conformément à la consigne. Mais `HEAD` a bougé pendant mon travail :
`28cd79d5a` → `39fffecad` (« fix(caisse): le tiroir jetait les commandes les plus anciennes »).

Ce commit d'une **autre session** a emporté `resources/js/languages/fr.json`, et avec lui **mes 6
clés de traduction** :

```
+ "featured_items_error"      + "featured_items_empty"
+ "most_popular_items_error"  + "most_popular_items_empty"
+ "sales_summary_error"       + "order_statistics_error"
```

(deux lignes de ce diff sont les leurs : `cuisine_libre`, `troncature`).

Les clés sont correctes et présentes **une seule fois** chacune — j'ai compté, arbre et `HEAD`
concordent. Rien n'est cassé. Mais l'attribution est fausse : six lignes de G5 vivent dans un
commit G1. Je ne défais rien — cela perturberait une session en cours, et la consigne me
l'interdit. `en.json` (6 clés symétriques) reste, lui, non commité.

C'est le piège connu du dépôt : « commit par pathspec en arbre partagé ».

---

## 9. Fichiers touchés

### Modifiés — mon périmètre exclusivement

| Fichier | Δ | Objet |
|---|---|---|
| `resources/js/components/admin/dashboard/ChannelStatsComponent.vue` | +45 −8 | `.catch` créé, état d'erreur/vide, `progressbar`, clé stable |
| `resources/js/components/admin/dashboard/FeaturedItemsComponent.vue` | +34 −8 | `[]` au lieu de `{}`, état d'erreur/vide, clé `id` |
| `resources/js/components/admin/dashboard/MostPopularItemsComponent.vue` | +32 −8 | idem |
| `resources/js/components/admin/dashboard/OrderStatisticsComponent.vue` | +50 −22 | `fetchError`, `chiffre()`, 10 `data-testid`, 10 `aria-hidden` |
| `resources/js/components/admin/dashboard/RealtimeReportComponent.vue` | +21 −4 | bannière d'erreur visible, 3 `data-testid` |
| `resources/js/components/admin/dashboard/SalesSummaryComponent.vue` | +33 −6 | `fetchError`, `montant()`, 3 `aria-hidden` |
| `resources/js/languages/en.json` | +6 | clés miroir |
| `resources/js/languages/fr.json` | +6 | **emportées par le commit `39fffecad`** (§8) |
| `tests/Feature/Dashboard/DashboardDateContractMatrixTest.php` | +115 | T5.3 — 6 cas de bord nommés |

### Créés

```
tests/js/dashboard/channelStatsComponent.spec.js
tests/js/dashboard/featuredItemsComponent.spec.js
tests/js/dashboard/mostPopularItemsComponent.spec.js
tests/js/dashboard/orderStatisticsComponent.spec.js
tests/js/dashboard/realtimeReportComponent.spec.js
tests/js/dashboard/salesSummaryComponent.spec.js
reports/supervision/2026-09-03/G5-bancs-mordent.txt
reports/supervision/2026-09-03/G5-RAPPORT.md
```

### Fichiers interdits — aucun touché

Vérifié par `git diff --name-only` sur mon périmètre : rien dans `admin/pos/**`,
`PosOrderController.php`, `SyncOverviewController.php`, `admin/observability/**`,
`app/Support/Backup/**`, `app/Console/Commands/Backup/**`, `HealthController.php`,
`InterrupteurController.php`. Ces fichiers apparaissent modifiés dans `git status` — c'est le
travail des **autres sessions** de l'arbre partagé, pas le mien.

**Zone gelée : 0 ligne.** `safety-check.sh` → PASS.
**Aucun commit de ma part.**
