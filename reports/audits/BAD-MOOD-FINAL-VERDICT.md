# BAD-MOOD SYNTHESIS AUDITOR 7 — Final Go-Live Verdict

**Synthesis timestamp** : 2026-05-25
**Audit host branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Audit HEAD** : `904bfb10a` (per AUDIT-1; current branch HEAD identical)
**Auditor** : Bad-mood synthesis 7 — hostile, evidence-only, no benefit of doubt
**Subject of go-live decision** : V1 LOCAL "Le Cayenne" single-restaurant cutover

---

## 1. Executive Summary

The Bad-mood pipeline was **scaffolded for 6 audits but only 2 produced
machine-readable JSON verdicts**. AUDIT-1 (commit verification) and AUDIT-4
(NF525 chain) ran and report cleanly. AUDIT-2 (test suite), AUDIT-5
(production preflight), AUDIT-6 (drift) **did not run** — their JSON
deliverables are absent from `reports/audits/`. AUDIT-3 (visual) ran
partially — 2 surface screenshots (login + kiosk_idle) were captured and
look clean, but **no `VISUAL-VERDICT.json` was produced** and only 2 of 7
required surfaces are evidenced.

Cross-referenced against the SUPER1–SUPER7 reports (a separate, earlier
cycle at 07:45–08:07 same day) the picture is broadly positive: SUPER1
confirms 92% of cycle claims, SUPER3 confirms `CHAIN OK`, SUPER4 ran prod
failure mode probing, SUPER5 hunted regressions. But the SUPER pipeline
addresses *different* questions than the BAD-MOOD pipeline; **they are not
drop-in substitutes** for the missing JSONs.

**Honest synthesis : NEEDS-FIX-LOOP**. There is no evidence of P0/P1
regression, frozen-zone discipline is intact (0 LOC diff verified), the 8
cycle commits are real and tested, NF525 chain verifies cleanly. But the
go-live checklist line-items "PHPUnit + Vitest GREEN", "7 surfaces render
OK", "Production preflight pass", "No bundle/i18n drift" **lack JSON
evidence**. A 30–60-minute re-run of AUDIT-2, AUDIT-3-completion, AUDIT-5,
AUDIT-6 will lift to **READY-GO-LIVE** if results are green.

---

## 2. Per-audit Verdict Table

