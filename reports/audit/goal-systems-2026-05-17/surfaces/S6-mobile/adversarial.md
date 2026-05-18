# S6 MOBILE — Adversarial RED-Team Audit (2026-05-17)

> Hostile static analysis of `mobile/` surface. Read-only. file:line citations.
> Scope: V0 prototype customer-facing app (HTML+JSX prototype, no React Native build yet).
> Threat model: menu drift, allergen liability, fake order V0, promo abuse,
> reverse-engineer / API abuse, App Store pull, PII / RGPD, outdated app,
> dependency CVE, payment bypass.

---

## EXECUTIVE FRAMING (must read first)

**Critical context**: `mobile/` is NOT a shipped React Native / Expo app. There
is **no `package.json`**, **no `app.json`**, **no `eas.json`**, **no native
binaries**, **no real network calls**. It is a browser-runnable HTML+JSX
prototype with comments like `// Phase 6 : will POST /api/v1/frontend/...`.

This changes severity calculus drastically:
- **All "client-side trust" findings are MAX severity in their stub state**,
  because there is literally no backend re-verify path. Every mock is the
  source of truth right now.
- **Dependency CVE audit is N/A** (no deps installed) — but the *absence* of
  a package.json is itself a SHIP BLOCKER finding (R-OPS-001).
- **App Store / reverse-engineer findings apply** at the point Phase 6 wires
  the stubs to real endpoints WITHOUT adding backend re-validation. Many
  comments hint that authors plan to trust client-computed values.

The audit documents:
1. What is exploitable *today if shipped as-is*.
2. What is exploitable *the moment Phase 6 naively wires up* (the documented
   trajectory in code comments).
3. Architectural traps already baked in that Phase 6 will inherit silently.

---

## RED-TEAM FINDINGS

### R-AUTH-001 — P0 BLOCKER — Hardcoded mock token: anyone "logs in" as user_id=12345
- **File:line**: `mobile/index.html:175-178`
- **Attack dim**: #5 (reverse engineer + API abuse), #7 (PII access)
- **Code**:
  ```js
  const onLogin = () => {
    storage.setAuth({ token: 'mock-v0-token', phone: '+33642799884', user_id: 12345 });
    storage.markOnboardingSeen();
  };
  ```
- **Exploit**: ScreenOTP (`mobile/screens-onboarding.jsx:245-313`) accepts ANY 4 digits — no
  verification against any backend, no rate limiting on retry, no captcha. The OTP
  hint "Code de démo : 1234" is shown when `window.LC.isDev` is truthy (line 296-298),
  but the OTP value is never checked anywhere. The auto-advance at line 254
  (`if (next.join('').length === 4) setTimeout(onNext, 400)`) accepts 0000, 9999,
  literally anything. On success the SAME `user_id=12345` token is granted to
  every attacker, who then has read access to "Ikyes B." loyalty data (347 pts,
  Visa **4242 on file, +33 6 42 79 98 84 phone), order history, etc.
- **Severity**: SHIP-BLOCKER. If this HTML is hosted publicly (which is the
  current shape — static files in `mobile/`), GDPR breach (Art. 32) on day 1.

### R-PAY-001 — P0 BLOCKER — "Pay at counter" bypasses ALL backend, awards loyalty points client-side
- **File:line**: `mobile/index.html:211` and `mobile/index.html:131-138`
- **Attack dim**: #3 (fake order V0), #4 (promo abuse), #5 (API abuse)
- **Code**:
  ```js
  // index.html:211
  onPickCounter={()=>{ setModal(null); setHistory(h=>[...h,'confirm']);
                       setScreen('confirm'); setCart([]);
                       setTimeout(()=>setModal('gain'), 900); }}
  // index.html:131
  const snapshotOrder = () => {
    const id = 'C-' + Math.floor(1000 + Math.random() * 9000);
    const total = cart.reduce((s, i) => s + (i.lineTotal || i.price * i.qty), 0);
    ...
  };
  ```
- **Exploit**: The "Payer à la caisse" button fires zero network requests.
  ScreenConfirm renders a QR ticket (`LECAY-ORDER-${id}` line 744), the cart
  is cleared, and points are credited via `ModalPointsGain`. An attacker can
  generate **infinite fake order receipts** (with valid-looking C-#### numbers
  collision-likely against real ones since the range is only 9000 IDs), screenshot
  the QR + total, present at the counter, and demand the order. Restaurant
  has zero way to validate without manual phone lookup. Equally, loyalty
  points are awarded BEFORE pickup — see R-LOY-001.
