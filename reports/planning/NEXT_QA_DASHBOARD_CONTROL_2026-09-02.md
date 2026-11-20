# Prochaine boucle QA — Dashboard et contrôle (snapshot reproductible)

Date : 2026-09-02

## Priorité de reprise mise à jour — 2026-09-03

Les tests HTTP manquants et la rupture `delivered_24h` sont fermés. La prochaine exécution doit traiter, dans cet ordre, sans élargir le périmètre :

1. retirer la limite fonctionnelle de 100 commandes du tiroir (pagination complète ou endpoint serveur borné aux quatre files), avec scénario >100 et plus ancienne commande visible ;
2. remplacer les constantes numériques de `filesControle.js` et `fileCuisine.js` par les enums JS canoniques, puis ajouter un test de parité enum PHP/JS ;
3. rendre les claims outbox en vol, orphelins et terminaux ; corriger `retried`→`requeued`; séparer le compteur de purge `failed_jobs` du compteur `domain_events` ;
4. aligner terminalité, queue `high`, pagination de purge et vérité post-DELETE de l'audit ;
5. rendre audit+mutation des interrupteurs cohérents en cas d'échec, puis corriger le texte du cockpit ;
6. lier le restore drill au nom+SHA du backup courant, ramener la fraîcheur à 26 h et centraliser toutes les sorties rouges ;
7. reconstruire les quatre bundles et exiger les quatre sentinelles de fraîcheur vertes ; le Playwright caisse courant a déjà prouvé cinq échecs DOM causés par le bundle POS non livré ;
8. corriger ou classer avec preuve les trois sentinelles PHP rouges, puis rejouer PHPUnit complet et Vitest complet sur un HEAD immuable ;
9. rendre les erreurs console/network bloquantes dans Playwright, rejouer dashboard, cockpit et caisse, et publier les artefacts sous le reporter autoritaire ;
10. avant production, exclure explicitement le dump gzip de 20 octets et aligner le préflight de déploiement PHP 8.1/8.4.

Conditions de CLOSE inchangées : **zéro P1 ouvert, suites complètes vertes, bundles frais, navigateur sans erreur non attendue et double audit PASS**.

Source : `reports/audit/CODEX_DEEP_AUDIT_DASHBOARD_CONTROL_CONTINUATION_2026-09-02.md`

Routage : **REWORK complexe**, puis `playwright-full-e2e`. Aucun CLOSE avant double PASS.

## Objectif

Transformer les correctifs statiques actuels en une preuve reproductible du dashboard et du cockpit : autorisations, isolation branche, vérité opérationnelle, audit des actions, robustesse UI, accessibilité et absence de régression globale.

État au dernier audit : HEAD `47c001fbb`. Le contrat commun de dates, les frontières DST, la date EOD serveur et le garde `popular-items` sont présents. Les résultats ciblés du commit sont seulement attestés; le full PHPUnit lancé avant ce commit est devenu hybride. Les tests `DashboardRoutesAuthzMatrixTest.php` et `PopularItemsFailClosedTest.php` existent en non suivi et doivent être intégrés avant toute certification.

## Gate 0 — Obtenir un snapshot testable

1. Faire trancher le gate frozen par le propriétaire : contresignature valide et hook capable de la reconnaître, ou worktree propre sans mutation frozen staged.
2. Exiger `bash .cursor/hooks/safety-check.sh: PASS`; aucun bypass.
3. Geler le snapshot et enregistrer : commit, `git status --short`, SHA des fichiers dashboard, SHA du bundle, base URL et DB dédiée.
4. Démarrer le serveur depuis ce worktree sur 8766/`foodking_e2e`; prouver PID/cwd et réponse HTTP.
5. Corriger le contrôle frozen de toute certification : `git diff HEAD --stat -- <liste>` plus inspection `git diff --cached`.

## Wave 1 — Local validation critique

Test strategy : `local-validation`.

