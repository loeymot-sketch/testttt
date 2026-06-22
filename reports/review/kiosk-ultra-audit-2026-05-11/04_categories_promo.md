# K04 — Categories + Promo Carousel

> Audit read-only, HEAD `6a33a9763` (branch `feature/mobile-app-le-cayenne-2026-05-10`).
> Stale memory in `00_ULTRA_PLAN.md` line 4 mentions HEAD `245e8ab57` — confirmed
> obsolete via `git rev-parse HEAD`.

## Files audited

- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` — 1589 lines
  (template + script + scoped style + Bold Appétissant override block).
- `resources/js/components/frontend/kiosk/KioskPromoCarouselComponent.vue` — 183 lines.
- `resources/js/helpers/kioskCategoryOrder.js` — 76 lines.
- `resources/js/store/modules/kioskFilter.js` — 86 lines.
- `tests/js/kioskCategoriesTopChips.spec.js` — 115 lines.
- `tests/js/kioskCategoryOrder.spec.js` — 41 lines.
- `tests/js/kioskFilterPersist.spec.js` — 166 lines.

Cross-referenced (read but not in scope):
- `app/Services/Kiosk/KioskMenuService.php` (server payload `promos[]`).
- `app/Http/Controllers/Frontend/MenuController.php` (kiosk endpoint).
- `resources/js/store/modules/kioskMenu.js` (`SET_PROMOS`, `kioskPromos` getter).
- `resources/js/helpers/kioskFilters.js` (`KIOSK_FILTERS`,
  `isVariationAllowedByFilters`).
- `resources/js/store/modules/kioskCart.js` (uses `kiosk.promo.error.*` strings).
- `resources/js/languages/fr.json|en.json|ar.json`.

## Findings

### P0 (blocker pre-merge V1)

- **K04-P0-01: `kioskFilter` Vuex module hydrated but never consumed on
  `KioskCategoriesComponent`.**
  - File: `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:343-396`
    (computed) vs `resources/js/store/modules/kioskFilter.js:21-46`.
  - Issue: `kiosk.catalog.filters_label` + `kiosk.catalog.filters_reset` keys exist
    in `fr/en/ar.json` and the persistence layer (`kioskFilter.toggle`,
    `localStorage 'kiosk:filters'`) is fully implemented, but the
    `KioskCategoriesComponent` template has zero filter chip UI. `KIOSK_FILTERS`
    is consumed only inside wizard steps (`KioskStepGarnitures/Sauce/Supplements/
    Viande`). The promise of the dashboard-level "filter persistence across
    navigations" tested by `kioskFilterPersist.spec.js:96-147` is therefore
    untestable through the actual user UI from the category surface — the test
    works on the store guard, but a real user has no way to flip filters from
    the catalog.
  - Evidence: `grep -rn "kioskFilter/activeFilters" resources/js` returns zero
    hits in `KioskCategoriesComponent.vue`. The component imports nothing from
    `kioskFilter`. No `<KsFilterChips>` or analogous DS chip is mounted.
  - Suggested fix: either (a) wire a filter row inside
    `kiosk-catalogue-top-actions` (line 35) — toggling
    `kioskFilter/toggle` and applying `applyKioskFilters` over
    `catalogProducts` — or (b) demote the unused store module to backlog and
    drop the persistence promise from the audit plan and the route guard test.
    Current state is "Potemkin persistence" — works at store level, invisible
    to user.

- **K04-P0-02: Promo marquee uses fixed `translateX(-33%)`, but the carousel
  ships exactly the server-returned promos count (no triplication).**
  - File: `resources/js/components/frontend/kiosk/KioskPromoCarouselComponent.vue:114-117`.
  - Issue: `@keyframes kiosk-promo-marquee { from { translateX(0) } to {
    translateX(-33%) } }` is correct only for an _odd-tripled_ list, i.e. when
    the same array is rendered three times so that `-33%` lands on a clean
    repeat. The template renders the promos `<article v-for="promo in
    promos">` ONCE. With 1 promo the animation is suppressed (`length > 1`
    guard line 11), but with 2, 4, 5, … promos the marquee scrolls off-screen
    and never wraps cleanly — the last card slides into the void instead of
    cycling. With 3 promos it happens to look acceptable, which is probably
    why no one caught it.
  - Evidence: No duplicated `<article>` block, no JS-driven cycling, single
    pass `for promo in promos` line 14-37. Animation hard-coded -33%.
  - Suggested fix: either (a) wrap the inner list in `[...promos, ...promos,
    ...promos]` and keep -33%, (b) compute `translateX(-${100 *
    (promos.length - 1) / promos.length}%)` via inline style, or (c) replace
    marquee with an interval-driven `transform: translateX(-${idx *
    cardWidth}px)` Vue ref. Without a fix, customers with !=3 promos see
    visual glitch + content that disappears.

