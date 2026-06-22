# Wave T — E2E Audit Plan
## "Caisse passer commande jusqu'à commande prête et livré client ou livreur"

**Run name**: `wave-t-caisse-to-delivered-2026-05-20`
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**Base URL**: http://127.0.0.1:8000
**Generated**: 2026-05-20
**Plan author**: Wave T Plan agent (single agent, audit-only)
**Status**: PLAN ONLY — not for execution by this agent

---

## 1. Owner mandate (verbatim, French)

> "pour caisse passer commande jusqu'à commande prête et livré client ou livreur"

Translation for adversarial agents:
- Test the full POS-driven order lifecycle: **POS (caisse) → KDS (cuisine prête) → handoff (OSS for takeaway client OR delivery boy for livraison)**.
- Two parallel orders required: **Order #1 = À emporter / cash** (validates OSS hand-off to client) and **Order #2 = Livraison / TPE** (validates delivery boy hand-off).
- Every state captured visually + technically. Every cross-surface fact (totals, item names, status) must be byte-equal.

---

## 2. Scope summary

### In scope (4 waves, chained A → B → C → D)

| Wave | Surface | Subject | Order under test |
|------|---------|---------|------------------|
| **A** | POS (`/admin/pos`) | 2 orders placed by caissier (cash takeaway + TPE livraison) | Creates Order #1 + Order #2 |
| **B** | KDS (`/admin/kitchen-display-system`) | Both orders auto-arrive in PRÉPARATION; chef 1-clic PRÊT | Transitions both |
| **C** | OSS (`/order-status-screen`) | Order #1 PRÊT pulse → picked up by client → disappears | Order #1 only |
| **D** | Livreur / Suivi (`/admin/pos-orders-tracker`) + API | Order #2 assign to delivery boy → LIVRÉ | Order #2 only |

### Out of scope (explicit, no penalty if untested)

- Kiosk → KDS path (covered Wave P, GREEN)
- NF525 daily close / Z report (Wave M closed)
- Refunds, cancellations, parked orders
- Mobile (`/mobile`) + Web showcase (`/`)
- Allergen rendering (Wave Q-4 closed — empty allergens are the canonical state)
- Stock rupture cascade
- Multi-branch isolation (single-branch Le Cayenne local)
- Loyalty + discount logic
- Receipt printing (simulation hardware bypass active)

---

## 3. Wave dependency model (critical)

Per advisor reconcile: PROMPT_PLAN's "runnable in isolation" mandate conflicts with the owner's single-order lifecycle mandate. **Decision: option A — fixture chain.**

- **Wave A is hard-gated.** Wave A must complete `verdict ∈ {GREEN, AMBER}` (no P0) before B/C/D dispatch. If Wave A is RED, B/C/D are skipped this round.
- **Wave A emits** `tests/e2e/__fixtures__/wave-t-orders.json` shaped:
  ```json
  {
    "order_1": {
      "id": <int>,
      "token": "AUDIT-WAVE-T-001-<ts>",
      "mode": "TAKEAWAY",
      "payment": "CASH",
      "total_cents": <int>,
      "items_summary": ["Cayenne", "Tacos", "Boisson"]
    },
    "order_2": {
      "id": <int>,
      "token": "AUDIT-WAVE-T-002-<ts>",
      "mode": "DELIVERY",
      "payment": "TPE",
      "total_cents": <int>,
      "items_summary": ["Cayenne", "Big Burger", "Frites"],
      "customer": { "name": "...", "phone": "...", "address": "..." }
    },
    "captured_at": "<ISO-8601>"
  }
  ```
- Waves B/C/D read this fixture in `beforeAll`. If file missing → spec sentinel-skip with `test.skip(true, 'Wave A fixture missing — orchestrator must run Wave A first')`.
- Spec authors must NOT chain `test.describe.serial` across waves — each wave is its own spec file.

---

## 4. Pre-flight prerequisites (orchestrator runs before round 1)

