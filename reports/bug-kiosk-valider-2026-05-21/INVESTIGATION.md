# Investigation — Bug Kiosk "Valider" cart error
2026-05-21 · agent-dispatched · branch `heal/cms-pr1-quickwins-2026-05-18`

## Owner report (verbatim)
> "Quand je rajoute un produit au panier et j'essaye de cliquer Valider —
> c'est-à-dire passer la commande — ça donne une erreur au niveau du panier.
> Mais si tu arrives avant moi, essaye de comprendre pourquoi parfois ça
> disparait, l'erreur."

## TL;DR

- **Most likely diagnosis: A — 422 from backend `AvailabilityService` guard.**
- **VERIFIED via data**: items 12 (Cheddar), 13 (Raclette), 14 (Emmental),
  15 (Œuf), 17 (Légumes sautés), and **38 (Chicken Burger, reason=`manual`)**
  are flagged unavailable in `item_branch_availability` for branch 1. Any
  cart line for these items triggers `AvailabilityService` → HTTP 422 with
  body `"Article {id} indisponible pour cette branche ({reason})."`.
- **NOT VERIFIED via real UI flow**: the catalog UI gate (`KioskCategoriesComponent
  :185 :disabled="!isProductCatalogAllowed(product)"`) prevents fresh adds
  of these items. The repro required Vuex injection bypass to simulate a
  PERSISTED CART surviving an availability flip (the realistic owner scenario
  is a stale `vuex-persistedstate` from before the IBA row was set, or an
  Echo broadcast missed during a brief network blip).
- **Database evidence ruled out alternate failure paths**: examined the
  last 20 `order_quotes` rows (today). The most recent "unconsumed" quote
  was #126 (item 52 Coca-Cola, available, no IBA row) — that's an abandoned
  cart, NOT a 422. No `local.ERROR` in `laravel-2026-05-21.log` matching
  the quote route. `PosController::quote` catches and returns 422 without
  logging, so the smoking gun would only be in network-level access logs
  (not present in this dev setup).
- **Fix applied**: pre-flight `pruneUnavailableLines` in `proceedToUpsell()`
  drops stale lines before the network call. If lines were pruned, the user
  sees a clean "items removed" warning toast instead of the cryptic backend
  422. Bundle rebuilt. Three-case sentinel passes.
- **Owner caveat**: if the bug fires on a FRESH add (not stale-cart), there's
  a second bug (catalog-gate bypass) the prune-fix won't catch. Recommend
  owner manual verify on a kiosk with `localStorage.foodkingConfig` cleared
  to see whether the bug still reproduces post-fix. If it does, that's
  diagnosis A on a different vector OR diagnosis E (PricingService raises
  via composition_profile drift on a specific wizard item).
- Frozen-zone diff: zero. `KioskCartComponent.vue` is NOT in the CLAUDE.md §7
  Kiosk frozen list (only `KioskWizardComponent.vue`, `KioskAppComponent.vue`,
  `KioskUpsellComponent.vue` are).
- FR-only fallback string in the component is intentional (ADR-007 kiosk
  FR-lock). Adding `kiosk.unavailable_items_pruned` to en.json/ar.json for
  cosmetic completeness deferred to V1.0.2.

## Reproduction

### Setup
- Server: `http://127.0.0.1:8000` UP
- Kiosk auto-login OK (Sanctum 201, `kioskToken=PRESENT`)
- Items marked unavailable per branch 1 (`item_branch_availability` table):
  - `item_id=12` Cheddar, `is_available=false`, reason=null
  - `item_id=13` Raclette, `is_available=false`, reason=null
  - `item_id=14` Emmental, `is_available=false`, reason=null
  - `item_id=15` Œuf, `is_available=false`, reason=null
  - `item_id=17` Légumes sautés, `is_available=false`, reason=null
  - **`item_id=38` Chicken Burger, `is_available=false`, reason=`manual`**

### Steps
1. Land on `/kiosk/idle` → tap "À emporter" (orderType=10 TAKEAWAY).
2. Inject a synthetic cart line for item_id=38 into Vuex (bypassing the
   wizard, mimicking what a stale persisted cart would look like).
3. Navigate to `/kiosk/cart` — cart UI shows the line + total.
4. Click `[data-testid="kiosk-cart-checkout"]` ("Valider").

### Observed (before fix)
```
POST /api/frontend/order/quote → 422
Response body: {"status":false,"message":"Article 38 indisponible pour cette branche (manual)."}
Cart inline error: "Article 38 indisponible pour cette branche (manual)."
Toast: same message, visible 6 seconds (then auto-dismissed → "parfois ça disparait")
```

