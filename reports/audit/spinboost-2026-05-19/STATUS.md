# SPINBOOST SAAS ULTRA-DEEP AUDIT — STATUS

**Date:** 2026-05-19
**Branch:** `v1-0-1-hardening-2026-05-17` (FoodKing host repo; SpinBoost has no repo/branch yet)
**HEAD:** `ce23352ab` (FoodKing context only)
**Wave:** D (parallel with 4 other wave-D masters; SpinBoost scope is disjoint — separate SaaS)
**Specialists:** Architect, Security, RED — all read-only.
**Method:** 3 specialist JSONs reviewing the 3 spec docs at testttt/ root (DESIGN_BRIEF + ULTRA_PLAN_DECOMPOSED + ULTRA_REVIEW, all dated 2026-05-16). No code archaeology — SpinBoost has ZERO implementation in foodking-web.
**Source memory:** `project_spinboost_ultra_review_2026-05-16` (GO-RISKY/GO-CLEAN-avec-pivot verdict, 3 livrables to be priorisé V1.x).

---

## 0. Production-deploy-blocker scope clarification

The mission asks "Flag P0/P1 if any (production deploy blockers)." Whose production?

- **FoodKing V1 LeCayenne production deploy:** SpinBoost is NOT a blocker. Confirmed by code-level scan: zero foodking-web migrations, models, services, controllers, routes, env keys, or frontend components reference wheel/spin/giveaway/SpinBoost logic. The 3 SPINBOOST_* docs sit at testttt/ root as planning artifacts only. SpinBoost stack (Next.js 15 + Tailwind + shadcn/ui + Supabase + Stripe + Resend) is fully disjoint from FoodKing Laravel/Vue stack. **No coupling → no risk → SpinBoost release status is independent of LeCayenne V1.**
- **SpinBoost's own production deploy (its hypothetical MVP launch):** 9 P0 from ULTRA_REVIEW 2026-05-16 remain unresolved (concept pivot, drawProof HMAC, webhook idempotency, runtime contradiction, mono-app collapse, KMS envelope, MFA, schema constraints, JCA). This audit adds 3 new P0 not covered by the original review (see §3 P0 list).

**Verdict on mission constraint:** confirmed. SpinBoost is V1.x backlog candidate at best, and not a V1 LeCayenne blocker in any sense.

---

## 1. Documentation state inventory

| Artifact | Status | Notes |
|----------|--------|-------|
| DESIGN_BRIEF_SPINBOOST_2026-05-16.md | Complete | 7-day designer brief, mobile-first player + dashboard + marketing, palette x3 propositions, 12 components, anti-patterns, WCAG AA, deliverables Figma scaffolding. Quality: high. |
| ULTRA_PLAN_SPINBOOST_DECOMPOSED_2026-05-16.md | Complete | 6 sprints (0-5), 6-8 weeks ~38 j-humain ~10k€ cash, kill list of 12 items, GO/NO-GO gates at each sprint, top-3 risk mitigations. Quality: high. |
| ULTRA_REVIEW_SPINBOOST_2026-05-16.md | Complete | 4 sub-agent adversarial review, 9 P0 + 10 P1 + 7 P2 cross-validated. Quality: very high — primary sources cited including Google policy + DGCCRF + FTC 16 CFR 465. |
| SpinBoost repo / codebase | NOT EXISTS | No `pnpm create next-app` has been run. No git repo. No domain registration confirmed. No SAS creation. Sprint 0 pre-requisites (5 warm-leads, juridique RDV) status unknown — owner has not communicated progress since 2026-05-16. |
| Graphiti `spinboost` group | Allocated | Memory `project_spinboost_ultra_review_2026-05-16` references it; not searched in this audit (foodking group is current focus). |

---

## 2. Audit refresh focus

The 2026-05-16 ULTRA_REVIEW already enumerated 9 P0 + 10 P1 with cross-validation from 4 sub-agents. **This audit does NOT re-derive those findings** — they remain valid as of 2026-05-19 (3 days elapsed, no implementation has happened). Instead, this audit:

1. **Confirms** the 9 original P0 are still open (none have been worked on; ULTRA_PLAN Sprint 0 has not started per Graphiti silence).
2. **Adds** new findings from 3 angles that the original review under-specified (architecture invariant guardrails, security shifts left, RED-team adversarial scenarios on Option A pivot).
3. **Reframes** for V1.x backlog priorisation — what should the owner schedule FIRST when (if) they resume SpinBoost work, in light of FoodKing post-V1.0.1 hardening state.

---

## 3. Findings consolidated — 4-list

### P0 — Production-deploy-blockers for SpinBoost's OWN MVP launch (NOT FoodKing V1)

**Carried forward from ULTRA_REVIEW 2026-05-16 (unchanged):**

| # | Finding | Source |
|---|---------|--------|
| ULTRA-P0-1 | Concept incentivized review violates Google policy + DGCCRF + FTC (zone grise long-standing, EU enforcement scaling 2025) — Option A pivot acted | ULTRA_REVIEW §1.2, §2 P0-1 |
| ULTRA-P0-2 | Tirage authoritative sans chaîne d'audit cryptographique — Play.drawProof HMAC required | ULTRA_REVIEW §2 P0-2 |
| ULTRA-P0-3 | Webhook Stripe sans idempotency ni DLQ — WebhookEvent UNIQUE table required | ULTRA_REVIEW §2 P0-3 |
| ULTRA-P0-4 | Hono + Edge runtime + Prisma contradiction — Node runtime SEUL acté | ULTRA_REVIEW §2 P0-4 |
| ULTRA-P0-5 | 3 apps + Turborepo overkill — mono Next.js acté | ULTRA_REVIEW §2 P0-5 |
| ULTRA-P0-6 | ENCRYPTION_KEY 32 bytes en env var sans KMS ni rotation | ULTRA_REVIEW §2 P0-6 |
| ULTRA-P0-7 | MFA absent pour OWNER restaurateur | ULTRA_REVIEW §2 P0-7 |
| ULTRA-P0-8 | Prisma : 3 contraintes schéma manquantes (concurrent stock + slot probability sum + WIN sans Prize) | ULTRA_REVIEW §2 P0-8 |
| ULTRA-P0-9 | Joint Controller Agreement RGPD art. 26 absent | ULTRA_REVIEW §2 P0-9 |

**NEW P0 added by this audit (not in ULTRA_REVIEW):**