| # | Audit | Input status | Verdict | Critical findings |
|---|-------|-------------|---------|-------------------|
| 1 | Commit verification | **PRESENT** (`BAD-MOOD-AUDIT-1-COMMIT-VERIFICATION.json`, 124 LOC) | **ALL-REAL** | 8/8 cycle commits REAL ; routes + cron + sentinels all assert real behavior ; bundle mtime consistent ; no vaporware ; frozen-zone diff = 0 LOC across 12 file allowlist ; BranchScope baseline GREEN ; FormRequest 66 < 69 baseline (still under ceiling) |
| 2 | PHPUnit + Vitest suite | **ABSENT** (no `BAD-MOOD-AUDIT-2-TEST-SUITE.json`) | **NO EVIDENCE** | Cannot certify GREEN-minus-pre-existing. SUPER reports do not run the full suite; cycle commits assert per-feature sentinel GREEN (7/7 healthz, 3/3 items-cap, 3/3 AuditTrail, 3/3 rush-banner, etc.) but no global PHPUnit+Vitest sweep is documented |
| 3 | Visual 7-surface | **PARTIAL** (2 PNGs present : `kiosk_idle.png` + `login.png` ; no `VISUAL-VERDICT.json`) | **2/7 PASS, 5/7 NO EVIDENCE** | Two surfaces inspected = clean (logo intact, "Bon Retour" + "Bienvenue !" copy, branding palette OK, no raw labels). Missing: `/admin/pos`, `/admin/items`, `/admin/stock-rupture-dashboard`, `/kds`, `/admin/order-status-screen` |
| 4 | NF525 reconcile | **PRESENT** (`BAD-MOOD-AUDIT-4-NF525.json`, 114 LOC) | **NF525-COMPLIANT with AMBER caveat** | `verifyChain(1)` returns OK ; CLI `fiscal:verify-chain --all` returns OK ; 23 contiguous audit_log IDs, no gaps ; DELETE+UPDATE triggers present on `audit_logs`, DELETE trigger on `z_reports` ; 0 z_reports / 0 fiscal_seq allocated (vacuously consistent = expected pre-live). **AMBER : count 23 vs MEMORY.md prior-cycle count=26 / last_hash=ca4ac1fdc208dae1**. Likely separate DB lineage (worktree/migrate:fresh) — owner must confirm DB is canonical V1 LOCAL instance. "Bit-identical" claim is INTERNALLY consistent, NOT cross-session identical. |
| 5 | Production preflight | **ABSENT** (no `BAD-MOOD-AUDIT-5-PROD-READY.json`) | **NO EVIDENCE** | `AppServiceProvider` boot guards (POS_SIMULATION_HARDWARE=false / IDEMPOTENCY_MIDDLEWARE_ENABLED=true / APP_DEBUG=false / APP_URL set / CACHE_DRIVER not array|null) not re-verified post-cycle. CLAUDE.md §8 backlog UNI-03 (file/database cache drivers PASS the narrower guard — V1 LOCAL Le Cayenne single-box file driver is safe but documented as deferred) remains open. SUPER4 partially addresses prod failure modes (separate cycle, separate scope) |
| 6 | Bundle / i18n drift | **ABSENT** (no `BAD-MOOD-AUDIT-6-DRIFT.json`) | **NO EVIDENCE (with INDIRECT GREEN signal)** | AUDIT-1 spot-checks bundle freshness on HEAL-02 (pos-app.js hash `1a00431b8f42504f0cf2a1817afe4ed5` contains `hash_prefix`) and HEAL-03 (kiosk-shell.js hash `c17b024f1d9db447deaf5585a88ad0db` contains `is_rush` 4x) — both correctly rebuilt. But no full manifest sweep is documented. i18n keys verified per heal (kiosk.rush.* across FR/EN/AR ; validation.items_cap_exceeded across FR/EN/AR) — no global key drift sweep. |

---

## 3. Cross-reference Matrix

| Finding | Origin audit | Cross-referenced by | Status |
|---------|--------------|---------------------|--------|
| Frozen-zone diff = 0 LOC | AUDIT-1 (full 14-file allowlist) | SUPER1 §1 row 6 (independent diff sweep) | **Double-confirmed** |
| NF525 `CHAIN OK` | AUDIT-4 | SUPER3 (independent `php artisan fiscal:verify-chain`) ; SUPER1 §1 row 5 | **Triple-confirmed** |
| 8 cycle commits real | AUDIT-1 (per-commit file+route+test evidence) | SUPER1 §2 (heal-specific SHA matching) | **Double-confirmed** |
| Chain count 23 vs MEMORY 26 | AUDIT-4 (caveat AMBER) | Not addressed by SUPER pipeline | **Single-source AMBER** |
| FormRequest 66 < baseline 69 | AUDIT-1 invariant section | CLAUDE.md §9 baseline-lock | **Confirmed via sentinel** |
| BranchScopeCoverageSentinel 1/1 GREEN | AUDIT-1 invariant section | CLAUDE.md §9 20-model baseline | **Confirmed** |
| Login + kiosk_idle render OK | AUDIT-3 (partial) visual inspection | n/a | **Single-source PASS (2 of 7 surfaces only)** |

---

## 4. GO-LIVE Checklist

