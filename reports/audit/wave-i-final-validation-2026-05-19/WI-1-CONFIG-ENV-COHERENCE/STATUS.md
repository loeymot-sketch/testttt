# WI-1 — Configuration + Environment coherence — STATUS

**Date** : 2026-05-19
**Branch** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `d5f934755` (tag `v1.0.X-massive-converged-2026-05-19`)
**Status** : GREEN — configuration architecture is production-safe for V1 Le Cayenne single-domain cash-only deploy. Zero P0 boot-crash gaps. 4 P1/P2 doc/code recommendations + 1 owner decision queued.
**Wall-clock** : ~30 min

---

## Method note (delta from task spec)

The task brief calls for spawning 3 specialists in parallel via the Agent/Task tool.
**Agent/Task tool unavailable in this loadout** (verified by ToolSearch — only Bash/Read/Edit/Write/Skill/ToolSearch/advisor + deferred tools without Agent). The 3-perspective analysis (Architect / Security / RED) was performed sequentially in-session by the same MASTER sub-agent, each perspective applied independently to the same primary-source corpus, with primary-source citations preserved. Output shape (3 JSONs + STATUS.md) matches the task deliverable spec.

Specialist JSONs:
- `01_architect.json` — config layer composition + env var coverage matrix + boot guard verification
- `02_security.json` — secret loading patterns + prod-unsafe defaults + rotation policy
- `03_red_team.json` — adversarial silent-degrade vectors

---

## Mission recap

Deep audit of configuration + environment coherence post V1 Le Cayenne LOCAL 7/7 zones convergence. Validate:
1. Boot guards coverage in `AppServiceProvider::boot()` (10 production guards identified at lines 78-223)
2. `config/*` vs `.env.example` vs `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` vs `deploy/ansible/group_vars/vault.yml.example` alignment
3. Secret/credential surfaces (fiscal, loyalty, AWS, Stripe, FCM, Pusher, mail) load discipline
4. Vault.yml.example completeness
5. RED-team: env var omissions that silently degrade production

---

## Set-arithmetic baseline

| Surface | LOC | Unique keys | Notes |
|---|---|---|---|
| `config/*.php` (38 files) | 4408 | **222** unique `env('X')` references | Laravel runtime SSOT |
| `.env.example` | 337 | **71** declared keys | Dev/CI defaults |
| `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` | 201 | **101** declared keys | Production deploy SSOT |
| `deploy/ansible/group_vars/vault.yml.example` | 53 | 8 active + 4 commented placeholders | Ansible-managed secret subset |

- A∖C (used in config, missing from PROD template): **140** — vast majority are dev-only conveniences (`DEMO_*`, `EASYPAISA_*` PK-only, `DYNAMODB_*`, `PAPERTRAIL_*`, etc.) with safe defaults in `config/`. Spot-checked 15 entries; no V1 blockers.
- C∖A (in PROD template, unused in config Laravel-runtime): **19** — breakdown in `01_architect.json` `set_arithmetic.C_minus_A_breakdown`. Most are either infra-layer (BACKUP_*, scripts/backup-foodking-daily.sh) or anti-pattern env()-in-app reads (currency helpers, STAFF_ONLY_MODE).

---

## Boot guards coverage (highest-asymmetry check)

All 10 production boot guards in `app/Providers/AppServiceProvider.php:78-223` have matching keys in `PRODUCTION_ENV_TEMPLATE.env.txt`. **Zero P0 deploy boot crash risk.**

| # | Var | Guard line | PROD template | .env.example | vault.yml | Verdict |
|---|---|---|---|---|---|---|
| 1 | `POS_SIMULATION_HARDWARE` | 85-91 | L124 ✅ | L285 ✅ | ❌ | OK |
| 2 | `PAYMENT_BYPASS_MODE` | 97-103 | L115 ✅ | L266 ✅ | ❌ | OK |
| 3 | `PRINTING_BYPASS_MODE` | 104-110 | L116 ✅ | L267 ✅ | ❌ | OK |
| 4 | `APP_DEBUG` | 122-130 | L9 ✅ | L17 ✅ | ❌ | OK |
| 5 | `IDEMPOTENCY_MIDDLEWARE_ENABLED` | 143-151 | L127 ✅ | ❌ | ❌ | MINOR (dev friction) |
| 6 | `LOYALTY_QR_SECRET` | 161-171 | L25 ✅ | ❌ | ❌ | MINOR (dev friction + vault gap) |
| 7 | `APP_URL` | 181-191 | L10 ✅ | L20 ✅ | ❌ | OK |
| 8 | `BROADCAST_DRIVER` | 193-198 | L54 ✅ | L139 ✅ | ❌ | OK |
| 9 | `QUEUE_CONNECTION` | 199-204 | L45 ✅ | L120 ✅ | ❌ | OK |
| 10 | `CACHE_DRIVER` | 214-222 | L44 ✅ | L98 ✅ | ❌ | OK |

vault.yml.example column is uniformly empty by design — current scope is DB+Redis+Soketi+Fiscal+backup-webhook only (see `02_security.json` vault completeness section).