| # | Finding | Specialist | Action |
|---|---------|------------|--------|
| SPIN-ARCH-02 | Stack contradiction P0-4 ostensibly resolved BUT no runtime-pinning enforcement encoded (eslint guard + `export const runtime = 'nodejs'` checkpoint) — drift risk | Architect | Sprint 1 day 1: pin + lint rule |
| SPIN-ARCH-03 | Route-group skeleton not locked, no arch-lint rule preventing /api/* prefix drift | Architect | Sprint 1 day 1: skeleton + arch-unit-js rule |
| SPIN-ARCH-04 | DESIGN_BRIEF §3.A A3 lacks "server-truth" instruction to designer — risk of client-rolls-then-animates pattern | Architect | Designer addendum + Playwright sentinel |
| SPIN-SEC-03 | MFA TOTP is Sprint 5 in plan — should be Sprint 1 (retrofit cost compounds) | Security | Move to Sprint 1, Auth.js v5 with TOTP via `otpauth` lib |
| SPIN-RED-01 | Voucher rendered client-side post-server-response → DOM mutation displays fake top prize, reputation damage | RED | Sprint 2: server-rendered voucher image (signed S3 URL or signed HTML data-URI) |
| SPIN-RED-02 | Patron self-play loop inflates KPIs + bypasses tier limit + harvests own emails | RED | Sprint 2: internal-IP/fingerprint tagging on dashboard + tier billing on EXTERNAL plays only |
| SPIN-RED-06 | Stripe billing — no explicit overage policy for Starter 500-plays/mo tier | RED | Sprint 4: metered billing + soft warning at 80% + overage at 20% over |

### P1 — Graves, V1.x backlog

**Carried forward from ULTRA_REVIEW (still open):** P1-1 anti-fraude, P1-2 JWT revocation, P1-3 pseudonymisation, P1-4 conservation + mineurs, P1-5 NF ISO 20488, P1-6 Sunday competitor, P1-7 tarif 29€ → 49€ (adjusted), P1-8 50 restos en 6 mois irréaliste, P1-9 DPIA, P1-10 schéma 7 problèmes secondaires.

**NEW P1 added by this audit:**

| # | Finding | Specialist | Action |
|---|---------|------------|--------|
| SPIN-ARCH-05 | Slot probability sum=10000 check only at Zod app-level, not DB-level CHECK constraint | Architect | Raw SQL migration: `ALTER TABLE campaign_slots ADD CONSTRAINT slots_sum_10000 CHECK …` |
| SPIN-ARCH-06 | OTel traceparent propagation to Stripe/Resend/Sentry not explicitly tasked in Sprints 2-4 | Architect | Add explicit propagation tasks |
| SPIN-ARCH-07 | Prisma org-scope middleware missing — solo founder will leak cross-org rows accidentally | Architect | Sprint 1: `prisma.$extends` middleware auto-injecting `orgId` |
| SPIN-ARCH-09 | Onboarding writereview URL manual paste has no regex validation + no "test the link" UI | Architect | Sprint 3: regex + open-in-new-tab confirmation step |
| SPIN-SEC-02 | Turnstile only on email form, NOT on spin endpoint itself — rotating-proxy farm bypass | Security | Sprint 2: Turnstile token validation on `/api/v1/public/plays/spin` |
| SPIN-SEC-04 | JCA boundary table SpinBoost vs Resto not detailed — generic Legalstart template insufficient | Security | Sprint 0: explicit boundary table + 1h avocat review |
| SPIN-SEC-05 | Play table holds email + IP + fingerprint + NPS — fails RGPD art. 5 data minimisation | Security | Sprint 2: split into Play (long-retention, hashed) + PlayAntiFraud (30-90j) |
| SPIN-SEC-06 | Auth.js v5 JWT default — needs DB session strategy for revocation | Security | Sprint 1: `session: { strategy: 'database' }` + /account/sessions UI |
| SPIN-SEC-08 | Stripe webhook endpoint publicly exposed — needs IP allowlist + rate-limit | Security | Sprint 4: middleware allowlist Stripe CIDR ranges |
| SPIN-SEC-09 | Age verification ≥16 (RGPD art. 8 France) checkbox missing in A2 design | Security | Sprint 2 + DESIGN_BRIEF: 3rd checkbox |
| SPIN-SEC-10 | DPIA treated as paperwork — should be design forcing function (forces SEC-05 + SEC-01) | Security | Sprint 0 + Sprint 5: avocat reviews 2× |
| SPIN-RED-04 | Email normalization missing — Gmail plus-addressing + dots bypass emailHash cooldown | RED | Sprint 2: normalize before HMAC (strip dots for Gmail, strip plus suffix) |
| SPIN-RED-07 | Writereview URL paste vulnerable to typo-squat / competitor sabotage | RED | Sprint 3: Google Places autocomplete-only + Place ID uniqueness |
| SPIN-RED-08 | Spin endpoint DoS via 1000-IP botnet exhausts Upstash free tier | RED | Sprint 5 load test: griefer scenario + Upstash upgrade trigger |

### P2 — V1.x polish / hardening backlog

**Carried forward:** P2-1 OTel propagation (now P1 per SPIN-ARCH-06), P2-2 Inngest kill (already in kill list), P2-3 PWA kill (already in kill list), P2-4 Auth.js seul (already acted), P2-5 Supabase lock-in, P2-6 animation roue mid-range, P2-7 pentest budget (acted).

**NEW P2 added:**

| # | Finding | Specialist | Action |
|---|---------|------------|--------|
| SPIN-ARCH-08 | Webhook DLQ aspect not in plan — silent 3-day retry burn | Architect | webhook_events.processing_status + Sentry alert 3× consecutive |
| SPIN-ARCH-10 | Voucher redemption state machine missing — fraud + KPI inaccuracy | Architect+RED | V1.1 Sprint 6 |
| SPIN-ARCH-11 | Flyer versioning + Venue.slug immutability | Architect | V1.1 |
| SPIN-ARCH-12 | No ARCHITECTURE.md / FROZEN_ZONES.md scaffolding for SpinBoost | Architect | Sprint 0 |
| SPIN-SEC-11 | Pentest €1500 self-pentest acceptable V1 — upgrade trigger when MRR > €3000 | Security | docs/SECURITY_ROADMAP.md |
| SPIN-SEC-12 | No incident response runbook / breach comms template | Security | Sprint 5 |
| SPIN-RED-03 | Voucher screenshot sharing — V1 mitigation via short code + expiry + cashier callout | RED | Sprint 2 mitigation + V1.1 redemption state machine |
| SPIN-RED-09 | NPS poisoning by competitor 50-fingerprint script | RED | V1.1 outlier detection |
| SPIN-RED-10 | Voucher resale on eBay/Vinted — fixed once redemption state machine ships | RED | V1.1 |
| SPIN-RED-11 | Stripe webhook thin-payload config (reduce PII in webhook body) | RED | Sprint 4 |

### V1.x roadmap recommendation — sequenced

See §5 below.

---

## 4. Strategic verdict — Option A vs Option C re-evaluation (post-FoodKing V1.0.1)

The ULTRA_REVIEW 2026-05-16 ended with: **Option A (pivot Google decoupling) recommended, Option C (kill SpinBoost, fold into FoodKing as Review Boost add-on) noted as fallback if traction insufficient after 3 mois.**

Re-evaluating Option C in light of FoodKing post-V1.0.1 hardening state (memory `project_v1_0_1_hardening_2026-05-17`):

**For Option C (fold into FoodKing):**
- FoodKing V1 is SHIPPABLE for Le Cayenne single-restaurant per V1.0.1 hardening cycle. Sub-systems are stabilized (POS, Kiosk, KDS, OSS, Livreur). Adding a "Review Boost" module would mean ONE more sub-system to maintain in the same Laravel/Vue codebase.
- Distribution gratuite to existing FoodKing customers (1 restaurant currently — Le Cayenne) is not meaningful at V1 scale. Becomes valuable only once FoodKing has 10+ restaurants.
- FoodKing's NF525 chain, BranchScope, idempotency middleware, and audit log infrastructure could be reused for SpinBoost's WheelEvent + Voucher state machine — meaningful 30-40% code reuse savings vs greenfield Next.js.
- Tech stack mismatch (Laravel vs Next.js) means owner solo cannot rebuild SpinBoost as a Laravel module without learning Vue 3 deeper — but that learning is also needed for FoodKing maintenance anyway.

**For Option A (standalone Next.js):**
- ULTRA_PLAN economics: 6-8 weeks + 10-12k€ cash to MVP. Independent product, independent monetisation, larger TAM (any FR resto, not just FoodKing customers).
- Risk: solo founder commitment to a 2nd codebase in parallel with FoodKing V1.0.2 hardening + Le Cayenne support is high. Memory `feedback_no_cloud_until_owner_initiates` indicates owner is on hardening focus, not cloud/SaaS expansion. SpinBoost falls in the "vision avant production" bucket.
- Pivot Option A solved the regulatory risk (Google policy) — biggest unlock. Without pivot, SpinBoost is non-shippable. WITH pivot, marketing repositioning to "Voice of Customer + CRM marketing" is unproven — the 5 warm-leads pre-requisite is doubly critical.

**This audit's verdict:** Option A remains the recommended path IF owner explicitly resumes SpinBoost. But **the current owner posture (FoodKing V1.0.1/V1.0.2 hardening focus, V1 single-resto for Le Cayenne, no cloud expansion until explicit go) makes Option C "fold-into-FoodKing" the operationally rational fallback if SpinBoost resumes within 12 months.** A Review Boost module shipped to FoodKing V1.2 would:
1. Leverage FoodKing's NF525 + BranchScope + idempotency infra.
2. Distribute to FoodKing customers as add-on (no CAC).
3. Cap risk: if Google policy enforcement scales further in EU 2026-2027, FoodKing add-on is contained, not a SaaS-existential threat.
4. Defer the 6-8 weeks Next.js learning curve.

**Recommendation to owner:** before resuming SpinBoost in any form, decide: (a) Option A standalone (revisit when FoodKing V1.0.2 stabilized + 5 warm-leads confirmed) or (b) Option C fold-in (schedule for FoodKing V1.2 roadmap). DO NOT spec a third option (parallel work both stacks).

---

## 5. V1.x backlog priority order — sequenced

If SpinBoost resumes (either as standalone Option A or fold-into Option C), the priority order is:

### Phase 0 (pre-coding gates — Sprint 0 equivalent)
1. **Owner decision Option A vs C** (highest leverage, blocks all subsequent work).
2. ULTRA-P0-1 — pivot Option A confirmed (already documented).
3. ULTRA-P0-9 + SPIN-SEC-04 — JCA + boundary table + DPIA Sprint 0 with avocat 1h.
4. SPIN-ARCH-12 — copy/adapt FoodKing CLAUDE.md + create ARCHITECTURE.md + FROZEN_ZONES.md (cheap, prevents 3-month drift).

### Phase 1 (Sprint 1 — Foundation)
5. SPIN-ARCH-02 + SPIN-ARCH-03 — runtime pinning + route-group skeleton + arch-lint rules.
6. ULTRA-P0-5 — mono Next.js app (already acted in plan).
7. SPIN-SEC-03 — **MFA TOTP at Sprint 1 not Sprint 5** (zero owners = zero retrofit cost).
8. SPIN-SEC-06 — Auth.js v5 DB session strategy + /account/sessions UI.
9. SPIN-ARCH-07 — Prisma org-scope middleware ($extends).
10. ULTRA-P0-6 + SPIN-SEC-01 — envelope encryption skeleton (DEK/KEK in Vercel KV pre-KMS).
11. SPIN-ARCH-06 — OTel `@vercel/otel` + traceparent propagation contract documented.

### Phase 2 (Sprint 2 — Wheel + Play core)
12. ULTRA-P0-2 — Play.drawProof HMAC (server-authoritative tirage).
13. ULTRA-P0-8 + SPIN-ARCH-05 — atomic stock + slot probability sum CHECK at DB-level.
14. SPIN-RED-01 — server-rendered voucher image (signed URL or signed HTML data-URI).
15. SPIN-RED-02 — internal-IP/fingerprint tagging for patron self-play loop detection.
16. SPIN-SEC-02 — Turnstile on `/spin` endpoint.
17. SPIN-SEC-05 — Play table split (Play + PlayAntiFraud).
18. SPIN-SEC-09 + DESIGN_BRIEF update — age ≥16 checkbox.
19. SPIN-RED-04 — email normalization (Gmail plus-addressing + dots).
20. SPIN-ARCH-04 + DESIGN_BRIEF update — designer addendum "server-truth wheel".

### Phase 3 (Sprint 3 — Onboarding + Flyer)
21. SPIN-ARCH-09 + SPIN-RED-07 — writereview URL via Google Places autocomplete + Place ID uniqueness.

### Phase 4 (Sprint 4 — Stripe billing)
22. ULTRA-P0-3 — webhook idempotency `UNIQUE(provider, eventId)`.
23. SPIN-ARCH-08 — webhook DLQ + Sentry 3× alert.
24. SPIN-SEC-08 — Stripe IP allowlist + rate-limit.
25. SPIN-RED-06 — metered billing + overage policy (Starter 500 plays/mo).
26. SPIN-RED-11 — Stripe thin-payload config.

### Phase 5 (Sprint 5 — Polish + Pilotes)
27. SPIN-SEC-10 — DPIA finalisée + avocat 1h.
28. SPIN-SEC-11 — pentest €1500 self path (nuclei + zap-baseline + semgrep + 1j freelance).
29. SPIN-SEC-12 — incident response runbook + Resend templates pré-drafted.
30. SPIN-RED-08 — load test with griefer scenario.

### V1.1 (post-MVP)
31. SPIN-ARCH-10 + SPIN-RED-03 + SPIN-RED-10 — voucher redemption state machine (kills resale + sharing fraud).
32. SPIN-RED-09 — NPS outlier detection + volatility indicator.
33. SPIN-ARCH-11 — flyer versioning + slug immutability.
34. SPIN-SEC-07 — FingerprintJS Pro paid graduation (MRR > €1000).

### V1.2 (Google MyBusiness OAuth)
35. ULTRA kill-list item: Google MyBusiness OAuth + sync (re-evaluated post enforcement landscape 2026).

### V2 (long term)
36. SMS/WhatsApp player, marque blanche, autres jeux, multi-user.

---

## 6. Heal decision — READ-ONLY (no code touched)

**Decision:** Read-only. No commits.

**Rationale:**
- SpinBoost has zero code in foodking-web. There is nothing to heal in this audit's scope.
- The 3 spec docs are owner-authored and timestamped 2026-05-16. Modifying them without owner gate would violate the FoodKing 'frozen docs' convention.
- All findings are V1.x backlog recommendations; none require immediate action.
- Mission constraint explicit: SpinBoost is NOT V1 LeCayenne blocker.

---

## 7. Verdict — V1 LeCayenne unaffected; SpinBoost V1.x conditional

- **0 P0 against FoodKing V1 LeCayenne** — SpinBoost is standalone, no coupling, no blocker.
- **12 P0 against SpinBoost's OWN MVP launch** (9 carried + 3 new from this audit). Open backlog.
- **24 P1 against SpinBoost's OWN MVP launch** (10 carried + 14 new). Sequenced V1.x backlog above.
- **10 P2 against SpinBoost** — V1.x polish + V1.1 expansion.

**Strongest evidence:** repo scan confirms ZERO SpinBoost code in foodking-web. 3 spec docs are doc-only artifacts. Memory `feedback_no_cloud_until_owner_initiates` (2026-05-18) confirms owner posture is hardening + V1 single-resto, not cloud/SaaS expansion. SpinBoost falls in the "vision avant production" bucket per owner's own framing.

**Production readiness for SpinBoost MVP launch:** NOT READY — 12 P0 open + Sprint 0 gates not started.
**Production readiness for FoodKing V1 LeCayenne:** UNAFFECTED by SpinBoost state.

**Strategic recommendation:** before resuming SpinBoost in any form, owner decides Option A (standalone Next.js, 6-8 weeks) vs Option C (fold-into FoodKing V1.2 add-on, leverages existing infra). This audit recommends explicit owner decision before any Sprint 0 work — Option C may be operationally more rational given current owner posture, but Option A retains larger TAM if resources/timing align.

---

## 8. Deliverables index

- `reports/audit/spinboost-2026-05-19/round-1/SPIN-1-architect/architect.json` (~1450 words)
- `reports/audit/spinboost-2026-05-19/round-1/SPIN-2-security/security.json` (~1495 words)
- `reports/audit/spinboost-2026-05-19/round-1/SPIN-3-red/red.json` (~1490 words)
- `reports/audit/spinboost-2026-05-19/STATUS.md` (this file)

Source docs (read but not modified):
- `DESIGN_BRIEF_SPINBOOST_2026-05-16.md`
- `ULTRA_PLAN_SPINBOOST_DECOMPOSED_2026-05-16.md`
- `ULTRA_REVIEW_SPINBOOST_2026-05-16.md`

---

**END STATUS**
