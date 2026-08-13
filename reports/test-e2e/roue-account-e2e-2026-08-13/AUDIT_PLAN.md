# AUDIT PLAN — roue-account-e2e-2026-08-13

Plan-only document. No specs written, no captures taken here. Produced for the GStack main
team + adversarial supervisor about to run `test-e2e` on the convergence of
`plans/GOAL_ROUE_UX_IDENTITE_2026-08-13.md` (4 commits: QR+logo overlay, device-memory login,
prize carousel, redirect logic).

## Pre-flight (verified, do first)

- Laravel dev server up on `http://127.0.0.1:8000` (backend repo, branch
  `pos/category-first-caisse-2026-06-23`). Admin creds `admin@lecayenne.fr` /
  `TestVisuel2026!`. Wheel PIN `481526`, session cookie `wheel_unlocked_at` (4h TTL,
  `EnsureWheelAccess` middleware) — open the door ONCE per browser context and reuse it
  (`wheel-pin` route is rate-limited 5/min/IP — see `tests/e2e/roue-2026-08-13.spec.js`
  `ouvrirLaPorte()` pattern; do not re-enter the PIN per test).
- Static site repo `"/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne"`
  (branch `codex/cayenne-home-product-visual-max`) must be served locally BEFORE Wave B/C run:
  `python3 -m http.server 8899 --bind 127.0.0.1 &` (canonical port per
  `tests-e2e/README-tests.md`; `roue-lots-bandeau-2026-08-13.spec.js` defaults to 8900 via
  `LC_BASE` override — standardize on **8899** for this audit unless already occupied, and
  check for port conflicts first: `lsof -i :8899`).
- Playwright workers=1 for any spec sharing a rate-limited resource (wheel PIN, OTP send).
  Orphan dev-server/python processes cleaned before starting.
- `tests/e2e/helpers/login.js`, `mega-audit-snap.js` (4-file artifact quartet), `rate-limit.js`
  (`clearFoodKingRateLimits`) available Laravel-side. Static site has NO equivalent helpers —
  follow the plain-`require('playwright')` pattern already used in `tests-e2e/*.spec.js`
  (see `roue-2026-08-09.regression.js`, `roue-lots-bandeau-2026-08-13.spec.js`,
  `account-retour-connu-2026-08-13.spec.js`) — do NOT import `@playwright/test` fixtures there.
- **Existing coverage already in place, do not duplicate — extend instead**:
  `tests/e2e/roue-2026-08-13.spec.js` (Laravel `@playwright/test`, borne/validation/reglages/
  historique/lot layout, QR bounding-box ≥120px, no duplicate lot list, PIN gate, phone-number
  leak check) covers general admin wheel screen layout but **not** the new `.qr-logo` overlay,
  **not** QR decodability, **not** `/admin/roue-validation` QR specifically beyond the shared
  layout checks. `roue-lots-bandeau-2026-08-13.spec.js` and `account-retour-connu-2026-08-13.spec.js`
  already cover the carousel DOM/ARIA and the 3-state account modal via mocked/seeded storage.

---

## Wave A — Admin tablet + staff QR screens (Laravel, `@playwright/test`)

**Spec**: `tests/e2e/roue-qr-logo-2026-08-13.spec.js` (new, extends `roue-2026-08-13.spec.js`
patterns — share `ouvrirLaPorte()`/serial-per-context idiom, do not re-open the PIN gate).

**Surfaces**: `/admin/roue-borne` (kiosk, `kiosk()`), `/admin/roue-validation`
(`show()`/`issue()` POST).

**Visual states to capture** (mega-audit-snap quartet where the Laravel helper applies, plain
`page.screenshot` otherwise):
1. `/admin/roue-borne` landscape (1180×820) and portrait (820×1180) — QR + `.qr-logo` overlay
   visible, centered, white circular backing, no overlap with `.gauche/.droite/.scene/.actes`.
2. `/admin/roue-validation` after PIN — QR + `.qr-logo` overlay, plain-text URL under the QR
   (`issue()` returns `url` to the view) legible and matching the QR payload.
3. `/admin/roue-validation` POST `issue()` twice in a row (staff taps "generate" again) — new
   token, new QR, old token no longer valid (`unlock_token_ttl_minutes`), logo overlay persists
   correctly on the regenerated SVG (no stale DOM node reused with wrong `src`).