### Backend

- Intégrer les drafts de tests HTTP, puis compléter les trois routes encore sans référence committée : `top-customers`, `popular-items`, `audit-trail`. Les trois autres sont désormais couvertes par la matrice dates de `47c001fbb`.
- Paramétrer la matrice sur les 16 routes plutôt que déduire leur sécurité depuis `total-orders`.
- Matrice GET/PUT : non authentifié 401 ; Admin/Tenant Admin autorisés ; POS/Chef/manager refusés.
- `branch_id` absent/0/autre branche sur **chaque** endpoint dashboard, y compris audit trail.
- Rejouer le cas critique `popular-items` déjà codé : détenteur de `dashboard`, non Admin/Tenant Admin, `branch_id=0`, avec données divergentes sur deux branches ; aucune requête inter-branche ne doit être exécutée.
- Rejouer le contrat dates unique de `47c001fbb` puis compléter les dates futures et bornes exactes de la limite annuelle sur les quatre endpoints.
- Rejouer les sentinelles `sales-summary` de mars/octobre et vérifier aussi les montants/moyenne, pas seulement les labels et tailles.
- EOD : refuser les dates impossibles (`2026-02-31`, mois 00/13), utiliser le jour Paris côté serveur, extraire le PDF et vérifier la parité CA/TVA/tenders/canaux avec le Z sur branche, remboursements et multi-tender.
- Tester la parité après-minuit entre tuiles `business_date`, live ticket moyen et synthèse EOD, ou documenter clairement des périodes différentes.
- Backup : absent, decoy `.gz`, ancien 26 h, restore réussi, restore échoué, preuve périmée.
- Backup identité : un drill vert ne vaut que pour le fichier exact affiché, idéalement même SHA-256; injecter « ancien fichier A restauré vert, nouveau fichier B frais mais non testé » et exiger un cockpit/readiness dégradé.
- Backup persistance : faire passer toute sortie `FAILURE` de `backup:verify-restore` par un unique enregistreur de verdict rouge. Couvrir au minimum fichier illisible, live DB inaccessible et création scratch impossible, en prouvant qu'un vert antérieur est remplacé.
- Backup seuil : aligner `RestoreDrillResult` sur les 26 h du plan/readiness et tester 25 h 59, 26 h 00, 26 h 01 et 48 h.
- Fraîcheur santé : checks non vides sans timestamp, timestamp illisible, futur, exact 30 min et >30 min ; backup exact 26 h, 26 h 01, 26 h 29 et 26 h 30 avec parité readiness/cockpit.
- Queue : toutes probes lisibles, une illisible, toutes illisibles, backlog au-dessus du seuil.
- Outbox : remplacer toute notion de livraison par `broadcast_at`; exposer séparément pending, claim en cours, stale claim, livré et échec terminal. Injecter un crash après claim/avant broadcast et prouver qu'il reste rouge et récupérable.
- Outbox contrat API/UI : restaurer immédiatement l'alias `dispatched_24h` prévu par le plan ou livrer dans le même snapshot la migration Vue vers `delivered_24h`; rendre `in_flight`, `stale_claimed` et `terminal_failures`, avec un test de composant qui injecte le payload réel. Aucun déploiement backend seul.
- Outbox échec terminal : aligner la définition sur l'épuisement réel des tentatives; une ligne avec `attempts=1` et `last_error` doit rester « retry en cours », pas « terminale ».
- Outbox worker : borner la sonde à la queue `high` ou à un heartbeat explicite ; prouver qu'un worker d'une autre queue ne peut pas la rendre verte.
- Retry outbox : partager lock, service et audit durable avec la commande Artisan ; tester deux opérateurs concurrents, âge maximal, postcondition et dispatch après commit.
- Drain : refuser la suppression globale implicite, définir la portée, préserver/exporter la preuve, confirmer l'action et auditer acteur/corrélation/compte avant-après. Filtrer/paginer avant d'appliquer la borne de 500, tester 500 jobs étrangers devant un job outbox, puis garantir que l'audit ne prétend pas `deleted=N` si le `DELETE` échoue ou n'en supprime qu'une partie.
- Toggle : validation, état inchangé sur 403/422/500, round-trip Admin, restauration et trace métier durable.
- Audit trail : exécuter les nouveaux tests système/actif/soft-deleted/id orphelin, puis ajouter le contrat HTTP, permissions et scope branche.
- Audit NF525 : ne plus assimiler préfixe présent et chaîne valide ; injecter un maillon rompu et exiger un état rouge, une vérification datée par branche, un timestamp exact et une branche affichée.
- Remplacer les messages bruts d'exception du contrôleur par un contrat public stable, avec corrélation/log interne.

