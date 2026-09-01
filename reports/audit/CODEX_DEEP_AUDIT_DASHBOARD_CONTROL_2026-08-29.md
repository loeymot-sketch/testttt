# Audit profond — Dashboard et contrôle

Date : 2026-08-29  
Cycle observé : `CAISSE-SUPERVISOR-CONTROL-20260823` (`PHASE: AUDIT`)  
Périmètre : dashboard administrateur, cockpit « État du système », interrupteurs opérationnels, autorisations, isolation `branch_id`, vérité des signaux de santé, performance, accessibilité et couverture de tests.  
Mode : lecture, tests et navigation uniquement ; aucun fichier produit n'a été modifié.

## Verdict exécutif

**VERDICT: NEEDS_FIX**  
**RELEASE_READINESS: BLOCKED**  
**TEST_ASSURANCE: INCOMPLETE**

Le dashboard ciblé possède une base fonctionnelle et ses tests unitaires/feature dédiés passent, mais l'assurance « tout est testé » n'est pas atteinte. L'audit identifie cinq défauts P1 directement liés au dashboard/contrôle, plusieurs lacunes P2, un E2E frais impossible à exécuter à cause du gate de sécurité du dépôt, et des gates globaux rouges (backend, frontend, prix, invariants, bundles, i18n et dépendances).

Le cycle ne doit pas être clôturé ni présenté comme prêt production avant correction, reconstruction des bundles, exécution E2E fraîche et double audit PASS.

## Méthode et preuves

- Lecture du plan actif, du rapport d'exécution, des règles P0/P1, du contexte de mission et de la mémoire locale de secours. Graphiti n'était pas disponible dans cette session.
- Analyse statique des routes, contrôleurs, services, composants Vue, tests, commandes planifiées et sentinelles.
- Navigation réelle avec une session `POS Operator` persistée sur `http://localhost:8000` à 1366×768.
- Reproduction en lecture seule de cas de données via Tinker.
- Exécution des suites ciblées et globales disponibles.
- Revue UI selon les Web Interface Guidelines et revue des risques de synchronisation/invariants FoodKing.

Limite d'environnement : le serveur visible sur le port 8000 utilisait des bundles/URLs incohérents avec `.env` (`APP_URL=http://127.0.0.1:8766`). Les observations visuelles prouvent le comportement de l'instance courante, mais ne constituent pas une preuve fidèle du dernier source. Les API du cockpit échouaient dans cette instance et les images pointaient vers le port 8766.

## Matrice de validation