### P1 (high — V1.0.1 sprint candidate)

- **K04-P1-01: No `role="tablist"` / `role="tab"` / `aria-selected` on the
  category sidebar.**
  - File: `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:94-124`.
  - Issue: The sidebar is a vertical list of buttons providing the primary
    navigation between menus, but it ships `role="navigation"` (line 97) with
    plain `<button>` elements relying on `aria-current="page"` (line 105).
    WAI-ARIA Authoring Practices recommends `role=tablist` for a one-of-many
    selector that swaps a content pane (the `.kiosk-product-zone` to the
    right). Screen readers won't announce "1 of N selected" — they'll
    announce "current page" which is wrong (it's the same SPA route).
  - Evidence: lines 95-99 use `aria-label` + `role="navigation"`, no `tabindex
    -1/0` roving focus, no `@keydown.up/down/home/end` handler — confirmed
    by `grep "@keydown|ArrowUp|ArrowDown"` returning nothing.
  - Suggested fix: switch to `role="tablist" aria-orientation="vertical"`,
    each button `role="tab" :aria-selected="isCategoryActive(cat) ?
    'true':'false'" :tabindex="isCategoryActive(cat) ? 0 : -1"`, and the
    `.kiosk-product-zone` becomes `role="tabpanel" :aria-labelledby="`
    selected button id. Add Up/Down/Home/End handlers per APG pattern. Same
    fix unlocks RTL switch — `aria-orientation="vertical"` is unambiguous.

- **K04-P1-02: Promo carousel relies on FR-only fallback for 6 extra promo
  i18n keys that are exposed in the cart flow but missing from EN/AR.**
  - File: `resources/js/languages/fr.json:1223-1235` vs `en.json:1357-1363` and
    `ar.json:1284-1290`.
  - Issue: `kiosk.promo.applied|apply|label|loading|placeholder|remove` exist
    only in FR. Used by `KioskCartComponent.vue:270-314`. Per CLAUDE.md §2 the
    V1 is FR-locked (other locales fall back), but `setLocale('ar')` is
    callable through the kiosk a11y panel (`useKioskA11y.js`) — once a user
    switches to AR mid-session, those strings appear in FR while the rest of
    the cart is in AR (i18n parity drift). The promo carousel itself uses
    only the 5 keys present in all locales — but the catalog screen leads to
    the cart screen, so this is K04-adjacent.
  - Evidence: `node -e ...` diff confirms FR-only: `[ 'applied', 'apply',
    'label', 'loading', 'placeholder', 'remove' ]`. K07 (cart) will surface
    this independently — flagging here for the categories→cart user journey.
  - Suggested fix: add EN+AR translations for those 6 keys (V1 small, owner
    can sign off without owner-gate as this is additive).

- **K04-P1-03: `kiosk.promo.error.*` codes referenced in `kioskCart.js:559,
  584, 587` are not declared in any locale JSON.**
  - File: `resources/js/store/modules/kioskCart.js:559,584,587` vs
    `resources/js/languages/*.json`.
  - Issue: `commit('SET_PROMO_ERROR', 'kiosk.promo.error.empty')` produces a
    string that `KioskCartComponent.vue:302` renders via `$te(promoError) ?
    $t(promoError) : promoError`. Since `kiosk.promo.error.empty/invalid/
    network` do NOT exist in fr/en/ar.json (verified `grep
    "promo.error" resources/js/languages/` → empty), the raw label
    `kiosk.promo.error.empty` is displayed to the user. CLAUDE.md §11 Visual
    Test Mandate flags this exact failure pattern.
  - Evidence: `grep -rn "promo.error" resources/js/languages/` → no results.
  - Suggested fix: add the three keys to fr/en/ar.json under
    `kiosk.promo.error`. Trivial.

- **K04-P1-04: `KioskPromoCarousel` has no dedicated test coverage.**
  - File: `tests/js/` — `KioskPromoCarousel` appears only as a stub in
    `kioskOrderTypeExplicit.spec.js:153`. No unit spec for the carousel
    itself.
  - Issue: critical UI (8.5 server-driven, NF525-adjacent because promos
    affect customer-visible cart line items) ships untested for: marquee
    on/off via `reducedMotion`, `formatPromoValue` percent/amount rendering,
    aria-label fallback when i18n is missing, empty `promos[]` render-nothing
    contract.
  - Evidence: `grep -rn "KioskPromoCarousel\|kioskMenu/kioskPromos" tests/` →
    1 hit, stub only.
  - Suggested fix: add `tests/js/kioskPromoCarousel.spec.js` covering at least
    T-K04-04 (proposed below).

- **K04-P1-05: `getProductBadge(product)` returns localized labels but
  `is_featured == 5` is checked with loose equality and no schema doc.**
  - File: `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:717-722`.
  - Issue: `if (product.is_featured == 5)` uses `==` and a magic number `5`
    (likely `status=5` "published" semantics borrowed from
    `status` field). Backend `KioskMenuService.projectItems` (verified) does
    not expose `is_featured` explicitly — this is reading a passthrough field
    that might be string "5" or int 5 depending on caster. Risk:
    label `kiosk.catalog.badge_new` either always or never appears across
    deploys.
  - Evidence: line 719 `if (product.is_featured == 5)`. No type guard. Backend
    field origin: not documented in `projectItems`.
  - Suggested fix: replace with explicit boolean field (`product.is_new`
    already exists per `productBadges` line 729) and remove the `is_featured
    == 5` heuristic. Strict equality after `parseInt` if kept.

- **K04-P1-06: Sidebar shows admin `name` even after `displayCategoryName`
  strips "Nos " prefix — but `selectedSidebarRow` returns the
  child sandwich-cold row, leaking the "Sandwich froid" raw label to the
  breadcrumb in a non-French locale.**
  - File: `KioskCategoriesComponent.vue:357-381` (`selectedCategory`,
    `selectedCategoryDisplayName`) + helper config
    `window.foodkingConfig.kioskSandwichSplit.cold_sidebar_label || 'Sandwich
    froid'` (see `kioskMenu.js:69-94`).
  - Issue: the `cold_sidebar_label` is admin-config'd literal FR — when locale
    flips to AR, the breadcrumb still reads "SANDWICH FROID" (uppercase via
    `text-transform`). This is a hidden FR-lock contract that V1 inherits.
  - Evidence: `expandKioskSidebarCategories` returns rows with `name:
    coldLabel` — fed straight to `displayCategoryName(cat)` line 705.
  - Suggested fix: when generating the cold row in
    `kioskSandwichSplit.js::expandKioskSidebarCategories`, use an i18n key
    instead of a raw config string. Out of K04 scope (helper), flagged for
    K14.

### P2 (medium — backlog)

- **K04-P2-01: `cat-pane` transition `:key` includes `kioskSandwichSubcolumn`
  but no FLIP optimization, causing the entire grid to remount on cold→hot
  toggle.**
  - File: `KioskCategoriesComponent.vue:132-222`.
  - Issue: `<transition name="kiosk-cat-pane" mode="out-in">` with `:key=
    "${selectedCategoryId}-${kioskSandwichSubcolumn || 'sig'}"` forces
    teardown+remount of the entire product grid on every category click.
    On large menus (~80 items), this is observable (image re-decode flash).
    No `<TransitionGroup>` with FLIP, no key-by-item-id caching.
  - Suggested fix: keep a single `<TransitionGroup>` keyed by `product.id`,
    apply fade-in only to entering items, accept the trade-off that header
    + title change without full transition. Defer to P9.x performance pass.

- **K04-P2-02: Marquee animation runs at fixed 26s regardless of promos
  count.**
  - File: `KioskPromoCarouselComponent.vue:111-112`.
  - Issue: 26s for 3 promos = ~8.7s per promo (fine), but for 6 promos it's
    4.3s per promo (too fast to read) and for 1 promo it's suppressed
    anyway. WCAG 2.2.2 Pause/Stop/Hide is satisfied (auto-stops on reduced-
    motion / no controls exposed = pass on continuous criterion only because
    no "moving content" carries information beyond promo).
  - Suggested fix: dynamic duration `animation-duration: ${promos.length *
    9}s`, expose a pause-on-hover via CSS.

- **K04-P2-03: `formatPromoValue` for `percent` always uses `toFixed(0)` —
  drops fractional percent promos (e.g., 12.5%).**
  - File: `KioskPromoCarouselComponent.vue:79-87`.
  - Issue: `Number.isInteger(n) ? n : n.toFixed(0)` — if marketing creates a
    12.5% promo, customer sees "-13%" with no `min_cart` mention of
    fraction. Owner backend allows `value:float` per `KioskMenuService:481`.
  - Suggested fix: `n.toFixed(n % 1 === 0 ? 0 : 1)` or accept fractional with
    `Math.round(n*10)/10`.

- **K04-P2-04: `image_full_path` shows cover, but uses `loading="lazy"`
  inside both 64px brand thumbnail and 234px product image — the brand
  thumbnail is above-the-fold and should be eager.**
  - File: `KioskCategoriesComponent.vue:111-122` (sidebar thumb, OK lazy),
    `KioskCategoriesComponent.vue:6-12` (brand-thumb has NO `loading=`
    attribute at all). Inconsistent.
  - Suggested fix: explicit `fetchpriority="high"` on the brand thumb to
    avoid layout shift on initial paint, keep `lazy` on product cards
    > 4 below the fold via intersection observer.

- **K04-P2-05: Custom scrollbar styling (`scrollbar-color: rgba(255,255,255
  ,0.24)` line 968) is white-on-anything, broken under light Bold
  Appétissant theme.**
  - File: `KioskCategoriesComponent.vue:967-976`.
  - Issue: light theme uses `--kiosk-bold-bg: #FFF8F1` — a 0.24 alpha
    white thumb on a near-white track is invisible. Sidebar scrollability
    cue is lost. Dark-only color hardcoded.
  - Suggested fix: use a theme token `--kiosk-scrollbar-thumb` resolved
    light/dark.

### P3 (low — nice-to-have)

- **K04-P3-01: `kiosk-promo-card__amount` span has no `aria-label`; the
  visible `-30%` is read as "minus thirty percent" by VoiceOver but with no
  context.**
  - File: `KioskPromoCarouselComponent.vue:23-24`.
  - Suggested fix: combine into one `aria-label="Offer 30% off, code SPRING,
    minimum cart 15€"` on `<article>` itself.

- **K04-P3-02: Promo `min_cart` rendering uses `formatPrice` but with no
  unit hint in the AR string `{value}` (LTR formatPrice output dropped into
  RTL run).**
  - File: `KioskPromoCarouselComponent.vue:27` + `fr.json:1228` "À partir de
    {value}" / `ar.json:1289` "بدءًا من {value}".
  - Suggested fix: ensure formatPrice respects RTL with
    ` ` non-breaking space or bidi marker.

- **K04-P3-03: Category sort uses `localeCompare(... 'fr', { sensitivity:
  'base' })` regardless of active locale — Arabic categories would sort
  via French collation.**
  - File: `kioskCategoryOrder.js:73`.
  - Suggested fix: pass `i18n.global.locale.value` as `'fr'|'en'|'ar'` first
    arg.

- **K04-P3-04: `sortCategoriesForKioskDisplay` ignores `parent_id` — child
  categories appear interleaved with parents in the sidebar, only saved by
  `expandKioskSidebarCategories` which depends on
  `window.foodkingConfig.kioskSandwichSplit` config (silent fallback if
  missing).**
  - File: `kioskCategoryOrder.js:57-75` + `kioskMenu.js:69-94`.
  - Suggested fix: stable ordering should be tier → parent_id → sort → name.

## Existing E2E coverage

- `tests/js/kioskCategoriesTopChips.spec.js` — covers `Mon compte` chip wiring
  to `kiosk.loyalty` route, `Allergènes` chip removal, robustness against
  router push error. Does NOT cover: any filter chip, any catalog grid
  interaction, no promo carousel mount.
- `tests/js/kioskCategoryOrder.spec.js` — tier ordering for desserts/drinks
  vs mains, tie-break by `sort`, classification of `Sandwich froid`. Does
  NOT cover: parent_id grouping, locale-aware collation, missing `slug`
  fallback.
- `tests/js/kioskFilterPersist.spec.js` — exhaustive on the store (init, toggle,
  reset, corrupted localStorage, private-mode throw, route-guard mount order,
  customer allergens roundtrip). Does NOT cover: the user-visible filter
  toggle (because there is none, see K04-P0-01).
- `tests/js/kioskRtl.spec.js` (read for context) — confirms `<html dir>` flips
  correctly on AR; does not exercise `KioskCategoriesComponent.vue` RTL
  layout.

Indirect coverage:
- `tests/js/kioskOrderTypeExplicit.spec.js:153` stubs
  `KioskPromoCarouselComponent` (no behavioural assertion).
- No `kioskCategoriesGrid.spec.js`, no `kioskPromoCarousel.spec.js`.

## Proposed new E2E tests

- **T-K04-01: Filter chips actually toggle catalog visibility (P0 gap repro).**
  - Steps: Vitest mount `KioskCategoriesComponent` with 3 items
    `is_vegetarian=[true,false,true]`, dispatch `kioskFilter/toggle vegetarian`,
    expect `productZone` to render only the 2 vegetarian items (assert
    `data-testid="kiosk-product-card-*"` count, and `kiosk-categories-zone-count`
    label).
  - Assertions: `filtered_count === 2`; card filtered-out has
    `aria-disabled="true"` OR is absent; no console error.
  - Note: this test **will fail** at HEAD until K04-P0-01 is fixed. Useful
    as a TDD pin.

- **T-K04-02: Sidebar tablist semantics for screen readers.**
  - Steps: Vitest mount, assert `aside[role="tablist"]`, each button has
    `role="tab"`, `aria-selected`, `tabindex` roving; press ArrowDown ->
    next category becomes selected; press End -> last; Home -> first.
  - Assertions: 1 single `tabindex=0` at a time, axe-core scan finds 0
    "ARIA role tablist required" violations.

- **T-K04-03: Promo marquee duration scales with promos count.**
  - Steps: mount `KioskPromoCarouselComponent` with N=2, N=3, N=5 promos;
    inspect computed `animation-duration` (or inline-style) and the final
    `transform` keyframe.
  - Assertions: at promos.length>=2, `--animated` class applied; rendered
    cards = N (single pass) AND either (a) inner block triplicated or (b)
    `translateX(...)` end-state computed from `N`.

- **T-K04-04: `KioskPromoCarouselComponent` dedicated mount + formatPromoValue.**
  - Steps: Vitest mount with `kioskMenu` store stub returning
    `[{id:1,type:'percent',value:30,code:'A',min_cart:null},{id:2,type:'amount',
    value:5,code:'B',min_cart:15}]`. Snapshot card 1 amount span="-30%", card 2
    amount span="-5,00 €" + min_cart="À partir de 15,00 €". Toggle
    `kioskSettings/reducedMotion=true` → `kiosk-promo-track--animated`
    removed.
  - Assertions: data-testids `kiosk-promo-card-1`, `kiosk-promo-card-2`
    present; aria-label resolves to "Offres en cours" (FR) and "العروض
    الحالية" (AR after setLocale).

- **T-K04-05: i18n raw-label sentinel for promo error path.**
  - Steps: dispatch `kioskCart/validatePromo('')` → expect store `promoError
    === 'kiosk.promo.error.empty'`; mount `KioskCartComponent`, assert the
    visible text is **not** the literal `kiosk.promo.error.empty` (i.e. the
    i18n key is resolved through fr/en/ar.json).
  - Assertions: rendered text in FR matches a French-language translation;
    `$te('kiosk.promo.error.empty')` returns true for all 3 locales.
  - Note: **will fail** at HEAD per K04-P1-03; useful TDD pin.

## Risks & open questions

- **OWNER GATE (FR-lock V1 scope):** K04-P1-02 and K04-P1-03 add EN+AR keys.
  Memory `feedback_v1_focus_no_saas_2026-05-08` says V1 is FR-only — but
  `useKioskA11y.js` already allows AR runtime switch (`kioskRtl.spec.js`
  expects this). Owner must confirm whether the runtime AR switch is in
  V1 scope; if not, both keys can be downgraded to P2.

- **K04-P0-02 (marquee math)** is a visual defect that I have not seen on a
  live screen — flagged from code reading only. Recommend Playwright capture
  at N=2 and N=5 promos to confirm whether the gap is cosmetic ("last card
  visible then snaps back") or material ("blank stretch + late return").
  NEEDS INVESTIGATION via Playwright before pulling P0 trigger; downgrade to
  P1 if visual is acceptable on Le Cayenne's current 3-promo config.

- **K04-P0-01 (Potemkin filter persistence)** raises a design question — is
  the planned customer-facing filter chip row dropped from V1 (and the
  store module should follow), or is the UI still pending implementation?
  Cf. `kioskFilter.js:1-2` comments referencing `P-MEGA-09` and
  `P-MEGA-W3.D` — phase work apparently parked. Owner gate: V1 scope.

- **Frozen-zone:** none of the K04 files are listed in
  `00_ULTRA_PLAN.md:35-46` frozen list. All fixes proposed here are
  modifiable without owner gate, except K04-P1-06 (touches
  `helpers/kioskSandwichSplit.js`, owned by K14 audit — coordinate).

- **Stale doc:** `00_ULTRA_PLAN.md:4` says HEAD=`245e8ab57`. Actual HEAD =
  `6a33a9763`. Plan author should refresh before downstream synthesis.
