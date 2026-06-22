# FoodKing — Cloud Migration Data Dossier
**Date:** 2026-06-04 · **HEAD:** `da4463345` · **Branch:** `heal/cms-pr1-quickwins-2026-05-18`
**Author:** Claude (8 parallel read-only agents + targeted source verification)
**Purpose:** Collecter le maximum de données structurelles pour **programmer le passage sur le cloud**.

> **Owner-mandate context (do not lose this):** V1 = logiciel PERSONNEL Le Cayenne, mono-poste LOCAL,
> FR, 1 branche — *pas un SaaS*. Cloud/scale/multi-tenant = vision future. Cette demande owner ("programmer
> le passage cloud") **est** l'initiation explicite qui lève le gate `feedback_no_cloud_until_owner_initiates`.
> Ce dossier **tier** chaque finding (V1-single-VPS *now* vs V2-multi-instance/SaaS *later*) pour ne JAMAIS
> surfacer un item SaaS comme blocker V1.

---

## ⚠️ THE HEADLINE DECISION — "le cloud" = deux choses très différentes

La plupart des "blockers" ci-dessous **n'existent que sous le Track B**. Il faut choisir la cible :

| | **TRACK A — Lift & Shift** | **TRACK B — Multi-instance / SaaS** |
|---|---|---|
| **Cible** | 1 VPS cloud (OVH/Hetzner) = même topologie qu'aujourd'hui, juste pas dans le resto | N instances derrière load-balancer, multi-tenant, auto-scale |
| **Behaves like** | Exactement comme la single-box actuelle | Architecture distribuée neuve |
| **Cache=file / Session=file** | ✅ OK (un seul process host) | ❌ Casse (état non partagé entre instances) |
| **Soketi single-process** | ✅ OK | ❌ Doit clusteriser (Redis adapter) |
| **Local-disk storage** | ✅ OK | ❌ Doit passer S3 |
| **BranchScope 10-model gap** | ✅ Non pertinent (1 branche) | ❌ Hard-fail tenancy |
| **Vrai blocker set** | **~4 items** (voir §0) | **~20 items** |
| **Effort** | jours (IaC déjà écrit) | semaines-mois |
| **Statut artefacts** | Ansible/scripts DÉJÀ écrits, jamais exécutés | Plans seulement |

**Track A est déjà ~80% outillé** (deploy/ansible + scripts/deploy existent). **Track B est un projet V2.**
Recommandation par défaut : **Track A** comme premier passage cloud (le resto reste mono-branche), Track B
en backlog V2 quand plusieurs restaurants existeront.

---

## §0 — LES DEUX GATES STRUCTURELS (avant TOUT cloud, indépendants du Track)

Ces deux points priment sur toute la plomberie infra. Ils déterminent si le passage cloud est même la bonne cible.

### GATE-1 · Hardware reachability — le POS pilote du matériel physique sur le LAN local ✅ VERIFIED
- **Imprimantes = ESC/POS over TCP, port 9100, adressées par IP LAN.**
  `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:14` (`$port = ... ?? 9100`),
  `app/Services/Hardware/EscPosPrinterService.php:31,126` (`'host' => $printer->host`).
- **Cash drawer** : `app/Services/Cash/CashDrawerService.php` (kick ESC/POS via imprimante).
- **TPE / terminal de paiement** : `app/Http/Controllers/Admin/PaymentTerminalController.php` (physique au comptoir).
- `config/pos.php:6-37` confirme : *"physical POS hardware (cash drawer, TPE terminal, ticket printer) is not yet
  [wired]"* → flag `simulation_hardware` (doit être `false` en prod, boot guard `AppServiceProvider.php:165`).

**Implication cloud (la vraie première question d'architecture) :** un backend hébergé dans le cloud
**ne peut pas joindre une imprimante `192.168.x.x:9100` à l'intérieur du restaurant**. Trois réponses possibles :
1. **Hybride (recommandé)** : un *nœud local in-store* garde la liaison matériel (impression, drawer, TPE),
   le cloud porte les données/admin/sync. C'est l'architecture naturelle pour un POS cloud.
2. **Tunnel/VPN** cloud→LAN du resto (fragile, latence, sécurité).
3. **Périphériques réseau exposés** (insécure, à proscrire).

→ **Avant de programmer le passage, trancher : "cloud" signifie-t-il "données dans le cloud + nœud local
matériel" (hybride) ou "tout dans le cloud" (impossible tel quel pour le matériel) ?** Ce choix précède Redis, S3, etc.

### GATE-2 · Encaissement NO-GO — verdict autoritatif 2026-06-03 ✅ VERIFIED (file exists)
Verdict cloud-readiness le plus récent (`reports/test-e2e/sync-borne-caisse-kds-2026-06-03/cloud-readiness/ULTRAPLAN_ENCAISSEMENT_PRE_CLOUD.md`)
**supersède** les anciens "GO ABSOLUTE/CONVERGED" de mai. État réel de la capture de paiement :
- 🔴 **Carte = STUB** — `resources/js/components/admin/pos/PosCounterCollectModal.vue` (fichier confirmé) :
  pas de saisie réf TPE/montant, pas d'`OrderPayment`, pas de `CashMovement`. (~80 LOC à construire.)
- 🔴 **Ticket-Restaurant = STUB** — pas de count/value/split, 0 conformité FR (25€/j, CONECS).
- ⚠️ **Espèces = PARTIEL** — monnaie affichée mais **non persistée** ; session caisse non bloquante (trou cash-trail NF525).
- ⚠️ **Z-report** — `ZReportService.php:666` stocke des clés numériques `'1'/'2'/'5'` dans `total_by_method` signé
  (P1 lisibilité, pas corruption ; **frozen §7 → LOCK + owner gate**).

**Implication :** aller en production/cloud est gated par la *complétude de l'encaissement*, pas par Redis.
Corroboré mémoire `feedback_determined_handoff_anti_drift_2026-06-03`. Le package de build existe déjà
(`reports/handoffs/HANDOFF_GOAL_ENCAISSEMENT_MASSIVE_E2E.md`, plan P-CARD/P-TR/P-CASH/P-DB/P-ZREPORT/P-CONFIG).

---

## §1 — Stack snapshot ✅ VERIFIED

| Élément | Valeur | Source |
|---|---|---|
| Framework | **Laravel 9.52.21** | `php artisan --version` |
| PHP | `^8.1` (CI override 8.2.30) | `composer.json:10-14` |
| Build | **Laravel Mix 6** (webpack, *PAS* Vite) | `package.json` scripts |
| Frontend | **Vue 3.5.31** + Laravel Echo 2.3 + pusher-js 8.4 | `package.json` |
| DB | **MySQL 8** (sqlite seulement dev/test) | `config/database.php:18` |
| Docroot | `/public` (Laravel std), Nginx + PHP-FPM | `docs/DEPLOYMENT_GUIDE_V1.md` |
| Migrations | **176 fichiers**, ~88-95 tables | `database/migrations/` |
| Ports | 8000 (serve), 6001 (soketi), 3306, 6379 — tous env-driven | `soketi.json`, `.env.example` |

**Deps cloud-relevantes déjà présentes** (`composer.json`) : `predis/predis ^3.4`, `pusher/pusher-php-server ^7.2`,
`league/flysystem-aws-s3-v3 ^3.29`, `aws/aws-sdk-php-laravel ^3.9`, `laravel/sanctum ^3.0`,
`spatie/laravel-permission ^5.6`, `spatie/laravel-medialibrary ^10.5`, `barryvdh/laravel-dompdf ^3.0`,
`stripe/stripe-php ^10.11`. → **Le code est déjà câblé pour Redis/Pusher/S3**, il suffit de configurer.
**Ext PHP requises :** pdo_mysql, bcmath, mbstring, openssl, gd/imagick, zip, exif, json, curl, tokenizer, xml.

---

## §2 — MATRICE DES BLOCKERS, TIERÉE Track A vs Track B

Légende : 🅰️ = bloque/concerne Track A (single-VPS) · 🅱️ = seulement Track B (multi-instance/SaaS) · ✅ déjà géré

| # | Finding | Track | Sévérité | État | Source (verified⁺) |
|---|---|---|---|---|---|
| B1 | **Hardware non joignable depuis le cloud** (printers TCP:9100 LAN) | 🅰️🅱️ | **STRUCTUREL** | OPEN — décision archi | ⁺`TcpPrinterTransport.php:14` |
| B2 | **Encaissement carte/TR = stubs, espèces non persistées** | 🅰️🅱️ | **STRUCTUREL** | OPEN — build + Z LOCK | ⁺`PosCounterCollectModal.vue` |
| B3 | `AuditLogService.php:273` runtime `env()` casse sous `config:cache` (chaîne HMAC) | 🅰️🅱️ | **P0** | OPEN (frozen → LOCK) | ⁺`AuditLogService.php:273` |
| B4 | NF525 fiscal-secrets ≥32 chars + 5 boot guards | 🅰️🅱️ | P0 (à fournir) | ✅ guards en place | ⁺`AppServiceProvider.php:165-300` |
| B5 | **Single primary DB writer** : fiscal writes jamais sur replica (`lockForUpdate`+UNIQUE) | 🅰️🅱️ | P0 archi | contrainte à respecter | ⁺`FiscalSequenceService.php:100` |
| B6 | 6-ans rétention → stockage immuable WORM (S3 Object-Lock) | 🅰️🅱️ | P1 légal | local OK court-terme | `config/fiscal.php` |
| B7 | EU/FR region hosting (GDPR + souveraineté + accès DGFiP) — **PAS** NF525 per-se | 🅰️🅱️ | P1 procurement | choix fournisseur | (légal, non code) |
| B8 | TRUNCATE bypass triggers → REVOKE (CVP0-1) | 🅰️🅱️ | P1 | ✅ écrit `site.yml:59-71` | ⁺`deploy/ansible/site.yml:65-71` |
| B9 | Scheduler Laravel dormant (backup/auto-repair off) | 🅰️🅱️ | P1 ops | OPEN (PR-01) | `plans/core-bulletproof/PR-01` |
| C1 | `CACHE_DRIVER=file` → locks non-atomiques entre instances ; **guard ne bloque que array/null** (UNI-03) | 🅱️ | P0 (B only) | OPEN ≤5 LOC | ⁺`AppServiceProvider.php:295` + `.env.example:150` |
| C2 | `SESSION_DRIVER=file` → perte session entre instances | 🅱️ | P0 (B only) | conf à changer | ⁺`.env.example:211` |
| C3 | Media Library **session-affinity=true** → upload/convert cross-instance casse | 🅱️ | P0 (B only) | conf à désactiver | `config/media-library.php:51` |
| C4 | Filesystem `local` (images/reçus/archives fiscales) | 🅱️ | P0 (B only) | passer S3 | `config/filesystems.php:16`, `config/fiscal.php:158` |
| C5 | Soketi single-process (`appManager.driver=array`) → pas de fan-out multi-pod | 🅱️ | P0 (B only) | Redis adapter | `soketi.json:5` |
| C6 | BranchScope : 10 modèles "V2 SaaS hard-fail" sans scope | 🅱️ | P0 (B only) | backlog V1.0.2 | `BranchScopeCoverageSentinelTest.php:56-65` |
| C7 | Pas de package tenancy ; rôles Spatie globaux ; tokens sans claim branch | 🅱️ | P1 (B only) | re-archi V2 | (analyse) |
| C8 | Crons `onOneServer()` → besoin leader unique (pas schedule:run par instance) | 🅱️ | P1 (B only) | 1 scheduler pod | `app/Console/Kernel.php` |
| C9 | OSS wall poll-only (60s/2s), pas de cache CDN → charge API à l'échelle | 🅱️ | P2 (B only) | optim V2 | `OssSyncService.js:9-16` |

**Track A — vrai blocker set (≈4) :** B1 (décision hybride matériel) · B2 (encaissement) · B3 (env() frozen) · B5/B6/B7 (respecter single-writer + EU region + plan rétention). Tout le reste (C*) est Track B.

---

## §3 — Database & NF525 (le cœur dur) ✅ VERIFIED on key claims

- **Triggers d'immuabilité** (MySQL `SIGNAL SQLSTATE '45000'`) sur : `audit_logs` (BEFORE UPDATE+DELETE),
  `z_reports` (BEFORE DELETE), `cash_movements`, `cash_drawer_sessions`, `order_payments`, `stock_movements`,
  `composition_snapshot` (order_items), delivery-cash. → **Compatibles RDS MySQL 8** (syntaxe standard, pas de
  `log_bin_trust_function_creators` requis pour triggers). FK passées en `restrictOnDelete()`.
- **TRUNCATE contourne les triggers** → mitigé par **REVOKE DROP/ALTER** sur 7 tables fiscales
  (`deploy/ansible/site.yml:65-71`, CVP0-1). ⚠️ **Note : la mémoire disait "REVOKE TRUNCATE, hors testttt,
  commit f840c3ef5" — FAUX/stale. C'est REVOKE DROP,ALTER, in-repo.** (À corriger en mémoire.)
- **Chaîne HMAC** : `AuditLogService::computeHash` = `hash_hmac('sha256', prevHash.'|'.canonical(action,payload),
  secretFor(branch))` (`:237-243`) ; `UNIQUE(branch_id, prev_hash)` + 1 retry sur collision ; `verifyChain()`
  re-walk renvoie 1ʳᵉ ligne altérée. Z chain : `ZReportService` chaîne `prev_hash` = signature Z précédente,
  `closed_at` canonicalisé **UTC ISO-8601** (TZ-stable).
- **Cache::lock** (triple défense séquence fiscale) : `fiscal_seq_b{branch}` 5s + `lockForUpdate()` +
  `UNIQUE`. **Suppose 1 cache partagé + 1 writer DB.** Multi-instance sans Redis partagé = collision/fork de chaîne.
- **Backups** : `php artisan foodking:backup-daily` (Kernel 03:00) → mysqldump gz, rétention **30j daily + 12mo
  monthly + 24 quarters = 6 ans** ; `backup:verify-restore` 05:00 (restore scratch + vérif chaîne).
  **⚠️ Ce sont des dumps fichier locaux — pas des snapshots RDS** : sur cloud, prévoir un bucket S3 pour `storage/backups/`.
- **Retention 6 ans** = `config/fiscal.php archive_retention_years=6` (config) ; l'**immuabilité durable** (WORM)
  est une exigence **loi→archi**, pas encore couverte (archive_disk=`local`).
- **Data residency** : **NF525 n'impose AUCUNE clause de localisation** (Art. 286-I-3°bis CGI = inaltérabilité /
  sécurisation / conservation / dispo DGFiP 6 ans — *pas* localisation). FR/EU est requis par **RGPD + souveraineté
  + accès DGFiP**, à justifier ainsi (pas "NF525-mandaté"). Certification NF525 attache au **logiciel/éditeur**
  (LNE ou Infocert, ou auto-attestation), **pas à l'hébergeur** (qui lui relève d'ISO 27001 / SecNumCloud / HDS).
  Export DGFiP (**JET XML**, Pilier P4) = **DEFERRED, spec TBD** (`docs/gates/GATE_P_MEGA_22_NF525_READINESS...`).

