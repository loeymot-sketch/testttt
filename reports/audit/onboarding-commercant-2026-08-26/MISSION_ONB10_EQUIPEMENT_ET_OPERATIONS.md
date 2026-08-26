# MISSION ONB-10 — ÉQUIPEMENT & OPÉRATIONS · Rapport de mission
- GOAL : `plans/GOAL_ONB10_EQUIPEMENT_ET_OPERATIONS_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, `:8766`, base `foodking_e2e`)
- Port : **8810** · Voie : CENTRAL « équipement & observabilité » (KDS/OSS en lecture) · Parallèle avec : 01, 02, 05, 06, 07, 08, 09 (vague A)

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-10 (équipement & opérations). Lis : CONSTITUTION.md (§2 TPE simulé), PROJECT_BRAIN.md §2, SYSTEM_MAP.md,
SYNC_CONTRACT.md, PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/
MISSION_ONB10_EQUIPEMENT_ET_OPERATIONS.md, plans/GOAL_ONB10_EQUIPEMENT_ET_OPERATIONS_2026-08-26.md, puis recon/Z7_equipement_ops.md (intégral) et
recon/Z0_carte_dashboard.md (§4, §6, §10). Pré-vol §0.1 : worktree .claude/worktrees/onb10-equipement depuis HEAD, APP_URL=http://127.0.0.1:8810,
.env.testing, liens durs, serveur 8810, PLAYWRIGHT_BASE_URL, filet backup/pre-onb10 + dump kiosk_machines/printers/payment_terminals ; un récepteur TCP
(`nc -l 9100`) pour prouver les impressions par les octets (PRINTING_BYPASS_MODE=true rend test-print menteur en local). ⛔ Ne touche jamais aux 14 bornes,
3 imprimantes, 2 TPE existants ; entités GOAL-ONB10 supprimées avec leurs jetons (device_id kiosk-<id>) et leur compte technique. Puis « lance le GOAL » :
W0 → W1 (rejoue /Users/1millnonstop/.claude/jobs/06c6b42a/tmp/recon/Z7/z7-api-1-create.js et z7-api-5-borne3.js sur 8810 ; localise le middleware kiosk ;
mesure les files) → W2 révocation des jetons D'ABORD → W3..W6. Pipeline ultra-audit-profond, spécialistes lecture seule en un message (Sécurité + SRE en tête),
implémenteur unique, ROUGE avant tout « fini », Jalonneur, matrice §S, deux cycles identiques. KDS/OSS : lecture et fiches de renvoi, jamais d'édition.
Jamais de push. Gates §G : proposer, ne pas trancher. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Quatrième heure du commerçant : brancher son matériel. Le mandat exige que la borne, l'imprimante et le TPE se règlent depuis le Dashboard. Mesuré : la borne du Dashboard n'est pas celle
qui se connecte, une borne supprimée commande encore, l'écran Imprimantes refuse toute adresse réseau avec un message parlant de SMTP, les vraies imprimantes sont affichées « Archivé »,
le TPE simulé n'est ni dit ni éditable, et l'écran de santé crie sans dire quoi faire. Persona Nadia, un dimanche, sans intégrateur.

## 2. ÉTAT MESURÉ LE 2026-08-26 (`recon/Z7_equipement_ops.md` — quasi complet, 2 passages, 53 + 6 appels API, 13 captures `recon/screens/Z7/`)
**2.1** : `/admin/settings/kiosk-machines/list` (14 bornes), `printers` (3), `payment-terminals` (2), `kiosk-setup`, `/admin/observability/system` (relié au menu, `menus` id 33), `/admin/observability/outbox` (orphelin),
`/admin/kitchen-display-system` (V2, vide), `/admin/order-status-screen`, `/kiosk/login` → `/kiosk/idle` auto-connecté `kiosk-lecayenne`, `/api/healthz|health|health/live|health/ready` (200 sans auth), `/dl/{borne,caisse,kitchen}-bridge.js` (200 text/plain).
**2.2 Ce qui marche** : doublon de borne 422 ; mot de passe de borne jamais relu ; borne désactivée → 400 FR actionnable (`KioskMachineLoginController.php:77-82`) ; RBAC `pos@` 403 ; TPE : 201/200, `fee_percent 150` → 422, `gateway_type` hors liste 422, suppression = archivage `status=5` ;
imprimantes : DNS accepté, USB sans hôte accepté, suppression 204, `test-print` 200 (bypass) ; interrupteur PUT/restauration ; `/api/healthz` honnête (`queue_pending 1511`, chaîne fiscale ok) ; KDS/OSS s'ouvrent, état vide propre.
**2.3 Constats**
| Sév. | Constat | Preuve |
|---|---|---|
| P1 | Déconnecter / désactiver / supprimer une borne ne révoque pas son jeton (menu 200 avec l'ancien jeton ; seule la borne peut se déconnecter) | `api-1-results.json [8]-[11]`, `api-5-results.json` (borne 85 supprimée, jeton = 1) ; `KioskMachineService.php:108,147,176` |
| P1 | La borne créée n'est pas celle qui se connecte : auto-login `kiosk-lecayenne` (`config/kiosk.php:275-278`), modale sans clé/aide, `.env` requis hors local (`docs/KIOSK_DEPLOYMENT.md:19-24`) | capture 02, 13 ; `pw-log.json auto_login_username` |
| P1 | Aucune adresse locale/LAN acceptée (`127.0.0.1:9100`, `192.168.1.50:9100` = placeholder du champ, `10.0.0.1:22`) ; message anglais « … Use a public hostname (e.g. smtp.mailgun.org…) » ; `allowlist = []` | `[18]-[20]`, capture 04 ; `PrinterRequest.php:56-62`, `SafeRemoteHost.php:184-186` |
| P1 | Statut imprimante : écran 1/0 vs moteur `Status::ACTIVE=5` → réelles « Archivé », créées jamais choisies par l'auto-impression, « Archivé » depuis l'écran = 422 muet | capture 03, `[21]` ; `PrintersComponent.vue:35-36,141,149`, `PrinterRequest.php:58`, `KitchenTicketAutoPrinter.php:238` |
| P1 | Largeur 42 (80 mm SAGA) proposée puis refusée en silence (`Rule::in([32,48])`) | capture 05, `[22]`, `pw-log visible_field_errors: []` |
| P1 | TPE `simulation` non éditable (passerelle vide, `GATEWAY_TYPES` sans `simulation`), jamais expliqué | capture 07, `[34]` ; `PaymentTerminal.php:38`, `PaymentTerminalsComponent.vue:92-96` |
| P2 | État du système : 3 alertes permanentes (1 490 messages = file gelée, sauvegarde 21 j, planificateur muet 656 min) sans action ; Outbox orphelin (10 674 en attente, `queue:work` DOWN, `websockets:serve` DOWN) | captures 09, 10 |
| P2 | Postes de cuisine réglables article par article seulement ; deux nomenclatures (`cuisine_chaude` vs `kitchen_hot`) ; carillon en localStorage ; items par poste : chaud 37 / froid 3 / bar 8 / none 11 | capture 12 ; `KitchenDisplaySystemComponent.vue:131-137,305-309,320-327` |
| P2 | PIN admin borne « 1234 » affiché par défaut, jamais imposé | capture 08, `[16]` |
| P3 | `/api/health`, `/api/health/ready` détaillés sans authentification ; colonnes hors cadre à 1366 ; 12 bornes de stress ; `test-print` `ok:true` en bypass | `routes/api.php:144-152`, captures 01/03/06, `config/printing.php:202-207` |
**2.4 Angles morts** : installer sa borne (guide = fichier du dépôt), brancher une imprimante, TPE, postes, santé sans action, `chef@` refusé (dérive locale), carillon par navigateur.
**2.5 Cayenne** : `kiosk-lecayenne`/`kiosk123`, `kiosk-borne-b{id}@lecayenne.local` (`EnsureKioskMachineCommand.php:24,141`), afficheur « LE CAYENNE » (`config/printing.php:185`), `RECEIPT_WEBSITE/PHONE` (`:83,109`), ponts « Le Cayenne — Sanei SK1-31 », `KIOSK-LC-001`, « TPE Le Cayenne #1 / SIM-CAYENNE-1 », `admin@/pos@lecayenne.fr` (`config/app.php:123,129`).
**2.6 Nettoyage du 26/08** : bornes 84/85, imprimante 19, TPE 21 supprimés ; jetons kiosk-84/85 = 0 ; interrupteur restauré.

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-08-13 `GOAL_COMMERCANT_BACKEND_ACCES` S1.3 : allowlist **fermée** par décision propriétaire (option b host+port recommandée, jamais implémentée côté Dashboard), oracle de scan de port identifié ; S2 santé VPS vérifiée.
- 2026-08-25 `GOAL_CONSOLIDATION` : sondes honnêtes (`queue.monitored_queues`), file `notifications` orpheline documentée (⛔ worker non rebranché), pastille de santé POS (19 + 16 tests), runbook worker.
- 2026-08-07 révocation par appareil pour les **utilisateurs** (`DeviceTokenService`) — les **bornes** n'en bénéficient pas (le P1).
- Tests existants : `tests/Feature/Kiosk/` (9), `tests/Feature/Hardware/` (8+), `PosSystemHealthTest` (19), `posSystemHealthPill.spec.js` (16), `PrinterControllerTest`, `PaymentTerminalControllerTest` (cités 13/08 — vérifier `ls`).

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Bornes service | `app/Services/KioskMachineService.php` | `destroy :108`, `changeStatus :147`, `logout :176` | aucun ne révoque |
| Bornes login | `app/Http/Controllers/Auth/KioskMachineLoginController.php` | `:77-82` refus FR | |
| Auto-login | `config/kiosk.php` `:206` `spa_auto_login`, `:210` `auto_login_trusted_ips`, `:211` `auto_login_local_bypass`, `:256-259` `auto_login_secret`, `:266-283` identité `kiosk-lecayenne` · `resources/views/master.blade.php:136-174` · `app/Support/KioskAutoLoginGate.php:47-60` | | G-BORNE-ID |
| Ponts | `routes/web.php:124-141` (`/dl/{bridge}`), `tools/{borne,caisse-bridge,kitchen-bridge}/` | | |
| Imprimantes | `app/Http/Requests/Admin/PrinterRequest.php:56-62` (hôte), `:57` largeur, `:58` statut · `app/Rules/SafeRemoteHost.php:19,45-56,184-186` · `resources/js/components/admin/settings/Printers/PrintersComponent.vue:35-36,115,129-133,141,149` · `app/Services/Kitchen/KitchenTicketAutoPrinter.php:238` · `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php` · `config/printing.php:83,109,185,202-207` | | |
| TPE | `app/Models/PaymentTerminal.php:38` · `app/Http/Requests/Admin/PaymentTerminalRequest.php:31` · `settings/PaymentTerminals/PaymentTerminalsComponent.vue:92-96` · `PaymentTerminalController.php:102-107` · `config/pos.php:37` | | fiscal-adjacent |
| Cuisine | `admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:131-137,305-309,320-327` · migration `2026_04_20_230000` (enum) | lecture | voie KDS |
| Santé | `admin/observability/{SystemHealthComponent,OutboxOverviewComponent}.vue` · `observabilityRoutes.js:26-46` · `HealthController`, `HealthzController` (`routes/api.php:144-152`) · `config/queue.php` · `docs/RUNBOOK_WORKER_CAISSE.md:61` · `menus` id 33 | | |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Kiosk|Hardware|Printer|PaymentTerminal|Health"` → figer W0 · `kiosk_machines` 14 · `printers` 3 · `payment_terminals` 2 · `jobs` par file (`notifications` ~1 490-1 511) · `domain_events` non dispatchés 10 674 · `failed_jobs` 1 · items par poste chaud 37 / froid 3 / bar 8 / none 11.

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-BORNE-ID | Identité + lien d'installation par borne (A) vs auto-login `.env` (B) | **A** | borne = développeur |
| G-DATA | Colonnes `setup_secret`, `setup_expires_at` | oui | W4 bloquée |
| G-LAN | Allowlist host+port réglable depuis le Dashboard (décision fermée conservée) | oui | imprimante LAN = `.env` |
| G-TPE | « Simulation » éditable, Stripe/Senangpay masqués | oui | TPE non éditable |
| G-OUTBOX | Fusionner Outbox dans État du système | oui | page orpheline |
| G-NOTIF (avec ONB-09) | 1 490 jobs `notifications` : purge / traitement / pause nommée | pause nommée + purge des > 30 j après export | alerte rouge permanente |
| G-PURGE | Purger 12 bornes `KM-STRESS/SOAK` (local) | oui (local seulement) | bruit |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- `test-print` renvoie `ok:true` en bypass local : **prouver par les octets** (`nc -l 9100`).
- Une borne supprimée laisse aujourd'hui son jeton : après tes tests, vérifier `personal_access_tokens WHERE device_id LIKE 'kiosk-%'` et le compte `kiosk-borne-b<id>@`.
- `/kiosk/login` redirige vers `/kiosk/idle` en local (`auto_login_local_bypass`) : désactiver le bypass pour prouver le parcours d'installation.
- `chef@lecayenne.fr` refusé en local (dérive de données) : réparer en W0, ne pas en faire un défaut.
- KDS/OSS sont une autre voie : lecture, mesures, fiches — jamais d'édition.
- `:8000` = autre worktree ; ta session = **:8810**.

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | | | | | |

Fiches de renvoi : ONB-05 (réglage typé allowlist host:port, PIN obligatoire, entrées de menu Outbox/Postes, réservation d'État du système) · voie KDS+OSS (préférences KDS côté serveur, personnalisation OSS) · ONB-02 (enum `KdsStation` partagé, validation) · ONB-12 (identités `kiosk-lecayenne`, `RECEIPT_*`, ponts « Le Cayenne ») · ONB-09 (G-NOTIF) · ONB-13 (`/api/health` exposé, oracle TCP) · État final : —
