# Z7 — OPÉRATIONS & ÉQUIPEMENT (bornes, imprimantes, TPE, KDS/OSS, santé) — 2026-08-26

> Cible `http://127.0.0.1:8766` (arbre principal, HEAD `43b120c7d` + non commité), base locale `foodking_e2e`. Second passage :
> réutilise les 53 résultats API du premier (`…/tmp/recon/Z7/api-1-results.json`, cités `[n]`), son journal Playwright
> (`pw-log.json`) et ses 12 captures ; ajoute la preuve « borne supprimée » (`api-5-results.json`), l'état DB et le nettoyage final.
> **VERSION PARTIELLE écrite tôt (règle de résilience) — complétée en fin de passage.**

## 1. Périmètre parcouru (URL réellement visitées, état)
| URL | État | Preuve |
|---|---|---|
| `/admin/settings/kiosk-machines/list` (+ modale « Ajouter une borne ») | 200, 14 bornes dont 12 `KM-STRESS-*`/`KM-SOAK-*` | captures 01, 02 ; `[14]` |
| `/admin/settings/printers` (+ formulaire, 4 hôtes, largeur 42) | 200, 3 lignes (2 réelles « Archivé ») | captures 03-05 ; `[18]-[31]` |
| `/admin/settings/payment-terminals` (+ modale « Modifier » du TPE réel, non enregistrée) | 200, 2 lignes | captures 06, 07 ; `[32]-[38]` |
| `/admin/settings/kiosk-setup` (lecture) | 200 | capture 08 ; `[16]` |
| `/admin/observability/system` (État du système, interrupteurs) | 200, lié au menu (`menus` id 33 `observability/system`) | capture 09 ; `[39]-[50]` |
| `/admin/observability/outbox` | 200, **orphelin** (aucun lien) | capture 10 ; `[48]-[49]` |
| `/admin/kitchen-display-system` (admin — `chef@` refusé, voir §4) | 200, V2, état vide | capture 12 |
| `/admin/order-status-screen` (lecture) | 200, titres « En préparation » / « Prêt » | DOM `pw-log.json` (pas de capture : 12 max) |
| `/kiosk/login` (contexte vierge) | 200 → **redirigé `/kiosk/idle`**, auto-login `kiosk-lecayenne` | capture 13 |
| `/api/healthz` · `/api/health` · `/api/health/live` · `/api/health/ready` (sans auth) | 200 / 200 / 200 / 200 (`/api/health/full` du brief n'existe pas → HTML SPA) | script `z7-db-0-etat.js` |
| `/dl/borne-bridge.js` · `/dl/caisse-bridge.js` · `/dl/kitchen-bridge.js` | 200 `text/plain` attachment (`routes/web.php:128-141`) ; `/dl/borne` → HTML SPA | idem |
| API : `kiosk-machine` CRUD/logout/change-status, `auth/kiosk-login|logout`, `printers` (+`test-print`), `payment-terminals`, `observability/interrupteurs|system-health|outbox` avec `admin@`, `pos@`, `chef@` | voir §2-3 | `api-1-results.json`, `api-5-results.json` |

## 2. CE QUI MARCHE (preuves)
- **Validations serveur** : borne en doublon → 422 `machine_id`/`username` `[4]` ; TPE `fee_percent=150` → 422 « ne doit pas dépasser 99.999 » `[35]` ; `gateway_type` hors liste → 422 `[34]` ; imprimante `width_chars=42` → 422 `[22]` ; interrupteur hors liste blanche (`idempotency`) → 404 `[45]`.
- **Mot de passe de borne jamais relu** : `GET kiosk-machine/show` ne renvoie que `id,user_id,branch_id,machine_id,username,user_name,branch_name,is_login,status` `[5]`.
- **Borne désactivée** : nouvelle connexion refusée 400 avec message FR actionnable « Réactivez-la dans Admin → Paramètres → Bornes » `[12]` (`Auth/KioskMachineLoginController.php:77-82`).
- **RBAC** : `pos@` 403 sur liste bornes `[15]`, kiosk-setup `[17]`, création imprimante `[30]`, création TPE `[37]`, `PUT interrupteurs` `[42]`, outbox `[49]` ; lecture imprimantes/TPE autorisée (`permission:pos`, `PrinterController.php:19-20`) ; `chef@` sans jeton → 401.
- **Interrupteur** `impression_ticket_client_auto` : PUT `true` → `actif:true` `[40]`, relu `[41]`, RESTAURÉ `false` `[46]` ; preuve DB : `settings` pilotage `{"$value": false}`, `config('printing.auto_print_client_receipt')=false`.
- **CRUD TPE** : création 201 `[32]`, modification des frais 200 `[33]` ; « Supprimer » = archivage `status=5` (`PaymentTerminalController.php:102-107`), rien n'est perdu.
- **Imprimantes** : nom DNS accepté `[21]`, type USB sans hôte accepté `[25]`, suppression 204 `[24][26]`, `test-print` 200 `[27]`.
- **Santé** : `/api/healthz` honnête (`queue_pending:1511`, chaîne fiscale ok) ; « État du système » est dans le menu (CONFIGURATION → État du système) et lisible en français (capture 09).
- **Ponts** : les trois scripts se téléchargent en clair (`text/plain`, `attachment`) — mais aucun écran n'y mène (§4).
- **KDS/OSS** : s'ouvrent, état vide propre « Aucune commande en cours » (capture 12), aucune erreur console hors `ERR_CONNECTION_REFUSED` (WebSocket local absent, dégradation polling).

## 3. CONSTATS (P0 → P3)
```
[P1] app/Services/KioskMachineService.php:108-127 (destroy), :176-195 (logout), :151-174 (changeStatus) — Déconnecter, désactiver ou SUPPRIMER une borne dans le Dashboard ne coupe pas sa session
  reproduction : créer une borne test → POST /api/auth/kiosk-login → Dashboard « Déconnexion » (kiosk-machine/logout) → GET /api/frontend/menu avec le jeton = 200 [8][9] ; désactiver → 200 [10][11] ; Supprimer → 200 (api-5-results.json : ligne kiosk_machines=0, jeton=1) ; seul POST /api/auth/kiosk-logout PAR LA BORNE révoque (401, jeton=0)
  preuve       : réponses API ci-dessus + SELECT personal_access_tokens device_id='kiosk-85' (1 puis 0) ; le code ne touche jamais aux jetons (push Firebase + delete / is_login=NO / status)
  impact commerçant : une borne retirée, volée ou mal réglée continue à commander jusqu'à 8 h (sanctum.expiration 480) alors que l'écran dit « déconnectée » / « inactive » / n'existe plus
  recommandation : dans les trois méthodes, supprimer les jetons `device_id = 'kiosk-<id>'` (DeviceTokenService) ; refuser en middleware un jeton kiosk dont la borne est inactive/absente

[P1] config/kiosk.php:266-283 + resources/views/master.blade.php:136-174 + app/Support/KioskAutoLoginGate.php:47-60 — La borne créée dans le Dashboard n'est pas celle qui se connecte
  reproduction : ouvrir /kiosk/login dans un navigateur vierge → redirection /kiosk/idle, `window.foodkingConfig.kioskAutoLogin.username = "kiosk-lecayenne"` (pw-log) alors que la table compte 14 bornes ; la modale « Ajouter une borne » (capture 02) ne fournit ni clé, ni lien, ni QR, ni explication de « ID machine » / « Utilisateur »
  preuve       : capture 13 ; docs/KIOSK_DEPLOYMENT.md:19-24 (« KIOSK_MACHINE_* obligatoires hors APP_ENV=local ») ; en prod l'auto-login exige en plus KIOSK_AUTO_LOGIN_TRUSTED_IPS ou KIOSK_AUTO_LOGIN_SECRET (.env)
  impact commerçant : après avoir « ajouté sa borne », la tablette n'affiche rien d'exploitable sans qu'un développeur édite le .env ; une seule identité machine pour toutes les bornes
  recommandation : écran Bornes → bouton « Installer cette borne » générant un lien `/kiosk?machine_key=…` par borne (secret en base), et encart d'étapes (pont /dl/borne-bridge.js, mode kiosque)

[P1] app/Http/Requests/Admin/PrinterRequest.php:56-62 + app/Rules/SafeRemoteHost.php:184-186 — Aucune adresse locale/LAN acceptée depuis l'écran Imprimantes, message anglais parlant de SMTP
  reproduction : Imprimantes → Ajouter → hôte 10.0.0.1:22 (capture 04) ; API : 127.0.0.1:9100 [18], 192.168.1.50:9100 [19] (= placeholder du champ, PrintersComponent.vue:115), 10.0.0.1:22 [20] → 422 « The host resolves to a forbidden IP range… Use a public hostname (e.g. smtp.mailgun.org, smtp.sendgrid.net) »
  preuve       : réponses 422 + `config('security.safe_remote_host_allowlist') = []` (tinker)
  impact commerçant : décision propriétaire du 13/08 (allowlist fermée) cohérente pour Le Cayenne, mais un nouveau restaurant ne peut enregistrer ni son imprimante réseau ni son pont local sans `SAFE_REMOTE_HOST_ALLOWLIST=192.168.1.50/32:9100` dans le .env → développeur
  recommandation : message FR propre aux imprimantes ; réglage « adresses d'imprimantes autorisées » (typé host:port) dans le Dashboard, ou à défaut l'encart qui dicte la ligne .env

[P1] resources/js/components/admin/settings/Printers/PrintersComponent.vue:35-36,141,149 + PrinterRequest.php:65 + app/Services/Kitchen/KitchenTicketAutoPrinter.php:238 — Le statut « Actif » de l'écran (1) n'est pas celui du moteur (Status::ACTIVE = 5)
  reproduction : capture 03 : les deux imprimantes réelles (`printers` id 2, 5, status=5) s'affichent « Archivé » ; une imprimante créée depuis l'écran est enregistrée status=1 [21] ; choisir le bouton radio « Archivé » envoie 5 → 422 (règle `in:0,1`) sans message (aucun `errors.status` affiché)
  preuve       : SELECT printers (status=5) ; UI `Number(printer.status) === 1 ? Actif : Archivé` ; moteur `->where('status', Status::ACTIVE)`
  impact commerçant : son imprimante cuisine ajoutée « Actif » n'est jamais choisie par l'impression automatique ; ses vraies imprimantes lui paraissent archivées ; impossible d'archiver depuis l'écran
  recommandation : une seule convention (Status::ACTIVE/INACTIVE) dans le formulaire, la règle et le moteur ; afficher l'erreur statut

[P1] PrintersComponent.vue:129-133 + PrinterRequest.php:64 — Largeur « 42 (80 mm SAGA) » proposée puis refusée en silence
  reproduction : Ajouter → largeur 42 → Enregistrer : la modale reste ouverte, aucun message (capture 05 ; pw-log `visible_field_errors: []`) ; réseau 422 [22]
  preuve       : option `<option :value="42">` vs `Rule::in([32, 48])` ; aucun `errors.width_chars` dans le formulaire
  impact commerçant : le bouton ne fait rien ; la SAGA est justement l'imprimante du comptoir
  recommandation : ajouter 42 à la règle (ou retirer l'option) et afficher l'erreur

[P1] app/Models/PaymentTerminal.php (GATEWAY_TYPES = stripe, senangpay, ingenico, verifone, manual) + app/Http/Requests/Admin/PaymentTerminalRequest.php:31 + settings/PaymentTerminals/PaymentTerminalsComponent.vue:92-96 — Le seul TPE réel (`gateway_type=simulation`) n'est pas éditable et rien n'explique qu'il est simulé
  reproduction : Terminaux → « Modifier » sur « TPE Le Cayenne #1 » → PASSERELLE vide (capture 07) ; API `gateway_type=simulation` → 422 [34]
  preuve       : SELECT payment_terminals id 1 `gateway_type=simulation` ; options du select sans « simulation », aucun texte d'aide (pw-log `hint_simulation:false`)
  impact commerçant : ouvrir son TPE le force à choisir Ingenico/Verifone/Stripe/Senangpay/Manual — sans rapport avec le TPE simulé (CONSTITUTION §2) ; l'écran ne dit jamais que l'encaissement carte est simulé
  recommandation : option « Simulation (aucun terminal branché) » + bandeau explicatif ; masquer Stripe/Senangpay en V1 FR

[P2] resources/js/components/admin/observability/SystemHealthComponent.vue + docs/RUNBOOK_WORKER_CAISSE.md:61 — Les trois alertes de l'État du système sont permanentes et non actionnables
  reproduction : capture 09 : « file d'attente : 1490 messages » (= file `notifications` gelée volontairement, runbook §4), « dernière sauvegarde il y a 21 jours », « planificateur muet depuis 656 min » ; capture 10 (Outbox, page orpheline, Admin seulement [49]) : 10 674 événements en attente, `queue:work` DOWN, `websockets:serve` DOWN, boutons « Rejouer les échecs » / « Purger échecs > 24h »
  preuve       : `/api/health` : `notifications_size:1511`, `default_size:0` ; `failed_jobs`=1 ; `domain_events` non dispatchés = 10 674
  impact commerçant : du rouge en permanence sans bouton « lancer le worker » ni distinction entre file gelée et file en panne ; il finit par ignorer l'écran
  recommandation : exclure la file `notifications` du compteur (ou la nommer), lier Outbox au menu ou le retirer, ajouter l'action attendue à chaque alerte

[P2] resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:131-137,305-309,320-327 — Postes de cuisine et carillon non réglables centralement
  reproduction : capture 12 (V2 par défaut) : aucun filtre de poste (il n'existe qu'en disposition legacy `#kds-station-filter`) ; son = case + volume persistés en localStorage du navigateur
  preuve       : SELECT items actifs par `kds_station` : cuisine_chaude 37, cuisine_froide 3, bar 8, none 11 — vocabulaire différent de celui des imprimantes (`receipt/kitchen_hot/kitchen_cold/bar`)
  impact commerçant : ses postes ne se définissent qu'article par article, avec deux nomenclatures ; pas de réglage « son cuisine » partagé
  recommandation : écran « Postes » (liste + correspondance imprimante) et préférences KDS côté serveur

[P2] settings/KioskSetup (capture 08) + [16] `kiosk_admin_pin_set:false` — PIN admin borne par défaut « 1234 » affiché à l'écran, jamais imposé
  impact commerçant : la sortie du mode borne est protégée par un code public tant qu'il n'y pense pas
  recommandation : forcer la saisie d'un PIN au premier enregistrement, ne pas afficher la valeur par défaut

[P3] routes/api.php:144-146 — `/api/health` et `/api/health/ready` détaillent version, files, driver de diffusion, planificateur, sauvegardes sans authentification (le commentaire `:150-151` réserve ce rôle à `/healthz`) ; faible (LAN) mais inutile pour un commerçant
[P3] captures 01/03/06 — colonnes Statut/Action hors cadre à 1366 px (défilement horizontal) ; 12/14 bornes sont des reliquats `KM-STRESS-*`/`KM-SOAK-*` (base locale)
[P3] config/printing.php:202-207 — `test-print` répond `ok:true` vers un hôte inexistant [27] parce que `PRINTING_BYPASS_MODE=true` (interdit en prod) ; en local, le bouton ne prouve rien
```

## 4. ANGLES MORTS d'un nouveau commerçant
1. **Installer sa borne** : aucun écran ne relie « Bornes » → identité machine (.env) → pont `/dl/borne-bridge.js` → mode kiosque ; le guide est un fichier du dépôt (`docs/KIOSK_DEPLOYMENT.md`). 2. **Brancher une imprimante** : LAN refusé (P1), statut incohérent (P1), largeur 42 muette (P1), ponts USB indécouvrables. 3. **TPE** : pas de mot « simulé » à l'écran. 4. **Cuisine** : pas de notion de « poste » hors fiche article. 5. **Santé** : alertes sans action ; Outbox orphelin. 6. **Compte cuisine** : `chef@lecayenne.fr / 123456` refusé (400 « Identifiants invalides ou compte bloqué », `pw-log`) alors que `users` le dit actif (status 5) — dérive locale, mais le message ne distingue pas mot de passe faux et compte bloqué ; scénario « que voit `chef@` » exécuté avec `admin@`. 7. **Carillon** : réglé par navigateur, perdu à chaque changement de machine.

## 5. « CAYENNE » EN DUR vu à l'écran ou dans la config équipement
`kiosk-lecayenne` / `kiosk123` (`config/kiosk.php:275-278`, `docs/KIOSK_DEPLOYMENT.md:21-22`, `EnsureKioskMachineCommand.php:24`, e-mail `kiosk-borne-b{id}@lecayenne.local` `:141`) · afficheur client « LE CAYENNE » (`config/printing.php:185`) · ticket `RECEIPT_WEBSITE=lecayenne.fr`, `RECEIPT_PHONE=03 65 67 82 91` (`:83,:109`) · ponts « Pont d'impression BORNE Le Cayenne — Sanei SK1-31 » (`tools/borne/bridge.js` en-tête) · `KIOSK-LC-001`, « TPE Le Cayenne #1 / SIM-CAYENNE-1 », « Le Cayenne (principal) » dans toutes les listes · écran d'accueil borne : logo, « TACOS • BURGERS • SANDWICHS • BOWLS », « 100% HALAL », « Le Terminator » (capture 13) · comptes par défaut `admin@lecayenne.fr` / `pos@lecayenne.fr` (`config/app.php:123,129`).

## 6. QUESTIONS PROPRIÉTAIRE
1. L'allowlist fermée du 13/08 reste-t-elle la règle pour un commerçant tiers, ou accepte-t-on un réglage Dashboard des `host:port` autorisés ? 2. Révoquer les jetons à la déconnexion / désactivation / suppression d'une borne : accord pour toucher `KioskMachineService` ? 3. Statut imprimante : convention 1/0 (écran) ou 5/10 (moteur) ? 4. Largeur 42 : ajouter à la règle ? 5. Le TPE « simulation » doit-il être éditable et affiché comme tel ? 6. Purger les 12 bornes de stress avant livraison ? 7. Outbox : page à lier ou à retirer ? 8. PIN borne obligatoire au premier démarrage ?

## 7. NETTOYAGE effectué (preuve DB) — complété par le chef de projet
Bornes 84 et 85 supprimées par l'agent ; imprimante 19 supprimée ; TPE 21 supprimé par le chef de projet ; jetons `device_id` `kiosk-84`/`kiosk-85` : 0 en base ;
interrupteur `printing.auto_print_client_receipt` = `false` (défaut) vérifié. `kiosk_machines/printers/payment_terminals LIKE '%AUDIT%' = 0`.

## 8. Captures (`recon/screens/Z7/`)
`01` liste des bornes (14, dont 12 `KM-STRESS/SOAK`) · `02` modale « Ajouter une borne » (aucune clé, aucune aide) · `03` imprimantes réelles affichées « Archivé » · `04` hôte `10.0.0.1:22` refusé, message anglais SMTP · `05` largeur 42 : modale muette · `06` TPE liste · `07` TPE « Modifier » passerelle vide · `08` configuration borne (PIN « 1234 » affiché) · `09` État du système (3 alertes permanentes) · `10` Outbox orphelin (10 674 en attente, workers DOWN) · `12` KDS V2 vide · `13` `/kiosk/login` → idle auto-connecté `kiosk-lecayenne`.
