# CHARTE DE MISSION — Convergence FoodKing + Vitrine Le Cayenne

Date d'ouverture : 2026-09-03
Superviseur : Claude (session unique, responsabilité complète jusqu'à validation)
HEAD d'ouverture : `28cd79d5a` — branche `pos/category-first-caisse-2026-06-23`
Origine : contre-audit Codex (dashboard/contrôle, 2026-09-02) + audit Grok (vitrine lecayenne.fr, T01–T58, 2026-08-28)

---

## §0 — Ce que cette mission n'est PAS

Ce n'est pas l'exécution des rapports Codex et Grok tels qu'écrits.

**Ces deux rapports ont été re-vérifiés ligne par ligne avant toute planification, et une partie
notable de leurs affirmations est fausse ou périmée sur le HEAD courant.** Exécuter leur liste
telle quelle aurait fait perdre plusieurs journées à réparer des choses déjà réparées, et aurait
laissé passer au moins un défaut qu'aucun des deux n'a vu.

Le §1 donne le résultat de cette re-vérification. Les GOALs du §3 ne portent que sur ce qui a
été **prouvé** encore vrai, plus ce que la vérification a trouvé en propre.

---

## §1 — Résultat de la re-vérification (2026-09-03, 01h00–02h00)

### §1.1 — Ce que l'audit Codex affirmait, et qui est FAUX sur le HEAD courant

| Affirmation Codex | Réalité mesurée | Preuve |
|---|---|---|
| « Frozen : `KioskWizardComponent.vue` staged, 53 insertions » | **Diff zone gelée VIDE** sur les 15 fichiers | `git diff HEAD --stat -- <15 fichiers §7>` → aucune sortie |
| « `bash .cursor/hooks/safety-check.sh` : BLOCKED » | **PASS** | `[safety-check] Frozen zones: OK / Passed` — exit 0 |
| « Sentinelles PHP critiques : 3 rouges » | **9 tests verts / 9** | `FrozenZoneSha256Baseline` + `WithoutGlobalScopesAudit` + `Zone5PricingSsotConvergence` → PASS |
| « Playwright caisse : 5 échecs consécutifs, `pos-control-*` absents du DOM » | **11/11 PASS** après reconstruction | `tests/e2e/goal-caisse-controle-2026-09-02.spec.js`, chromium, 3,4 min |
| « les contrôles `pos-control-*` ne sont pas livrés par `public/js/pos-app.js` » | Vrai mais **hors sujet** : ils sont dans le morceau différé `pos-shell.*.js` (28 sélecteurs), résolu par `manifest.js` | grep sur le bon fichier |
| « `DIRECT_HTTP_GAPS_COMMITTED: 0/16` puis « 3 routes sans test » » | **0 route sans référence** — les 4 « manquantes » sont couvertes par URL concaténée | `DashboardRoutesAuthzMatrixTest.php:27-33` |
| « `DashboardController::dashboardFailure()` renvoie le message brut d'exception en 422 » | **RÉFUTÉ** — renvoie `trans('all.message.database_error_message')`, le brut ne va qu'au log | `DashboardController.php:58,63-66` |

**Cause commune** : le rapport Codex a été écrit sur un instantané mouvant, avec des `grep`
littéraux aveugles à la concaténation d'URL et au découpage en morceaux différés de Webpack.
La leçon vaut pour la suite de cette mission : **on grep le fichier réellement servi, pas
celui dont on suppose le nom.**

### §1.2 — Ce qui était VRAI et qui est DÉJÀ CORRIGÉ par cette session

| Défaut | État | Action |
|---|---|---|
| 4 sentinelles de fraîcheur de lot rouges (`app`, `pos-app`, `admin-kds`, `admin-reports`) | **CORRIGÉ** | `npm run production` — 15/15 verts après |
| Les sources du 2026-09-02 23h24 n'étaient pas livrées (lots du 18h12 et du 01-09) | **CORRIGÉ** | lots reconstruits 2026-09-03 01h28, servis et vérifiés en HTTP sur `:8766` |

C'était **le défaut le plus coûteux du lot** : il produisait à lui seul les 4 rouges Vitest, les
5 échecs Playwright, et l'affirmation « le tiroir existe dans le source mais n'est pas livré ».
Une seule commande l'a fermé.

### §1.3 — Ce qui reste VRAI et doit être corrigé (P1 prouvés)

| ID | Défaut | Preuve | GOAL |
|---|---|---|---|
| **V-01** | Le tiroir caisse demande une seule page de 100, tri `id desc`, et présente ça comme toute la journée. Aucun plafond serveur : le 100 est un choix client. Au-delà, ce sont **les commandes les plus anciennes** qui disparaissent — celles qui traînent. 4 files, 2 badges, `activeOrdersStats`, `readyOrders` et le rang cuisine deviennent faux en silence. | `PosComponent.vue:4782,4802,5312` · `OrderService.php:137-140` · `PosOrdersTrackerComponent.vue:1856` | G1 |
| **V-02** | L'API de relance renvoie `requeued`, l'écran lit `data.retried` → l'opérateur ne voit jamais combien d'événements ont été relancés. | `SyncOverviewController.php:478` vs `OutboxOverviewComponent.vue:509` | G2 |
| **V-03** | `inFlight` et `staleClaimed` sont chargés dans l'état du composant et **jamais rendus**. Le cockpit peut connaître 2 149 claims orphelins sans les montrer. | `OutboxOverviewComponent.vue:417-418,472-473` (aucun usage dans le template) | G2 |
| **V-04** | La confirmation de purge annonce un nombre de `domain_events` pour une action qui supprime des `failed_jobs` — deux ensembles différents. Le bouton est en plus désactivé sur ce compteur étranger. | `OutboxOverviewComponent.vue:82,153` vs `SyncOverviewController.php:566` | G2 |
| **V-05** | L'audit de purge écrit `deleted => count($ids)` **avant** le `DELETE`. Une suppression qui échoue ou n'en supprime qu'une partie laisse une preuve immuable, signée en chaîne, qui ment. | `SyncOverviewController.php:547` puis `:566` | G2 |
| **V-06** | La sonde du worker outbox lit **n'importe quelle** ligne `jobs.reserved_at`, sans `where('queue','high')`. Un worker de notifications actif affiche le worker outbox « up » alors que sa file est morte. | `SyncOverviewController.php:658-662` | G2 |
| **V-07** | L'audit d'un interrupteur est écrit **avant** `InterrupteurService::regler()`, dont l'écriture en base peut échouer ; le seul `catch` n'attrape que `InvalidArgumentException`. Le journal immuable affirme alors une bascule qui n'a pas eu lieu. | `InterrupteurController.php:94` puis `:110` · `InterrupteurService.php:120` | G3 |
| **V-08** | La preuve de restauration ne rapproche **ni le nom ni le SHA-256** du dernier backup affiché avec le fichier réellement restauré. Un dump B corrompu arrivé après un drill vert sur A est présenté comme « réellement remonté ». | `SyncOverviewController.php:758` et `:871`, jamais rapprochés | G4 |
| **V-09** | Six des neuf sorties d'échec de `backup:verify-restore` rendent FAILURE **sans persister de verdict rouge**. Fichier illisible, base injoignable, droit `CREATE DATABASE` retiré ⇒ `/health/ready` continue d'afficher « restauration vérifiée » sur le succès de la veille, jusqu'à 48 h. | `BackupVerifyRestoreCommand` : persistent 144/219/291 ; ne persistent pas 108/116/126/149/181/207 | G4 |

### §1.4 — Ce que la vérification a trouvé en propre (aucun des deux audits ne l'avait vu)

| ID | Trouvaille | Preuve |
|---|---|---|
| **N-01** | **La carte sauvegarde du cockpit reste verte 29 minutes par jour pendant que la bande d'alertes du même écran dit le contraire.** Le contrôleur compare bien en décimal (`> 26`), mais le JSON publie la valeur **arrondie**, et l'écran recalcule son vert dessus (`26 <= 26`). Codex avait signalé le défaut au mauvais endroit — côté serveur, il est déjà corrigé. | `SyncOverviewController.php:834` (correct) vs `:868` (arrondi publié) vs `SystemHealthComponent.vue:229` |
| **N-02** | **`CustomerStatsComponent.vue` et `TopCustomersComponent.vue` sont des composants morts** : ils n'apparaissent nulle part dans `resources/js` hors leur propre `name:`. Ce ne sont pas des composants « non testés », ce sont des composants que personne ne monte, alors que leurs routes API existent et sont maintenues. | grep exhaustif `resources/js` |
| **N-03** | Codex affirme qu'un test verrouille `attempts=1` comme « terminal ». **Ce test n'existe pas.** Le seul cas `attempts=1` compté terminal est un `contract_violation`, réellement terminal. Le prédicat reste fautif, mais il n'est verrouillé par rien — la correction est donc libre. | `OutboxDeliverySemanticsTest.php:117` |

### §1.5 — Confirmés mais de gravité inférieure à ce qui était annoncé

| ID | Défaut | Vraie gravité | Raison |
|---|---|---|---|
| V-10 | `terminal_failures` sans seuil sur `attempts` | P2 | prédicat fautif, mais aucun test ne le verrouille (N-03) |
| V-11 | Purge : `limit(500)` avant le filtre métier PHP | P2 | affame un candidat outbox seulement si 500 `failed_jobs` étrangers plus vieux |
| V-12 | `RestoreDrillResult::MAX_AGE_HOURS = 48` contre 26 h au contrat | P2 | incohérence réelle de contrat, sans faux vert supplémentaire une fois V-09 corrigé |
| V-13 | Enums métier recopiées en dur dans `filesControle.js` / `fileCuisine.js` | P2 | **les 13 valeurs concordent aujourd'hui** avec `app/Enums/` — c'est une dette, pas un défaut actif |
| V-14 | 8 composants dashboard sans aucun test direct | P2 | dont 2 morts (N-02) : 6 à couvrir, 2 à trancher |
| V-15 | Texte cockpit « journal serveur, pas NF525 » périmé | P3 | l'écriture va bien dans `audit_logs` désormais |
| V-16 | `first_date=0&last_date=0` traité comme « aucune date » ; 367 jours acceptés | P3 | aucune conséquence produit démontrée |

---

## §2 — Ce que la mission doit produire

Un état où **chaque affirmation de vert est reproductible sur un instantané figé** :

1. Zéro P1 ouvert sur la liste §1.3, chacun fermé par un banc qui **mord** (retirer le correctif
   doit faire rougir le banc — cf. `memory/prouver-qu-un-banc-mord.md`).
2. Lots livrés = sources (les 5 sentinelles de fraîcheur vertes au moment du commit final).
3. Suites complètes vertes, **avec les journaux bruts joints**, sur un arbre où aucune autre
   session ne teste en même temps.
4. Campagne navigateur fraîche, captures **lues et analysées**, erreurs console bloquantes.
5. Zone gelée : diff nul, `safety-check.sh` PASS.
6. Chaîne NF525 intacte (`php artisan fiscal:verify-chain --all`).
7. Vitrine : les P0 et P1 encore vrais fermés, vérifiés sur le **contenu servi**, pas sur le source.

---

## §3 — Découpage en GOALs

Chaque GOAL est un fichier autonome de ce dossier. Chacun porte : périmètre, ancres vérifiées,
tâches, critère d'acceptation nommant un chemin de test, surface visuelle, et condition de sortie.

| GOAL | Titre | Défauts couverts | Dépendances |
|---|---|---|---|
| `G1_CAISSE_JOURNEE_COMPLETE.md` | La caisse voit toute la journée de service | V-01, V-13 | aucune |
| `G2_COCKPIT_OUTBOX_VERITE.md` | Le cockpit outbox dit la vérité et agit juste | V-02..V-06, V-10, V-11 | aucune |
| `G3_INTERRUPTEURS_ATOMIQUES.md` | Un audit n'atteste que ce qui a eu lieu | V-07, V-15 | aucune |
| `G4_SAUVEGARDE_PREUVE_REELLE.md` | La sauvegarde prouve la sauvegarde du jour | V-08, V-09, V-12, N-01 | aucune |
| `G5_DASHBOARD_COUVERTURE.md` | Chaque écran du dashboard a un banc, ou n'existe plus | V-14, N-02, V-16 | porte P1 |
| `G6_PREUVE_REPRODUCTIBLE.md` | Un instantané figé, des suites vertes, des journaux bruts | preuve de tout le reste | G1–G5 |
| `G7_MISE_EN_PRODUCTION.md` | Le déploiement ne peut pas choisir un dump de 20 octets | dump d'un octet, PHP 8.1 vs 8.4 | G6 + porte P2 |
| `G8_VITRINE_DEFAUTS_REELS.md` | Vitrine : les quatre défauts encore vrais | T04, T07, T10, T58 (+ T05, T27) | porte P3 pour le déploiement |
| `G9_VITRINE_TICKETS_NON_TRANCHES.md` | Vitrine : les dix-sept jamais mesurés | T09, T15, T17, T19-T21, T24-T26, T30, T31, T35, T36, T39-T41, T57 | G8 |

### Ce que la vérification vitrine a changé

Le découpage initial prévoyait quatre GOALs vitrine (P0 / tunnel / contenu / technique). La
vérification l'a rendu caduc : sur les 41 tickets vérifiables dans le dépôt, **19 étaient déjà
corrigés** entre le 28 août et le 2 septembre, 1 est réfuté par la mesure, 1 n'est pas
reproductible. Il ne restait pas de quoi remplir quatre GOALs — il restait quatre défauts.

Les 17 restants n'ont laissé **aucune trace** dans le dépôt : ni commit, ni fichier, ni objet
`git fsck`. Ils ne sont ni vrais ni faux, ils n'ont pas été mesurés. Les mélanger aux quatre
prouvés aurait fait perdre la distinction qui compte.

## §4 — Règles d'exécution (non négociables)

1. **Un banc avant un correctif.** Le banc doit rougir sans le correctif. Sinon il ne prouve rien.
2. **Mesurer la couche que l'écran consomme.** Un défaut lu dans une projection n'est un défaut
   produit que si l'écran consomme cette couche.
3. **Vérifier le contenu servi**, pas le numéro de commit ni le nom de fichier supposé.
4. **Arbre partagé** : d'autres sessions testent dans `testttt` en ce moment. Aucun `git add .`,
   toujours `git commit -- <chemins>`. Aucune suite complète tant que l'arbre n'est pas calme.
5. **Zone gelée** : aucun octet sans LOCK contresigné. `safety-check.sh` avant chaque commit.
6. **Boucle** : audit → correctif → test → visuel → contre-audit adverse. Max 3 boucles sur le
   même défaut, puis escalade avec analyse de cause.
7. **Convergence** = deux rondes consécutives avec le même ensemble de constats, P0+P1 = 0.

---

## §5 — Portes propriétaire

| Porte | Sujet | QUI | QUOI | OÙ | État |
|---|---|---|---|---|---|
| P1 | Sort des 2 composants morts (`CustomerStats`, `TopCustomers`) : remonter, déplacer ou supprimer | Propriétaire | Décision écrite | G5 §Décision | EN ATTENTE |
| P2 | Déploiement du backend en production (PHP 8.1 vs script 8.4) | Propriétaire | Autorisation + fenêtre | G7 | EN ATTENTE |
| P3 | Déploiement de la vitrine (push `main` = déploiement Vercel immédiat) | Propriétaire | Autorisation explicite par lot | G8, G9 | EN ATTENTE |
| P3-a | Politique d'upsell après « Sans formule » : un écran, aucun, ou les trois actuels | Propriétaire | Choix écrit | G8 §T07 | EN ATTENTE |
| P3-b | Consommation au comptoir : oui ou non (les CGV et l'accueil se contredisent) | Propriétaire | Choix écrit | G8 §T27 | EN ATTENTE |
| P4 | Toute retouche de zone gelée | Propriétaire | LOCK contresigné | `plans/LOCK_*.md` | AUCUNE PRÉVUE |

Les GOALs G1 à G4, G6, et la partie code de G8 et G9 ne dépendent d'aucune porte : ils
s'exécutent tout de suite. G5 avance sur six composants sur huit sans attendre P1. Seuls les
déploiements attendent.