- **Severity**: SHIP-BLOCKER. The entire payment fail-open story has no
  guard at all. The Stripe path (`ScreenStripe` in `screens-modals.jsx:68-109`)
  is identically broken — `onClick={() => go('confirm')}` at line 103 advances
  to confirmation WITHOUT calling Stripe (hardcoded `4242 4242 4242 4242`
  rendered as placeholder text only).

### R-LOY-001 — P0 — Client-side loyalty debit/credit, no server authority
- **File:line**: `mobile/data/dev-helpers.js:123-185` (`redeemReward`)
                 and `mobile/data/dev-helpers.js:44-87` (`earnPoints`)
- **Attack dim**: #5 (API abuse), #3 (fraud / fake commerce)
- **Code**:
  ```js
  // dev-helpers.js:154
  const newBalance = account.balance - reward.points_cost;
  LC.loyalty._replaceAccount({ balance: newBalance, ... });
  ```
- **Exploit**: All loyalty mutations happen in localStorage. The "atomic
  balance check + debit with idempotency" claim at line 90 is purely
  client-side. Open DevTools, run
  `LC.loyalty._replaceAccount({balance: 999999999})` → infinite points →
  redeem all rewards → present QR codes at counter. Restaurant has no API
  to cross-check; staff sees "Code LCY-XXXXXX" with a balance number and
  trusts it.
- **Severity**: At launch this is a Le-Cayenne-only nuisance ($X loss per
  fraudster). At Phase 6 native build it becomes catastrophic if the migration
  PRESERVES the client-trust shape: comment at `dev-helpers.js:10-14` warns
  "the dev namespace gets gated by build env" — but `LC.loyalty._replaceAccount`
  is NOT under `LC.dev`. It is exposed via `data/loyalty.js:258` directly on
  the production namespace.

### R-LOY-002 — P0 — Idempotency key is deterministic client-derived, 10-min collision window
- **File:line**: `mobile/data/loyaltyRewardState.js:170-173`
- **Attack dim**: #5 (API abuse), #3 (loyalty double-debit)
- **Code**:
  ```js
  function redemptionIdempotencyKey(userId, rewardId) {
    const win = Math.floor(Date.now() / (10 * 60 * 1000));
    return 'redeem-' + userId + '-' + rewardId + '-' + win;
  }
  ```
- **Exploit**: The key is `redeem-{user_id}-{reward_id}-{floor(now/10min)}`.
  An attacker who knows `user_id=12345` (always — see R-AUTH-001) and a
  reward_id (1-8 from `data/loyalty.js:112-121`) can precompute the next
  60 windows of keys and submit pre-emptive idempotency claims against the
  Phase 6 backend. Also the GAP documented at lines 164-168 (boundary-crossing
  replay at minute 9:59 → 10:01) means the same redemption can be replayed
  legitimately every ~10 min, indefinitely. Backend `Idempotency-Key`
  middleware (B-02) MUST reject client-provided keys for redeem and use
  server-generated UUIDs only.

### R-PROMO-001 — P0 — Promo codes hardcoded client-side, 10% discount on ANY accepted code
- **File:line**: `mobile/screens-main.jsx:1339-1387` (`PromoCodeRow`) and
                 `mobile/screens-main.jsx:598-601` (`ScreenCart` total calc)
- **Attack dim**: #4 (promo abuse)
- **Code**:
  ```js
  // screens-main.jsx:1346
  if (trimmed === 'WELCOME10' || trimmed === 'CAYENNE') {
    setStatus('applied'); setAppliedCode(trimmed);
    if (typeof onApply === 'function') onApply(trimmed);
  }
  // screens-main.jsx:600
  const discount = promoCode ? Math.round(subtotal * 10) / 100 : 0;
  ```
- **Exploit**: (a) Both promo codes are in plaintext in JS the browser ships
  — reverse engineering trivial. (b) The discount is computed entirely
  client-side; the cart total displayed to the user is `subtotal - discount`,
  and the "Pay at counter" path (R-PAY-001) accepts whatever total the
  client claims. (c) The discount logic only checks "is `promoCode` truthy",
  so an attacker who modifies the state to `promoCode = "FREE100"` would
  still only get 10% — because the rate is hardcoded — but combined with
  R-PAY-001 they can override total to 0 entirely. (d) The discount
  computation `Math.round(subtotal * 10) / 100` (line 600) is mathematically
  ALSO bugged: a €17.55 subtotal gives `Math.round(175.5)/100 = 176/100 =
  €1.76` discount (10.03%), not €1.755 → over-credit by €0.005 per order.