### Frontend

- Monter directement les dix composants sans référence de test.
- Trancher explicitement le sort de `CustomerStatsComponent` et `TopCustomersComponent`, non montés malgré leurs endpoints : remonter, déplacer ou retirer avec preuve de non-usage.
- `RealtimeReportComponent` : contraste/classes, format de valeur, erreur réseau et polling.
- `SystemHealthComponent` : ok/degraded/unknown, restore failed, queue unknown, GET 403, toggle confirm/cancel/success/failure, erreurs concurrentes, rafraîchissement interrupteurs, timer cleanup et `aria-live`.
- `OutboxOverviewComponent` : données fraîches/stale, 403/500/timeout/offline, ancien vert invalidé, retry `0/N`, confirmation drain, succès/erreur accessibles, double clic et cleanup polling.
- Dashboard : loader par requête, erreurs/retry visibles, permissions fail-closed, locale via `Intl`, clavier/focus et erreur PDF inline.
- Renommer l'action EOD pour distinguer sans ambiguïté « télécharger la synthèse » de « clôturer le Z fiscal ».
- Verrouiller la matrice route Vue/API pour Admin, Tenant Admin, détenteur `settings` sans rôle admin et utilisateur sans `settings`.
- Ajouter `aria-hidden` aux icônes décoratives, des clés stables et les attributs sémantiques des barres de progression ; images avec alt utile, dimensions et lazy loading.

## Wave 2 — Build et suites complètes

Test strategy : `local-validation`.

1. Rebuild Mix production après tous les changements source.
2. Vérifier que le hash servi égale le manifest et contient les correctifs attendus.
3. Reproduire et identifier les `FF…E` vus dans le run interrompu à `4 819/5 444`, puis exécuter PHPUnit ciblé et `php artisan test --exclude-group=manual` jusqu'au résumé final.
   Le run ciblé post-Sub4 est déjà rouge : 384 tests, 1 erreur (`OrderStatisticsSingleGroupedQueryTest`, branche requise), 2 skips. Corriger le fixture/contrat sans affaiblir le fail-closed `branch_id`.
4. Exécuter Vitest ciblé, puis `npx vitest run`. Ne pas importer comme PASS le run `port-e2e-0902` : il est rouge (`8` fichiers, `13` tests) et repose sur un autre HEAD ; corriger ou classer explicitement les bundles absents, `sauceChipsCouleurs` et `KeyboardNavigationSentinel`. Rejeter la régression `StockLowAlertsWidget.canFetchAlerts()` du port : permissions vides/absentes doivent rester fail-closed et le test correspondant ne doit pas être supprimé.
   Le cache du checkout principal est réécrit par des runs isolés et a montré successivement `systemHealthSauvegardeRestauration.spec.js` puis `posTrackerCanalLisible.spec.js` en échec; conserver les logs d'erreur complets, terminer les implémentations associées, puis rejouer ciblé + complet sur un snapshot figé.
   Le premier résumé complet post-Sub4 est rouge : 468 fichiers, 5 fichiers en échec, 9 tests en échec, 3 skips et 1 erreur. La relance JSON donne 469 fichiers, 3 928 tests, 10 échecs et 3 skips : `backendMenuVirtualChildrenOverride`, `composerEditorV2`, `noVacuousSpecSentinel`, `posTrackerCanalLisible` (4), `sidebarV1Cleanup`, `appBundleFreshnessSentinel`, `posAppBundleFreshnessSentinel`. Corriger/classer chaque cause, puis relancer sur un SHA immuable.
   Le log complet `vitest-full-run-A.txt` reste rouge : 470 fichiers, 7 fichiers rouges, 4 tests échoués et 3 skips. Six fichiers POS échouent au chargement et `posTrackerCanalLisible` porte quatre échecs. Le rerun ciblé de huit fichiers POS est vert, mais omet ce dernier et ne remplace pas le full; reproduire sur un worktree figé avant attribution.
