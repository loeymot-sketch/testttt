# WE-3 — SpinBoost Option C Fold-in FoodKing V1.2 Plan — STATUS

**Date:** 2026-05-19
**Wave:** E (post Wave D SpinBoost ultra-deep audit + owner decision 2026-05-19 Option C)
**Branch:** `v1-0-1-hardening-2026-05-17`
**HEAD:** `ce23352ab`
**Master sub-agent:** WE-3 (this agent)
**Discipline:** GStack + Superpowers brainstorming + RED-team adversarial, audit-heavy, NO IMPLEMENTATION
**Mode:** READ-ONLY (plan + 2 specialist JSONs + this STATUS.md only)

---

## 1. Mission recap

WE-3 mission: write a comprehensive V1.2 fold-in plan documenting Option C strategy (vs Option A standalone Next.js), reflecting owner decision 2026-05-19 and re-evaluating the 9 P0 + 14 P1 carry-over findings from Wave D SpinBoost ultra-deep audit against FoodKing infrastructure reuse opportunities.

**Constraint:** READ-ONLY plan only. 0 code changes. 0 commits except docs. Plan size ~28-32 KB target.

---

## 2. Deliverables produced

| File | Size | Purpose |
|---|---|---|
| `plans/SPINBOOST_FOLD_IN_FOODKING_V12_PLAN_2026-05-19.md` | ~32 KB | Main V1.2 fold-in plan (13 sections, all mandatory §1-§8 + §9 acceptance criteria + §10 residual risks + §11 specialist synthesis + §12 deliverables + §13 owner gates) |
| `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/SPIN-ARCH-foldin.json` | ~5.5 KB | Architect specialist read-only audit: 5 P0 architectural concerns + 5 P1 recommendations + reuse path validation |
| `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/SPIN-RED-foldin.json` | ~7 KB | RED specialist adversarial audit: 7 scenarios attacking Option C thesis + 3 sharper edges (TAM/cashier-collusion/DSR) + counter-recommendation Option D defer |
| `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/STATUS.md` | this file | Wave E completion status |

---

## 3. Key findings from this plan write-up

### 3.1 CRITICAL ambiguity surfaced

Task brief §2 said: *"Customer leaves Google Review (verified via Place API) → trigger wheel-spin → reward voucher"* — **this is exactly the P0-1 incentivized review pattern** that ULTRA_REVIEW 2026-05-16 flagged as violating Google policy + DGCCRF + FTC. The Option A pivot (decoupling reward from Google review act) was explicitly the mitigation for that risk. Plan §2.1 surfaces this as the #1 critical decomposition:

- **C-1 variant (RECOMMENDED)** — decoupled, conforme. Spin trigger = post-meal email opt-in. Google review CTA secondary, outlined, non-rewarded, identical for all players. Preserves ~80% product value (gamification + email opt-in + NPS feedback). Eliminates 100% Google policy risk.
- **C-2 variant (NOT RECOMMENDED)** — incentivized, Google review gate. Preserves "Boost" branding but imports P0-1 risk + contaminates FoodKing core brand if Google sanctions.

Plan recommends C-1 by default; C-2 requires explicit owner override + Sprint -1 juridique addendum + 3-month monitoring.

### 3.2 FoodKing infrastructure reuse map verified

Plan §3.2 enumerates specific FoodKing paths reused (advisor §3 sharpening applied):

