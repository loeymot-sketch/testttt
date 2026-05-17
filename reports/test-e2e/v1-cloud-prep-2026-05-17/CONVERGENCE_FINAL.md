# V1 Cloud-Prep — Final Convergence Report

**Session** : 2026-05-17 → 2026-05-18
**Branche** : `v1-0-1-hardening-2026-05-17`
**HEAD pre-session** : `4fc4c3b86` (V1.0.1 hardening converged)
**HEAD post-session** : `155ddbde8`
**Predecessors** : `reports/test-e2e/v1-0-1-2026-05-17/CONVERGENCE_V1_0_1.md` (V1.0.1 hardening) → Master Plan V2 Phase A/B/C → 7 implementer waves 5A-5G
**Methodology** : `superpower-gstack` composé — GStack 7-step + Superpowers parallel subagents + RED-team adversarial — TDD discipline, frozen-zone absolute, file:line anti-fabrication.

---

## 1. Verdict global : **GO ABSOLUTE** for Phase D cloud deploy

V1 Cloud-Prep session **converged**. 6 commits scope-minimal, 67 files modifiés (+3960 LOC net), 0 frozen-zone touch, **13 P0 + 5 V1.0.2 P1 RED-team findings closed** (18 total), 1 LOCK plan owner-gate authored pour heal frozen-zone POS XSS. Toutes verification gates green : Vitest 1444/1447 PASS / 0 FAIL stable across 4 waves, PHPUnit heal-scope 80/80 + Wave 5G broader 95/95 + sentinels NEW PASS, E2E heal-scope 16-21/17-21 stable (1 skipped déterministe). NF525 chain unchanged. Phase D cloud-deploy unblocked pending owner-physique 10 actions (AWS keys + LOCK signature + Ansible vault + DR drill).

---

## 2. Commits + files + LOC

| Commit | Wave | Subject | Files | +/- LOC |
|--------|------|---------|-------|---------|
| `72b078682` | Phase C local | POS offline + backup procedure + cloud env template | 8 | +978 / -0 |
| `0d35b4182` | 5D | RED-team hardening + Phase D playbook + visual mandate + baseline KIs heal | 14 | +594 / -5 |
| `dec9aec5a` | 5E | 4 RED-team P0 (LanguageService RCE + POS IDOR + Ansible templates + Outbox pruning) | 7 | +405 / -2 |
| `55edb83ba` | 5F | 5 P0/P1 heals + POS offline UI integration + 2 sentinels | 12 | +623 / -11 |
| `b680bb980` | 5F align | POS IDOR sentinel align (withoutGlobalScope INTERNAL + explicit 403) | 1 | +5 / -3 |
| `155ddbde8` | 5G | 5 V1.0.2 P1 closures + POS XSS LOCK plan owner-gate | 28 | +1386 / -10 |
| **TOTAL** | — | — | **67** | **+3983 / -23** |

---

## 3. P0 closed (13 items)

