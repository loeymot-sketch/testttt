# GOAL — SUPERVISOR 100% PRODUCTION-PERFECT (Le Cayenne V1-LOCAL)
**Date:** 2026-06-09 · **Supervisor:** Claude (orchestrator brain) · **Mode:** adversarial, evidence-only, V1-LOCAL envelope
**Audit base:** `heal/pre-cloud-exec-2026-06-05` @ `b97bd6474` (deployed OVH, served `:8765`) — read-only.
**Engine:** 7-lane × 3-stage pipeline (DONE-inventory → "very-bad-supervisor" LACKING-hunt → anti-hallucination verify), 21 agents, every `file:line` grep-confirmed.

> **Verdict in one line:** the V1 core is **genuinely built and rendering clean** (7 surfaces live-proven, 0 console errors, NF525 chain intact at `audit_logs=2673`), but it is **NOT shippable without fault yet** — there is **no single integrated branch** (RC-01), **one real POS revenue-leak** (CAISSE-01), and a cluster of FR-locale + offline-resilience defects. **0 P0 fiscal-corruption, 5 P1, 6 P2, 1 P3.**

---

## §0 — PREAMBLE (the rules this plan obeys)

### 0.1 Provenance — finding #0 (drives everything else)
Production work is **fragmented across two divergent branches, never integrated:**
- `heal/pre-cloud-exec-2026-06-05` (b97bd6474) = **deployed**, served `:8765`. Carries the wizard-builder W4–W7 work (15 unique commits).
- `heal/deployed-dashboard-fixes-2026-06-08` (9c68d6059) = **20 unique commits** of dashboard-excellence + **the FR-i18n confirm/delete/validation fixes** (`a952c5f72`).
- merge-base `8cb938b1ec`; **15-ahead / 15-behind divergent** — *neither is a superset.* The session checkout (`heal/cms-pr1-quickwins`, ad29e7875) is **178 commits stale** (correctly NOT audited).
- **There is no single shippable branch today.** This is the dominant blocker to "production without fault" and it makes several lower findings (CENTRAL-P1-02 English admin, the label.no `N°` fix) *already-fixed-but-undelivered*. → **RC-01 + GATE-INT-1.**

### 0.2 V1-LOCAL envelope (scope fence — immutable owner mandate)
Single-box, **FR locale**, `branch_id=1`, simulated TPE (`POS_SIMULATION_HARDWARE=true`) is a **deliberate choice, not a bug**. **Out of scope, never a blocker:** cloud/SaaS/multi-tenant/scale/rate-limit/CDN. The adversarial agents were fenced to this; out-of-envelope items were dropped (see §3).