- **Severity**: P0 promo abuse + P3 rounding drift.

### R-MENU-001 — P1 — Menu pricing/items diverge from backend SSOT, no client-side reconciliation
- **File:line**: `mobile/data/menu.js:7-13` ("/config/menu.php = STALE")
                 plus 37 hardcoded items lines 389-502
- **Attack dim**: #1 (menu drift)
- **Exploit**: Comment at `menu.js:13` explicitly says `/config/menu.php`
  is stale and that the SSOT is now `MenuResetLeCayenneCommand.php` +
  `MenuHealLightV2Command.php`. There is **no runtime mechanism** to verify
  mobile menu vs DB. If owner updates Big Cayenne from €9.50 → €10.50 in DB,
  mobile keeps showing €9.50. Customer orders, pays €9.50 client-computed
  (R-PAY-001), restaurant claims €10.50 → dispute, refund, bad review.
  Equally damaging in reverse: backend gets €10.50, mobile says €9.50,
  refund cycle for "wrong price advertised". The audit of competitor pricing
  drift across "tacos-xxl" (referenced at `screens-main.jsx:109` but
  REMOVED from menu data — see R-MENU-002 below) proves drift is already
  live.

### R-MENU-002 — P2 — Stale slug reference: featured card targets `tacos-xxl` which no longer exists
- **File:line**: `mobile/screens-main.jsx:109`
- **Code**:
  ```js
  const featured = window.LC.menu.findItem('tacos-xxl') || window.LC.menu.items[0];
  ```
- **Exploit**: `findItem` returns `undefined` because the heal-light v2
  refactor (`menu.js:431-441`) renamed tacos to `tacos-1-viande` and
  `big-tacos-2-viandes`. The home screen "SIGNATURE" featured card silently
  falls back to `items[0]` (Sandwich Cayenne) — branding incoherence (says
  "tacos signature" with sandwich image). For an attacker this is just an
  amusing tell that the codebase is drift-prone; for marketing it's a
  homepage that doesn't show what management thinks it shows.

### R-ALLERG-001 — P0 BLOCKER — Allergens hardcoded per category default, customer-supplied substitutions not re-checked
- **File:line**: `mobile/data/menu.js:233-246` (`defaultAllergensFor`)
                 and `mobile/screens-item-steps.jsx:815-816, 1128-1129`
- **Attack dim**: #2 (allergen liability), #6 (App Store pull)
- **Code**:
  ```js
  // menu.js:233-244
  function defaultAllergensFor(cat, opts) {
    if (opts && opts.allergens !== undefined) return opts.allergens;
    switch (cat) {
      case 1: case 2: case 3: case 4: case 5: return ['gluten'];
      case 6: return [];           // Bols
      case 7: return [];           // Frites
      ...
    }
  }
  ```
- **Exploit**: Allergens are derived from CATEGORY, not from actual recipe.
  Examples of life-threatening inaccuracies:
  - Bols (cat 6) declared `[]` but `bol_sauce_default: 'Sauce fromagère
    maison'` → contains LACTOSE, undisclosed (`menu.js:445-452`).
  - Frites (cat 7) declared `[]`. The "Cheddar fondu" style option
    (`menu.js:191`) adds lactose; the wizard cart line will not augment
    the displayed allergen list. Customer with severe lactose intolerance
    selects "Cheddar fondu", sees Frites with `[]` allergens, eats it.
  - Big Cayenne (`menu.js:394-397`) lists `[gluten]` but description says
    "INCLUS : Cheddar + Œuf + Jambon" → lactose AND œuf undeclared by default.
  - All sandwich items default to `[gluten]` only — no `[oeuf]` for Big
    Classique even though included recipe has Œuf (`menu.js:417-418`).
  - Tacos (cat 5) declared `[gluten]` only — no `[lactose]` even though
    "Sauce fromagère maison" is the locked sauce (`menu.js:437`).
  - Supplements (cat 8) per-item: Boursin lists `[lactose]` correctly
    (`menu.js:471`) — but Jambon at `menu.js:473` declares `[]` despite
    classic French jambon often containing sulfites/lactose-from-additives.
