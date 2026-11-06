# Contre-audit profond — Dashboard et contrôle (continuation)

Date : 2026-09-02
Source : `reports/audit/CODEX_DEEP_AUDIT_DASHBOARD_CONTROL_2026-08-29.md`
Mode : lecture, comparaison des preuves, inspection statique et vérification de gouvernance. Aucun fichier produit modifié.

## Mise à jour de clôture — 2026-09-03

Cette section remplace les états de snapshot plus anciens conservés plus bas pour la traçabilité.

- HEAD final relevé : `28cd79d5ab43ba258bcf69bedef1d84d6dc918e4`. Le passage depuis `5d93c471b` pendant Vitest ne contient qu'un document de production ; aucun fichier `app/`, `resources/`, `routes/` ou `tests/` n'a changé pendant la suite.
- `bash .cursor/hooks/safety-check.sh` : **PASS**. Ce résultat ne remplace pas la sentinelle SHA frozen, qui reste rouge.
- PHPUnit ciblé dashboard/contrôle : Dashboard `64/64`, Observability `96/96`, Pilotage `20/20`, ressource caisse `6/6`, garde caissier interrupteur `2/2` — **188 tests verts**.
- Vitest ciblé dashboard/cockpit/caisse : **247/247**, 15 fichiers.
- Playwright dashboard + cockpit : **1/1** sur Chromium, trois pages, aucune réponse HTTP fautive ni clé i18n brute. Le banc collecte toutefois des refus WebSocket/connexion sans les faire échouer : ce PASS n'est pas un PASS console propre.
- Playwright caisse sur le bundle réellement servi : **5 échecs consécutifs**, puis arrêt volontaire des retries identiques (`1` test interrompu, `5` non exécutés). Les badges, cartes et panneaux `pos-control-*` attendus sont absents du DOM. Cela confirme fonctionnellement les sentinelles de fraîcheur : le tiroir existe dans le source mais n'est pas livré par `public/js/pos-app.js`.
- Vitest complet : **460 fichiers verts, 4 rouges ; 3 917 tests verts, 4 rouges, 3 ignorés** sur 464 fichiers / 3 924 tests. Les quatre rouges sont exclusivement les sentinelles de fraîcheur `admin-reports`, `app`, `admin-kds` et `pos-app` : les bundles servis sont antérieurs à leurs sources.
- Rejeu backend critique : `PrinterControllerTest` `6/6` et `PrinterHostAllowlistSentinelTest` `6/6` verts ; `FrozenZoneSha256BaselineSentinelTest` rouge, `WithoutGlobalScopesAuditSentinelTest` rouge (compte attendu 26, trouvé 12), `Zone5PricingSsotConvergenceSentinelTest` rouge (cliquet `KitchenBundledAddonCollapser`).
- La dernière suite PHPUnit complète consignée sur cette branche reste rouge : **5 542 tests, 23 020 assertions, 10 échecs**. Les replays ci-dessus prouvent que plusieurs défauts d'impression ont depuis verdi, mais ils ne transforment pas ce run complet en certification verte.
- `bash scripts/check-invariants.sh -v` sort `1` : 3 familles rouges / 19 occurrences (`branch_id`, dispatch après commit, audit actions sensibles). Le garde est en partie textuel et produit des faux positifs documentaires, mais un pipeline de release ne peut pas l'ignorer tant que chaque occurrence n'est pas classée ou corrigée.

### Ce qui est réellement corrigé depuis le snapshot `47c001fbb`

- Le dashboard masque maintenant les détails d'exception au client et journalise l'erreur interne ; `popular-items` passe par le garde fail-closed de branche. La matrice HTTP directe couvre désormais les 16 routes dashboard au moins une fois.
- Le widget NF525 ne prétend plus qu'un simple préfixe prouve l'intégrité ; il affiche branche et heure exacte.
- L'API outbox et le Vue utilisent tous deux `delivered_24h`; le composant possède maintenant un état périmé, un message d'erreur, un verrou de lecture, des retours d'action accessibles et une confirmation de purge.
- Le bouton de paiement fractionné produit désormais une entrée `audit_logs`; les tests de refus d'audit et d'accès caissier passent.
- Les dates civiles, inversions, DST, date EOD serveur et libellés français ont des tests ciblés verts.

### P1 encore ouverts au terme de l'audit

