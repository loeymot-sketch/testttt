# ULTRA-PLAN V1.0.2 HARDENING — 2026-05-25

> **Trigger** : owner `/ultra plan decompose et planify to correct the problems with max intelligence and audit and following plan with max discipline and goal`
> **Source** : 8 backlog findings from `reports/ultra-review-goal-2026-05-25/CONVERGENCE_HEAL.md`
> **Branch** : `heal/cms-pr1-quickwins-2026-05-18`
> **HEAD baseline** : `afc094091`

---

## 1. NORTH STAR (mandate non-négociable)

1. **0 frozen-zone touches** — CLAUDE.md §7 absolu sur 12 paths
2. **NF525 chain preserved bit-identical** — pas de DELETE/UPDATE sur audit_logs/z_reports, append-only
3. **0 new P0/P1 introduced** — chaque wave validée par adversarial
4. **Scope-minimal LOC per fix** — pas de refactor opportuniste
5. **Backend defense-in-depth pattern** — Resource layer prefer Vue layer where possible
6. **Convergence GREEN** — identical findings sets 2 rounds consécutifs
7. **Frozen-zone diff = 0** vérifié post-chaque-commit
8. **AUTO MODE compatible** — execute waves sans pause pour clarification, but rollback-ready

---

## 2. SCOPE — 8 findings → 4 waves

### IN scope (V1.0.2 backlog cleared)

| ID | Sev | Owner Source | Wave |
|----|-----|--------------|------|
| UR4-002 | P1 | RED-team | A (Vuex localStorage sentinel) |
| UR4-003 | P1 | RED-team | A (KDS non-card tel: links) |
| UR4-008 | P1 | RED-team | A (paper receipts NF525 archive) |
| UR1-002 | P2 | Architect | B (SSOT helpers/phoneDisplay.js) |
| UR1-003 | P1 | Architect | B (5 bundle staleness sentinels) |
| UR4-007 | P2 | RED-team | C (NF525 deploy guard + scripts) |
| UR3-A1 | P2 | A11y | D (profile dropdown ARIA + keyboard) |
| UR3-U1 | P3 | UX | D (ProfileEdit phone placeholder) |

### OUT scope (explicitly NOT touched this cycle)

- **NF525 chain breach id=34** — owner-gate operation, append-only invariant. C wave adds guard for **future** prod, mais ne resoudra pas le breach local. Owner decides when/if.
- **V2 SaaS multi-tenant** — explicit owner mandate "no cloud until initiates"
- **Frozen zones** — PaymentComponent, PosV5TrancheRow, kiosk wizard, pos-wizard.js+css, fiscal services
- **i18n EN/AR** — V1 LOCAL Le Cayenne FR-only mandate

---

## 3. WAVE DECOMPOSITION

### 🌊 WAVE A — Browser storage + NF525 paper trail (P1 critical-archive)

**Why first** : touches user-visible + NF525 6-year paper archive surfaces. Maximum business risk if delayed.

**A1 (UR4-002)** — Vuex localStorage sanitize PENDING_*
- File: `resources/js/store/index.js` (or `store/modules/auth.js` if separate)
- Action: when `vuex-persistedstate` rehydrates `auth.authInfo`, sanitize `phone` field through PhoneDisplay-equivalent JS helper
- Read-write pattern, not in-place
- Risk R-A1: breaking login persistence — mitigate by sanitize-on-rehydrate AND sanitize-on-commit