- **Liability**: EU FIC 1169/2011 mandates declaration of 14 allergen
  groups. The code at `screens-main.jsx:36-37` cites the regulation but
  the implementation hardcodes only category-level defaults. **A single
  anaphylactic incident from a misdeclared allergen = restaurant criminal
  exposure + App Store immediate removal** (App Review Guideline 1.5 —
  Developer Information accuracy, 5.1.1 — Health/Medical).
- **Severity**: SHIP-BLOCKER. The composer profile (`menu.js:294-374`)
  also does NOT recompute allergens based on selected sauce / supplement /
  drink — the cart line's `composition_summary` is text-only.

### R-RGPD-001 — P0 — PII in plaintext localStorage, no encryption-at-rest, no export API
- **File:line**: `mobile/api/storage.js:8-25` (storage primitives),
                 `mobile/data/user.js:11-36` (PII shape),
                 `mobile/index.html:176` (token written to LS)
- **Attack dim**: #7 (customer PII), #10 (RGPD)
- **Stored in localStorage `lecayenne.*` unencrypted**:
  - `lecayenne.auth` = `{token, phone, user_id}` — phone number in clear text
  - `lecayenne.cart` = order composition (food preferences = sensitive per
    GDPR Art. 9 if related to health/religion → dietary restrictions could
    be religion proxy)
  - `lecayenne.loyalty` = full account (member_number, lifetime_earned, etc.)
  - `lecayenne.loyalty_consent` = consent flag (must be auditable per GDPR
    Art. 7(1) — there is no audit trail, only current state)
  - `lecayenne.qr_preference`, `lecayenne.loyalty_idempotency` (last 500
    redemption events with timestamps)
- **Missing**:
  - No data export endpoint (GDPR Art. 15 right of access)
  - No deletion API (Art. 17 right to be forgotten) — `ModalOptOutConfirm`
    (`screens-modals.jsx:311-334`) only clears loyalty fields, leaves
    `auth`, `cart`, `onboarding_seen`, `qr_preference` etc.
  - No data residency declaration (EU vs US storage)
  - No retention period (cf. NF525 6-year hold for fiscal — but loyalty
    is NOT fiscal-bound, so should follow standard 3-year-since-inactive
    GDPR principle)
- **Exploit**: Any malicious JS injected (XSS — see R-XSS-001) or shared
  device (PWA on family iPad) leaks full PII trivially. Browser extension
  with `storage` permission reads everything.
- **Severity**: P0 — CNIL fines up to €20M / 4% global revenue.

### R-XSS-001 — P1 — Babel-standalone in production + 3 unpinned CDN scripts (subresource integrity present but supply-chain risk)
- **File:line**: `mobile/index.html:77-79`
- **Code**:
  ```html
  <script src="https://unpkg.com/react@18.3.1/umd/react.development.js"
          integrity="sha384-hD6..." crossorigin="anonymous"></script>
  <script src="https://unpkg.com/@babel/standalone@7.29.0/babel.min.js"
          integrity="sha384-m08..." crossorigin="anonymous"></script>
  ```
- **Exploit**: (a) `react.development.js` is the unminified dev build —
  shipping to prod = bigger bundle + dev warnings exposing component state
  via React DevTools more verbosely. (b) `@babel/standalone` in production
  means EVERY user's browser compiles JSX at runtime — significant CPU /
  battery cost on low-end Android (matters for the customer-facing
  audience). (c) SRI is present (good) but unpkg.com is a single point of
  failure — a CDN compromise that swaps the integrity-matching file (only
  possible if SHA-384 collision found, currently infeasible) would inject
  any script. (d) `crossorigin="anonymous"` is correct. The `babel` runtime
  parses JSX strings the developer wrote — there's no user-controlled JSX
  string to attack, so no traditional XSS sink, BUT (e) `localStorage`
  values like `auth.phone` are rendered into DOM verbatim
  (`screens-onboarding.jsx:263` "Code envoyé au +33 6 12 34 56 78") with
  React's auto-escaping — that's safe. **No `dangerouslySetInnerHTML`,
  no `eval`, no `new Function` found via grep**. Risk reduces to supply-chain
  (CDN) and bundle bloat. Severity: P1 (supply chain) + P2 (bundle size).