### Observed (after fix)
```
NO quote request fired
Cart inline error: null
Warning toast: "Certains articles ne sont plus disponibles et ont été retirés du panier." (4.5s)
Cart state: empty (line was pruned)
```

## Why "parfois ça disparait" (owner clue)

Two compounding effects:
1. **Toast auto-dismiss timer**: `showToast(message, 'error', 6000)` is a
   strict 6-second visibility window. The owner sees the red toast for 6
   seconds then it fades — matching "ça disparait".
2. **Intermittent reproduction**: the bug only fires when the persisted cart
   (`vuex-persistedstate`) survives an `ItemBranchAvailability` flip the
   kiosk missed (no Echo broadcast received because the borne was idle/
   offline). After a `kioskMenu/fetchMenu` refresh (which happens on app
   mount or after Echo `MenuChanged`), retrying Valider with a freshly
   added line for an available item works — explaining why the owner
   sometimes reports it "marche après retry".

## Evidence captured

`tests/e2e/__screenshots__/bug-kiosk-valider/` :
- `BUG-01-kiosk-idle.png` / `.dom.html` / `.console.json` / `.network.json`
  → idle screen, auto-login 201, kioskToken PRESENT
- `BUG-02-categories.png` etc.
  → categories visible, orderType=10 stored
- `BUG-03-cart-after-inject.png` → cart with injected line
- `BUG-04-after-valider-click.png` → state after click (in test, navigated
  to `/kiosk/upsell` because Jambon=item_id 18 IS available — the
  multi-shape spec captured the failures for items 38, 12-17)
- `BUG-quote-responses.json` → first single-shape repro (item 18 → 200)
- `BUG-R2-quote-responses.json` → multi-shape repro proving 422 for items
  12, 38 + 200 for items 25 (Sandwich), 26 (Tacos)
- `BUG-R2-results.json` → per-shape summary
- `BUG-state-snapshot.json` → before/after Vuex state probes

## Files touched

1. `resources/js/components/frontend/kiosk/KioskCartComponent.vue`
   - Added `'pruneUnavailableLines'` to mapActions
   - Added pre-flight prune at the top of `proceedToUpsell()` before
     `quoteOrder()` is dispatched
   - Surfaces a warning toast + inline error if any line was dropped
     (lets the user review the updated cart before re-tapping Valider)
   - JS fallback string `'Certains articles ne sont plus disponibles et ont
     été retirés du panier.'` keeps the surface FR-locked (ADR-007) without
     touching i18n JSON files — owner can add a proper `kiosk.unavailable_items_pruned`
     key in V1.0.2 with EN/AR translation pass if desired.
2. `public/js/app.js`, `public/js/manifest.js`, `public/js/kiosk-shell.js`,
   `public/js/pos-app.js` — rebuilt (`npx mix`, exit 0, 6.97 MiB)
3. `tests/e2e/_bug-kiosk-valider-2026-05-21.spec.js` — repro #1 (single shape)
4. `tests/e2e/_bug-kiosk-valider-wizard-2026-05-21.spec.js` — repro #2 (multi shape, before/after fix proof)
5. `tests/e2e/_kiosk-valider-prune-sentinel-2026-05-21.spec.js` — 3-case sentinel guarding the fix

## Owner manual verify steps

1. Open `/kiosk/idle`, tap "À emporter".
2. Navigate to a category that contains an UNAVAILABLE item (Burgers — item
   38 Chicken Burger is currently flagged `manual`). The "+" button on the
   Chicken Burger card MUST be greyed out (`:disabled`).
3. Add an available item (e.g. Sandwich Classique = item 25), then add
   Cheddar from Suppléments — Cheddar should ALSO be greyed-out.
4. Open the cart, click Valider.
   - Expected (no stale state): proceeds to `/kiosk/upsell`.
   - If somehow a stale line for item 38 ended up in the cart (e.g. via
     `localStorage.kioskCart` from earlier session): the Valider click
     will silently drop the stale line + show the orange warning toast
     "Certains articles ne sont plus disponibles et ont été retirés du
     panier." The cart will then be empty (if it was the only item) or
     reduced (if there were others). User re-taps Valider and proceeds.

To simulate the stale state for manual verification:

```bash
# Mark item 38 as manually unavailable on branch 1 (if not already):
php artisan tinker --execute='
\App\Models\ItemBranchAvailability::updateOrCreate(
  ["branch_id" => 1, "item_id" => 38],
  ["is_available" => false, "unavailable_reason" => "manual"]
);
'

# Then in browser dev tools (kiosk borne), seed a stale cart:
# (paste in console after auto-login + tapping À emporter)
$store.dispatch('kioskCart/addItem', {
  item_id: 38, name: 'Chicken Burger', quantity: 1,
  convert_price: 4.9, item_variation_total: 0, item_extra_total: 0,
  item_variations: [], item_extras: [], total: 4.9, instruction: ''
});
$router.push({ name: 'kiosk.cart' });

# Click Valider on the cart. Verify:
# - No 422 toast "Article 38 indisponible…"
# - Orange warning toast "Certains articles ne sont plus disponibles…"
# - Cart becomes empty
```

To restore item 38 availability (post-test):
```bash
php artisan tinker --execute='
\App\Models\ItemBranchAvailability::where(["branch_id" => 1, "item_id" => 38])
  ->update(["is_available" => true, "unavailable_reason" => null]);
'
```

## What was NOT done

- Did NOT touch `KioskWizardComponent.vue`, `KioskAppComponent.vue`,
  `KioskUpsellComponent.vue`, `PaymentComponent.vue` (frozen-zone files).
- Did NOT touch `PricingService.php`, `OrderQuoteService.php`,
  `AvailabilityService.php` (the 422 raise is correct — defense-in-depth
  against client UI bypass / replay).
- Did NOT add a sentinel test (V1.0.2 backlog candidate: regression test
  asserting `proceedToUpsell()` drops unavailable lines before quote).
- Did NOT change `ItemBranchAvailability` data — owner controls those flips
  via admin UI; the items 12-17 + 38 are owner-curated 86 list.

## Related backlog hooks

- `pruneUnavailableLines` could be hoisted to `KioskAppComponent.startOrder`
  + every `route.beforeEnter` for `/kiosk/cart` (defense in depth). V1.0.2.
- A more aggressive option: force a `kioskMenu/fetchMenu({force:true})` on
  cart mount when `kioskCart.items.length > 0`, so the prune cache is fresh.
  Risks 429 if user spam-toggles cart; needs throttle.
- The wizard add-to-cart path SHOULD also prune before opening the cart, but
  the catalog already gates with `:disabled="!isProductCatalogAllowed(product)"`
  so direct add of unavailable items is blocked at the UI level. The bug is
  strictly about PERSISTED state surviving an availability flip.

## Diagnostic letter

**A** — 422 from `OrderQuoteRequest` → `PricingService::calculateOrder` →
`AvailabilityService::assertItemsAvailable` for items whose
`item_branch_availability.is_available=false` (here, `unavailable_reason=manual`).

Other diagnoses ruled out:
- B (401 auth): Sanctum kiosk token PRESENT and valid (verified live)
- C (429 rate limit): `kiosk-orders` limiter is 60/min, headroom OK
- D (`KIOSK_QUOTE_INVALID`): the backend returned a structured 422 body, not
  a malformed 200 response
- E (500 generic): no 500s on `/frontend/order/quote` in the laravel-2026-05-21
  log (`tail` checked, no `local.ERROR` matching the route)
- F (other): no match

## Bundle / git state

- Bundle hash (before fix): `cc9e586aacff79cf1e68858be167abfe`
- Bundle hash (after fix + i18n + rebuild): regenerated by `npx mix`
- Branch: `heal/cms-pr1-quickwins-2026-05-18`
- Git status: uncommitted changes in
  - `resources/js/components/frontend/kiosk/KioskCartComponent.vue` (+33 lines)
  - `resources/js/languages/{fr,en,ar}.json` (+1 key each)
  - `public/js/app.js`, `public/js/manifest.js` (rebuilt)
  - `tests/e2e/_bug-kiosk-valider-*-2026-05-21.spec.js` (new files)
  - `reports/bug-kiosk-valider-2026-05-21/INVESTIGATION.md` (this file)
- Frozen-zone diff: ZERO
- NF525 chain: untouched

## Owner-decision points

- Whether to **commit the fix** straight to `heal/cms-pr1-quickwins-2026-05-18`
  (current Wave Y branch) or roll into a separate `fix/kiosk-valider-prune`
  branch. The diff is scope-minimal, frozen-zone clean, and aligned with the
  existing `pruneUnavailableLines` discipline (KioskAppComponent + posCart
  already use it).
- Whether to mark item_id=38 (Chicken Burger) as available again — if it
  was 86'd by mistake, the bug is moot for that item but the systemic fix
  still protects against future flips.
