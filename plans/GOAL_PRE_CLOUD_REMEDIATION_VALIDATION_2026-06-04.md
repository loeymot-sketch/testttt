# GOAL — Pre-Cloud Remediation & Validation (Le Cayenne V1 → Cloud)

**Date** : 2026-06-04 · **Author** : Claude (supervisor) · **Status** : **PREPARE-ONLY — NOT FOR EXECUTION**
**Mission** : assemble the two delivered read-only audits (the POS *box* + all other *systems*) into ONE ultra-developed action plan — disciplines, test structures, convergence waves, owner gates, and a deeper-audit plan for what is still un-covered — so that **after remediation there is strictly zero defect**, because the cloud cutover happens immediately after.

> Companion (exhaustive, machine-generated, lives outside this doc):
> `reports/audit/pre-cloud-2026-06-04/PRE_CLOUD_REMEDIATION_CATALOG.md` (+ `.json`) — every one of the 156 findings with its file:line, recommended correction, and templated validation test. This GOAL is the **strategy**; the catalog is the **per-finding worklist**.
> Source reports: `reports/audit/pos-box-massive-2026-06-04/POS_BOX_AUDIT_REPORT.md` · `reports/audit/systems-massive-2026-06-04/SYSTEMS_AUDIT_REPORT.md`.

---

## §0 — Preamble (read before anything)

### §0.1 PREPARE-ONLY hard gate  🔒
**This document plans; it does NOT authorize a single code change.** No Edit/Write on business code, no migrations, no commits to `app/`, `resources/`, `public/`, `config/`, `routes/`, `database/`. Execution begins **only** when the owner explicitly says "go / execute / lance la remediation". Until then every wave below is a *recipe*, not a task in flight. The audits that produced the 156 findings were already read-only and are **closed** — nothing here re-opens them; this only organizes the fix-and-prove campaign for later.

### §0.2 Working-tree decision
- Current branch `heal/cms-pr1-quickwins-2026-05-18`. The two audit deliverables + this plan + the catalog are **docs/reports only** (no business code) → committable as a documentation checkpoint when owner authorizes, on a `plan/pre-cloud-remediation` branch. **No push** without owner (CLAUDE.md §10).
- Execution (later) runs in an **isolated git worktree** per wave to protect the user's working copy and parallel jobs. Frozen-touching work runs in its own quarantined worktree under a LOCK doc (§5).

### §0.3 What "strictly zero defect before cloud" means here
Cloud cutover inherits every V1 defect and multiplies its blast radius (multi-instance cache, ALB, remote printers). So the bar is **production-perfect, not "almost"**. Convergence = the rejection rules in §8 all clear AND two consecutive verification cycles produce P0+P1 = 0 with an **identical** findings set (flake guard). A green unit test alone never closes a finding — the visual gate (CLAUDE.md §6) and the NF525 chain attestation (§0.6) are co-equal evidence.

### §0.4 Per-task execution pipeline (referenced, not re-described)
Each finding, when executed later, runs through the **`ultra-audit-profond`** 14-step pipeline (5 read-only specialists in parallel → implementer TDD-first → RED dispute → dual visual cross-check → frozen/NF525 gates → commit → BRAIN update). The army map + fan-out matrix is in `ultra-architect-planify` Axis 4. This GOAL does not repeat it; it only assigns *which* findings flow through it in *which* wave.

### §0.5 Anchors verified for this plan (anti-fiction, ANCHOR-FIRST)
All counts below come from re-reading the two machine JSONs on 2026-06-04 (`node` over `*_MACHINE.json`), not from memory. Frozen list = CLAUDE.md §7 (14 files). Fiscal artisan commands cited (`fiscal:verify-chain`, `fiscal:assert-chain-clean`, `fiscal:verify-z-membership`) were confirmed present via `php artisan list`. Test dirs cited (`tests/Feature/{Auth,Branch,Cash,Catalog,Coupon,Delivery,Dashboard,...}`) confirmed via `ls`. The unaudited **auth surface** (9 `resources/js/components/frontend/auth/*.vue` + `app/Http/Controllers/Auth/{Login,ForgotPassword,KioskMachineLogin}Controller.php`) confirmed via `ls`/`find` — it is the single biggest coverage gap and gets its own deeper-audit wave (§7).

