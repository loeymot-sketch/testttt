# Wave POSAPP — WB-R1-01 root-cause + heal

RUN=rush-sync-2026-05-13 / round-1 / wave-POSAPP
Branch: feature/mobile-app-le-cayenne-2026-05-10
Severity: P1 (37 unhandled-promise rejections at every POS V4 mount; setup test 00 timed out in rush-100 rounds 2/3/4)

## Root cause

Vue Router 4 `useLink()` throws `MATCHER_NOT_FOUND` when a `RouterLink :to="{ name: '<unknown>' }"` references a route name not in the router's matcher (vue-router/dist/vue-router.mjs:707 + 725). `useLink` wraps `router.resolve` inside a Vue 3 `reactive(...)` that re-runs whenever `currentRoute.value` changes — every effect tick produces a fresh thrown error, which Vue 3's effect runner converts into an unhandled-promise rejection. The recursive `Qe.fn → w → get value → Qe.fn → w → get value` chain in the rush-100 stack trace is exactly that pattern: each `PosV5Button` mounted with `as="router-link"` is a reactive effect, and four such buttons fire on every mount.

`resources/js/components/admin/pos/PosComponent.vue` references four route names in `:to="{ name: ... }"` RouterLink bindings:

- L101 `admin.pos-orders.tracker`
- L114 `admin.order-status-screen` (target="_blank")
- L860 `admin.pos-orders.list`
- L943 `admin.pos-orders.show` (params: { id })

`resources/js/components/DefaultComponent.vue:110` also reads `auth.login`.

All five names live in the **full router** (`resources/js/router/modules/posOrderRoutes.js` + `orderStatusScreenRoutes.js`) but pos-app.js ships a **slim router** with only POS V4 + a handful of compat redirects. The five names above were missing → MATCHER_NOT_FOUND → 37 rejections (4 buttons × ~8-9 reactive ticks during cart/category hydration).

Why it amplified through rounds 2/3/4: the rejection cascade pumped the microtask queue, which delayed `PosComponent`'s `mounted()` hooks past Playwright's 15 s setup timeout. The page eventually rendered, but past the gate.

## Heal applied

`resources/js/pos-app.js` — added 5 stub routes (name + path + `beforeEnter` that forces `window.location.assign` so the legacy app.js bundle takes over for the actual screen). RouterLink now resolves the href correctly for both in-tab and `target="_blank"` clicks. Tagged `[rush-sync WB-R1-01 heal 2026-05-13]`.

## Verification

`tests/e2e/rush-sync-wave-POSAPP-verify.spec.js` — Playwright captures `/admin/pos-v4` console after login+navigation+2 s flush window. Result:

- **pageErrors=0** (was 37)
- **rejections=0** (was 37)
- 3 residual console messages, all Pusher/Echo WebSocket connection-refused to :6001 (unrelated; no soketi running locally)

Artifacts: `reports/test-e2e/rush-sync-2026-05-13/round-1/wave-POSAPP-console.json` + screenshot.

## Frozen-zone status

Clean. `public/js/pos-wizard.js` untouched. The heal lives in `resources/js/pos-app.js` (the slim entry point, NOT in the frozen POS wizard).

## Follow-up notes

- The full app router defines `admin.pos-orders.list` at path `/admin/pos-orders` (parent redirects there). Pos-app's stub uses `/admin/pos-orders` — matches.
- `admin.pos-orders.show` uses `/admin/pos-orders/show/:id` — the legacy posOrderRoutes.js declares it as a child of `/admin/pos-orders` with relative `show/:id`, so absolute path is `/admin/pos-orders/show/:id`. Matches.
- No other slim entry point (kiosk, admin-kds, admin-oss) shows this pattern; only pos-app.js was at risk because POS V4 is the only surface served from a non-full bundle while still rendering shared components that reference admin routes.