### R-OPS-001 — P0 BLOCKER — No package.json, no app.json, no build config, no native shell
- **File:line**: `find mobile/ -name "package.json"` returns nothing
- **Attack dim**: #8 (outdated app), #9 (CVE), #6 (App Store)
- **Implication**: There is no Expo SDK version, no react-native version,
  no build script, no fastlane config, no signing keys. This means:
  - Cannot ship to App Store / Play Store: no `Bundle ID`, no `applicationId`,
    no entitlements.
  - Cannot enforce force-update: no version negotiation, no
    `expo-updates` channel, no `minimum_supported_version` check against a
    backend `/health` ping.
  - Cannot audit CVE surface: no `npm audit`, no Snyk, no Dependabot
    targets.
  - The "Phase 6 = Capacitor / Vue 3 / RN" mention (`dev-helpers.js:13`)
    is not converging on a single target — the comment-trail mentions
    Capacitor, RN, AND Vue 3 in different files. Architecture indecision
    = ship date uncertainty.
- **Severity**: SHIP-BLOCKER for "is this an app?" question. Today the only
  shippable artefact is the HTML hosted as a PWA.

### R-OUTDATED-001 — P1 — No version check, no force-update plumbing
- **File:line**: `mobile/index.html` (entire) — no `<meta name="app-version">`,
                 no `/api/v1/frontend/version` check pre-rendering
- **Attack dim**: #8 (outdated app indefinitely shows stale menu)
- **Exploit**: Customer installs app v1.0 (or pins the PWA to home screen)
  in 2026. Owner changes menu, prices, allergens 6 months later. Stale
  client keeps showing old menu indefinitely — combined with R-PAY-001 +
  R-MENU-001, customer can place an order for an item that was removed
  (e.g. "Bacon" supplement that the heal-light v2 archived) and present
  a QR ticket the counter can't fulfill.

### R-PWA-001 — P2 — apple-mobile-web-app-capable=yes with no service worker, no offline cache, no PWA manifest
- **File:line**: `mobile/index.html:7-10`
- **Attack dim**: #8 (outdated app), #6 (App Store discovery)
- **Exploit**: Installable as Apple home-screen webapp but with no
  manifest.json, no SW, no offline support → customer in restaurant with
  bad cellular signal loses the entire cart on page refresh. No `link
  rel="manifest"` declaration. Doesn't qualify as PWA for any browser's
  install prompt criteria.

### R-QR-001 — P1 — Loyalty QR signature is client-mocked SHA-256 with NO secret, easily forged
- **File:line**: `mobile/data/loyalty.js:166-188` (`generateSignedQR`)
- **Code**:
  ```js
  const data = new TextEncoder().encode(code + '|' + exp);
  const buf = await crypto.subtle.digest('SHA-256', data);
  signature = Array.from(new Uint8Array(buf))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('').slice(0, 32);
  ```
- **Exploit**: The "signature" is SHA-256(loyalty_code|expires_at) truncated
  to 128 bits. **No secret involved**. Anyone with the loyalty_code (which
  is shown on the user's own QR rendered visibly in the UI:
  `data/loyalty.js:131` `loyalty_code: 'A1B2C3D4'`) can mint valid
  signatures for arbitrary expires_at values.
  - Comment at line 160 explicitly states "V0 backend `scan()` strips
    the `FK:` prefix and matches loyalty_code — IGNORES signature."
    So the signature is purely decorative TODAY.
  - In Phase 6 if `/loyalty/qr/sign` returns HMAC(app.key, ...) (line 161)
    BUT the V0 mock signature continues to be accepted (e.g. fallback
    path), attackers can forge any user's QR until the V0 fallback is
    removed.
- **Severity**: P1 today (loyalty fraud via shared device or shoulder-surfed
  loyalty_code) → P0 the moment backend trusts ANY signature.

### R-DEV-001 — P0 — `LC.dev` namespace exposed in production: shipped helpers grant points, redeem rewards, time-travel
- **File:line**: `mobile/data/dev-helpers.js:10-14, 349-362`
- **Code comment**:
  ```js
  // ⚠️ This file is loaded in production V0 app. It is intentionally not
  //    behind a `if (DEBUG)` flag because V0 IS a prototype; in Phase 6
  //    native build (Capacitor / Vue 3 / RN per CONNECTION_PLAN.md §4) the
  //    dev namespace gets gated by build env.
  ```
