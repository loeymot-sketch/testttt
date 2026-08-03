# VERDICT SUPERVISEUR — Audit des plans CŒUR Bulletproof (PR-01..07) · 2026-06-04

> Auditeur : superviseur FoodKing (session distincte). **Read-only — aucun code touché, aucun plan exécuté, aucun daemon/`config:cache` lancé.** Toute affirmation = vérifiée `file:line`/DB ; sinon « à vérifier ».
> Entrées : `plans/GOAL_V1_CORE_BULLETPROOF_2026-06-04.md` + `plans/core-bulletproof/PR-01..07` + `HANDOFF_SUPERVISOR_AUDIT_2026-06-04.md`. Doctrine : CONSTITUTION.md, CLAUDE.md §§7-8-10, SYSTEM_MAP/SYNC_CONTRACT.
> Contrainte limite : disque box à 98% → pas de flotte d'agents adversariaux ; vérif faite par grep/Read directs (= le cœur anti-hallucination demandé). Honnête sur ce point.

## 0. Qualité d'ensemble (avant les verdicts)
Les 7 plans sont **de très haute qualité** : core-first, **additifs**, **hors frozen-zone**, NF525 respecté, cloud correctement différé, analyses adversariales réelles avec `file:line`. La **matrice §3 (C-01..C-11)** du GOAL est exactement la « liste de toutes les circonstances de panne du cœur » demandée par l'owner, avec l'invariant **0 perte** + chemin de récupération + test par ligne. Rien ici ne resurface le cloud/SaaS comme blocker V1. **Ce n'est pas un rubber-stamp** : je confirme la solidité par preuve, et j'ajoute UN angle que la session-planificatrice ne pouvait pas voir (§ croisement ci-dessous).

## ⚠️ 1. CROISEMENT CRITIQUE — les 2 chantiers parallèles entrent en collision (mon angle unique)
La session-planificatrice a écrit ces PR contre le code du working-tree, **sans savoir** que le working-tree contient **mon Wave-1 NON COMMITÉ** (campagne « plancher go-live », même thème). Ce sont **deux moitiés du même effort** qui se chevauchent — à **réconcilier avant exécution**, jamais double-exécuter :

| PR | Mon Wave-1 (sur disque, non commité) | Conséquence pour l'audit |
|---|---|---|
| **PR-01 N1 (81 ordres auto-rejetés)** | TRAP-2 a **réécrit `app/Jobs/CleanupStalePendingKioskOrders.php`** (gate `status IN PENDING,ACCEPT` + `payment PENDING_COUNTER,UNPAID` + no-fiscal-seq + kiosk, TTL 15→180 min, action `PENDING→REJECTED`/`ACCEPT→CANCELED`) | **L'analyse « 81 » est PÉRIMÉE.** Compte réel que le cleanup frapperait au démarrage du scheduler avec MON gate = **1252 ordres kiosk PENDING_COUNTER** (DB-vérifié). **Amplification ×15 du plus gros risque de PR-01.** |
| **PR-01/PR-03 (daemons)** | OPS-3 a déjà créé `deploy/local/` (plists launchd serve/queue/queue-high/soketi + `dev-stack.sh`) | **Deux approches de gestion daemon** pour la même box. `scripts/foodking-up.sh` (PR-01) n'existe pas encore. → réconcilier : ne pas livrer 2 superviseurs. launchd est plus natif Mac (PR-03 note lui-même que supervisord ≠ Mac). |
| **PR-04 (widget outbox)** | OPS-2 a modifié `HealthController.full()`→**503 si dégradé** + sondes honnêtes | **Complémentaire, pas conflit** : PR-04 évite (à juste titre) de poller `/ready` (piège 503). Mon OPS-2 = surface pager/CLI ; PR-04 = surface dashboard. 2 surfaces du même but. |
| **GOAL (scheduler dormant)** | OPS-1 a créé `backup:verify-restore` (drill restore) ; OPS-2 `storage:cleanup` ; j'ai **ajouté 2 lanes à `Kernel.php`** (backup-verify 05:00, storage-cleanup 04:10) | Additif, sans conflit avec PR-01 (qui lit Kernel.php). Mais **`Kernel.php` est désormais modifié** dans le working-tree (par moi) — PR-01 le déclarait « NE PAS modifier » ; ma modif est antérieure et additive. |

**Résolution structurante (la plus importante du verdict)** : le **`migrate:fresh --seed` du cutover go-live** (runbook `docs/GO_LIVE_RUNBOOK_LECAYENNE.md`, mon OPS-3) **efface les 1252 + 107 ordres pollués**. Donc **si la DB est wipée AVANT le premier `schedule:work`, il n'y a 0 ordre à rejeter** — quelle que soit la version du job. ⇒ L'ordre d'exécution doit **lier PR-01 au nettoyage DB**, pas seulement à un « triage des 81 ».

