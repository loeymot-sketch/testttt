# TASK LIST — SUPERVISOR 100% (ultra-disciplined execution goal)
**2026-06-09 · derived from** `GOAL_SUPERVISOR_100_PRODUCTION_PERFECT_2026-06-09.md` **+** `reports/test-e2e/supervisor-100-2026-06-09/findings.json`
**Audit base:** `heal/pre-cloud-exec-2026-06-05` @ b97bd6474 (deployed, served :8767). **20 findings: 0 P0 · 5 P1 · 11 P2 · 1 P3.**

---

## §0 — DISCIPLINE CONTRACT (read before touching a single task — non-negotiable)
1. **DB safety:** the operating `foodking` DB is shared by `:8000`/`:8765`/`:8767`. **NEVER mutate via browser or test there.** All mutation-E2E + RefreshDatabase tests run on the **disposable `:8766` clone** (`reference_e2e_harness_foodking_e2e`). Verify `.env.testing`/`.env.e2e` before any `php artisan test`/`migrate`.
2. **Frozen zones (LOCK + owner gate, or triple-vert):** `public/js/pos-wizard.js`+css+blade · `PaymentComponent.vue` · `v5/PosV5TrancheRow.vue` · kiosk `Kiosk{Wizard,App,Upsell}Component.vue` · `Fiscal/{FiscalSequence,ZReport,AuditLog}Service.php` · `PricingService.php` · `BranchScope.php` · `IdempotencyKeyMiddleware.php` · `OrderStateMachine.php`. **A task touching these is GATE-BLOCKED until §G cleared.**
3. **NF525:** no env-bypass; `composition_snapshot` frozen at creation; chain append-only; re-attest `audit_logs` count+last_hash before/after every wave (must be unchanged or append-only).
4. **Evidence-before-done (per task):** PHPUnit/Vitest filter GREEN (named test path) → `git diff --stat` frozen-list = 0 → live `e2e_check` GREEN on `:8766` → screenshot Read+analyzed if frontend → NF525 re-attest. **A green test alone ≠ done.**
5. **Heal cap:** max 3 self-correction loops on one cluster → STOP + escalate with root-cause (CLAUDE.md §10).
6. **Git:** commit own work with **explicit pathspec** (`git add <files>` + `git commit -m … -- <files>`). **NEVER `git add -A`/`.` or `--amend` in this shared worktree** (collision-proven 2026-06-09). **NEVER push** without owner.
7. **Per-task LOOP:** orchestrate → plan → execute (scope-minimal) → audit → test → visual/e2e → self-correct → update BRAIN.
8. **V1-LOCAL fence:** in-scope = bugs/FR-locale/NF525/sync/UX on used surfaces. Out-of-scope (never a task): cloud/scale/multi-tenant/hardware-not-wired.

---

## §1 — TRIAGE (the smart part — what can start NOW vs blocked)
**🟢 READY NOW (no owner gate, non-frozen):** T1.1 (merge-manifest) · T3.1–T3.5 (FR-locale: money/time/payenum/phone/studio-i18n — though T1 integration may resolve several) · T4.1 BORNE-01 · T4.2/T4.3 KDS recall · T4.4 CAISSE-02 · T5.1 CENTRAL-P1-01 · T5.2 cat-label · T5.3 runbook · TV.1 stand up `:8766`.
**🔴 GATE-BLOCKED (owner decision first):** T1.2–T1.4 (need GATE-INT-1: which branch) · T2.x CAISSE-01 (GATE-FROZEN-1) · T4.5 BORNE-02 (only if Plan-B flipped) · T6.1 loyalty (GATE-LOYALTY-1) · T6.2 legal (GATE-PUBLISH-1) · prod DB reset (GATE-DATA-1).
**⚠️ NOTE:** start T1.1 (manifest) + the gate decisions in parallel; most FR-locale tasks are *subsumed* by T1 integration — confirm against the sibling branch before doing them net-new (avoid double work).

---

## §G — OWNER GATES (Wave 0 — unblock first; WHO/WHAT/WHERE)
| Gate | Decision | WHO | WHAT unblocks it | Unblocks |
|---|---|---|---|---|
| **GATE-INT-1** | Which branch is the integration target; which tree the live server serves | Owner | written line in BRAIN §2 + merge-manifest | T1.2–T1.4, all FR-locale |
| **GATE-FROZEN-1** | CAISSE-01: LOCK `pos-wizard.js` OR fix server-side in `PricingService` | Owner | LOCK doc countersign OR "server-side" instruction | T2.x |
| **GATE-LOYALTY-1** | Canonical mobile earn ratio (evidence → 10 pt/€) | Owner | signed note in `mobile/data/loyalty.js` | T6.1 |
| **GATE-PUBLISH-1** | Is `/Downloads/web` ever published? | Owner | publish decision recorded | T6.2 urgency |
| **GATE-DATA-1** | Clean-state reset of operating DB (200 soak orders / 146 SLA) + NF525 6-yr retention handling | Owner | reset plan + retention decision | prod cutover |

---

