# FOODKING — EXECUTION ROADMAP V1
**Date** : 2026-05-16
**Audit source** : `reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md` §5
**Scope** : Weeks 1-12 (Phase 1 stabilisation Le Cayenne + Phase 2 hardening V1.0.1)
**Out of scope** : Phase 3 multi-tenant migration, Phase 4 SaaS commercialisation (NO-GO ferme per verdict)
**Owner profile** : non-senior-dev, limited bandwidth — every sprint splits owner-action vs Claude-action

> **North-star goals reference** (per `CLAUDE.md` + `PROJECT_BRAIN.md` §1) :
> - **G1** V1 Le Cayenne GO-LIVE production-grade
> - **G2** NF525 compliance absolue (audit chain HMAC + 6y retention)
> - **G3** Multi-tenant branch isolation absolue
> - **G4** Pricing SSOT backend authoritative
> - **G5** Visual + technical evidence à chaque livraison
> - **G6** V1.0.1 hardening (88 FormRequest authz, password policy, Sanctum TTL, API key versioning, 6 listeners idempotency, observability SLI)
> - **G7** V1.x backlog (F-016b stock UI, 17 advisories, Laravel 9→11, ESLint, Saga, Stripe idempotency)
> - **G8** V2 SaaS commercialisation (multi-restaurant, billing, onboarding, marketing) — **OUT of this roadmap**

---

## §1 GAP-TO-GOAL MAPPING

Every P0/P1 finding in verdict §5 mapped to the north-star goal(s) it blocks. Finding IDs are SSOT per audit §5 ordering.

### P0 findings (blocks Le Cayenne ouverture)

| # | Finding | Blocks goal | Source agent | Why it blocks |
|---|---|---|---|---|
| **P0-1** | AWS keys `AKIAYJOT77SIZHDXNYOZ` leaked commit `a4a88df06` non rotées | G1, G5 | Agent 2/8 | Tout commit ultérieur ré-embarque le risque en historique permanent. Sécurité fondationnelle. |
| **P0-2** | RCE primitive LanguageService + tokens Sanctum wildcard `['*']` | G1, G3 | Agent 2 | Client kiosk peut écrire PHP arbitraire + ability `kiosk:order` est décorative tant que tokens sont wildcard. |
| **P0-3** | IDOR cross-branch `PosOrderController` via `withoutGlobalScope(BranchScope::class)` | G3, G1 | Agent 2 | Fuite multi-tenant directe. G3 isolation absolue violée. |
| **P0-4** | Aucun backup automatisé, aucun restore testé | G1, G2 | Agent 4 | Disk fail = perte des 6 ans NF525 = exposition pénale. G2 retention compromise. |
| **P0-5** | Aucun alerting câblé (Slack/Sentry/BetterUptime) | G1, G5 | Agent 4 | Outage 22h samedi soir détecté par client dimanche matin. Owner non-dev-senior ne peut pas opérer sans alerting. |
| **P0-6** | Stripe charge `(int) $total * 100` tronque centimes (NF525 mismatch) | G2, G4 | Agent 2 | NF525 reçu doit refléter le débit exact. Troncation = ticket fiscal incorrect + revenue loss. |
| **P0-7** | Dual Order / FrontendOrder sur même table `orders` | G1, G2, G4 | Agent 1 | Fillable divergent + observer single attach = chaque fix fiscal/payment doit être dupliqué. Block enforce G4 SSOT. |
| **P0-8** | Allergènes fabriqués mobile (60/60 items default `['gluten','lactose']`) | G1 | Agent 6 | EU FIC 1169/2011 legal exposure. 1 ligne fix `mobile/data/menu.js:274`. |
| **P0-9** | Mobile promo code affiche "✓ Code appliqué" sans discount | G1 | Agent 6 | UX trompeuse client-facing. Stub désactivé suffit V1. |
| **P0-10** | 10 runbooks tous `DRAFT_SKELETON_NOT_SIGNED` | G1, G5 | Agent 4 | Owner ne peut pas opérer "kiosk 500 vendredi 19h30" sans commandes copy-paste exécutables. |
| **P0-11** | POS direct-cash → CashMovement wiring untested | G2, G1 | Agent 5 + Wave Z 2026-05-09 | Cash trail audit chain doit être bout-en-bout. NF525 audit log impact. |
| **P0-12** | OrderService.php 2432 LOC + 5 status mutations bypassent OrderStateMachine | G1, G2, G4 | Agent 1 | State machine guarantees ne tiennent que si `apply()` est seul writer. G4 SSOT enforcement. |
| **P0-13** | PHPSpreadsheet 1.30.0 CVE-2024-45048 RCE reachable via admin Excel import | G1 | Agent 2 | Surface attaque admin live. Upgrade ≥ 2.0.0 obligatoire. |
| **P0-14** | Laravel 9.52 EOL (sécurité plus patchée depuis fev 2024) | G1, G7 | Agent 2 | Pas de CVE patches. Migration L10→L11 sur track séparé (V1.x), mais flag à éponger Phase 2. |
| **P0-15** | E2E non-bloquant CI (`continue-on-error: true` + label opt-in) | G5, G1 | Agent 5 | PRs ship green sans Playwright. G5 visual evidence non enforcée. |

### P1 findings (doit être corrigé V1.x sous 4-12 semaines)