1. **Tiroir caisse tronqué au-delà de 100 commandes.** `PosComponent.vue:4780-4785` demande une seule page de 100, triée par défaut du plus récent au plus ancien, puis présente cette page comme toute la journée de service. En rush, les plus anciennes attentes peuvent disparaître ; compteurs et rang cuisine deviennent faux. Aucun test >100 ne l'exerce.
2. **Enum métier dupliquée dans le frontend.** `filesControle.js:18-23` et `fileCuisine.js:34-40` recopient les valeurs numériques de `OrderStatus`, `PaymentStatus`, `OrderType` et moyen de paiement malgré les enums JS canoniques disponibles. Les tests verrouillent la copie au lieu de prouver la convergence ; une évolution serveur peut faire diverger le contrôle silencieusement.
3. **États outbox critiques toujours invisibles.** `terminalFailures`, `inFlight` et `staleClaimed` sont chargés, mais aucun bloc ne les rend ; `terminalFailures` ne sert qu'à activer des boutons. Le cockpit peut donc connaître des claims orphelins sans les montrer.
4. **Retour retry incorrect.** L'API renvoie `requeued` (`SyncOverviewController.php:477-481`) ; le Vue lit `data.retried` (`OutboxOverviewComponent.vue:509`). L'opérateur n'obtient jamais le nombre réel relancé.
5. **Sémantique outbox non convergée.** `terminal_failures` inclut toute ligne avec `last_error`, même à une tentative ; la sonde worker lit toute queue réservée, pas spécifiquement `high`; la purge applique `limit(500)` avant le filtre métier PHP ; l'audit écrit `deleted=N` avant le `DELETE`. La confirmation affiche en outre le nombre de `domain_events` terminaux alors que l'action supprime des lignes distinctes de `failed_jobs`.
6. **Preuve de restauration encore falsifiable.** Le vert ne compare ni nom ni SHA du dernier backup au fichier réellement restauré ; `MAX_AGE_HOURS=48` contredit le contrat 26 h ; plusieurs sorties `FAILURE` de `backup:verify-restore` ne persistent pas un rouge et peuvent laisser survivre un ancien vert.
7. **Audit interrupteur avant mutation.** Le journal immuable est écrit avant `InterrupteurService::regler()`. Si la mutation échoue ensuite, l'audit affirme une bascule réussie. Le texte UI `SystemHealthComponent.vue:119` dit encore à tort « journal serveur, pas le journal fiscal NF525 ».
8. **Bundles de production non convergés.** Quatre sentinelles Vitest prouvent que les sources ne correspondent pas aux bundles distribués. Le Playwright caisse confirme l'impact : cinq scénarios tombent parce que les éléments `pos-control-*` n'existent pas dans le DOM servi. Le Playwright dashboard a testé le bundle présent, pas l'ensemble des sources actuelles.
9. **État opérationnel de la machine testée dégradé.** Le cockpit mesuré indique worker outbox et WebSocket arrêtés, 10 920 événements en attente, 249 jobs `high` et un job échoué. C'est l'environnement local/E2E, pas une mesure de production, mais il interdit d'y conclure à une chaîne temps réel opérationnelle.
10. **Préparation production à sécuriser.** Le relevé SSH versionné signale un `pre-deploy` gzip de 20 octets à côté de deux sauvegardes plausibles, ainsi qu'un script de déploiement exigeant PHP 8.4 sur un hôte PHP 8.1. Aucun déploiement ne doit sélectionner le fichier de 20 octets ni utiliser le script sans alignement explicite.

### Verdict de clôture

**VERDICT: NEEDS_FIX**  
**RELEASE_READINESS: BLOCKED**  
**TEST_ASSURANCE: CIBLÉE VERTE, GLOBALE ROUGE**

Le dashboard/cockpit possède désormais une base solide et ses tests ciblés passent, mais « tout est testé et prêt » serait faux : suite frontend complète rouge, suite backend complète non verte, trois sentinelles backend critiques rouges, bundles périmés et défauts P1 fonctionnels encore ouverts.

## Verdict

**VERDICT: NEEDS_FIX**

**RELEASE_READINESS: BLOCKED**

**TEST_ASSURANCE: NON REPRODUCTIBLE SUR LE SNAPSHOT COURANT**

Quatre défauts P1 du 29 août ont un correctif présent dans le code et une sentinelle PHPUnit dédiée : lecture cockpit réservée Admin/Tenant Admin, dashboard non-admin sans `branch_id` refusé, dates inversées en 422 et queue illisible en `unknown`. Le contrat backup a aussi été aligné sur `*.sql.gz` et 26 h. Le dernier commit qui touche le cockpit reste `179e27d9984d0ab8addfcdbf4551d74a64616e74`, avec une correction backend de la sémantique outbox et des actions de replay/drain.

Cela ne suffit pas à certifier le dashboard courant. La preuve « absolue » du 1er septembre est explicitement ciblée, reconnaît une recapture manquante, a ignoré un diff frozen staged et ne contient pas de campagne Playwright cockpit fraîche. Surtout, `179e27d99` casse le contrat entre l'API et le composant outbox : l'API ne renvoie plus `dispatched_24h`, tandis que le Vue ne lit que cette clé. Le safety gate officiel reste rouge et aucune suite complète verte n'est disponible sur ce SHA.

### Snapshot post-commit contrôlé — HEAD actuel