## §2 — TASK TABLES BY WAVE
> Columns: **ID · Task · Finding · Files · Acceptance (test path) · e2e_check (:8766) · Gate/Frozen · Status**

### WAVE 1 — INTEGRATION (RC-01) — *highest leverage; resolves CENTRAL-P1-02 + several SWEEP findings for free*
| ID | Task | Finding | Files | Acceptance | e2e_check | Gate | Status |
|---|---|---|---|---|---|---|---|
| T1.1 | Produce divergence manifest (`git log A..B`, `..A`, `diff --name-status`) | RC-01 | `reports/release/branch-merge-manifest.md` (TO CREATE) | file lists 15+20 commits + 66 files, keep/drop each | — | — | 🟢 READY |
| T1.2 | Integrate ≥`a952c5f72` + dashboard-excellence onto chosen branch; resolve `fr.json` conflict (wizard-builder keys AND FR fixes survive) | RC-01, CENTRAL-P1-02 | `resources/js/languages/fr.json`, sibling commits | merged `fr.json` has `'no':'Non'` + wizard keys + FR validation; 0 `<<<<<<<` | delete dialog FR "Êtes-vous sûr?/Oui, supprimer" | GATE-INT-1 | 🔴 |
| T1.3 | Rebuild bundles post-merge | RC-01 | `public/js/*`, `mix-manifest.json` | `BundleFreshnessSentinel` specs green + `grep 'Yes, Delete it!' public/js/app.js = 0` | — | GATE-INT-1 | 🔴 |
| T1.4 | Frozen-zone integrity post-merge | RC-01 | — | `FrozenZoneSha256BaselineSentinelTest` GREEN | — | GATE-INT-1 | 🔴 |

### WAVE 2 — REVENUE / FISCAL (CAISSE-01) — *POS under-bill, both halves confirmed*
| ID | Task | Finding | Files | Acceptance | e2e_check | Gate | Status |
|---|---|---|---|---|---|---|---|
| T2.1 | Decide route: frozen `pos-wizard.js` (LOCK) vs server-side reprice | CAISSE-01 | — | owner LOCK doc OR "server-side" | — | GATE-FROZEN-1 | 🔴 |
| T2.2 | Fix: emit Grande/Cheddar as priced `item_extras` (id+price) OR have `PricingService` resolve the named `menu_extras` upgrades | CAISSE-01 | `pos-wizard.js:4153-4159` (frozen) OR `app/Services/Pricing/PricingService.php` | `(test TO CREATE: tests/Feature/Pos/PosWizardFritesUpgradeBillingTest.php)` RED→GREEN | order.total == wizardRecap | GATE-FROZEN-1 | 🔴 |
| T2.3 | DB-assert under-bill closed | CAISSE-01 | — | `order_items.item_extra_total == 2.00` for Grande+Cheddar | :8766 POS wizard → checkout → DB read | — | 🔴 |

### WAVE 3 — FR-LOCALE residue *(confirm not already carried by T1 before doing net-new)*
| ID | Task | Finding | Files | Acceptance | e2e_check | Gate | Status |
|---|---|---|---|---|---|---|---|
| T3.1 | Uniform FR money formatter | SWEEP-MONEY-01, LIVE-I18N-MONEY | items/transactions/studio render paths | money renders `1,50 €` not `1.50` everywhere | items+transactions show `X,XX €` | — | 🟢 (or via T1) |
| T3.2 | FR 24h time formatter | SWEEP-TIME-01 | sales-report/transactions date render | DATE renders `13:41` not `01:41 PM` | — | — | 🟢 (or via T1) |
| T3.3 | Payment-mode enum → FR label | SWEEP-PAYMODE-01 | transactions payment-mode render | shows "Espèces/Mobile" not `COUNTER_CASH` | — | — | 🟢 (sibling has fix → via T1) |
| T3.4 | Fix employee phone `null`-concat | SWEEP-EMP-01 | employees list phone formatter | phone renders clean, no `null` prefix | employees list clean phone | — | 🟢 READY |
| T3.5 | Add missing fr key `studio.product_composer_button` | SWEEP-STUDIO-I18N | `resources/js/languages/fr.json` (+en/ar) | `$t` resolves; 0 intlify warnings on /admin/items/studio | studio button labeled (not empty) | — | 🟢 READY |

