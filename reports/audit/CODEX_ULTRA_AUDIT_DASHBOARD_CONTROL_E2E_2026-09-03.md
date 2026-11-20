# Ultra-audit Codex — dashboard, cockpit, caisse et E2E

Date : 2026-09-03  
Snapshot final observé : `f393b98fb86f1841c2133884c88b32636c5e29c0`  
Dernier commit produit : `c36f07e1356cbaf203ab9871e956d425564c2580`  
Mode : audit, tests et preuves uniquement. Aucun fichier produit modifié.

## Verdict exécutif

**VERDICT: NEEDS_FIX**  
**RELEASE_READINESS: HUMAN_GATE**  
**CODE_ASSURANCE: FORTE MAIS PAS ABSOLUE SUR UN SHA PROPRE UNIQUE**

La convergence technique est réelle : les tests ciblés, Vitest complet et les 16 scénarios
navigateur exécutés pendant cet audit sont verts. La caisse >100 commandes, les enums, les
dates/DST, les opérations outbox, le restore drill, les interrupteurs et les garde-fous NF525
ont des preuves ciblées positives.

La certification production reste néanmoins impossible pour trois raisons indépendantes :

1. la machine d'encaissement mesurée en production tourne en `APP_ENV=staging` avec
   `POS_SIMULATION_HARDWARE=true`, donc les gardes réservés à `production` sont inertes ;
2. quatre widgets dashboard confondent encore panne, absence de données et chargement ;
3. les gardes transverses officiels restent rouges : cinq infractions au contrat pricing et
   trois familles de détection dans `check-invariants.sh`.

## Preuves exécutées pendant cet audit

| Couche | Résultat | Portée et réserve |
| --- | --- | --- |
| Safety | **PASS** | 15 zones frozen contrôlées ; aucun PHP staged. |
| `git diff --check` | **PASS** | Aucun whitespace error dans le diff courant. |
| PHPUnit ciblé | **92/92 PASS** | Service-day >100, isolation branche, enum, backup/restore, outbox, interrupteurs, dates/EOD, PHP deploy floor et garde config-cache NF525. |
| Vitest ciblé | **127/127 PASS**, 22 fichiers | Dashboard, cockpit, caisse et borne. Avertissements i18n non bloquants dans un stub de test borne. |
| Vitest complet frais | **533/533 fichiers PASS** ; **4383 PASS**, 3 skips | Exécuté sur le worktree partagé courant ; le commit produit n'a pas changé pendant le run. Le worktree contient toutefois des modifications utilisateur non liées. |
| Playwright dashboard/cockpit/caisse/convergence | **16/16 PASS**, Chromium, 2,3 min | Trois pages admin, 11 flux caisse et 4 flux convergence ; zéro réponse HTTP >=400, zéro clé i18n brute, aucune erreur de navigation. |
| Postcondition E2E DB | **PASS** | `0` commande et `0` ligne de commande résiduelles portant le préfixe `GCC0902-`. |
| Pricing guard | **FAIL — 5** | Une dérogation expirée, une arithmétique locale non autorisée et trois blocs kiosk sans sign-off conforme. |
| Invariants scriptés | **FAIL — 3 familles / 19 hits** | 1 lecture `branch_id` issue de la requête, 3 dispatchs signalés, 15 hits du garde audit. Voir qualification ci-dessous. |

Artefacts navigateur :
`reports/test-e2e/codex-ultra-audit-2026-09-03/dashboard/`.

La campagne a rencontré les refus WebSocket attendus sur `127.0.0.1:6001`. L'application reste
utilisable et les specs passent, mais cela certifie la résilience au temps réel absent, **pas**
la connectivité WebSocket opérationnelle. Le cockpit local a rendu honnêtement un backup vieux
de 71 jours et un restore drill rouge (`88` tables restaurées contre `113` en live).