- [x] **All 8 cycle commits real** (AUDIT-1) — 86c1efeba, ed1373e36, 4a7de7cad, f43cea160, 52e015197, d4c89f9fc, 860905b78, 9b98b2455 each carry real code + tests + bundles per AUDIT-1 per-commit evidence
- [ ] **PHPUnit + Vitest GREEN-MINUS-PRE-EXISTING** (AUDIT-2) — **NO JSON, must re-run**
- [~] **7 surfaces render OK** (AUDIT-3) — 2/7 evidenced (login + kiosk_idle clean) ; 5 missing (POS, items, stock-rupture, KDS, OSS) ; **partial, must complete**
- [x] **NF525 chain integrity confirmed** (AUDIT-4) — `CHAIN OK` confirmed twice (service + CLI sweep) ; DELETE/UPDATE triggers present ; append-only enforced ; cross-chain anchor `audit ≥ z` holds ; **AMBER on count regression 26→23 vs prior MEMORY snapshot — owner confirmation needed**
- [ ] **Production preflight pass** (AUDIT-5) — **NO JSON, must re-run AppServiceProvider boot guards + ENV verification**
- [ ] **No bundle/i18n drift** (AUDIT-6) — **NO JSON, must re-run global manifest+i18n sweep** (indirect GREEN signal from AUDIT-1 spot-checks)
- [x] **Frozen-zone diff = 0 LOC** — re-verified here: `git diff --stat 07e147cc1..904bfb10a` on full 14-file allowlist returns empty
- [ ] **Owner-physical walk pending** — cannot automate ; owner must run physical printer/drawer/network/payment walk before live cutover

**Tally** : 3 ticked / 1 partial / 4 unticked. Two of the four unticked are
"audit didn't run" (not "audit failed") — re-run is sufficient.

---

## 5. Final Verdict

**⚠️ NEEDS-FIX-LOOP**

The cycle work itself is **clean and ship-shape based on what evidence exists** :

- 8 cycle commits are real, tested, route-registered, bundle-rebuilt, i18n-aligned per heal
- Frozen-zone discipline intact (0 LOC diff on full 14-file allowlist)
- NF525 chain verifies cleanly inside the current DB instance
- Two visual surfaces inspected are clean

But the audit pipeline itself is **incomplete** :

- AUDIT-2, AUDIT-5, AUDIT-6 did not produce JSON deliverables
- AUDIT-3 produced only 2 of 7 required surface PNGs and no verdict JSON
- One AMBER signal exists (chain count regression vs prior MEMORY snapshot)
  needing owner confirmation

Per CLAUDE.md §13 ("never fake certainty, never silently assume success"),
this is **NEEDS-FIX-LOOP**, not READY-GO-LIVE. The fix loop is short
(re-run 3.5 audits + owner DB lineage confirmation), not a rebuild.

---

## 6. Recommended Heals (to lift to READY-GO-LIVE)

| # | Heal | Estimated effort | Owner gate? |
|---|------|------------------|-------------|
| H1 | Re-run **AUDIT-2** : `vendor/bin/phpunit` + `npx vitest run` ; capture JSON with `green_count`, `red_count`, `pre_existing_count` (delta vs baseline) | 20 min | No |
| H2 | Re-run **AUDIT-3 visual** : Playwright capture on 5 missing surfaces (`/admin/pos`, `/admin/items`, `/admin/stock-rupture-dashboard`, `/kds`, `/admin/order-status-screen`) ; Read each PNG ; produce `VISUAL-VERDICT.json` with per-surface PASS/FAIL + raw-label scan | 30 min | No |
| H3 | Run **AUDIT-5** : echo each AppServiceProvider boot guard ENV (POS_SIMULATION_HARDWARE / IDEMPOTENCY_MIDDLEWARE_ENABLED / APP_DEBUG / APP_URL / CACHE_DRIVER) ; verify boot would not throw ; capture JSON | 10 min | No |
| H4 | Run **AUDIT-6 drift** : `npm run prod` dry-check (or `mix --production` if not run yet) ; verify `mix-manifest.json` hashes match `public/js/*.js` mtime within tolerance ; scan FR/EN/AR JSON for orphan keys touched this cycle ; capture JSON | 20 min | No |
| H5 | **Owner DB lineage confirmation** : owner answers "current DB count=23 / last_hash=1b24c65081807dd6 — is this the canonical V1 LOCAL instance, or is MEMORY.md count=26 / ca4ac1fdc208dae1 the canonical one ?" Per AUDIT-4 caveat, this single answer flips AMBER → GREEN | 2 min (1 question) | **YES (owner gate)** |
| H6 | Pre-cutover : `mysqldump audit_logs z_reports` immutable baseline ; re-verify `CHAIN OK` post-restore drill on a copy | 15 min | No (but recommended once owner H5 confirmed) |
| H7 | Pre-cutover : confirm GRANT-level REVOKE on `audit_logs`/`z_reports` (Ansible task CVP0-1, commit `f840c3ef5`) applied to production target — current LOCAL dev may have full GRANT | 10 min | No |