| # | Finding | Blocks goal | Source |
|---|---|---|---|
| **P1-16** | 14 controllers importent `DB` facade direct | G6 | Agent 1 |
| **P1-17** | OrderStateMachine::apply() utilisé seulement 2× | G2, G4 | Agent 1 |
| **P1-18** | Frontend zéro architectural layering (0 API client, 113 Vuex flat, 308 Options vs 10 Composition) | G7 | Agent 1 |
| **P1-19** | 39 occurrences `withoutGlobalScope(BranchScope::class)` | G3 | Agent 1/3 |
| **P1-20** | KDS UX 3.2/10 (8 P0 cross-validated audit 2026-05-11) | G6 | Agent 6 + memory |
| **P1-21** | POS Vanilla wizard 0 ARIA, 32px touch targets, 100% FR hardcoded (frozen) | G6 | Agent 6 |
| **P1-22** | Bug `branch.status=1 vs 5` documenté non corrigé | G3 | Agent 3 + BRAIN |
| **P1-23** | 23 `assertTrue(true)` dans tests fiscal/payment/state-machine | G5, G2 | Agent 5 |
| **P1-24** | Frozen-zones hook `safety-check.sh:9-12` liste 2 fichiers vs 13+ doctrine | G5, G1 | Agent 5/8 |
| **P1-25** | Aucun script deploy/rollback (`bin/` vide pour ops) | G1 | Agent 4 |
| **P1-26** | Contradiction CLAUDE.md vs AGENTS.md (load tous deux à chaque session) | G5, G1 | Agent 8 |
| **P1-27** | Aucun driver TPE natif (`payment_terminals` table seule, BypassMode actif) | G1 | Agent 7 |
| **P1-28** | 88 endpoints sans FormRequest authz | G6 | BRAIN |
| **P1-29** | Stress test théâtral (sqlite-memory `lockForUpdate no-op`) | G5 | Agent 5 |
| **P1-30** | Vuex modules 113 flat — pas de migration Pinia entamée | G7 | Agent 1 |

### Coverage de goals par les findings

- **G1** (V1 Le Cayenne) : bloqué par P0-1, P0-2, P0-3, P0-4, P0-5, P0-8, P0-9, P0-10, P0-13, P0-14, P0-15, P1-24, P1-25, P1-26, P1-27
- **G2** (NF525) : bloqué par P0-4, P0-6, P0-7, P0-11, P0-12, P1-17, P1-23
- **G3** (Multi-tenant branch isolation) : bloqué par P0-2, P0-3, P1-19, P1-22
- **G4** (Pricing SSOT) : bloqué par P0-6, P0-7, P0-12, P1-17
- **G5** (Visual + technical evidence) : bloqué par P0-1, P0-5, P0-10, P0-15, P1-23, P1-24, P1-26, P1-29
- **G6** (V1.0.1 hardening) : bloqué par P1-16, P1-20, P1-21, P1-28
- **G7** (V1.x backlog) : référé par P0-14, P1-18, P1-30
- **G8** (V2 SaaS) : **explicitement out of scope** (Phase 3+ per verdict §7)

---

## §2 CRITICAL PATH DIAGRAM

The single longest dependency chain from current state to V1 Le Cayenne GO-LIVE. Everything off this spine is parallelizable.

```
        ┌─────────────────────────────────────────────┐
        │ WEEK 0 (today)                              │
        │ P0-1 [OWNER] Rotate AWS+APP_KEY+FISCAL      │  ← BLOCKS all subsequent commits
        │ P0-1b [Claude] gitleaks + composer audit CI │  ← parallel, 30min
        │ P1-26 [Claude] Archive AGENTS.md → _LEGACY  │  ← parallel, 5min
        └────────────────┬────────────────────────────┘
                         │
        ┌────────────────▼────────────────────────────┐
        │ WEEK 1 — Secure + Detect                    │
        │ P0-2 LanguageService + Sanctum role-scope   │  ←┐
        │ P0-13 PHPSpreadsheet ≥ 2.0.0               │   │ parallel
        │ P1-24 safety-check.sh array 2→13 + CI gate │  ←┘
        │ P0-5 Alerting Slack+Sentry+BetterUptime    │
        │ P0-4 spatie/laravel-backup quotidien        │  ← GATES P0-7, Phase 3
        └────────────────┬────────────────────────────┘
                         │
        ┌────────────────▼────────────────────────────┐
        │ WEEK 2 — Runbooks + Ops                     │
        │ P0-10 Sign 4 runbooks critiques             │
        │ P1-25 bin/deploy.sh + bin/rollback.sh       │
        │ P0-15 E2E bloquant CI (drop continue-on-err)│
        │ P0-6 Stripe bcmath cast                     │  ← quick win
        │ P0-3 IDOR PosOrderController scope assert   │
        │ P0-8 Mobile allergens default []            │  ← quick win
        │ P0-9 Mobile promo button hide               │  ← quick win
        │ P1-22 Branch.status=1→5 SQL UPDATE          │  ← quick win
        └────────────────┬────────────────────────────┘
                         │
        ┌────────────────▼────────────────────────────┐
        │ WEEKS 3-6 — Order Collapse Sprint (4 weeks) │
        │ [LOCK doc owner-signed] P0-7 collapse        │
        │ Order/FrontendOrder duality                  │
        │ Parallel tracks (non-NF525):                 │
        │ - P1-20 KDS UX heal (8 P0 audit)             │
        │ - P0-11 Cash trail E2E test                  │
        │ - P1-19 audit 39 withoutGlobalScope sites    │
        └────────────────┬────────────────────────────┘
                         │
        ┌────────────────▼────────────────────────────┐
        │ WEEK 7 — Enforce sole-writer                │
        │ P0-12 OrderStateMachine apply() seul writer │  ← REQUIRES P0-7 done
        │ P1-17 grep ->status= en CI lint              │
        └────────────────┬────────────────────────────┘
                         │
        ┌────────────────▼────────────────────────────┐
        │ WEEKS 8-9 — POS Surgical Patch              │
        │ [LOCK doc] pos-wizard.js ARIA + 44px + i18n │
        │ P1-21 (subset 200 lignes diff)               │
        └────────────────┬────────────────────────────┘
                         │
        ┌────────────────▼────────────────────────────┐
        │ WEEKS 10-11 — Hardening V1.0.1              │
        │ P1-28 FormRequest authz top-20 endpoints    │
        │ P1-23 Replace 23 assertTrue(true)            │
        │ P1-29 Stress test MySQL CI                   │
        │ P1-16 14 controllers DB facade → services    │
        └────────────────┬────────────────────────────┘
                         │
        ┌────────────────▼────────────────────────────┐
        │ WEEK 12 — Final Gate                        │
        │ Production readiness checklist 40 items     │
        │ Owner DR drill                               │
        │ V1 Le Cayenne GO-LIVE                        │
        └─────────────────────────────────────────────┘
```