### 0.3 NF525 / frozen integrity (attestation)
- **Fiscal chain attestation:** start `audit_logs=2673 · z_reports=5` → end `audit_logs=2674 · z_reports=5`. Sole delta = **`id=2675 action=user.login`** (the auditor's own admin login — append-only, HMAC-chained). **No order/payment/fiscal record created or altered**; z_reports unchanged; no DELETE/UPDATE/gap. Append-only integrity intact (and the login-logging proves the chain works). Business mutations confined to disposable `:8766`.
- Operating DB `foodking` is **shared by `:8000` + `:8765`** → every mutating click on those hits the real chain. Mutation-E2E is therefore confined to a disposable `:8766` clone.
- Frozen zones touched by findings: `public/js/pos-wizard.js` (CAISSE-01 — STRICT no-touch) → **owner gate**. All other fixes are in **non-frozen** files.

### 0.4 Convergence criteria (when this GOAL is DONE — 100%, not 99%)
A finding is closed only when: fix applied in non-frozen code (or owner-gated for frozen) → PHPUnit/Vitest green → **frozen-zone diff = 0** → **live E2E proof on `:8766`** (the exact `e2e_check` in each task) → NF525 chain re-attested unchanged/appended-only → adversarial re-audit returns the same finding set twice (flake guard). Per-task pipeline = `ultra-audit-profond`.

---

## §1 — DONE (well-built, evidence-backed) — the "what works" table

| Lane | Capability | Evidence | State |
|---|---|---|---|
| **Spine** | Pricing SSOT 100% backend, `composition_snapshot` frozen at creation | BRAIN §7#3; CLAUDE §8 | ☑ code |
| **Spine** | NF525 HMAC fiscal chain + MySQL DELETE triggers, gap-free sequence | **live chain intact `audit_logs=2673`** | ✅ proven |
| **Spine** | Idempotency dual-layer + `webhook_events`; BranchScope ×20 models (sentinel-locked); order state machine + lockForUpdate; Sanctum single-ability; prod boot guards | BRAIN §7 #4–7,#16; CLAUDE §9 | ☑ code |
| **BORNE** | Kiosk idle "Bienvenue!", light-mode, À-emporter-only (dine-in off); offline cash queue; Plan-B counter routing | **live E2E ✅** + anchors | ✅/☑ |
| **CAISSE** | POS "Commande rapide", real cats + per-cat images; **unified "À ENCAISSER BORNE (200)" queue live**; **D2 create-then-collect on `/admin/encaissement`** (origin badge Borne); cash F-003 chain-signed; drawer-pop forensic | **live E2E ✅** | ✅ proven |
| **KDS+OSS** | KDS grid + empty-state + recall + bump; **D1 contract** (kitchen prepares before pay, bump stays active); OSS 2-col FIFO; polling-fallback "Mode secours" | **live E2E ✅**; BRAIN §6 D1, §7#13,#23 | ✅ proven |
| **WEB+APP** | Web `:8095` + mobile `:8087` standalone served (no-wireup V1); mobile palette noir/orange/jaune; backend storefront | **live 200 ✅** | ✅/☑ |
| **CENTRAL** | Dashboard cockpit (CA/ticket/SLA/canal, **FR money `31 773,90 €`**); catalogue **45 produits / 11 cats / 45 actifs** CRUD + import/export; sales-report payés-seulement (H-03); GDPR phone-gate | **live E2E ✅**; BRAIN §6,§7#17 | ✅ proven |

**Live E2E surfaces proven this campaign (0 console errors, FR, Cayenne palette):** `/admin/dashboard`, `/kiosk/idle`, `/admin/pos`, `/admin/kitchen-display-system`, `/admin/order-status-screen`, `/admin/items`, `/admin/encaissement`. Screenshots `supervisor-100/01-07`.

---

## §2 — LACKING (deeply-decomposed catalog) — the "what's missing & why"

> Each finding is grep-verified on the deployed tree. Severity per V1-LOCAL: **P0**=fiscal/data corruption or core-flow-broken · **P1**=important defect / revenue / FR-locale on key surface · **P2**=real bug on edge/used path · **P3**=runbook/polish. `e2e_check` = the proof that closes it.

### 🔴 RC-01 — BRANCH FRAGMENTATION (P1, RELEASE) — **the meta-blocker**
**Anchor:** merge-base `8cb938b1ec`; audited HEAD `b97bd6474` (15 unique) vs sibling `9c68d6059` (20 unique); missing commit `a952c5f72` (FR confirm/delete dialogs + `label.no N°→Non`); `git diff 8cb938b1ec..sibling = 66 files +2730/-500`. Both branches edit `fr.json` + compiled `public/js` bundles → naive merge conflicts + rebuild.
**Why:** releasing from the deployed tree **drops 20 tested commits** (incl. the FR-i18n fix); releasing the sibling drops the wizard-builder. **No single branch is shippable.** Live evidence of the drop: `appService.js` English dialogs present in `public/js/app.js` (`grep 'Yes, Delete it!' = 1`); `fr.json:1122 'no':'N°'`.
**Decomposed tasks:** (1) **OWNER GATE GATE-INT-1** — pick the integration target + confirm which tree the live server serves. (2) Write `reports/release/branch-merge-manifest.md` (all 15+20 commits + 66 files keep/drop). (3) Integrate ≥`a952c5f72` onto the chosen branch, resolve `fr.json` conflict so BOTH wizard-builder keys AND FR fixes survive (0 `<<<<<<<`). (4) Rebuild bundles; `BundleFreshnessSentinel` green + `grep 'Yes, Delete it!' public/js/app.js = 0`. (5) `FrozenZoneSha256BaselineSentinelTest` green post-merge.
**e2e_check:** merged branch served → `/admin/items` delete → alert reads **"Êtes-vous sûr ?" / "Oui, supprimer"** (not English); `/admin/items/create` radio renders **"Non"** (not "N°").

### 🔴 CAISSE-01 — POS frites upgrade UNDER-BILLED (P1, CAISSE) — **revenue leak**
**Anchor:** `public/js/pos-wizard.js:1325-1326` (display `addonTotal += FRITES_GRANDE/CHEDDAR_PRICE`), `:2259-2264` (recap renders +1,00/+1,00), `:4153-4159` (`buildWizardPosLineAddonsPayload` emits `item_extras:{extras:[],names:[]}` EMPTY + `item_extra_total:0`; upgrades go only into `menu_extras[]` text); `PosComponent.vue:3892-3901`; server SSOT reprice drops the unpriced text → charges **0**.
**Why:** recap shows **+2,00 €**, the order is charged **0 €** for the upgrade → **silent revenue loss + display-vs-charge divergence** (NF525-adjacent: the printed total ≠ what the wizard showed the operator). **Both halves confirmed read-only (advisor-prompted):** the wizard emits the upgrade into `menu_extras[]` text with empty `item_extras`, AND `grep menu_extras app/` returns **nothing** — the backend has **zero `menu_extras` pricing path**; `PricingService` prices only `item_extras` (`:74,:169-170,:290-294`). So the upgrade is provably never charged. `pos-wizard.js` is **FROZEN strict-no-touch** → **owner gate**, OR fix server-side reprice to honor the named upgrades.
**Decomposed tasks:** (1) **GATE-FROZEN-1** owner sign-off (LOCK doc) to touch `pos-wizard.js` payload build OR choose the server-side route. (2) Make `buildWizardPosLineAddonsPayload` emit the Grande/Cheddar as real priced `item_extras` (id+price) not `menu_extras[]` text — OR have `PricingService` reprice the named upgrades. (3) Regression: existing POS pricing tests + a new `tests/Feature/Pos/PosWizardFritesUpgradeBillingTest.php` (RED→GREEN). (4) Frozen-diff = 0 if server-side; if pos-wizard.js touched, LOCK + triple-vert.
**e2e_check:** `:8766` `/admin/pos` → frites item → select Grande+Cheddar → recap == base+2.00 → checkout → **DB-read: `order.total == recap` AND `order_items.item_extra_total == 2.00`.** (Fails today: charged 0.)

### 🟠 WEBAPP-L1 — Mobile loyalty 10pt/€ advertised vs 1pt/€ credited (P1, WEB+APP) — gate-D1
**Anchor:** `mobile/data/loyalty.js:49 earn_ratio:10`, `:50 redeem_ratio:100`; banner `screens-main.jsx:1337` ("{earn_ratio} pt par €"); earn-display 1pt/€ at `screens-modals.jsx:260,262` + `screens-main.jsx:669,901` (`Math.round(total)`); seed `orders.js` 1pt/€ except a 29.80→33 anomaly.
**Why:** the app **advertises a 10× higher earn rate than it credits** — a commercial-commitment contradiction baked into every points figure; the long-standing **gate-D1** before any backend wireup. (Latent today = unwired prototype; P1 not P0.)
**Decomposed tasks:** (1) **GATE-LOYALTY-1** owner confirms canonical ratio (evidence → 10pt/€). (2) Add `computeEarnedPoints(total)=Math.round(total*CONFIG.earn_ratio)`; route all 3 display sites + history fallback through it. (3) Reconcile `orders.js` seed (fix 29.80→33 + 1pt/€ rows; flag welcome-bonus rows). (4) On wireup (G5) fetch config from backend so display can't drift from credit.
**e2e_check:** mobile `index.html` → banner "10 pt par €" → mock checkout T=13.00 → confirm "+130" (not +13) → seeded 29.80 order shows `computeEarnedPoints(29.80)`.

### 🟠 WEBAPP-L2 — Standalone WEB 29 legal placeholders "À COMPLÉTER" (P1, WEB+APP) — **conditional/LCEN**
**Anchor:** `/Users/1millnonstop/Downloads/web/legal/mentions.html:58-67` (13: raison sociale/SIREN/SIRET/RCS/TVA/APE/directeur publication) + `:76-79` (host) + privacy(7)+cgv(4)+cookies(4)+allergens(1) = **29**.
**Why:** LCEN art. 6-III requires éditeur + host legal identity on a public commercial site. **Conditional:** `/Downloads/web` is a STANDALONE no-wireup prototype; **fix = owner DATA ENTRY, not code**; bites **only if/when published** (E.DELICE SAS data already on file: SIRET 10417050100019 / TVA FR19104170501).
**Decomposed tasks:** (1) Owner fills éditeur identity (9 fields, mentions.html:58-67). (2) Owner fills host block (:76-79). (3) Sweep remaining 16 (`grep -rc 'À COMPLÉTER' /Downloads/web/legal = 0`). (4) **GATE-PUBLISH-1**: only required before public publication; NOT a deployed-app blocker.
**e2e_check:** post data-entry, legal pages contain **no** "À COMPLÉTER"; SIRET/TVA/directeur populated.

### 🟠 CENTRAL-P1-02 — Pervasive English on FR admin (P1, CENTRAL) — **already healed in sibling branch**
**Anchor:** `lang/fr/validation.php:16-144` (~103 English "The :attribute" lines) + `appService.js:78-116` (logout/destroy/acceptOrder SweetAlert English: ":93 You will not be able to recover", ":97 Yes, Delete it!") + `EmployeeRequest.php:86` ("The role field is required."); `config/app.php:157 locale='fr'`.
**Why:** the most pervasive FR-locale defect on used admin surfaces (every FormRequest failure + the delete-gate dialog renders English). **NOTE:** healed on `heal/deployed-dashboard-fixes-2026-06-08` (validation.php +236 / appService.js +57 / EmployeeRequest −7) → **forward-port via RC-01, not net-new work.**
**Decomposed tasks:** (1) forward-port `validation.php` FR translation (`grep 'The :attribute' = 0`). (2) forward-port the 3 SweetAlert dialogs (i18n keys). (3) remove/translate `EmployeeRequest::messages()`. **All carried by RC-01 integration.**
**e2e_check:** `:8766` `/admin/employees` Create empty → FR validation; any list Delete → FR SweetAlert.

### 🟡 BORNE-01 — Offline cash replay mis-handles 409 as retryable failure (P2, BORNE)
**Anchor:** `kioskOfflineQueue.js:561-587` (generic catch, no 409 case), `:16/:565 MAX_ATTEMPTS=10`, `:340-348` abandon telemetry; server 409 source `IdempotencyKeyMiddleware.php:76,88-93`; trigger `kioskCart.js:774-797` (CASH-only).
**Why:** a 409 `IDEMPOTENCY_KEY_CONFLICT` on replay **proves the original POST succeeded** → retrying to abandonment is backwards: emits false `order_abandoned` telemetry + permanent IndexedDB residue; the order IS in the kitchen but the borne logged it abandoned. Rare on LAN (P2). `kioskOfflineQueue.js` non-frozen.
**Decomposed tasks:** (1) special-case `status===409 && code==='IDEMPOTENCY_KEY_CONFLICT'` as TERMINAL SUCCESS (synced+=1, drop entry). (2) assert 2xx-replay path drops as success. (3) guard `_reportAbandoned` so 409-success ≠ abandoned.
**e2e_check:** Vitest: enqueue cash entry, syncQueue with mocked 409 → queue drained, `synced===1, failed===0, abandonedNew===0`, no `order_abandoned` POST.

### 🟡 BORNE-02 — payCounter leaves orphan CARD order (P2, BORNE) — gated behind Plan-B off
**Anchor:** `KioskErrorPaymentRefusedComponent.vue:92-98` (payCounter only `$emit`+route, unbound by frozen parent); `FrontendOrderService.php:228-237` (card → OrderCreated deferred); `KioskCashInstructionComponent.vue:124-131` (observability-only POST); gate `config/kiosk.php:54 KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true`.
**Why:** after a refused CARD payment, "Payer à la caisse" strands the order UNPAID/PENDING, invisible to KDS + counter. **Unreachable in V1-LOCAL default** (Plan-B routes all cards to counter) → P2; **must fix before owner ever flips Plan-B off.** Fix in non-frozen children + a backend counter-conversion path.
**Decomposed tasks:** (1) backend endpoint to convert refused-card order → PENDING_COUNTER + dispatch OrderCreated. (2) `payCounter()` calls it before routing; on success only. (3) feature test: after payCounter, order appears in KDS query + counter record exists.
**e2e_check:** `:8766` (Plan-B off) refuse card → payCounter → order visible on KDS + in encaissement queue.

### 🟡 CAISSE-02 — Parked-order recall is a destructive non-idempotent GET (P2, CAISSE)
**Anchor:** `routes/api.php:924` (`GET /{id}` show→recall); `ParkedOrderController.php:48-58`; `PosParkedOrderService.php recall()` does `lockForUpdate()` then **hard `delete()`** (no SoftDeletes).
**Why:** recall **hard-deletes** the parked row and returns the snapshot; if the response is lost (tab reload mid-request) and the cashier retries the GET → 404, **ticket permanently lost.** Single-box vector is narrow (P2).
**Decomposed tasks:** (1) make recall idempotent: SoftDelete (or status flag) so a re-GET returns the snapshot, not 404. (2) consider POST/DELETE semantics for the destructive op. (3) feature test: recall once → snapshot; simulate lost response + re-GET → still snapshot (not 404).
**e2e_check:** Feature test as above (park → recall → re-recall returns snapshot post-fix).

### 🟡 KDS-OSS-01 — Legacy `?v2=0` inline recall is localStorage-only (P2, KDS) — NF525 ledger gap
**Anchor:** `store/modules/kds.js:66-85` (`recallItem` pure localStorage `kds.bumped_items_v1`, NO axios); `KitchenDisplaySystemComponent.vue:1895-1903` (`kdsRecall` only dispatches local).
**Why:** on the legacy 4-lane layout (emergency-rollback path), inline "Annuler" **never hits the server** → the correction is **never written to the NF525 ledger** and OSS/kitchen stay "Prêt". Only on `?v2=0` (P2).
**Decomposed tasks:** (1) `recallItem` POSTs `/api/admin/kds-order/recall/{order}` (same path as v2). (2) keep localStorage as optimistic UI only. (3) Vitest: recallItem fires the server POST.
**e2e_check:** `:8766` `/kds?v2=0` → bump to PREPARED → inline "Annuler" → assert POST to `kds-order/recall` fires + OSS reverts.

### 🟡 KDS-OSS-02 — RAPPELÉ recall badge has no polling-recovery (P2, KDS)
**Anchor:** `KitchenDisplaySystemComponent.vue:1987-2002` (KdsOrderRecalled = Echo/WS-only) → `:2490-2499 kdsRecalledMap` 60s TTL, not state-backed; `KitchenDisplaySystemOrderService` doesn't surface recall in the poll payload.
**Why:** a 2nd KDS screen in polling fallback (WS down) **never sees a recall** → kitchen keeps a recalled order as ready. Degraded-mode correctness gap (P2).
**Decomposed tasks:** (1) surface recall state in the `/api/admin/kds-order` poll payload (e.g. `recalled_at`). (2) KDS renders RAPPELÉ from poll data, not just WS. (3) test: poll payload includes recall + 2nd screen shows it.
**e2e_check:** `:8766` 2 KDS screens, screen B WS-down (poll) → recall on A → B shows RAPPELÉ within one poll cycle.

### 🟡 CENTRAL-P1-01 — Variante tab masks failures as empty-state (P2, CENTRAL) — *headline downgraded by verifier*
**Anchor:** `ItemVariationController.php:39` (`catch(Exception)` not `\Throwable` → an `\Error` escapes as 500); `ItemVariationListComponent.vue:121-125` (`.catch` swallows → only flips loading), `:51` renders `no_data_available` for BOTH "0 variants" and "request failed".
**Why:** error-vs-empty are **indistinguishable** to the gérant (no error-state/retry) — a real robustness/UX gap. **The "500 on every product" headline was NOT reproducible** (screenshot shows normal 200 empty-state; report admits no stack trace) → **verifier downgraded P1→P2** and retitled. (Adversarial honesty in action.)
**Decomposed tasks:** (1) `catch(\Throwable)` → 422 envelope not bare 500. (2) Vue renders a distinct error-state+retry on reject. (3) Vitest: rejected axios mock → error banner; 200 empty → empty-state.
**e2e_check:** `:8766` product with ≥1 variation → Variante tab renders table (200); 0-variation → empty-state; forced 5xx → error banner (not empty-state).

### ⚪ SPINE-01 — Confirm KDS/OSS run under chef@/pos@ not admin@ (P3, runbook) — *downgraded by verifier*
**Anchor:** `KitchenDisplaySystemComponent.vue:1950` (`if(branchId<=0) return;` — admin poll-only by KDS-03 design), banner `:1400 kdsAdminNoPush`, FR label `fr.json:755`; seeder chef/pos = branch_id=1 (get sub-second push).
**Why:** **deliberate** isolation (admin spans all branches) + **visibly flagged** in FR ("Mode admin centralisé 60 s"). Intended operator accounts already get push → **P3 runbook note only.**
**Decomposed tasks:** (1) add to BRAIN §9: "KDS/OSS operated under chef@/pos@ (branch_id=1), never habitually admin@." (2) OPTIONAL single-branch fast-path only if owner confirms admin-on-KDS is real practice.
**e2e_check:** login chef@ → `/kds` → `window.Echo` shows `private-branch.1` subscribed; branch-1 order appears <3s.

### 🩺 LIVE-OBSERVED (supervisor E2E, this campaign — feed the catalog)
| ID | Sev | Finding | Evidence |
|---|---|---|---|
| **LIVE-DATA** | **P1 go-live gate** | Operating `foodking` DB carries **~200 soak-kiosk test orders + 146 SLA alerts aged "11 j"** → needs clean-state reset before prod | live dashboard + encaissement (200 cards) |
| **LIVE-I18N-MONEY** | P2 | `/admin/items` PRIX renders `1.50` (en-US dot, no €) vs dashboard `31 773,90 €` — FR money formatter not uniform | live E2E 06 |
| **LIVE-UX-CAT** | P3 | Two POS category chips both truncate to "Sandwich …" (indistinguishable) | live E2E 03 |
| **LIVE-NOTE** | — | `/home` redirects admin→`/admin/dashboard` — customer storefront needs non-admin context (verify WEB-03 there) | live E2E |

---

## §3 — ADVERSARIAL CROSS-EXAMINATION (the "discussion" — what the verifier KILLED)
The anti-hallucination stage is the supervisor's bad-mood second opinion. It **dropped or downgraded 6 claims** — proof the catalog is not inflated:
- **WEB-01 (P1 "empty /menu") → DROPPED.** Headline reproduction false: every in-app nav carries `?s=<slug>`; bare `/menu` only via direct-typed URL/cold-race → P2 edge, not the claimed P1 nav failure.
- **CENTRAL "500 on every product" → DOWNGRADED P1→P2.** Screenshot shows a normal 200 empty-state; report admits no stack trace; zero-variant path provably returns 200. The masking gap is real; the 500 is not asserted.
- **SPINE-02 (worker/cron outbox liveness) → DROPPED** as duplicate of existing deploy gate **G-SYNC-1** (tracked 3× in BRAIN); degrades to polling with zero data loss, not a code defect.
- **SPINE-01 OSS-half → DROPPED** — the OSS status wall is by-design a public/unauthenticated poll surface (`authBranchId<=0`).
- **SPINE-01 KDS-half → DOWNGRADED P1→P3** — deliberate KDS-03 design with visible FR banner; intended accounts get push.
- **RC-02 (English SweetAlert) → DROPPED as healed-in-divergent-branch** (folded into RC-01 integration).
- **CAISSE-01 anchor corrected** (supervisor cited `:911`; actual `:924`); **WEBAPP-L1 anchors corrected** (`:20/21`→`:49/50`, history site `:901` added). Findings stand; anchors hardened.

---

## §4 — E2E COVERAGE LEDGER (honest: proven vs pending — this is the "100% not 99%")
| Surface / claim | Method | Status |
|---|---|---|
| Dashboard, Kiosk-idle, POS, KDS, OSS, Catalogue, Encaissement render (FR, 0-err) | live Playwright `:8765` + screenshot-read | ✅ PROVEN |
| FR money on dashboard `31 773,90 €` / 45 items / D2 encaissement queue | live | ✅ PROVEN |
| WEBAPP-L1 mobile loyalty 10× divergence | grep line-by-line + in-page eval | ✅ PROVEN (code) |
| All 12 findings' `file:line` anchors | grep/Read by verify agents | ✅ PROVEN |
| **Per-page navigation sweep — all 27 navigable admin routes + 7 operational (34 pages)** | live Playwright `:8767` (deployed tree) read-only | ✅ **DONE** — 0 console errors each; 5 new FR-locale findings (see `reports/.../SWEEP_COVERAGE.md`) |
| **Mutation flow** kiosk→KDS→OSS sync; CAISSE-01 under-bill DB-assert; recall paths; destructive buttons (delete/save) | **needs `:8766` disposable clone** (forbidden on `:8765`/`:8767` shared chain) | ⏳ PENDING (each task's `e2e_check`, Wave-V) |
> **Honest stance:** the load-bearing renders, the 12 anchors, AND the read-only per-page sweep of all 34 admin/operational pages (0 console errors) are proven. What remains is *mutation* coverage — placing orders, exercising destructive buttons, CAISSE-01's DB-assert — which **must** run on the disposable `:8766` clone (never the operating chain) and validates the *fixes*, which are owner-gated and not yet applied. Mutation E2E is sequenced to the remediation waves, scoped not faked.

### §4b — New findings from the per-page sweep (all P2, FR-locale/i18n/display)
| ID | Page(s) | Finding | RC-01-linked? |
|---|---|---|---|
| SWEEP-MONEY-01 | items, transactions, items/studio | money `1.50`/`+ 8.50` en-US dot/no-€ vs correct `32 525,40 €` elsewhere | likely |
| SWEEP-TIME-01 | sales-report, transactions | DATE en-US `01:41 PM` vs FR 24h `13:41` | likely |
| SWEEP-PAYMODE-01 | transactions | MODE shows raw enum `COUNTER_CASH` not FR label | yes (sibling fix exists) |
| SWEEP-EMP-01 | employees | phone renders `null0680718093` (null+number concat) | net-new |
| SWEEP-STUDIO-I18N | items/studio | missing fr key `studio.product_composer_button` → 90 empty button labels | net-new |
> Interpretation: FR-locale defects are **surface-specific** (cash-overview/dashboard/encaissement correct) = the live signature of RC-01; integrating the branches closes MONEY/TIME/PAYMODE. EMP-01 + STUDIO-I18N appear net-new. All roll into Wave-1 (integration) + Wave-3 (FR residue).

---

## §5 — REMEDIATION WAVES (decomposed, dependency-ordered)
**Wave 0 — Owner gates (BLOCKS everything downstream):** GATE-INT-1 (pick integration branch), GATE-FROZEN-1 (pos-wizard.js LOCK for CAISSE-01 OR server-side), GATE-LOYALTY-1 (mobile ratio), GATE-PUBLISH-1 (web legal scope), LIVE-DATA decision (clean-state reset). → §G.
**Wave 1 — INTEGRATION (RC-01):** merge the two branches into one shippable tree; forward-ports CENTRAL-P1-02 + label.no `N°` for free; rebuild bundles; sentinels green. *Single highest-leverage wave.*
**Wave 2 — REVENUE/FISCAL (CAISSE-01):** under-billing fix (server-side preferred to avoid frozen touch) + regression test + `:8766` DB-assert.
**Wave 3 — FR-LOCALE residue:** LIVE-I18N-MONEY (uniform money formatter), any FR strings not carried by Wave 1.
**Wave 4 — OFFLINE/SYNC resilience:** BORNE-01 (409-success), KDS-OSS-01 (server recall), KDS-OSS-02 (poll recovery), CAISSE-02 (idempotent recall), BORNE-02 (only before Plan-B flip).
**Wave 5 — ROBUSTNESS/POLISH:** CENTRAL-P1-01 (Throwable + error-state), LIVE-UX-CAT, SPINE-01 runbook line.
**Wave V — VALIDATION SWEEP:** `:8766` per-finding `e2e_check` + full per-button click-through of the ~58 admin pages; NF525 re-attest; convergence (two identical adversarial passes).
**Wave 6 — STANDALONE (publish-gated):** WEBAPP-L1 loyalty, WEBAPP-L2 legal data — only if/when web/mobile are wired/published.

Each wave closes only under §0.4 convergence criteria. Max 3 heal loops/cluster → escalate (CLAUDE.md §10).

---

## §G — OWNER GATES (WHO / WHAT / WHERE)
| Gate | Decision needed | WHO | WHAT (unblocks) | Status |
|---|---|---|---|---|
| **GATE-INT-1** | Which branch is the integration target; which tree the live server serves | Owner | written decision in BRAIN §2 + merge-manifest | ⛔ PENDING |
| **GATE-FROZEN-1** | CAISSE-01 fix: touch frozen `pos-wizard.js` (LOCK) OR server-side reprice | Owner | LOCK doc countersign OR "server-side" instruction | ⛔ PENDING |
| **GATE-LOYALTY-1** | Canonical mobile earn ratio (evidence → 10pt/€) | Owner | one-line signed note in `mobile/data/loyalty.js` | ⛔ PENDING |
| **GATE-PUBLISH-1** | Is `/Downloads/web` ever published? (drives WEBAPP-L2 urgency) | Owner | publish decision recorded | ⛔ PENDING |
| **GATE-DATA-1** | Clean-state reset of operating `foodking` DB before go-live (200 soak orders / 146 SLA) + NF525 retention handling | Owner | reset plan + NF525 6-yr retention decision | ⛔ PENDING |

---

## §F — FINAL RULE (DONE = production-perfect, not "almost")
This GOAL is DONE only when: **one integrated branch** exists (RC-01 closed), **CAISSE-01 charges what it shows**, FR-locale is uniform, offline/sync resilience holds in degraded mode, every `e2e_check` is GREEN on `:8766`, the per-button sweep is complete, the NF525 chain is re-attested unchanged/appended-only, and two consecutive adversarial passes return P0+P1=0 with identical sets. Until then: **partial > wrong, blocked > silently dangerous.**