### §0.6 NF525 attestation protocol (every fiscal-adjacent wave close)
Before AND after any wave that touches a fiscal path: capture `SELECT COUNT(*), MAX(current_hash) FROM audit_logs` + same for `z_reports`, and run `php artisan fiscal:verify-chain --all` + `php artisan fiscal:assert-chain-clean`. Chain must be **appended-only** (count grows or equal; no row mutated/deleted; prior hashes unchanged). Any unexpected change → STOP + human gate. Frozen fiscal services (`FiscalSequenceService`, `ZReportService`, `AuditLogService`) diff MUST be 0 lines unless under a §5 LOCK doc.

---

## §1 — Consolidated inventory (the assembled state)

**156 confirmed corrections** survived adversarial existence+necessity challenge + 4-reviewer report self-audit across both campaigns (BOX 66 + SYSTEMS 90). The self-audit *removed* my own over-claims (a would-be P0 on discounts was disproved by re-running `ZReportDiscountNettingTest` 5/5; duplicates dropped; severities recalibrated) — so this set is calibrated, not inflated.

| Severity | Count | Meaning for cloud go/no-go |
|---|---|---|
| **P0** | **0** | No release-blocking fiscal/payment corruption found. The floor is solid. |
| **P1** | **21** | Must be GREEN before cloud — operator-identity NF525, money mis-bucketing, broken filters, security guards. |
| **P2** | **58** | Should be GREEN before cloud — correctness blurs, config footguns, data-ops. |
| **P3** | **77** | Polish + deadcode + cosmetic — batch-cleared, none block cloud alone but the "strictly zero" bar wants them gone. |

| Cross-cut tag | Count | Where it concentrates |
|---|---|---|
| **frozen-touching** | **15** = **10 hard** + **5 referenced** | **Hard** (fix lands IN a §7 file → LOCK): pos-wizard.js ×7, ZReportService ×2, OrderStateMachine ×1. **Referenced** (primary root non-frozen; a §7 file is only *referenced* → fix on the non-frozen side): M6-001, S13-02, M10-04, M6-006, S2-02 → §5 quarantine |
| **NF525-adjacent** | **33** | receipt/operator, Z netting, TVA, fiscal identity → W2/W3 |
| **security** | **10** | PII guards, branch-isolation, RBAC dropdown, push-scope → W4 |
| **needs-live-verify** | **5** | MUST be proven live in W1 before any fix is designed |
| live-verified (already) | 12 | main-agent Playwright-confirmed in the audits |
| coverage gaps (RED-flagged) | 147 | → deeper-audit W8 (§7) |
| confirmed solid (good logic/UX) | 136 | regression guard — do not break these |

Category split: logic 69 · ux 28 · functional 14 · nf525 12 · deadcode 11 · security 10 · visual 9 · perf 3.

---

## §2 — Cross-cutting clusters (fix once, validate everywhere)

The single most important assembly insight: **4 file roots carry duplicate findings across BOTH reports** — fixing the root once must be validated against every dependent finding, and they must NOT be split across waves.

| Root file | Findings (report) | Cluster meaning | Wave |
|---|---|---|---|
| `app/Services/Receipt/ReceiptDataService.php` | **M11-01**(BOX) + **S11-02**(SYS) | `:70` reads `order->user` (the *customer*) as operator → every receipt prints "Opérateur: Client passage" instead of the cashier. NF525 operator-identity. The cross-report headline. | **W2** |
| `app/Services/OrderService.php` | M6-001(BOX) + S13-02 + S5-02 + S5-06 + S12-02 + S12-05(SYS) | 6 findings on the order/total/tax/discount spine — discount-vs-tax ordering, persisted `total_tax` basis, delivery totals. Highest-leverage backend file. | **W3** |
| `app/Services/PaymentService.php` | M10-01 + M10-05(BOX) + S16-01(SYS) | counter-collect cash with no CashMovement/drawer linkage + kiosk→counter operator. | **W2/W3** |
| `resources/js/services/appService.js` | M4-04 + M6-006(BOX) + S1-DASH-01(SYS) | `requestHandler` date/payload serialization → dashboard filters silently 422 + POS offline replay loss. | **W3/W5** |