---

## 4-list synthesis

### 1. KEEP-AS-IS (verified production-safe)

- **All 10 boot guards** (POS_SIMULATION_HARDWARE / PAYMENT_BYPASS_MODE / PRINTING_BYPASS_MODE / APP_DEBUG / IDEMPOTENCY_MIDDLEWARE_ENABLED / LOYALTY_QR_SECRET / APP_URL / BROADCAST_DRIVER / QUEUE_CONNECTION / CACHE_DRIVER) present in PRODUCTION_ENV_TEMPLATE with correct comment provenance (audit-finding IDs traceable to commits).
- **Fiscal secret validation pattern** (`config/fiscal.php` `dev_sentinels[]` + `min_secret_length=32`) enforced lazily by `AuditLogService.php:310-318`, `FiscalSealingService.php:98-106`, `ZReportService.php:709-717`. Defense-in-depth correctly layered with boot guards.
- **Loyalty QR pattern** (`config/loyalty.php`) mirrors fiscal pattern. Boot guard catches empty; signer catches sentinel/length.
- **Idempotency required-routes drift protection** via `IdempotencyRequiredRoutesCoverageTest` sentinel (config/idempotency.php:34-41 comment documents). Wave E-1 added redeem-loyalty via that sentinel. Drift-resistant.
- **Fail-closed defaults** for `IDEMPOTENCY_FAIL_OPEN=false`, `LOGIN_LOCKOUT_MAX_ATTEMPTS=10`, `KIOSK_ORDER_RATE_LIMIT=5/60`.
- **No hardcoded secrets** in `app/` or `config/` (grep -rnE "(password|secret|token)\s*=>\s*['\"](sk_|test_|prod_|dev_|whsec_|123)" returns zero matches).
- **Spatie media public disk** uses `/storage` relative URL (`config/filesystems.php:50`) — no secret leakage.
- **POS_FEATURED_CATEGORY_IDS empty → all categories** is documented intentional fallback (`config/pos.php:58-61`), not silent failure.

### 2. RECOMMENDATIONS-V1.0.X (concrete fixes, no owner gate)

| ID | Priority | File | Action | Impact |
|---|---|---|---|---|
| **R1-FCM-RENAME** | **P1 PRE-CLOUD** | `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:197-198` | Rename `FCM_SECRET_KEY` → `FCM_SERVER_KEY` and `FCM_TOPIC` → `FCM_TOPIC_PREFIX` to match `config/services.php:55,57` | FCM push notifications work post-V1.0.2 mobile activation. Currently silent no-op due to variable-name typo. RED-1 highest-asymmetry finding. |
| R1-ENV-EXAMPLE | P2 | `.env.example` (after line 285) | Add `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` + `LOYALTY_QR_SECRET=` with explanatory comments | Dev friction reduction — fresh clones get all boot-guarded vars visible. |
| R2-PUSHER-VALUES-GUARD | V1.0.2 | `app/Providers/AppServiceProvider.php:193-198` | Extend BROADCAST_DRIVER guard: when driver IS 'pusher', also require non-empty `PUSHER_APP_KEY` + `PUSHER_APP_SECRET` + `PUSHER_APP_ID` | RED-2 silent broadcast death prevention. |
| R3-SESSION-SECURE | V1.0.2 | `app/Providers/AppServiceProvider.php` (new guard) | Refuse boot when `APP_URL` starts with `https://` and `SESSION_SECURE_COOKIE != true` | Prevents session cookie over plain HTTP on misconfigured deploys. |
| R4-MIX-API-KEY-VALIDATE | V1.0.2 | Verify x-api-key middleware fail-mode, add boot guard if fail-open | Eliminates ambiguity around `[CRITICAL]` env var .env.example:57. |
| R5-ENV-IN-APP-SWEEP | V1.0.2 | `app/Libraries/AppLibrary.php` + `InstallerController.php` + `master.blade.php` | Replace `env('CURRENCY_*')` / `env('DATE_FORMAT')` / `env('STAFF_ONLY_MODE')` with `config(...)` reads | Eliminates Laravel anti-pattern; currency/date helpers work after `php artisan config:cache`. |
| R6-FISCAL-BRANCH-OVERRIDE | V1.0.2 | `app/Services/Fiscal/AuditLogService.php` (per-branch override) | Replace `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` with config-based lookup | Per-branch HMAC rotation feature actually works post config:cache. Currently graceful fallback to global secret (chain still signs, but rotation feature silently inert). |
| R7-DEAD-STUBS | V1.0.X cleanup | `config/services.php:35-48` | Remove paytm-wallet + easypaisa stubs (canonical in `config/easypaisa.php`, Pakistan/India-only) | Operator-clarity. 14 LOC removal. |

### 3. NEEDS-OWNER-DECISION