- HEAD au dernier relevé : `47c001fbb1997e1e4d22eba522df66355a4c8990`. Il ajoute le contrat dates/DST/EOD et le garde `popular-items` au lot santé/restore de `28c2771a8`; le lot outbox reste celui de `179e27d99`.
- Vitest : le cache est réécrit par des runs ciblés concurrents et n'est pas une preuve stable. Le JSON complet le plus exploitable donne 469 fichiers, 3 928 tests, 10 échecs et 3 skips; sept fichiers sont rouges : `backendMenuVirtualChildrenOverride`, `composerEditorV2`, `noVacuousSpecSentinel`, `posTrackerCanalLisible` (4), `sidebarV1Cleanup`, `appBundleFreshnessSentinel`, `posAppBundleFreshnessSentinel`. Deux sentinelles signalent notamment les bundles devenus plus vieux que `en.json`. Le run a chevauché des commits/edits, il est donc rouge **et** non rattachable à un snapshot immuable.
- Après rebuild, `vitest-full-run-A.txt` apporte enfin un log complet conservé : 470 fichiers, 7 fichiers rouges, 4 tests en échec et 3 skips. Six fichiers POS échouent au chargement (`PosComponent`, disponibilité, animations, accessibilité, sonnerie, discount) et `posTrackerCanalLisible` porte les quatre assertions rouges. Un rerun ciblé ultérieur de huit fichiers POS est vert, mais ne couvre pas `posTrackerCanalLisible` et ne remplace pas une suite complète verte; il confirme surtout que le worktree mobile rend les résultats non reproductibles.
- Build : `public/js/app.js` et `public/mix-manifest.json`, ignorés par Git, ont été reconstruits à `02:04:50/51`; SHA-256 respectifs `f7dd10d7…` et `1e9eaf9e…`. Le build existe, mais la parité bundle réellement servi/reporter navigateur n'est pas encore démontrée.
- PHPUnit : le run externe du checkout courant s'est arrêté sans résumé à `4 819/5 444` (88 %), après avoir affiché deux échecs et une erreur (`FF…E`). Ce run est incomplet et non vert. Le run complet observé dans `.claude/worktrees/port-e2e-0902` a fini rouge : `5 761` tests, `23 211` assertions, `2` erreurs, `16` échecs, `36` skips et `6` incomplets. Cette worktree repose sur un autre HEAD (`ef0d7b0f1`) et ne certifie donc pas `179e27d99`.
- Diagnostic de la worktree `port-e2e-0902` : Vitest a terminé rouge avec `8` fichiers en échec, `13` tests en échec, `4 036` verts et `3` skips sur `504` fichiers. Cinq sentinelles signalent des bundles absents, `KeyboardNavigationSentinel` échoue aussi faute de `public/css/app.css`, et `sauceChipsCouleurs` détecte un chemin sans classe couleur. L'échec dashboard `stockLowAlertsWidgetCountBadge` correspond à une régression propre au port : `canFetchAlerts()` y retourne `true` quand les permissions sont vides/absentes, contrairement au checkout audité qui retourne `false`; le test de refus est en cours de suppression dans cette worktree. Ce changement ne doit pas être porté. Le PHPUnit de cette worktree était déjà rouge à 40 % avec au moins douze marqueurs `F`; son résultat reste diagnostique car le base HEAD et les fichiers staged diffèrent.
- Playwright autoritaire : reporter toujours daté du 29 août, donc aucune preuve navigateur de `179e27d99` ni du cockpit. Une campagne séparée `goal-caisse-controle-2026-09-02.spec.js` a cependant fini `passed` le 2 septembre : 11 scénarios de tiroir POS/cuisine/encaissement/livré et navigation sans rechargement, 10 captures, ouverture `/admin/pos-v4` mesurée à 86 ms. Cette spec est non committée, n'exerce pas le cockpit Admin et n'est pas publiée dans le reporter autoritaire ; c'est une preuve ciblée utile, pas une certification dashboard/contrôle globale.
- Frozen : `KioskWizardComponent.vue` reste staged avec 53 insertions et lock sans contresignature ; safety `BLOCKED`.

## Delta critique — contre-audit du commit `179e27d99`

Le correctif backend P1-J est réel : `broadcast_at` porte désormais la livraison, les claims récents/orphelins sont séparés et les sondes ne prennent plus le dernier claim outbox pour une diffusion réussie. Le correctif P1-K est également substantiel : verrou commun, service de replay commun, audit par événement, conservation de `attempts`/`last_error`, filtre de purge sur `DispatchDomainEventsJob` et export avant suppression.

Il n'est toutefois pas complet de bout en bout :