**Discipline**: each cluster is ONE remediation unit (one branch, one implementer, one RED dispute) with N validation tests (one per dependent finding). Never let two waves touch the same root in parallel (write-conflict + state contamination — `ultra-architect-planify` Axis 3).

---

## §3 — Category → validation test-pattern (the disciplines)

Every finding's acceptance is templated by its category — the pattern below is the *shape* of the proof, not the final citation. **Conscious deferral**: per the Verified-Citation rule a finding is not "ready to code" until the implementer names a concrete test path (existing file or `to-be-created at <path>`); that naming happens at each wave's start, when the cluster's exact target files are open, NOT in this prepare-only doc. A naked "tests pass" with no named path is rejected at that point. §4 pre-seeds the real test dirs (`tests/Feature/{Pos,Cash,Coupon,Branch,Auth,...}`) each wave will draw from.

| Category | n | Acceptance test-pattern (what proves it fixed) |
|---|---|---|
| **logic** | 69 | PHPUnit Feature on a real DB order/row asserting the corrected branch + (frontend) Vitest unit on the method + live Playwright reproduction of the exact click-path proving the fixed result. |
| **ux** | 28 | Playwright capture + Read+analyze screenshot; if copy/i18n, assert resolved key in `fr.json` (no `Label.x`/`0undefined`); owner sign-off where wording is subjective. |
| **functional** | 14 | Playwright E2E reproducing the broken click-path → asserts corrected end-state (button reaches backend / route resolves / state transitions) + PHPUnit on the controller action. |
| **nf525** | 12 | PHPUnit fiscal test (seq gap-free / Z netting / chain HMAC) + `fiscal:verify-chain --all` & `fiscal:assert-chain-clean` before+after (appended-only). Frozen fiscal-service diff = 0 unless LOCK. |
| **deadcode** | 11 | Static: grep proves 0 remaining refs to removed symbol/route + full PHPUnit+Vitest suite green (no regression) + frozen diff = 0. |
| **security** | 10 | PHPUnit authz/branch-isolation test (403 / scoped for branch_id>0, admin bypass intact) + adversarial RED replay proving the exploit path is closed. Cite `tests/Feature/{Branch,Auth,Address,Coupon}Security*`. |
| **visual** | 9 | CLAUDE.md §6 visual gate: capture → Read → analyze (no raw label / layout / branding / i18n); two consecutive clean captures. |
| **perf** | 3 | Before/after measurement of the named metric (query count / `block_for` ms / payload size) via timing test or live trace + no functional regression. |

---

## §4 — Convergence waves (risk-first ordering)

Sequential by default (shared backend roots). Read-only audit phases *within* a wave fan out in parallel. Each wave has a checkpoint (the 6-point gate from `ultra-architect-planify` Axis 3) and an interrupt-resume manifest. **None of this runs until owner authorizes (§0.1).**

### W1 — Baselines, Owner Gates, Live-verify  *(no fixes; unblocks everything)*
- Capture green baselines: full PHPUnit run, Vitest run, frozen-zone `git diff --stat` = 0, NF525 chain snapshot (§0.6), the 29-page admin walk re-run (0 console errors / 0 raw labels is the regression floor).
- **Resolve the 5 needs-live-verify findings live** before any of them is "fixed": `M10-04` (Livrer on unpaid order), `S13-02` (per-order `total_tax` pre-discount basis — NF525-critical), `S11-04` (hardcoded role-id dropdown), `S11-07` (employee save error swallow), `SLV-04` (KDS 49 vs OSS 1 count divergence). A fix designed against an unverified claim is forbidden.
- Open all **Owner Gates** (§6) — the A/B product decisions gate the design of ~10 findings.
- **Checkpoint**: baselines recorded in `reports/test-e2e/pre-cloud/W1-BASELINE.md`; 5 live-verifies resolved (confirmed/refuted); owner gates dispatched.