| Contrôle | Résultat | Preuve synthétique |
| --- | --- | --- |
| `npm run verify:boucle` | PASS | Contrat de boucle valide au début de l'audit. |
| Safety check pré-E2E | **HALT** | Zone frozen staged : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`; preuve de lock non reconnue par le hook. Aucun contournement effectué. |
| Backend ciblé dashboard | PASS | 35 tests passés, 8,28 s. |
| Backend ciblé santé/contrôle | PASS | 59 tests passés, 11,34 s. |
| Frontend ciblé dashboard | PASS | 5 fichiers, 60 tests passés ; 2 warnings Vue sur l'élément réservé `i`. |
| Couverture instrumentée | **INDISPONIBLE** | Aucun Xdebug/PCOV et aucun provider `@vitest/coverage-*` installé ; aucun pourcentage de lignes/branches ne peut être certifié sur ce snapshot. |
| Backend complet hors groupe manual | **FAIL** | 5 échecs, 5 332 succès, 36 ignorés, 2 incomplets ; 1 663,49 s. |
| Frontend Vitest complet | **FAIL** | 3 fichiers en échec, 459 passés ; 4 tests en échec, 3 810 passés, 3 ignorés ; 679,58 s. |
| E2E Playwright frais | **NON EXÉCUTÉ** | Bloqué par le safety check. `playwright-latest.json` a été remplacé par la collecte du 2026-08-29 : 0 exécuté, 47 ignorés. La version HEAD du 2026-08-24 indiquait 0 exécuté, 1 ignoré. |
| Collecte Playwright supervisor A→E | COLLECTÉE SEULEMENT | 17 tests dans 5 fichiers, 0 scénario dashboard/cockpit. Collecte ≠ exécution. |
| Collecte Playwright dashboard/admin | COLLECTÉE SEULEMENT | 47 tests dans 6 fichiers ; 2 scénarios cockpit admin-only dans une spec mutatrice beaucoup plus large. Aucun run frais. |
| `npm run pos:lint:status` | PASS | Aucun usage illégal de statut détecté. |
| `npm run pos:lint:pricing` | **FAIL** | 5 violations/allowances expirées, dont arithmétique frontend et wrappers de signoff manquants. |
| `composer invariants` | **FAIL** | 1 hit `branch_id`, 3 hits dispatch après commit, 15 hits audit sensible ; triage requis, sans assimiler tous les hits à des défauts confirmés. |
| `npm run i18n:audit` | **FAIL** | Vue : 13 clés FR manquantes, 119 EN, 487 AR, 765 DE, 766 BN ; Laravel : 2 FR, 24 EN, 35 AR, 55 DE, 52 BN. |
| `npm run perf:bundle-check` | **FAIL** | `public/js/app.js` 7 386 KB pour un budget de 5 000 KB ; plusieurs chunks kiosk dépassent leurs budgets. |
| `composer audit --locked` | **FAIL** | 7 avis sur 3 paquets (`laravel/framework`, `spatie/laravel-medialibrary`, `firebase/php-jwt`). |
| `npm audit --omit=dev` | **FAIL** | 19 vulnérabilités prod : 2 low, 4 moderate, 10 high, 3 critical. Exploitabilité non établie sans analyse de reachability. |
| `npm run codex:doctor` | **FAIL** | Exécutable plateforme Codex absent (`ENOENT`) ; audit final GPT obligatoire indisponible. |

### Revalidation de continuation

- 36 tests backend ciblés supplémentaires passent sur l'état courant : `DashboardBranchScopeMatrixTest`, `SalesSummaryAvgPerDayDivisorSentinelTest`, `SystemHealthTest`, `InterrupteurTest` et les tests POS health sélectionnés par le filtre.
- Les 60 tests Vitest dashboard ciblés repassent. Deux warnings Vue `component id: i` subsistent dans le smoke outbox.
- Ces PASS ne contredisent pas les findings : ils ne contiennent ni utilisateur dashboard branchless, ni GET cockpit non-admin, ni dates inversées, ni restauration backup, ni panne simultanée de toutes les queues.
- Les probes Laravel sans écriture reproduisent les écarts d'autorisation et d'isolation avec des réponses HTTP réelles du kernel.
- Le diagnostic du hook confirme un défaut de gouvernance : `.cursor/hooks/safety-check.sh:38-51` bloque inconditionnellement toute zone frozen staged et ne contient aucune logique de reconnaissance de lock. Le lock staged existe, mais sa contresignature propriétaire reste non cochée. Gate : `docs/gates/GATE_DASHBOARD_CONTROL_E2E_FROZEN_WORKTREE_2026-08-29.md`.

### Échecs backend complets

1. `ProfilePublishMidCartRejectionTest` : une option supprimée entre v1/v2 est acceptée en 201 au lieu de 422.
2. `AdminApiEnforcementDirectCallTest` : le scénario de modification des permissions reçoit 422 au lieu de 200/201.
3. `WithoutGlobalScopesAuditSentinelTest` : 14 appels pluriels non annotés à `withoutGlobalScopes()`.
4. La même sentinelle détecte une dérive de comptage : 26 occurrences réelles pour 12 attendues.
5. `Zone5PricingSsotConvergenceSentinelTest` : la sentinelle elle-même plante sur une regex invalide (`Unknown modifier ']'`).

### Échecs frontend complets

1. Le plafond d'identifiants E2E hardcodés est dépassé : 65 couples `(fichier,id)` pour un plafond de 56 et 28 fichiers pour un plafond de 24.
2. `public/js/app.js` est plus ancien que sa source d'ancrage.
3. `public/js/pos-app.js` est plus ancien que sa source d'ancrage.

## Constats prioritaires

### P1-01 — Le cockpit système est lisible par un caissier

**Impact :** violation du moindre privilège et exposition d'informations opérationnelles globales.

- `routes/api.php:1660-1671` place `system-health` et la lecture des interrupteurs dans le groupe admin authentifié, sans rôle métier dédié.
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:47-67` protège l'outbox mais pas `systemHealth()`.
- `app/Http/Controllers/Admin/Pilotage/InterrupteurController.php:25-42` ne protège que `update()` ; `index()` n'a pas de gate Admin/Tenant Admin.
- `resources/js/router/modules/observabilityRoutes.js:26-34` autorise la page via `permissionUrl: "dashboard"`.
- La session réelle `POS Operator`, qui possède `dashboard`, a pu ouvrir `/admin/observability/system` et voir le shell global, les explications backup/scheduler et l'en-tête des interrupteurs. L'écriture reste refusée par le contrôleur, mais la lecture ne l'est pas.
- Une requête interne via le kernel Laravel avec le même rôle confirme les statuts exacts : `GET system-health = 200`, `GET interrupteurs = 200`, `GET outbox = 403`.
- `SystemHealthTest` ne teste qu'Admin et non authentifié. `InterrupteurTest` teste le refus non-admin de PUT, pas celui de GET. `AdminRoutePermissionFloorTest.php:57-63` attribue à tort la protection de GET à la ligne 38 du contrôleur, alors que cette ligne appartient à `update()`.

