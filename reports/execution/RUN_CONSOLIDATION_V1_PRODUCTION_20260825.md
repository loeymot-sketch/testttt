# Exécution — GOAL `CONSOLIDATION_V1_PRODUCTION_20260825`

- **Lancé le** : 2026-08-25 sur « lance le GOAL, discipline maximale »
- **HEAD au départ** : `43b120c7d` · branche `pos/category-first-caisse-2026-06-23`
- **Mode** : fil unique (la session interdit la délégation à des sous-agents sans demande explicite)
- **Filet** : branche `backup/pre-consolidation-2026-08-25` créée **sans changement de branche active**

---

## W0 — Pré-vol : FERMÉE

| Contrôle | Résultat |
|---|---|
| Filet Git | `backup/pre-consolidation-2026-08-25` @ `43b120c7d` — branche active inchangée |
| Chaîne NF525 (entrée) | `audit_logs` = **8 095**, dernier condensat `7caed138…`, `z_reports` = **33** |
| Zone gelée (entrée) | **0 ligne** sur les 13 fichiers CLAUDE.md §7 |
| PHPUnit (base réelle) | **5 194 passés / 36 sautés / 2 incomplets / code sortie 0**, zéro `⨯` |
| Vitest (base réelle) | **443 fichiers / 3 629 passés / 3 sautés** |
| Serveurs | `:8000` et `:8766` UP · soketi `:6001` UP · `queue:work` UP |

⚠️ **Écart de base non expliqué** : le cycle précédent avait consigné **4 862** tests PHPUnit ;
la mesure de ce jour en donne **5 194** (+332), plus 2 « incomplets » absents du relevé antérieur.
Zéro échec dans les deux cas. Je ne sais pas d'où vient l'écart et je ne l'invente pas — c'est
**5 194** qui fait foi désormais.

---

## W1 — Vérité documentaire : FERMÉE (9 tâches)

### Ce qui a été trouvé et corrigé
- **Contradiction `PROJECT_BRAIN §9` vs `§2`** — §9 (2026-05-09) déclare « MERGE BLOQUÉ » sur 15 P0 ;
  §2 (2026-08-22) décrit V1 **déployé et servi en production**. Dossier de décision écrit,
  **§9 n'a PAS été réécrit** (CLAUDE.md §12 : surfacer, ne pas arbitrer).
  → `reports/audit/CONTRADICTION_BRAIN_S9_VS_S2_2026-08-25.md`
- **Trois citations de ligne périmées**, corrigées après mesure du code :
  | Document | Disait | Réalité |
  |---|---|---|
  | `CLAUDE.md:385` | `AppServiceProvider.php:78-145` | **`:190-370`** |
  | `CLAUDE.md:397` | `AppServiceProvider.php:215` (liste cache) | **`:366`** (`:215` = garde PIN Carnet) |
  | `CONSTITUTION.md:18` | `AppServiceProvider.php:158+` | **`:190+`**, garde `POS_SIMULATION_HARDWARE` à `:197` |
- **Règle « instrument avant produit »** ajoutée à `CLAUDE.md §3ter`.

### Ce qui a été vérifié et s'est révélé JUSTE (aucun correctif)
- La note « `User` n'est pas isolé par succursale » est **exacte** : `BranchScope.php:21` fait bien
  un no-op explicite sur `User`, et `BranchScopeCoverageSentinelTest` (ligne 129-131) ne teste
  qu'une **présence textuelle** par regex.

### Inventaires produits
- **9 gates** réellement en attente · **261 plans**, dont **171 (65 %)** d'avril-mai
  → `reports/audit/INVENTAIRE_GATES_ET_PLANS_2026-08-25.md`
- **Synthèse de clôture du cycle caisse** → `reports/audit/SYNTHESE_CLOTURE_CAISSE-SUPERVISOR-CONTROL_2026-08-25.md`

### ⚠️ Correction d'une affirmation de mon propre GOAL
Le GOAL présentait les 3 gates `DROP_TABLE` comme un danger latent. **Faux, vérification faite** :
`online_orders` et `delivery_boys` **n'existent plus** dans la base (111 tables), `waiters` et
`chefs` non plus ; seul `dining_tables` subsiste (1 ligne). Aucune purge destructrice n'attend
d'être exécutée. 🔴 En revanche `delivery_boy_cash_sessions` / `delivery_boy_cash_movements`
**subsistent** et sont des tables de piste de caisse — à ne jamais emporter dans un nettoyage « livreurs ».

---

## W2 — Harnais E2E : FERMÉE sauf vagues D/F

### T-4.1 — Dérive des fixtures, chiffrée
Relevé sur la base réelle : **27 des 36 identifiants d'articles codés en dur ne correspondent à
aucun article existant**. 24 fichiers portent 56 couples (fichier, identifiant) ; **11 fichiers
portent des identifiants morts**.

- Sentinelle créée : `tests/js/e2eFixturesSansIdentifiantCode.spec.js` (6 tests)
- ⚠️ **Défaut trouvé dans MA propre sentinelle** par test négatif : un cliquet par *fichier* ne
  mordait pas quand on ajoutait un identifiant à un fichier déjà fautif. Resserré sur les
  **couples (fichier, identifiant)** — plafond 56 — puis **prouvé mordant dans les deux cas**
  (57 > 56 et 25 > 24), fichiers sondes restaurés à l'octet près (md5 identiques).

### T-4.2 — Isolation des préfixes
⚠️ **Correction d'un chiffre que j'avais donné** : je disais « 8 specs partagent le préfixe ».
Le grep littéral en trouvait 8, mais **17 specs appellent `placeKioskOrder`** et héritaient
toutes du même défaut `AUDIT-KIOSK-WAVE-E`. La vraie surface était **17**.