5. Exécuter status/pricing lint, invariants, i18n, bundle budgets et audits de dépendances.
6. Conserver les logs bruts/JUnit ; aucun résumé manuel seul ne vaut preuve.
7. Ajouter instrumentation PCOV/Xdebug et Vitest coverage si l'équipe veut une métrique de couverture ; ne pas confondre couverture et correction.

## Wave 3 — Playwright dashboard/contrôle dédié

Test strategy : `playwright-full-e2e` après autorisation humaine et Gate 0 PASS.

### Rôles et navigation

- Admin/Tenant Admin voient et ouvrent le cockpit.
- POS/Chef/manager ne voient pas le lien, le deep-link redirige/refuse, GET et PUT répondent 403.
- Session expirée : 401 puis UX de reconnexion, aucune donnée stale exposée.

### États opérationnels

- Health ok, degraded et unknown.
- Backup frais + restore vert ; backup frais + restore rouge ; backup vieux/absent/decoy.
- Queue nominale, backlog et driver illisible.
- Scheduler frais et périmé.
- Erreurs 403/422/500, timeout, offline/retry et récupération du polling.

### Actions

- Toggle Admin avec confirmation, preuve avant/après et restauration systématique.
- Annulation du confirm : zéro requête/mutation.
- Échec serveur : UI inchangée et erreur annoncée.

### Affichage

- 390×844, 768×1024 et 1366×768.
- Contraste Ticket Moyen, focus visible, ordre clavier, live regions, états vides/erreurs.
- Chaque widget dashboard exerce succès, vide et échec — pas seulement présence du titre.

### Preuve requise

- Reporter `reports/antigravity/playwright-latest.json` frais avec zéro skip inattendu.
- Interdire une réussite silencieuse en présence d'erreurs console/WebSocket inattendues; la spec catalogue actuelle les collecte sans les asserter.
- Rapport conforme à `workflows/report-format.md`.
- Traces/PNG/console/network, SHA du bundle, commit et base DB.
- Postconditions : toggle restauré, aucune fixture synthétique résiduelle, aucune mutation hors scope.

## Wave 4 — Convergence et fermeture

1. Deux runs consécutifs sur le même snapshot avec même résultat P0/P1=0.
2. Rejouer le safety check et les diffs frozen après les tests.
3. Audit Claude terminal : PASS.
4. Audit final GPT/Codex : PASS.
5. Validation humaine ; seulement ensuite CLOSE.

## Critères d'acceptation

- Aucun accès global non autorisé et aucune fuite `branch_id`.
- Aucun 500 sur entrée utilisateur invalide.
- Le cockpit ne peut être vert si backup restore, queue ou scheduler sont inconnus/rouges.
- Le compteur de livraisons rendu doit provenir de `broadcast_at`, et les claims orphelins doivent être visibles dans l'UI.
- Toute bascule sensible est réversible et auditée durablement.
- Source, bundle, serveur et reporter appartiennent au même snapshot.
- Suites ciblées et complètes vertes, sans skip silencieux, avec logs bruts.
- Safety/frozen PASS et double audit PASS.
