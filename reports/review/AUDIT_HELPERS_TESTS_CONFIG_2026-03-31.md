# Audit Report — Helpers, Tests, Config, i18n, Database

**Date:** 2026-03-31  
**Scope:** JS helpers, test suites, configuration, i18n, MenuSeeder  
**Auditor:** Claude (Architect)

---

## Executive Summary

| Area | Critical | High | Medium | Low | Info |
|------|----------|------|--------|-----|------|
| JS Helpers | 1 | 3 | 5 | 4 | 2 |
| Test Suites | 0 | 4 | 5 | 3 | 2 |
| Configuration | 1 | 2 | 2 | 1 | 0 |
| i18n | 0 | 2 | 3 | 2 | 1 |
| Database/Seeder | 0 | 1 | 2 | 2 | 0 |
| **Total** | **2** | **12** | **17** | **12** | **5** |

---

## 1. JS Helpers Audit

### 1.1 `kioskPrinter.js`

#### [CRITICAL] P0-PRINT-01: Hardcoded French strings in receipt builder
- **Lines 91, 151, 165, 167**: `'VOTRE NUMÉRO'`, `'FIDELITE'`, `'Merci pour votre commande !'`, `'À bientôt !'`, `'Réduction fidélité'`, `'Sous-total'`, `'Paiement'`
- **Impact:** Receipt text is not translatable. If the kiosk locale is set to `en` or `ar`, the receipt will still print in French.
- **Fix:** Accept a `translations` object parameter or use an i18n instance. The `buildEscPosReceipt()` and `buildReceiptData()` functions should receive translated labels.

#### [HIGH] P1-PRINT-02: XSS risk in receipt DOM fallback
- **Line 232**: `window.print()` fallback relies on a DOM element (`#kiosk-print-receipt`). If the receipt content is rendered with `v-html` or unescaped interpolation, user-controlled data (item names, instructions) could inject HTML.
- **Fix:** Ensure the receipt DOM element uses text-only rendering (`v-text` or `{{ }}` in Vue).

#### [MEDIUM] P2-PRINT-03: `formatEur()` is private and hardcodes EUR
- **Line 247-249**: `formatEur()` always appends `' EUR'` regardless of the restaurant's configured currency.
- **Fix:** Use `formatKioskPrice()` from `kioskFormatPrice.js` instead, or accept currency as parameter.

#### [LOW] P3-PRINT-04: `padLine()` truncates long item names silently
- **Line 41**: `left.substring(0, available)` truncates without ellipsis. Long item names like "Omelette Champignons Fromage + Sauce supplémentaire: Algérienne" will be cut mid-word.
- **Fix:** Add `'…'` suffix when truncation occurs.

### 1.2 `kioskOfflineQueue.js`

#### [HIGH] P1-OFFLINE-01: No payload size validation before localStorage write
- **Line 35**: `localStorage.setItem(QUEUE_KEY, JSON.stringify(queue))` — if the queue grows large (many failed orders with full payloads), this will silently fail when quota is exceeded.
- **Impact:** Orders could be silently lost.
- **Fix:** Add a queue size cap (e.g., max 50 entries) and evict oldest synced entries before adding new ones.

#### [HIGH] P1-OFFLINE-02: Race condition in concurrent sync attempts
- **Lines 86-125**: `syncQueue()` loads the queue, iterates, and saves. If `startAutoSync` fires while a manual sync is in progress, both will load the same queue state and may double-submit orders.
- **Fix:** Add a mutex flag (`_syncing = true/false`) to prevent concurrent execution.

#### [MEDIUM] P2-OFFLINE-03: `_reportAbandoned()` defined but only called inside `startAutoSync`
- **Line 133**: If `syncQueue()` is called directly (not via `startAutoSync`), abandoned orders are never reported.
- **Fix:** Move the `_reportAbandoned` call into `syncQueue()` itself.

#### [MEDIUM] P2-OFFLINE-04: No encryption of order payloads in localStorage
- **Impact:** Customer data (names, phone numbers from loyalty) stored in plaintext in localStorage.
- **Fix:** Consider encrypting sensitive fields or clearing the queue on logout.

### 1.3 `kioskMenuCache.js`

