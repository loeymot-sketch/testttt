# GOAL — ONB-10 ÉQUIPEMENT & OPÉRATIONS
## FoodKing — Onboarding commerçant · brancher sa borne, son imprimante, son TPE, régler sa cuisine et lire la santé du système depuis le Dashboard, sans développeur

- **Slug** : `ONB10_EQUIPEMENT_ET_OPERATIONS_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — sous-voie « équipement & observabilité » (`settings/{KioskMachine,Printers,PaymentTerminals}/**`, `admin/observability/**`) ; KDS/OSS = voie KDS+OSS **en lecture** (coordination)
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB10_EQUIPEMENT_ET_OPERATIONS.md`
- **Port de session** : **8810** · **Persona** : Nadia reçoit sa borne et son imprimante un dimanche ; elle veut les brancher depuis le Dashboard et savoir si « ça marche ».

> **En cinq lignes.** Le problème (mesuré Z7, 53 appels API, 13 captures) : **déconnecter, désactiver ou supprimer une borne ne révoque pas son jeton** (elle commande
> encore 8 h) ; la borne créée dans le Dashboard **n'est pas celle qui se connecte** (auto-login `kiosk-lecayenne`, `.env`) ; l'écran Imprimantes **refuse toute adresse
> locale/LAN** avec un message anglais parlant de SMTP, affiche « Archivé » pour les imprimantes actives (statut 1 vs 5) et propose une largeur 42 qu'il refuse en silence ;
> le seul TPE réel (`simulation`) n'est pas éditable et rien ne dit qu'il est simulé ; État du système montre trois alertes permanentes non actionnables et l'Outbox est orphelin.
> FINI = parcours « installer sa borne / son imprimante / son TPE » sans `.env`, jetons révoqués, statuts cohérents, santé actionnable (C1..C7). Hors : KDS/OSS en écriture (voie KDS),
> visibilité du menu (→ 05), réglages typés (→ 05). Premier geste : W0 puis rejouer `tmp/recon/Z7/z7-api-1-create.js` + `z7-api-5-borne3.js` sur :8810.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb10-equipement`, branche `goal/onb10-equipement-2026-08-26`, depuis **HEAD**.
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8810` ; `.env.testing` ; liens durs ; `ReflectionClass(App\Services\KioskMachineService::class)` → worktree ; serveur 8810 ; `PLAYWRIGHT_BASE_URL`.
- ⚠️ `PRINTING_BYPASS_MODE=true` en local : `test-print` répond `ok:true` vers un hôte inexistant (`config/printing.php:202-207`) — **une preuve d'impression n'est jamais un 200** ; utiliser le pont de test ou un serveur TCP de capture (`nc -l 9100`) pour prouver les octets.
- Base partagée : bornes / imprimantes / TPE de test préfixés `GOAL-ONB10`, supprimés en fin de vague **avec** leurs jetons (`personal_access_tokens.device_id = 'kiosk-<id>'`) et leur utilisateur technique (`kiosk-borne-b<id>@…`) ; ⛔ ne jamais toucher aux 14 bornes existantes (dont 12 `KM-STRESS/SOAK` : leur purge est un gate), aux 3 imprimantes, aux 2 TPE réels ;
  interrupteurs : valeur notée avant, restaurée après ; jamais `migrate:fresh` ; `safe-test.sh --phpunit "Kiosk|Printer|PaymentTerminal|Health|Pilotage"`.
- Filet : `git branch backup/pre-onb10-2026-08-26` + `mysqldump foodking_e2e kiosk_machines printers payment_terminals`.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Bornes | `settings/KioskMachine/{KioskMachineComponent,KioskMachineListComponent,…}.vue`, `app/Http/Controllers/Admin/KioskMachineController.php`, `app/Services/KioskMachineService.php`, `app/Http/Requests/KioskMachineRequest.php`, `app/Http/Controllers/Auth/KioskMachineLoginController.php`, `app/Support/KioskAutoLoginGate.php`, `config/kiosk.php` (clés `auto_login_*`, `:206-259`), `resources/views/master.blade.php:136-174` (payload auto-login), `docs/KIOSK_DEPLOYMENT.md`, `routes/web.php:124-141` (`/dl/*`), `tools/borne/**` (en-têtes) |
| S2 Imprimantes & TPE | `settings/Printers/PrintersComponent.vue`, `app/Http/Controllers/Admin/PrinterController.php`, `app/Http/Requests/Admin/PrinterRequest.php`, `app/Rules/SafeRemoteHost.php`, `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php` (messages), `config/printing.php` (clés d'exposition), `settings/PaymentTerminals/PaymentTerminalsComponent.vue`, `PaymentTerminalController.php`, `app/Http/Requests/Admin/PaymentTerminalRequest.php`, `app/Models/PaymentTerminal.php` (`GATEWAY_TYPES :38`) |
| S3 Cuisine & écrans (lecture + demandes) | `admin/kitchenDisplaySystem/**`, `admin/orderStatusScreen/**` **en lecture** ; nouveau `App\Enums\KdsStation` partagé (coordination ONB-02 / voie KDS), écran « Postes de cuisine » (`settings/KitchenStations/**` À CRÉER) qui **écrit `items.kds_station` en lot** via l'API articles existante |
| S4 Santé & pilotage | `admin/observability/{SystemHealthComponent,OutboxOverviewComponent}.vue`, `observabilityRoutes.js`, `app/Http/Controllers/{HealthController,HealthzController}.php`, `config/queue.php` (`monitored_queues`), `docs/RUNBOOK_WORKER_CAISSE.md` |

| HORS | Porté par |
|---|---|
| Logique KDS/OSS (`KitchenDisplaySystemComponent.vue`, `KdsV2Grid.vue`, `OrderStatusScreenController`) | voie KDS+OSS — ce GOAL lit, mesure, **demande** |
| `KioskAppComponent.vue`, `KioskWizardComponent.vue`, `KioskUpsellComponent.vue` (gelés), `KioskIdleScreenComponent.vue` (ONB-01) | jamais / ONB-01 |
| Entrées de menu (État du système, Outbox, Bornes…) | ONB-05 |
| Réglages typés (allowlist imprimantes, PIN, seuils) — **le mécanisme** | ONB-05 ; ce GOAL déclare les réglages et consomme |
| `DeviceTokenService` (révocation par appareil des utilisateurs) | lecture seule ; ce GOAL révoque les jetons **de bornes** via l'API publique du service |
| Impression des tickets (contenu, `KitchenTicketAutoPrinter`) | voie CAISSE / KDS — lecture pour la cohérence de statut |
| File `notifications` orpheline (décision purge/worker) | **G-NOTIF** partagé avec ONB-09 (push) — ce GOAL mesure et propose |

Zones à coordonner : `routes/api.php` (routes « installer cette borne », postes de cuisine), `settingRoutes.js`, `fr.json`, `config/kiosk.php` (voie BORNE : déclarer).

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé (trio kiosk, `PaymentComponent.vue`, fiscal) · SCOPE-2 3 boucles · SCOPE-3 migration non prévue (une colonne `kiosk_machines.setup_secret` est PRÉVUE : G-DATA) · SCOPE-4 NF525 (le TPE est fiscal-adjacent : jamais de changement du flux de paiement, `PaymentService.php` hors périmètre) · SCOPE-5 fichier d'une autre voie.

## §0.4 — Pipeline
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques**.

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Jeton de borne révoqué | déconnexion / désactivation / suppression dans le Dashboard → `GET /api/frontend/menu` avec l'ancien jeton = 401 en < 1 s | **3/3** |
| C2 | Parcours borne sans `.env` | Dashboard → borne → « Installer cette borne » → lien/clé → `/kiosk/...` → accueil, sur une borne **différente** de `kiosk-lecayenne` | **0** édition de fichier |
| C3 | Imprimante locale/LAN acceptée | `127.0.0.1:9100` et `192.168.1.50:9100` acceptés via allowlist host+port réglée dans le Dashboard ; `10.0.0.1:22` refusé ; message FR | **VRAI** |
| C4 | Statut imprimante cohérent | écran, règle, moteur d'impression utilisent la même convention ; imprimantes réelles affichées « Active » | **VRAI** |
| C5 | Largeur 42 | acceptée ou retirée ; aucune soumission muette | **0** erreur silencieuse |
| C6 | TPE simulé lisible | option « Simulation » éditable + bandeau explicatif ; Stripe/Senangpay masqués | **VRAI** |
| C7 | Santé actionnable | chaque alerte de l'écran porte une action ou un motif ; file `notifications` gelée nommée comme telle ; Outbox relié ou retiré | **0** alerte muette |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · `tests/Feature/Kiosk/` 9 · `tests/Feature/Hardware/` (8+) · `tests/Feature/Pos/PosSystemHealthTest.php` (19 cas) · `tests/js/posSystemHealthPill.spec.js` (16) ·
Mesures Z7 : 14 bornes (12 stress), 3 imprimantes (2 réelles `status=5` affichées « Archivé »), 2 TPE (`simulation`, `manual`), `/api/healthz` `queue_pending 1511`, `domain_events` non dispatchés 10 674, `failed_jobs` 1, `queue:work` DOWN, `websockets:serve` DOWN (local).
**Prouvé Z7 (ne pas casser)** : doublon de borne 422 ; mot de passe de borne jamais relu ; borne désactivée → connexion refusée 400 FR actionnable (`KioskMachineLoginController.php:77-82`) ; RBAC `pos@` 403 (bornes, kiosk-setup, imprimantes/TPE en écriture, interrupteurs PUT, outbox) ; TPE `fee_percent 150` → 422 ; `gateway_type` hors liste 422 ; interrupteur restauré ; suppression TPE = archivage `status=5` ; nom DNS et USB acceptés pour une imprimante.

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : G0.
- **C-LAN** — décision propriétaire 2026-08-13 « allowlist fermée » (`SafeRemoteHost.php:45-56`, option (b) host+port) vs mandat 2026-08-26 « sans développeur ». Tranché : **la décision de sécurité reste** (rien d'ouvert par défaut) ; ce qui change, c'est **où** l'allowlist se règle (Dashboard, réglage typé host:port via ONB-05) au lieu du `.env` — G-LAN.
- **C-STATUT** — écran `1/0` (`PrintersComponent.vue:35-36,141,149`, `PrinterRequest.php:58 Rule::in([0,1])`) vs moteur `Status::ACTIVE = 5` (`KitchenTicketAutoPrinter.php:238`) : les vraies imprimantes sont `5`. Tranché : **convention `Status::ACTIVE/INACTIVE` partout** (données existantes conservées).
- **C-BORNE** — la fiche Z0 (`Z0_carte`) disait « clé machine affichée ? » : mesuré NON ; la borne qui se connecte est `kiosk-lecayenne` (`config/kiosk.php:275-278`, `master.blade.php:136-174`, `KioskAutoLoginGate.php:47-60`, `docs/KIOSK_DEPLOYMENT.md:19-24`). Tranché : une identité **par borne**, secret en base, lien d'installation — G-DATA.
- **C-SANTÉ** — `Z0_carte §6` disait État du système orphelin ; mesuré : relié (`menus` id 33). Outbox, lui, est orphelin. Corrigé.

## §0.8 — Le commerçant-type et ses questions
Nadia : 1. « J'ai ajouté ma borne dans le logiciel, et la tablette affiche… quoi ? » 2. « Mon imprimante est en 192.168.1.50 : pourquoi refusée, et c'est quoi SMTP ? »
3. « Mes imprimantes sont "Archivé" : elles marchent pourtant ? » 4. « Le TPE est "Simulation" : je peux encaisser par carte ? » 5. « Il y a du rouge partout dans État du système : je fais quoi ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Bornes | **CRUD OK, amorçage et révocation CASSÉS** | `app/Services/KioskMachineService.php` (`destroy :108`, `changeStatus :147`, `logout :176` — aucun ne touche aux jetons) · `Auth/KioskMachineLoginController.php:77-82` · `config/kiosk.php:206-211,256-259,266-283` (`spa_auto_login`, `auto_login_trusted_ips`, `auto_login_local_bypass`, `auto_login_secret`, `kiosk-lecayenne`) · `resources/views/master.blade.php:136-174` · `app/Support/KioskAutoLoginGate.php:47-60` · `docs/KIOSK_DEPLOYMENT.md:19-24` · `routes/web.php:124-141` (`/dl/{bridge}`) · `KioskMachineTableSeeder.php` · routes `routes/api.php:666-674` | `tests/Feature/Kiosk/` 9 · `tests/Feature/Auth/MultiDeviceLoginTest.php` |
| S2 Imprimantes & TPE | **BLOQUANT pour un nouveau restaurant** | `app/Http/Requests/Admin/PrinterRequest.php:56-62` (hôte `SafeRemoteHost`), `:57` `width_chars in [32,48]`, `:58` `status in [0,1]` · `app/Rules/SafeRemoteHost.php:19,45-56,184-186` · `PrintersComponent.vue:35-36,115,129-133,141,149` · `app/Services/Kitchen/KitchenTicketAutoPrinter.php:238` · `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php` · `config/printing.php:83,109,185,202-207` · `app/Models/PaymentTerminal.php:38` (`GATEWAY_TYPES` sans `simulation`) · `PaymentTerminalRequest.php:31` · `PaymentTerminalsComponent.vue:92-96` · `PaymentTerminalController.php:102-107` (archivage) · routes `api.php:1330-1346` | `tests/Feature/PrinterControllerTest.php`, `tests/Feature/Admin/PaymentTerminalControllerTest.php` (cités 13/08 — vérifier `ls`) |
| S3 Cuisine & écrans | **RÉGLABLE ARTICLE PAR ARTICLE** | `KitchenDisplaySystemComponent.vue:131-137,305-309,320-327` (V2, son en localStorage, filtre de poste legacy seulement) · `items.kds_station` (chaud 37 / froid 3 / bar 8 / none 11) · `OrderStatusScreenComponent.vue` (« En préparation » / « Prêt ») | tests KDS 46 (voie KDS) |
| S4 Santé | **HONNÊTE, NON ACTIONNABLE** | `SystemHealthComponent.vue` (7 cartes, 3 alertes) · `OutboxOverviewComponent.vue` (orphelin) · `HealthController`, `HealthzController` (`routes/api.php:144-152`, sans auth) · `config/queue.php` (`monitored_queues`) · `docs/RUNBOOK_WORKER_CAISSE.md:61` · `menus` id 33 | `PosSystemHealthTest.php` (19), `posSystemHealthPill.spec.js` (16) |

**Sortie d'ancrage brute** : `grep -n "public function destroy\|changeStatus\|logout" KioskMachineService.php` → `:108`, `:147`, `:176` · `grep -n "width_chars\|status" PrinterRequest.php` → `:57 Rule::in([32,48])`, `:58 Rule::in([0,1])` ·
`grep -rn "class TcpPrinterTransport" app` → `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:5` · `grep -n "127.0.0.0\|allowlist" SafeRemoteHost.php` → `:19`, `:45`, `:51-56` (host+port, décision 13/08) ·
`grep -n "GATEWAY_TYPES" PaymentTerminal.php` → `:38` · `grep -n "auto_login" config/kiosk.php` → `:206,210,211,256,259` · `SELECT COUNT(*) FROM kiosk_machines` → 14 · Z7 `api-1-results.json` = 53 étapes.

# §2 — ÉTAT MESURÉ LE 2026-08-26 (extrait de `recon/Z7_equipement_ops.md`)
**Marche** : voir §0.6 « prouvé ». **Constats** : [P1] jetons de borne non révoqués (logout/désactivation/suppression → menu 200 avec l'ancien jeton ; seul le logout PAR la borne révoque) · [P1] borne du Dashboard ≠ borne qui se connecte (auto-login `kiosk-lecayenne`, modale sans clé ni aide) ·
[P1] aucune adresse locale/LAN acceptée + message anglais SMTP (`allowlist = []`) · [P1] statut imprimante 1 vs 5 (réelles « Archivé », créées jamais choisies par l'auto-impression, « Archivé » depuis l'écran = 422 muet) · [P1] largeur 42 proposée puis refusée sans message · [P1] TPE `simulation` non éditable, jamais expliqué ·
[P2] alertes permanentes non actionnables ; Outbox orphelin (10 674 en attente, workers DOWN) · [P2] postes de cuisine et carillon non réglables centralement (deux nomenclatures : `cuisine_chaude` vs `kitchen_hot`) · [P2] PIN borne « 1234 » affiché · [P3] `/api/health` détaillé sans auth · [P3] colonnes hors cadre à 1366 ; 12 bornes de stress · [P3] `test-print` `ok:true` en bypass.
**Angles morts** : installer sa borne, brancher une imprimante, TPE, postes de cuisine, santé sans action, compte cuisine refusé (dérive locale), carillon par navigateur.
**Cayenne** : `kiosk-lecayenne`/`kiosk123`, `kiosk-borne-b{id}@lecayenne.local`, afficheur « LE CAYENNE », `RECEIPT_WEBSITE=lecayenne.fr`, `RECEIPT_PHONE`, ponts « Le Cayenne — Sanei SK1-31 », `KIOSK-LC-001`, « TPE Le Cayenne #1 », comptes `@lecayenne.fr`.

# §3 — SOUS-SYSTÈME 1 : BORNES — de « ajouter une borne » à « la tablette commande »

## Sub 1.1 — Révocation des jetons (le P1 de sécurité)
**Ancrages** : `KioskMachineService.php:108,147,176`, `DeviceTokenService` (API publique : révocation par `device_id`), `personal_access_tokens.device_id = 'kiosk-<id>'`, `KioskMachineLoginController.php:77-82`.
**Tâches**
- **T-1.1.1** — ROUGE d'abord : créer une borne de test → login → Dashboard logout / désactivation / suppression → `GET /api/frontend/menu` avec l'ancien jeton → aujourd'hui 200 (rejouer `z7-api-5-borne3.js`).
  • test : (À CRÉER à `tests/Feature/Kiosk/KioskMachineLifecycleRevokesTokensTest.php`)
- **T-1.1.2** — Révoquer les jetons `device_id = 'kiosk-<id>'` dans les trois méthodes ; middleware kiosk : refuser un jeton dont la borne est inactive ou absente (fail-closed) ; message FR à la borne (« Cette borne a été désactivée par le gérant »).
  • ancrage : `KioskMachineService.php`, middleware kiosk (à localiser W1 : `block_kiosk_token_admin` voisin) · test : le même, VERT · C1
  • au-delà : borne hors ligne au moment de la désactivation → refus à la reconnexion ; deux bornes, une seule révoquée ; suppression pendant une commande en cours (panier local perdu : documenter).
**Acceptation** : C1 = 3/3 · test VERT · `MultiDeviceLoginTest` intact.

## Sub 1.2 — Installer cette borne (identité par borne, sans `.env`)
**Ancrages** : `config/kiosk.php:206-283`, `master.blade.php:136-174`, `KioskAutoLoginGate.php:47-60`, `docs/KIOSK_DEPLOYMENT.md`, `/dl/borne-bridge.js`, modale « Ajouter une borne » (capture 02 : aucune aide).
**Tâches**
- **T-1.2.1** — Décision G-BORNE-ID : A) **secret d'installation par borne** en base (`kiosk_machines.setup_secret`, migration G-DATA) + lien `/kiosk/installer?borne=<id>&secret=…` à usage unique qui pose le jeton d'appareil · B) garder l'auto-login unique `.env` (statu quo, développeur obligatoire). Recommandation **A**.
- **T-1.2.2** — Écran Bornes : bouton « Installer cette borne » (lien + QR + étapes : télécharger le pont `/dl/borne-bridge.js`, mode kiosque, tester) ; modale de création avec aide (« ID machine » = identifiant unique de la tablette, « Utilisateur » = compte technique) ; regénération du secret.
  • test : (À CRÉER à `tests/Feature/Kiosk/KioskInstallLinkTest.php`) + (À CRÉER à `tests/js/kioskMachineInstallPanel.spec.js`) · visuel : `http://127.0.0.1:8810/admin/settings/kiosk-machines/list`
  • au-delà : lien utilisé deux fois → refus ; lien expiré ; borne désactivée → lien inerte ; `auto_login_local_bypass` (`config/kiosk.php:211`) ne masque pas le parcours en local (test avec le bypass désactivé).
- **T-1.2.3** — `docs/KIOSK_DEPLOYMENT.md` réécrit du point de vue du commerçant, relié depuis l'écran ; identités Cayenne (`kiosk-lecayenne`, `kiosk-borne-b{id}@lecayenne.local`) → dérivées de `company_name` (fiche de renvoi ONB-12).
**Acceptation** : C2 = 0 édition de fichier · 2 tests VERTS · captures lues · question 1 de Nadia = OUI.

# §4 — SOUS-SYSTÈME 2 : IMPRIMANTES & TPE

## Sub 2.1 — Allowlist réglable, statut cohérent, largeur honnête
**Ancrages** : `PrinterRequest.php:56-62`, `SafeRemoteHost.php:45-56,184-186`, `PrintersComponent.vue:35-36,115,129-133,141,149`, `KitchenTicketAutoPrinter.php:238`, `TcpPrinterTransport.php`.
**Tâches**
- **T-2.1.1** — ROUGE : 4 hôtes (`127.0.0.1:9100`, `192.168.1.50:9100`, `10.0.0.1:22`, DNS) + largeur 42 + statut « Archivé » → consigner (422 anglais, 422 muet).
  • test : (À CRÉER à `tests/Feature/Hardware/PrinterRequestEdgeCasesTest.php`)
- **T-2.1.2** — Allowlist **host+port** (mécanisme existant `SafeRemoteHost.php:51-56`) alimentée par un réglage typé « Adresses d'imprimantes autorisées » (déclaré via ONB-05, format `ip/32:port`, valeurs par défaut vides) ; message FR spécifique aux imprimantes (plus de « SMTP ») ; oracle de scan fermé (ports hors liste refusés, messages identiques) — G-LAN.
  • test : le même, VERT + `tests/Feature/Security/` (SSRF existant, vérifier `ls`) · C3
- **T-2.1.3** — Convention de statut unique `Status::ACTIVE/INACTIVE` (écran, règle `:58`, moteur `:238`) ; migration de données = **aucune** (les réelles sont déjà 5) ; erreurs `status`/`width_chars` affichées ; largeur 42 : ajoutée à `Rule::in` si le moteur la gère (`TcpPrinterTransport` / `OrderReceiptEscPosRenderer`, à vérifier) sinon retirée de l'écran.
  • test : (À CRÉER à `tests/Feature/Hardware/PrinterStatusConventionTest.php`) · C4, C5
- **T-2.1.4** — Preuve d'impression **par le contenu** : `test-print` en local sans bypass vers `nc -l 9100` → octets ESC/POS reçus ; message FR d'échec avec cause (hôte injoignable / refusé / timeout) au lieu de `tcp_open_failed:$errstr` (oracle).
  • test : (À CRÉER à `tests/Feature/Hardware/PrinterTestPrintBytesTest.php`)
**Acceptation** : C3, C4, C5 VRAIS · 3 tests VERTS · captures lues · questions 2 et 3 = OUI.

## Sub 2.2 — TPE simulé, dit et éditable
**Ancrages** : `PaymentTerminal.php:38`, `PaymentTerminalRequest.php:31`, `PaymentTerminalsComponent.vue:92-96`, `PaymentTerminalController.php:102-107`, `config/pos.php:37` (`simulation_hardware`), CONSTITUTION §2.
**Tâches**
- **T-2.2.1** — Ajouter `simulation` aux `GATEWAY_TYPES` et à la règle ; option « Simulation (aucun terminal branché — l'encaissement carte est saisi manuellement) » ; bandeau explicatif quand `pos.simulation_hardware=true` ; masquer Stripe/Senangpay en V1 FR (G-TPE).
  • test : (À CRÉER à `tests/Feature/Hardware/PaymentTerminalSimulationEditableTest.php`) · visuel : `/admin/settings/payment-terminals`
  • ⚠️ aucun changement de `PaymentService.php` ni du flux d'encaissement (fiscal).
- **T-2.2.2** — Frais TPE : effet réel sur les rapports (où `fee_percent` est consommé — à établir W1 ; si nulle part : le dire à l'écran).
**Acceptation** : C6 VRAI · test VERT · question 4 = OUI.

# §5 — SOUS-SYSTÈME 3 : CUISINE & ÉCRANS (lecture + demandes)

**Ancrages** : `KitchenDisplaySystemComponent.vue:131-137,305-309,320-327`, `items.kds_station`, imprimantes `receipt/kitchen_hot/kitchen_cold/bar`, migration `2026_04_20_230000`.
**Tâches**
- **T-3.1.1** — Écran « Postes de cuisine » : liste des 4 postes, correspondance poste ↔ imprimante, tableau des articles par poste avec affectation **en lot** (via `PUT item` existant), mise en évidence des `none` vendables (11 mesurés). Enum partagé `KdsStation` (coordination ONB-02 : `ItemRequest`, et voie KDS).
  • test : (À CRÉER à `tests/Feature/Kitchen/KitchenStationsBulkAssignTest.php`) + (À CRÉER à `tests/js/kitchenStationsPage.spec.js`) · visuel : `/admin/settings/kitchen-stations`
- **T-3.1.2** — Préférences KDS côté serveur (carillon, volume, disposition, délai d'alerte) : **fiche de renvoi à la voie KDS** avec le réglage typé déclaré (ONB-05) ; ce GOAL ne modifie pas le composant KDS.
- **T-3.1.3** — OSS : personnalisation (logo, message) : fiche de renvoi voie KDS+OSS ; lecture avec `chef@` réparé (W0).
**Acceptation** : 2 tests VERTS · 0 article vendable en `none` sans décision écrite · fiches de renvoi écrites.

# §6 — SOUS-SYSTÈME 4 : SANTÉ & PILOTAGE ACTIONNABLES

**Ancrages** : `SystemHealthComponent.vue`, `OutboxOverviewComponent.vue`, `HealthController`/`HealthzController` (`api.php:144-152`), `config/queue.php`, `docs/RUNBOOK_WORKER_CAISSE.md`, `menus` id 33.
**Tâches**
- **T-4.1.1** — Chaque alerte porte une **action** ou un **motif** : file `notifications` gelée (nommée « notifications en pause — décision du 25/08 », exclue du compteur rouge ou comptée à part) ; sauvegarde (bouton « lancer une sauvegarde » si commande existante, sinon « demander à l'intégrateur » avec la commande) ; planificateur (« relancer » = runbook §, non exécuté depuis l'écran en V1).
  • test : (À CRÉER à `tests/js/systemHealthAlertsActionable.spec.js`) · C7
- **T-4.1.2** — Outbox : relier au menu (fiche ONB-05) **ou** fusionner dans État du système (recommandé : carte « Événements en attente » avec « Rejouer les échecs » réservé à `settings`) — G-OUTBOX.
- **T-4.1.3** — `/api/health` et `/api/health/ready` détaillés sans authentification : réserver le détail à `settings`, garder `live/ready` minimaux pour la supervision (`routes/api.php:144-152`, commentaire `:150-151`).
  • test : (À CRÉER à `tests/Feature/Health/HealthEndpointsExposureTest.php`)
- **T-4.1.4** — Décision **G-NOTIF** (avec ONB-09) : purger ou traiter les 1 490 jobs `notifications` ; ce GOAL mesure (`SELECT queue, COUNT(*) FROM jobs GROUP BY queue`, âge min/max) et propose ; aucun worker rebranché sans accord (1 490 push sur des commandes vieilles de semaines).
**Acceptation** : C7 = 0 alerte muette · 2 tests VERTS · G-OUTBOX, G-NOTIF tranchés · question 5 = OUI.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Fonction \ scénario | annulation | rechargement | double soumission | deux onglets | rôle inférieur | données vides | volume | réseau/worker coupé | effet borne / caisse / cuisine | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Borne : cycle de vie | — | — | doublon 422 (mesuré) | désactiver + supprimer simultanément | `pos@` 403 (mesuré) | — | 14 bornes | borne hors ligne à la révocation | jeton → 401 (`KioskMachineLifecycleRevokesTokensTest`) | réactiver → relogin requis | `machine_id` 190 car., unicode |
| Installer cette borne | lien non utilisé | — | lien 2× → refus | — | lien sans permission → 403 | — | — | tablette hors ligne | borne connectée commande | regénérer le secret | secret expiré, borne inactive |
| Imprimante | — | — | 422 affiché | — | 403 écriture, 200 lecture (mesuré, `permission:pos`) | hôte vide (USB) OK (mesuré) | 3 → 10 imprimantes | hôte injoignable → message FR sans oracle | `KitchenTicketAutoPrinter` choisit l'active (`PrinterStatusConventionTest`) | archiver → désarchiver | `10.0.0.1:22`, port 65536, DNS, largeur 42 |
| TPE | — | — | — | — | 403 | frais vides | 2 | — | aucun changement du flux caisse | archivage (mesuré) | `fee_percent 150` → 422 (mesuré) |
| Postes de cuisine | annulation du lot → 0 écriture | — | idempotent | — | 403 | poste vide | 59 articles | — | KDS affiche selon le poste | remettre `none` (avertissement) | poste inconnu → 422 FR (ONB-02) |
| Santé | — | rafraîchir | « rejouer » idempotent | — | détail réservé à `settings` | file vide | 10 674 événements | worker DOWN → alerte + action | — | — | — |

# §A — ARMÉE D'AGENTS
Architecte (identité par borne, enum poste partagé) · **Sécurité** (jetons de borne, oracle de scan, `/api/health` exposé, lien d'installation à usage unique) · **SRE/Synchro** (files, worker, outbox, propagation) · UX/A11y (écran Bornes/Imprimantes/Postes, 3 gabarits) ·
**Psychologie commerçant** (« ça marche ? » → preuve par le contenu, pas par un 200 ; « Archivé » = panique ; rouge permanent = ignoré) · DBA (`kiosk_machines.setup_secret`, index `device_id`) ·
Implémenteur unique · ROUGE (rejoue `z7-api-1-create.js`, `z7-api-5-borne3.js`, 4 hôtes, après chaque vague) · QA visuel + ROUGE visuel · **Jalonneur**.
Disque `reports/test-e2e/ONB10_EQUIPEMENT_ET_OPERATIONS/<round>/wave-<W>-<rôle>.json` ; contrat de constat ; ~1 200-1 500 mots.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases, compte `chef@` réparé, `nc -l 9100` prêt | séquentiel | — |
| **W1** | Reconnaissance : rejouer Z7 ; localiser le middleware kiosk ; consommation de `fee_percent` ; capacités largeur 42 ; mesure des files | fan-out lecture seule | — |
| **W2** | S1.1 révocation des jetons (T-1.1.*) — **sécurité d'abord** | séquentiel | — |
| **W3** | S2 imprimantes & TPE (T-2.*) | séquentiel | G-LAN (réglage via ONB-05), G-TPE |
| **W4** | S1.2 installer sa borne (T-1.2.*) | séquentiel | **G-BORNE-ID, G-DATA** |
| **W5** | S3 postes de cuisine + S4 santé (T-3.*, T-4.*) | séquentiel ; coordination voie KDS | G-OUTBOX, G-NOTIF |
| **W6** | Convergence : deux cycles, `safe-test.sh --phpunit "Kiosk|Hardware|Printer|PaymentTerminal|Health"`, Vitest, Playwright `tests/e2e/onb10-*.spec.js` (À CRÉER), BRAIN | séquentiel | — |
**§X.8** 6 points · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-BORNE-ID** | Identité par borne + lien d'installation (A) vs auto-login `.env` (B) | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque W4 |
| **G-DATA** | Colonne `kiosk_machines.setup_secret` (+ `setup_expires_at`) | Propriétaire | accord | `docs/gates/GATE_LOG.md` | EN ATTENTE — bloque W4 |
| **G-LAN** | Allowlist host+port réglable depuis le Dashboard (décision fermée du 13/08 conservée, lieu du réglage changé) | Propriétaire | accord | MISSION §6 | EN ATTENTE — bloque T-2.1.2 |
| **G-TPE** | Option « Simulation » éditable, Stripe/Senangpay masqués | Propriétaire | accord | MISSION §6 | EN ATTENTE — bloque T-2.2.1 |
| **G-OUTBOX** | Outbox : fusionner dans État du système ou relier au menu | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-4.1.2 |
| **G-NOTIF** | Sort des 1 490 jobs `notifications` (purge / traitement / pause nommée) — partagé ONB-09 | Propriétaire | décision | `MISSION_ONB09` §6 + ici | EN ATTENTE — bloque T-4.1.4 |
| **G-PURGE** | Purger les 12 bornes `KM-STRESS/SOAK` (base locale) | Propriétaire | accord | MISSION §6 | EN ATTENTE — optionnel |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `CONSTITUTION.md §2` (TPE simulé) · `SYNC_CONTRACT.md` · `SYSTEM_MAP.md §1-3, §5-6` · `CLAUDE.md §9` (jetons par appareil) · `docs/PLAYWRIGHT_MCP_OPS.md §7` · `docs/KIOSK_DEPLOYMENT.md` · `docs/RUNBOOK_WORKER_CAISSE.md` ·
`plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-10) · `recon/Z7_equipement_ops.md` · `recon/Z0_carte_dashboard.md §4, §6, §10` · `tmp/recon/Z7/{z7-api-1-create.js,z7-api-5-borne3.js,z7-pw.js,api-1-results.json,pw-log.json}` ·
`plans/GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13.md` (S1.3 allowlist option b, S2 santé) · `plans/GOAL_CONSOLIDATION_V1_PRODUCTION_2026-08-25.md` (Sub 1.1 pastille, Sub 3.3 worker, file `notifications`).

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. C1..C7 VRAIS ; 3. PHPUnit ≥ 5 194 + ≥ 12 tests créés VERTS, Vitest ≥ 3 644 ; 4. diff gelé 0 (trio kiosk, `PaymentComponent.vue`, fiscal) ; 5. NF525 ajout seul, flux de paiement intact ;
6. gates tranchés ou différés ; 7. BRAIN vrai, `docs/KIOSK_DEPLOYMENT.md` réécrit ; 8. deux cycles identiques ; 9. fiches de renvoi écrites (ONB-05 réglages/menu, voie KDS préférences/OSS, ONB-02 enum station, ONB-12 identités Cayenne, ONB-09 G-NOTIF).
**Interdit** : toucher aux bornes/imprimantes/TPE réels · rebrancher un worker sur `notifications` · déclarer une impression réussie sur un 200 · éditer un composant KDS/OSS · approuver un gate.
> Le sens : Nadia branche sa borne et son imprimante un dimanche, lit « connectée » et « imprimé », et une borne retirée cesse de commander à la seconde.
