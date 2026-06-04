# WI-7 STATUS — Documentation + CLAUDE.md alignment final audit

**Date** : 2026-05-19
**Mode** : AUDIT-ONLY (read-only, no edits, plan recommendations only)
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**HEAD audited** : `d5f934755`
**Session scope** : 113 commits since baseline `ec0d49241`
**Specialists** : 3 parallel (Architect / RED / Continuity-keeper)
**Deliverables** : this STATUS.md + 3 specialist JSONs under `specialists/`

---

## §0 Executive verdict

**Documentation state** : GREEN-with-drift.

The repository's working memory (`PROJECT_BRAIN.md`) tracks reality within ~14 commits; the stable memory (`CLAUDE.md`) has accumulated **10 days and ~450 commits of drift** since it was last edited at commit `9d9dddae1` (2026-05-09). Four material content drifts are documented below; one is a hard CI-locked contradiction (BranchScope §9). All NF525 fiscal attestations and frozen-zone diff = 0 claims are empirically verified true at audit time.

**Adversarial RED winner** : **CLAUDE.md §9 says "11 models" + "User exempted" — code declares BranchScope on 20 models + User IS scoped + Customer is the recursion-exempt one** (see `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` CI baseline-lock).

No documentation file is materially dangerous (no "doc tells operator to do X, but X breaks invariant"). Drifts are coverage-gaps + stale pointers, not active misdirection.

---

## §1 4-list categorization

### ALIGN-AS-IS (no change required)

| Doc | Status | Evidence |
|---|---|---|
| `docs/ORDER_FLOW.md` | Accurate | Mermaid state diagram matches `app/Domain/Order/OrderStateMachine.php` |
| `docs/BUSINESS_RULES.md` | Accurate | Pricing SSOT + discount cascade + coupon rules still hold |
| `docs/AUTHZ_MATRIX.md` | Accurate (minor OSS Echo gap) | POS Phase 9 permission matrix verified live |
| `docs/IDEMPOTENCY.md` | Accurate | matches `IdempotencyKeyMiddleware.php` behaviour |
| `docs/OUTBOX_PATTERN.md` | Accurate | matches Outbox listeners shipped Wave 3 |
| `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` | Accurate (WI-1 covered) | Wave 5I bootstrap aligned |
| CLAUDE.md §1–§6 (identity / mission / principles / architecture / LOOP / Visual Test Mandate) | Accurate | core operating doctrine unchanged |
| CLAUDE.md §10–§14 (decision framework / memory discipline / anti-drift / evidence / style) | Accurate | mandate intact |
| PROJECT_BRAIN.md §2 NF525 attestations | Verified live | `php artisan fiscal:verify-chain` returns `CHAIN OK` at audit time |
| PROJECT_BRAIN.md frozen-zone diff = 0 attestations | Verified | `git diff --stat` empty for 13 §7 files since 9d9dddae1 |

### STALE-DOCS (description outdated but not contradicting code)

| Doc | Issue | Recommendation |
|---|---|---|
| `docs/ARCHITECTURE.md` (2026-03-10) | No mention of Outbox, Domain Events, OrderStateMachine, BranchScope | Add 'V1.0.1 hardening' addendum pointing to OUTBOX_PATTERN.md, EVENT_CONTRACT.md, FISCAL_SECRETS.md. Do not rewrite — point. |
| `docs/PROJECT_CONTINUITY_AND_VISION.md` §3.1 | POS amend = "à spécifier, largement non implémenté" | One-line update: "V1.0.1: PosParkedOrder + RefundWithCounterEntryService handle amend via refund-and-recreate." |
| CLAUDE.md §15 | References `reports/antigravity/` (legacy) | Replace with `reports/test-e2e/` |
| CLAUDE.md §15 | Cites `MASTER_ITER14_V1_HARDENING_DELIVERY_2026-05-09.md` as "last delivery" — 8 newer GOAL/MASTER plans since | Replace with pointer-to-BRAIN-§2 |
| MEMORY.md (user-level) §START HERE | "Branche v1-0-1-hardening-2026-05-17 HEAD 1e7c65ecc" | Real current = `heal/cms-pr1-quickwins-2026-05-18` HEAD `d5f934755` (or post-Wave-E `9624ff74e`) |