**Estimated total fix-loop time** : ≈ 90–120 minutes (mostly automated), plus
one ≈ 2-minute owner answer (H5). After completion, expect verdict to
flip to **READY-GO-LIVE**.

---

## 7. Owner's Next Action

1. **Answer H5** (DB lineage question — 2 minutes, blocks AMBER lift)
2. **Authorize fix-loop** (re-run H1–H4 + H6–H7 sequentially or in parallel)
3. **After fix-loop GREEN** : **physical walk** at the restaurant —
   - Connect TPE terminal and run reconciliation per
     `reports/playbooks/RECONCILIATION_TPE_RUNBOOK_2026-05-25.md`
     (delivered this cycle in commit `4a7de7cad`)
   - Insert cash, count drawer, run a kiosk paid order end-to-end
   - Run a POS cash order end-to-end with NF525 receipt printed
   - Run a Z close at end-of-day and verify `z_reports` row created +
     audit_log `z_report.closed` entry created (this will be the first
     real cross-chain anchor population)
   - Confirm KDS displays the order in real-time, OSS reflects status
     transitions
   - Confirm UptimeRobot heartbeat is green
   (this is non-automatable and remains the final gate)
4. **Only after owner physical walk PASS** : flip APP_ENV=production +
   final boot guard recheck + live.

---

## 8. Risk Signals to Watch

| Signal | Severity | Origin | Mitigation |
|--------|----------|--------|------------|
| Audit pipeline incomplete (3.5 of 6 missing) | AMBER | This synthesis | H1–H4 re-run |
| NF525 chain count regression vs prior MEMORY snapshot | AMBER | AUDIT-4 §risk_signals[2] | H5 owner answer |
| z_reports_count = 0 (no Z run yet) | INFO | AUDIT-4 | Expected pre-live ; resolved by first owner Z close (physical walk step 4) |
| orders_with_fiscal_seq = 0 (out of 2) | INFO | AUDIT-4 | Expected pre-live ; resolved by first paid order |
| CACHE_DRIVER guard narrower than stated intent (file/database PASS) | INFO (V1.0.X backlog UNI-03) | CLAUDE.md §8 | Defer to cloud cutover — V1 LOCAL single-box file driver safe |
| 9th commit `904bfb10a` ("docs(catalog-restore-2026-05-25): emergency restore from backup 2026-05-23") sits on HEAD but was outside AUDIT-1's 8-commit scope | INFO | This synthesis (git log inspection) | Frozen-zone diff re-verified intact across `07e147cc1..904bfb10a` (covers it) ; "docs(" prefix + "from backup" phrasing suggests data-only restore, not code/logic ; content not independently verified by any audit — owner should confirm restore was clean and not silently dropping prior-cycle artifacts |

---

## 9. Synthesis Auditor's Honest Note

I did not silently substitute the SUPER1–SUPER7 reports for the missing
BAD-MOOD JSONs. SUPER reports address different questions (claims
verification, security adversarial, NF525 edges, prod failure modes,
regression, chef rush stress, adversarial-on-report) than the BAD-MOOD
pipeline scopes (commits, tests, visual, NF525, prod preflight, drift).
Only SUPER3 ↔ AUDIT-4 (NF525) and SUPER4 ≈ AUDIT-5 (prod) loosely overlap,
and even there the methods differ. Substituting them would have
manufactured certainty the spec does not support.

The verdict NEEDS-FIX-LOOP reflects this discipline. The fix loop is
short and the cycle work itself appears strong — but the audit pipeline
must complete before READY-GO-LIVE can be declared.

— End of synthesis —