---

## 2. VERDICT PAR PR

### PR-01 — Supervision daemons + scheduler → **AUTORISÉ-AVEC-AJUSTEMENTS**
- (a) **Sert le cœur** ✅ : les chemins de récupération `outbox:rescue` (C-01) + `fiscal:retry-alloc` (C-09) sont **dormants** sans scheduler — vérifié, ce sont les invariants « 0 perte » de la matrice §3.
- (b) **Additif/hors-frozen** ✅ : un nouveau script, 0 fichier existant modifié, 0 frozen.
- (c) **NF525 intact** ✅ (script seul).
- (d) **Adversarial réel** ✅ EXCELLENT (N1/N2/N3/N4). **MAIS effet manquant à AJOUTER** : `app/Jobs/CleanupStalePendingKioskOrders.php` a été **réécrit dans le working-tree** (mon Wave-1) ; le compte N1 n'est plus 81 mais **1252 kiosk PENDING_COUNTER** (`Order::where('source_surface','kiosk')->where('payment_status',15)->count()=1252`, DB-vérifié) + une **tempête d'events `OrderCanceled`**. AFF#2 confirmée : `DispatchDomainEventsJob.php:46 $this->onQueue('high')` → un `queue:work` simple est inerte.
- (e) **Rollback crédible** ✅ (ne pas lancer / Ctrl-C, 0 fichier source).
- **AJUSTEMENTS OBLIGATOIRES avant exécution :**
  1. 🔴 **DB propre AVANT le 1er `schedule:work`** : exécuter `migrate:fresh --seed` (cutover, efface les 1252) **OU** triage explicite — sinon le cleanup annule ~1252 ordres + storm de notifs. Ce gate (**G-TRIAGE**) est **élevé en priorité**.
  2. **Réconcilier `foodking-up.sh` avec mon `deploy/local/` launchd déjà sur disque** (ne pas livrer 2 gestionnaires de daemon). Recommandation : garder launchd + y intégrer le `--check`/healthcheck de PR-01 + le knob `PHP_CLI_SERVER_WORKERS` de PR-03.
  3. **Queue obligatoire `--queue=high,default`** (N3 confirmé) ; garde idempotence spécifique à la lane `high`, pas un substring.
  4. **Confirmer mail/SMS/push = no-op en local** avant le 1er scheduler (sinon vrais rejets/notifs clients).

### PR-02 — Dégradation sync VISIBLE → **AUTORISÉ**
- (a) Sert le cœur ✅ **P0** (« silencieux=grave » owner). (b) Additif/non-frozen ✅ (KDS auditable + flag opt-out). (c) NF525 ✅. (d) Adversarial ✅ **EXCELLENT** — le design **opt-out** (`KDS_SHOW_FALLBACK_BANNER` défaut true, masquer seulement si `local AND flag=false`) évite le piège « flip default-true ramène le bruit dev ». AFF#3 CONFIRMÉE sur les 2 surfaces : `PosOrdersTrackerComponent.vue` (`isDevEnv`) **et** `ConnectionStatusBanner.vue:73,89` (`return env==='local'||'testing'` ; `if(this.isDevEnv) return false`). (e) Rollback ✅ (flag=false / revert 1 computed).
- **Notes (pas de blocage)** : (1) `ConnectionStatusBanner.vue` est un **widget PARTAGÉ** (SYSTEM_MAP §6, importé multi-voies) → coordination, pas une édition de voie libre. (2) Trancher §6.3 (flag partagé POS/OSS vs KDS-only + suivi nommé) — ne pas laisser « KDS réglé » se lire « dégradation réglée ». (3) Ajouter le spec V2 manquant (sinon fix non couvert CI).

### PR-03 — Sûreté crash serveur mono-process → **AUTORISÉ**
- (a) Sert le cœur ✅ (crash=arrêt service). (b) Additif/non-frozen ✅ (`PHP_CLI_SERVER_WORKERS`, template supervisor ; Fiscal en **lecture seule**). (c) NF525 ✅ — la **preuve no-gap** (kill mid-tx → rollback InnoDB atomique, alloc `MAX+1` sous `lockForUpdate`+`DB::transaction`) est **solide et juste**. (d) Adversarial ✅ (N1 restart-loop masquant = le vrai danger ; N2 pas-prod-grade). (e) Rollback ✅.
- **AJUSTEMENT** : réconcilier `scripts/deploy/supervisor.conf.template` + le knob avec mon `deploy/local/` launchd (même point que PR-01). php-fpm correctement **différé cloud**.