1. **P1-M — rupture API/UI immédiate.** `SyncOverviewController` renvoie seulement `delivered_24h`, alors que `OutboxOverviewComponent.vue:365` lit seulement `data.dispatched_24h`. Le composant conserve donc son objet initial à zéro. Il ne déclare ni ne rend `terminal_failures`, `in_flight` ou `stale_claimed`. Le plan autoritaire exigeait pourtant de conserver `dispatched_24h` comme alias déprécié pendant une release, puis de migrer le composant en W4 (`GOAL...:201-209,240`). Aucun test JS ne couvre ce contrat : le sentinel ne vérifie que les cinq anciennes sections et le montage.
2. **P1-N — `terminal_failures` inclut les échecs récupérables.** La requête classe terminal toute ligne `dispatched_at IS NULL AND last_error IS NOT NULL`, sans seuil `attempts`. Or `DispatchDomainEventsJob` remplit `last_error` après chaque tentative échouée et libère le claim, avant l'épuisement des six essais. Le nouveau test verrouille même comme « terminal » un événement à `attempts=1`. La définition du plan demandait des attempts épuisés.
3. **P1-O — sonde `queue_work` non bornée à la lane `high`.** `probeHealth()` cherche n'importe quelle ligne `jobs.reserved_at` sans `where('queue', 'high')`. Un worker actif sur une autre file peut donc afficher le worker outbox comme `up` alors que la lane outbox est morte.
4. **P1-P — purge bornée avant filtrage.** La requête prend les 500 plus vieux `failed_jobs`, puis filtre la classe en PHP. Si ces 500 lignes sont étrangères, un `DispatchDomainEventsJob` plus récent mais encore au-delà du cutoff n'est jamais atteint, même après des clics répétés. Il faut filtrer côté SQL de manière sûre, ou paginer jusqu'à obtenir au plus 500 candidats outbox.
5. **P1-Q — audit de drain décrit une suppression avant qu'elle réussisse.** La ligne `outbox.drain` enregistre `deleted=count(ids)` avant le `DELETE`. Une suppression qui échoue laisse une preuve immuable affirmant à tort que les lignes ont été supprimées. Le test couvre uniquement l'échec d'écriture de l'audit, pas l'échec entre audit et delete ni une suppression partielle.
6. **P1-L toujours ouvert.** Le composant n'a toujours aucun `catch`, état stale, confirmation ou annonce accessible pour retry/drain. Un réseau cassé conserve l'ancien vert et les mutations peuvent échouer silencieusement.
7. **Documentation incohérente.** `docs/OUTBOX_PATTERN.md` décrit encore `dispatched_at` comme livraison réussie et le runbook expose encore `dispatched_24h`/sondes fondées sur `dispatched_at`.

La mention « 181 tests verts » du commit n'est pas reproductible depuis les artefacts : aucun log brut/JUnit de ce run n'est versionné. Le seul log brut de la mission est `phpunit-baseline-cible.log`, antérieur au commit, avec 359 tests et 1 erreur. Les nouveaux tests ciblés peuvent être pertinents sans constituer une certification du snapshot complet.

## Delta critique — lot restore-drill committé dans `28c2771a8`

Le lot P1-A/P1-H ajoute bien une preuve persistée, l'agrège au cockpit et à `/health/ready`, corrige les timestamps invalides/futurs et compare l'âge du fichier avant arrondi. Il a cependant été committé sans corriger les trois faux verts détectés pendant son contre-audit :

1. **P1-R — un ancien drill peut attester le mauvais backup.** Le cockpit décide `sauvegardeOk` avec « fichier courant frais » ET « dernier drill green », sans vérifier que `restauration.file` ou son SHA correspond au `dernier_fichier` affiché. Un nouveau dump corrompu peut donc arriver après un ancien drill vert et être présenté comme « réellement remonté ». Aucun test n'injecte deux noms/SHA différents.
2. **P1-S — seuil du plan non respecté.** Le plan exige qu'un drill de plus de 26 h dégrade readiness/cockpit. `RestoreDrillResult::MAX_AGE_HOURS` vaut 48 et les tests ne couvrent que 60 h/121 h. Une preuve vieille de 27 à 48 h reste donc verte contrairement au contrat.
3. **P1-T — des échecs de commande ne remplacent pas l'ancien vert.** `backup:verify-restore` persiste l'échec seulement pour « aucun fichier », l'échec de restore et le verdict final. Les sorties précoces driver non MySQL, base live absente, scratch=live, fichier illisible, lecture live impossible et création scratch impossible retournent `FAILURE` sans écrire `RestoreDrillResult`. Un dernier succès peut alors rester vert après l'échec du drill courant. Le plan demandait explicitement la persistance du succès **et de l'échec**; le test Feature nommé dans le plan (`RestoreDrillResultIsPersistedTest`) n'existe pas.

Preuve d'exécution nouvelle : `phpunit-cible-apres-sub4.txt` termine avec 384 tests, 2 035 assertions, 1 erreur et 2 skips. L'erreur `OrderStatisticsSingleGroupedQueryTest` a ensuite été corrigée dans le commit. Le message de commit annonce « PHPUnit ciblé 384 tests, 0 échec », mais aucun nouveau log brut correspondant n'est joint : l'unique artefact versionnable contredit encore cette affirmation. Un premier Vitest complet donne 468 fichiers, 5 fichiers en échec, 9 tests en échec, 3 skips et 1 erreur; la relance JSON donne 469 fichiers, 10 tests en échec et 3 skips, avec les sept fichiers listés dans le snapshot ci-dessus. Ce sont des résultats rouges exécutés sur un worktree mobile, pas une certification.

