# Matrice de traçabilité — Dashboard et contrôle

Date : 2026-09-02

## Actualisation finale — HEAD `28cd79d5a` (2026-09-03)

Les chiffres ci-dessous remplacent le snapshot `47c001fbb` conservé plus bas :

- **Routes dashboard sans test HTTP direct : 0/16.** Chaque suffixe de `routes/api.php:1508-1527` est désormais cité par au moins un test Feature/Unit. La nouvelle matrice ajoute directement les six anciennes lacunes et `PopularItemsFailClosedTest` verrouille le défaut multi-branche oublié.
- **Composants sans test direct : 8/18.** Restent sans référence littérale JS/E2E : `ChannelStatsComponent`, `CustomerStatsComponent`, `FeaturedItemsComponent`, `MostPopularItemsComponent`, `OrderStatisticsComponent`, `RealtimeReportComponent`, `SalesSummaryComponent`, `TopCustomersComponent`.
- **Backend ciblé : 188 verts. Frontend ciblé : 247 verts. Playwright dashboard/cockpit : 1 vert**, mais le test navigateur ne rend pas les erreurs console bloquantes.
- **Playwright caisse servi : 5 rouges**, puis arrêt après une sixième attente identique ; les contrôles `pos-control-*` sont absents, cohérent avec le bundle POS périmé.
- **Vitest complet : 4 échecs/3 924**, tous liés à des bundles périmés. **Sentinelles PHP critiques : 3 rouges** (frozen SHA, compteur `withoutGlobalScopes`, cliquet NF525).

**TRACEABILITY_VERDICT: INCOMPLETE**  
**DIRECT_HTTP_GAPS_COMMITTED: 0/16**  
**DIRECT_COMPONENT_TEST_GAPS: 8**  
**RUNTIME_ASSURANCE: BLOCKED_BY_RED_GLOBAL_GATES**

### Matrice des nouveaux risques caisse/outbox/restore

| Contrat | Implémentation courante | Preuve actuelle | Verdict |
| --- | --- | --- | --- |
| Journée de service complète dans le tiroir | une page `per_page=100`, pagination ignorée | aucun cas >100 | **P1 : tronqué** |
| Statuts caisse synchronisés aux enums | valeurs PHP recopiées en nombres dans deux modules JS | tests verrouillent les nombres | **P1 : divergence possible** |
| Outbox livré 24 h | API et Vue convergent sur `delivered_24h` | ciblé PHP+JS vert | Corrigé |
| Claims en vol/orphelins/terminaux visibles | données chargées, pas rendues | aucun test de présence visuelle | **P1 : invisible** |
| Résultat retry | API `requeued`, Vue `retried` | test ne vérifie pas le compteur | **P1 : contrat rompu** |
| Purge outbox ciblée et fidèle | cap avant filtre, compteur de confirmation d'une autre table, audit avant delete | pas de cas 500 étrangers/delete partiel | **P1** |
| Restore atteste le backup courant | aucun rapprochement nom/SHA | aucun test A restauré/B courant | **P1** |
| Échec de drill remplace tout ancien vert | plusieurs retours précoces sans `store(failed)` | non couvert | **P1** |
| Mutation interrupteur et audit atomiques | audit avant mutation | échec d'audit couvert, échec de mutation non couvert | **P1** |
| Bundle distribué = source | quatre bundles déclarés périmés | Vitest complet rouge | **Bloquant release** |

Mode : audit statique en lecture seule du HEAD `47c001fbb`. Aucun test n'a été lancé par cet audit sous le safety gate rouge ; les exécutions externes sont rouges, hybrides, ciblées ou basées sur un autre HEAD.

Périmètre : 16 routes dashboard, cockpit observabilité, composants Vue, tests Feature/Unit/JS/E2E.

## Verdict

**TRACEABILITY_VERDICT: INCOMPLETE**

**DIRECT_HTTP_GAPS_COMMITTED: 3/16**

**DIRECT_COMPONENT_TEST_GAPS: 10**

**RUNTIME_ASSURANCE: BLOCKED_BY_FROZEN_GATE**