**Assertions**:
- `.qr-logo` `<img>` present, `alt=""` (decorative, per GOAL §Sub1.1), positioned
  `absolute; translate(-50%,-50%)`, computed size 15-22% of the QR container width (GOAL's
  validated range), white circular backdrop behind it with visible contrast.
- QR SVG `errorCorrection` observable effect: module/path count comparable to what
  `WheelQrLogoTest.php`/`WheelQrGenerationTest.php` (backend, already asserted per GOAL §Sub1.1
  convergence note) expects — do not re-derive from scratch, just confirm the rendered SVG in
  the DOM isn't stale/cached from before the H/margin-2 change (hard-refresh, bypass cache).
- **Real scannability from the browser-rendered artifact** (not the raw PHP SVG string): screenshot
  the `.qr-boite`/`.qr` container, crop, and decode with a QR reader available in this
  environment (reuse whatever the backend convergence note used —
  `khanamiryan/qrcode-detector-decoder`, GD mode, already proven working per GOAL §Sub1.1 "PREUVE
  DE SCANNABILITÉ RÉELLE"). Assert the decoded payload matches `wheel.public_url` + `/roue.html?t=`
  + a non-empty token (and `&preview=` when `wheel.enabled=false`).
- No horizontal/vertical overflow (`scrollHeight`/`clientHeight` diff ≤1px) on any container that
  now hosts the overlay — this is exactly the class of defect `roue-2026-08-13.spec.js` already
  caught once (CSS comment breaking a rule); the overlay is a second chance for the same failure
  mode on a DIFFERENT rule.
- Console/network clean (no 404 on `public/images/wheel/logo-mark.png`, no CORS/mixed-content
  warnings).

**Acceptance**: all captures Read+analyzed, 0 overflow, QR decodes on both screens in both
orientations, 0 console error, `roue-2026-08-13.spec.js` (existing) still green (non-regression
run, not just assumed).

---

## Wave B — Customer `roue.html` (static site, plain Playwright, multi-viewport)

**Spec**: `tests-e2e/roue-fond-carrousel-redirection-2026-08-13.spec.js` (new — this wave
subsumes and extends `roue-lots-bandeau-2026-08-13.spec.js`'s scope to also cover T-1.2.1
background enrichment and T-1.3.1/1.3.2 redirect logic, which that existing spec does not
touch; keep the existing spec running standalone as well, don't merge files).

**Surfaces**: `roue.html?t=<mock>&preview=<key>` served at `127.0.0.1:8899`, mocked
`/api/frontend/wheel/config` response (same interception pattern as the existing bandeau spec —
no live backend dependency for this wave).

**Viewports** (GOAL explicitly requires these, mobile-first — this is scanned on a phone):
375×667 (iPhone SE, counter-use), 375×812 (primary scan format), 768×1024 landscape
(tablet-scan, per GOAL §Sub1.2 acceptance).

**Visual states to capture**:
1. Idle/pre-play screen at all 3 viewports — background enrichment (braises animation intact +
   any new ambient asset) visible, canvas wheel + medallions intact, carousel band positioned
   AFTER the CTA block (`#ecran0..3`) and BEFORE `.msg`, per GOAL's corrected placement — CTA
   still reachable without scroll on 375×667 specifically (the tightest budget case called out
   in the GOAL).
2. Carousel band scrolled mid-way (manual scroll only — assert no `scroll-behavior:smooth` /
   auto-advance under `prefers-reduced-motion: reduce`, and separately assert it does NOT
   auto-advance even with reduced-motion off — GOAL says "jamais d'auto-avance" unconditionally).
3. Post-win reveal screen (`revelerLot()`) for all 4 `prize_type` values
   (`free_item`, `points`, `coupon_percent`, `coupon_fixed`) via `?preview=` — CTA target URL
   differentiated per GOAL §Sub1.3 (menu for free_item/points, cart-with-code for coupons if
   `commander.html` supports `?code=` — confirm G3 resolution status before asserting a specific
   URL shape; if G3 was resolved "keep simple redirect", assert that instead of a fictional
   `?code=` param).
4. Transition/"merci" micro-page state if T-1.3.2 was implemented (optional per GOAL — check
   git log for the actual decision before writing an assertion that assumes it shipped).

**Assertions**:
- Carousel: one thumbnail per active segment, `role="list"`/`role="listitem"`, non-empty `alt`
  per thumbnail, `overflow-x` scrollable, reuses `segments[pi].photo` (no second network call —
  assert via `page.route` call-count on `/wheel/config`, must be exactly 1).
- Zero overlap (measured, not eyeballed) between ambient background asset and canvas/CTA —
  `pointer-events:none` on the ambient layer confirmed by attempting a click through it.
- Contrast AA on carousel text-over-photo (spot-check 2-3 thumbnails, not exhaustive).
- Redirect CTA `href`/`onclick` target matches the resolved prize-type mapping.
- `roue-2026-08-09.regression.js` (pre-existing, PIN-gated Laravel-side flow) and
  `roue-lots-bandeau-2026-08-13.spec.js` both still green — run them, don't assume.

**Acceptance**: 0 overflow at all 3 viewports, carousel present+correct, 4 prize-type CTAs
verified, both pre-existing specs still green, console clean.

---

## Wave C — Account modal 3-state cross-check (static site, `index.html` → account-v2)

**Spec**: extend `tests-e2e/account-retour-connu-2026-08-13.spec.js` coverage with a
side-by-side comparator spec, `tests-e2e/account-trois-etats-2026-08-13.spec.js` (new) —
the existing spec already covers state (c) `login-connu` well; this wave's job is the
**cross-state diff** the existing spec doesn't do.

**States** (GOAL §2 confirmed distinct):
(a) first-visit signup (`mode='signup'`, no localStorage) — full form.
(b) classic login, unknown device (`mode='login'`, user clicks "J'ai un compte" with no
    `PHONE_KEY`/`lc_known_first`) — full form, reworded subtitle (T-2.3.1, text-only change).
(c) `login-connu`, known device (`PHONE_KEY` + `lc_known_first` + valid TTL) — single email
    field, first-name + last-2-digits-of-phone greeting, "Ce n'est pas moi" escape hatch.

**Visual states to capture**: all 3 side by side at 390×844 (existing spec's viewport).

**Assertions**:
- (a) vs (b): field sets IDENTICAL (first/last/email/phone all visible) — this is the
  documented, intentional non-difference (GOAL §2.2, "volontairement un NON-CHANGEMENT"). Only
  the subtitle text differs. Assert the DOM diff is text-only, not structural — a structural
  diff here would be a regression the GOAL explicitly forbids.
- (b) vs (c): (c) shows exactly ONE input (email); phone/first/last inputs absent from the DOM
  (not just hidden — GOAL doesn't specify but a hidden-not-removed field is a residual-state
  risk worth flagging if found).
- (c) "Ce n'est pas moi" clears `lc_known_first`/`lc_known_last`/`lc_known_first_at` AND calls
  `api.setPhone(null)` (assert via `PHONE_KEY` removal, not just UI state) → falls back to (b)
  exactly, not a 4th hybrid state.
- TTL edge: `lc_known_first_at` at exactly 90 days minus 1s (valid) vs plus 1s (expired) —
  boundary case the existing spec may only test with an obviously-expired timestamp; add the
  boundary if missing.
- `lc:auth-changed` event coupling (T-2.1.2): simulate a 401/logout event and confirm
  `lc_known_first` is purged in the SAME tick as `PHONE_KEY` (no window where one is stale and
  the other isn't).
- Regression: `account-email-otp-2026-07-28.spec.js` (pre-existing, covers first-visit OTP
  flow) still green — this spec has documented stale-selector/mail-config issues (see below);
  run it and record its actual current status rather than assuming pass or fail.

**Acceptance**: 3-state DOM diff matches the GOAL's intended structural boundaries exactly, TTL
boundary correct, purge-on-logout atomic, both pre-existing specs' current status recorded with
evidence.

---

## Wave D — Cross-cutting sweep (a11y / console / i18n / cross-surface QR↔roue.html)

**Spec**: `tests-e2e/roue-account-cross-cutting-2026-08-13.spec.js` (new, or split
Laravel-side + web-side halves if cleaner — see below).

**1. Cross-surface QR→roue.html round-trip** (the one genuine end-to-end scenario across both
repos): `WheelCounterController::issue()`/`kiosk()` build the QR payload as
`config('wheel.public_url') . '/roue.html?t=' . urlencode($token)` (+ `&preview=` when
`wheel.enabled=false`). `roue.html` reads its API base from `<meta name="api-base-url">`
(empty by default = relative fetch) and reads `t`/`preview` from `location.search`
(`roue.html:545`). Two options depending on what's feasible locally:
   - **Preferred**: extract the real `token` from `/admin/roue-validation`'s plain-text `url`
     value (Laravel side), then open `http://127.0.0.1:8899/roue.html?t=<realtoken>&preview=<key>`
     (static side) with the `<meta api-base-url>` overridden (via `page.addInitScript` or a
     locally-served copy of `roue.html` with the meta tag pointed at `http://127.0.0.1:8000`) and
     confirm `/api/frontend/wheel/config?...&t=<realtoken>` returns 200 and the wheel actually
     starts (not the mocked-config path used in Waves B/C).
   - **Fallback if CORS/domain wiring blocks a genuine cross-origin local call**: a token-shape
     round-trip check only (regex format, URL structure, `urlencode` correctness) plus an
     explicit note in the report that live cross-origin validation was not exercised and why.
   This is the only scenario in this audit that proves the QR a staff member scans in Wave A
   actually degrades gracefully into a working Wave B session — everything else tests each side
   in isolation with mocks.

**2. A11y sweep**: `.qr-logo` alt="" correctly decorative (Wave A), carousel `role="list"`
ARIA (Wave B), account modal focus trap + labelled inputs across all 3 states (Wave C),
keyboard-only run through: open roue-borne → tab order sane; open account modal in
`login-connu` state → tab lands on email field, not a hidden phone field.

**3. i18n/label sweep**: grep-and-visually-confirm no raw label leaks (`Label.X`,
`wheel.foo`, `0undefined`) on any of the 3 waves' new elements (`.qr-logo`, carousel
thumbnails' `alt`, `login-connu` greeting string interpolation with `{first}`).

**4. Pre-existing documented defects — assess, don't silently skip**:
   - `tests-e2e/roue-2026-08-09.regression.js` "3 steps" failure (documented pre-existing,
     mentioned in mission brief) — re-run, confirm it's still the same failure mode, and have
     the team make an explicit call: cheap+safe fix now, or log as out-of-scope with a one-line
     reason. Do not silently leave it un-triaged.
   - `account-email-otp-2026-07-28.spec.js` stale-selector/mail-config issues — same treatment:
     re-run, triage, explicit in/out-of-scope call with reason.
   Neither is to be "fixed" as a side-effect of Wave C without this explicit triage step first —
   the mission brief flags both as pre-existing and wants an assessed decision, not a reflexive
   patch.

**Acceptance**: cross-surface QR round-trip proven live OR explicitly documented as
infeasible-with-reason, 0 a11y regressions on new elements, 0 raw-label leaks, both pre-existing
defects triaged with an explicit written decision (fix-now / explicitly-out-of-scope + why).

---

## Out of scope (explicit)

- G1 (child/family background asset) — no asset exists, intentionally left undone per GOAL;
  do not flag as a new finding, do not invent/source a replacement asset.
- G2 (merge to `main` / VPS+Vercel deploy) — this is a local audit only, not a deploy
  readiness check. Do not assert on production URLs.
- G3 CTA-with-code — only assert whatever was ACTUALLY decided/shipped (check git log/diff for
  the real resolution before writing the assertion); do not assume `?code=` support was added
  if `commander.html` doesn't have it.
- Backend phone-based identity contract (`GuestSignupController::verify`, `User::where('phone',…)`)
  — explicitly NOT renegotiated by the GOAL; Wave C tests the CLIENT-side memory layer only, not
  a request to add email-only server auth.
- Any frozen-zone file (§7 CLAUDE.md) — none of the 4 commits touch one per the GOAL's own
  anchor check; if any wave's audit surfaces a need to touch one, STOP and escalate rather than
  patch.
- Load/performance testing, cross-browser matrix beyond Chromium (mega-audit-snap default),
  real SMS/email delivery (OTP specs already mock/stub this per existing pattern).
- WheelQrGenerationTest.php / WheelQrScannabilityTest.php / WheelQrLogoTest.php internals —
  these are PHPUnit, already green per GOAL's Sub 1.1 convergence note; Wave A only re-verifies
  the BROWSER-RENDERED artifact, not the PHP unit-level assertions again.

## Risk flagged for the executing team

Biggest risk: Wave D's cross-surface QR round-trip is the only scenario that actually proves
staff-tablet-QR → customer-phone-scan works end-to-end: everything else in Waves A-C tests each
side against mocks/fixtures. If local CORS/domain wiring makes the live call infeasible, say so
explicitly rather than silently falling back to the token-shape check without flagging it as a
coverage gap in the final report.