## Delta critique — commit dates/DST/EOD `47c001fbb`

Ce commit ferme statiquement trois familles de défauts du rapport : les quatre endpoints datés partagent `resolveDashboardWindow()`, refusent borne isolée/date impossible/inversion/>366 jours; `salesSummary` itère maintenant par jours civils avec `CarbonPeriod` et expose les libellés; le PDF EOD refuse une date calendaire impossible et laisse le serveur choisir le jour. Le garde `popular-items` appelle aussi désormais le fail-closed branche avant `ItemService`. Les nouvelles sentinelles sont ciblées et cohérentes avec ces changements.

La preuve n'est néanmoins pas convergée : le commit annonce 187 PHPUnit et 51 Vitest verts, sans log brut/JUnit joint. Le PHPUnit complet déjà actif lors du commit avait démarré sur `28c2771a8` puis a continué alors que les fichiers PHP changeaient; quel que soit son résumé, ce run hybride ne peut certifier ni l'ancien ni le nouveau SHA. Les deux tests HTTP du garde `popular-items`/matrice six routes existent encore en non suivi au dernier relevé, donc ils ne font pas partie de `47c001fbb`. Enfin, le périmètre comptable ouvert demeure : parité chiffrée PDF/Z, remboursements et multi-tender ne sont pas vérifiés; le libellé UI parle toujours de « clôture » pour une simple synthèse.

## Delta vérifié depuis le 29 août

| Finding initial | État du code courant | État de preuve |
| --- | --- | --- |
| P1-01 cockpit lisible par POS | Corrigé statiquement : `SyncOverviewController::systemHealth()` et tout `InterrupteurController` sont sous `role:Admin|Tenant Admin`; route Vue passée de `dashboard` à `settings`. | PHPUnit dédié présent et committé. Le run complet du checkout s'est interrompu non vert à 88 % ; la capture POS round-5 prouve une redirection et un PUT 403, pas la matrice GET complète. |
| P1-02 `branch_id` fail-open | Corrigé statiquement : `DashboardService::dashboardBranchId()` répond 403 pour un non-admin sans branche. | Un endpoint `total-orders` est couvert. Aucune preuve fraîche que chaque méthode dashboard, notamment l'audit trail, reste fail-closed après les changements du 2 septembre. |
| P1-03 dates inversées | Renforcé dans `47c001fbb` : contrat commun aux quatre endpoints, date calendaire stricte, borne isolée refusée et fenêtre maximale validée. | Matrice PHPUnit dédiée présente dans le commit; résultats ciblés seulement attestés, pas de log brut ni full vert sur ce SHA. |
| P1-04 backup faux vert | Partiellement corrigé : filtre `*.sql.gz`, seuil 26 h et texte UI honnête indiquant que la restauration de 5 h n'est pas lue. | **Ouvert** : le verdict global peut encore dire « Tout va bien » alors qu'un restore drill a échoué, car son résultat n'est ni persisté ni agrégé. |
| P1-05 queue faussement à 0 | Corrigé statiquement : probe partiel/total illisible lève, payload `unknown`, statut non `ok`. | Test dédié présent ; le run complet du checkout n'a pas atteint de verdict final. |
| P1-06 stratégie E2E absente | Non corrigé. | `playwright-latest.json` ne contient qu'un scénario dashboard exécuté le 29 août, antérieur aux correctifs/captures du 1er septembre. Aucun scénario cockpit admin n'y figure. |

## Preuves nouvelles contre-auditées

### 1. Le certificat Grok n'est pas une preuve de convergence

`reports/grok/CERTIFICAT_VALIDATION_ABSOLUE.md` dit lui-même :

- « ciblé, pas FoodKing entier » ;
- « Pas 2e round identique preview » ;
- seulement 83 tests PHPUnit ciblés et 39+7 tests frontend annoncés ;
- aucune suite backend/frontend complète ;
- aucun raw log/JUnit joint permettant de reproduire les nombres annoncés.

Le handoff du 2 septembre qualifie lui-même le résultat de **JAUNE**, liste 14 travaux non faits et confirme l'absence de `CONVERGENCE_FINAL`.

### 2. Le contrôle frozen du certificat est un faux négatif

La commande prescrite dans le GOAL était `git diff --stat -- <frozen>`. Elle n'inspecte pas l'index. Or :

- `git diff HEAD --stat -- resources/js/components/frontend/kiosk/KioskWizardComponent.vue` montre **53 insertions** ;
- la même différence est staged ;
- le lock existe, mais sa contresignature propriétaire est toujours `☐` ;
- `bash .cursor/hooks/safety-check.sh` retourne `BLOCKED`.

La mention « Frozen = vide » du certificat n'est donc pas valide pour le snapshot certifié. Le contrôle requis doit utiliser au minimum `git diff HEAD --stat -- ...` et vérifier explicitement l'index.

### 3. Le reporter Playwright autoritaire est ancien et trop étroit

`reports/antigravity/playwright-latest.json` :