Le dépôt ne permet pas d'affirmer « tout est testé ». Trois routes dashboard n'ont encore aucune référence HTTP directe **committée** dans les tests Feature/Unit, dix composants n'ont aucune référence directe dans les tests JS/E2E, et la campagne Playwright autoritaire ne couvre qu'un parcours dashboard ancien. Deux drafts non suivis (`DashboardRoutesAuthzMatrixTest`, `PopularItemsFailClosedTest`) couvrent ces trois routes dans le worktree, mais ne font pas partie du SHA audité. Les comptes ci-dessous mesurent des **fichiers committés qui citent le chemin ou le composant**, pas le nombre ni la qualité des assertions.

## Delta outbox — HEAD `179e27d99`

| Contrat | Backend | Frontend | Test direct | Verdict |
| --- | --- | --- | --- | --- |
| Livraison 24 h | `delivered_24h` fondé sur `broadcast_at` | lit seulement `dispatched_24h` | PHPUnit backend seulement | **ROMPU** : UI à zéro |
| Claim en vol | `in_flight` | non lu/non rendu | PHPUnit backend seulement | Non livré à l'opérateur |
| Claim orphelin | `stale_claimed` | non lu/non rendu | PHPUnit backend seulement | Non livré à l'opérateur |
| Échec terminal | `terminal_failures` sans seuil attempts | non lu/non rendu | test à `attempts=1` | Sémantique incorrecte et invisible |
| Santé worker outbox | `jobs.reserved_at` quelconque ou broadcast récent | `up/down` | aucun cas autre queue | Faux vert inter-queue possible |
| Erreur/staleness réseau | aucune modification API requise | aucun `catch`/état stale | aucun | Ancien vert conservé |
| Drain limité à 500 | limite SQL avant filtre métier PHP | action sans détail/confirmation | aucun cas >500 étrangers | Candidat outbox affamé possible |
| Audit drain | audit avant delete, champ `deleted` anticipé | résultat non affiché | audit-write failure seulement | Postcondition non prouvée |

Le test `tests/js/observabilityOutboxRoute.spec.js` vérifie le câblage et cinq anciennes sections, mais pas la forme du payload ni la réaction aux états opérationnels. Le plan exigeait explicitement un alias `dispatched_24h` pendant la migration ; cet alias est absent.

## Delta restore-drill — commit `28c2771a8`

| Contrat | Implémentation actuelle | Test | Verdict |
| --- | --- | --- | --- |
| Persister chaque succès et chaque échec | plusieurs retours `FAILURE` n'écrivent rien | test Feature prévu absent | **Gap P1** |
| Fraîcheur maximale 26 h | constante à 48 h | seulement 60 h/121 h | **Contradiction plan** |
| Drill du fichier actuellement affiché | aucun rapprochement nom/SHA | tous les cas utilisent le même nom | **Faux vert possible** |
| Readiness production | `restore_drill` est gating en production | tests exécutés hors production | Partiel |
| Cockpit visuel | statut/résultat rendus | banc composant présent | Committé, mais identité fichier/SHA non vérifiée et full Vitest rouge |

## Delta dates, DST, EOD et garde branche — commit `47c001fbb`

| Contrat | Implémentation actuelle | Test committé | Verdict |
| --- | --- | --- | --- |
| Quatre endpoints datés | résolveur commun, dates strictes, bornes isolées/inversées/>366 refusées | `DashboardDateContractMatrixTest` | Corrigé statiquement; full non certifiant |
| Jours DST | `CarbonPeriod`, un label par jour/montant | `SalesSummaryDstBoundarySentinelTest` | Corrigé statiquement |
| Date envoyée par Vue | `Date` devient jour civil local dans `requestHandler` | `dashboardDateEnvoyeeEnJourCivil.spec.js` | Ciblé; fonction partagée à surveiller |
| Date PDF EOD | jour par défaut serveur, date impossible refusée | PHPUnit EOD + `dashboardEodPdfJourServeur.spec.js` | Date corrigée; parité chiffrée PDF/Z non prouvée |
| `popular-items` fail-closed | garde explicite avant `ItemService` | tests présents seulement en non suivi | Code corrigé, preuve à intégrer |

## Méthode et limites