**A2 (UR4-003)** — KDS non-card panels with `tel:` links
- Files (TBD via grep) :
  - `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (and sub-panels)
  - `OnlineOrderShowComponent.vue` if has tel:
  - `TableOrderShowComponent.vue` if has tel:
- Action: guard `tel:{{ phone }}` href + display via PhoneDisplay frontend helper

**A3 (UR4-008)** — Paper receipts NF525 archive 6 ans
- Files :
  - `resources/js/components/admin/onlineOrders/OnlineOrderReceiptComponent.vue`
  - `resources/js/components/frontend/order/FrontendOrderReceiptComponent.vue` (or similar)
  - Check Blade receipt templates `resources/views/admin/.../*.blade.php`
- Action: skip phone display when PENDING_ ; print stylesheet `@media print` verification

**Wave A execution**
- 3 sub-agents in parallel (A1+A2+A3)
- Each agent: read target files, apply scope-minimal, capture proof, commit
- Convergence after all 3 land: full smoke + adversarial pass

**Acceptance**
- A1: `localStorage.getItem('vuex')` post-login contains NO `PENDING_` substring
- A2: DOM grep no `tel:PENDING_` malformed hrefs
- A3: Receipt rendered with empty phone line or `<p v-if>` collapsed

---

### 🌊 WAVE B — Dev discipline + SSOT (P1+P2)

**Why after A** : SSOT helper from B1 will inform any A redo / further heals.

**B1 (UR1-002)** — Extract `helpers/phoneDisplay.js` frontend SSOT
- New file: `resources/js/helpers/phoneDisplay.js`
- Export: `safePhone(phone)` mirror PHP `App\Support\PhoneDisplay::safe`
- Replace 4 inline ternaries from GOAL Phase A in :
  - BackendNavbarComponent.vue
  - MessageListComponent.vue
  - CreditBalanceReportComponent.vue
  - ProfileEditProfileComponent.vue
- Plus the Wave A new touch-points (KDS panels, receipts)
- Risk: minimal, pure refactor with mirror existing behavior

**B2 (UR1-003)** — Bundle staleness sentinels coverage gap
- Q12 sentinel exists at `tests/js/sentinels/kdsBundleFreshnessSentinel.spec.js`
- Add 4 sibling sentinels :
  - `adminShellBundleFreshnessSentinel.spec.js`
  - `adminReportsBundleFreshnessSentinel.spec.js`
  - `posAppBundleFreshnessSentinel.spec.js`
  - `appBundleFreshnessSentinel.spec.js`
- Each reuses `globalThis.__assertBundleFresh` helper already exported
- CI : add to vitest config if not auto-discovered

**Wave B execution**
- Sequential: B1 first (helper available before sentinels can verify it bundled)
- Then B2 (1 agent, 4 sibling specs)
- Convergence after both: vitest run all sentinels green

**Acceptance**
- B1: `helpers/phoneDisplay.js` exists, ≥4 components import it, inline ternaries removed
- B2: 5 sentinel specs (kds + 4 new) pass via `npx vitest run sentinels`
- B2 negative test: temporarily delete `startsWith('PENDING_')` from 1 bundle, sentinel fails (manual prove during dev)

---

### 🌊 WAVE C — NF525 deploy guard + Hetzner deploy scripts (P2 critical-cutover)

**Why after B** : foundational scripts for owner go-cloud. Independent of A+B technical changes.

**C1 (UR4-007 part 1)** — `fiscal:assert-chain-clean` artisan command
- New file: `app/Console/Commands/FiscalAssertChainCleanCommand.php`
- Signature: `fiscal:assert-chain-clean {--branch=1} {--all}`
- Wraps `AuditLogService::verifyChain` + `ZReportService::verifyChain`
- Exit codes :
  - 0 = chain clean both NF525 chains
  - 1 = chain tampered (one or both)
  - 2 = invalid args
  - 3 = exec error
- Difference from `fiscal:verify-chain` (existant) : this command **refuses to start** if any tamper, designed to be `set -e` deploy-safe

**C2 (UR4-007 part 2)** — `scripts/deploy/pre-flight.sh`
- Bash script — pre-deploy gate checks :
  1. `php artisan fiscal:assert-chain-clean --all`
  2. `php artisan app:preflight-production` (existing)
  3. `php artisan fiscal:verify-chain --branch=1`
  4. Verify all `tests/Feature/Sentinels/*` green
  5. Frozen-zone manifest diff vs ALLOW_LIST
  6. Vendor dir clean (no .git)
- Exit non-zero on any fail

**C3 (UR4-007 part 3)** — `scripts/deploy/deploy-hetzner.sh` skeleton
- Bash — actual deployment :
  - rsync code (excluding .git, node_modules, vendor/laravel/sail dev deps)
  - SSH remote: php artisan migrate --force
  - SSH remote: php artisan bundle:rebuild (npx mix --production)
  - SSH remote: php artisan queue:restart
  - SSH remote: php artisan optimize
  - SSH remote: php artisan fiscal:assert-chain-clean
  - Health check curl
- `--dry-run` flag mandatory for V1.0.2 (no actual prod yet)

**C4 (UR4-007 part 4)** — `scripts/deploy/server-setup-hetzner.sh` skeleton
- Bash — first-time Hetzner CX22/CX32 provisioning :
  - apt update + nginx + php8.x + mysql + redis
  - certbot + lecayenne.fr SSL
  - User/group setup
  - Permissions
  - Cron jobs (fiscal:close-all-active-branches, foodking:backup-daily)
  - Systemd queue worker
- `--dry-run` mandatory V1.0.2

**Wave C execution**
- 4 sub-agents in parallel (C1+C2+C3+C4 mostly independent)
- C1 has 1 artisan command, runs unit test
- C2/C3/C4 are bash scripts, all `--dry-run` only this cycle (no real prod)
- Convergence after 4 land: each script `--dry-run` exits 0

**Acceptance**
- C1: `php artisan fiscal:assert-chain-clean` exits 1 on tamper (proven via current id=34 breach) ; exits 0 on clean
- C2: `bash scripts/deploy/pre-flight.sh` diagnoses + non-zero exit on existing tamper
- C3: `bash scripts/deploy/deploy-hetzner.sh --dry-run` prints intended steps + exits 0
- C4: `bash scripts/deploy/server-setup-hetzner.sh --dry-run` same

---

### 🌊 WAVE D — A11y + UX polish (P2+P3)

**Why last** : visible polish layer, lower technical risk, validate via Playwright A11y MCP.

**D1 (UR3-A1)** — Profile dropdown ARIA + keyboard
- File: `resources/js/components/layouts/backend/BackendNavbarComponent.vue`
- Add :
  - Trigger button: `aria-haspopup="menu"`, `aria-expanded` (dynamic)
  - Dropdown container: `role="menu"`
  - Menu items: `role="menuitem"` + `tabindex` management
  - Keyboard: Esc closes, Arrow up/down navigates, Enter activates
  - Focus trap when open, restore to trigger on close
- Library option: use existing dropdown.js helper if it supports ARIA, otherwise vanilla addEventListener

**D2 (UR3-U1)** — ProfileEdit phone placeholder
- File: `resources/js/components/admin/profile/ProfileEditProfileComponent.vue`
- Add `placeholder="+33 6 12 34 56 78"` on phone input
- Trivial 1-LOC fix

**Wave D execution**
- 2 sub-agents parallel (D1+D2 independent)
- D1 agent has more work (ARIA + keyboard), D2 trivial
- Convergence: axe-core scan profile dropdown 0 critical issues

**Acceptance**
- D1: axe-core report 0 critical/serious issues on dropdown ; Playwright keyboard test (Tab, Esc, Arrow keys) functional ; aria-expanded toggles correctly
- D2: placeholder visible on empty input

---

## 4. EXECUTION DISCIPLINE

### LOOP per wave (CLAUDE.md §5)

1. **ORCHESTRATE** — read PROJECT_BRAIN + this plan + relevant docs
2. **PLAN** — wave-specific dispatch strategy (parallel vs sequential)
3. **EXECUTE** — scope-minimal sub-agents + capture evidence
4. **AUDIT** — re-read each diff, frozen-zone check
5. **TEST** — PHPUnit filter + Vitest filter + frozen-zone diff
6. **VISUAL TEST** — Playwright capture for any Vue/CSS touched
7. **SELF-CORRECT** — max 3 heal cycles per finding, escalate above
8. **UPDATE BRAIN** — final convergence in PROJECT_BRAIN §3

### Frozen-zone gate (each wave)

```bash
git diff <wave-start-sha>...HEAD --name-only | grep -E "PaymentComponent|PosV5Tranche|KioskWizard|KioskApp|KioskUpsell|pos-wizard|FiscalSequence|ZReport|AuditLogService|BranchScope|IdempotencyKey|PricingService|OrderStateMachine"
```
Expected: empty. Non-empty = STOP + revert.

### NF525 chain gate (each wave)

```bash
php artisan fiscal:verify-chain 2>&1 | head -5
```
Expected: same status as wave-start (pre-existing breach id=34 preserved, no new breaches).

### Test-e2e per wave

- Wave A: Playwright capture admin login → profile dropdown / KDS / receipt preview (3 surfaces)
- Wave B: vitest sentinels run + grep imports
- Wave C: bash `--dry-run` each script + assert exit 0
- Wave D: axe-core scan + keyboard nav recording

### Commit cadence

- 1 commit per finding sub-cluster (e.g., A1 = 1 commit, A2 = 1 commit, A3 = 1 commit, A-converge = 1 commit)
- Final commit per wave: convergence report
- Total expected commits: 8-12 finer than 1 big-bang

---

## 5. EVIDENCE REQUIREMENTS

Per CLAUDE.md §13 :
- PHPUnit / Vitest verts (filter sur module touché)
- Frozen-zones diff = 0 (mandatory)
- Playwright PNG **lus via Read tool** (multimodal verify)
- DOM grep proofs (e.g., no `tel:PENDING_`, no `PENDING_` in localStorage)
- Adversarial sub-agent dispute pass each wave

---

## 6. RISK REGISTER

| ID | Risk | Wave | Likelihood | Mitigation |
|----|------|------|------------|------------|
| R-A1 | Vuex sanitize break login persistence | A1 | Low | Read-then-rewrite pattern ; smoke test login/refresh |
| R-A2 | KDS layout regression on tel: change | A2 | Low | Visual test KDS round-2 isolated post-touch |
| R-A3 | Receipt print stylesheet regression | A3 | Medium | Playwright `@media print` emulation + manual receipt sample |
| R-B1 | Helper extract breaks 4 existing components | B1 | Low | Per-component visual test |
| R-B2 | Sentinel false-positive on fresh dev rebuild | B2 | Medium | Sentinel reads bundle mtime vs source mtime tolerance ±2s |
| R-C1 | New artisan command conflicts existing one | C1 | Very low | grep `fiscal:assert` 0 match before create |
| R-C2..C4 | Deploy scripts assume Hetzner-specific paths | C2-C4 | High but isolated (--dry-run V1.0.2) | Owner reviews before actual prod use |
| R-D1 | ARIA changes interfere existing dropdown.js | D1 | Medium | Add ARIA passively without removing existing logic |
| R-D2 | Placeholder hides existing required-field UX | D2 | Very low | Visual check |

---

## 7. ROLLBACK PLAN

Each wave = identifiable in git log :
```
git log --grep="V1.0.2 Wave A"
git log --grep="V1.0.2 Wave B"
git log --grep="V1.0.2 Wave C"
git log --grep="V1.0.2 Wave D"
```

Owner can:
- Revert per wave: `git revert <wave-converge-commit>`
- Revert per finding: `git revert <finding-commit>`
- Roll back to GOAL final: `git reset --hard ee7aef899` (last GOAL convergence)
- Roll back to ultra-review heal: `git reset --hard afc094091` (current HEAD pre-V1.0.2)

DB rollback: NO DB touches in V1.0.2 plan (all code/scripts only). audit_logs untouched. Permissions untouched.

---

## 8. CONVERGENCE CRITERIA (final ship V1.0.2)

✅ All 8 findings closed via commits identifiable
✅ NF525 chain status unchanged (id=34 pre-existing only, no new breach)
✅ Frozen-zone diff = 0 LOC over entire V1.0.2 cycle
✅ Final test-e2e 7 surfaces GREEN
✅ Adversarial round 2 finds 0 new P0/P1 (or only documented backlog)
✅ Vitest sentinels green
✅ PHP lint + PHPUnit smoke green
✅ Convergence report `reports/test-e2e/v1-0-2-hardening-2026-05-25/CONVERGENCE_FINAL.md`

---

## 9. WAVES TIMELINE (estimated)

| Wave | Parallel agents | Wall-clock estimate | Cumulative |
|------|-----------------|---------------------|------------|
| A | 3 | 25-40 min | 40 min |
| B | 1 then 1 (sequential) | 15-25 min | 65 min |
| C | 4 | 30-50 min | 115 min |
| D | 2 | 15-25 min | 140 min |
| Final convergence | 1 smoke + 1 adversarial | 15-20 min | 160 min |

**Total estimated** : 2h30 - 3h wall-clock under autonomous AUTO MODE execution.

---

## 10. NEXT STEP (post-plan)

**Default execution path** (AUTO MODE) :
1. Begin Wave A immediately (3 parallel sub-agents)
2. Convergence + commit
3. Begin Wave B (sequential B1→B2)
4. Begin Wave C (4 parallel)
5. Begin Wave D (2 parallel)
6. Final test-e2e + adversarial
7. Convergence report + master commit
8. Update PROJECT_BRAIN §3 LAST DONE

**Owner override path** :
- Owner can interrupt at any wave boundary
- Owner can request partial wave (e.g., "only A + D, skip C")
- Owner can request iteration cap (default unlimited)

---

## 11. AUTHORS + REFERENCES

- **Plan author** : Claude Opus 4.7 1M (GOAL MODE post-Ultra-Review)
- **Source findings** : `reports/ultra-review-goal-2026-05-25/UR-1-*.json` + `UR-2` + `UR-3` + `UR-4`
- **Convergence heal** : `reports/ultra-review-goal-2026-05-25/CONVERGENCE_HEAL.md`
- **Project doctrine** : `CLAUDE.md` (§5 LOOP, §7 frozen-zones, §8 NF525, §13 evidence)
- **PROJECT_BRAIN.md** : §1 NORTH STAR, §3 LAST DONE (à update post-Wave-D)