- mtime : 2026-08-29 07:56:15 ;
- 1 expected, 1 passed, 0 failed ;
- scénario unique : `login → dashboard KPIs → Vue d’ensemble / Suivi en direct` ;
- source : `tests/e2e/_grok-dashboard-wave-A-2026-08-29.spec.js` ;
- aucune assertion cockpit, backup, queue, dates, `branch_id`, erreur réseau ou toggle.

Les round-5 du 1er septembre sont des scripts de capture `.mjs`, pas un run publié dans le reporter autoritaire. La capture POS « observability » montre le dashboard après redirection et un PUT forcé à 403. Elle ne prouve pas l'écran cockpit Admin, les GET 403 de chaque API, ni les états dégradés.

### 4. Le snapshot a changé, puis a été intégré et rebâti

Deux fichiers dashboard ont été modifiés le 2 septembre après le build et le certificat initial :

- `app/Services/DashboardService.php` : résolution des comptes soft-deleted dans l'audit trail ;
- `resources/js/components/admin/dashboard/RealtimeReportComponent.vue` : correctif de contraste du ticket moyen.

Ces changements et le garde supplémentaire de fraîcheur santé sont désormais committés dans `78a878e23`. Un rebuild Mix daté de 02:04 est présent et le cache Vitest du même créneau déclare 465 fichiers sans échec. La capture round-5 prouve toutefois uniquement le défaut **antérieur** — texte sombre sur carte sombre — et le reporter Playwright n'a pas été régénéré après le rebuild. Le correctif de contraste n'a toujours aucun test direct de composant ni mesure navigateur fraîche.

`php -l app/Services/DashboardService.php` passe. `AuditTrailUsesAuditLogSentinelTest.php` est désormais committé et couvre compte actif, compte soft-deleted, identifiant orphelin et événement système, avec de vraies fixtures rôle Admin. Le run complet correspondant s'est arrêté non vert à 88 % sans résumé permettant d'attribuer les trois anomalies. Le contrat HTTP de `/audit-trail` et la matrice complète des rôles/branches restent absents.

### 5. L'environnement E2E n'est pas encore certifiable

- `.env` et `.env.e2e` pointent vers `http://127.0.0.1:8766` et `foodking_e2e` ;
- lors du contrôle suivant, 8000 écoutait de nouveau et 8766 répondait 200 ;
- aucune preuve n'établit encore PID/cwd, SHA servi, DB réellement utilisée et parité avec le source audité ;
- surtout, le safety hook reste `BLOCKED`, donc cette disponibilité ne donne pas l'autorisation de lancer une campagne de certification.

Une nouvelle campagne sans safety PASS et sans prouver serveur, worktree, base et bundle testerait potentiellement un autre état que le source audité.

### 6. La traçabilité route → composant → test révèle des gaps structurels

La matrice détaillée est dans `reports/audit/DASHBOARD_CONTROL_TRACEABILITY_MATRIX_2026-09-02.md` :

- 6 routes dashboard sur 16 n'ont aucune référence HTTP directe Feature/Unit : `order-statistics`, `order-summary`, `customer-states`, `top-customers`, `popular-items`, `audit-trail` ;
- 10 composants n'ont aucune référence directe JS/E2E ;
- `CustomerStatsComponent` et `TopCustomersComponent` ne sont pas montés par le dashboard courant alors que leurs endpoints existent ;
- `orderStatistics` ne partage pas le garde inversion/>366 jours des trois autres endpoints datés, et tous ignorent silencieusement une borne isolée.

La présence de tests du shell `DashboardComponent` ne couvre pas implicitement ces contrats.

## Findings ouverts

### P1-A — La restauration backup est branchée, mais peut encore produire trois faux verts

Le HEAD `28c2771a8` persiste et agrège désormais le dernier restore drill dans le cockpit et readiness. Le contrat demeure insuffisant : aucune identité fichier/SHA ne relie le dump courant à la preuve restaurée (P1-R), le seuil de preuve vaut 48 h au lieu des 26 h prévues (P1-S), et plusieurs sorties précoces `FAILURE` de la commande ne remplacent pas un ancien succès (P1-T). Un nouveau `.sql.gz` non testé, un drill vieux de 27–48 h ou un échec précoce après un ancien vert peuvent donc encore rendre la santé verte.

### P1-B — Les campagnes E2E fraîches restent ciblées, pas certifiantes

Il manque un run dédié, post-build et post-correctifs, couvrant au minimum : Admin/Tenant Admin/POS/Chef/manager/non authentifié, navigation et API, scope branche, dates, backup ok/stale/restore-failed, queue ok/unknown/backlog, erreurs réseau, polling et toggle avec restauration. La spec non committée `dashboard-catalogue-captures-2026-09-02.spec.js` a été renforcée avec deux assertions et passe sur 12 pages catalogue. La spec non committée `goal-caisse-controle-2026-09-02.spec.js` passe aussi ses 11 scénarios POS ciblés et produit 10 captures. Aucune ne certifie le cockpit : elles n'ouvrent pas `/admin/observability/system`, n'exercent pas sa matrice de rôles, ne publient pas dans le reporter autoritaire et la première laisse passer les erreurs console/WebSocket qu'elle collecte.

