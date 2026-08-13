# GOAL — Gestion Commerçant, Backend, Accès
— 2026-08-13, owner : « audit et améliore la gestion et tout le parcours de commerçant et
backend et accès ! pense à tout »

## §0 Préambule

### §0.1 Working-tree decision
Branche `pos/category-first-caisse-2026-06-23`, commit après la convergence roue/test-e2e de ce
même jour. Le working tree porte ~130 fichiers modifiés/non-suivis appartenant à **d'autres
sessions actives en parallèle sur ce repo** (confirmé toute la journée : commits `10da3eba6`,
`5253256c2`, `147e3650c`, `83534ced9` etc. d'une session tierce sur `borne.blade.php`/vitrine).
Discipline déjà en place et reconduite : jamais `git add -A`, toujours fichiers nommés, une voie
par commit.

### §0.2 CE QUE CE GOAL N'EST PAS — anti-fiction, lu avant toute décomposition
Une exploration réelle (grep/find/lecture directe, PAS supposée) montre que **la demande owner
est déjà couverte à 90%+** par des sessions antérieures documentées en mémoire :

- **`goal_admin_nav_breadth_convergence_2026-08-13.md`** (463 lignes, lu intégralement) : audit
  bouton-par-bouton de TOUT le dashboard + navigation admin, **70/71 pages (99%) avec preuve
  fonctionnelle réelle** (cycle CRUD/persistance/interaction/machine-à-états prouvé en direct, pas
  juste "la page s'ouvre"). 2 vrais P0/bugs de production trouvés ET corrigés dans cette même
  session (fiche commande Uber Eats qui crashait, éditeur de langue qui restait bloqué). Une
  dispute adversariale réelle (2 agents lancés pour RÉFUTER) déjà exécutée. Les seules pages sans
  preuve sont 2 cas structurellement intestables (Theme = aucun champ requis exploitable ; SMS/
  Payment-Gateway/License = credentials/appels externes réels, déclinés par principe).
- **137 fichiers** `tests/Feature/Sentinels/` + `tests/Feature/Security/` existent déjà (vérifié
  `find`, pas supposé) — couvrant RBAC, idempotency, fiscal, multi-branche, mots de passe, CORS,
  etc.
- Le scheduler (25+ tâches `app/Console/Kernel.php`) et le worker de file d'attente sont
  **vérifiés VIVANTS EN DIRECT sur le VPS** ce jour (`crontab -l`, `supervisorctl status`,
  `tail schedule.log` — cron actif chaque minute, `lecayenne-worker RUNNING`, 0 `failed_jobs`,
  tâches fiscales/outbox toutes `DONE` sans erreur sur les 10 dernières minutes observées).

**Conséquence** : ce GOAL ne redécompose PAS "gestion commerçant" page par page (ce serait
reproduire un travail déjà fait et déjà consigné). Il cible les **gaps réels, vérifiés,
non-couverts** identifiés en creusant au-delà de ce qui précède — 3 systèmes resserrés, chacun
avec des anchors neufs, pas de repompage.

### §0.3 Pipeline de référence
Chaque tâche suit `ultra-audit-profond` (audit lecture-seule → implémentation → RED-team → test →
visuel si UI → adversarial). Non re-décrite ici.

### §0.4 Convergence
Deux cycles consécutifs P0+P1=0 avec ensemble de findings identique (règle `test-e2e`), zone gelée
§7 = 0 ligne, aucune mutation de donnée réelle irréversible sans restauration DB-niveau prouvée
(discipline déjà appliquée toute la journée sur ce repo).

---

## §1 Système 1 — Accès (RBAC) en profondeur : EXÉCUTION, pas seulement navigation

### Contract
L'audit du 2026-08-13 (nav breadth) a prouvé qu'on peut CLIQUER jusqu'à chaque page RBAC en ayant
le bon rôle. Il n'a PAS prouvé qu'un rôle À FAIBLE PRIVILÈGE est réellement BLOQUÉ d'atteindre un
point d'API à privilège élevé en contournant l'UI (appel direct). C'est le gap réel : navigation
≠ enforcement.

### Anchors (vérifiés ce jour)
- `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php:67` — `RETURN_TRUE_BASELINE = 64`,
  **PASS confirmé en direct** (`php artisan test --filter=FormRequestAuthzDriftSentinelTest`).
- `app/Models/Scopes/BranchScope.php` + `app/Models/User.php` — **corrigé après revue Architect**
  (le libellé initial était imprécis) : `User.php:9,89` importe bien `BranchScope` et appelle
  `static::addGlobalScope(new BranchScope())` — le scope EST enregistré. Mais
  `BranchScope::apply()` fait un **no-op explicite** dès que `$model instanceof User`
  (commentaire du code : "Never branch-filter the authenticatable model"). Résultat identique
  (User, donc TOUT le personnel Chef/Waiter/Employee/DeliveryBoy/Customer, n'a **aucune isolation
  multi-branche** réelle sur read/update/delete/show) mais le mécanisme est un no-op VOLONTAIRE
  dans `apply()`, pas une absence d'enregistrement — nuance qui compte pour juger si c'est un oubli
  ou une décision délibérée non documentée. Contredit CLAUDE.md §9 qui liste "User" parmi les 24
  modèles scopés. Documenté par la session du 2026-08-13 comme "hors scope V1 mono-branche" mais
  **jamais corrigé ni dans le code ni dans la doc contradictoire**.
- `app/Rules/SafeRemoteHost.php:42-48,74-78` — mécanisme d'allowlist déjà câblé
  (`config('security.safe_remote_host_allowlist')` ← `.env SAFE_REMOTE_HOST_ALLOWLIST`), **vide
  par défaut**, conçu explicitement pour ce cas (commentaire du développeur d'origine :
  "V1 LOCAL Le Cayenne ships closed and admin must opt in").
- `PROJECT_BRAIN.md` / CLAUDE.md §9 — Sanctum `kiosk:order` scopé par device, `tokenCan()` dans
  8 contrôleurs (verified WI-7, ancien).

### Sub 1.1 — Enforcement réel, pas juste navigable
**Tasks** :
- T-1.1.1 Pour 3-4 endpoints API sensibles représentatifs (ex. `PUT /api/admin/setting/permission/:id`
  déjà exercé côté UI le 2026-08-13, `POST /api/admin/administrator`, un endpoint fiscal
  lecture-seule), appeler l'API DIRECTEMENT (pas via clic UI) avec un jeton d'un rôle à privilège
  strictement inférieur (Waiter/Employee) et confirmer 403/401 réel — pas juste "le bouton n'est
  pas affiché".
  • test: (TO BE CREATED at tests/Feature/Security/AdminApiEnforcementDirectCallTest.php)
- T-1.1.2 Vérifier `RouteCoverage_AdminPermissionGateSentinelTest.php` (existant) — le lire
  intégralement pour confirmer qu'il teste bien l'ENFORCEMENT (middleware/gate réel) et pas
  seulement la PRÉSENCE d'une déclaration de permission sur la route (motif "sentinelle qui ment"
  déjà rencontré une fois ce jour sur `BranchScopeCoverageSentinelTest`).
  • test: tests/Feature/Sentinels/RouteCoverage_AdminPermissionGateSentinelTest.php (existant,
    à AUDITER pas à réécrire sauf défaut trouvé)