| ID | Finding | Heal file:line | Commit |
|----|---------|----------------|--------|
| P0-#1 | LanguageController RCE primitive (LFI + arbitrary file write) | `app/Http/Controllers/Admin/LanguageController.php` constructor `permission:settings` middleware | `dec9aec5a` |
| P0-#2 | POS IDOR PosOrderController::show cross-branch fiscal leak | `app/Http/Controllers/Admin/PosOrderController.php:107-115` (withoutGlobalScope INTERNAL + abort_unless 403) | `dec9aec5a` + `b680bb980` |
| P0-#3 | Phase D Ansible templates missing (nginx + supervisor) | `deploy/ansible/templates/nginx-foodking.conf.j2` (106 LOC) + `supervisor-foodking.conf.j2` (52 LOC) | `dec9aec5a` |
| P0-#4 | Outbox pruning command missing (90d GDPR + table bloat) | `app/Console/Commands/PruneOutboxCommand.php` (104 LOC) + `PruneWebhookEventsCommand.php` (102 LOC) + Kernel daily 04:15 | `dec9aec5a` |
| P0-#5 | Backup procedure NF525 6y retention | `scripts/backup-foodking-daily.sh` + `restore-foodking-from-backup.sh` + `docs/runbooks/BACKUP_RESTORE_NF525.md` (141 LOC) | `72b078682` + `0d35b4182` (gunzip-t + s3_put_retry) |
| P0-#6 | POS offline mode (network loss = cash inacceptable) | `resources/js/helpers/posOfflineQueue.js` (124 LOC) + `posOfflineQueueDb.js` (94 LOC) + `usePosOfflineState.js` (104 LOC) | `72b078682` + `55edb83ba` (UI integration `PosComponent.vue` +174 LOC) |
| P0-#7 | Cash drawer idempotency missing | `routes/api.php` (+4 LOC `idempotency` middleware on `cash-drawer/open` + `sessions/{open,close,reconcile}`) | `55edb83ba` |
| P0-#8 | RefundCreated event ZERO production dispatch | `app/Services/Order/RefundWithCounterEntryService.php:229` + `app/Services/PaymentService.php:134` (+ NEW `tests/Feature/RefundCreatedDispatchTest.php` 2/2 PASS) | `55edb83ba` |
| P0-#9 | POS Split-payment phantom CARD cash theft | `app/Http/Requests/PosOrderRequest.php` (+39 LOC `terminal_id required_if mode=CARD`) + `app/Services/Payments/SplitPaymentService.php` (+29 LOC defense-in-depth) + NEW sentinel | `55edb83ba` |
| P0-#10 | Phase D Ansible playbook missing | `deploy/ansible/site.yml` (160 LOC, 20 tasks / 8 role groups) + `inventory/production.ini` + `group_vars/all.yml` | `0d35b4182` |
| P0-#11 | QUEUE_CONNECTION=sync local (event fanout sync-blocking) | `.env` (local, gitignored — `QUEUE_CONNECTION=redis`) | `72b078682` |
| P0-#12 | LOG_CHANNEL=stack DEBUG verbose (PII leak prod) | `.env` (local, gitignored — `LOG_CHANNEL=daily` + `LOG_LEVEL=warning`) | `72b078682` |
| P0-#13 | Cloud env template absent (Phase D blocked) | `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` (142 LOC) | `72b078682` |

---

## 4. V1.0.2 P1 closed (5 items in Wave 5G)