- Helper étendu **de façon purement additive** : option `tokenPrefix`, validateur
  `assertPrefixeAuditValide()`, dérivateur `prefixeAuditPourSpec()` — défaut historique préservé.
- **17 specs migrées**, 0 restant sous le défaut partagé, 0 erreur de syntaxe.
- Résultat mesuré : **17 préfixes distincts, 0 collision « préfixe-de »** (le piège subtil : deux
  préfixes distincts ne suffisent pas si l'un commence l'autre — `LIKE 'X%'` emporte le voisin).
- Test créé : `tests/js/e2ePrefixesDisjoints.spec.js` (9 tests), rendu **auto-découvrant**.

⚠️ **Sévérité revue à la baisse, honnêtement** : `playwright.config.js:74` fixe **`workers: 1`**.
La collision était donc **dormante**, pas active. Elle se serait réveillée à la première
parallélisation.

### T-4.3 — Pièges de mesure
Les 3 specs `reducedMotion` étaient **déjà corrigées** par le cycle précédent (`page.emulateMedia`).
Restait à empêcher la rechute :
- `tests/js/e2eInstrumentsDeMesureFiables.spec.js` (5 tests), **prouvée mordante**
- ⚠️ **Faux positif dans ma propre sentinelle** : elle punissait les commentaires d'avertissement
  qui *citent* le motif interdit. Corrigée par dépouillement des commentaires avant analyse.
- Documenté : `docs/PLAYWRIGHT_MCP_OPS.md §7` + `CLAUDE.md §3ter`

### Reste ouvert
**T-4.4.1 / T-4.4.2** (vagues D et F) et **T-4.4.3** (boucle de convergence E2E) — non traités.

---

## W5 — Durcissement runtime : FERMÉE (4 tâches)