---

## §4 — Realtime sync (Borne→Caisse→KDS)

- **Transport** : pattern **outbox** (PAS `ShouldBroadcast` direct) — événements persistés en table `domain_events`
  (`dispatched_at=NULL`), `DispatchDomainEventsJob` (queue `high`, tries=6, backoff [1,5,15,60,300]) push vers
  **Soketi** (`:6001`, Pusher-protocol) ; client **Laravel Echo** (`resources/js/bootstrap.js:293+`,
  `WebSocketService.js`). Canaux `private-branch.{branchId}` (`routes/channels.php:41`).
- **Events** : `OrderCreated`, `OrderStatusChanged`, `KdsOrderRecalled` + `OutboxBroadcastSwallowedEvent` (obs).
- **Polling fallback** (par surface, intentionnel) : KDS 60s↑/5s↓ ; OSS 60s↑/2s↓ (poll-only, pas d'Echo) ;
  Kiosk 15s fixe ; queue `block_for=5` (F-LAT-01 : cold paint 2292→269 ms).
- **Cloud (Track B)** : Soketi doit passer `appManager.driver=redis` pour fan-out multi-pod ; OU Pusher SaaS /
  Ably. Redis pub/sub partagé pour les workers. Lock DB (RDS) OK pour le claim atomique de l'outbox.
- **Gap obs** : staleness outbox détectée mais `Log::error` seulement — pas d'alerte pager (à câbler Datadog/Sentry).

---

## §5 — Multi-tenancy (Track B uniquement) — readiness ~60-65% structurel

- **Mécanisme** : scoping par colonne `branch_id` via `BranchScope` global (PAS de package tenancy ;
  ni `stancl/tenancy` ni `spatie/multitenancy` dans composer). Admin bypass `branch_id=0`.
- **29 tables clés portent `branch_id`** (données déjà partitionnées). **21 modèles déclarent BranchScope**
  (sentinel baseline `BranchScopeCoverageSentinelTest.php`).
- **Exemptions permanentes** : `Branch` (self-ref circulaire), `Customer` (récursion token Sanctum).
- **10 modèles "V2 SaaS hard-fail"** sans scope (low-risk V1 mono-branche, blocker V2) : `FrontendDiningTable`,
  `ZReport`, `AuditLog`, `OrderDiscountLog`, `Message`, `DiningTableAuditLog`, `KioskPromo`, `UpsellRule`,
  `ActionLog`, `DomainEvent` (`...SentinelTest.php:56-65`).
- **Gaps opérationnels V2** : rôles/permissions Spatie globaux (pas de scope branche) ; tokens Sanctum sans
  claim `branch_id` (dépendent de `user.branch_id` au runtime) ; pas d'isolation DB/schéma ; jobs/crons sans
  propagation de contexte branche.
- **Verdict** : structure prête à ~60-65%, opérationnalisation ~45-50%. Chantier V2, **jamais un blocker V1**.

---

## §6 — Config / Secrets / Ops

- **Secrets à mettre en gestionnaire managé (KMS/Secrets Manager)** : `APP_KEY`, `FISCAL_AUDIT_SECRET`,
  `FISCAL_Z_REPORT_SECRET` (+ overrides `FISCAL_AUDIT_SECRET_BRANCH_{id}`), `LOYALTY_QR_SECRET`,
  `STRIPE_WEBHOOK_SECRET` (vide = risque event forgé), `MIX_API_KEY`, DB/Redis/Mail creds. **Rotation des clés
  fiscales NE DOIT PAS re-signer l'historique** → conserver chaque version ≥6 ans pour re-walk offline DGFiP
  (`docs/FISCAL_SECRETS.md`).
- **Crons (`app/Console/Kernel.php`)** — tous `onOneServer()` (⇒ leader unique en cluster) : per-minute
  (outbox rescue/monitor, fiscal retry-alloc) ; 5-min (cleanup kiosk, healthz, stripe drain, SLO) ; hourly
  (outbox/webhook retry-failed) ; daily Paris-TZ (00:01 Z-open safety, 02:00 fiscal:archive, 03:00 backup,
  03:30 verify-chain, 04:00/04:15 prune, 05:00 verify-restore, 23:59 Z-close). **TZ Europe/Paris critique.**
- **Jobs** : `DispatchDomainEventsJob` (high), `ProcessWebhookEventJob`, `SendFcmNotificationJob` + cleanups.
  → **worker requis** : `php artisan queue:work --queue=high,default --tries=6 --backoff=1,5,15,60,300` (supervisor/systemd).
- **Storage writes** : images Media Library, QR tables, **archives fiscales `storage/fiscal/{branch}/{period}.zip`**,
  backups `storage/backups/`, logs. (Pas de reçu PDF runtime trouvé — Z = JSON signé + chaîne HMAC.)
- **Logging** : 8 canaux daily (stack 14j, security 90j, **fiscal 400j**, observability JSON 90j). Cloud → centraliser
  (CloudWatch/Loki) + fiscal vers système immuable long-terme.
- **Payments** : **Stripe = RÉEL** (webhook `/payment/stripe-webhook/`, idempotent UNIQUE(provider,webhook_id),
  `STRIPE_WEBHOOK_SECRET` env) ; **SenangPay = RÉEL** (secret en DB `gateway_options`, HMAC-SHA256) ;
  **SumUp / Viva / Carte-TPE comptoir = STUBS** (cf. GATE-2).

---

## §7 — Inventaire cloud-prep EXISTANT (déjà sur disque)

> **Important : tout est "DONE-as-code" (écrit + gardé par sentinels) mais JAMAIS exécuté sur un host réel.**
> Le repo tourne toujours en LOCAL single-box.

**✅ Écrit & gardé par CI :**
- **`deploy/ansible/`** (Track OVH) — `site.yml` playbook idempotent (PHP 8.2, MySQL8, Redis7, Nginx+Certbot,
  Soketi/supervisor), **CVP0-1 REVOKE 7 tables** (`:59-71`), snapshot pré-migrate, cron, logrotate, vault.example,
  group_vars (sizing VPS-1 €8.11/mo).
- **`scripts/deploy/`** (Track Hetzner) — `server-setup.sh`, `deploy.sh`, `deploy-hetzner.sh` (dry-run par défaut),
  `pre-flight.sh` (12 checks), runbooks (README_DEPLOY 35KB, GO_LIVE_CHECKLIST, CRONTAB_PROD, UPTIMEROBOT).
- **`docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt`** — ~75 clés prod + 5 boot guards documentés.
- **`tests/Feature/Deploy/`** — 3 sentinels CI (backup-before-migrate, template hardening, server-setup hardening).
- Fixes shippés lors des cycles cloud-prep : POS_SIMULATION_HARDWARE guard, Stripe cents, POS offline URL, Stripe webhook idempotency.

**📋 Planifié (écrit, non exécuté) :** build encaissement (P-CARD/TR/CASH/DB/ZREPORT), core-bulletproof PR-01..07,
cutover infra (RDS TRUNCATE-revoke, WAF/2FA, secrets→KMS, switch S3, DNS/TLS, provisioning).

**⛔ Open-blocker :** GATE-2 encaissement ; B3 env() frozen (PR-07) ; UNI-03 (C1) ; PR-01 scheduler dormant ;
sentinel allergènes CI-red (HEAD commit "all healed except allergens").

### 🚩 DEPLOY-TRACK DRIFT (incohérence ouverte, à trancher AVANT réutilisation)
Deux toolchains concurrentes, cibles différentes :
| Track | Host | Dir | PHP |
|---|---|---|---|
| Ansible | OVH VPS-1 | `/var/www/foodking` | **8.2** |
| deploy.sh | Hetzner | `/var/www/lecayenne` | **8.4** |
| deploy-hetzner.sh | Hetzner | `/var/www/foodking` | **8.2** |
→ README_DEPLOY (le plus récent) s'engage sur **Hetzner CX22**. **Pas de chemin canonique unique.** À unifier.

---

## §8 — Ledger de vérification (anti-hallucination, CLAUDE.md §3ter)

| Claim | Statut | Preuve |
|---|---|---|
| Printers ESC/POS TCP:9100 par IP LAN | ✅ **VERIFIED** | `TcpPrinterTransport.php:14`, `EscPosPrinterService.php:31` |
| `AuditLogService.php:273` runtime `env()` | ✅ **VERIFIED** | grep direct, seul env() des services Fiscal |
| UNI-03 guard forbid array/null only @ **L295** (pas L215) | ✅ **VERIFIED** | `AppServiceProvider.php:295` ; CLAUDE.md "215" = **stale** |
| `PosCounterCollectModal.vue` existe (carte stub) | ✅ **VERIFIED** | fichier présent |
| CVP0-1 REVOKE **in-repo**, 7 tables, DROP/ALTER (pas TRUNCATE) | ✅ **VERIFIED** | `deploy/ansible/site.yml:65-71` ; mémoire "hors testttt/f840c3ef5" = **stale** |
| `.env.example` CACHE/SESSION=file, QUEUE=redis | ✅ **VERIFIED** | `.env.example:150,172,211` |
| Coût €80-120/mo, "Scaleway", region us-east-1 | ❌ **AGENT-ASSERTED, écarté** | éditorialisation Agent 1 ; us-east-1 contredit OVH-GRA/EU |
| RDS triggers compat, retention tiers, BranchScope counts | 🟡 agent-asserted (cohérent multi-agents) | non re-greppé ligne par ligne |
| NF525 = pas de clause localisation | ✅ corrigé par advisor (légal) | Art. 286 CGI |

---

## §9 — PROGRAMME DE PASSAGE recommandé (séquencé, options ouvertes — pas de pick fournisseur)

**Phase 0 — Décisions owner (gates, avant tout code) :**
1. **Track A (single-VPS) ou Track B (multi-instance/SaaS) ?** → défaut recommandé **A**.
2. **Modèle matériel** (GATE-1) : hybride "données cloud + nœud local impression/TPE/drawer" — confirmer.
3. **Fournisseur/region EU** (OVH-Strasbourg/Hetzner/Scaleway… — choix business, justifié RGPD+souveraineté).
4. **Unifier le deploy-track drift** (OVH-Ansible vs Hetzner-scripts).

**Phase 1 — Débloquer les gates structurels (indépendant du Track) :**
- GATE-2 : finir l'encaissement (carte manuelle SumUp ~80 LOC, TR, persistance espèces) + Z LOCK owner-gate.
- B3 : sortir `env()` de `AuditLogService.php:273` vers `config()` (frozen → LOCK + gate) pour survivre `config:cache`.
- B5/B6 : verrouiller "fiscal writes = single primary writer" ; planifier WORM 6 ans (S3 Object-Lock) pour archives.
- PR-01 : activer le scheduler (sinon backup/auto-repair dormants).

**Phase 2a — Track A cutover (si choisi) :** réutiliser Ansible/scripts (déjà écrits) → 1 VPS EU, Redis+Soketi
local au VPS, storage local OK, CVP0-1 REVOKE, secrets→vault, dry-run pre-flight, go-live runbook. Nœud local matériel.

**Phase 2b — Track B (V2, plus tard) :** fermer C1-C9 (cache/session→redis + widen guard UNI-03, S3 storage+archives,
Soketi-redis-cluster, BranchScope 10 modèles, tenancy package, leader scheduler, S3 immutable). Re-archi multi-tenant.

**Phase 3 — Ops cloud :** logs centralisés, monitoring (UptimeRobot OPS-GATE-1 déjà documenté), alerting outbox,
verify-restore drill, rotation secrets fiscaux (conserver versions ≥6 ans).

---

## Annexe — Fichiers-clés
`app/Services/Fiscal/{FiscalSequenceService,AuditLogService,ZReportService,FiscalSealingService,FiscalChainValidator}.php`
· `app/Services/Hardware/{EscPosPrinterService,PrinterTransport/*}.php` · `app/Providers/AppServiceProvider.php:158-300`
· `config/{fiscal,pos,cache,queue,broadcasting,filesystems,media-library,logging}.php` · `app/Console/Kernel.php`
· `deploy/ansible/site.yml` · `scripts/deploy/*` · `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt`
· `tests/Feature/{Deploy,Branch/BranchScopeCoverageSentinelTest}.php` · `SYNC_CONTRACT.md` · `CLAUDE.md §8`
· `reports/test-e2e/sync-borne-caisse-kds-2026-06-03/cloud-readiness/ULTRAPLAN_ENCAISSEMENT_PRE_CLOUD.md`