#### [MEDIUM] P2-CACHE-01: No data integrity validation on load
- **Line 51**: Only checks `Array.isArray(snapshot.categories)` — doesn't validate that categories have required fields (`id`, `name`). Corrupted data could cause runtime errors in components.
- **Fix:** Add a lightweight schema check (e.g., first item has `id` and `name`).

#### [LOW] P3-CACHE-02: localStorage fallback doesn't clean up after successful IDB migration
- If data was saved to localStorage (IDB failure), then IDB becomes available again, the localStorage copy is never cleaned up.
- **Fix:** After successful IDB save, try `localStorage.removeItem(SNAPSHOT_KEY)`.

### 1.4 `kioskFormatPrice.js`

#### [MEDIUM] P2-PRICE-01: Manual formatting ignores locale decimal separator
- **Line 36**: `num.toFixed(digits).replace('.', ',')` — hardcodes comma as decimal separator. For `en-US` locale with `currencySymbol` override, this would show `12,50 $` instead of `12.50 $`.
- **Fix:** Use `Intl.NumberFormat` even when `currencySymbol` is provided, or respect the locale's decimal separator.

#### [LOW] P3-PRICE-02: `getPriceOptionsFromStore()` returns `'€'` as default
- **Line 65**: Hardcoded default `'€'` — acceptable for current single-restaurant setup but will break for SaaS multi-currency.
- **Fix:** Document this as a known SaaS-blocking limitation.

### 1.5 `kioskCategoryOrder.js`

#### [LOW] P3-CAT-01: Regex patterns may match unintended substrings
- **Line 29**: `jus ` (with trailing space) won't match `jus` at end of string. `the ` matches English "the" in any context.
- **Fix:** Use word boundaries `\b` where possible: `/\bjus\b/`, `/\bthé\b/`.

#### [INFO] I-CAT-02: `norm()` function duplicated across helpers
- `kioskCategoryOrder.js` and `kioskItemDisplayOrder.js` both define identical `norm()` functions.
- **Fix:** Extract to a shared `kioskNormalize.js` utility.

### 1.6 `kioskDrinkAddons.js`

#### [MEDIUM] P2-DRINK-01: Redundant check in `kioskIsDrinkAddonName()`
- **Line 16**: `if (n.includes('frite') || n.includes('menu')) return false;` — already handled by `kioskIsFoodLikeAddonName()` on line 15.
- **Impact:** No bug, but misleading code.

#### [LOW] P3-DRINK-02: No handling of accented drink names
- `n.includes('thé')` works, but `n.includes('café')` won't match `'CAFÉ'` because `.toLowerCase()` preserves accents. The `.toLowerCase()` on line 7 handles case, but NFD normalization is missing (unlike `norm()` in other helpers).
- **Fix:** Add `.normalize('NFD').replace(/[\u0300-\u036f]/g, '')` for consistency.

### 1.7 `kioskDisplayText.js`

#### [INFO] I-TEXT-01: Replacement pairs are French-only
- **Line 15**: `'extras'` → `'suppléments'` — if the kiosk is in English or Arabic, this replacement is incorrect.
- **Fix:** Accept locale parameter and use locale-appropriate replacements.

### 1.8 `kioskSandwichSplit.js`, `kioskItemDisplayOrder.js`, `kioskUpsellFlow.js`, `kioskMedia.js`

- **No critical issues found.** These helpers have good null handling, defensive coding, and clear documentation.

### 1.9 `posCartLineMath.js`

#### [MEDIUM] P2-CART-01: `parsePositiveInt()` returns `fallback` for zero
- **Line 7**: `n > 0 ? n : fallback` — a quantity of `0` would be treated as `fallback` (typically `1`), which means a zero-quantity line would still contribute to the total.
- **Fix:** Clarify intent. If zero is invalid, add a comment. If zero should be allowed, use `n >= 0`.

---

## 2. Test Suites Audit

### 2.1 Missing Test Coverage (JS)

#### [HIGH] T-GAP-01: No tests for `kioskFormatPrice.js`
- The centralized price formatter has zero test coverage. This is a critical display function used across all kiosk screens.
- **Missing tests:** locale fallback, currency symbol override, position left/right, invalid locale handling, `getPriceOptionsFromStore()` with missing store data.