**Attendu :** permission/role explicite et symétrique sur route, API et navigation, avec tests 401/403 pour un utilisateur authentifié non-admin.

### P1-02 — L'isolation `branch_id` échoue en mode fail-open pour un non-admin sans branche

**Impact :** fuite inter-branches latente si un utilisateur non-admin reçoit la permission dashboard sans `branch_id` valide.

- `app/Services/DashboardService.php:43-53` transforme `branch_id <= 0` en `null`, ensuite interprété comme absence de filtre sur plusieurs requêtes dashboard.
- `app/Services/DashboardService.php:895-911` applique le filtre aux audits seulement lorsque `actorBranchId > 0`; sinon tous les logs deviennent accessibles.
- `DashboardBranchScopeMatrixTest` couvre le manager de branche et Admin, mais pas le non-admin branchless.

L'état E2E courant ne contient aucun non-admin branchless persistant avec la permission dashboard. Une reproduction sans écriture, en remplaçant seulement `branch_id` en mémoire sur le `POS Operator`, obtient néanmoins `HTTP 200`, `total_orders=3246` et un audit trail global. Le défaut reste contraire à l'invariant : l'absence de branche doit refuser, jamais élargir.

### P1-03 — Une plage de dates inversée provoque une erreur serveur

**Impact :** 500 reproductible et déni de service fonctionnel du résumé des ventes.

- `app/Services/DashboardService.php:228-299` accepte des dates arbitraires, ne borne pas la fenêtre et divise par `count($data)`.
- Avec `first_date=2026-08-30` et `last_date=2026-08-01`, la boucle ne produit aucun jour et la division de la ligne 292 déclenche `DivisionByZeroError`.
- `app/Http/Controllers/Admin/DashboardController.php:113-120` attrape `Exception`, pas `Throwable`; l'erreur s'échappe.
- Aucun test ciblé ne couvre date inversée, date invalide ou fenêtre maximale.

**Attendu :** validation 422 explicite, ordre des bornes imposé, durée maximale bornée et test de non-régression.

### P1-04 — Le statut backup peut afficher un faux vert