- Routes autoritaires : `routes/api.php:1492-1512`.
- Autorisation : `DashboardController.php:53-75` (`dashboard` sur 15 actions, `pos-manage-fiscal` sur `eodPdf`).
- Recherche littérale des chemins sous `tests/Feature`, `tests/Unit`, `tests/js`, `tests/e2e`.
- Recherche littérale des noms de composants sous `tests/js` et `tests/e2e`.
- Une référence E2E dans un ancien `http-trace.json` n'est pas une assertion exécutable.
- La spec de capture ajoutée le 2 septembre contenait initialement zéro `expect()`. Elle en contient maintenant deux et passe sur 12 pages catalogue, mais ne couvre ni le cockpit, ni les rôles négatifs, ni les erreurs console/WebSocket qu'elle collecte. La spec caisse séparée passe 11 scénarios et produit 10 captures, sans exercer le cockpit Admin.
- Le cache Vitest du relevé initial indiquait 465 fichiers sans échec. Il a ensuite été remplacé successivement par des runs isolés rouges sur `systemHealthSauvegardeRestauration.spec.js` puis `posTrackerCanalLisible.spec.js`; aucun de ces états volatils ne remplace un log brut complet, les tests HTTP/composant manquants ou une campagne Playwright fraîche.

## Routes dashboard

| Route | Fichiers Feature/Unit | JS | E2E | Assurance statique |
| --- | ---: | ---: | ---: | --- |
| `GET total-sales` | 2 | 0 | 1 | Partielle |
| `GET total-orders` | 9 | 0 | 2 | La plus fournie, mais ne généralise pas aux autres méthodes |
| `GET total-customers` | 1 | 0 | 0 | Backend ciblé seulement |
| `GET total-menu-items` | 1 | 0 | 0 | Backend ciblé seulement |
| `GET order-statistics` | 1 | 0 | 1 | Matrice dates committée ; rôle/branche seulement dans draft non suivi |
| `GET order-summary` | 1 | 0 | 1 | Matrice dates committée ; rôle/branche seulement dans draft non suivi |
| `GET sales-summary` | 3 | 0 | 0 | Matrice dates + DST committées |
| `GET customer-states` | 1 | 0 | 0 | Matrice dates committée ; rôle/branche seulement dans draft non suivi |
| `GET top-customers` | **0** | 0 | 0 | **Gap total** |
| `GET featured-items` | 1 | 0 | 0 | Backend ciblé seulement |
| `GET popular-items` | **0** | 0 | 0 | **Gap total** |
| `GET realtime-report` | 2 | 0 | 1 | Backend partiel, aucun test direct du composant |
| `GET sla-alerts` | 1 | 0 | 0 | Backend ciblé seulement |
| `GET channel-statistics` | 3 | 0 | 0 | Backend partiel, aucun test direct du composant |
| `GET audit-trail` | **0** | 0 | 0 | **Gap HTTP** ; le service est testé directement |
| `POST eod-pdf` | 1 | 1 | 0 | Date stricte/serveur et téléchargement JS ciblés; contenu comptable non comparé au Z |

Les trois gaps HTTP directs committés sont donc : `top-customers`, `popular-items`, `audit-trail`. Le draft non suivi `DashboardRoutesAuthzMatrixTest.php` les référence, mais n'est pas une preuve attachée au SHA.

## Surfaces Vue

| Composant | Références JS | Références E2E | État |
| --- | ---: | ---: | --- |
| `DashboardComponent.vue` | 7 | 10 | Shell couvert, widgets non déduits |
| `OverviewComponent.vue` | 2 | 6 | Partiel |
| `LastZReportWidget.vue` | 0 | 6 | E2E seulement |
| `OrderSummaryComponent.vue` | 2 | 1 | Partiel |
| `SlaAlertsComponent.vue` | 1 | 0 | JS seulement |
| `StockLowAlertsWidget.vue` | 3 | 2 | Couvert directement |
| `AuditTrailComponent.vue` | **0** | **0** | **Gap** |
| `ChannelStatsComponent.vue` | **0** | **0** | **Gap** |
| `CustomerStatsComponent.vue` | **0** | **0** | **Gap** |
| `FeaturedItemsComponent.vue` | **0** | **0** | **Gap** |
| `MostPopularItemsComponent.vue` | **0** | **0** | **Gap** |
| `OrderStatisticsComponent.vue` | **0** | **0** | **Gap** |
| `RealtimeReportComponent.vue` | **0** | **0** | **Gap** |
| `SalesSummaryComponent.vue` | **0** | **0** | **Gap** |
| `TopCustomersComponent.vue` | **0** | **0** | **Gap** |
| `SystemHealthComponent.vue` | **0** | **0** | **Gap** |