- **Exploit**: DevTools console in any deployed V0 build:
  - `LC.dev.earnPoints(10000, 'manual_cashier')` → instant 10k points
  - `LC.dev.advanceTime(86400 * 365 * 10)` → fast-forward 10y for testing
    expiry behaviors (or to defeat any time-based throttle that gets added)
  - `LC.dev.seedAccount({balance: 999999})` → set balance directly
  - `LC.dev.simulateRefund('C-1234')` → reverse the earn for an order
    (mint negative redeems)
- **Severity**: P0 if any V0 build is ever live (preview link shared,
  staging deployed publicly, etc.). The comment-promised "Phase 6 native
  build gates by env" is a future tense — no current gate.

### R-IMG-001 — P2 — `Date.now` globally monkey-patched, breaks any time-sensitive code that loads after dev-helpers.js
- **File:line**: `mobile/data/dev-helpers.js:21-27`
- **Code**:
  ```js
  let _timeOffsetMs = 0;
  const _origDateNow = Date.now.bind(Date);
  function _stubbedNow() { return _origDateNow() + _timeOffsetMs; }
  Date.now = _stubbedNow;
  ```
- **Exploit**: This file is loaded LAST in `index.html:74` — but it
  unconditionally replaces `Date.now` for the entire app. All subsequent
  date computations (JWT exp validation if added, idempotency windows,
  QR expiry, ETA display) silently honor the offset. Defense-in-depth
  failure: even a `_timeOffsetMs` reset only restores the offset, not the
  identity of `Date.now`. A third-party analytics or anti-fraud script
  loaded later cannot distinguish stubbed time from real time.

### R-AUTH-002 — P1 — Token has no expiry, no rotation, never sent to any backend
- **File:line**: `mobile/index.html:176` (`token: 'mock-v0-token'`)
- **Exploit**: `isAuthenticated()` (`api/storage.js:52-55`) only checks
  truthy `a.token` — no expiry validation, no signature verification. The
  same literal token persists in localStorage indefinitely. If Phase 6 wires
  a real Sanctum bearer here without changing the check, an attacker who
  steals the localStorage value once retains access until the user manually
  logs out. No silent refresh, no last-active timestamp, no device binding.

### R-AUTH-003 — P2 — `clearAuth` does NOT clear all PII; partial logout leaves traces
- **File:line**: `mobile/api/storage.js:35-51`
- **Code**: clears `auth`, `cart`, `loyalty*`, `redeemed_keys`,
  `wallet_*_dismissed_at`. Does NOT clear `onboarding_seen` (so re-login
  skips intro), `qr_preference`. More importantly it does NOT wipe browser
  IndexedDB / SessionStorage / cookies if any get added later.
- **Severity**: P2 — privacy minor leak; user expects logout to be complete.

### R-ORD-001 — P1 — Order IDs collide deterministically with real fiscal serials (C-#### range, 9000 buckets)
- **File:line**: `mobile/index.html:132`
- **Code**: `const id = 'C-' + Math.floor(1000 + Math.random() * 9000);`
- **Exploit**: ID space is `C-1000` through `C-9999`. The mock ORDERS_HISTORY
  uses `C-1100, C-1142, C-1190, C-1208, C-1212, C-1234` (`data/orders.js`).
  At Phase 6 wireup if the backend assigns fiscal serials in the same
  prefix space, mobile-generated IDs collide with backend-generated ones.
  Also collision among mobile users themselves (the birthday paradox at
  N=9000 reaches 50% at ~110 orders generated).

### R-FRAUD-001 — P1 — Reorder path silently re-applies stale prices from history snapshot
- **File:line**: `mobile/data/orders.js:7-8` and historical entries
                 `mobile/data/orders.js:67-78` (Bowl Cheesy 11.50, C-1190)
- **Exploit**: Comment line 8 promises `create ↔ POST /api/v1/frontend/order
  avec composition_snapshot` — but in V0 there is no create call. If Phase 6
  uses the history line_total as `composition_snapshot.total`, attacker
  re-orders an item whose price has since gone up (Sandwich Cayenne was
  €7.00, now €7.50 per `menu.js:390` heal-light v2 note) → backend trusts
  the snapshot total. Lose 50 cents per re-order at scale.

### R-NETLESS-001 — P2 — Zero network observability: no error reporting, no analytics, no crash reporter
- **File:line**: entire mobile/ directory, no Sentry/Bugsnag/Datadog
- **Exploit**: Customer encounters a JS crash mid-flow → blank screen, no
  recovery, no signal back to ops. Restaurant ops won't even know the app
  is broken. Reputation damage compounds silently.