**Impact :** le cockpit affirme une restauration/retention que le backend ne prouve pas, avec divergence possible par rapport à readiness.

- `SyncOverviewController::systemHealth()` cherche seulement le `.gz` le plus récent sous `storage/backups/db-daily` et utilise son mtime avec un seuil de 30 h.
- `resources/js/components/admin/observability/SystemHealthComponent.vue:77-88` affirme que la sauvegarde est restaurée à 05:00 et conservée six ans.
- `app/Console/Kernel.php:195-210` exécute `backup:verify-restore` et écrit seulement dans un log ; aucun résultat vérifié n'alimente le cockpit.
- `app/Http/Controllers/Api/HealthController.php:196-207` exige `*.sql.gz` et dégrade après 26 h. Un fichier âgé de 28 h peut donc être vert dans le cockpit et rouge dans readiness ; un `.gz` non SQL peut satisfaire le cockpit.
- Aucun test de `SystemHealthTest` ne couvre le résultat de restauration ni la parité des seuils.

**Attendu :** une source de vérité unique et persistée : fraîcheur, type de fichier, checksum/restauration testée, timestamp, statut et cause d'échec.

### P1-05 — `/api/healthz` peut déclarer la file saine quand sa mesure échoue

**Impact :** faux vert de supervision lors d'une panne de driver/connexion queue.

- `app/Http/Controllers/Api/HealthzController.php:224-243` ignore chaque exception de `Queue::size()` et retourne 0 lorsque tous les probes échouent.
- La file en attente n'entre pas dans `statusChecks` (`HealthzController.php:56-64`) ; même une saturation ne change pas le statut HTTP.
- `tests/Feature/FilesSurveilleesTest.php:146-152` exige seulement un entier et accepte explicitement 0 en cas d'échec driver. `HealthzEndpointTest` ne vérifie que type et positivité.

Le comportement non-Pusher « informatif OK » est une décision connue et testée ; il n'est pas reclassé comme bug dans cet audit.

### P1-06 — La stratégie E2E active ne couvre pas le dashboard demandé

**Impact :** une campagne dite « supervisor » peut passer sans exercer une seule route du dashboard ou du cockpit système.

- Les cinq specs `audit-supervisor-waveA` à `waveE` collectent 17 tests, mais couvrent suivi commandes, caisse, argent, KDS, écran client et roue. Elles ne référencent ni `/admin/dashboard` ni `/admin/observability/system`.
- Le corpus séparé dashboard/admin collecte 47 tests dans six specs, mais n'a pas été exécuté fraîchement sur ce snapshot.
- Les deux scénarios cockpit se trouvent aux lignes 1023 et 1072 de `admin-operations-crud-functional.spec.js`, une spec de 27 scénarios dont beaucoup créent/modifient des données. Le premier ne vérifie qu'un GET admin 200 ; le second bascule `wheel` avec restauration. Aucun ne teste POS/Chef/manager en 403, erreur de probe, backup non restaurable, queue inconnue ou confirmation opérateur.
- `dashboard-sidebar-permission-filtering.spec.js:60-67` normalise explicitement POS Operator vers `/admin/dashboard` et se limite à vérifier que Transactions/Employés/Clients sont masqués. Il ne vérifie pas que le cockpit global est absent/inaccessible.
- Le fichier autoritaire `reports/antigravity/playwright-latest.json` contient désormais 0 succès et 47 skips : la commande `--list` avec le reporter JSON l'a remplacé par une collecte, pas une exécution. La version HEAD contenait déjà 0 succès et 1 skip. Aucune des deux ne prouve un run vert récent.

**Attendu :** une campagne dédiée dashboard/contrôle, collectée et exécutée, avec matrice de rôles négative et scénarios d'erreur réels. Une simple collecte ou une capture visuelle ne vaut pas validation.

## Constats P2

### P2-01 — Les alertes SLA sont bornées dans l'UI, pas dans l'API