#### [HIGH] T-GAP-02: No tests for `kioskPrinter.js`
- The receipt builder and print dispatch have zero test coverage.
- **Missing tests:** `buildEscPosReceipt()` output structure, `buildReceiptData()` field mapping, `printReceipt()` method selection, edge cases (empty items, null fields).

#### [HIGH] T-GAP-03: No tests for `kioskOfflineQueue.js`
- The offline order queue has zero test coverage. This is a critical data-loss-prevention system.
- **Missing tests:** `saveOrder()` persistence, `syncQueue()` retry logic, abandoned order handling, `startAutoSync()`/`stopAutoSync()` lifecycle, queue pruning, idempotency key preservation.

#### [HIGH] T-GAP-04: No tests for `kioskMedia.js`
- Image resolution helper has zero test coverage.
- **Missing tests:** `kioskResolveImageSrc()` with various source shapes, `kioskVariationsForAttribute()` with string vs number keys.

### 2.2 Test Quality Issues (JS)

#### [MEDIUM] T-QUAL-01: `posCart.spec.js` tests simulate logic instead of testing real code
- **Lines 23-79**: All three tests manually re-implement the store logic rather than importing and testing the actual `posCart` Vuex module. These tests prove the test code works, not the production code.
- **Fix:** Import the actual Vuex module or test via a mounted component.

#### [MEDIUM] T-QUAL-02: `KioskWizard.spec.js` uses a mock component instead of the real one
- **Lines 27-258**: `createKioskWizardMock()` creates a completely separate component that mirrors the real wizard's logic. If the real wizard diverges, these tests won't catch it.
- **Mitigation:** The file also includes tests using `shallowMount(KioskWizardComponent)` (lines 1060+), which test the real component. The mock-based tests should be clearly labeled as "logic specification" tests.

#### [MEDIUM] T-QUAL-03: `kioskDrinkAddons.spec.js` has minimal coverage
- Only 2 test cases for `kioskIsDrinkAddonName()`. Missing: edge cases for accented names, empty strings, null input, names that are borderline (e.g., "Ice Tea Frites" — food or drink?).

#### [MEDIUM] T-QUAL-04: `kioskDisplayText.spec.js` has minimal coverage
- Only 2 test cases. Missing: `undefined` input, numeric input, multiple replacements in one string, strings that match multiple patterns.

#### [LOW] T-QUAL-05: No test for `posCartLineMath.js`
- The shared POS cart math module has no dedicated test file despite being a critical pricing calculation module.

### 2.3 Test Quality Issues (PHP)

#### [MEDIUM] T-PHP-01: `FrontendOrderServiceTest` tests source code strings, not behavior
- **Lines 62-104**: Tests like `source_code_contains_item_validation()` assert that specific strings exist in source files. This is fragile — a rename or refactor breaks the test without changing behavior.
- **Fix:** Test the actual service behavior (call the method with invalid data, assert exception).

#### [LOW] T-PHP-02: `it_prioritizes_db_price_over_client_price()` doesn't test the service
- **Lines 39-55**: Creates a mock `$dbItem` object and asserts its own price. This proves nothing about the actual `FrontendOrderService`.
- **Fix:** Instantiate the real service, pass a crafted request with a manipulated client price, and verify the order uses the DB price.

#### [LOW] T-PHP-03: Feature tests are comprehensive but may be slow
- 39 Feature test files with `RefreshDatabase` — each test class migrates the database. This is correct but may cause slow CI runs.
- **Info:** Consider using `LazilyRefreshDatabase` or test parallelization.

### 2.4 Untested Critical Paths

| Critical Path | Test Exists? | Gap |
|---|---|---|
| Kiosk price formatting | No | No test file at all |
| Kiosk receipt printing | No | No test file at all |
| Kiosk offline queue sync | No | No test file at all |
| Kiosk image resolution | No | No test file at all |
| POS cart line math | No | No test file at all |
| Kiosk wizard (real component) | Partial | Real component tested for canAdvance/menu, but not for buildCartItem on real component |
| Kiosk payment state machine | Yes | Good coverage |
| Frontend discount integrity | Yes | Good coverage |
| KDS order sorting | Yes | Good coverage |
| Order state transitions | Yes | Good coverage |

---

## 3. Configuration Audit

### 3.1 `config/kiosk.php`