**Acceptance** : les appels API directs avec rôle insuffisant renvoient 401/403 réel (pas un
"laisser passer" silencieux) ; le sentinel de couverture de route est confirmé tester le
comportement, pas juste la déclaration.

### Sub 1.2 — BranchScope / User : trancher la contradiction, pas la redocumenter une 3e fois
**Tasks** :
- T-1.2.1 Décision binaire à proposer à l'owner (PAS à trancher seul, item §G) : (a) corriger
  CLAUDE.md §9 pour dire la vérité (User n'est PAS scopé), ou (b) commencer le scoping réel de
  `User` par branche pour Chef/Waiter/Employee/DeliveryBoy (Customer déjà exempté à raison,
  Sanctum recursion documentée). Ne PAS choisir seul — c'est un changement d'invariant
  multi-tenant, exactement le genre de décision que CLAUDE.md §12 réserve à l'escalade.
- T-1.2.2 Si (a) choisi : corriger le texte CLAUDE.md §9 uniquement (aucun code touché).
  • test: N/A (doc)
- T-1.2.3 Si (b) choisi : scoper `User` par branche pour les 4 rôles concernés, RED-team sur les
  régressions potentielles (staff cross-branche existant en donnée réelle ?), sentinel
  `BranchScopeCoverageSentinelTest` mis à jour pour vérifier le COMPORTEMENT (une requête staff
  d'une branche ne doit PAS voir les lignes d'une autre), pas la présence textuelle.
  • test: (TO BE CREATED at tests/Feature/Sentinels/BranchScopeUserBehavioralSentinelTest.php)
**Acceptance** : CLAUDE.md §9 et le comportement réel du code disent la MÊME chose (peu importe
laquelle des deux directions est choisie), le sentinel concerné teste un comportement réel.

### Sub 1.3 — Printers SSRF-guard vs architecture LAN : le risque réel est un port-scan oracle, pas juste "loopback autorisé"
**⚠️ CORRIGÉ après revue Architect — le risque initial était sous-évalué.** `SafeRemoteHost`
ne valide QUE le champ `host` de `PrinterRequest` ; le champ `port` reste libre sur `[1,65535]`.
`TcpPrinterTransport::send()` fait un `fsockopen($host, $port, …)` brut avec des messages
d'erreur DISTINCTS selon l'issue (`tcp_open_failed:$errstr($errno)` vs `tcp_write_partial`) — une
**oracle de scan de port** classique. Le docblock d'origine de `SafeRemoteHost.php` désigne
explicitement ce `fsockopen` comme "port-scan primitive" et met `127.0.0.0/8` dans la liste
interdite précisément pour CETTE raison — le risque qu'on rouvrirait avec une allowlist host-seul
n'est PAS le risque résiduel "DNS-rebind" déjà accepté par le développeur d'origine, c'est un
risque DIFFÉRENT et non accepté. Autre point : ce chemin est atteignable par **POS-Operator** (via
`testPrint`), pas seulement Admin — surface plus large qu'"admin de confiance seul".
**Tasks** :
- T-1.3.1 Confirmé le 2026-08-13 (mémoire) : impossible de créer/modifier une imprimante avec le
  SEUL format d'adresse valide pour ce restaurant (`127.0.0.1:9100`/`9101`, pont local par poste,
  cf. CLAUDE.md). La garde bloque `127.0.0.0/8` par défaut ; le mécanisme d'allowlist EXISTE déjà
  (`SafeRemoteHost.php`) et attend `SAFE_REMOTE_HOST_ALLOWLIST` en `.env` — vide aujourd'hui, mais
  ne couvre que le HOST, jamais le PORT.
  Proposer à l'owner (item §G, changement `.env` + potentiel changement de code) DEUX options,
  pas une seule : (a) allowlist host-seul telle que le mécanisme existe déjà
  (`SAFE_REMOTE_HOST_ALLOWLIST=127.0.0.1/32`) — SIMPLE mais rouvre l'oracle de scan sur les 65535
  ports de la machine locale ; (b) allowlist host+port combinée (ex. limiter explicitement le port
  à la plage `9100-9103` quand le host est dans l'allowlist) — nécessite une petite extension de
  `SafeRemoteHost`/`PrinterRequest`, ferme réellement l'oracle. **Recommandation à l'owner : (b).**
- T-1.3.2 Une fois la décision prise et l'allowlist (+ éventuelle extension port) posée : test réel
  création/édition imprimante avec `127.0.0.1:9100` — doit PASSER ; tentative avec un port hors
  plage autorisée (si option b retenue) doit continuer d'ÉCHOUER, preuve que l'oracle reste fermé
  pour tout ce qui n'est pas explicitement listé. `testPrint` toujours safe (bypass déjà confirmé
  `printing.bypass.enabled=true` en dev).
  • test: (TO BE CREATED at tests/e2e/admin-printer-lan-allowlist-2026-08-13.spec.js)