- `app/Services/Loyalty/PosRedemptionService.php` — extend (not fork) for voucher redemption
- `app/Services/Loyalty/LoyaltyQrSigner.php` — HMAC signing pattern direct-applicable for voucher QR
- `app/Services/Pricing/DiscountCalculator.php` + `app/Enums/DiscountType.php` — voucher rewards as new DiscountType variants
- `app/Services/Fiscal/AuditLogService.php` — chain-sign voucher_issued + voucher_redeemed events
- `app/Models/Scopes/BranchScope.php` — per-branch review_boost_settings
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` — already mounted, covers spin + redeem endpoints
- `app/Services/Idempotency/RedisIdempotencyKeyRepository.php` — Redis-backed idempotency
- `app/Services/Loyalty/LoyaltyService.php` — for loyalty-points reward variant
- `app/Mail/` Laravel Mailable infra — voucher email pattern existing

**Estimated savings:** 12-15 j-humain effectifs (~30-40% reduction vs greenfield Option A 38 j ULTRA_PLAN).

### 3.3 9 P0 from Wave D ULTRA_REVIEW re-evaluated in fold-in context (§6 grid)

- **5 APPLIES** (still required to address): P0-2 drawProof HMAC, P0-8 schema constraints, P0-9 JCA RGPD, ARCH-04 server-truth wheel, RED-01 server-rendered voucher
- **3 MITIGATED** by FoodKing infra: P0-3 webhook idempotency (IdempotencyKeyMiddleware), P0-7 MFA (Sanctum + Spatie), ARCH-03 namespace (Laravel conventions)
- **2 N/A** (stack-specific to Next.js): P0-4 Hono/Edge contradiction, P0-5 mono-app collapse
- **1 SOFTER** (degradable scope): P0-6 KMS envelope (Laravel APP_KEY + sealed-box acceptable V1.2)
- **1 BIVALENT** (depends C-1/C-2): P0-1 incentivized review

### 3.4 Architect specialist (SPIN-ARCH-foldin.json) — 5 fold-in P0 concerns

1. **ARCH-FOLDIN-01** — NF525 audit chain integrity for new event types (voucher_issued, voucher_redeemed) → Sprint 0 code-read AuditLogService.log signature
2. **ARCH-FOLDIN-02** — PricingService::calculateOrder frozen zone untouched → reuse DiscountCalculator + OrderDiscountLog only; LOCK doc if necessary
3. **ARCH-FOLDIN-03** — BranchScope auto-injection on review_boost_* models → Sprint 0 PHPUnit test isolation
4. **ARCH-FOLDIN-04** — Server-truth wheel animation (no client-roll) → Sprint 2 Vue + Playwright sentinel
5. **ARCH-FOLDIN-05** — wheel_slots JSON trade-off vs normalized CampaignSlot table → V1.2 JSON acceptable + V1.3 migration plan documented

### 3.5 RED specialist (SPIN-RED-foldin.json) — 3 sharper edges added to plan

These were folded back into the main plan after the RED audit:

1. **RED-FOLDIN-04** (TAM limitation: 1 restaurant = inconclusive ROI signal) → plan §1.4 Q-FINAL gate added + §5 Q-FINAL section
2. **RED-FOLDIN-06** (Cashier-server collusion fraud) → plan Sprint 3 added cashier outlier detection KPI + Sentry alert
3. **RED-FOLDIN-07** (GDPR DSR workflow missing for solo founder) → plan Sprint 0 added ReviewBoostDsrExportCommand + tombstone pattern + avocat 1h DPIA scope expanded

RED also confirmed:
- Plan adequately covers 4-5 scenarios (R-01 Google enforcement, R-02 trust, R-04 voucher fraud, R-05 maintenance, R-06 NF525 chain)
- Option C remains operationally rational vs Option A given current owner posture
- BUT "when" question (V1.2 vs V1.3 vs V1.4) more important than "whether" — Q-FINAL gate must be explicit

### 3.6 Anti-pattern guard added (§3.1)

Plan now explicitly forbids module cross-coupling: `app/Services/ReviewBoost/` cannot import from `app/Services/Pos/Kiosk/Kds/Oss/`. Sprint 5 grep gate enforced.

---

## 4. Sprint sequencing summary

| Sprint | Effort | Calendar | Key deliverables |
|---|---|---|---|
| 0 — Archi + DB + RGPD | 1-2 j | 2-3 j | Migrations + Models + DSR command + avocat 1h |
| 1 — Place API (skip C-1) | 0-3 j | 0-5 j | Skipped if C-1 retained (recommended) |
| 2 — Wheel + Spin + Voucher core | 3-5 j | 5-7 j | ReviewBoostWheelService + drawProof HMAC + server-truth wheel + email confirmation |
| 3 — POS validation + admin settings | 2-3 j | 3-5 j | PosRedemptionService extension + admin dashboard + cashier outlier KPI |
| 4 — Anti-fraud + RED-team | 2-3 j | 3-5 j | Email normalization + Turnstile + server-rendered voucher image |
| 5 — E2E + i18n + production smoke | 1-2 j | 2-3 j | Playwright + NF525 regression + Lighthouse + tag |
| **TOTAL C-1** | **9-15 j** | **~4-6 sem** | |
| **TOTAL C-2** | **+3-5 j** | **+1-2 sem** | C-2 only if owner override |

---

## 5. Owner gates synthesis

### Pre-Sprint 0 (6 cases)

- [ ] V1.0.2 stabilisé
- [ ] V1.1 roadmap clarifiée (hard stop: if V1.1 +4 weeks delay → V1.2 defer V1.3)
- [ ] Variant C-1 acted (recommended) OR C-2 with override gate
- [ ] Avocat 1h confirmed budget (~300€)
- [ ] Budget cash ~1-2 k€ dispo
- [ ] Q-FINAL timing justified: Le Cayenne explicit request OR FoodKing 3+ restos OR owner accepts inconclusive ROI

### Open questions for owner (Q-1 to Q-FINAL)

10 owner-gate questions in plan §5 covering:
- Variant C-1/C-2 (Q-1)
- Reward economics (Q-2)
- Cooldown rules (Q-3)
- Wheel customization (Q-4)
- Distribution + pricing (Q-5)
- Juridique (Q-6)
- Place API budget (Q-7 only if C-2)
- Mobile mirror V1.2 vs V1.3 (Q-8)
- Frozen zone LOCK (Q-9)
- Marketing positioning C-1 validation (Q-10 RED-added)
- Q-FINAL timing trigger (RED-added)

---

## 6. Conformance to mission constraints

- [x] READ-ONLY — 0 code changes, 0 commits to FoodKing core, 0 modifications to existing files
- [x] Plan-only deliverable: 4 files in plans/ + reports/audit/wave-e-2026-05-19/WE-3-*/
- [x] Discipline GStack + Superpowers + RED-team applied (2 specialist JSONs + sharp edges folded back into plan)
- [x] Memory `feedback_no_cloud_until_owner_initiates` respected: 0 cloud infrastructure proposed, all reuse of FoodKing existing hosting
- [x] CLAUDE.md §7 frozen zones respected: PricingService + FiscalSequenceService + BranchScope all enumerated for NOT modify
- [x] NF525 invariants respected: audit_logs chain append-only via existing AuditLogService.log
- [x] Plan size: ~32 KB (target 28-32 KB) ✓
- [x] Wall-clock: ~35-40 min (target 30-45 min) ✓
- [x] Sub-agent dispatch precedent (Wave D pattern): wrote specialist JSONs in those personas (advisor confirmed this approach since no Task/Agent tool available in this agent's function list)

---

## 7. Strategic verdict

**Option C fold-in V1.2 Review Boost** is the operationally rational choice given:

1. FoodKing V1 SHIPPABLE for Le Cayenne (V1.0.1 hardening complete)
2. Owner posture: hardening focus + no-cloud-yet directive
3. ~30-40% code reuse savings vs greenfield Option A standalone
4. Risk containment: Google policy materializing = contained module pause, not SaaS-existential threat
5. Distribution: 0 CAC for FoodKing customers (future scale-up)

**HOWEVER, "when" matters more than "whether"** — per RED-FOLDIN-04 verdict:

- V1.2 timing requires explicit Le Cayenne request OR 3+ FoodKing restos before Sprint 0
- Otherwise: V1.2 = effort for 1 inconclusive data point. Defer to V1.3 or V1.4 acceptable.

**Plan SPINBOOST_FOLD_IN_FOODKING_V12_PLAN_2026-05-19.md is DRAFT, ready for owner review.** Owner answers 10 questions + 6 pre-Sprint 0 gates → either green-light Sprint 0 OR archive plan as design doc for V1.3+.

---

## 8. Anti-drift check

Plan does not silently override:
- ✓ Memory `feedback_no_cloud_until_owner_initiates` (2026-05-18) — no cloud actions proposed
- ✓ Memory `feedback_v1_focus_no_saas_2026-05-08` — strategic 24mo paused respected
- ✓ Memory `project_v1_0_1_hardening_2026-05-17` — V1.0.2 dependency acknowledged
- ✓ CLAUDE.md §7 frozen zones (PricingService, FiscalSequenceService, BranchScope, etc.)
- ✓ CLAUDE.md §8 NF525 invariants (audit chain append-only, fiscal sequence untouched)
- ✓ ULTRA_REVIEW 2026-05-16 Option A pivot rationale (P0-1 risk) — applied to C-1 default
- ✓ Wave D STATUS.md 2026-05-19 12 P0 + 24 P1 sequencing — re-evaluated grid §6

No contradiction surfaced.

---

## 9. Next steps (owner-initiated only)

1. Owner reads `plans/SPINBOOST_FOLD_IN_FOODKING_V12_PLAN_2026-05-19.md` end-to-end.
2. Owner answers Q-1 to Q-FINAL (10 questions §5).
3. Owner validates 6 pre-Sprint 0 gates §1.4.
4. If all GO → schedule Sprint 0 (1-2 days) when V1.0.2 + V1.1 stabilized.
5. If not GO → archive plan as V1.3+ design doc, kill V1.2 module.
6. Either way: 0 immediate action on FoodKing codebase.

---

## 10. Deliverables index

- `plans/SPINBOOST_FOLD_IN_FOODKING_V12_PLAN_2026-05-19.md`
- `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/SPIN-ARCH-foldin.json`
- `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/SPIN-RED-foldin.json`
- `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/STATUS.md` (this file)

Reference docs (read only, not modified):
- `DESIGN_BRIEF_SPINBOOST_2026-05-16.md`
- `ULTRA_PLAN_SPINBOOST_DECOMPOSED_2026-05-16.md`
- `ULTRA_REVIEW_SPINBOOST_2026-05-16.md`
- `reports/audit/spinboost-2026-05-19/STATUS.md`
- `reports/audit/spinboost-2026-05-19/round-1/SPIN-1-architect/architect.json`
- `reports/audit/spinboost-2026-05-19/round-1/SPIN-2-security/security.json`
- `reports/audit/spinboost-2026-05-19/round-1/SPIN-3-red/red.json`

---

**END STATUS — WE-3 complete.**