`app/Services/DashboardService.php:490-524` charge tous les tickets de la fenêtre 24 h. `SlaAlertsComponent.vue:150-160` n'en affiche que six après réception. Le test frontend valide la limite DOM, mais aucun test n'impose un plafond/pagination serveur. Risque mémoire et latence durant un rush.

### P2-02 — Les bascules sensibles ne disposent pas d'une preuve d'audit métier durable

`InterrupteurController::update()` (`:38-57`) écrit un `Log::info`, pas un `AuditLog` immuable/chaîné, alors que l'UI indique « Chaque bascule est tracée ». Les bascules influencent remises, fidélité, promotions et impression. Une action prend effet au premier clic sans confirmation explicite (`SystemHealthComponent.vue:123-135,233-244`).

### P2-03 — États d'erreur et permission frontend trop permissifs

- `DashboardComponent.vue:133-147` autorise certains accès rapides si la table de permissions est absente ou incomplète.
- `LastZReportWidget.vue:79-89` applique le même fail-open pour le chargement du rapport Z.
- `OverviewComponent.vue:59-97` partage un seul loader entre trois requêtes parallèles : la première terminée masque le chargement des deux autres ; les erreurs sont avalées et les KPI restent vides.
- `RealtimeReportComponent`, `ChannelStatsComponent` et `AuditTrailComponent` n'offrent pas d'état robuste d'erreur/retry pour leur polling.
- Sur l'instance naviguée, « Contrôle SLA indisponible » apparaissait et plusieurs valeurs restaient vides/à zéro sans diagnostic exploitable.

### P2-04 — Couverture frontend directe incomplète

Huit composants dashboard n'ont aucune référence dans les tests : `ChannelStatsComponent`, `CustomerStatsComponent`, `FeaturedItemsComponent`, `MostPopularItemsComponent`, `OrderStatisticsComponent`, `RealtimeReportComponent`, `SalesSummaryComponent`, `TopCustomersComponent`. `AuditTrailComponent` n'est que mentionné dans le commentaire d'une sentinelle backend ; il n'est pas monté/testé comme composant.

`SystemHealthComponent.vue` n'a pas de test unitaire direct. Le seul scénario E2E de contrôle opérationnel n'a pas été rejoué sur le snapshot courant.

### P2-05 — Accessibilité et robustesse visuelle incomplètes

- Statistiques dynamiques sans `aria-live`.
- Barres de progression sans rôle/valeurs ARIA explicites.
- Icônes décoratives sans `aria-hidden` dans plusieurs widgets.
- `window.alert` pour les erreurs de clôture EOD (`DashboardComponent.vue:248-265`) au lieu d'un message inline focusable/annoncé.
- Images avec alt génériques, dimensions absentes et clés Vue basées sur l'objet dans plusieurs classements.
- États vides/erreurs et styles `focus-visible` non uniformes.
- À 1366×768, SLA et contrôle système sont sous le pli : le cockpit principal montre d'abord accès rapides et statistiques.

### P2-06 — Coûts de calcul et poids bundle élevés

- `DashboardService.php:319-343` effectue 18 `COUNT` séquentiels à chaque chargement des états clients ; la mesure runtime confirme 19 requêtes pour un seul appel `customerStates()`.
- `DashboardService.php:542-546` charge les commandes du jour en PHP puis filtre les collections au lieu d'agréger en SQL.
- `public/js/app.js` pèse 7 386 KB, au-dessus du budget 5 000 KB.

### P2-07 — Les endpoints renvoient des détails d'exception

Plusieurs méthodes de `DashboardController` renvoient directement `$exception->getMessage()` à l'appelant authentifié. `/api/health/ready`, public, inclut également des messages bruts par sous-système (`HealthController.php:97-140`). Une analyse de reachability doit distinguer les messages anodins des détails SQL/infrastructure exposables.

## Risques transverses et gates rouges