### PR-04 — Alerte outbox VISIBLE → **AUTORISÉ**
- (a) Sert le cœur ✅ (pipeline dégradé non remonté). (b) Additif/non-frozen ✅ (nouveau read authed + widget ; sonde existante réutilisée). (c) NF525 ✅. (d) Adversarial ✅ **EXCELLENT** — le **piège 503** (N1 : un widget qui `.catch` le 503 de `/ready` recrée le silence) est une prise fine et juste ; design correct = read authed **toujours-200**. (e) Rollback ✅.
- **Note** : complémentaire à mon OPS-2 (qui a rendu `HealthController.full()`→503) — PR-04 évite justement ces endpoints 503. **0 conflit.** Debounce 2-3 polls (N4) retenu.

### PR-05 — `/menu` 404 → **AUTORISÉ (option A = laisser)**
- (a) Cœur : **NON** (cosmétique P3, vitrine éteinte voulue) — correctement classé **secondaire**. (b)(c) Aucun changement (option A). (d) Adversarial ✅ **EXCELLENT** — AFF#5 CONFIRMÉE : `public/menu/le-cayenne-v2/` **existe** mais `config/menu_images.php:30 base_path='images/menu'` lit `public/images/menu/` → le dossier v2 est un **doublon orphelin** non load-bearing. (e) Rollback n/a.
- **Gate G2** (owner) : A (laisser, recommandé) / B (renommer doublon) / C (redirect prod 301). Ne JAMAIS toucher `public/images/menu/` (load-bearing).

### PR-06 — Backlog différé → **AUTORISÉ (doc only, non exécuté)**
- (a) Hors cœur (secondaire) ✅ correctement différé. (b)(c) Rien exécuté. (d) Inventaire vérifié : **COUPON-CAP-01 = DÉJÀ SHIPPÉ** (confirmé par mon cycle mgmt-testplan : `CouponMaxUsesGlobalEnforcementTest`) ; **brute-force lockout LIVE** (`throttle:login-lockout`) ; **FormRequest sentinel** bloque déjà la croissance ; **ZRPT countersign** = gate **G3** (gouvernance, frozen `ZReportService` → LOCK). (e) n/a.
- **Aligné** : rien ici ne touche le cœur ; tout est owner-gated/post-validation.

### PR-07 — `env()`→`config()` (config:cache) → **AUTORISÉ (scope strict) ; sweep DIFFÉRÉ cloud**
- (a) Cœur : **NON** (cloud-prep, nul en local non-caché) — correctement P2. (b) Additif/non-frozen pour le **fix strict** (`master.blade.php:184 kioskUsePosWizard env→config` + `config/kiosk.php` défaut **true** + suppression `.bak.w1b`/`.DS_Store`). (c) NF525 ✅. (d) Adversarial ✅ **EXCELLENT** + **TEST DE DISCIPLINE FROZEN RÉUSSI** : AFF#4 CONFIRMÉE — `app/Services/Fiscal/AuditLogService.php:273 env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` est **frozen NF525**, et PR-07 le **classe explicitement HORS-PR (LOCK+gate)** au lieu de le « corriger » → **discipline frozen respectée à la lettre**. N4 (« jamais `config:cache` sur la box live ») = juste et critique. (e) Rollback ✅.
- **Notes** : le **sweep des 35 `env()`** = backlog cloud-prep (W5), pas ce PR (correct). Le fix strict modifie `master.blade.php` (fichier de la session staff-only) → cohérent avec son pattern. **Ne JAMAIS `config:cache` en live.** Étant cloud-prep, PR-07 n'est pas urgent V1 — peut rester en W5.

---

## 3. ANTI-HALLUCINATION — 5 affirmations reconfirmées
| # | Affirmation | Verdict | Preuve |
|---|---|---|---|
| 1 | PR-01 : 81 ordres kiosk PENDING auto-rejetés | **DIVERGENCE — corrigé** | Job réécrit par mon Wave-1 ; compte réel = **1252** kiosk PENDING_COUNTER (DB) ; old-gate=107. `Kernel.php:105`, `CleanupStalePendingKioskOrders.php:53-66` |
| 2 | PR-01 : `DispatchDomainEventsJob:46 onQueue('high')` | ✅ CONFIRMÉ | `app/Jobs/DispatchDomainEventsJob.php:46 $this->onQueue('high')` |
| 3 | PR-02 : masquage aussi PosOrdersTracker + ConnectionStatusBanner | ✅ CONFIRMÉ | `PosOrdersTrackerComponent.vue` `isDevEnv` ; `ConnectionStatusBanner.vue:73,89` |
| 4 | PR-07 : `AuditLogService:273` env frozen NF525 | ✅ CONFIRMÉ + discipline respectée | `app/Services/Fiscal/AuditLogService.php:273` ; classé hors-PR LOCK+gate |
| 5 | PR-05 : `public/menu/le-cayenne-v2/` = doublon | ✅ CONFIRMÉ | dir existe ; `config/menu_images.php:30 base_path='images/menu'` (≠ menu/v2) |