La dernière preuve PHPUnit complète versionnée reste : **5991 PASS**, 6 incomplets, 36 skips,
durée 1196,28 s, dans `reports/supervision/2026-09-03/phpunit-complet.txt`. Elle date de 03:15,
donc précède les derniers commits de garde NF525 ; ce delta a été rejoué par les tests ciblés,
mais ce n'est pas un full run frais sur un checkout propre et immuable.

## Ce qui est validé

- Le tiroir caisse ne disparaît plus silencieusement après 100 commandes : endpoint de jour de
  service, troncature déclarée et tests de branche sont verts.
- Les statuts des files caisse/cuisine utilisent les enums canoniques ; les sentinelles de
  parité PHP/JS ciblées sont vertes.
- Outbox : les compteurs livrés/requeued, états in-flight/stale/terminal, drain/purge, audit
  post-mutation et borne worker sont couverts par les tests ciblés.
- Restore drill : rapprochement nom+SHA, persistance des échecs et seuil de fraîcheur sont
  couverts ; l'écran réel affiche le rouge au lieu de masquer l'échec local.
- Interrupteurs : mutation et audit sont transactionnels ; refus d'audit et rollback sont
  testés.
- Dashboard : fenêtres civiles, inversion refusée en 422, DST mars et EOD sont verts côté API
  et navigateur.
- Les bundles actuels rendent les contrôles caisse et cockpit attendus. Les anciens constats
  de bundle périmé ne se reproduisent plus dans la campagne fraîche.
- Les zones frozen restent intactes pendant cet audit.

## Findings ouverts

### P0-OPS-01 — Les protections production sont inertes sur la machine qui encaisse

Le constat versionné `reports/supervision/2026-09-03/FINDING-APP-ENV-STAGING.md` rapporte une
mesure SSH en lecture seule : `APP_ENV=staging`, `POS_SIMULATION_HARDWARE=true`, 879 commandes,
1386 écritures d'audit, 18 Z et une dernière commande le 2 septembre à 23:18.

L'inspection du code corrobore l'impact : les gardes de `AppServiceProvider` sont conditionnés
par `app()->environment('production')`; `HealthController` retire scheduler, backup_age et
restore_drill de la décision de readiness hors production. Ainsi `/api/health/ready` peut être
globalement `ok` avec `restore_drill=degraded` sur cette machine.

**Impact :** protections NF525/config-cache/hardware non armées et readiness global trompeur.
**Décision : HUMAN_GATE.** Ne pas basculer aveuglément en production : le garde hardware ferait
refuser le boot tant que les terminaux bancaires ne sont pas câblés. Court terme recommandé :
rendre explicitement visible « activité fiscale avec gardes production inertes » ; cible :
câblage bancaire, simulation `false`, puis environnement `production` avec préflight.

### P1-UI-01 — Overview reste bloqué visuellement sur `…` après erreur

`resources/js/components/admin/dashboard/OverviewComponent.vue:54-58` maintient bien
`loadError`, et les trois `catch` le positionnent (`:85-108`). Mais le template `:1-40` ne rend
jamais ce drapeau. Après panne d'un endpoint, la tuile affiche `…` indéfiniment, sans message ni
retry. Une panne peut donc être prise pour un chargement lent.

### P1-UI-02 — Audit Trail présente une panne comme un journal vide ou ancien

`resources/js/components/admin/dashboard/AuditTrailComponent.vue:90-93` ne possède aucun
`catch`. Au premier échec, la table annonce « Aucun événement audité récent » (`:62-64`) ; après
un échec de polling, elle conserve l'ancien vert sans marqueur stale. C'est une ambiguïté
opérationnelle sur une surface NF525.

### P1-UI-03 — Order Summary avale l'erreur réseau

`resources/js/components/admin/dashboard/OrderSummaryComponent.vue:156-158` arrête uniquement
le loader. Aucun état erreur, aucun retry et aucune annonce ne sont rendus ; les valeurs
précédentes ou nulles restent visibles. Les quatre barres colorées (`:21-45`) sont en outre des
`span` sans sémantique de progression.