- **Prix SSOT :** `pos:lint:pricing` signale 5 écarts, dont `PosCounterCollectModal.vue:562` et trois sites dans le wizard kiosk. Aucun correctif n'a été tenté car le wizard est frozen/staged et hors périmètre dashboard.
- **`branch_id` :** le défaut P1-02 est confirmé dans le dashboard ; la sentinelle globale détecte en plus 14 appels pluriels non annotés à `withoutGlobalScopes()` dans une commande staged.
- **Dispatch après commit :** le guard global signale 3 sites dans `ItemService.php`; ils nécessitent une revue de flux avant qualification finale.
- **Symétrie OrderService/FrontendOrderService :** les tests de contrat et de stock passent. Aucun de ces services n'a été modifié par cet audit.
- **Frozen zones :** le hook bloque correctement l'exécution E2E. Aucune zone frozen n'a été touchée.
- **Dépendances :** 7 avis Composer et 19 avis npm interdisent d'affirmer un niveau de sécurité production sans triage, plan de migration et tests de compatibilité.

## Lacunes du jeu de tests à fermer

1. Accès GET refusé à `system-health` et `interrupteurs` pour POS Operator, Chef, manager de branche et utilisateur dashboard custom.
2. Non-admin `branch_id=null/0` : toutes les méthodes dashboard et audit trail doivent refuser sans requête globale.
3. Dates inversées, invalides, futures et fenêtres excessives.
4. Cohérence cockpit/readiness lorsque le backup est absent, trop ancien, extension invalide ou restauration échouée.
5. Tous les drivers de queue en erreur : statut `unknown/degraded`, jamais 0/healthy.
6. Seuil SLA serveur, volume extrême et pagination.
7. Traçabilité métier d'un toggle : acteur, ancien/nouvel état, branche/tenant, horodatage et corrélation.
8. Chargement partiel, timeout, 401/403/500, retry et rafraîchissement concurrent pour chaque widget.
9. Clavier, focus, lecteur d'écran, contrastes, états live, 390×844, 768×1024 et 1366×768.
10. E2E dashboard/contrôle dédié : API 401/403, navigation par rôle, santé dégradée/inconnue, backup, queue, erreurs réseau, polling et toggle réversible.
11. E2E rond 3 des cinq specs supervisor A→E pour le périmètre historique du cycle, distinct de la preuve dashboard.

## Conditions minimales de sortie

- Corriger P1-01 à P1-06 et ajouter les tests négatifs/limites correspondants.
- Résoudre le gate frozen avec une preuve de lock/gate reconnue, sans contournement.
- Obtenir zéro échec sur suites dashboard ciblées, backend complet et frontend complet.
- Réparer les sentinelles avant de les utiliser comme preuve (regex pricing, inventaire `withoutGlobalScopes`, fixtures E2E).
- Reconstruire les bundles, repasser fraîcheur et budgets.
- Triage documenté des audits Composer/npm avec upgrade ou acceptation humaine explicite.
- Exécuter un E2E Playwright dashboard/contrôle dédié sur desktop/tablette/mobile, avec preuves de rôles, 403, erreurs réseau et cohérence santé.
- Rejouer séparément A→E pour le périmètre supervisor historique ; ne pas l'utiliser comme substitut à la preuve dashboard.
- Obtenir `AUDIT_VERDICT: PASS` puis `GPT_FINAL_AUDIT_VERDICT: PASS` ; le binaire Codex doit être réparé pour la seconde preuve.

## Fichiers générés ou modifiés par l'audit

L'audit i18n a régénéré quatre CSV sous `reports/i18n/` (`dead_keys_VUE`, `identical_fr_en_VUE`, `missing_keys_per_locale_LARAVEL`, `missing_keys_per_locale_VUE`). La collecte Playwright `--list` a aussi remplacé `reports/antigravity/playwright-latest.json` par un artefact où les 47 cas sont marqués `skipped`; il ne doit pas être interprété comme une exécution. Ce sont des artefacts de diagnostic. Aucun fichier applicatif n'a été modifié.