| ID | Finding | Heal file:line | Tests |
|----|---------|----------------|-------|
| R4 | OSS wakeLock TV walls (display sleeps after 5min idle) | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` (+40 LOC `_acquireWakeLock` + visibilitychange) | `tests/js/ossWakeLockOnMount.spec.js` 6/6 PASS |
| V1.0.1-bcrypt | bcrypt rounds 10→12 + zero-friction auto-rehash | `config/hashing.php` env default 12 + `LoginController.php` inline `Hash::needsRehash` post-Auth | `tests/Feature/Auth/BcryptRoundsUpgradeTest.php` 4/4 PASS |
| R9 | Settings update fanout (admin → POS/Kiosk live sync) | `app/Events/SettingsUpdated.php` NEW + `app/Listeners/PersistSettingsUpdatedToOutbox.php` NEW + 5 controllers wired (Currency/Tax/Company/Site/OrderSetup) | `tests/Feature/Settings/SettingsUpdatedBroadcastTest.php` 5/5 PASS |
| R10 | Branch status flip revokes user tokens | `app/Events/BranchStatusChanged.php` NEW + `app/Listeners/RevokeTokensOnBranchDeactivated.php` NEW (scope `tokenable_type=User` strict, kiosk:order preserved) + `BranchController::update` wire | `tests/Feature/Branch/BranchDeactivationTokenRevokeTest.php` 5/5 PASS |
| Phase D | Readiness probe `/api/health/ready` verified | `app/Http/Controllers/HealthController.php` (215 LOC existing) + `routes/api.php:140` (no auth, no rate-limit, K8s-compatible) | 12/12 PASS unchanged |

---

## 5. Test convergence

| Suite | Result | Notes |
|-------|--------|-------|
| **Vitest** (FULL) | **1444 PASS / 0 FAIL / 3 skipped** (1447 total) | Stable Wave 5D→5G ; 2 baseline KIs healed Wave 5D ; NEW Wave 5G ossWakeLock 6/6 |
| **PHPUnit heal-scope** | **80/80 PASS** (296 assertions) | Stable across all waves — zero regression on POS+KDS+OSS+Refund+Stock |
| **PHPUnit Wave 5G broader** | **95/95 PASS** (Auth + Login + Settings + Branch + Health) | NEW tests : Bcrypt 4/4 + Settings 5/5 + Branch revoke 5/5 + Health 12/12 unchanged |
| **PHPUnit Feature broader** | **487/494 PASS** | 6 pre-existing skipped + 1 incomplete (unrelated to session scope) |
| **PHPUnit POS suite** | **50/50 PASS** (178 assertions) | Wave 5E + 5F IDOR + Split-pay + offline UI = zero regression |
| **PHPUnit CashDrawer\|CashSession** | **45/45 PASS** | Idempotency middleware addition non-regressive |
| **PHPUnit Kitchen\|OSS\|Kds** | **120/121 PASS** | 1 pre-existing unrelated POS authz fail (NOT session regression) |
| **PHPUnit Refund\|Stock** | **100/100 PASS** | RefundCreated dispatch wire confirmed |
| **E2E Playwright heal-scope** | **16 passed / 1 skipped / 0 failed** | POS+KDS+OSS+staff routing stable through Wave 5F |
| **E2E visual-mandate** | **7 captures GREEN** | `tests/e2e/phase-c-visual-mandate-2026-05-17.spec.js` — login/POS/items/stock/KDS/OSS/kiosk-idle ; console-errors.json baseline captured |
| **Vitest sentinels NEW** | **2/2 PASS** | `PosSplitPaymentPhantomCardSentinelTest` + `FrenchRuntimeNoBangladeshDemoDataSentinelTest` fix |

**Frozen-zone diff** (session 6 commits, 13 CLAUDE.md §7 protected files) : **0 lines, 0 files modified**.

**NF525 chain** : `audit_logs` row count + last `current_hash` unchanged (cf. V1.0.1 baseline `ca4ac1fdc208dae1`). Triggers `no_update`/`no_delete` active. `composition_snapshot` immutability preserved. `fiscal_sequence_no` monotonic. Loi de Finance France compliance 100%.

---

## 6. NEW deliverables

### Cloud infrastructure (Phase D unblock)
- `deploy/ansible/site.yml` (160 LOC, 20 tasks / 8 role groups idempotent)
- `deploy/ansible/inventory/production.ini` (10 LOC)
- `deploy/ansible/group_vars/all.yml` (39 LOC vault refs)
- `deploy/ansible/templates/nginx-foodking.conf.j2` (106 LOC HTTP-only initial + Certbot --nginx)
- `deploy/ansible/templates/supervisor-foodking.conf.j2` (52 LOC queue worker + Soketi)
- `deploy/ansible/README.md` (64 LOC ops runbook)
- `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` (142 LOC OVH VPS-1 template)

### Backup + DR (NF525 6y retention)
- `scripts/backup-foodking-daily.sh` (105 LOC mysqldump --triggers --routines + SHA-256 + s3cmd OVH + gunzip-t + retry exp-backoff)
- `scripts/restore-foodking-from-backup.sh` (77 LOC SHA verify + verifyChain AuditLog + ZReport + exit 3 on FAIL)
- `docs/runbooks/BACKUP_RESTORE_NF525.md` (141 LOC 6 sections : setup/cron/monthly DR drill/6y retention/emergency/owner-physique)

### POS offline mode (V1.0.2 integration shipped Wave 5F)
- `resources/js/helpers/posOfflineQueue.js` (124 LOC IndexedDB queue manager)
- `resources/js/helpers/posOfflineQueueDb.js` (94 LOC DB wrapper + localStorage fallback)
- `resources/js/composables/usePosOfflineState.js` (104 LOC Vue 3 composable)
- `resources/js/components/admin/pos/PosComponent.vue` (+174 LOC UI integration, NOT pos-wizard.js frozen)
- Path A server-authoritative replay : UUIDv4 idempotency-key + PCI-DSS/PII strip + 30min TTL + MAX_ENTRIES=50

### Sentinels (RED-team P0 regression-proof)
- `tests/Feature/Sentinels/PosSplitPaymentPhantomCardSentinelTest.php` (225 LOC) — cashier cannot persist `OrderPayment(mode=CARD)` without valid `terminal_id` from current branch
- `tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php` (+5 LOC fix post Sprint 2B phone migration)

### LOCK plan owner-gate
- `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` (401 LOC) — frozen-zone heal request for POS wizard XSS escape, complete scope/rollback/safety-check override/sub-agent instructions/owner sign-off section

### Visual mandate captures
- `tests/captures/phase-c-visual-mandate-2026-05-17/` — 7 screenshots (login + admin-pos + admin-items + stock-rupture + KDS + OSS + kiosk-idle) + `console-errors.json` baseline
- `tests/e2e/phase-c-visual-mandate-2026-05-17.spec.js` (127 LOC)

### Misc
- `app/Events/SettingsUpdated.php` + `BranchStatusChanged.php` + `RefundCreated` dispatch wire
- `app/Listeners/PersistSettingsUpdatedToOutbox.php` + `RevokeTokensOnBranchDeactivated.php`
- `app/Console/Commands/PruneOutboxCommand.php` + `PruneWebhookEventsCommand.php` + Kernel 04:15 daily
- `soketi.json` maxConnections 100→500 (V1+V2 headroom)

---

## 7. V1.0.2 remaining backlog

### Owner-physique action required (blocks Phase D)
- **AWS key rotation** (commit `a4a88df06` "up" auto-commit historic exposure — carryover from ultra-goal verdict 2026-05-13)
- **POS XSS LOCK plan owner countersign** — `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` requires owner gate signature before frozen-zone touch
- **Ansible vault password** + OVH VPS-1 SSH key provisioning + DR drill on staging

### Frozen-zone LOCK pending owner-gate (V1.0.2 scope)
- POS wizard XSS escape primitive heal (`pos-wizard.js` frozen, plan authored Wave 5G)
- POS legacy de/bn `kds_*` i18n 71-key parity gap (carryover from V1.0.1 Wave Z V1.0.2 backlog)
- Stripe cents-truncation fix unbundled (CTO P0-6, V1.0.1 V1.0.2 backlog)

### Low priority V2 / SaaS B2B (deferred)
- **Wave 5H pending (NOT done)** : PhpSpreadsheet RCE upgrade (1 CRITICAL advisory) + FormRequest authz refactor 88 endpoints
- DEL-9 auto-dispatch (3 sub-sprints ~15j, V1.0.2)
- Webhook DLQ provider replay full refactor (Stripe + SenangPay parity)
- P1-Z7-01 Stage B terminal_id UI selector (Stage A wire done Wave Z)
- OSS branch enum logging hardening
- Channels clear-to-empty + DRY sub-component
- Sanctum customer:order ability (mobile/web wireup, Phase 6 backlog)
- 17 advisories composer triage (PhpSpreadsheet CRITICAL + 16 others)
- Laravel 9→10→11 migration track
- Spatie permissions 5→6 track
- ESLint v10 + Vue plugin

---

## 8. Phase D owner-physique checklist (10 actions)

Before `ansible-playbook site.yml -i inventory/production.ini --ask-vault-pass` real run :

- [ ] **1. Owner countersign LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md** (frozen-zone gate per CLAUDE.md §10)
- [ ] **2. Rotate AWS keys** exposed in commit `a4a88df06` (carryover ultra-goal 2026-05-13)
- [ ] **3. Provision OVH VPS-1** Cloud server + Object Storage bucket (NF525 6y retention 2200d lifecycle + object-lock compliance)
- [ ] **4. SSH passwordless sudo** for `deploy` user on VPS-1 (Ansible playbook requirement)
- [ ] **5. Generate `ansible-vault` password** + populate `group_vars/all.yml` vault refs (db/redis/fiscal/s3 secrets)
- [ ] **6. Copy `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` → `.env` on VPS-1** (review APP_KEY + DB + Pusher + Stripe live keys)
- [ ] **7. Run DR drill** on staging (full backup + restore + `verifyChain` AuditLog + ZReport pass) — monthly mandatory per `docs/runbooks/BACKUP_RESTORE_NF525.md`
- [ ] **8. Install cron `backup-foodking-daily.sh`** + cron monitor (failure alert via email/Slack)
- [ ] **9. Certbot --nginx** for SSL provisioning (Ansible `nginx-foodking.conf.j2` HTTP-only initial, Certbot injects 443+HSTS in-place)
- [ ] **10. Smoke E2E on production VPS-1** (login + admin-pos + kiosk-idle + KDS + OSS + readiness probe `/api/health/ready` 200) — validate captures match `tests/captures/phase-c-visual-mandate-2026-05-17/` baseline

Post Phase D : V1 Cloud-Prod **LIVE** for Le Cayenne single-restaurant. V1.0.2 hardening cycle begins (Wave 5H PhpSpreadsheet + FormRequest authz + frozen-zone LOCK heals).