| # | Check | Command / Action |
|---|-------|------------------|
| 1 | Server 200 on `/login` | `curl -sI http://127.0.0.1:8000/login \| head -1` |
| 2 | Migrations clean | `php artisan migrate:status \| grep -c Pending` → expect 0 |
| 3 | Admin user | `admin@lecayenne.fr` / `123456` (verified preflight) |
| 4 | Branch=1, items=59 (verified preflight) | — |
| 5 | **Delivery boy seed (Wave D prerequisite)** | `php artisan tinker --execute='echo App\Models\DeliveryBoy::count();'` — if 0, **Wave D spec author** creates one via `DeliveryBoyService::store()` in spec's `globalSetup` block (no factory under `database/factories/` — service-layer creation only). Acceptable seed shape: name="Livreur Test", phone="+33600000000", branch_id=1, active=true. |
| 6 | `POS_SIMULATION_HARDWARE=true` in `.env` (drawer/TPE bypass) | already set in V1 local |
| 7 | Test data orphan cleanup | `php artisan iter15:cleanup-test-orders --apply --token-prefix=AUDIT-WAVE-T-` |
| 8 | Playwright workers=1 | `grep workers tests/e2e/playwright.config.js` → must be 1 |
| 9 | NF525 chain pre-test snapshot | `php artisan fiscal:verify-chain --tail` → record `last_hash` |
| 10 | Bundle freshness | `ls -lt public/js/admin-pos.js public/mix-manifest.json` — within last commit |

If any check fails → orchestrator halts before Wave A spawn.

---

## 5. Wave A — POS (caisse, 2 orders placed)

### Spec file
`tests/e2e/test-e2e-wave-t-caisse-to-delivered-A-pos.spec.js`

### Login + context
- 1 browser context: `loginAsAdmin(page)` from `tests/e2e/helpers/login.js` (admin has POS access)
- Forces landing at `/admin/pos`
- `attachMegaAuditRecorder(page, 'tests/e2e/__screenshots__/test-e2e-wave-t-A-pos/')`