### WAVE 4 — OFFLINE / SYNC resilience (all non-frozen)
| ID | Task | Finding | Files | Acceptance | e2e_check | Gate | Status |
|---|---|---|---|---|---|---|---|
| T4.1 | 409 IDEMPOTENCY_KEY_CONFLICT = terminal success (not abandon) | BORNE-01 | `kioskOfflineQueue.js:561-587,340-348` | `(test: resources/js/helpers/__tests__/kioskOfflineQueue spec)` 409→synced+=1, abandonedNew=0 | — | — | 🟢 READY |
| T4.2 | Legacy `?v2=0` inline recall hits server | KDS-OSS-01 | `store/modules/kds.js:66-85`, `KitchenDisplaySystemComponent.vue:1895-1903` | Vitest: recallItem POSTs `/api/admin/kds-order/recall/{order}` | :8766 /kds?v2=0 recall → server POST + OSS reverts | — | 🟢 READY |
| T4.3 | Recall state in poll payload (WS-down recovery) | KDS-OSS-02 | `KitchenDisplaySystemOrderService`, KDS render | poll payload includes `recalled_at`; KDS renders RAPPELÉ from poll | :8766 2 screens, B WS-down → recall on A → B shows in 1 poll | — | 🟢 READY |
| T4.4 | Idempotent parked-recall (SoftDelete) | CAISSE-02 | `PosParkedOrderService::recall`, `routes/api.php:924` | feature test: re-GET after recall returns snapshot (not 404) | :8766 park→recall→re-recall=snapshot | — | 🟢 READY |
| T4.5 | Counter-conversion for refused-card order | BORNE-02 | `KioskErrorPaymentRefusedComponent.vue:92-98` + backend endpoint | feature test: payCounter → order in KDS + counter record | :8766 (Plan-B off) refuse card → payCounter → KDS visible | only if Plan-B flipped | 🔴 |

### WAVE 5 — ROBUSTNESS / POLISH
| ID | Task | Finding | Files | Acceptance | e2e_check | Gate | Status |
|---|---|---|---|---|---|---|---|
| T5.1 | `catch(\Throwable)` + Vue error-state (Variante tab) | CENTRAL-P1-01 | `ItemVariationController.php:39`, `ItemVariationListComponent.vue:121-125,51` | induced `\TypeError`→422 (not 500); Vitest rejected-mock→error banner, 200-empty→empty-state | :8766 Variante tab: ≥1 var renders table; forced 5xx→error banner | — | 🟢 READY |
| T5.2 | Disambiguate twin "Sandwich …" category chips | LIVE-UX-CAT | POS category bar render | two sandwich categories distinguishable | :8767 POS category bar | — | 🟢 READY |
| T5.3 | Runbook: KDS/OSS under chef@/pos@ (branch_id=1), not admin@ | SPINE-01 | `PROJECT_BRAIN.md §9` | owner-confirmed line present | — | — | 🟢 READY |

### WAVE V — VALIDATION SWEEP (proves the fixes; mutation E2E)
| ID | Task | Acceptance | Status |
|---|---|---|---|
| TV.1 | Stand up disposable `:8766` foodking_e2e clone (`APP_ENV=e2e php artisan serve --port=8766`) | clone up, chain OK, resettable | 🟢 READY |
| TV.2 | Run every closed finding's `e2e_check` on `:8766` | each GREEN; screenshots Read | after fixes |
| TV.3 | Kiosk→KDS→OSS sync mutation proof (place order, observe propagation <3s on chef@ screen) | order propagates; WS+polling both verified | after fixes |
| TV.4 | Destructive-button exercise (delete/save/import) on `:8766` | each FR-confirm + correct effect, no orphan | after fixes |
| TV.5 | NF525 re-attest + frozen-diff 0 + 2 identical adversarial passes | P0+P1=0, identical sets twice, chain append-only | gate to ship |

### WAVE 6 — STANDALONE (publish-gated; only if web/mobile wired/published)
| ID | Task | Finding | Acceptance | Gate | Status |
|---|---|---|---|---|---|
| T6.1 | Loyalty `computeEarnedPoints(total)=round(total*earn_ratio)` on 3 sites + history + seed reconcile | WEBAPP-L1 | `computeEarnedPoints(13)===130` at ratio 10; banner==credit | GATE-LOYALTY-1 | 🔴 |
| T6.2 | Owner fills 29 LCEN legal placeholders (éditeur+host identity) | WEBAPP-L2 | `grep -rc 'À COMPLÉTER' /Downloads/web/legal = 0` | GATE-PUBLISH-1 | 🔴 |

---

## §3 — DEFINITION OF DONE (the goal — 100%, not 99%)
The GOAL is DONE only when **ALL** hold:
- [ ] RC-01 closed → **one integrated branch** exists (T1.x), bundles fresh, frozen-SHA green.
- [ ] CAISSE-01 charges what it shows (T2.x), DB-asserted on `:8766`.
- [ ] FR-locale uniform (T3.x): money `1,50 €`, time `13:41`, no raw enums, no `null`-phone, no empty i18n labels.
- [ ] Offline/sync resilient in degraded mode (T4.x): 409-success, server recall, poll recovery, idempotent parked-recall.
- [ ] Robustness (T5.x): no error-as-empty masking; categories disambiguated; runbook line present.
- [ ] **Every `e2e_check` GREEN on `:8766`** (TV.2) + sync proof (TV.3) + destructive-button pass (TV.4).
- [ ] NF525 chain append-only/unchanged; **frozen-zone diff = 0** across the whole GOAL; two consecutive adversarial passes return **P0+P1 = 0 with identical sets** (TV.5).
- [ ] Operating DB clean-state decided + applied (GATE-DATA-1) before prod cutover.

**Until then: partial > wrong, blocked > silently dangerous.** No "almost". No false green.