### W2 — Operator / Receipt NF525 cluster  *(7 findings · highest cross-report leverage)*
The `ReceiptDataService.php:70` operator-identity root + receipt-print chain (`ReceiptComponent.vue` ×3, reprint path). Fixes the cross-report duplicate (M11-01 ⊕ S11-02 ⊕ S16-01). NF525 attestation mandatory. Note heal `6b26e1be3` exists but is NOT on this branch — verify whether to cherry-pick or re-derive.
- **Acceptance**: PHPUnit Feature asserting receipt `operator_name` = the authenticated cashier (creator), not `order->user`; for kiosk→counter orders, operator = the collecting cashier; live receipt capture shows the real name. Cite `tests/Feature/Pos/` + a `to-be-created` `tests/Feature/Receipt/OperatorIdentityTest.php`.

### W3 — Money & Fiscal  *(27 findings · NF525-adjacent)*
`OrderService.php` 6-finding spine + `ZReportService` netting/bucketing + `PaymentService` counter-collect + split-tender Z mis-bucket + TVA basis. Several touch frozen fiscal services → those route to §5 quarantine; the non-frozen majority proceed here.
- **Acceptance**: per finding, fiscal PHPUnit (Z netting, seq gap-free, split-tender bucketing) + `fiscal:verify-chain --all` appended-only + frozen diff = 0. Cite `ZReportDiscountNettingTest` (proven 5/5), `tests/Feature/Cash/`, `tests/Feature/Coupon/`.

### W4 — Security & branch-isolation  *(7 findings)*
`CustomerService::show` PII guard (S10-01), KioskMachine branch-scope (S8-02), Employee RBAC (S11-03), CustomerAddress (S10-02), push-notification scope (S13-04), language/loyalty-setup authz, App-Debug toggle write (S7-03, also an owner gate).
- **Acceptance**: branch-isolation PHPUnit (403/scoped for branch_id>0) + adversarial RED replay. Cite `tests/Feature/BranchIsolationTest.php`, `tests/Feature/AddressSecurityTest.php`, `tests/Feature/CouponSecurityTest.php`, `to-be-created` `tests/Feature/Customer/CustomerShowAuthzTest.php`.