### P1-UI-04 — Last Z Report confond interdit, panne et absence de Z

`resources/js/components/admin/dashboard/LastZReportWidget.vue:71-76` classe le défaut de
permission comme indisponibilité ; `:101-109` fait de même pour liste vide et exception. Les
trois cas rendent `no_data_available` (`:9-11`). Un incident fiscal/HTTP n'est donc pas
distinguable d'une journée sans clôture.

### P1-GOV-01 — Le garde pricing officiel est rouge

`npm run pos:lint:pricing` relève exactement cinq écarts :

- `PosComponent.vue:6315` : dérogation expirée depuis le 10 mai 2026 ;
- `PosCounterCollectModal.vue:562` : calcul local `base + amount` ;
- `KioskWizardComponent.vue:1999`, `:2023`, `:2087` : blocs sans sign-off conforme.

Le contrat FoodKing interdit la logique de prix métier côté frontend. Ces cinq occurrences
doivent être déplacées côté backend ou faire l'objet d'un gate/sign-off explicite ; trois sont
dans une zone frozen et ne peuvent pas être modifiées sans LOCK.

### P1-GOV-02 — Le pipeline d'invariants reste rouge malgré de nombreux faux positifs

`bash scripts/check-invariants.sh -v` sort `1` avec 19 hits. L'inspection réduit le risque :

- les trois événements Item signalés sont émis après la transaction ou utilisent
  `DispatchableAfterCommit` ;
- les quinze hits « audit » incluent imports et commentaires, donc ne prouvent pas quinze
  absences d'audit ;
- le hit restant lit `request.branch_id` pour un guest dans
  `FrontendOrderService.php:175`, vérifie seulement l'existence de la branche puis l'utilise
  comme namespace idempotent.

Le comportement guest peut être intentionnel, mais contredit littéralement le garde
« branch_id server-side only ». Tant que l'exception n'est pas encodée de façon bornée et
testée, ou le garde rendu assez précis, le pipeline release reste rouge et ne peut pas être
présenté comme PASS.

### P1-CERT-01 — Les preuves ne forment pas encore un certificat unique et immuable

Le HEAD a avancé pendant l'audit de `7931a3363` à `c36f07e13`, puis à `f393b98fb`. Le dernier
changement est documentaire seulement et n'invalide pas les tests produit, mais le worktree
partagé contient des modifications et artefacts d'autres travaux. La suite PHPUnit complète
fraîche n'a pas été rejouée jusqu'au résumé final sur ce même checkout.

Il est donc exact de dire « corrections fortement vérifiées », pas « certification absolue du
SHA courant ».

## Couverture qui reste à acquérir

- Playwright injectant les états cockpit dégradés : worker mort, purge 500, timeout/offline,
  restore rouge puis récupération. Les bancs Vue/PHP les couvrent, pas le navigateur réel.
- Connectivité WebSocket réelle et événement caisse→KDS ; la campagne actuelle tolère le port
  6001 arrêté.
- Deux administrateurs basculant le même interrupteur simultanément.
- Parité chiffrée PDF EOD / Z fiscal pour remboursements et paiements multiples.
- Décision propriétaire sur `CustomerStatsComponent` et `TopCustomersComponent`, toujours
  maintenus/testés mais non montés.
- Audit accessibilité complet : icônes décoratives non masquées, barres non sémantiques,
  images produit génériques sans dimensions/lazy et erreur PDF encore en `window.alert`.

## Conclusion

Les régressions fonctionnelles majeures du dashboard/cockpit/caisse relevées le 2 septembre
sont essentiellement corrigées et les campagnes fraîches le confirment. Le reliquat n'est pas
un vague « peut-être » : une porte P0 d'exploitation/conformité, quatre états UI trompeurs, cinq
écarts pricing, un garde d'invariants rouge et quatre scénarios de robustesse non certifiés.

**VERDICT: NEEDS_FIX**  
**NEXT_STATE: HUMAN_GATE puis REWORK borné**