### R-COMP-001 — P2 — Composer profile drift: hardcoded `composer_profile` "mirrors DB shape" but with no schema validation
- **File:line**: `mobile/data/menu.js:277-285, 294-375`
- **Code comment**: "composer_profile hardcoded mirror DB shape for wireup
  futur mécanique"
- **Exploit**: When Phase 6 swaps in real API response, no validator checks
  the API matches the assumed shape. Mismatched field (e.g. backend renames
  `step_key` → `key`) silently renders empty wizard. Customer cannot complete
  bowl order, abandons cart.

### R-MOCK-001 — P3 — Wallet stubs return placeholder SVG; users tap "Add to Apple Wallet" → confused
- **File:line**: `mobile/data/wallet-spec.js:32-46`
- Behavior is correct (modal explains "Bientôt disponible") but the asset
  URLs reference stub SVGs that look indistinguishable from official Apple
  branding (Apple Wallet brand guidelines violation = potential trademark
  takedown request to App Store reviewer).

### R-EARN-001 — P1 — Welcome bonus credit triggered client-side on first login, no anti-abuse
- **File:line**: `mobile/data/loyalty.js:89` (`code: 'welcome_bonus'`,
                 status `'mock'`) and `index.html:175-178`
- **Exploit**: Each `clearAuth` (logout) resets the `loyalty_idempotency`
  store but NOT the welcome bonus dedupe (because welcome credit is granted
  by `LC.dev.earnPoints(25, 'welcome_bonus')` on a fresh account — there is
  no per-user welcome flag). Attacker logs out, logs back in, claims 25 pts
  again. With R-AUTH-001 there's no user identity to constrain this to one
  per-phone — same `user_id=12345` granted to all, but the localStorage is
  device-scoped, so an attacker on incognito tab gets fresh +25.

---

## SUMMARY TABLE

| ID | P | Vector | One-line |
|---|---|---|---|
| R-AUTH-001 | P0 | #5 #7 | Mock token, ANY OTP accepted, user_id=12345 leaked |
| R-PAY-001  | P0 | #3 | "Pay at counter" creates fake order with no backend call |
| R-LOY-001  | P0 | #5 | Loyalty mutations purely client-side |
| R-LOY-002  | P0 | #5 | Idempotency key deterministic, replayable cross-window |
| R-PROMO-001| P0 | #4 | Hardcoded promo codes, 10% on any code, math bug |
| R-MENU-001 | P1 | #1 | Mobile menu drifts from DB SSOT silently |
| R-MENU-002 | P2 | #1 | Stale slug `tacos-xxl` → wrong featured card |
| R-ALLERG-001 | P0 | #2 #6 | Cat-level allergen defaults miss recipe ingredients (lactose in bols/sauces) |
| R-RGPD-001 | P0 | #7 #10 | Plaintext localStorage PII, no export, no deletion API |
| R-XSS-001  | P1 | #5 | CDN+Babel runtime production shipment, supply-chain |
| R-OPS-001  | P0 | #8 #9 #6 | No package.json, cannot ship to stores, no CVE audit |
| R-OUTDATED-001 | P1 | #8 | No force-update / version check plumbing |
| R-PWA-001  | P2 | #8 #6 | No manifest, no SW, no offline |
| R-QR-001   | P1 | #2 #5 | Loyalty QR signature mock, no secret, forgeable |
| R-DEV-001  | P0 | #5 | `LC.dev.*` shipped to prod, instant point fraud |
| R-IMG-001  | P2 | #5 | `Date.now` globally monkey-patched in prod |
| R-AUTH-002 | P1 | #5 | Token has no expiry, no rotation |
| R-AUTH-003 | P2 | #7 | clearAuth leaves traces |
| R-ORD-001  | P1 | #3 | Client-generated order IDs collide with real fiscal serials |
| R-FRAUD-001 | P1 | #1 #3 | Reorder uses stale price snapshot |
| R-NETLESS-001 | P2 | — | No crash reporter / observability |
| R-COMP-001 | P2 | #1 | No schema validation for composer_profile API swap |
| R-MOCK-001 | P3 | #6 | Apple Wallet stub branding may violate trademark |
| R-EARN-001 | P1 | #3 #4 | Welcome bonus re-claimable per device wipe |

**Counts**: 9 P0 (ship-blockers), 8 P1, 6 P2, 1 P3. Total 24 findings.

---