### W5 — Logic-blur  *(74 findings · the bulk)*
Every logic/functional/perf finding not already in W2–W4: wizard required-step gates, parked-recall dropped lines, supplements under-billing, offline-replay loss, dashboard date filters, OSS/KDS divergences, delivery totals. Cluster by file root; each root = one remediation unit. This is where "lots of blurs / unnecessary things" (owner's words) get burned down.
- **Acceptance**: per category-pattern (§3). Frontend findings fire the visual gate. RED dispute mandatory before each cluster closes.

### W6 — UX & Visual  *(31 findings)*
FR-currency inconsistency, copy/i18n, empty/error states, layout. Several sit in frozen `pos-wizard.js` (currency formatting) → §5 quarantine; the rest proceed. Owner sign-off on subjective wording.
- **Acceptance**: §6 visual gate, two consecutive clean captures per surface; i18n key assertions for copy.

### W7 — Deadcode & config cleanup  *(10 findings)*
Dead routes/buttons/symbols + stale config comments (e.g., the `pos.manual_discount_enabled` "OFF until F1" comments now that F1 is fixed — owner-gate the *intent* first, §6 Gate A).
- **Acceptance**: grep 0-residual-refs + full suite green + frozen diff = 0.

### W8 — Deeper audit of "what's left" + final pre-cloud go/no-go
Not a fix wave — the **coverage-closing** wave (§7). Audit the 147 RED-flagged gaps + the unaudited auth surface + the dine-in table, surface any NEW finding, fold it into the right wave, then run the final convergence + go/no-go (§8).

**Wave-parallelism**: W2→W7 sequential (shared roots). W8's audit phase fans out parallel (read-only). Frozen quarantine (§5) is owner-gated and may run last or interleaved only under a LOCK doc.

**Interrupt-resume**: at every wave boundary, write `reports/test-e2e/pre-cloud/INTERRUPT_<wave>_<ts>.md` (last green SHA, last task status, next task) + update `PROJECT_BRAIN.md §2`. On resume: read manifest → `git status` → 1-task smoke → continue.

---

## §5 — Frozen-touching quarantine (15 findings: 10 hard-LOCK + 5 referenced)  🔒

Two distinct cases. Treat them differently — conflating them either over-blocks (referenced) or under-protects (hard) a frozen zone.

### §5a — HARD-frozen (10): the fix lands IN a CLAUDE.md §7 file → `/lock-plan` doc + owner countersign + triple-green MANDATORY
| LOCK group | Findings | Frozen file (primary root) | Required artifact |
|---|---|---|---|
| `LOCK_POSWIZARD_*` | M3-01, M3-02, M3-03, M3-05, LV-03, LV-04, LV-05 | `public/js/pos-wizard.js` (+ css/blade) | Owner countersign; wizard is "design parfait" — most are *logic/currency* fixes inside the frozen file → first try a non-frozen shim (e.g. format upstream), else LOCK. |
| `LOCK_ZREPORT_*` | M6-002, M12-004 | `app/Services/Fiscal/ZReportService.php` | NF525 chain attestation (§0.6) + LOCK; netting already proven by F1 — verify these aren't already covered before touching. |
| `LOCK_ORDERSM_*` | M12-003 | `app/Domain/Order/OrderStateMachine.php` | LOCK; state-transition test proving no regression. |

### §5b — FROZEN-REFERENCED (5): primary root is NOT a §7 file; a §7 file is only *referenced* (the guard / component the fix interacts with). **Fix on the non-frozen side → no LOCK.** LOCK only if the chosen remediation is *forced* into the §7 file.
| Finding | Primary root (where the fix goes) | §7 file referenced | Disposition |
|---|---|---|---|
| M6-001 [P1] | `app/Services/OrderService.php` | `PaymentComponent.vue` | OrderService is NOT frozen — fix here; PaymentComponent only consumes the total. No LOCK. |
| S13-02 [P1] | `app/Services/OrderService.php` | `ZReportService.php` | Fix the `total_tax` basis in OrderService (non-frozen); ZReportService reads it. **NF525-critical** → run §0.6 attestation even though no frozen edit. No LOCK. |
| M10-04 [P2] | `PosComponent.vue` | `OrderStateMachine.php` | The state machine is the *correct guard being bypassed* by the UI shortcut — fix the UI to respect it. No LOCK; RED + visual gate mandatory. |
| S2-02 [P2] | `PosOrderShowComponent.vue` | `OrderStateMachine.php` | Same: dropdown must respect `allows()`. Fix the Vue dropdown. No LOCK; RED + visual gate. |
| M6-006 [P3] | `appService.js` (`floatNumber`) | `PaymentComponent.vue` | Bug surfaces in the frozen PaymentComponent numpad, but a `floatNumber` sanitizer fix in appService (non-frozen) closes it without touching the frozen file. Prefer the shim; LOCK only if it must enter PaymentComponent. |

**Rule**: always prefer a scope-minimal fix *outside* the frozen file (caller / config / shim). Only when genuinely impossible → LOCK doc with before/after, owner countersign, separate commit tagged `[LOCK_*]`. A §5b finding that ends up needing a frozen edit is promoted to §5a at that point.

---

## §6 — Owner gates (WHO / WHAT / WHERE + A/B decisions)

Product/intent decisions that gate the *design* of findings. Claude cannot self-decide these. While PENDING, the dependent findings stay in design-hold; independent waves proceed.

| Gate | Decision (A/B) | WHO | WHAT unblocks | WHERE proof | Blocks |
|---|---|---|---|---|---|
| **G-A Discount intent** | Manual POS discounts: **A)** keep ON (default `true` is intended) → fix comments only; **B)** OFF until policy → flip default + guard | Owner | One-line intent | commit msg + `config/pos.php` | W7 deadcode (ESC-01, comment drift), several P3 |
| **G-B OSS counter-orders** | Counter/POS orders on OSS: **A)** show **B)** exclude (kiosk+takeaway only) | Owner | allowlist decision | `OrderStatusScreenOrderService` test | W5 OSS findings |
| **G-C Dine-in** | Dine-in/tables: **A)** keep (gate the public QR endpoint) **B)** remove in V1 | Owner | keep/remove | feature-flag + S17 findings | W5/W8 dine-in audit |
| **G-D Fiscal identity UI** | Branch SIRET/TVA/legal: confirm `foodking:set-branch-legal` values are final for Le Cayenne (verified populated: SIRET 10417050100019, TVA FR19104170501, E.DELICE SAS) | Owner | confirm final | branch row | W2 receipt legal block |
| **G-E App-Debug toggle** | Admin Site toggle writes `APP_DEBUG=true` to `.env` (prod-boot footgun): **A)** remove the toggle **B)** restrict to non-prod | Owner | remove/restrict | S7-03 fix | W4 security |
| **G-F TPE branch assignment** | TPE created via Settings as admin → `branch_id=0` invisible to caisse: assign existing terminals to `branch_id=1`? + the **separate terminal-supplier onboarding** the owner asked about (interrupted Goal B) | Owner (physical) | supplier email + terminal serials + assign branch | S8 findings + Goal B package | W4 + a standalone task |
| **G-G Frozen LOCKs** | Countersign each `/lock-plan` in §5 | Owner | signature per LOCK | LOCK doc §10 | §5 quarantine |