`DashboardComponent.vue:48-69` monte Overview, Realtime, SLA, Channel, Audit, OrderStatistics, SalesSummary, OrderSummary, Last Z, Featured, Popular et Stock Low. Il ne monte ni `CustomerStatsComponent` ni `TopCustomersComponent`. Les routes `total-customers`, `customer-states` et `top-customers` existent pourtant. Il faut décider explicitement si ces surfaces sont mortes, destinées à une autre page, ou doivent être remontées ; leur simple présence ne vaut pas couverture utilisateur.

## Contrats transverses

### Autorisation et isolation branche

- Le middleware de route est explicite (`DashboardController.php:53-75`).
- `DashboardService.php:22-44,60-75` centralise les requêtes et refuse un non-admin sans branche.
- La matrice branche actuelle exerce surtout `total-orders`, `realtime-report`, `total-customers`, SLA et channel. Elle ne prouve pas les 16 routes.
- Admin et Tenant Admin voient volontairement l'ensemble des branches selon le contrat métier documenté ; ce n'est pas considéré comme une fuite.
- `auditTrail()` applique manuellement le filtre (`DashboardService.php:953-976`) parce que `AuditLog` est exempté du scope global. Cette exception sensible n'a pas de test HTTP direct.

`47c001fbb` ajoute `DashboardService::assertDashboardBranchScope()` avant la délégation à `ItemService::mostPopularItems()`. Le fail-open `branch_id=0` est donc fermé statiquement. La preuve HTTP dédiée existe dans deux fichiers non suivis : elle doit être intégrée puis rejouée sur le SHA final.

Le classement « populaire » compte par ailleurs les lignes `order_items`, pas les quantités vendues, sans filtre de paiement/statut ni exclusion explicite des commandes annulées/remboursées. Même hors fuite branche, sa sémantique métier n'est pas verrouillée par un test.

### Dates

- `resolveDashboardWindow()` porte maintenant le même contrat sur `order-statistics`, `order-summary`, `sales-summary` et `customer-states`.
- La matrice committée couvre succès, inversion, >366, borne isolée, date impossible et défaut sans date sur les quatre endpoints.
- `salesSummary()` itère avec `CarbonPeriod`; les bascules Paris de mars et octobre sont couvertes, ainsi que la taille de l'année bissextile.
- La fonction globale `requestHandler()` convertit désormais tout objet `Date` en `YYYY-MM-DD` local. C'est correct pour les sélecteurs recensés, mais son rayon d'appel dépasse le dashboard; le full Vitest est requis pour exclure une régression d'un autre module.
- Deux bords ne sont pas verrouillés : `first_date=0&last_date=0` tombe dans le défaut à cause de `empty()`, et `diffInDays() > 366` accepte une plage de 367 jours inclusifs (2026-01-01→2027-01-02).

**Statut** : défauts de contrat et DST fermés statiquement dans `47c001fbb`; preuve globale/reproductible encore absente.

### Jour fiscal et synthèse EOD

- Les tuiles « Ventes/Commandes du jour » utilisent `business_date` (`DashboardService.php:411-417`), tandis que le Ticket Moyen du live utilise une fenêtre minuit→minuit sur `order_datetime` (`DashboardService.php:489-528`). Après minuit pendant le même service fiscal, les trois chiffres affichés côte à côte ne portent donc plus sur la même population. Aucun test de parité après-minuit n'existe.
- `47c001fbb` valide désormais l'existence calendaire de la date EOD et le client omet le paramètre par défaut, laissant le serveur choisir aujourd'hui Paris. Le nom téléchargé vient de l'en-tête serveur.
- Le bouton français est libellé « PDF Clôture du jour » alors que le contrôleur précise que cette synthèse **ne clôture pas** le Z fiscal. Cette ambiguïté de commande est dangereuse sur une surface NF525 ; le libellé doit distinguer téléchargement de synthèse et clôture effective.
- Le test EOD vérifie magic bytes PDF, permission, cas vide, chaîne manifestement invalide et une forme de payload. Il n'extrait pas le PDF pour comparer CA/TVA/tenders/canaux avec le Z, ne teste pas de date impossible, de fuseau navigateur, de branche, de remboursement complet/partiel ni de parité multi-tender.