### DRIFT-RECOMMENDATIONS (CLAUDE.md proposed patches — owner-gate before applying)

These are concrete recommended diffs to `CLAUDE.md` after owner-sign-off. **They are NOT applied** (WI-7 mandate AUDIT-ONLY).

#### D1 — §7 Frozen list incomplete (P1)
**Current §7 list** (13 files): kiosk Vue × 3 + pos-wizard × 3 + fiscal × 3 + multi-tenant × 4.
**Add** (per BRAIN §2 line 60 "untouched protected files", paths verified at audit time):
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`

**Why** : BRAIN repeatedly attests these untouched in 6+ session entries (Ultra-goal, V1.0.1, Wave Z). Operationally frozen; should be canon.

#### D2 — §8 NF525 production boot guards (P2)
**Add** to §8 (after "Aucun env flag pour bypass") :

> Production boot guards in `app/Providers/AppServiceProvider.php:78-145` REFUSE TO BOOT if `POS_SIMULATION_HARDWARE!=false`, `IDEMPOTENCY_MIDDLEWARE_ENABLED!=true`, `APP_DEBUG=true`, or `APP_URL` is empty. Added by commits `2477a2d05`, `dafb6b3c4`, `1e7c65ecc`, `2949e92ed`.

**Why** : abstract invariant ("forbidden") + concrete enforcement (RuntimeException at boot) — currently doc states the rule but not the mechanism.

#### D3 — §8 TRUNCATE bypass concrete reference (P2)
**Replace** : "TRUNCATE bypass mitigé via GRANT level (deploy doc)"
**With** : "TRUNCATE bypass mitigé via GRANT-level REVOKE on `audit_logs` + `z_reports` (Ansible task CVP0-1, commit `f840c3ef5`)."

#### D4 — §9 BranchScope paragraph (P0 hard contradiction) — STRONGEST
**Current §9 says** (3 lines):
> `BranchScope` global appliqué sur 11 models post iter11+12 : Order, FrontendOrder, OrderItem, OrderPayment, KioskMachine, StockLevel, StockMovement, CashDrawerSession, CashMovement, PendingPaymentConfirmation, PushNotification, DiningTable, Printer
> ...
> User model exempté pour éviter Sanctum recursion

**Code reality** (`grep 'addGlobalScope(new BranchScope' app/Models/*.php`):
- 20 models DECLARE BranchScope (not 11, not the 13 listed)
- User.php:90 declares `static::addGlobalScope(new BranchScope())` (User IS scoped, NOT exempt)
- Customer.php has NO BranchScope declaration (Customer IS the Sanctum-recursion-exempt)
- `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` line 48 is the CI baseline-lock: Customer is documented as `'Sanctum customer-token recursion risk (per CLAUDE.md §9)'` — i.e. the sentinel ALREADY references CLAUDE.md §9, but §9 says "User" while the sentinel exempts Customer. The two docs are mutually contradictory.

**Recommended replacement §9 (BranchScope paragraph)** :

> `BranchScope` global appliqué sur **20 models** (baseline locked by `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php`) :
> Order, FrontendOrder, OrderItem, OrderPayment, OrderQuote, PosParkedOrder, KioskMachine, StockLevel, StockMovement, ItemBranchAvailability, CashDrawerSession, CashMovement, DeliveryBoyCashSession, DeliveryBoyCashMovement, PendingPaymentConfirmation, PaymentTerminal, PushNotification, DiningTable, Printer, User.
>
> **Exemptions documentées** : Branch (self-reference), Customer (Sanctum customer-token recursion). V1.0.2 backlog : 9 additional models (FrontendDiningTable, ZReport, AuditLog, OrderDiscountLog, Message, DiningTableAuditLog, KioskPromo, UpsellRule, ActionLog, DomainEvent — single-tenant V1 low-risk, V2 SaaS hard-fail per the sentinel). `ItemWizardProfile` uses the nullable variant `WizardProfileBranchScope` (global-or-branch published).
>
> Admin (branch_id=0) bypass ; staff (branch_id>0) scoped.

#### D5 — §9 FormRequest authz status (P1)
**Replace** : "FormRequest authz scattered → roadmap V1.0.1 refactor 88 endpoints"
**With** : "FormRequest authz unified on baseline **66 of 88** endpoints (CI sentinel `tests/Feature/Authz/FormRequestAuthzSentinelTest.php` or equivalent, baseline-lock pattern). V1.0.2 backlog : 22 remaining endpoints, chip-away per commit cadence. See BRAIN §2 commits `c86fabb7a` `0c824ddbd` `68b63c090`."

#### D6 — §15 plans + reports pointer (P2)
**Replace** :
- `plans/MASTER_ITER14_V1_HARDENING_DELIVERY_2026-05-09.md` — last delivery
- `reports/antigravity/` (Playwright cycle reports)

**With** :
- "Active plan : see `PROJECT_BRAIN.md` §2 for current GOAL pointer (rotating, current `plans/GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md` + Wave E follow-ons)."
- "Playwright cycle reports : `reports/test-e2e/`"

#### D7 — §9 Sanctum TTL roadmap note (P3)
**Add** after current TTL claim : "V1.0.1 roadmap (BRAIN §1) : TTL 8h → 1h sensitive ops."

#### D8 — §0 / header stamp (P2)
After applying D1–D7, change header to: "FoodKing Master Operating Memory (Claude Code edition, **2026-05-19 post WI-7**)" and re-anchor the "iter15 ultra-review 2026-05-09" reference at §7 to a session-history footnote.

### SAFE-TO-ARCHIVE (housekeeping, no functional impact)

| Target | Volume | Rationale |
|---|---|---|
| `plans/PLAN_CV1-*`, `plans/PLAN_CAISSE_V1_*` pre-2026-05-09 | ~80 files | Cycle CV1 and Caisse V1 W1/W2 closed; superseded by V1.0.1/V1.0.2 |
| `plans/E2E_MASSIVE_AUDIT_P*_2026-05-04.md` | 5 files | Cycle closed per BRAIN entries |
| User-level `MEMORY.md` entries pre-2026-05-13 | ~50 line pointers | Move to `MEMORY-ARCHIVE.md`; keep §🆕 START HERE pointer fresh |
| `docs/cursor-handoff/` | full subtree (Cursor era) | CLAUDE.md preamble states "remplace la version Cursor obsolète" — these are historical |
| `docs/HANDOFF_NEW_CURSOR/` | full subtree | Same rationale |

Recommended location : `plans/archive/2026-04-pre-iter14/` + `docs/archive/cursor-era/`.

---

## §2 NF525 invariants verification (§8 of CLAUDE.md)

All 5 stated invariants verified at audit time:

1. **Pricing SSOT** — `app/Services/Pricing/PricingService.php` exists, frozen-zone diff = 0 since 2026-05-09. `composition_snapshot` cast as array on `app/Models/OrderItem.php:71`. INSERT-only attested by Critical Focus Z5 zone (5 INSERT, 0 UPDATE).
2. **Fiscal sequence monotonic** — `app/Services/Fiscal/FiscalSequenceService.php` present + frozen. Critical Focus Z2 verified `fiscal_sequence_no=354` monotonic.
3. **HMAC-SHA256 audit chain** — `app/Services/Fiscal/AuditLogService.php:20` doc + `:242` `hash_hmac('sha256', ...)`. Live verification: `php artisan fiscal:verify-chain` returns `CHAIN OK (audit_logs + z_reports) (branch=1)`.
4. **DB triggers BEFORE DELETE** — `database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`, `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php`, `2026_05_16_130000_add_cash_movements_delete_trigger_sqlite.php`, `2026_05_18_120300_add_delivery_boy_cash_no_delete_triggers_sqlite.php`, `2026_05_18_140000_add_stock_movements_immutability_triggers.php` present.
5. **TRUNCATE GRANT REVOKE** — now Ansible task CVP0-1 (commit `f840c3ef5`). Doc claim "deploy doc" is vague — fix recommended D3.

**Verdict** : §8 invariants all upheld in code; doc upgrades recommended (D2, D3) to surface concrete enforcement.

---

## §3 Multi-tenant + Auth invariants verification (§9 of CLAUDE.md)

| Claim (CLAUDE.md §9) | Verified ? | Evidence |
|---|---|---|
| BranchScope on 11 models | **NO** — 20 models declare | grep `addGlobalScope(new BranchScope` returns 20 hits |
| User model exempt | **NO** — User.php:90 declares scope | `grep -n "BranchScope" app/Models/User.php` |
| Admin (branch_id=0) bypass | YES | BranchScope.php unchanged |
| Sanctum TTL 480 min | YES | config/sanctum.php:51 = 480 (default env) |
| tokenCan('kiosk:order') in 6+ controllers | YES — 8 controllers | `grep -rn "tokenCan('kiosk:order')" app/Http/Controllers/` returns 8 hits |
| `permission:settings` Spatie gate | YES | routes/api.php uses `permission:catalog.publish`, `permission:ingredients_manage`, `permission:customers_show...` (richer than CLAUDE.md §9 implies) |
| FormRequest authz roadmap 88 endpoints | OUTDATED — 22 remaining | Sentinel baseline 88→66 commits c86fabb7a + 0c824ddbd |
| Idempotency `X-Idempotency-Key` + dual layer | YES | IdempotencyKeyMiddleware unchanged, scope (branch_id, user_id, hash(key)) |
| `webhook_events` UNIQUE | YES | WebhookEvent model + DB constraint present |

**Verdict** : §9 has TWO factual errors (count + User exemption) plus ONE outdated status (FormRequest authz). Fix via D4 + D5.

---

## §4 Plans folder bucket summary

`plans/` contains **153 .md files** (per `ls plans/*.md | wc -l`).

Bucket by date:

| Bucket | Date range | Count (approx) | Action |
|---|---|---|---|
| Active GOAL/MASTER | 2026-05-16 to 2026-05-19 | 20 | KEEP visible |
| LOCK plans (V1.0.2 owner-gate) | 2026-05-17 to 2026-05-18 | 5 | KEEP visible (G4/G5/G6 + POS Loyalty + Fiscal anon) |
| V1.0.1 hardening | 2026-05-08 to 2026-05-14 | 12 | KEEP visible |
| Mid-cycle 2026-04-26 to 2026-05-08 | iter11→iter14, V1.5* | ~36 | REVIEW; keep iter14 anchors per CLAUDE.md §15 |
| Early-cycle 2026-04-14 to 2026-04-25 | P9_*, P10_*, MEGA_PLAN_* | ~80 | ARCHIVE candidate (`plans/archive/2026-04-pre-iter14/`) |

No active plan contradicts code state. No deferred plan accidentally already healed without doc closure (BRAIN §1 V1.x backlog tracks closures via strike-through).

---

## §5 Adversarial RED finding (primary)

See `specialists/red-team-contradictions.json` for full evidence chain.

**Single strongest contradiction** :
- CLAUDE.md:274 (lines 274–279) : "BranchScope global appliqué sur **11 models**" + "User model **exempté** pour éviter Sanctum recursion"
- Code : `app/Models/User.php:90` declares `static::addGlobalScope(new BranchScope())` ; 20 models total declare the scope ; `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php:48` documents **Customer** (not User) as the Sanctum-recursion exempt

This is a CI-locked contradiction : the sentinel test `BranchScopeCoverageSentinelTest` ALREADY references "CLAUDE.md §9" as the canon for Customer exemption, while §9 says "User". A new contributor reading §9 would believe User is exempt, whereas the code actively scopes it.

**Severity** : P0 for documentation truth ; **P3 for runtime risk** (the discrepancy is documentation-only, runtime behaviour follows code = correct).

**Recommended fix** : D4 above.

---

## §6 Confidence + caveats

- **High confidence** : §2 NF525 verification (`php artisan fiscal:verify-chain` executed live), §3 multi-tenant verification (grep counts authoritative), §5 RED contradiction (CI-locked sentinel as ground truth).
- **Partial coverage** : BRAIN §4 NEXT TO DO and §7 VERIFICATION CHECKLIST sections were not directly inspected (BRAIN size 155 KB exceeds Read tool 25k-token limit). Three sectional reads (lines 1–250, scan of §2/§3) bound the audit. CONT-07 flags this gap.
- **No edits applied** : per WI-7 mandate AUDIT-ONLY. The 8 recommended diffs (D1–D8) require owner-sign-off before any edit to `CLAUDE.md`.

---

## §7 Recommended owner-sign-off action items

For owner gate (CLAUDE.md §10 — modification authorisation) :

| ID | Action | Severity | Effort |
|---|---|---|---|
| D1 | Add 2 PaymentComponent files to CLAUDE.md §7 | P1 | 2 LOC |
| D2 | Document AppServiceProvider boot guards in §8 | P2 | 3 LOC |
| D3 | Replace TRUNCATE "deploy doc" with Ansible CVP0-1 | P2 | 1 LOC |
| **D4** | **Rewrite §9 BranchScope paragraph (P0 contradiction)** | **P0** | **8 LOC** |
| D5 | Update FormRequest authz roadmap status | P1 | 2 LOC |
| D6 | Replace §15 stale plan + reports pointers | P2 | 4 LOC |
| D7 | Add Sanctum TTL roadmap note | P3 | 1 LOC |
| D8 | Stamp header "2026-05-19 post WI-7" + footnote | P2 | 2 LOC |
| ARCH-04 | Update PROJECT_CONTINUITY_AND_VISION.md §3.1 POS amend status | P2 | 1 LOC |
| CONT-01 | Update user-level MEMORY.md §START HERE pointer to current HEAD + branch | P1 | 4 LOC |
| CONT-03 | Strike-through password policy in BRAIN §1 V1.0.1 list (DONE) | P1 | 2 LOC |

Total : **~30 LOC of documentation changes**, zero code touch, zero frozen-zone risk.

**Estimated execution time post-sign-off** : 15 minutes (read CLAUDE.md once, apply 8 Edits, re-verify with grep).

---

## §8 Deliverables index

- `STATUS.md` (this file) — synthesis + 4-list + recommended diffs
- `specialists/architect-doc-structure.json` — docs/ folder consistency audit (8 findings)
- `specialists/red-team-contradictions.json` — adversarial 7 contradictions (1 P0)
- `specialists/continuity-keeper-brain-memory.json` — MEMORY.md + BRAIN coherence (8 findings)

**Total findings** : 23 (Architect 8 + RED 7 + Continuity 8)
**Frozen-zone touch** : 0 (mandate)
**NF525 chain attestation at audit time** : `CHAIN OK (audit_logs + z_reports) (branch=1)`
**Branch + HEAD verified** : `heal/cms-pr1-quickwins-2026-05-18` @ `d5f934755`

---

*WI-7 documentation audit COMPLETE. No edits applied per AUDIT-ONLY mandate. Owner-sign-off required before any CLAUDE.md or PROJECT_BRAIN.md edit per CLAUDE.md §10.*