### Token prefix
`AUDIT-WAVE-T-001-<ts>` (Order #1) and `AUDIT-WAVE-T-002-<ts>` (Order #2)

### Visual states (each = quartet PNG + DOM + console + network)

| # | State name | What is captured |
|---|------------|------------------|
| 01 | `01-pos-login-landed` | POS shell after login; category tabs visible; floating cart empty |
| 02 | `02-pos-drawer-open-50` | Open cash drawer modal with 50€ initial — submitted |
| 03 | `03-pos-cart-order1-cayenne` | Sandwich Cayenne added; wizard (if any) confirmed; line 1 in cart |
| 04 | `04-pos-cart-order1-tacos` | Tacos added (line 2) |
| 05 | `05-pos-cart-order1-boisson` | Boisson added (line 3) |
| 06 | `06-pos-cart-order1-takeaway-mode` | Mode "À emporter" selected; cart total + tax visible |
| 07 | `07-pos-cart-order1-payment-modal-cash` | Payment modal: cash tab; tendered = total; rendu = 0 |
| 08 | `08-pos-order1-confirmation-receipt` | Receipt panel post-confirm; Order #1 ID + token recorded |
| 09 | `09-pos-cart-reset-empty` | Cart cleared, back at catalog |
| 10 | `10-pos-cart-order2-cayenne` | Sandwich Cayenne added |
| 11 | `11-pos-cart-order2-burger` | Burger added |
| 12 | `12-pos-cart-order2-frites` | Frites added |
| 13 | `13-pos-cart-order2-delivery-modal` | Mode "Livraison" → customer modal opens (name+phone+address) |
| 14 | `14-pos-cart-order2-delivery-filled` | Customer details filled, modal validated |
| 15 | `15-pos-cart-order2-payment-modal-tpe` | Payment modal: TPE tab; total visible |
| 16 | `16-pos-order2-confirmation-receipt` | Receipt panel post-confirm; Order #2 ID + token recorded |
| 17 | `17-pos-suivi-both-orders-en-preparation` | Navigate to `/admin/pos-orders-tracker`; both orders visible in **EN PRÉPARATION** column |

### Cross-surface assertions (Wave A internal)

- **A-S1 (Wave S-1 hook)**: After State 08 confirm, within 5s of network 200, Order #1 must show `[data-status="PREPARING"]` in the tracker — NOT `CONFIRMED`. This validates Wave S-1 commit `aaae9c916` (auto ACCEPT→PREPARING on paid confirmed).
- **A-S4 (Wave S-4 hook)**: After State 16 confirm, Order #2 (TPE-paid) must **NOT** appear in the "À ENCAISSER" column (cash-pending only). If a third cash-pending order is created and parked, it should appear in "À ENCAISSER". Spec author MAY add State 18a (optional) to validate.

> **Canonical cash-pending-badge semantics** [WT-A-R1-13 heal, 2026-05-20]
>
> The bell badge `[data-testid="tracker-cash-badge-<id>"]` (and the
> equivalent `.cash-pending-badge` element on KDS) renders if and ONLY if:
>
> 1. `order.is_cash_pending === true|1` (SimpleOrderResource flag), OR
> 2. `payment_status === PaymentStatus::PENDING_COUNTER (15)` AND
>    `pos_payment_method === PosPaymentMethod::COUNTER_DEFERRED (6)`.
>
> Implementation: `PosOrdersTrackerComponent.vue::isCashPending()` (lines
> 866-872) + docstring at lines 138-142 + lines 199-204.
>
> Applicability per surface:
>
> | Order origin | Payment | Badge expected? |
> |---|---|---|
> | POS cash, paid at counter | settled | **NO** (already paid; never enters cash-pending state) |
> | POS TPE / card | settled | **NO** (never cash-pending) |
> | Kiosk PENDING_COUNTER (cash-at-counter) | unpaid | **YES** (until cashier collects) |
> | Kiosk paid (Stripe / TPE) | settled | **NO** |
>
> Wave A consequence: BOTH orders (Order #1 POS cash + Order #2 POS TPE)
> are expected to show **NO** badge. R1 reviewer flagged
> `order1_cash_badge_present=false` as a defect — this was incorrect; false
> is the canonical truth for POS-paid cash. The A-S4 hook asserts only the
> negative case for the TPE order (which is the only surface-specific risk;
> POS cash never reaches PENDING_COUNTER). Kiosk cash-at-counter is
> Wave B/C scope.
- **A-NUM1 (numeric integrity)**: total displayed in State 07 payment modal === total displayed in State 08 receipt === total displayed in State 17 tracker tile for Order #1.
- **A-NUM2 (numeric integrity)**: total displayed in State 15 payment modal === State 16 receipt === State 17 tracker tile for Order #2.

### Hard-gate exit

Wave A succeeds when:
1. All 17 states captured (quartets complete).
2. Fixture file `tests/e2e/__fixtures__/wave-t-orders.json` written with both order IDs + tokens.
3. Spec exits exit-code 0.
4. Adversarial review finds `open_P0 == 0` (P1 acceptable — orchestrator may still gate dispatch on P1 trend).

---

## 6. Wave B — KDS (cuisine prête)

### Spec file
`tests/e2e/test-e2e-wave-t-caisse-to-delivered-B-kds.spec.js`

### Login + context
- 1 browser context: `loginAsAdmin(page)` then navigate to `/admin/kitchen-display-system`
- KDS uses admin role (chef user not always seeded). Acceptable.
- `attachMegaAuditRecorder(page, 'tests/e2e/__screenshots__/test-e2e-wave-t-B-kds/')`

### Reads from fixture
- `tests/e2e/__fixtures__/wave-t-orders.json` → both order IDs

### Visual states

| # | State name | What is captured |
|---|------------|------------------|
| 01 | `01-kds-landing-both-preparing` | KDS shell loaded; both orders visible in EN PRÉPARATION column within 5s of mount |
| 02 | `02-kds-order1-card-detail` | Order #1 card expanded if collapsible; items list visible (Cayenne + Tacos + Boisson) |
| 03 | `03-kds-order2-card-detail` | Order #2 card expanded; items list visible (Cayenne + Burger + Frites) |
| 04 | `04-kds-order1-pret-click` | 1-clic CTA "Prêt" on Order #1 — captured DURING click animation if possible (else immediately after) |
| 05 | `05-kds-order1-pret-confirmed` | Order #1 moved to PRÊT column; status badge updated |
| 06 | `06-kds-order2-pret-click` | 1-clic CTA "Prêt" on Order #2 |
| 07 | `07-kds-order2-pret-confirmed` | Order #2 moved to PRÊT |
| 08 | `08-kds-final-both-pret` | Final state: both orders in PRÊT column; PRÉPARATION empty (modulo other parallel orders) |

### Cross-surface assertions (Wave B)

- **B-S2 (Wave S-2 hook)**: Each order card MUST display **exactly one** CTA button (not 2 — old "Préparer" + "Prêt" gone). Selector check: `card-N >> role=button` returns `count === 1` for orders in PRÉPARATION. Validates commit `52ddbb024`.
- **B-S2-NETWORK (Wave S-2 hook)**: Click on "Prêt" CTA triggers **exactly one** `PATCH /api/admin/orders/<id>/status` network call resulting in `status=PREPARED` (no PREPARING intermediate). Validates 1-clic = direct PREPARING→PREPARED jump.
- **B-S2-BADGE (Wave S-2 hook)**: [REVISED 2026-05-20 — WT-A-R1-13 heal] Both Wave A orders are POS-paid (Order #1 POS cash, Order #2 POS TPE) — NEITHER triggers `is_cash_pending=true` because both are already-settled. Per the canonical semantics block above, `.cash-pending-badge` MUST NOT appear on either card. The badge surfaces only for kiosk PENDING_COUNTER cash-at-counter orders (a separate fixture, not exercised by Wave A→B). To validate B-S2-BADGE positively, the spec author would need to add a kiosk cash-at-counter order seed before Wave A — out of scope here. Spec asserts only the negative case: `[data-testid="cash-pending-badge"]` count === 0 on both Wave A cards.
- **B-Q4 (Wave Q-4 hook, regression check)**: Allergen badges MUST NOT render — empty allergen set is canonical (no chef seeded with allergen data). Selector: `.allergen-badge` count === 0 across all cards.
- **B-SYNC-LATENCY**: Both orders must appear in KDS pile within **≤ 8s** of Wave A's confirm timestamps (slack for pusher / poll). Spec asserts `page.locator(orderCardSelector).waitFor({ state: 'visible', timeout: 8000 })`.
- **B-NUM3 (numeric integrity)**: Order #1 KDS card items === fixture `order_1.items_summary`. Order #2 KDS card items === fixture `order_2.items_summary`.

### Wave B writes back to fixture
- Add `kds_ready_at_order_1` + `kds_ready_at_order_2` ISO timestamps so Wave C/D can validate "recent PRÊT" pulse animations.

---

## 7. Wave C — OSS (écran client takeaway)

### Spec file
`tests/e2e/test-e2e-wave-t-caisse-to-delivered-C-oss.spec.js`

### Login + context
- 1 browser context: **no login needed** — OSS is public route `/order-status-screen`
- `attachMegaAuditRecorder(page, 'tests/e2e/__screenshots__/test-e2e-wave-t-C-oss/')`

### Reads from fixture
- Order #1 ID (TAKEAWAY mode → expected on OSS)
- Order #2 ID (DELIVERY mode → expected NOT on OSS per Wave O allowlist `KIOSK + TAKEAWAY` only)

### Visual states

| # | State name | What is captured |
|---|------------|------------------|
| 01 | `01-oss-landing-order1-pret-pulse` | OSS TV view; Order #1 in PRÊT column with pulse animation (recently READY < 60s) |
| 02 | `02-oss-font-size-token-check` | DOM-level `getComputedStyle(.order-tile).fontSize` >= 40px (Wave S-3 hook) |
| 03 | `03-oss-order2-absent` | Order #2 (DELIVERY) **not visible** anywhere on the OSS screen (allowlist enforcement) |
| 04 | `04-oss-order1-marked-picked-up` | Admin POST or UI click marks Order #1 picked up (status PICKED_UP) |
| 05 | `05-oss-order1-disappeared` | After ≤ 6s polling/pusher, Order #1 no longer visible on OSS |

### Cross-surface assertions (Wave C)

- **C-S3 (Wave S-3 hook — font tokens)**: At least one `.order-tile-number` / `.oss-order` element has `window.getComputedStyle(el).fontSize` parsing to a px value `>= 40`. Validates commit `890f5b5f1`. Capture DOM evidence in State 02.
- **C-S3-PULSE (Wave S-3 hook — pulse animation)**: Order #1 tile MUST have CSS class with `animation-name` containing "pulse" (or matching `@keyframes pulse-*`). Detect via `el.getAnimations().length > 0` or computed `animation-name !== 'none'`.
- **C-ALLOW (Wave O R3 hook)**: Order #2 (DELIVERY) MUST NOT appear in OSS DOM. Selector: `[data-order-id="<order_2.id>"]` returns 0 matches. If it DOES appear → P0 (allowlist breach, security/UX).
- **C-DISAPPEAR-LATENCY**: After marking Order #1 picked up, within **6 seconds** the tile must be gone (poll interval + animation). Spec uses `await expect(tile).toBeHidden({ timeout: 6000 })`.
- **C-NUM4 (numeric integrity)**: Order #1 number on OSS tile (`A0XXX` short ID or full ID) === fixture `order_1.id` representation. No mismatch.

### Wave C does NOT touch
- Kiosk surface, KDS surface (Wave B closed those)
- Order #2 (handled by Wave D)

---

## 8. Wave D — LIVREUR (delivery hand-off)

### Spec file
`tests/e2e/test-e2e-wave-t-caisse-to-delivered-D-livreur.spec.js`

### Login + context
- 1 browser context: `loginAsAdmin(page)`
- Navigates `/admin/pos-orders-tracker` (Suivi commandes) for delivery assignment UI
- `attachMegaAuditRecorder(page, 'tests/e2e/__screenshots__/test-e2e-wave-t-D-livreur/')`

### Pre-spec setup (Wave D ONLY)
- Spec's `globalSetup` or `beforeAll`:
  ```js
  // Ensure at least 1 delivery boy exists; create via DeliveryBoyService if 0.
  // Use execFileSync('php', ['artisan', 'tinker', '--execute', '...']) pattern.
  // Required fields: name, phone, branch_id=1, active=true.
  ```
- Fixture updated with `delivery_boy_id` if newly created.

### Reads from fixture
- Order #2 ID + token + customer name
- (Optional) delivery_boy_id if seeded

### Visual states

| # | State name | What is captured |
|---|------------|------------------|
| 01 | `01-tracker-order2-pret-visible` | Suivi commandes shows Order #2 in PRÊT column (or "À LIVRER" col if separate); eye/details icon visible |
| 02 | `02-tracker-order2-detail-modal` | Click eye icon → details modal: customer name + phone + address + items + total |
| 03 | `03-tracker-order2-assign-driver-ui` | Assign delivery boy dropdown / button opened; delivery boy selectable |
| 04 | `04-tracker-order2-assigned` | Delivery boy assigned (status badge update: ASSIGNED or sub-status `delivery_boy_id` set) |
| 05 | `05-tracker-order2-picked-up-by-driver` | Status transition to OUT_FOR_DELIVERY / PICKED_UP via UI or API call |
| 06 | `06-tracker-order2-delivered-final` | Status LIVRÉ / DELIVERED; admin tracker reflects final state |
| 07 | `07-admin-orders-list-delivered` | Navigate `/admin/orders` or equivalent ; Order #2 row shows DELIVERED + delivery boy name |

### Cross-surface assertions (Wave D)

- **D-ASSIGN-API**: Assignment uses `PATCH /api/admin/orders/<id>` or `POST /api/admin/orders/<id>/assign-delivery-boy` — verify exact route via DevTools during spec authoring. No 4xx/5xx in `network.json` for this call.
- **D-DELIVERED-API**: Status transition to DELIVERED uses `PATCH /api/admin/orders/<id>/status` body `{ status: 'DELIVERED' }` (or domain equivalent). Must return 2xx.
- **D-NF525-CHAIN**: After Order #2 reaches DELIVERED, NF525 chain MUST be unchanged or correctly appended (count incremented by however many appended events). Compare against pre-test `last_hash` snapshot. NO holes, NO rewrites.
- **D-NUM5 (numeric integrity)**: Order #2 total in tracker tile (State 01) === detail modal (State 02) === final orders list row (State 07).
- **D-CUSTOMER-PERSIST**: Customer name+phone+address shown in State 02 detail === values entered in Wave A State 14.
- **D-OSS-NEGATIVE (cross-wave consistency)**: At no point in Wave D should Order #2 appear on OSS. Optional spec step: snapshot OSS DOM at end of D and assert absence. (Defensive.)

### Wave D may use API directly
- If UI assignment is awkward or non-existent (DeliveryBoyController routes exist but admin tracker UI may not expose all transitions), spec author MAY use `page.request.patch(...)` calls with admin Sanctum token to drive transitions. Document each API call in spec comments. Visual capture is still mandatory — capture the resulting state in the admin UI after each API call.

---

## 9. Cross-cutting checks (in every wave)

Per REVIEWER_PROTOCOL.md 12 defect categories, every adversarial reviewer asks per state:

| # | Check | Severity |
|---|-------|----------|
| 1 | i18n leak (raw `label.xyz` / `kiosk.foo` visible) | P1 |
| 2 | Text truncation w/o tooltip | P2 |
| 3 | Button overlap (>50%) | P1 |
| 4 | Contrast WCAG AA (< 4.5:1 normal text) | P2 |
| 5 | Empty-state quality | P2 |
| 6 | Silent error (4xx/5xx in `network.json` w/o visible toast) | **P0** |
| 7 | Loading state missing (>2s request w/o spinner) | P2 |
| 8 | Aria/keyboard (icon-only without aria-label) | P2 (P1 if blocks primary task) |
| 9 | Console error (`level=error` outside vendor.js / pusher dev noise) | P1 |
| 10 | Unexpected 4xx/5xx (status≥400 outside allowlist 401-logout / 422-validation / 304-cache) | **P0** |
| 11 | **Numeric integrity** (same fact differs across surfaces) | **P0** |
| 12 | Visual hash drift (>5% pixel delta non-animated region) | P3 |

### Owner-priority addenda (hardcoded into every adversarial prompt)

1. **Visual FIRST** — open each PNG before DOM/console/network. Eyes catch what regex can't.
2. **Numeric integrity NON-NEGOTIABLE** — same total/item/status MUST be byte-equal across every surface in the same round. Cart=receipt=tracker=KDS=OSS=admin-orders.
3. **Silent errors = P0** — any 4xx/5xx without a visible `[role=alert]` / `.toast` / `.alert-*` user-facing surface is P0.
4. **No companion-spec attribution** — if a fact is "covered by Wave P" but NOT in THIS Wave T capture, it's NOT covered for this audit. Don't lean on prior runs.
5. **NF525 chain unchanged** post-audit — `php artisan fiscal:verify-chain --tail` last_hash matches pre-test or correctly grew by exactly the appended events.
6. **French i18n 100%** — no English fallback strings visible.

---

## 10. Artifact quartet (every visual state)

For each capture, spec emits 4 sibling files in `tests/e2e/__screenshots__/test-e2e-wave-t-<W>-<surface>/`:

```
<state>.png         ← screenshot via page.screenshot({ fullPage: false })
<state>.dom.html    ← await page.content() (truncated 2MB)
<state>.console.json ← console.* + pageerror sink (level + text + location + ts)
<state>.network.json ← responses status>=400 OR duration>2000ms (url+method+status+duration+ts)
```

Helper: `attachMegaAuditRecorder(page, screenshotDir)` from `tests/e2e/helpers/mega-audit-snap.js`.
Each `snap(name)` writes all 4. Buffers reset per snap (decoupled).

---

## 11. Findings JSON (per wave per round)

Schema strict per `~/.claude/skills/test-e2e/references/FINDINGS_SCHEMA.md`:

Adversarial reviewer emits one file per wave per round:
`reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/round-<N>/wave-<W>-findings.json`

Capture artifact log (parallel file from GStack team, not adversarial):
`reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/round-<N>/wave-<W>-capture.json` — lists every state captured + spec exit + timing.

ID convention: `<WAVE>-<NNN>` e.g. `A-001`, `B-007`, `C-002`, `D-005`.

### Verdict per wave
- `GREEN` → open_P0 == 0 AND open_P1 == 0
- `AMBER` → open_P0 == 0 AND open_P1 > 0
- `RED` → open_P0 > 0

---

## 12. Reports tree structure

```
reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/
├── PLAN.md                                          ← THIS FILE
├── REVIEWER_PROTOCOL.md                             ← copied from skill (orchestrator pre-flight #9)
├── POS/                                             ← orchestrator pre-scaffold, populated post-capture (rsync)
├── KDS/                                                from tests/e2e/__screenshots__/test-e2e-wave-t-{A,B,C,D}-*/
├── OSS/                                                Per-surface PNG aggregation for owner review.
├── LIVREUR/                                            (Authoritative artifacts live under tests/e2e/__screenshots__/.)
├── round-1/
│   ├── wave-A-capture.json     ← GStack team output (artifacts + timing + spec exit)
│   ├── wave-A-findings.json    ← Adversarial output (open_P0/P1, verdict)
│   ├── wave-B-capture.json
│   ├── wave-B-findings.json
│   ├── wave-C-capture.json
│   ├── wave-C-findings.json
│   ├── wave-D-capture.json
│   └── wave-D-findings.json
├── round-2/
│   └── … (identical shape; only present if round 1 not converged)
├── round-3/
│   └── … (identical shape)
└── CONVERGENCE_FINAL.md                             ← orchestrator emits on convergence
```

Per advisor reconcile: `POS/`, `KDS/`, `OSS/`, `LIVREUR/` (pre-scaffolded by orchestrator) = **per-surface PNG aggregation** populated AFTER each round via post-capture rsync. Authoritative artifacts remain under `tests/e2e/__screenshots__/test-e2e-wave-t-<W>-<surface>/`. Findings JSON live SOLELY under `round-N/`.

---

## 13. Severity gate & loop rules (orchestrator-side)

Per `~/.claude/skills/test-e2e/references/CONVERGENCE_RULES.md`:

1. **Block delivery**: any wave with `open_P0 > 0` or `open_P1 > 0` → orchestrator spawns fix agents on offending findings, then re-runs (clean re-capture).
2. **Convergence**: 2 consecutive rounds where ALL 4 waves are GREEN AND `set(findings_N.id) == set(findings_(N-1).id)`. Set-equality catches flakes.
3. **No iteration cap** — owner mandate is no-cap. Orchestrator loops until convergence.
4. **Stash defense** — after every fix batch, orchestrator verifies `git status --short` clean & `git log --oneline -5` includes the fix commits BEFORE next round. Lost iter15 lesson.
5. **Wave A hard-gate** — B/C/D cannot start in round N if Wave A round N produced a RED verdict. Re-fix A first.

### Convergence rule (declared by orchestrator, NOT by Plan agent / GStack / adversarial)
Plan agent (this file) does NOT score, does NOT loop, does NOT fix. Orchestrator is the loop controller.

---

## 14. Wave S validation hooks (concrete assertions, summary)

These hooks are embedded in the per-wave assertion sections above; this is the cross-reference table for owner traceability.

| Wave S commit | Wave T hook | Assertion (concrete DOM/network/CSS check) |
|---------------|-------------|----------------------------------------------|
| **S-1 (`aaae9c916`)** auto ACCEPT→PREPARING | **A-S1** | Within 5s of pay confirm 200, tracker tile has `[data-status="PREPARING"]` (NOT `CONFIRMED`). |
| **S-2 (`52ddbb024` + `9f74c3bbb`)** 1-clic CTA | **B-S2** | KDS card in PRÉPARATION column has exactly 1 `role=button` (single CTA "Prêt"). |
| **S-2** 1-clic PREPARING→PREPARED | **B-S2-NETWORK** | Click triggers exactly 1 `PATCH /api/admin/orders/<id>/status` → `status=PREPARED` (no intermediate). |
| **S-2** cash-pending badge | **B-S2-BADGE** | Cash order shows `.cash-pending-badge` visible; TPE order does NOT. |
| **S-3 (`890f5b5f1`)** OSS font 56px | **C-S3** | `getComputedStyle(.oss-order-tile).fontSize >= 40px` (≥ 40 covers 56 token + safety margin). |
| **S-3** pulse animation | **C-S3-PULSE** | PRÊT tile recently-READY has `animation-name` matching `pulse-*` (or `el.getAnimations().length > 0`). |
| **S-4 (`52ddbb024`)** Suivi "À ENCAISSER" col | **A-S4** | TPE-paid Order #2 NOT in "À ENCAISSER" column. (Cash-pending-only column.) |

---

## 15. Risks & mitigations

| Risk | Mitigation |
|------|-----------|
| Wave A capture fails → B/C/D blocked | Hard-gate documented (section 3). Spec sentinel-skip with explicit message. |
| Delivery boy seed missing | Pre-flight check #5 (section 4). Spec author creates via `DeliveryBoyService::store()` in globalSetup. |
| TPE simulation hardware glitch on pay confirm | `POS_SIMULATION_HARDWARE=true` already active; spec retries pay confirm if `payment_status` not COMPLETE within 10s. |
| OSS pulse animation flaky (timing-dependent) | Capture State 01 within 30s of Wave B's `kds_ready_at_order_1` (fixture timestamp). Use animation API not screenshot diff. |
| Order #2 leak onto OSS | C-ALLOW assertion is P0. Allowlist enforcement is Wave O R3 closed; regression check here. |
| NF525 chain drift mid-audit | Pre-flight #9 records `last_hash`. Post-Wave-D spec verifies `php artisan fiscal:verify-chain --tail` either unchanged OR cleanly appended. |
| Suivi route name ambiguity (`/admin/pos-orders-tracker` vs older paths) | Spec author verifies exact route during authoring via `routes/web.php` grep + DevTools. Document in spec header comment. |
| Browser auto-stash mid-audit drops fixes | Orchestrator runs `git stash list` + `git log --oneline -5` between rounds; commits fix-agent output explicitly. |

---

## 16. Acceptance criteria (final convergence)

Audit closed when ALL of these hold across 2 consecutive rounds:

1. Wave A, B, C, D each verdict = `GREEN` (open_P0 == 0 AND open_P1 == 0).
2. Findings set identity across rounds N-1 and N (set-equality on `finding.id`).
3. All 4 Playwright specs exit 0 on both rounds.
4. NF525 chain `count` increment from pre-test = exactly the events appended (no holes, no rewrites).
5. Frozen-zone diff per `CLAUDE.md §7` == 0 lines (no PaymentComponent, FiscalSequenceService, PricingService, BranchScope, etc. touched).
6. Cross-surface numeric integrity proven for both orders across all relevant surfaces (POS cart → POS receipt → KDS card → Suivi tile → OSS tile (Order #1) / admin orders list (Order #2)).
7. Owner mandate (section 1) fully satisfied: caisse → préparation → prêt → livré client (Order #1 via OSS) AND livré livreur (Order #2 via tracker).

When all 7 hold → orchestrator emits `CONVERGENCE_FINAL.md` and audit closes.

---

## 17. What this Plan agent does NOT do

- Does NOT write the Playwright specs (GStack capture agent does that, per round)
- Does NOT capture screenshots
- Does NOT score findings (adversarial reviewer does that)
- Does NOT fix code (fix agent does that, per finding cluster)
- Does NOT declare convergence (orchestrator does that)

Plan agent's only output: **this file**.

---

## 18. Next steps for orchestrator

1. **Run pre-flight** (section 4) — 10 checks, fix any failure before round 1.
2. **Spawn Wave A GStack capture agent** with this PLAN + Wave A section + token prefix mandate.
3. **Wait** for Wave A spec exit 0 + fixture file present.
4. **Spawn Wave A adversarial review agent** with this PLAN + REVIEWER_PROTOCOL + Wave A capture.
5. **Read** `round-1/wave-A-findings.json`. If `verdict == RED` → spawn Wave A fix agent → re-loop A. Else proceed.
6. **Spawn Wave B + C + D GStack agents** (in parallel, since Wave A fixture is now durable).
7. **Spawn Wave B + C + D adversarial agents** after each capture exits 0.
8. **Aggregate** all 4 findings JSON. If any wave RED → fix → re-loop offenders. If all GREEN → run round 2.
9. **Convergence check** post-round-2: set-equality + GREEN for all 4 → emit `CONVERGENCE_FINAL.md` + commit.

---

**END OF PLAN**