### P1-C — La validation du snapshot courant n'a pas convergé

Le cache Vitest initialement vert sur 465 fichiers a été remplacé par des runs complets rouges. Le run PHPUnit du checkout précédent s'est interrompu à 88 % après deux échecs et une erreur; le nouveau full PHPUnit a démarré sur `28c2771a8` puis a traversé l'arrivée de `47c001fbb`, donc il est hybride et non certifiant. Le run complet de la worktree externe a fini avec 2 erreurs et 16 échecs, aucun reporter Playwright dashboard/cockpit n'a été publié après `179e27d99`, et le safety gate interdit de qualifier ces exécutions de preuve de release. « Tout testé » reste donc factuellement impossible à affirmer.

### P1-D — Gate frozen et protocole de certification incohérents

Le hook officiel bloque, le lock n'est pas contresigné et le certificat a utilisé une commande qui omet le staged. Tant que ce gate n'est pas résolu sans contournement, les exécutions supplémentaires ne peuvent pas servir de preuve de release FoodKing.

### P1-E — Contrat dates corrigé, preuve complète absente

`47c001fbb` centralise le contrat sur les quatre endpoints et ajoute une matrice qui refuse borne isolée, dates impossibles, inversion et fenêtre excessive. Le défaut statique est fermé. Il reste à rattacher les résultats à un SHA figé et à couvrir le contrat HTTP complet des autres routes/permissions; les nombres du message de commit sont une attestation sans artefact brut.

### P1-F — `popular-items` est gardé, mais ses tests ne sont pas dans le commit

`47c001fbb` appelle désormais `DashboardService::assertDashboardBranchScope()` avant `ItemService::mostPopularItems()`, fermant statiquement le fail-open à `branch_id=0` pour un non-Admin. Les fichiers `PopularItemsFailClosedTest.php` et `DashboardRoutesAuthzMatrixTest.php` couvrent le cas dans le worktree, mais restent non suivis et ne font pas partie du commit audité; il faut les intégrer et les exécuter sur le snapshot final.

### P1-G — DST et date EOD corrigés; parité métier toujours ouverte

- `47c001fbb` remplace l'incrément fixe de 86 400 secondes par `CarbonPeriod`, publie les jours et ajoute les deux sentinelles DST; ce défaut est fermé statiquement.
- les tuiles du jour utilisent `business_date`, le Ticket Moyen utilise minuit→minuit ; après minuit pendant le même service, les chiffres côte à côte n'ont plus la même population.
- le PDF EOD refuse désormais `2026-02-31` et laisse le serveur choisir le jour Paris, mais aucune extraction ne prouve encore sa parité CA/TVA/tenders/canaux avec le Z sur remboursements et multi-tender.

### P1-H — Fraîcheur de base corrigée; preuve restore encore capable de faux vert

`28c2771a8` traite désormais timestamp absent/illisible/futur et compare l'âge exact du backup avant arrondi. Les faux verts restants concernent la preuve de restauration : seuil 48 h au lieu de 26 h, absence de correspondance fichier/SHA et sorties `FAILURE` qui ne remplacent pas toujours un ancien succès. Voir P1-R/P1-S/P1-T.

### P1-I — Le widget NF525 confond présence d'un hash et intégrité vérifiée

Le service ne recalcule pas la chaîne : il expose seulement huit caractères de `current_hash`. Pourtant l'UI et les sentinelles décrivent ce préfixe comme une preuve d'intégrité. Une rupture de chaîne peut donc rester affichée sous « Journal Inviolable ». Il manque un statut de vérification réel, daté, par branche, ainsi que le timestamp exact et la branche de chaque ligne.

### P1-J — Sémantique backend corrigée, livraison UI rompue

`179e27d99` corrige les requêtes backend : `delivered_24h` est fondé sur `broadcast_at`, et les claims en vol/orphelins sont séparés. Le défaut n'est cependant pas fermé de bout en bout : l'API a supprimé l'ancienne clé avant la migration du composant, qui continue à lire seulement `dispatched_24h`. Les nouveaux états ne sont pas rendus et la classification `terminal_failures` ne vérifie pas l'épuisement des tentatives. Voir P1-M/P1-N ci-dessus.

### P1-K — Replay durci, drain encore non atomique et UX destructive inchangée

`179e27d99` corrige le principal écart du retry : service et lock communs avec la commande, audit signé avant dispatch, conservation de `attempts`/`last_error`, et dispatch hors transaction. Le drain filtre désormais `DispatchDomainEventsJob` et exporte les candidats avant suppression.

Restent ouverts : la borne de 500 est appliquée avant le filtre métier, l'audit annonce `deleted=N` avant la postcondition réelle, et le composant n'a toujours ni confirmation, ni résultat, ni erreur accessible. Voir P1-P/P1-Q et P1-L.