**Statut date EOD** : corrigé statiquement. L'assurance comptable reste bloquée par la parité chiffrée PDF/Z non testée et le libellé de « clôture » trompeur.

### Erreurs

`DashboardController::dashboardFailure()` renvoie le message brut de toute exception non HTTP/non validation (`DashboardController.php:30-40`). Cela peut exposer des détails SQL/driver aux utilisateurs authentifiés et rend les contrats d'erreur dépendants des exceptions internes. Aucun test direct ne prouve une réponse publique stable et sans fuite pour chaque route.

### Audit trail

Le commit `78a878e23` ajoute des tests de service pour : acteur actif, soft-deleted, identifiant orphelin et acteur système. Ils corrigent aussi les fixtures en attribuant réellement le rôle Admin. Ces tests sont cohérents avec le changement `User::withTrashed()`/`auditActorName()` ; le run PHPUnit global s'est arrêté à 88 % après deux échecs et une erreur, sans résumé permettant d'identifier les tests concernés. Il manque encore le contrat HTTP (`permission`, 401/403, branche, enveloppe JSON et erreur).

**Finding P1-AUDIT-INTEGRITY-CLAIM** : le composant affirme que « le préfixe de hash atteste l'intégrité de la chaîne » (`AuditTrailComponent.vue:8-10`), mais `auditTrail()` ne recalcule ni `prev_hash` ni `current_hash` et n'appelle aucune vérification de chaîne ; il tronque simplement la valeur stockée à huit caractères (`DashboardService.php:1007-1016`). Une ligne altérée avec un hash non vide conserverait donc l'apparence « inviolable ». Les sentinelles actuelles verrouillent précisément `hash_prefix` comme prétendue « chain proof », ce qui prouve seulement la table source et la forme hexadécimale, pas l'intégrité.

Le widget masque aussi `branch_id` pourtant renvoyé et ne fournit qu'un temps relatif (`diffForHumans()`), sans timestamp exact/fuseau. Pour un Admin global, deux événements homonymes de branches différentes sont ambigus et la preuve temporelle n'est pas exploitable en audit.

La sortie honnête doit soit parler d'« identifiant de chaîne non vérifié », soit exposer un résultat de vérification réel, daté et fail-closed (`status`, `verified_at`, tête/branche, erreur). Les tests doivent injecter une rupture/tamper et exiger un état rouge.

### Cockpit et fraîcheur des mesures

Le commit `78a878e23` ajoute un garde pour mesure santé absente ou âgée de plus de 30 minutes, accompagné de trois tests. Deux faux-verts restent possibles dans cette version et ne sont pas couverts par ces cas :

- si `checks` est non vide mais `timestamp` est absent ou illisible, `mesure_age_min` reste `null` et aucune alerte de fraîcheur n'est ajoutée (`SyncOverviewController.php:630-659`) ;
- un timestamp futur produit un âge négatif et est considéré frais.

La sauvegarde présente aussi une divergence de bord : le cockpit arrondit l'âge à l'heure avant de comparer (`SyncOverviewController.php:615-620,676-684`), alors que readiness compare la valeur décimale (`HealthController.php:198-207`). Un fichier âgé d'environ 26 h 01 à 26 h 29 peut donc être vert dans le cockpit et rouge dans readiness.

**Finding P1-HEALTH-FRESHNESS** : une mesure sans timestamp valide/futur doit être `unknown/attention`, et cockpit/readiness doivent partager le même calcul de fraîcheur.

### Pipeline outbox et vérité de livraison