#### [CRITICAL] P0-CFG-01: Default kiosk credentials hardcoded in config
- **Lines 49-61**: Default username `'kiosk-lecayenne'` and password `'kiosk123'` are hardcoded as fallbacks. Even with `env()` calls, the fallback values are production credentials.
- **Impact:** If `.env` is missing or misconfigured, the kiosk auto-logs in with known credentials.
- **Fix:** Remove hardcoded fallback credentials. If env vars are missing, set `spa_auto_login` to `false` and require manual configuration.

#### [HIGH] P1-CFG-02: Password exposed in SPA payload
- **Line 63-66**: `$spaPayload` includes the plaintext password, which gets injected into the HTML page (via `master.blade.php`). Anyone viewing page source sees the kiosk password.
- **Fix:** Use a one-time token exchange instead of embedding credentials in the page.

#### [MEDIUM] P2-CFG-03: `sandwich_split.cold_item_slugs` hardcoded, not env-configurable
- **Lines 25-30**: Cold sandwich slugs are hardcoded. Adding a new cold sandwich requires a code change.
- **Fix:** Accept from env as comma-separated string: `env('KIOSK_COLD_SANDWICH_SLUGS', 'sandwich-froid,panini,...')`.

### 3.2 `config/menu.php`

#### [HIGH] P1-MENU-01: `config/menu.php` is restaurant-specific, not multi-tenant
- The entire file is hardcoded for "Le Cayenne" restaurant. This blocks SaaS evolution.
- **Impact:** Low for current single-restaurant deployment, high for future SaaS.
- **Fix:** Document as known SaaS-blocking. No action needed now.

#### [MEDIUM] P2-MENU-02: English contamination check includes common French words
- **Line 213-219**: `englishPatterns` includes `'Burger'`, `'Sandwich'` — these are used in the French menu (`'Nos Burgers'`, `'Nos Sandwichs'`). The `isEnglishContaminated()` method would flag the French menu as contaminated.
- **Actual behavior:** `menuExists()` returns `true` first (line 93), so the contamination check is only reached if the menu doesn't exist yet. But the logic is fragile.
- **Fix:** Remove `'Burger'` and `'Sandwich'` from English patterns since they're used in French context.

#### [LOW] P3-MENU-03: `protection.require_french_locale` is declared but never enforced
- **Line 748**: The `protection` config block declares `require_french_locale => true` but no code reads this value.
- **Fix:** Either enforce it in the seeder or remove it.

### 3.3 `config/sanctum.php`

#### [MEDIUM] P2-SANCTUM-01: Token expiration is 30 days by default
- **Line 52**: `43200` minutes = 30 days. This is long for a kiosk token. If a kiosk machine is compromised, the token remains valid for a month.
- **Fix:** Consider reducing to 7 days (`10080`) and implementing automatic token refresh on kiosk boot.

### 3.4 `webpack.mix.js`

#### [LOW] P3-WEBPACK-01: No source maps configuration
- **Line 13**: Single-line config with no `.sourceMaps()` call. Debugging production issues will be difficult.
- **Fix:** Add `.sourceMaps(false, 'source-map')` for production builds.

---

## 4. i18n Audit

### 4.1 Key Coverage

#### [HIGH] I18N-01: FR file is heavily trimmed (550 keys) vs EN (1193) and AR (1167)
- **663 keys exist in EN but not FR.** This means the French locale is missing translations for most admin/settings pages.
- **Impact:** Admin users on `fr` locale will see English fallback text for ~55% of the interface.
- **Fix:** Complete the FR translation file. Priority: admin menu items, label keys, message keys.

#### [HIGH] I18N-02: 34 FR-only keys missing from AR
- Keys like `label.kiosk_idle_video`, `label.loyalty_points_per_euro`, `label.kiosk_admin_pin` exist in FR but not AR.
- **Impact:** Arabic kiosk admin screens will show untranslated keys.
- **Fix:** Add missing keys to `ar.json`.

#### [MEDIUM] I18N-03: 1 kiosk key missing from FR (`kiosk.discount`)
- EN and AR have `kiosk.discount` but FR does not.
- **Fix:** Add `"discount": "Remise"` to the FR kiosk section.

#### [MEDIUM] I18N-04: 20 FR-only keys missing from EN
- Keys like `label.order`, `label.add_note`, `label.empty_cart` exist in FR but not EN.
- **Impact:** English users on kiosk/POS screens will see raw key names.
- **Fix:** Add missing keys to `en.json`.

