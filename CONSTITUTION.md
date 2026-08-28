<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- ║  CONSTITUTION — READ FIRST EVERY SESSION (cold-start canonical)     ║ -->
<!-- ║  No dated history here. History lives in PROJECT_BRAIN.md + memory. ║ -->
<!-- ║  Companion SSOT: SYSTEM_MAP.md · SYNC_CONTRACT.md · PARALLEL_PROTOCOL.md -->
<!-- ════════════════════════════════════════════════════════════════════ -->

# FoodKing — Constitution (V1 LOCAL « Le Cayenne »)

## 1. VISION (immuable — le seul cadre)
- **V1 = LOGICIEL PERSONNEL du restaurant « Le Cayenne »** : mono-poste, LOCAL, locale **FR**, **1 branche** (`branch_id=1`).
- **PAS un SaaS.** Cloud / multi-tenant / scale / concurrence-à-l'échelle = **FUTUR**. Ne JAMAIS les remonter comme P0/P1 ou blocker V1.
- But quotidien : ouvrir la caisse → prendre commandes (borne + comptoir) → cuisine prépare (KDS) → client voit le statut → encaisser (comptoir, TPE Plan B) → clôture Z → lire les chiffres du jour.
- Vise la **correction production-grade**, pas la vitesse seule. Partiel > faux. Bloqué > silencieusement dangereux.

## 2. STATUT MATÉRIEL (état courant — choix assumé, PAS un bug)
- Le **TPE tourne en mode SIMULÉ / ALTERNATIF** (`POS_SIMULATION_HARDWARE`) **jusqu'à** ce que le vrai terminal principal à la CAISSE soit fonctionnel. Choix temporaire **assumé** — ne JAMAIS le traiter comme un défaut à réparer.
- La simulation bypasse **le MATÉRIEL seulement** (tiroir-caisse / TPE), **JAMAIS** le pricing / la composition / le fiscal (vérifié : `PaymentService.php`, `config/pos.php:37`).
- État fichiers : `.env:93 POS_SIMULATION_HARDWARE=true` (dev) ; `.env.example:373=false` (template prod). Boot-guard prod refuse `true` en `APP_ENV=production` (`AppServiceProvider.php:190+`, garde `POS_SIMULATION_HARDWARE` à `:197`).
- Paiement kiosk = **Plan B** : routé à l'encaissement comptoir (`config/kiosk.php payment_route_all_to_counter`).

## 3. RÈGLES DURES (non-négociables)
1. **FROZEN ZONES** — modification = LOCK doc + gate owner OU triple-vert régression. Liste réelle (CLAUDE.md §7) :
   - Frontend : `resources/js/components/admin/pos/PaymentComponent.vue` · `.../pos/v5/PosV5TrancheRow.vue` · `.../frontend/kiosk/{KioskWizardComponent,KioskAppComponent,KioskUpsellComponent}.vue` · **`public/js/pos-wizard.js` + `public/css/pos-wizard.css` + `resources/views/admin-pos-v4.blade.php` (POS Vanilla JS wizard — STRICT no-touch)**.
   - Backend NF525 : `app/Services/Fiscal/{FiscalSequenceService,ZReportService,AuditLogService}.php` + triggers `audit_logs`/`z_reports`.
   - Backend tenant/payment : `app/Models/Scopes/BranchScope.php` · `app/Http/Middleware/IdempotencyKeyMiddleware.php` · `app/Services/Pricing/PricingService.php` · `app/Domain/Order/OrderStateMachine.php`.
   - Nuance kiosk : les 3 composants Vue kiosk sont §7 frozen — **« auditable » = lecture + tests autorisés, mais TOUTE édition exige LOCK + gate** (défaut = lecture stricte). Distinct du wizard POS Vanilla JS = **strict no-touch absolu** (réf. memory `feedback_kiosk_wizard_not_protected` / `feedback_wizard_popup_pos_protected`).
2. **NF525 intouchable** : 100% pricing backend (`PricingService::calculateOrder`), `composition_snapshot` figé à la création (jamais réécrit), séquence fiscale monotone gap-free, chaîne HMAC append-only, rétention 6 ans. Aucun env flag de bypass.
3. **No-cloud sans ordre explicite owner** : ne pas proposer/évoquer cloud/AWS/VPS/Phase-D tant que l'owner ne dit pas « go production cloud ».
4. **Locale FR** (ADR-007 immuable). Pas de message anglais user-facing.
5. **`simulation_hardware` = matériel uniquement** (cf. §2).
6. **Git** : jamais `git add .`/`-A` (secrets), jamais `--no-verify`, jamais push/force-push sans owner explicite.

## 4. LES 5 SYSTÈMES (modules autonomes = 1 voie d'agent chacun)
Détail des fichiers possédés (file:line) → **`SYSTEM_MAP.md`**. Voies **disjointes** : 2 agents ne touchent JAMAIS le même fichier en parallèle.
1. **BORNE (kiosk)** — commande client libre-service. Bundles `kiosk-*`. Wizard kiosk auditable.
2. **CAISSE (POS)** — terminal principal : paiement, encaissement, tiroir, fiscal. Bundle `pos-shell` + wizard POS Vanilla JS (frozen strict).
3. **KDS + OSS** — écran cuisine (bump/recall) + écran statut client. Bundles `admin-kds` / `admin-oss`.
4. **WEB + APP (client, standalone)** — site `/Users/1millnonstop/Downloads/web` + mobile `mobile/` (standalone, **NO API wireup V1**) + storefront client servi par ce backend (`resources/js/components/frontend/{home,menu,account,checkout,...}` non-kiosk).
5. **CENTRAL** — produits/catégories, dashboard, historique, réglages, utilisateurs, rapports. Bundles `admin-shell` / `admin-reports`.

## 5. ZONES PARTAGÉES (transverses — lock + gate, JAMAIS en parallèle, jamais par un agent-système seul)
Contrat détaillé → **`SYNC_CONTRACT.md`**. Protocole multi-agents → **`PARALLEL_PROTOCOL.md`**.
- **Pricing SSOT** : `app/Services/Pricing/PricingService.php` (frozen).
- **Chaîne NF525** : `app/Services/Fiscal/*` + triggers (frozen).
- **Bus de synchro temps-réel** : `app/Events/{OrderCreated,OrderStatusChanged,KdsOrderRecalled}.php` · canal privé `branch.{branchId}` (`routes/channels.php:41`) · soketi · `resources/js/services/WebSocketService.js` · queue/outbox.
- **Auth / isolation** : `BranchScope` (frozen) · Sanctum · `IdempotencyKeyMiddleware` (frozen).
- **Order core partagé** : `app/Services/{OrderService,FrontendOrderService}.php` · `OrderStateMachine` (frozen) — utilisés par plusieurs systèmes ⇒ coordination requise.

## 6. DISCIPLINE (chaque session/agent)
- Lire la chaîne canonique : **CONSTITUTION → SYSTEM_MAP → SYNC_CONTRACT (si voie touche la synchro) → PARALLEL_PROTOCOL**, puis `PROJECT_BRAIN.md §2` pour l'état courant.
- Anti-hallucination : tout chemin/canal/event/règle cité = vérifié `file:line` dans le vrai code, sinon écrire « à vérifier ».
- Evidence avant affirmation : tests verts + frozen-diff 0 + NF525 CHAIN OK + visuel analysé. Jamais feindre la certitude.
- Loop LOOP (CLAUDE.md §5) : orchestrate → plan → execute → audit → test → visual → self-correct (max 3) → update BRAIN.