`DispatchDomainEventsJob` a désormais deux marqueurs : `dispatched_at` est posé sous lock lors du claim, puis `broadcast_at` seulement après broadcast réussi (`DispatchDomainEventsJob.php:54-86,151-160`). Les lanes rescue/monitor/prune et leurs tests utilisent déjà `broadcast_at` comme preuve réelle.

Le dashboard outbox reste sur l'ancien contrat : pending = `dispatched_at IS NULL`, delivered 24 h = `dispatched_at IS NOT NULL`, dernier signal queue/websocket = dernier `dispatched_at` (`SyncOverviewController.php:321-345,539-575`). **Finding P1-OUTBOX-DELIVERY-MARKER** : un crash après claim et avant broadcast est soustrait aux pending, ajouté aux livrés et peut rendre les sondes vertes. `OutboxOverviewControllerTest` ne contient aucune assertion `broadcast_at`.

Autres risques de contrôle :

- la sonde queue cherche un `reserved_at` récent sur **n'importe quelle queue**, sans `where('queue', 'high')`; un worker notifications actif peut masquer un worker high mort (`SyncOverviewController.php:525-536`) ;
- le drain supprime tous les `failed_jobs` anciens, y compris ceux hors outbox, sans archive ni audit durable ;
- le retry HTTP ne partage ni lock de concurrence ni audit avec la commande Artisan déjà durcie ;
- le composant conserve l'ancien état sur erreur réseau, ne confirme pas le drain et n'annonce ni résultat ni échec.

La preuve attendue doit simuler : crash claim→broadcast, broadcast réussi/échoué, stale claim, workers séparés par queue, doubles retries concurrents, drain hors-scope refusé, audit acteur/corrélation et perte réseau après état vert.

## Cockpit de contrôle

| Surface | Références Feature/Unit | JS | E2E | Gap principal |
| --- | ---: | ---: | ---: | --- |
| `system-health` | 2 | 0 | 1 | Pas de test direct du composant ni campagne fraîche |
| `interrupteurs` | 4 | 0 | 0 | PUT backend partiel ; UX, postconditions et trace durable non démontrées |
| `outbox` | 2 | 1 | 3 | Couverture existante mais contrat livraison faux (`dispatched_at` au lieu de `broadcast_at`) |
| `sync-overview` | 1 | 0 | 0 | Backend seulement |
| `healthz` | 2 | — | 1 | Sondes séparées |
| `health/ready` | 5 | — | 0 | Pas de preuve navigateur attendue, mais parité cockpit/readiness à tester |
| `health/live` | 3 | — | 0 | Backend seulement |

Le cockpit agrège désormais le dernier restore drill, mais ne vérifie pas qu'il concerne le fichier courant, conserve un seuil de 48 h au lieu de 26 h et peut garder un ancien vert après plusieurs sorties précoces en échec. Le PUT interrupteur écrit dans le journal serveur, pas dans une preuve métier immuable ; l'UI le dit désormais honnêtement.

La route SPA utilise `permissionUrl: "settings"` (`observabilityRoutes.js:26-44`) alors que les API utilisent `role:Admin|Tenant Admin`. Le backend reste fail-closed, mais les deux autorités peuvent diverger : un porteur de `settings` non administrateur ouvre un écran qui répond 403, tandis qu'un Tenant Admin sans cette permission peut être bloqué côté SPA alors que l'API l'autorise. La navigation Tenant Admin n'est pas testée.

## Preuve minimale manquante

1. Safety/frozen PASS et snapshot identifié.
2. Tests HTTP paramétrés sur les 16 routes : 401, permissions, branche, succès, vide, erreur et format.
3. Tests directs des dix composants sans référence, incluant succès/vide/403/500/timeout/retry/polling cleanup/accessibilité.
4. Rejouer le contrat unique de dates et DST déjà committé dans une suite verte attachée au SHA.
5. Parité jour fiscal/live/EOD et contenu chiffré PDF/Z sur remboursements/multi-tender.
6. Lier le restore drill au fichier/SHA courant, seuil 26 h et persister toutes les sorties d'échec.
7. Playwright dashboard/contrôle frais, multi-rôles, multi-viewport, sans skip inattendu.
8. Suites complètes vertes et logs bruts sur le même SHA source/bundle/serveur.