### P1-L — Une panne réseau laisse l'ancien état vert à l'écran

`OutboxOverviewComponent::loadAll()` n'a aucun `catch`; sur 403/500/timeout/offline, le polling rejette mais conserve les dernières valeurs et le dernier timestamp sans bannière stale (`OutboxOverviewComponent.vue:358-371`). Les mutations retry/drain ont le même comportement. Le bouton retry est en outre activé dès qu'il existe n'importe quel pending ou `failed_job`, alors que l'endpoint ne sélectionne que les `domain_events` avec `last_error` : un clic peut répondre `requeued=0` sans explication.

Ce cockpit opérationnel doit distinguer `loading/ok/degraded/unknown/stale`, annoncer l'erreur, afficher l'âge des données, proposer retry et empêcher les requêtes concurrentes. Un test de composant doit prouver que l'ancien vert ne survit pas silencieusement à une panne.

### P2-A — Couverture directe des composants toujours lacunaire

Dix composants n'ont aucune référence de fichier dans les tests JS/E2E : `AuditTrailComponent`, `ChannelStatsComponent`, `CustomerStatsComponent`, `FeaturedItemsComponent`, `MostPopularItemsComponent`, `OrderStatisticsComponent`, `RealtimeReportComponent`, `SalesSummaryComponent`, `TopCustomersComponent` et `SystemHealthComponent`.

### P2-B — Audit durable des interrupteurs non démontré

Le PUT écrit encore un `Log::info`. La confirmation UI existe désormais, mais aucune preuve métier immuable ne démontre acteur, avant/après, branche/tenant, corrélation et horodatage.

### P2-C — Robustesse et accessibilité non convergées

Le défaut de contraste Ticket Moyen est confirmé par la capture round-5 et son correctif est présent dans le source committé et un build plus récent. Les lacunes déjà consignées (erreurs silencieuses, polling sans état, ARIA/live regions, clés stables, alt/dimensions, locale codée en dur) n'ont toutefois pas de validation navigateur fraîche multi-viewport.

### P2-D — Messages d'exception internes exposés

`DashboardController::dashboardFailure()` renvoie directement `$exception->getMessage()` en 422 pour toute exception non HTTP/non validation. Il faut un message public stable et une journalisation interne corrélée afin d'éviter la fuite de détails SQL/driver.

### P2-E — Deux bords du nouveau contrat dates restent ambigus

`resolveDashboardWindow()` utilise `empty()` pour détecter les bornes : `first_date=0&last_date=0` est traité comme « aucune date » et replie sur la période par défaut au lieu d'un 422. La borne maximale est aussi décalée d'un jour par rapport au nombre de jours effectivement rendu : `diffInDays() > 366` accepte du 1er janvier 2026 au 2 janvier 2027 (écart 366, mais 367 jours inclusifs dans `CarbonPeriod`). Les sentinelles vérifient 366 jours inclusifs pour l'année bissextile, pas ce bord à 367. Le contrat doit nommer sa métrique puis tester exactement limite−1/limite/limite+1.

## Matrice d'assurance actuelle

| Niveau | Verdict |
| --- | --- |
| Correctifs statiques P1-01/02/03/05 | Présents |
| Tests ciblés annoncés le 1er septembre | Attestation seulement, pas jointe sous forme de log brut |
| Vitest du source courant | Log complet conservé : 470 fichiers, 7 rouges, 4 tests échoués, 3 skips; rerun ciblé 8 fichiers vert, mais pas de full vert sur SHA figé |
| Bundle courant | Rebuild présent à 02:04 ; parité avec serveur/report Playwright non démontrée |
| Playwright autoritaire | 1 test ancien, dashboard seulement ; hors reporter, 12 pages catalogue et 11 scénarios caisse passent, sans couverture cockpit |
| Cockpit Admin réel | Pas de preuve fraîche |
| Full PHPUnit | Ancien run arrêté à 4 819/5 444 après `FF…E`; nouveau run démarré sur `28c2771a8` puis traversé par `47c001fbb`, donc hybride et non certifiant |
| Worktree de port externe | Vitest 8 fichiers/13 tests rouges ; PHPUnit final 2 erreurs/16 échecs ; autre HEAD, donc diagnostic seulement |
| Safety/frozen | BLOCKED |
| Double audit PASS | Absent pour ce snapshot |

Matrice exhaustive associée : `reports/audit/DASHBOARD_CONTROL_TRACEABILITY_MATRIX_2026-09-02.md`. Audit UI courant : `reports/audit/UI_DASHBOARD_CONTROL_GUIDELINES_2026-09-02.md`.

## Condition de sortie

Ne déclarer le goal accompli que lorsque : snapshot gelé et identifiable, safety PASS, source=bundle=serveur prouvés, suites ciblées et complètes vertes avec logs bruts, campagne Playwright dédiée sans skip, postconditions DB intactes, restore backup réellement mesuré, puis audit Claude et audit final GPT/Codex PASS.