> **G-F note** — the owner's earlier interrupted request (re-associate the borne + counter terminals under his name, get the supplier email + documents + step-by-step physical actions) is **still open** and is the natural companion to S8. It is *not* part of code remediation — flag it as a standalone owner-facing deliverable to revisit when he returns to it.

---

## §7 — Deeper-audit plan for "what's left" (W8 — go as deep as possible)

The owner asked to "go as deep as possible to validate… what is left." Coverage is not yet total. W8 closes it.

### §7.1 The 147 RED-flagged coverage gaps
The adversarial pass flagged 147 areas the per-micro-system auditors did not fully reach (listed per-system in both reports' "Coverage gaps" sections). W8 re-audits these in parallel read-only fan-out (one agent per micro-system's gap list), applies the same existence+necessity+self-audit discipline, and folds any NEW confirmed finding into the correct wave. **Silent-truncation ban**: any gap left un-audited at W8 close is logged explicitly, not implied-covered.

### §7.2 The unaudited **auth surface** (biggest hole)
Neither campaign audited authentication. Targets (confirmed present):
- Frontend: `resources/js/components/frontend/auth/*` — `LoginComponent`, `SignupPhone/Verify/Register`, `GuestLogin/Verify`, `ForgetPassword`, `ResetPassword`, `VerifyEmail` (9 components).
- Backend: `app/Http/Controllers/Auth/{LoginController, ForgotPasswordController, KioskMachineLoginController}.php`.
- **Why it matters pre-cloud**: cloud exposes auth to the public internet (vs single-box LAN). Rate-limiting, token TTL (Sanctum `kiosk:order` 480min — V1.0.1 roadmap wants 1h), password-reset token entropy/expiry, guest-login abuse, branch-scope on login redirect — all unproven.
- **Acceptance**: dedicated audit micro-task → PHPUnit in `tests/Feature/Auth/` (existing dir) + `to-be-created` rate-limit + token-TTL tests + Playwright capture of each auth screen (no raw label / layout). Treat as a **mini-campaign** mirroring the box/systems method.