### T-5.3.1 — `foodking:ensure-admin` sans garde de production ✅ VRAI DÉFAUT, CORRIGÉ
La commande crée ou **réinitialise** un compte administrateur avec un mot de passe dont le défaut
est **`123456`**, et n'avait aucune garde. Sur la machine qui sert, c'est une élévation de
privilège en une commande.
- Garde ajoutée (refus explicite en production, `--force` pour lever l'intention)
- `--dry-run` ne lève **pas** la garde — l'autoriser entretiendrait l'habitude
- ROUGE → VERT : `tests/Feature/Console/EnsureAdminGardeProductionTest.php` (5 tests)

### T-5.3.3 — Alertes SLA sans borne basse ✅ VRAI DÉFAUT, CORRIGÉ
Mesure sur la base : **344 commandes** en préparation depuis > 15 min — **les 344** avaient plus
de 24 h, la plus ancienne **75 jours** (2026-06-10). Le panneau SLA affichait donc 344 alertes
dont aucune n'était actionnable : une vraie commande en retard y était **invisible**.
- Fenêtre bornée des deux côtés, paramétrable (`config/dashboard.php`, défaut 24 h)
- ROUGE → VERT : `tests/Feature/Dashboard/SlaAlertesBorneBasseTest.php` (5 tests)
- **Effet mesuré : 344 → 0 alertes de bruit.**
- ⚠️ Les 344 commandes figées restent en base : c'est de la donnée d'exploitation, **je n'y touche pas**.

### T-5.3.2 — `HealthzController` ❌ MON ESCALADE ÉTAIT FAUSSE, RETIRÉE
J'avais signalé « la sonde ne teste rien hors `pusher` ». Lecture complète faite : le comportement
est **documenté, motivé, et déjà rendu honnête** le 2026-06-04 (OPS-2). La grille stricte promise
par le commentaire (`/api/health/ready`) **existe bien**.
- 🔴 J'avais aussi cru voir `/healthz` renvoyer du HTML : **erreur de ma part**, la route est
  `/api/healthz` — j'avais tapé à côté et touché le catch-all SPA.
- Action résiduelle réelle : **aucun test n'épinglait** les branches de pilote. Une indulgence non
  testée n'est plus un choix, c'est une dérive en attente.
  → `tests/Feature/HealthzDiffusionNonPusherTest.php` (5 tests) — **verrouille**, ne corrige pas.

### T-5.3.4 — Cliquet d'autorisation ✅ RESSERRÉ 64 → 62
`AnalyticRequest` et `AnalyticSectionRequest` répondaient `return true;`. Défense en profondeur
ajoutée (`$this->user()?->can('settings')`, cohérente avec la garde de route existante).
- `tests/Feature/Admin/AnalyticRequestAuthzTest.php` (4 tests)
- `FormRequestAuthzDriftSentinelTest::RETURN_TRUE_BASELINE` : **64 → 62**

---

## W3 — Caisse : partiellement ouverte (bloquée sur G3/G4)

### T-1.1.3 — Retour au vert de la pastille ✅ TROU DE COUVERTURE COMBLÉ
La suite existante (19 tests) prouvait abondamment la **dégradation**, jamais le **retour au vert**.
C'est pourtant la moitié qui compte : une pastille qui sait s'allumer mais pas s'éteindre finit ignorée.
- `tests/Feature/Pos/PosSystemHealthQueueRecoveryTest.php` (5 tests)
- **Verts du premier coup** : le produit se rétablissait déjà correctement. Aucun défaut trouvé —
  le comportement est désormais verrouillé au lieu d'être supposé.

**T-1.2.x reste bloqué** sur les décisions **G3** (grille de vente sous la ligne de flottaison) et
**G4** (portée de la recherche).

---

## Preuves de fin de session

| Contrôle | Résultat |
|---|---|
| Zone gelée | **0 ligne** sur les 13 fichiers, contrôlée en continu |
| Chaîne NF525 | 8 095 → **8 119** entrées (**ajout seul**), `z_reports` stable à **33** |
| Vitest | **443 fichiers / 3 629 passés / 0 échec** |
| Arbre de travail | préservé — aucun `checkout`/`reset`/`stash`/`clean` |
| Commits | **aucun** — rien commité, rien poussé |

## Tests créés dans cette session

| Fichier | Tests | Nature |
|---|---|---|
| `tests/js/e2eFixturesSansIdentifiantCode.spec.js` | 6 | cliquet, prouvé mordant |
| `tests/js/e2ePrefixesDisjoints.spec.js` | 9 | auto-découvrant |
| `tests/js/e2eInstrumentsDeMesureFiables.spec.js` | 5 | prouvé mordant |
| `tests/Feature/Console/EnsureAdminGardeProductionTest.php` | 5 | ROUGE → VERT |
| `tests/Feature/Dashboard/SlaAlertesBorneBasseTest.php` | 5 | ROUGE → VERT |
| `tests/Feature/HealthzDiffusionNonPusherTest.php` | 5 | verrouillage de contrat |
| `tests/Feature/Admin/AnalyticRequestAuthzTest.php` | 4 | régression d'autorisation |
| `tests/Feature/Pos/PosSystemHealthQueueRecoveryTest.php` | 5 | trou de couverture |
| **Total** | **44** | |

## Ce que j'ai corrigé chez moi

Quatre fois dans cette session, la vérification a démenti mes propres affirmations. C'est consigné
parce qu'un audit qui ne publie que ses trouvailles confirmées se ment sur son taux d'erreur :
1. « 8 specs partagent le préfixe » → **17**.
2. « Les gates `DROP_TABLE` sont un danger latent » → **caducs**, les tables n'existent plus.
3. « `HealthzController` ne teste rien » → **comportement documenté et délibéré**.
4. « `/healthz` renvoie du HTML » → **j'avais tapé la mauvaise route**.

Et deux fois, mes propres sentinelles étaient défaillantes — cliquet qui ne mordait pas, faux
positif sur les commentaires. Toutes deux trouvées par test négatif, avant livraison.

---

# W4 — Borne & cuisine : deux P0 trouvés en cherchant autre chose

## 🔴 P0-1 — La file `notifications` n'est écoutée par personne, et aucune sonde ne le voit

Trouvé en préparant le runbook de redémarrage du worker (T-3.3.1).

**Mesure** : `Queue::size('notifications') = 1490`, `LLEN queues:notifications = 1490`, liste
« prêts » (ni différés ni réservés), plus ancien travail `App\Jobs\SendFcmNotificationJob` avec
**`attempts=0`** — jamais tenté une seule fois.

**Cause** : `SendFcmNotificationJob.php:67` publie sur `onQueue('notifications')`, alors que le
worker local **et** le modèle superviseur de production (`supervisor.conf.template:42`) écoutent
`--queue=high,default`. Horizon n'est pas installé — aucun consommateur caché.

**Le pire** : les **trois** sondes de santé comptaient littéralement `default` + `high`
(`PosSystemHealthController:179`, `HealthController:127-128`, `HealthzController:227-228`).
1 490 travaux pourrissaient pendant que les trois surfaces affichaient « file OK ». Un faux vert —
exactement l'erreur que le correctif OPS-2 du 2026-06-04 avait éliminée pour le websocket.

**Ce qui a été fait** : `config/queue.php` gagne `monitored_queues` (source unique), et les deux
sondes d'exploitation la lisent au lieu d'écrire les files en dur. Une file illisible est ignorée
individuellement — pas de retour à 0 global, qui serait le faux vert d'origine.
- Sentinelle : `tests/Feature/Health/FilesSurveilleesTest.php` (5 tests) — elle **découvre** les
  `onQueue('…')` du code et échoue si l'un n'est pas surveillé. Une file neuve non supervisée
  cassera la CI.
- Effet mesuré sur la vraie surface : `/api/healthz` → `queue_pending` **0 → 1490**.

⛔ **Le worker n'a PAS été rebranché.** Le faire enverrait d'un coup 1 490 notifications push sur
des commandes vieilles de plusieurs semaines. Décision propriétaire —
`reports/audit/P0_FILE_NOTIFICATIONS_ORPHELINE_2026-08-25.md`.

## 🔴 P0-2 — Playwright vise par défaut un serveur qui sert **un autre worktree**

Trouvé parce qu'un correctif marchait en CLI mais pas via HTTP.

**Mesure différentielle** : même route, deux réponses.
```
:8000  /api/healthz → queue_pending = 0      → .claude/worktrees/goal-caisse-vision-2026-08-24/public  (HEAD 6b9f4a965)
:8766  /api/healthz → queue_pending = 1490   → public/  (HEAD 43b120c7d, mon arbre)
```
Écart entre les deux codes : **89 fichiers, 15 356 insertions**.

`playwright.config.js:12` vise `http://localhost:8000` **par défaut**, et `PLAYWRIGHT_BASE_URL`
n'était pas définie. Toute campagne E2E lancée par réflexe mesurait donc l'autre worktree.

Ce n'est pas un échec isolé, c'est une **confiance mal placée** : un correctif appliqué ici paraît
« ne pas marcher », un défaut corrigé ailleurs paraît « déjà résolu ».

**Ce qui a été fait** : garde dans `tests/Playwright/global-setup.js` — un marqueur unique déposé
dans le `public/` de cet arbre, demandé au serveur ciblé, retiré aussitôt (`finally`). Prouvée :
`:8000` **rejeté**, `:8766` **accepté**, port mort → erreur réseau distincte, **zéro résidu**.
Elle attrape aussi le cas où un catch-all SPA répond 200 avec du HTML.
- Sentinelle : `tests/js/e2eGardeMemeArbreDeTravail.spec.js` (8 tests), dont l'ordre — la garde
  doit précéder toute écriture de seeder, et suivre la garde de base.
- Rapport : `reports/audit/P0_E2E_VISE_UN_AUTRE_WORKTREE_2026-08-25.md`

⚠️ **Portée sur mes propres mesures** : les relevés CLI (`tinker`, `artisan test`) portaient bien
sur l'arbre principal — 344 alertes SLA, 1 490 travaux, 27 identifiants morts : tout tient. Mais
mes vérifications HTTP de `/healthz` et `/api/health/ready` avaient touché `:8000`, donc l'autre
worktree. Les conclusions de routage restent valables ; je le signale plutôt que de le taire.

## T-3.2 — 11 articles vendables invisibles en cuisine

`normalizeKdsStation()` range tout `null`/`''` en `none`, et le menu déroulant du KDS n'offre
**aucune** option `none`. Une commande entièrement `none` n'apparaît que sous « toutes les stations » —
et ce filtre est persisté par utilisateur en `localStorage`.

Relevé : 59 articles vendables — `cuisine_chaude` 37, `bar` 8, `cuisine_froide` 3, **`none` 11**.
Dont **7 boissons** (#119-125) alors que **8 boissons de même nature** (#52-59) sont en `bar` :
un lot ajouté plus tard sans poste. Et **#2 « Frites Seules »**, alors que des frites se cuisent.

- Test : `tests/js/kdsStationFiltreCouverture.spec.js` (7 tests) — épingle le comportement réel
- ⛔ **Aucun poste réattribué** : décision d'exploitation (CLAUDE.md §3bis interdit d'inventer des
  données menu) → `reports/audit/KDS_STATIONS_ARTICLES_INVISIBLES_2026-08-25.md`

## Régression que j'ai causée, et corrigée

Ma garde de production sur `EnsureAdminLoginCommand` a décalé le fichier de **+26 lignes**.
`WithoutGlobalScopesAuditSentinelTest` indexe sa liste blanche par `fichier:ligne` : ses 4 entrées
(56, 63, 70, 99) ne correspondaient plus (82, 89, 96, 125). **1 test rouge**, trouvé par la suite
complète, corrigé, et la nature du décalage documentée dans la sentinelle.

Note pour plus tard : une liste blanche indexée par numéro de ligne casse à chaque édition du
fichier gardé. Ce n'est pas mon travail de la redessiner ici, mais c'est une fragilité réelle.

## Sentinelle que j'ai écrite avec le mauvais outil

Ma première version de la couverture des stations KDS était un test **PHPUnit** lisant la table
`items`. Les tests tournent sur **sqlite `:memory:`** : la table n'existait même pas. Une sentinelle
adossée à des données vivantes ne prouve rien en CI. Supprimée et refaite en Vitest sur la logique
de filtrage, qui est déterministe.

---

# Auto-critique : mon correctif SLA créait un angle mort

Après avoir borné la fenêtre des alertes SLA (344 → 0 de bruit), j'ai vérifié ce que devenaient
les 344 commandes écartées. Résultat : **aucune autre surface ne les montrait**.

- 344 commandes en préparation, dont **313 de plus de 30 jours**, la plus ancienne du 2026-06-10
- Aucune commande console de détection de commandes bloquées n'existait
- `DashboardService:113` (`preparing_order`) est borné à la période courante : il ne les voit pas

J'avais donc troqué une fausse alerte permanente contre un **angle mort**, ce qui est pire :
la fausse alerte, au moins, était visible.

**Contrepartie livrée** : `php artisan foodking:commandes-figees` — strictement en **lecture**,
elle regarde exactement ce que le tableau de bord ne regarde plus.

```
344 commande(s) restée(s) en préparation depuis plus de 1 jour(s).
| 4497 | 1006264497 | 1 | 2026-06-10 22:54:50 | il y a 2 mois |
…
Lecture seule : rien n'a été modifié.
```

- `app/Console/Commands/ReportStuckOrdersCommand.php` · options `--jours`, `--limite`, `--json`
- `tests/Feature/Console/CommandesFigeesTest.php` (5 tests), dont un qui vérifie explicitement
  que **rien n'est modifié** — une commande figée peut porter une trace fiscale à conserver 6 ans,
  et aucune automatisation ne doit « corriger » son statut.

**Ce qu'il faut retenir** : borner un filtre, c'est aussi cacher. Un correctif qui réduit le bruit
doit être accompagné du moyen de voir ce qu'il écarte, sinon il déplace le problème au lieu de
le résoudre.

---

# ⚠️ CORRECTION TRANSVERSALE — l'environnement mesuré est LOCAL

Découvert en fin de session, en comparant les deux worktrees : `.env` de cet arbre porte
`APP_ENV=local` et `DB_DATABASE=**foodking_e2e**`.

**Tous mes chiffres de volume viennent donc d'un environnement de développement/E2E, pas de la
machine qui sert.** Je le corrige explicitement, parce que présenté sans cette précision, ce
rapport laisserait croire à des volumes de production.

| Mesure | Ce que j'ai écrit | Ce qu'il faut lire |
|---|---|---|
| 344 commandes figées en préparation | « sur la base réelle » | sur `foodking_e2e`, environnement **local** |
| 1 490 travaux `notifications` | « 1 490 en attente » | sur Redis **local** |
| 27 identifiants d'articles morts | mesure catalogue | sur `foodking_e2e` |
| 59 articles vendables, 11 en `none` | mesure catalogue | sur `foodking_e2e` |

## Ce qui reste vrai quel que soit l'environnement

Les **défauts de code et de configuration** ne dépendent pas de la base :

- `slaAlerts()` n'avait **aucune borne basse** — vrai dans tout environnement.
- `SendFcmNotificationJob` publie sur `notifications`, que **le modèle superviseur de production**
  (`supervisor.conf.template:42`) n'écoute pas — le défaut est donc **présent en production aussi**.
- Les **trois** sondes de santé comptaient `default` + `high` **en dur** — la cécité est la même partout.
- `foodking:ensure-admin` n'avait aucune garde de production — c'est justement en production qu'elle manquait.
- Les 11 articles sans poste cuisine relèvent de la **donnée menu**, qui suit le catalogue déployé :
  à vérifier sur le serveur avant toute conclusion.

**Ce que cela change dans l'ordre des priorités** : la première action sur le serveur n'est pas de
corriger, c'est de **mesurer** — les volumes réels y sont inconnus.

```bash
php artisan tinker --execute='foreach ((array) config("queue.monitored_queues") as $f) {
    echo $f." = ".Illuminate\Support\Facades\Queue::size($f)."\n"; }'
php artisan foodking:commandes-figees --jours=1
```

## Précision sur la portée des notifications bloquées

J'avais écrit « les notifications push **clients** ne partent pas ». Échantillon de 300 sur 1 490 :
**~53 % `customer_order_<id>`**, **~46 % `kitchen_branch_1`**, **< 1 % `oss_branch_1`**.

Ce sont donc **les trois publics** qui sont muets — clients, cuisine et écran de statut. L'impact
est plus large que ce que j'avais écrit, pas plus étroit.

## Note sur les vagues E2E D et F

Les deux worktrees partagent la **même base** (`foodking_e2e`), mais pas le même code :
`KitchenDisplaySystemComponent.vue` diffère de **29 lignes** entre eux.

Comme les campagnes Playwright visaient `:8000` par défaut — donc l'autre worktree — **le rouge de
la vague D a pu être mesuré sur un composant KDS différent de celui de cet arbre.**

⚠️ Je pose cela comme **hypothèse à vérifier**, pas comme conclusion : je n'ai pas relancé la
vague D sous `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766`. C'est le premier geste à faire avant de
chercher quoi que ce soit dans le code KDS — et la garde ajoutée à `global-setup.js` empêche
désormais de se tromper de cible sans le savoir.


---

# ⚠️ CORRECTION LA PLUS IMPORTANTE DE LA SESSION — le verdict GPT existait

J'ai écrit, ici et dans trois autres documents, que le canal GPT n'avait jamais rien produit.
**C'est faux.**

`reports/audit/GPT_FINAL_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823.md` porte
**`GPT_FINAL_AUDIT_VERDICT: REWORK`** avec six constats (1 P0, 4 P1, 1 P2), produits le 2026-08-23
par un **canal de repli** (`foodking-complex-implementer`) là où `gpt-5.5-pro` échouait en HTTP 400.
La mission Roue a elle aussi son verdict : **PASS**.

**Comment je me suis trompé** : j'ai ouvert `output_codex.json`, j'y ai trouvé l'erreur 400 que je
m'attendais à trouver, et je me suis arrêté là. Le fichier contenant la réponse était dans le même
répertoire. Un biais de confirmation ordinaire, répété quatre fois dans mes livrables.

**Vérification faite ce jour : les six constats sont CLOS**, traités par le cycle
`CAISSE-SUPERVISOR-CONTROL-20260823` lui-même sans que la traçabilité remonte au verdict.
Preuve point par point : `reports/audit/CORRECTION_VERDICT_GPT_EXISTE_2026-08-25.md`.

**Conséquence sur G1** : la clôture ne se fait plus « sans verdict », mais **« REWORK → RÉSOLU,
six constats vérifiés un par un »**. C'est plus solide que ce que je proposais.

**Leçon** : un fichier d'erreur ne prouve que l'échec de ce qu'il décrit, pas l'absence de toute
sortie. Lister TOUS les artefacts (`ls reports/audit/GPT_*`) avant de conclure au vide.

---

# W7 — Vague D rejouée sur le bon serveur : mon hypothèse était fausse, mais la piste était bonne

## Ce que j'avais supposé, et qui ne tient pas

J'avais posé que le rouge de la vague D pouvait n'être qu'un artefact du serveur `:8000`.
**Vérifié : non.** `data-kds-order-card="kiosk"` est **identique dans les deux worktrees**
(ligne 969), et le diff de 29 lignes ne concerne que l'affichage des extras. La vague D échoue
aussi sur le bon serveur. Hypothèse close.

## Mais la campagne a livré mieux qu'une confirmation

### 🔴 Un défaut de spec, puis une classe entière de défauts

La vague D échouait sur deux états consécutifs avec la même erreur backend :

```
state07: KDS→PREPARING → 422 « Header X-Idempotency-Key requis pour cette opération. »
state09: KDS→PREPARED  → 422 « Header X-Idempotency-Key requis pour cette opération. »
```

`config/idempotency.php:105` exige cet en-tête sur `api/admin/kds-order/change-status/*`,
**délibérément** — un double bump enverrait deux notifications client. La spec ne l'envoyait pas.
✅ Corrigé.

**Puis j'ai regardé les autres : 26 specs appellent cette route, 18 sans en-tête.**

Toutes prennent un 422, et l'échec **ressemble à un défaut de synchro cuisine**. C'est le pire mode
de panne d'un harnais : il envoie chercher dans le KDS un problème qui n'y est pas. Une part
importante du « pourrissement E2E » des cycles précédents s'explique probablement ainsi.
✅ Sentinelle `tests/js/e2eRoutesIdempotentesEnTete.spec.js` — cliquet à 18, prouvée mordante,
lisant `config/idempotency.php` comme source unique.

### ⚠️ Une affirmation fausse dans la spec elle-même

`state05` déclare « Pusher unreachable in dev (port 6001 down) ». **Faux** : `:6001` est ouvert et
répond HTTP 200, vérifié au même moment. C'est une narration codée en dur, pas une mesure — le
piège d'instrument exactement, cette fois inscrit dans le produit du test.

### L'échec résiduel, avec sept hypothèses éliminées

La carte KDS est **absente**, pas mal rangée. J'ai écarté, preuve à l'appui : statut hors liste
visible · non-libération board (`payment_status=15` = PENDING_COUNTER → libérée) · désaccord de nom
de champ (`source_surface` des deux côtés) · article sans station (#12 Cheddar = `cuisine_chaude`) ·
socket injoignable · mauvaise branche · autre worktree.

⚠️ **J'ai failli rapporter une fausse cause** : les commandes portent `status=16` (CANCELED) après
coup. Ce n'est pas la cause — le nettoyage **annule** au lieu de supprimer (NF525 : on ne détruit
pas une trace fiscale). Les horodatages le prouvent (créée 17:08:00, annulée 17:09:41).

**Conclusion** : toutes les conditions backend sont réunies et la carte ne rend pas — ni en 16 s de
temps réel, ni après rechargement. Le défaut est dans le **chemin de rendu**, ce qui confirme le
cycle précédent, mais cette fois avec les alternatives éliminées et sur le bon serveur.

Dossier complet : `reports/audit/VAGUE_D_CAUSES_REELLES_2026-08-25.md`.

---

# W8 — La dette d'idempotence soldée, et quatre erreurs de comptage assumées

## Le résultat

**Zéro** spec n'appelle plus `kds-order/change-status` sans en-tête d'idempotence. **12 fichiers
corrigés**, chacun commenté sur place pour que le correctif ne paraisse pas arbitraire dans six mois.
Cliquet verrouillé à **0** dans `tests/js/e2eRoutesIdempotentesEnTete.spec.js`, prouvé mordant.

Vitest après correction : **446 fichiers, 3 653 passés, 0 échec.**

## Le chiffre a été faux quatre fois — voici pourquoi

Je le consigne parce qu'un cliquet auquel on ne croit plus est un cliquet qu'on désactive.

| Annonce | Méthode | Pourquoi c'était faux |
|---|---|---|
| **16** | comptage manuel `awk` | s'arrêtait au premier site PORTANT l'en-tête et déclarait le fichier conforme — un fichier à deux appels dont un seul correct reste cassé |
| **18** | première sentinelle | comptait aussi les **commentaires**, noms de test et chaînes d'assertion mentionnant la route |
| **13** | + dépouillement des commentaires, contexte d'appel exigé | restait un faux positif : `wave-final-S3-kds.spec.js:449` porte la route dans une chaîne de **conseil** (`fix_hint`), pas dans un appel — cette spec pilote l'interface et observe le réseau |
| **12 réelles, 0 restante** | + exclusion des chaînes de prose, + preuve indirecte acceptée | `test-e2e-supervisor-wave-c-z4-latency` passait `idemKey:` à un assistant qui pose l'en-tête (`nodeRequest:85`) — **du code correct que j'accusais** |

## 🔴 Le bug le plus instructif : je me suis piégé moi-même

Après avoir commenté les 12 correctifs, la sentinelle a signalé **8 faux positifs sur des fichiers
que je venais de réparer**.

Cause : mon commentaire contenait littéralement le motif de route se terminant par une barre oblique
suivie d'une étoile. Pour le dépouilleur, cette séquence **ouvre un commentaire de bloc** — il
avalait tout jusqu'à la fermeture suivante, **y compris la ligne d'en-tête** qu'il cherchait.

Deux corrections :
1. Le commentaire est reformulé (`change-status/{id}`) — plus de séquence ouvrante.
2. **Le dépouilleur retire désormais les commentaires de LIGNE avant les blocs.** Dans l'ordre
   inverse, n'importe quel motif de ce genre dans un commentaire de ligne casse l'analyse. Le
   durcissement est appliqué aux deux sentinelles concernées, avec l'explication en commentaire.

## Ce que j'en retiens

Une sentinelle qui accuse du code correct est aussi nuisible qu'une qui laisse passer du code cassé :
dans les deux cas, on cesse de la croire. Les quatre chiffres successifs ne sont pas de la maladresse
accumulée — chacun est tombé parce que j'ai cherché un contre-exemple au précédent. C'est le test
négatif, à chaque étape, qui a fait le travail.

---

# W9 — Une dernière erreur de mesure, à moi, corrigée

En cherchant un spécimen borne vivant pour instrumenter SYNC-1, j'ai écrit que **le board KDS ne
contenait aucune commande borne**. **Faux.** J'avais interrogé les 20 plus récentes par identifiant
décroissant — qui sont toutes d'août, donc `pos`/`uber_eats`/`web`.

Sans limite, le compte réel : **1 275 commandes borne**, dont **181 en statut board**, **toutes**
branche 1 et paiement libéré, réparties sur juin (102) et juillet (79).

**Ce que cela apprend** : le pipeline borne → board a bel et bien fonctionné, sur 1 275 commandes.
Le défaut SYNC-1 n'est donc pas structurel dans le classement par surface — sinon aucune de ces 181
n'aurait jamais atteint un statut board. Cela resserre encore le périmètre.

Et cela recoupe la découverte de W5 : ces 181 font partie du même vivier de données mortes que les
344 commandes figées.

**Ma sonde d'instrumentation est restée bloquée** et a été retirée du dépôt — un test qui pend
immobilise une campagne sans rien dire. Le prochain geste est écrit : délai de garde sur chaque
`evaluate`, session chef réutilisée, relevé de `visibleRows`/`kioskOrders` plutôt que du DOM.

**Je n'ai pas conclu sur SYNC-1, et je le dis.** Neuf hypothèses éliminées, une dixième non
vérifiable sans observation en vol. Proposer une explication de plus serait de la spéculation.

---

# ✅ W9quater — SYNC-1 tranché au niveau des couches

Test décisif en HTTP pur (deux erreurs miennes corrigées d'abord : l'endpoint est `/api/auth/login`,
et le mot de passe borne `kiosk123`) :

```
devis HTTP 200 → commande HTTP 201 (#6930) → board HTTP 200
board : 1 commande | la mienne : ✅ PRÉSENTE — surface=kiosk status=4 type=10, en < 2 s
```

**Le backend est innocent, et c'est mesuré, plus déduit.** Il crée, libère, expose et marque la
surface correctement, en moins de 2 secondes — contre des budgets de 8 s puis 15 s côté spec.

**SYNC-1 échoue dans la couche navigateur.** Onzième et dernière élimination côté serveur.

Reste à distinguer deux cas, ce qui demande de relever `visibleRows`/`kioskOrders` du composant
pendant la campagne : la page reçoit et ne rend pas, ou elle ne reçoit pas faute de session valide.

Commande de sonde annulée (jamais supprimée), board revenu à 0, NF525 en ajout seul.

---

# ✅✅ W10 — SYNC-1 RÉSOLU : la vague D n'avait aucun défaut produit

## La preuve, en trois temps

**1.** La page KDS **appelle** l'API et **reçoit** la commande :
`GET /api/admin/kds-order` → HTTP 200, 1 ligne,
`{ id: 6931, status: 4, source_surface: "kiosk", stations: ["cuisine_chaude"] }`.

**2.** Aucune carte `data-kds-order-card` dans le DOM — **ni le message « Aucune commande borne en
cours. »**. Ce second point est le déclic : une colonne vide afficherait son message. Son absence
prouve que la colonne entière n'est pas rendue.

**3.** Et pourtant la page affiche :
`[A] NOUVELLE BORNE N°A0132 ATTENTE 03:05 1× CHE EN ATTENTE ENCAISSEMENT Prêt`

## La cause

`KitchenDisplaySystemComponent.vue:137` → `<KdsV2Grid v-if="useV2Layout">`, **`true` par défaut**.
`KdsV2Grid.vue` ne contient **aucun** `data-kds-order-card` (compté : 0). L'ancien balisage en
colonnes est derrière `v-if="!useV2Layout"` — mort par défaut depuis la refonte.

**La spec affirme contre une interface qui n'existe plus.**

## Ce que cela clôt

**La vague D n'avait AUCUN défaut produit.** Ses deux causes étaient dans le harnais :
1. en-tête d'idempotence manquant (états 07-10) → **corrigé**, avec 12 autres specs ;
2. sélecteurs V1 contre interface V2 (état 05) → **identifié**.

Le produit fait ce qu'il doit : la commande borne arrive en cuisine, marquée « NOUVELLE BORNE »,
avec file d'attente et état d'encaissement.

## Décision qui vous revient

Mettre la spec à jour demande de choisir ce qu'elle doit prouver : viser les sélecteurs **V2**
(recommandé — on teste l'interface servie), forcer la **V1** via `localStorage['kds.v2_enabled']`
(on teste alors une interface que personne ne voit), ou **les deux**.

⛔ Je ne tranche pas : choisir ce qu'un test doit prouver est une décision de conception.

## Propreté

Sonde retirée du dépôt · commandes #6930 et #6931 **annulées** (jamais supprimées) · NF525 en ajout seul.

---

# 🔴 W10bis — Deuxième cause systémique : 14 specs visent une interface morte

Le relevé étendu à tout le répertoire :

| Mesure | Valeur |
|---|---|
| Specs affirmant contre `data-kds-order-card` | **14** |
| dont forçant la V1 (`kds.v2_enabled`) | **0** |
| Specs visant les sélecteurs V2 | **3** |

**Les 14 testent une interface que personne ne voit.** Même mode de panne que l'en-tête
d'idempotence : l'échec ressemble à un défaut produit.

**Deux causes systémiques distinctes, trouvées le même jour**, expliquant à elles seules une part
majeure du « pourrissement E2E » traîné depuis des cycles :
1. une route devenue idempotente sans que les specs suivent → **12 specs corrigées, cliquet à 0** ;
2. une interface refondue sans que les specs suivent → **14 specs identifiées, cliquet à 14**.

Sentinelle : `tests/js/e2eSelecteursKdsV2.spec.js` (5 tests, prouvée mordante). Elle épingle les
trois faits porteurs : la V2 est le défaut, `KdsV2Grid` ne pose pas l'attribut, le balisage V1
survit derrière la bascule.

⛔ **Aucune spec migrée** : choisir ce qu'un test doit prouver vous revient.

## Le fil conducteur de la journée

Les deux découvertes suivent le même schéma. Quelque chose de légitime change côté produit — une
route se durcit, une interface se refond — et le harnais reste figé. Les tests échouent alors **en
accusant le produit**, et chaque cycle suivant part chercher un défaut là où il n'y en a pas.

C'est pour cela que les deux corrections sont des **cliquets adossés à la source** : l'un lit
`config/idempotency.php`, l'autre lit le composant lui-même. Le jour où la source rebouge, ils le
diront — au lieu de laisser des rouges inexplicables s'accumuler pendant des mois.

---

# 🔴 W11 — La classe généralisée : 23 sélecteurs que rien ne pose

Les deux causes systémiques de la journée partagent un schéma. J'ai voulu savoir jusqu'où il allait :
**un sélecteur qu'aucun fichier du produit ne pose est un test mort.**

Balayage complet : `resources/**` + `public/js/**` contre `tests/e2e/**`.

## Le chiffre, et les deux corrections qu'il a demandées

| Étape | Compte | Ce qui clochait dans MA mesure |
|---|---|---|
| brut | **132** | — |
| après gabarits | **55** | le produit écrit `` :data-testid=`kds-cols-${n}` `` ; chercher des chaînes exactes déclarait `kds-cols-4` mort **alors qu'il est posé** |
| après exclusion mobile | **23** | les specs `*mobile*` visent `mobile/`, codebase séparé par mandat (CLAUDE.md §3bis) — vérifié : `loyalty-balance`, `redeem-wizard`, `qr-mode-toggle` y existent bel et bien |

**23** est le chiffre défendable. Parmi eux : `kiosk-cart-validate`, `kiosk-tap-to-start`,
`receipt-close`, `receipt-grand-total`, `stock-rupture-dashboard`, `cash-overview-reconciliation-diff`,
`kiosk-offline-banner` — des sélecteurs qu'aucune spec ne trouvera jamais.

**Sentinelle** : `tests/js/e2eSelecteursMorts.spec.js` (5 tests, cliquet à 23, prouvée mordante).
Elle épingle aussi ses propres garde-fous : elle échoue si le balayage du produit devient vide
(qui déclarerait tout mort), et elle vérifie qu'elle tient compte des gabarits.

## Ce que la journée aura montré

Trois relevés, trois fois le même mécanisme : **le produit évolue légitimement, le harnais reste
figé, et le test accuse le produit.** Une route se durcit, une interface se refond, un sélecteur
disparaît — et des mois plus tard on cherche un défaut là où il n'y en a pas.

C'est pourquoi les trois cliquets sont **adossés à la source** : `config/idempotency.php`, le
composant KDS, et le produit lui-même. Le jour où la source rebouge, ils le disent.

## Et une constante dans mes propres mesures

Sur les trois relevés, mon premier chiffre a été faux **à chaque fois** — 16→12, 18→14, 132→23.
Toujours dans le même sens : **surestimé**. Chaque correction est venue d'un contre-exemple cherché
exprès. C'est le test négatif, systématiquement, qui a fait le travail — pas la confiance.

---

(retracté)

---

# ✅ W11ter — Les routes, mesurées correctement au troisième essai

J'avais annoncé « 0 chemin mort », puis « 45 », puis rétracté les deux. J'ai construit le résolveur
que je disais nécessaire : **appariement d'accolades + recomposition parent/enfant**, plus les
routes Blade de `routes/web.php` (c'est là que vivent `/admin/roue*`), moins les fichiers statiques.

| Version | Résultat | Défaut |
|---|---|---|
| plate, avec attrape-tout | **0** | `/:pathMatch(.*)*` appariait tout — test incapable d'échouer |
| plate, sans attrape-tout | **45** | routes enfants à chemin relatif jamais recomposées |
| **résolveur d'arbre + Blade** | **2** | — |

## Ce que la mesure correcte a trouvé

**`/admin/stock-rupture-dashboard`** — morte, et **CLAUDE.md:346 la documente déjà comme telle** :
« corrigé 2026-07-04, l'ancien `/admin/stock-rupture-dashboard` 404 → route SPA réelle
`admin.stock.rupture` ». La bonne route existe (`stockRoutes.js:19`).

**Trois specs y naviguaient encore** — `wave-polish-final-B`, `zone7-admin-daily`,
`test-e2e-rush-hour-50x50-wave-E` — **corrigées**. Un défaut connu, documenté depuis juillet, et
qui continuait de faire échouer des campagnes.

Reste **`/admin/delivery-boys/create`** (1 spec) : le routeur ne déclare que `""`, `show/:id` et
`show/:id/:orderId`. Le bon chemin ne se devine pas — cliquet à **1**, à trancher.

## Le tableau complet et définitif de la dérive harnais ↔ produit

| Dimension | État au 2026-08-25 | Cliquet |
|---|---|---|
| Contrats d'API (idempotence) | 12 specs cassées → **corrigées** | **0** |
| Disposition d'interface (KDS V1/V2) | **14** specs visent un balisage mort | 14 |
| Sélecteurs `data-testid` | **23** morts | 23 |
| Routes de navigation | 3 specs corrigées, **1** reste | **1** |

**Quatre dimensions mesurées, quatre cliquets adossés à la source.** Aucune n'est laissée au hasard,
et aucune n'est laissée sur un chiffre que je ne peux pas défendre.

## La leçon de la journée, en une phrase

Sur **cinq** relevés successifs, mon premier chiffre a été faux **cinq fois** — 16, 18, 132, 0, 45 —
et toujours parce que mon instrument mesurait autre chose que ce que je croyais. Le seul mécanisme
qui l'a détecté, à chaque fois, est le **test négatif** : introduire délibérément un cas fautif et
vérifier que la sentinelle le voit. Une sentinelle qu'on n'a pas vue échouer n'est pas une preuve.