**Acceptance** : un admin peut créer/modifier une imprimante avec l'adresse réelle du pont local,
SANS rouvrir un oracle de scan de port exploitable sur le reste de la machine — garde toujours
active pour tout ce qui n'est pas explicitement et étroitement listé.

---

## §2 Système 2 — Backend : santé prouvée en direct, pas supposée

### Contract
Le scheduler et le worker tournent (vérifié SSH ce jour). Ce système cherche les défaillances
SILENCIEUSES — celles qu'une tâche "DONE" dans les logs ne révèle pas.

### Anchors (vérifiés ce jour, via SSH direct sur le VPS)
- `crontab -l` sur `lecayenne` (alias SSH, `ubuntu@vps-418872ac`) : `* * * * * ... schedule:run`
  actif.
- `supervisorctl status` : `lecayenne-worker RUNNING`, queue `redis`.
- `storage/logs/schedule.log` (tail 40 lignes) : toutes les tâches des 5 dernières minutes `DONE`,
  0 erreur visible.
- `failed_jobs` table : 0 ligne au moment de la vérification.
- `app/Console/Kernel.php` (598 lignes) : 25+ tâches planifiées, dont `fiscal:close-all-active-branches`
  (23:59) / `fiscal:open-all-active-branches` (00:01) — le cycle qui a eu un vrai P0 17 jours plus
  tôt ce mois-ci (cf. `PROJECT_BRAIN.md §2`, résolu 2026-08-13), `backup:verify-restore` (05:00
  quotidien — existe-t-il une PREUVE que cette vérification réussit, ou seulement qu'elle
  s'exécute ?).

### Sub 2.1 — Logs plus loin que 5 minutes, chercher les erreurs récurrentes
**Tasks** :
- T-2.1.1 `laravel.log` sur le VPS : chercher les motifs `ERROR`/`CRITICAL`/`EXCEPTION` récurrents
  sur une fenêtre plus large (dernières 24-48h), pas juste le tail immédiat. Distinguer bruit
  connu (WebSocket Pusher en dev, déjà documenté ailleurs) de vrais problèmes.
  • test: N/A (audit, rapport écrit)
- T-2.1.2 `schedule.log` sur une fenêtre de 24h : chercher toute tâche qui échoue silencieusement
  (exit non-zéro sans lever d'exception visible) ou qui dépasse un temps d'exécution anormal.
**Acceptance** : rapport écrit avec verdict explicite par motif trouvé (bruit connu / à corriger /
à surveiller), pas de silence sur ce qui est trouvé.

### Sub 2.2 — Preuve réelle que les backups sont RESTAURABLES, pas juste PRIS
**Tasks** :
- T-2.2.1 Lire `backup:verify-restore` (la commande elle-même) — que vérifie-t-elle réellement ?
  Restaure-t-elle vraiment un dump dans une base de test et compare, ou vérifie-t-elle seulement
  que le fichier existe et n'est pas vide (preuve faible) ?
  • test: à documenter, pas nécessairement à créer si la commande existante est déjà rigoureuse
- T-2.2.2 Si la vérification est faible (juste "le fichier existe") : proposer le renforcement
  (restauration réelle dans une DB scratch + comptage de lignes clé) comme tâche séparée, PAS
  l'implémenter sans confirmation owner si ça touche la prod (item §G potentiel selon ce qui est
  trouvé).
**Acceptance** : verdict clair sur la force réelle de la preuve de restaurabilité, documenté dans
PROJECT_BRAIN.md.

### Sub 2.3 — Scheduler : le point qui a déjà causé un vrai P0 (Z fiscal) — creuser une seule fois, sérieusement
**Tasks** :
- T-2.3.1 Le P0 du 2026-08-13 (Z bloqué 17 jours) était un défaut de VÉRIFICATION DE CHAÎNE, pas
  de scheduler — déjà résolu et attesté (`PROJECT_BRAIN.md §2`). Ce sub-système ne re-creuse PAS
  ce P0 (déjà clos avec preuve). Il vérifie juste que le MÉCANISME qui l'a caché (logs `opened=0
  failed=1` journalisés sans que personne ne lise pendant 17 jours) a une alerte active
  maintenant, pas seulement un log passif.
  • anchor: grep `opened=0\|failed=1` dans les commandes fiscales + vérifier s'il existe un canal
    d'alerte (mail/Slack/dashboard) au-delà du fichier log.
**Acceptance** : soit une alerte active existe et est confirmée fonctionnelle, soit son absence
est documentée comme dette explicite avec une recommandation concrète (pas juste "ça devrait
alerter quelqu'un").

---

## §3 Système 3 — Parcours Commerçant : trancher ce qui traîne, pas re-page-tester

### Contract
70/71 pages ont une preuve BOUTON. Ce système ne re-teste PAS les boutons. Il force une décision
sur ce qui a été TROUVÉ mais jamais TRANCHÉ par l'owner — la vraie dette du "parcours commerçant"
n'est pas dans des boutons cassés, elle est dans des décisions en attente.

### Anchors (vérifiés — extraits directement de `goal_admin_nav_breadth_convergence_2026-08-13.md`,
pas reformulés de mémoire)
- **`OrderSetup`** : "tout le bloc frais de livraison est cosmétique, jamais lu — le vrai calcul
  lit des colonnes `Branch` sans UI admin" — trouvé, jamais tranché.
- **`KioskSetup`** : `kiosk_admin_pin` implique une porte de sécurité qui n'existe nulle part —
  trouvé, jamais tranché.
- **`LoyaltySetup`** : la valeur en euros d'un solde de points DÉJÀ GAGNÉ change rétroactivement
  sans préavis — trouvé, jamais tranché.
- **Filiales** (`/admin/settings/branches`) : décision owner explicite déjà demandée (mono-branche
  V1, aucun test de mutation réelle) — toujours en attente formelle.

### Sub 3.1 — Présenter les 3 décisions en attente, obtenir un choix réel
**Tasks** :
- T-3.1.1 Pour chacune des 3 (OrderSetup/KioskSetup/LoyaltySetup) : vérifier une dernière fois
  dans le code ACTUEL (pas supposer que la description d'il y a quelques heures est encore
  exacte, une autre session a touché des fichiers voisins aujourd'hui) que le défaut existe
  toujours tel que décrit, puis le présenter à l'owner avec 2-3 options concrètes chacun (pas
  "que veux-tu faire ?" ouvert).
**Acceptance** : chacun des 3 items a soit une décision owner actée (et alors une tâche de
correction créée séparément), soit un report explicite documenté avec raison.

### Sub 3.2 — Cohérence du parcours bout-en-bout (pas page par page)
**Tasks** :
- T-3.2.1 Un seul test d'INTÉGRATION narratif : ouverture de branche (`fiscal:open-all-active-branches`
  déjà prouvé ce jour) → une commande réelle passe (POS ou Kiosk, patron "commande jetable" déjà
  éprouvé par la session nav-breadth) → apparaît dans le rapport de ventes ET le X-report (déjà
  testé séparément le 2026-08-13, jamais chaîné) → clôture Z (`fiscal:close-all-active-branches`)
  → réouverture. Le but n'est pas de re-prouver chaque maillon (déjà fait), c'est de prouver que
  la CHAÎNE ne casse nulle part entre des maillons individuellement verts.
  • test: (TO BE CREATED at tests/Feature/Fiscal/DayInTheLifeMerchantJourneyTest.php)
**Acceptance** : le test narratif complet passe en une seule exécution, prouvant l'absence de
rupture entre les maillons déjà individuellement validés.

---

## §A Armée d'agents

| Rôle | Sub-systèmes | Mode |
|---|---|---|
| Security | 1.1, 1.2, 1.3 | lecture seule d'abord |
| Architect | 1.2 (décision BranchScope), 2.2, 2.3 | lecture seule |
| Implementer | 1.1 (test enforcement), 1.3.2 (test allowlist), 3.2 (test narratif) | write, séquentiel |
| RED-team | après chaque implémentation | lecture seule, dispute |
| SRE | 2.1, 2.2, 2.3 | lecture seule + SSH |

Dispatch : Security+Architect en parallèle (lecture seule, systèmes disjoints) ; Implementer
séquentiel par sub-système (même repo, discipline déjà appliquée toute la journée) ; RED-team
après chaque commit.

---

## §X Vagues

- **Wave 1** (fait, ce document) — ancrage + advisor.
- **Wave 2** — Sub 1.1 (enforcement direct-API) + Sub 2.1/2.2/2.3 (audit backend, lecture seule,
  aucune écriture prod) EN PARALLÈLE (systèmes disjoints, aucune écriture partagée).
- **Wave 3** — Sub 3.1 : présenter les 3 décisions owner. **BLOQUANT pour Sub 1.2.c et toute
  correction OrderSetup/KioskSetup/LoyaltySetup** tant que non tranché.
- **Wave 4** — Sub 1.3 (allowlist imprimante, après gate G1) + Sub 1.2 (selon décision Wave 3).
- **Wave 5** — Sub 3.2 (test narratif bout-en-bout) + convergence finale.

---

## §G Owner gates

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | Débloquer la création/édition d'imprimante LAN : (a) allowlist host-seul simple mais rouvre un oracle de scan de port, ou (b) allowlist host+port fermée (recommandé) | Owner | choix (a)/(b) + confirmation `.env` prod | commit + `.env` VPS | PENDING |
| G2 | CLAUDE.md §9 vs comportement réel `User`/BranchScope — corriger la doc OU le code | Owner | choix (a) ou (b) | PROJECT_BRAIN.md §2 | PENDING |
| G3 | OrderSetup frais de livraison cosmétique — que faire | Owner | choix parmi options présentées | PROJECT_BRAIN.md §2 | PENDING |
| G4 | KioskSetup PIN sans porte de sécurité réelle — que faire | Owner | choix parmi options présentées | PROJECT_BRAIN.md §2 | PENDING |
| G5 | LoyaltySetup valeur rétroactive des points — que faire | Owner | choix parmi options présentées | PROJECT_BRAIN.md §2 | PENDING |

G2-G5 ne bloquent PAS Wave 2 (audit backend + enforcement RBAC tournent sans eux). G1 ne bloque
que Sub 1.3.2.

## §F Règle finale
DONE = Sub 1.1/2.1/2.2/2.3/3.2 convergées avec preuve (test path réel ou explicitement TO BE
CREATED exécuté), les 5 gates §G présentées à l'owner avec options concrètes (pas juste
mentionnées), 0 ligne de zone gelée §7, 0 régression sur les 137 sentinels + suite Wheel/Fiscal
existante, PROJECT_BRAIN.md §2 mis à jour.