### 4.2 Hardcoded Text

#### [MEDIUM] I18N-05: `kioskPrinter.js` has 6+ hardcoded French strings
- See P0-PRINT-01 above. Receipt text is entirely in French regardless of locale.

#### [LOW] I18N-06: `kioskDisplayText.js` replacements are French-only
- See I-TEXT-01 above. `'extras'` → `'suppléments'` is French.

#### [LOW] I18N-07: `kioskSandwichSplit.js` default label is French
- **Line 20**: `coldLabel = options.coldLabel || 'Sandwich froid'` — hardcoded French default.

### 4.3 Interpolation Consistency

#### [INFO] I18N-08: Interpolation syntax is consistent
- All three language files use `{variable}` syntax (Vue i18n format). No mismatched `%s` or `{{}}` patterns found in the kiosk section.

---

## 5. Database / Seeder Audit

### 5.1 `MenuSeeder.php`

#### [HIGH] DB-01: `purgeExistingData()` truncates all menu data without backup
- **Lines 281-335**: The seeder truncates `items`, `item_categories`, `item_addons`, `item_extras`, `item_variations`, `item_attributes` — all without creating a backup.
- **Impact:** Running the seeder on a production database with custom menu data would destroy everything.
- **Fix:** Add a `--force` flag requirement for production. Add a pre-purge backup to a JSON file.

#### [MEDIUM] DB-02: `isEnglishContaminated()` false positive risk
- **Lines 211-245**: As noted in P2-MENU-02, the English patterns include words used in French menus (`'Burger'`, `'Sandwich'`).
- **Impact:** Could trigger unnecessary purge+reseed on a valid French database.

#### [MEDIUM] DB-03: No index creation for frequently queried columns
- The seeder creates items with `slug` and `item_category_id` but doesn't ensure indexes exist. The migrations should handle this, but it's worth verifying.
- **Fix:** Verify that migrations include indexes on `items.slug`, `items.item_category_id`, `item_variations.item_id`, `item_extras.item_id`.

#### [LOW] DB-04: `attachAddons()` attaches ALL addons to EVERY item
- **Lines 534-542**: Every menu item gets all 3 addon types (Menu, Frites Seules, Boisson Seule). Desserts and drinks shouldn't have "Menu (Frites + Boisson)" as an addon.
- **Fix:** Filter addons by category type — only attach menu addons to categories with `has_menu: true`.

#### [LOW] DB-05: Seeder output uses emoji characters
- **Lines 78-100**: `echo "✅"`, `echo "🚨"`, `echo "⚠️"` — these may not render correctly in all terminal environments (CI logs, Windows terminals).
- **Fix:** Use ASCII alternatives: `[OK]`, `[ERROR]`, `[WARN]`.

---

## 6. Recommended Priority Actions

### Immediate (Sprint)
1. **P0-PRINT-01**: Externalize hardcoded French strings in `kioskPrinter.js`
2. **P0-CFG-01**: Remove hardcoded default credentials from `config/kiosk.php`
3. **T-GAP-01/02/03**: Write tests for `kioskFormatPrice`, `kioskPrinter`, `kioskOfflineQueue`

### Next Sprint
4. **P1-OFFLINE-02**: Add sync mutex to prevent double-submission
5. **P1-CFG-02**: Replace SPA credential injection with token exchange
6. **I18N-01**: Complete FR translation file (admin keys)
7. **I18N-02**: Add missing kiosk keys to AR

### Backlog
8. **T-QUAL-01**: Replace simulated posCart tests with real module tests
9. **P2-PRICE-01**: Fix manual formatting locale issue in `kioskFormatPrice`
10. **DB-04**: Filter addon attachment by category type in seeder
11. **P2-OFFLINE-03**: Move `_reportAbandoned` into `syncQueue()`
12. **I-CAT-02**: Extract shared `norm()` to utility module

---

## Verdict

**NEEDS_FIX** — 2 critical issues (hardcoded credentials, untranslatable receipt) and 4 high-severity test coverage gaps require attention before the next release. No blocking architectural issues found. The helper code is generally well-structured with good defensive coding patterns.

Test type for fixes: **Kimi-test** (unit tests for helpers, no E2E needed).