### §7.3 Dine-in / tables
`pos.dine_in_enabled` was NOT found by grep in `config/pos.php` (memory says it's a feature flag = false). The flag's actual location is uncertain → W8 confirms where dine-in is gated, whether the S17 public QR-table endpoint is reachable when dine-in is disabled, and feeds Gate **G-C**.

### §7.4 Cloud-specific pre-flight (read-only, surfaces only — not cloud execution)
Per `reference_no_cloud_until_owner_initiates`, do NOT propose cloud actions. But the deeper audit *records* the V1-LOCAL→cloud risk surface so remediation doesn't have to be re-done in the cloud: cache-driver guard width (`file`/`database` PASS the guard — UNI-03 backlog), multi-instance `Cache::lock` coherence, remote-printer reachability (ESC/POS TCP:9100 → hybrid local-node), `APP_DEBUG`/boot-guard posture. These are **noted in W8's report**, not acted on, until the owner says "go production".

---

## §8 — Convergence criteria & pre-cloud go/no-go

### §8.1 Rejection rules (any one trigger = REJECT + heal; `ultra-architect-planify` Axis 6)
- Any raw label on a screenshot (`kiosk.X`, `Label.foo`, `0undefined`) → REJECT.
- Any layout break / console error on any tested viewport → REJECT.
- Any frozen-zone diff line without a §5 LOCK → REJECT/revert.
- Any P0/P1 from RED not addressed → REJECT.
- Any acceptance criterion lacking a named test path → REJECT the plan section.
- Any "almost works / good enough" → REJECT (production-perfect or block).
- NF525 chain hash changed unexpectedly (delete/truncate) → REJECT + **human gate immediately**.
- Two consecutive cycles with **differing** findings sets → NOT converged, loop (flake guard).

### §8.2 Definition of done (the "strictly zero" the owner asked for)
1. All 156 findings either GREEN (per §3 pattern, evidence on disk) or owner-deferred with written rationale.
2. Frozen quarantine (§5): each touched file either untouched (diff = 0) or under a countersigned LOCK with triple-green.
3. NF525: `fiscal:verify-chain --all` + `fiscal:assert-chain-clean` pass; chain appended-only; `fiscal:verify-z-membership` clean.
4. W8 deeper audit closed: 147 gaps audited-or-logged, auth surface audited, dine-in resolved, no NEW P0/P1 open.
5. Two consecutive full convergence cycles: P0+P1 = 0, identical findings set, 29-page admin walk = 0 console / 0 raw label, all sentinels 100% exact count.
6. `PROJECT_BRAIN.md` §2/§3/§7 updated; documentation checkpoint committed (no push w/o owner).

### §8.3 Pre-cloud go/no-go verdict
GO to cloud **only** when §8.2 1–6 all hold. Otherwise NO-GO with the exact open list. Cloud cutover is a *separate* owner-initiated mission (Track A lift-shift VPS dossier already exists: `reports/cloud-readiness/CLOUD_MIGRATION_DOSSIER_2026-06-04.md`) gated additionally by GATE-1 hardware LAN reachability + GATE-2 encaissement — both **outside** this remediation's scope.

---

## §A — References & launch protocol

- **Companion catalog (per-finding worklist)** : `reports/audit/pre-cloud-2026-06-04/PRE_CLOUD_REMEDIATION_CATALOG.md` / `.json` — 156 rows, grouped by wave, with correction + validation pattern.
- **Source audits** : `reports/audit/pos-box-massive-2026-06-04/` · `reports/audit/systems-massive-2026-06-04/` (reports + MACHINE.json + captures + findings).
- **Per-task pipeline** : `~/.claude/skills/ultra-audit-profond/` · **army map** : `ultra-architect-planify` Axis 4 · **gates** : `superpower-gstack` references.
- **Frozen list** : `memory/reference_frozen_zones.md` + CLAUDE.md §7. **NF525** : CLAUDE.md §8. **Cloud dossier** : `reports/cloud-readiness/CLOUD_MIGRATION_DOSSIER_2026-06-04.md`.
- **Cross-session memory** : `project_pos_box_massive_audit_2026-06-04` (covers both audit campaigns).

### Launch protocol (when owner says "go")
1. Owner authorizes → create worktree, branch `plan/pre-cloud-remediation`.
2. Run **W1** (baselines + 5 live-verifies + dispatch owner gates). Do NOT start W2 until W1 checkpoint green + gates answered for W2's dependents.
3. Per wave: cluster by root → `ultra-audit-profond` per cluster → RED dispute → visual gate → NF525 attestation if fiscal → checkpoint → interrupt-manifest → BRAIN update.
4. Frozen findings: pause for `/lock-plan` + owner countersign (§5).
5. W8 deeper audit → final convergence ×2 → go/no-go verdict.

---

## §F — Final rule
The mission is **strictly zero defect before cloud** — production-perfect, not "almost there". Every finding gets evidence (technical + visual +, where fiscal, chain attestation). Nothing here is executed until the owner explicitly authorizes; this document and its catalog are the *prepared* campaign, complete and launch-ready, waiting on the word "go".