## 4. ORDRE D'EXÉCUTION — validé avec UNE correction
Ordre conseillé README : `PR-02 → PR-04 → PR-01(post-triage) → PR-03 → PR-05/06/07`. **Je le valide, avec l'insertion d'un nettoyage DB AVANT PR-01** :

**G0 commit-checkpoint** (réconcilier 2 sessions : mon Wave-1 + le staff-only — voir §5) → **PR-02** (P0 visibilité, sûr, additif) → **PR-04** (alerte, additif) → **[G-TRIAGE : `migrate:fresh --seed` OU triage des 1252]** → **PR-01** (daemons, après DB-propre + réconciliation launchd/OPS-3) → **PR-03** (knob serve, plié dans launchd) → **PR-05/06/07** selon décisions/cloud.
**Règle parallélisme du GOAL respectée** : jamais 2 implementers en // ; le cœur partage `OrderService`/sync.

## 5. GATES OWNER (WHO / WHAT / WHERE)
| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| **G0** | Commit-checkpoint — **réconcilier les 2 working-trees non commités** : mon Wave-1 (7 fixes : backup-drill, storage:cleanup, health-503, HIST-04, kiosk-cleanup, drawer-trail, OSS-5s + Kernel lanes) **ET** le staff-only de l'autre session. Décider ce qui se commit, dans quel ordre, sans s'emmêler. | Owner + superviseur | « go commit » + arbitrage | ce chat | **PENDING — élevé** |
| **G-TRIAGE** | 🔴 **DB propre avant le 1er scheduler** : `migrate:fresh --seed` (efface 1252+107) OU triage explicite. **Sans ça, PR-01 annule ~1252 ordres.** | Owner | choix wipe vs triage | ce chat | **PENDING — CRITIQUE** |
| **G1** | Approche daemons — **réconcilier `foodking-up.sh` (PR-01) avec mon `deploy/local/` launchd**. | Owner | « go W1 » + choix launchd | ce chat | PENDING |
| **G2** | `/menu` : A laisser / B renommer / C redirect prod | Owner | choix A/B/C | ce chat | PENDING |
| **G3** | Countersign LOCK refund-Z (PR-06) — frozen `ZReportService` | Owner physique | signature `plans/LOCK_ZREPORT_*` | LOCK doc | PENDING (hors V1) |
| **G-CLOUD** | Jalon cloud (PR-07 sweep, php-fpm, secrets) | Owner | « go cloud » | ce chat | FUTUR (après validation locale) |

## 6. ⭐ VERDICT GLOBAL : **GO exécution** (conditionnel, ordre + gates ci-dessus)
Les 7 plans **protègent le cœur** et **ne le mettent pas en risque** : tous additifs, hors frozen, NF525 intact, adversarial réel. **Conditions dures du GO :**
1. **G-TRIAGE d'abord** (DB propre avant scheduler) — sinon PR-01 = 1252 annulations.
2. **Réconcilier mon Wave-1 (non commité) avec les PR** (TRAP-2↔PR-01, OPS-3↔PR-01/03, OPS-2↔PR-04) — ne pas double-exécuter ; mon Wave-1 fait DÉJÀ une partie du plancher (drill restore, storage:cleanup, health-503, kiosk-cleanup, OSS-5s).
3. **Queue `--queue=high,default`** (sinon PR-01 inerte).
4. **Jamais** : `config:cache` live, `kill` du `serve` vivant, touch frozen sans LOCK.
- **PR-02 + PR-04** peuvent partir **maintenant** (sûrs, additifs, P0/P2 visibilité) — ce sont les meilleurs premiers pas. **PR-01** attend G-TRIAGE + réconciliation. **PR-05/06/07** = secondaire/cloud, sans urgence.
- **PR-07** : le sweep + `AuditLogService:273` restent **cloud (W5)** — ne PAS les remonter comme blocker V1.

## 7. Alignement vision (attestation)
**Atteste** : l'ensemble (GOAL + PR-01..07) respecte la vision **V1 = outil PERSONNEL Le Cayenne, mono-poste, LOCAL, FR, 1 branche, PAS un SaaS** — le cœur (commande→validation→transfert inter-systèmes→synchronisation, **0 perte**) est protégé d'abord, tout le reste est différé/incrémental, le **cloud est documenté-mais-non-exécuté** (après validation locale), le **TPE simulé** est traité comme choix assumé, et **aucune zone frozen n'est touchée sans LOCK+gate** (PR-07 le prouve en sortant `AuditLogService:273`). Le seul vrai danger d'exécution (PR-01) est neutralisé par G-TRIAGE. **Aligné.**

---
*Audit read-only. Aucun code/plan exécuté. Verdict écrit dans `reports/handoffs/SUPERVISOR_VERDICT_2026-06-04.md`. Pas de commit, pas de push.*