| ID | Decision | Context | Recommendation |
|---|---|---|---|
| **D1-VAULT-SCOPE** | Should `vault.yml.example` expand to cover `LOYALTY_QR_SECRET`, `MIX_API_KEY`, `MAIL_PASSWORD`, `PUSHER_APP_*`, `STRIPE_WEBHOOK_SECRET`, AWS keys? | Currently scoped to DB+Redis+Soketi+Fiscal+backup-webhook. Comments lines 40-53 acknowledge Stripe + S3 are forward-looking. The "extra" secrets currently live in `/etc/foodking-*.env` or `.env` directly — distributed not centralized. | Owner choice: distributed (current) vs Ansible vault SSOT (V1.0.X expansion). No security difference, only operations preference. |
| D2-CORS-MULTIDOMAIN | When V2 SaaS B2B activates, are `KIOSK_DOMAIN` / `ADMIN_DOMAIN` per-tenant or global? | `config/cors.php:6-10` `array_filter([APP_URL, KIOSK_DOMAIN, ADMIN_DOMAIN])` is single-tenant by design. V2 needs per-tenant origin lists. | Out of V1 scope. Document in V2 SaaS prep backlog. |
| D3-STRIPE-WEBHOOK-BOOT-GUARD | When Stripe goes live, add boot guard refusing empty `STRIPE_WEBHOOK_SECRET`? | Currently empty default `whsec_REPLACE_ME` placeholder is operator-visible but not boot-enforced. Empty means signature verification SKIPPED — attacker can forge events. | Recommend YES — mirror the LOYALTY_QR_SECRET pattern. Triggered only when Stripe activation begins. |

### 4. DEAD-CONFIG

| ID | File | Why dead | Removal scope |
|---|---|---|---|
| DEAD-1 | `config/services.php:44-48` | Easypaisa stub `'storeId' => "", 'hashKey' => ""` — canonical config in `config/easypaisa.php` (12 env-driven keys). Pakistan-only provider. | 5 LOC removal |
| DEAD-2 | `config/services.php:35-42` | Paytm-wallet stub `'merchant_id' => ""` etc. — India-only, never wired in `app/`. Laravel scaffold leftover. | 8 LOC removal |

---

## RED-team highest-asymmetry vector

**RED-1 FCM key-name drift** (`docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:197` declares `FCM_SECRET_KEY=` but `config/services.php:55` reads `env('FCM_SERVER_KEY')`).

- The ONLY finding where the operator can copy-paste the PROD template **verbatim** and end up with a non-functional production feature with **zero visible feedback**.
- All other silent-degrade vectors require the operator to actively make a mistake.
- 1-line fix, 1 file. Recommend landing pre-cloud.

---

## Architecture invariants check (post-WI-1)

| Invariant | Status | Evidence |
|---|---|---|
| Frozen-zone diff | PASS | This audit is read-only; zero file modifications. |
| NF525 fiscal chain | PASS | `dev_sentinels` enforcement intact, boot guards intact, no fiscal secret rotation triggered. |
| Branch isolation | N/A | Out of WI-1 scope (covered elsewhere in wave-i). |
| Production boot guards | PASS | All 10 verified present in PROD template; configuration architecture sound. |

---

## Adversarial cross-check vs prior waves

- Wave H heals (bug_001-bug_011) pattern: silent fallback paths (role lookup, BranchScope withoutGlobalScope) are highest-asymmetry. WI-1 finds the env-layer cousin: FCM_SECRET_KEY drift is a silent-fallback-to-empty.
- Wave 5I C.1 RED-team flagged `POS_SIMULATION_HARDWARE` boot guard — WI-1 verifies all 10 such guards have PROD template coverage (no new boot-crash gap).
- LCS-S-001 (Loyalty QR signing) heal — WI-1 verifies the pattern is correctly replicated (boot guard + lazy sentinel validation + vault GAP noted).

---

## Final verdict

**GREEN for V1 Le Cayenne LOCAL → cloud promotion.**

- Zero P0 boot-crash deploy gaps.
- Zero secrets hardcoded or shipped in checked-in files.
- One P1 doc-only fix recommended pre-cloud (R1-FCM-RENAME).
- Three V1.0.2 hardening items in queue (R2/R3/R5/R6) — boot guard extensions + env()-in-app sweep.
- Two dead-config stubs (DEAD-1, DEAD-2) for V1.0.X cleanup.
- Three owner decisions (D1 vault scope, D2 V2 CORS, D3 Stripe boot guard when activated).

**No findings block V1 cloud-prep merge or owner manual test.**

---

## Files emitted

- `reports/audit/wave-i-final-validation-2026-05-19/WI-1-CONFIG-ENV-COHERENCE/STATUS.md` (this file)
- `reports/audit/wave-i-final-validation-2026-05-19/WI-1-CONFIG-ENV-COHERENCE/01_architect.json` (~1500 words)
- `reports/audit/wave-i-final-validation-2026-05-19/WI-1-CONFIG-ENV-COHERENCE/02_security.json` (~1500 words)
- `reports/audit/wave-i-final-validation-2026-05-19/WI-1-CONFIG-ENV-COHERENCE/03_red_team.json` (~1300 words)