## SHIP VERDICT

**NO-GO for production**. The mobile codebase is in V0 prototype state and
must NOT be shipped (or even published as a public PWA link) until the
9 P0s are remediated. The path forward is one of:

1. **PWA-prototype-only mode**: gate the static files behind HTTP basic
   auth, mark all surfaces "DEMO ONLY", disable persistence-of-points,
   remove dev-helpers from prod bundle. Buys time but is not a real product.
2. **Native build cycle (Phase 6 per `CONNECTION_PLAN.md`)**: introduces
   Capacitor or RN shell, swaps in real backend calls, removes ALL client-side
   loyalty/promo/order logic. Mandatory before any App Store / Play Store
   submission. Minimum effort estimate: 4-6 weeks engineer + 2 weeks
   compliance / Apple review iteration.

The single highest-ROI immediate action: **delete the `loyalty_idempotency`
key generator from client and require the Phase 6 backend to mint
server-side `Idempotency-Key` UUIDs**. That single change closes R-LOY-002
fully and partially mitigates R-PAY-001 + R-LOY-001 by forcing a network
round-trip.

---

## 500-WORD EXECUTIVE SUMMARY

The FoodKing mobile codebase under `mobile/` is a V0 prototype, not a
production app. There is no `package.json`, no React Native or Expo build,
no actual network calls — only HTML+JSX served statically in a browser
with `react@18.3.1` and `@babel/standalone` loaded from unpkg CDN. The
data layer (`mobile/data/menu.js`, `loyalty.js`, `orders.js`, `user.js`,
`dev-helpers.js`) is a localStorage-backed in-memory mock with comments
promising "Phase 6 will POST /api/v1/frontend/...". This framing inverts
the audit: every "client-side trust" finding is at MAX severity right now
because there is no backend re-verify path.

Nine ship-blocker (P0) findings dominate. Authentication is fully broken:
ANY four digits typed in the OTP screen (`screens-onboarding.jsx:245`)
authenticate the user as `user_id=12345` ("Ikyes B.") with a literal
mock token `'mock-v0-token'` written to localStorage (`index.html:175-178`).
The "Pay at counter" flow (`index.html:211`) clears the cart and shows a
QR receipt without ANY backend call — fake orders are mintable at will,
loyalty points are credited client-side, and the customer-facing QR
`LECAY-ORDER-####` collides probabilistically across 9000 possible IDs.
The Stripe path is the same — `onClick={() => go('confirm')}` advances
to confirmation without invoking Stripe (`screens-modals.jsx:103`,
hardcoded card 4242 4242 4242 4242 rendered as plain text).

Loyalty is fully forgeable. `LC.dev.*` is shipped to production
(`dev-helpers.js:10-14` explicitly notes "intentionally not behind DEBUG
flag") — `LC.dev.earnPoints(99999, 'manual_cashier')` from DevTools mints
infinite points. The "idempotency key" for redemptions
(`loyaltyRewardState.js:170`) is deterministic
`redeem-{user}-{reward}-{floor(now/10min)}` — boundary-crossing replay
is documented as a "known gap". Promo codes `WELCOME10` / `CAYENNE` are
hardcoded plaintext in JS (`screens-main.jsx:1346`); ANY accepted code
grants 10%, and the discount math `Math.round(subtotal * 10) / 100`
overshoots by half a cent on average.

Allergens are derived from category defaults rather than recipe inspection
(`menu.js:233-246`). Bols default to `[]` even though they ship with
lactose-bearing sauce fromagère; Big Cayenne lists `[gluten]` but
includes Cheddar + Œuf + Jambon. Composer wizard adding "Cheddar fondu"
to Frites does NOT augment the allergen badge. EU FIC 1169/2011 violation
risk + anaphylaxis liability + App Store removal (Guideline 5.1.1).

GDPR-wise, PII (phone, member number, loyalty balance, dietary
restrictions) sits unencrypted in `localStorage.lecayenne.*` with no
export API (Art. 15), no deletion API beyond partial cleanup
(`storage.js:35-51`), no consent audit trail, no data residency
declaration.

NO-GO ship verdict. Minimum 4-6 weeks engineer effort to swap the nine
P0 surfaces (auth, payment, loyalty mutations, loyalty idempotency, promo,
allergen recompute, PII encryption, dev-helpers gating, package.json
bootstrap) plus 2 weeks Apple/Play review iteration before this can be
considered a real customer mobile product.