### Hard prerequisites (gates that cannot be skipped)

1. **P0-1 rotation BEFORE any new commit lands** — every commit after exposed-but-unrotated secrets re-embeds risk in permanent history. Owner action.
2. **P0-4 backups + DR drill BEFORE P0-7 Order collapse** — collapse is destructive-class; rollback must be tested.
3. **P1-24 frozen-zones CI gate BEFORE any new frozen-zone PR** — else +6782 drift keeps growing during the very sprints meant to fix it.
4. **P1-26 AGENTS.md vs CLAUDE.md resolved BEFORE any "process discipline" sprint** — doctrinal split poisons enforcement.
5. **P0-7 collapse BEFORE P0-12 sole-writer enforce** — can't enforce one writer across two models.
6. **LOCK doc owner-signed BEFORE P0-7 starts** — frozen-zone NF525-sensitive.
7. **G2 NF525 chain HMAC integrity verified after EVERY frozen-zone touch** — sentinel `audit_logs` row count + last hash matches baseline.

---

## §3 WEEK-BY-WEEK SPRINTS

Each sprint = 1 sprint goal + 2-5 items + dependencies + parallel-safe set + estimated hours + critical-path flag.

Annotations :
- **[OWNER]** = owner action required (Claude does NOT touch — secrets, runbook signing, LOCK doc gate, DR drill)
- **[CLAUDE]** = Claude orchestrate + execute
- **[CP]** = on critical path (blocks downstream)
- **[//]** = parallel-safe (no shared state with other items same week)

### Week 0 (today, day 0)
**Sprint goal** : Stop the bleed — secrets rotation + scanning gates installed before any new commit.
- **P0-1** [OWNER, CP] Rotate AWS keys `AKIAYJOT77SIZHDXNYOZ` + `APP_KEY` (`php artisan key:generate` + redeploy) + FISCAL_*_SECRET seeds. Block any new commit until done. — 2h owner action
- **P0-1b** [CLAUDE, //] Install gitleaks pre-commit + `gitleaks-action` GitHub workflow + `composer audit` + `npm audit` in CI. — 1h
- **P1-26** [CLAUDE, //] Rename `AGENTS.md` → `AGENTS_LEGACY_2026-03.md` + entrée changelog + update CLAUDE.md §1 confirming SSOT. — 30min
- **P0-8** [CLAUDE, //] Mobile allergens default `[]` ligne `mobile/data/menu.js:274` + test E2E "eau minérale 0 allergène". — 1h
- **P0-9** [CLAUDE, //] Mobile promo code button hide / disable (`mobile/screens-main.jsx:595`). — 30min
- **Dependencies** : none (start state)
- **Critical path** : P0-1 owner rotation
- **Estimated hours** : 5h total (2h owner + 3h Claude)

### Week 1 — Secure + Detect
**Sprint goal** : Close the open RCE/IDOR surfaces, install backup auto, wire alerting.
- **P0-2** [CLAUDE, CP] Patch LanguageService : route `permission:settings`, `realpath()` whitelist `lang_path()`, reject `<?`. Replace abilities `['*']` → role-scoped (`['pos:order']` / `['kiosk:order']` / `['admin:catalog']`). Force re-login. CI lint fail on `createToken(..., ['*'], ...)`. — 1 day (8h)
- **P0-13** [CLAUDE, //] `composer require phpoffice/phpspreadsheet:^2.0` + smoke test admin Excel import. — 2h
- **P1-24** [CLAUDE, //] Synchronize frozen-zone list CLAUDE.md §7 + `memory/reference_frozen_zones.md` + `scripts/check-frozen-zones.sh` (array 2 → 13 files) + GitHub Action `frozen-zones.yml` fail CI if frozen file touched without `LOCK_*.md` co-committed. — 4h
- **P0-5** [CLAUDE, CP] Slack webhook (`LOG_SLACK_WEBHOOK_URL` env, channel `#foodking-prod-alerts`) + Sentry (laravel SDK + Vue SDK) + BetterUptime ping `/health/live` 60s. — 2h
- **P0-4** [CLAUDE + OWNER, CP] `composer require spatie/laravel-backup` + schedule quotidien 03:00 + S3/Wasabi GPG encryption + object-lock 6 ans. Owner test DR drill staging end-of-week. — 12h Claude + 2h owner drill
- **Dependencies** : P0-1 rotated, P1-26 doctrine clear
- **Parallel-safe** : P0-13 + P1-24 (independent file sets) ; P0-5 (config) + P0-4 (config + composer) après P0-2 patch
- **Critical path** : P0-2 + P0-4 + P0-5
- **Estimated hours** : 28h Claude + 2h owner

### Week 2 — Runbooks + Ops + Quick Wins
**Sprint goal** : Owner can operate incidents without dev-senior backup. CI gate is real.
- **P0-10** [CLAUDE + OWNER, CP] Sign 4 critical runbooks (FISCAL_SEQUENCE_BREAK, KIOSK_NETWORK_LOSS, OUTBOX_BLOCKED, ROLLBACK_CANARY) with `php artisan` copy-paste commands. Owner plays each runbook in staging, iterates. Print plastified cheatsheet for Le Cayenne. — 8h Claude + 4h owner test
- **P1-25** [CLAUDE, //] Write `bin/deploy.sh` (atomic symlink flip + `composer install --no-dev` + `npm run prod` + `php artisan migrate --force optimize`) + `bin/rollback.sh` tested. Supervisor/systemd config for `queue:work` + `schedule:run`. — 6h
- **P0-15** [CLAUDE, //] Modify `.github/workflows/playwright.yml` : drop `e2e-required` label opt-in + drop `continue-on-error: true`. Define smoke pack 5 specs minimum bloquant (kiosk happy / POS cash / KDS bump / OSS update / fiscal Z-close). — 4h
- **P0-6** [CLAUDE, //] Replace `(int) $total * 100` Stripe charge with `bcmath` round-half-up cast. Regression test sur `€X.99` (P0-S-08). — 1h
- **P0-3** [CLAUDE, //] Audit `withoutGlobalScope(BranchScope::class)` in `PosOrderController::show` (specific case) + add explicit assertion `auth()->user()->branch_id === $order->branch_id`. — 2h
- **P1-22** [CLAUDE, //] SQL `UPDATE branches SET status=5 WHERE status=1` + sweep cleanup + fix `PersistCatalogChangedToOutbox.php:38-41`. — 1h
- **Dependencies** : Week 1 alerting + backups in place
- **Parallel-safe** : P1-25 + P0-15 + P0-6 + P1-22 (4 quick wins, independent files)
- **Critical path** : P0-10 (runbooks block ops readiness)
- **Estimated hours** : 22h Claude + 4h owner

### Week 3 — Order Collapse Sprint Kickoff
**Sprint goal** : LOCK doc owner-signed + tests RED for collapse + branch isolated.
- **P0-7** [CLAUDE + OWNER, CP] Invoke `/lock-plan` skill → generate `LOCK_ORDER_COLLAPSE.md` (file:line plan, fillable consolidated, observer single-attach, view scopes for read-only, data migration plan if divergent). RED-team review BEFORE execution. Owner gate sign-off. — 8h Claude + 2h owner gate
- **P0-7-tests** [CLAUDE] RED tests for Order/FrontendOrder collapse : POS vs Kiosk same row structure, fiscal sequence allocation, audit log, idempotency. — 12h
- **P1-20** [CLAUDE, //] KDS UX heal kickoff (8 P0 cross-validated audit 2026-05-11) : accordéon fermé default OPEN, banners no-stack, bump 32→44px, allergenModal typo, contrast 3.2:1 → 4.5:1, 18 raw labels FR → i18n keys. — 12h (week 3-4 split)
- **P0-11** [CLAUDE, //] Feature test cash trail POS direct-cash → CashMovement bout-en-bout (Sprint 1B follow-up + Wave Z gap). — 4h
- **Dependencies** : Week 2 done (alerting + runbooks + frozen-zone CI gate live)
- **Parallel-safe** : P1-20 + P0-11 (no shared files with Order collapse)
- **Critical path** : P0-7 LOCK doc owner-signed
- **Estimated hours** : 36h Claude + 2h owner

### Week 4 — Order Collapse Implementation
**Sprint goal** : Collapse Order/FrontendOrder GREEN tests.
- **P0-7-impl** [CLAUDE, CP] Implement collapse on feature branch dedicated. Single Order model, fillable consolidated `[parent_order_id, transaction_id, card_type, source_surface, fiscal_alloc_error_at, ...]`. Observer attach to Order in `AppServiceProvider.php:68`. Migrate `OrderService.php:1102` create FrontendOrder → create Order. — 24h
- **P0-7-tests-green** [CLAUDE] Run regression suite. Fiscal allocation OK. Audit log writes. Idempotency intact. — 8h
- **P1-20** [CLAUDE, //] KDS UX heal completion (visual gate per `CLAUDE.md` §6). — 8h
- **P1-19** [CLAUDE, //] Audit 39 `withoutGlobalScope(BranchScope::class)` occurrences — annotate each : (a) admin legitimate, (b) pre-auth lookup, (c) UNSAFE → fix. — 8h
- **Dependencies** : Week 3 LOCK doc signed + RED tests done
- **Parallel-safe** : P1-20 + P1-19
- **Critical path** : P0-7-impl
- **Estimated hours** : 48h Claude

### Week 5 — Order Collapse Hardening
**Sprint goal** : Order collapse production-grade + adjacent surfaces validated.
- **P0-7-hardening** [CLAUDE, CP] Edge case sweep : parked orders, kiosk pre-auth, fiscal allocation retry, audit chain HMAC integrity verify post-merge. — 16h
- **P0-7-RED** [CLAUDE] RED-team adversarial pass on collapsed Order. NF525 chain still gap-free. `composition_snapshot` immutable. — 8h
- **P1-19** [CLAUDE, //] Apply fixes to UNSAFE `withoutGlobalScope` sites identified Week 4. — 12h
- **P1-23** [CLAUDE, //] Replace 5 of 23 `assertTrue(true)` (NF525-critical paths first). — 4h
- **Dependencies** : Week 4 collapse impl GREEN
- **Parallel-safe** : P1-19 + P1-23
- **Critical path** : P0-7-hardening + P0-7-RED
- **Estimated hours** : 40h Claude

### Week 6 — Order Collapse Merge + Stabilize
**Sprint goal** : Order collapse merged to main. NF525 verified. State machine sole writer ready.
- **P0-7-merge** [CLAUDE + OWNER, CP] Owner-gate sign-off + merge feature branch. Triple-vert tests + visual gate. — 4h Claude + 2h owner gate
- **P0-7-monitor** [CLAUDE] 48h post-merge monitor for fiscal anomalies via alerting (Week 1). — passive
- **P0-12-prep** [CLAUDE, CP] RED tests for 5 mutations sites in OrderService.php (lines 1530, 1609, 1714, 1820, 1907). Each test asserts state-machine invariant respected (interdites bloquées, audit row, idempotency). — 16h
- **P1-19** [CLAUDE, //] Final UNSAFE `withoutGlobalScope` sweep. — 8h
- **P1-23** [CLAUDE, //] Replace 5 more `assertTrue(true)`. — 4h
- **Dependencies** : Week 5 P0-7 RED clean
- **Parallel-safe** : P1-19 + P1-23
- **Critical path** : P0-7-merge → P0-12-prep
- **Estimated hours** : 32h Claude + 2h owner

### Week 7 — OrderStateMachine Sole Writer
**Sprint goal** : `OrderStateMachine::apply()` is the only writer. CI lint enforced.
- **P0-12** [CLAUDE, CP] Replace 5 mutations in OrderService.php with `OrderStateMachine::apply($order, $next, $actor, $reason)`. GREEN tests. — 16h
- **P0-12-CI** [CLAUDE, CP] CI lint rule fail on `->status = ` in `app/Services/` + `app/Http/Controllers/`. Grep final = 0 hits hors `OrderStateMachine.php`. — 2h
- **P1-17** [CLAUDE] Document state machine doctrine in `docs/adr/0001-orderstatemachine-sole-writer.md`. — 2h
- **P1-23** [CLAUDE, //] Replace 5 more `assertTrue(true)` (state-machine paths). — 4h
- **Dependencies** : Week 6 collapse merged + P0-12-prep RED tests
- **Parallel-safe** : P1-23 only
- **Critical path** : P0-12 + P0-12-CI
- **Estimated hours** : 24h Claude

### Week 8 — POS LOCK Surgical Patch Start
**Sprint goal** : LOCK doc for POS wizard + RED tests + start ARIA + i18n.
- **P1-21-LOCK** [CLAUDE + OWNER, CP] `/lock-plan` skill → `LOCK_POS_WIZARD_A11Y.md` for `public/js/pos-wizard.js` + `admin-pos-v4.blade.php` (200 lignes diff scope-minimal). Owner gate. — 4h Claude + 2h owner
- **P1-21-impl** [CLAUDE] ARIA roles + touch targets 32→44px + var(--pos-v5-brand-red) + i18n keys `pos.wizard.*`. — 16h
- **P1-28-track1** [CLAUDE, //] FormRequest authz refactor top-5 endpoints (most exposed admin: items, orders, users, branches, fiscal). — 16h
- **P1-23** [CLAUDE, //] Replace remaining 8 `assertTrue(true)`. — 4h
- **Dependencies** : Week 7 sole-writer enforced
- **Parallel-safe** : P1-28 + P1-23
- **Critical path** : P1-21-LOCK
- **Estimated hours** : 40h Claude + 2h owner

### Week 9 — POS LOCK Completion + V1.0.1 Hardening Track
**Sprint goal** : POS patch merged. FormRequest authz progress. KDS hardened.
- **P1-21-tests** [CLAUDE, CP] E2E visual gate POS Vanilla wizard : Read screenshots each surface, ARIA validated, 44px confirmed, FR strings localized. — 8h
- **P1-21-merge** [CLAUDE + OWNER, CP] Owner-gate sign-off + merge. — 2h Claude + 2h owner
- **P1-28-track2** [CLAUDE, //] FormRequest authz endpoints 6-15. — 24h
- **P1-16** [CLAUDE, //] Refactor 5 of 14 controllers with `DB::transaction` inline → thin controller + service. — 16h
- **P1-29** [CLAUDE, //] Port `RushMidiSimulationTest` to MySQL CI matrix step (reuse `ci-sync-rupture-harness.yml` harness). Drop sqlite-memory self-doc. — 4h
- **Dependencies** : Week 8 POS impl done
- **Parallel-safe** : P1-28 + P1-16 + P1-29 (different file domains)
- **Critical path** : P1-21-tests + P1-21-merge
- **Estimated hours** : 54h Claude + 2h owner

### Week 10 — Hardening V1.0.1 Continued
**Sprint goal** : Remaining V1.0.1 backlog burned down.
- **P1-28-track3** [CLAUDE] FormRequest authz endpoints 16-40. — 32h
- **P1-16** [CLAUDE] Refactor remaining 9 controllers. — 24h
- **P0-14-plan** [CLAUDE] ULTRA-PLAN Laravel 9.52 → 10 → 11 migration (separate track V1.x — plan only week 10, exec separate cycle). — 4h
- **Dependencies** : Week 9 POS merged + V1.0.1 tracks established
- **Parallel-safe** : entire week is hardening parallel work
- **Critical path** : none (parallelism week)
- **Estimated hours** : 60h Claude

### Week 11 — Production Readiness Sweep
**Sprint goal** : All 40 checklist items from verdict §8 verified.
- **Checklist verification** [CLAUDE + OWNER] Verify 40 items in verdict §8 : Commandes (10), Cuisine (5), Paiements (6), Imprimantes (4), Droits (4), Sauvegardes (4), Logs (4), Tests (3). — 24h Claude + 8h owner
- **P0-11-extended** [CLAUDE] Extended cash trail tests : variance detect blocks close session, TPE BypassMode decision (owner). — 8h
- **P1-19-final** [CLAUDE] Final `withoutGlobalScope` audit pass + ADR. — 4h
- **P1-23-final** [CLAUDE] Final `assertTrue(true)` replacement (last few). — 4h
- **Dependencies** : Weeks 1-10 complete
- **Critical path** : Checklist verification
- **Estimated hours** : 40h Claude + 8h owner

### Week 12 — Final Gate + GO-LIVE Le Cayenne
**Sprint goal** : Owner DR drill in production. Cheatsheet plastified. Open Le Cayenne.
- **DR drill production** [OWNER] Restore from backup → verify outbox replay → close Z report → measure RTO/RPO. — 4h owner
- **Owner runbook walkthrough** [OWNER] Play 4 critical runbooks in production silent-mode (no real incident, walkthrough). — 4h owner
- **Soft-launch** [OWNER + CLAUDE] First real day Le Cayenne open. Claude on-call standby (via alerting Sentry+Slack). — passive
- **Retrospective** [CLAUDE] Push Graphiti episode `foodking` group + update PROJECT_BRAIN.md §2/§3/§4. — 2h
- **V1.x backlog re-prioritize** [CLAUDE] Plan for V1.x sprints (G7) : Laravel migration, Pinia migration, F-016b stock UI, Saga pattern, Stripe webhook idempotency. — 4h
- **Dependencies** : Week 11 checklist ≥ 90% green
- **Critical path** : Owner DR drill + soft-launch
- **Estimated hours** : 6h Claude + 8h owner

### Total budget Phase 1+2 (12 weeks)

| Resource | Hours | Notes |
|---|---|---|
| **Claude orchestrate + execute** | ~388h | over 12 weeks = ~32h/week avg ; week 4-5 peak ~48h |
| **Owner action** | ~32h | rotation, runbook signing, LOCK gate, DR drill, walkthrough |
| **Total** | ~420h | realistic for owner 60% bandwidth + Claude orchestration |

---

## §4 QUICK WINS — Top 8 items <2h with disproportionate downstream value

Ordered by leverage (impact per hour invested) :

| # | Item | Hours | Leverage | Goal blocked | Why huge leverage |
|---|---|---|---|---|---|
| **QW1** | **P0-8 mobile allergens default `[]`** | 0.25h | LEGAL/CRITICAL | G1 | 1-line fix, removes EU FIC 1169/2011 legal exposure on 60/60 items incl. eau minérale. `mobile/data/menu.js:274`. |
| **QW2** | **P1-26 archive `AGENTS.md` → `_LEGACY_2026-03.md`** | 0.1h | DOCTRINAL/HIGH | G5 | Rename + changelog entry. Closes the CLAUDE.md vs AGENTS.md doctrinal split that poisons every process discipline sprint downstream. |
| **QW3** | **P0-9 mobile promo button hide** | 0.5h | UX/HIGH | G1 | Hides "✓ Code appliqué" trompeuse banner sans discount. Customer-facing trust. `mobile/screens-main.jsx:595`. |
| **QW4** | **P1-22 `UPDATE branches SET status=5 WHERE status=1`** | 0.5h | OPS/HIGH | G3 | SQL one-liner + fix `PersistCatalogChangedToOutbox.php:38-41`. Closes documented bug carried 3+ cycles. |
| **QW5** | **P1-24 safety-check.sh array 2 → 13** | 0.5h | DISCIPLINE/CRITICAL | G5, G1 | Replace 2-file array with full 13-file list from CLAUDE.md §7 + memory/reference_frozen_zones.md. Without this the +6782 lignes drift keeps growing during Phase 1. |
| **QW6** | **P0-15 E2E `continue-on-error: false`** | 0.5h | TEST/HIGH | G5 | Single-line `.github/workflows/playwright.yml` change. Drops opt-in label. PRs can no longer ship green sans Playwright. |
| **QW7** | **P0-6 Stripe `bcmath` cast** | 1h | NF525/HIGH | G2, G4 | Replace `(int) $total * 100` with bcmath round-half-up + regression test €X.99. Fixes revenue loss + NF525 receipt mismatch. |
| **QW8** | **P0-13 PHPSpreadsheet `composer require ^2.0`** | 1.5h | CVE/CRITICAL | G1 | `composer update phpoffice/phpspreadsheet` + smoke test admin Excel import. Closes CVE-2024-45048 RCE reachable via admin import. |

**Total** : ~5h to land 8 quick wins covering G1, G2, G3, G4, G5 simultaneously. **Day-1 burst recommended** in Week 0.

---

## §5 RISKS OF SEQUENCING

### Risk A — Owner delays P0-1 rotation
**Symptom** : owner doesn't rotate AWS keys/APP_KEY/FISCAL secrets within 24h of receiving this roadmap.
**Consequence** : every commit from this cycle's onward re-embeds the leaked secrets in permanent git history. The audit verdict §0 P0 lists this first for a reason — it's the only finding that GETS WORSE WITH TIME if untreated. Detection probability via gitleaks-action increases daily.
**Mitigation** : Claude must NOT push any commit (except documentation-only) until owner confirms rotation. Block Week 1 entirely until P0-1 done.

### Risk B — Owner skips LOCK gate for P0-7 Order collapse
**Symptom** : owner accepts collapse work without `/lock-plan` skill output + signed LOCK doc.
**Consequence** : Order collapse is frozen-zone NF525-sensitive (`OrderStateMachine`, `ZReportService` chain). Bypassing LOCK = no triple-vert rollback path. If collapse breaks fiscal allocation, rollback impossible without DR restore (also gated by Week 1 P0-4).
**Mitigation** : LOCK doc is a HARD gate. Claude refuses to start Week 4 impl without owner-signed `LOCK_ORDER_COLLAPSE.md`.

### Risk C — Owner accepts "go faster" pressure → compress Order collapse to 2 weeks
**Symptom** : owner wants Le Cayenne open in 8 weeks not 12. Compresses P0-7 collapse from 4 weeks (weeks 3-6) to 2 weeks (weeks 3-4).
**Consequence** : 4-week estimate (per verdict §6 #8) is for collapse + RED-team + hardening + verify NF525 chain HMAC integrity. Halving it skips RED-team or hardening or NF525 verify. Each skip = production-grade risk that surfaces post-launch.
**Mitigation** : if owner insists, delay Le Cayenne open instead. NO-COMPROMISE on P0-7 quality. Document compression risk in PROJECT_BRAIN.md §4.

### Risk D — Parallel tracks (KDS heal Week 3-4, FormRequest Week 8-10) underestimated
**Symptom** : P1-20 KDS UX heal alone is 8 P0 items cross-validated by audit 2026-05-11 — likely 16-24h not 12h. P1-28 FormRequest authz on 88 endpoints — likely 50-70h not 56h.
**Consequence** : parallel tracks slip into the critical-path spine, delaying P0-12 sole-writer enforce (Week 7) or POS LOCK (Week 8).
**Mitigation** : parallel tracks are non-blocking BY DESIGN. If they slip, push to V1.x backlog (G7) and protect the critical path. Don't let scope creep cascade.

### Risk E — Owner delays runbook signing (Week 2)
**Symptom** : 4 critical runbooks remain `DRAFT_SKELETON_NOT_SIGNED` because owner doesn't have time to play them in staging.
**Consequence** : Week 12 final gate fails on "owner runbook walkthrough" — Le Cayenne opens WITHOUT operational confidence. First real incident at Le Cayenne = owner googling Laravel commands at 19h30 Friday.
**Mitigation** : Owner runbook play is BLOCKING for GO-LIVE. If owner can't allocate 4h Week 2 + 4h Week 12, delay GO-LIVE by 1 week.

### Risk F — Going faster than this roadmap
**Symptom** : owner wants to skip Phase 2 hardening (weeks 8-11) and open Le Cayenne at Week 7 (after P0-12).
**Consequence** : POS Vanilla wizard 0 ARIA + 32px touch targets remains as-is = accessibility liability + customer-facing UX defect. FormRequest authz 88 endpoints scheduled → not done = 88 endpoints depend on controller-internal authz checks (scattered, untested). Le Cayenne can technically OPEN at Week 7 but G6 V1.0.1 incomplete.
**Mitigation** : OK to open Le Cayenne at Week 7 IF owner accepts G6 hardening as soft-launch backlog and commits to weeks 8-11 retroactively. Document tradeoff in PROJECT_BRAIN.md.

### Risk G — Phase 3 multi-tenant gets pulled into Phase 1
**Symptom** : during Le Cayenne pilot, a friend restaurateur asks "can I have my menu too?" — owner is tempted to start items.branch_id migration immediately.
**Consequence** : Phase 3 (verdict §7) is 8-12 weeks structural work. Cramming into Phase 1 = abandoned Le Cayenne hardening + risk multi-tenant migration breaks single-tenant assumptions still load-bearing.
**Mitigation** : Phase 3 is OUT OF THIS 12-WEEK ROADMAP. Explicit refusal. Friend restaurateur waits until V2 commercial readiness (Phase 4, separate track).

---

## §6 CRITICAL-PATH GANTT (ASCII)

```
WEEK            0   1   2   3   4   5   6   7   8   9  10  11  12
                ──  ──  ──  ──  ──  ──  ──  ──  ──  ──  ──  ──  ──
P0-1 rotate     ████
P0-1b CI scan   ████
P1-26 doctrine  ██
P0-8 allergens  ██
P0-9 promo      ██
                ─── critical path spine ─────────────────────────
P0-2 RCE+abil       ████████
P0-13 PHPSpread     ████
P1-24 frozen-CI     ████████
P0-5 alerting       ████████
P0-4 backups        ████████████
                                ─── ops + quick wins ───
P0-10 runbooks          ████████████
P1-25 deploy.sh         ████████
P0-15 E2E block         ████
P0-6 Stripe cast        ██
P0-3 IDOR scope         ████
P1-22 branch.status     ██
                                    ─── Order collapse 4w ───
P0-7 LOCK + RED                 ████
P0-7 implement                      ████████████
P0-7 hardening                              ████████
P0-7 RED-team                                   ████
P0-7 merge                                          ████
P1-20 KDS heal                  ████████████
P0-11 cash trail                ████
P1-19 withoutScope              ████████████████
                                                ─── enforce ───
P0-12 sole writer                                       ████████
P0-12 CI lint                                               ████
P1-17 ADR                                                   ██
                                                    ─── POS LOCK ───
P1-21 LOCK+impl                                             ████████
P1-21 tests+merge                                               ████████
                                                            ─── V1.0.1 ───
P1-28 FormRequest                                               ████████████████
P1-16 controllers                                                   ████████████
P1-29 stress MySQL                                                  ████
P1-23 assertTrue                            ████████████████████████████
                                                                    ─── gate ───
Checklist 40 items                                                          ████████
DR drill production                                                                 ████
GO-LIVE Le Cayenne                                                                      █
```

### Legend
- `████` = item executes that week
- `──` = boundary between sprint phases
- Critical path = top-to-bottom chain of items that block downstream

### Key observations
1. **Week 0 is a 1-day burst** — 5 items in 5h total (3 quick wins + 2 doctrine fixes + 1 owner rotation). Maximum leverage day.
2. **Weeks 3-6 are dominated by the Order collapse spine** — but 3 parallel tracks (KDS heal, cash trail, withoutScope audit) keep utilization high without conflict.
3. **Week 7 is a chokepoint** — P0-12 sole-writer enforce REQUIRES P0-7 collapse done. No parallelism that week beyond CI lint + ADR.
4. **Weeks 8-11 are parallel hardening** — owner can pause if bandwidth tight; nothing blocks GO-LIVE except Week 11 checklist + Week 12 DR drill.
5. **Week 12 is OWNER-LED** — Claude is standby/passive. DR drill + runbook walkthrough + soft-launch = owner operational confidence proof.

---

## §7 FINISH-LINE DEFINITION — Le Cayenne GO-LIVE criteria

Per verdict §8 (40-item checklist). GO-LIVE = ≥ 90% green = ≤ 4 items yellow allowed (none in fiscal/payment/auth).

### Hard gates (must be 100% green, not just 90%)
- [ ] P0-1 AWS keys rotated + gitleaks pre-commit + composer audit CI
- [ ] P0-2 Sanctum abilities role-scoped + LanguageService route gated + force re-login executed
- [ ] P0-4 Backup quotidien auto + restore drill tested in staging
- [ ] P0-5 Alerting Slack + Sentry + BetterUptime live + tested
- [ ] P0-7 Order collapse merged + NF525 chain HMAC integrity verified
- [ ] P0-10 4 critical runbooks signed + owner walked-through
- [ ] P0-12 OrderStateMachine.apply() = sole writer + CI lint enforced
- [ ] P0-15 E2E smoke pack 5 specs bloquant CI
- [ ] DR drill production tested (Week 12 owner action)

### Soft gates (90% green target)
- P1-19 withoutGlobalScope audit completed + UNSAFE sites fixed
- P1-20 KDS UX 8 P0 healed
- P1-21 POS wizard LOCK patch ARIA + 44px + i18n merged
- P1-23 23 assertTrue(true) replaced with real assertions
- P1-25 bin/deploy.sh + bin/rollback.sh tested
- P1-28 FormRequest authz top-40 endpoints (out of 88)

### Backlog acceptable post-launch (V1.x — G7)
- P1-16 14 controllers DB facade refactor (target weeks 9-10)
- P1-18 Frontend API client layer + Composition migration
- P1-27 TPE driver natif (owner decision required)
- P1-30 Pinia migration
- P0-14 Laravel 9 → 11 (separate track)
- 17 advisories composer triage (incl. PHPSpreadsheet ≥ 2.0 already done QW8)

### Explicit V2 SaaS deferred (Phase 3+ per verdict §7 — OUT of this roadmap)
- items.branch_id + 7 catalog tables migration
- Billing / subscription / plan / Stripe Billing
- Onboarding command + signup flow + marketing site
- UberEats / Deliveroo / JustEat integrations
- DPA GDPR + DPIA + Privacy policy

---

## §8 REFERENCE INDEX

- **Audit verdict SSOT** : `reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md` §5 (P0 1-15, P1 16-30)
- **Detailed agent reports** : `reports/audit/cto-global-2026-05-16/agent-1-architect.md` through `agent-8-claude-dependency.md`
- **North-star goals** : `PROJECT_BRAIN.md` §1
- **Operating doctrine** : `CLAUDE.md` (post P1-26 archive, AGENTS.md becomes `AGENTS_LEGACY_2026-03.md`)
- **Frozen-zones** : `CLAUDE.md` §7 + `memory/reference_frozen_zones.md` + `scripts/check-frozen-zones.sh` (post P1-24 sync)
- **Production checklist** : verdict §8 (40 items)
- **Target architecture** : verdict §9
- **Phase 3+ scope (out of roadmap)** : verdict §7 Phase 3-5

---

— Fin EXECUTION_ROADMAP_V1 2026-05-16.
