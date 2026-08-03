# i18n + Dead-Code Cleanup Sweep — STATUS

**Date:** 2026-05-18
**Branch:** heal/cms-pr1-quickwins-2026-05-18
**Mode:** scope-minimal removals only, evidence-required
**Wall-clock duration:** ~25 minutes
**Final verdict:** **PARTIAL CLEANUP COMPLETED**, 3 of 6 planned phases executed (Phase 1 / Phase 3 / Phase 6); 3 phases deferred to V1.0.x backlog after evidence-based KEEP verdict.

---

## Before / After Counts

### Vue JSON locales (`resources/js/languages/`)

| Locale | Flat keys BEFORE | Flat keys AFTER | Removed |
| ------ | ---------------- | --------------- | ------- |
| fr.json | 1912 | 1730 | -182 (incl. 1 trailing-dot dedupe in Phase 3) |
| en.json | 2031 | 1840 | -191 (incl. collapse of empty `number` namespace) |
| ar.json | 1881 | 1696 | -185 (incl. collapse of empty `number` namespace) |

> Removed-count > 187 because cascaded empty parent namespaces (e.g. the entire `"number"` top-level in en/ar) were collapsed by the JSON writer after the leaf keys were removed.

### Source files

| Phase | File | Action |
| ----- | ---- | ------ |
| 6 | `app/Events/SendEmailVerification.php` | DELETED |
| 6 | `app/Listeners/SendEmailVerificationNotification.php` | DELETED |

---

## Per-Category Result

### PHASE 1 — 240 high-confidence dead i18n keys → **187 removed, 53 KEPT**

**Status:** EXECUTED (commit `0a1a01a16`)

Source list: `reports/audit/foundation-2026-05-18/round-1/F-7-I18N/_high_confidence_dead.txt` (239 unique candidates; audit README said 240 but file is 239 lines).

Verification methodology (per-key, all 239):
1. **Literal match scan** for `'key'`, `"key"`, `` `key` `` across `app/`, `resources/js/components/...`, `resources/views/`, `tests/`, `public/js/`, `mobile/`, and standalone `/Users/1millnonstop/Downloads/web/` tree (3650 source files, ~700 MB content).
2. **Dynamic-template scan** for the key's parent namespace:
   - `` `prefix.${...}` `` (template literal)
   - `"prefix.".concat(...)` (compiled JS)
   - `'prefix.' + ...` / `"prefix." + ...` (Vue concat)

Outcome:
- **187 SAFE** → removed from all 3 JSON locales.
- **53 KEPT** because they matched:
  - Literal hits: `kiosk.order_type.dine_in`, `kiosk.order_type.takeaway`, `kiosk.cart.bottom_sheet.add_one_named`, `kiosk.categories`, `kiosk.products`, `error.network_issue`, `error.rate_limited`, `error.service_unavailable`, `catalog.review_cart`, etc. (15 keys) — used in active Vue components, dropped from delete list.
  - Dynamic template hits:
    - `kiosk.wizard.frites_sauce.*` (5 keys) — `$t(\`kiosk.wizard.frites_sauce.${key}\`)` in `KioskOrderSummaryComponent.vue:382`, `KioskWizardComponent.vue:1470`.
    - `kiosk.wizard.prompt.*` (8 keys) — `$t(\`kiosk.wizard.prompt.${type}\`)` in `KioskWizardComponent.vue:1586,1606`.
    - `menu.*` (21 keys) — `$t('menu.' + menu.language)` in `BackendMenuComponent.vue`, `BackendNavbarComponent.vue`, `FrontendNavBarComponent.vue`, `FrontendMobileAccountComponent.vue`, `BreadcrumbComponent.vue`. This was the audit's first big false-positive: the 21 `menu.*` keys (accounts, app, dashboard, settings, users, reports, etc.) ARE consumed by sidebar/navbar rendering that pulls menu names from DB.

187 removed keys span families: `kiosk.admin_screen.*` (74 keys — admin screen surface deprecated), `kiosk.app.still_here_*` / `kiosk.app.admin_trigger_title` (4 keys), `kiosk.catalog.*` (8 keys), `kiosk.wizard.step.{viande,sauce,pain}.fallback_*` and `meta_included` (15 keys), `kiosk.wizard.menu.frites_upgrade_*` (4 keys), `kiosk.wizard.summary.menu_label_none` (1 key), `admin.stock_rupture.{confirm_bulk,mark_rupture_selected,no_selection,toggle_success}` (4 keys), `button.{base_ht,orders,test_print}` (3 keys), `kiosk.{add,welcome,checkout,pay,thank_you,language,loading,confirm,...}` (~30 keys), `pos.{cancel_order,reprint_loading,reprint_ticket,kiosk_counter_collect_sub,tracker.button_sub}` (5 keys), `message.*` (8 keys — old image upload / token / NFC messages), `number.{10,25,50,100,500,1000}` (6 keys), `a11y.{cart_updated,loading_items}` (2 keys), `kiosk.badges.{gluten_free,pork_free}` (2 keys), `error.printer_unreachable` (1 key).

### PHASE 2 — 55 Bangladesh-legacy keys → **0 removed, 46 KEPT WITH EVIDENCE**

**Status:** SKIPPED (audit claim CONTRADICTED by evidence)

The audit STATUS.md asserted "55 keys in en.json + ar.json. NO Blade template grep match for these → safe purge." This claim is **WRONG**.

Evidence: `resources/js/components/admin/settings/PaymentGateway/PaymentGatewayComponent.vue:45` renders `$t("label." + paymentGatewayOption.option)` — fully dynamic. The variable `paymentGatewayOption.option` is sourced from the API and contains values like `bkash_app_key`, `easypaisa_status`, `flutterwave_mode`, etc. Parallel pattern at `SmsGatewayComponent.vue:45,58`.

Additional evidence: `app/Http/Controllers/Admin/PaymentGatewayController.php:37` does `'App\\Http\\PaymentGateways\\Requests\\' . ucfirst($request->payment_type)` — dynamic FormRequest resolution. The 22 gateway FormRequest files in `app/Http/PaymentGateways/Requests/` (Bkash.php, Cashfree.php, Easypaisa.php, Flutterwave.php, Mercadopago.php, Paystack.php, Razorpay.php, Sslcommerz.php, Clickatell.php, etc.) define rules() for fields named exactly `bkash_app_key`, `bkash_app_secret`, `easypaisa_store_id`, `flutterwave_public_key`, etc. The admin UI consumes those field names verbatim and asks i18n for `label.<field_name>`.

If the 46 Bangladesh keys were purged, ANY admin who opens the Payment Gateway settings page for any of bkash / easypaisa / cashfree / clickatell / flutterwave / mercadopago / paystack / sslcommerz / razorpay / bulksmsbd would see raw `label.bkash_app_key` raw keys leak in the UI.

**Recommendation:** if owner wants to retire these gateways, the cleanup must touch BOTH (a) the FormRequest classes in `app/Http/PaymentGateways/Requests/`, (b) any DB seed for `payment_gateway` table referencing these, (c) the Vue rendering surface, AND (d) the i18n keys — together. NOT a scope-minimal removal. Document as V1.0.x deferred.

### PHASE 3 — 3 empty-string trailing-dot keys in fr.json → **3 fixed**

**Status:** EXECUTED (commit `86656f1d1`)

Per F-7-I18N D.1 (P1 finding):
- `fr.json` `menu` namespace `""` → renamed to `"label"` (value "Menu" preserved)
- `fr.json` `label` namespace `""` → **REMOVED** (value "Libellé" was synonym of the pre-existing `label.label = "Label"` at the same nesting level; renaming to `"label"` would have created a JSON duplicate-key collision that JSON decoders resolve by keeping the later occurrence; safer to remove)
- `fr.json` `kiosk.filters` namespace `""` → renamed to `"all"` (value "Tous" preserved; addresses `$t(\`kiosk.filters.${f}\`)` in 4 Kiosk step components with `f === "all"`)

EN/AR already had no empty-string keys (audit-verified).

### PHASE 4 — 253 duplicate FR values → **0 changed, deferred**

**Status:** SKIPPED per task constraint

Task explicitly: "**only if grep proves all callers can be migrated (else KEEP)**". 253 duplicates × ~10+ callers each (e.g. "Annuler" appears in 10 keys: `label.cancel`, `button.cancel`, `kds.kds_undo_bump`, `button.kds_recall`, `admin.stock_rupture.cancel`, etc.) cannot be migrated to a single `common.*` canonical namespace inside a 30-45 minute scope-minimal sweep. Each migration would require touching every caller file and re-running the visual sentinel suite.

Document as V1.0.x backlog: 3 PRs (introduce `common.*` canonical, migrate ~250 keys, deprecate old keys).

### PHASE 5 — 10/16 PHP namespace files → **0 deleted, KEEP (audit claim WRONG)**

**Status:** SKIPPED (audit claim CONTRADICTED by evidence)

The audit STATUS.md asserted "10/16 PHP namespace files are EMPTY shells from Bangladesh template — recommend delete to reduce dead files." This claim is **WRONG**.

Evidence: each of the 10 supposedly-empty files contains valid enum-keyed translations the app consumes at runtime:

| File | Content (FR) | Active Caller(s) |
| ---- | ------------ | ---------------- |
| `lang/fr/addressType.php` | `AddressType::HOME => 'Home'`, `WORK`, `OTHER` | Address admin UI |
| `lang/fr/ask.php` | `Ask::YES => 'Oui'`, `Ask::NO => 'Non'` | `ItemExport.php:38`, `MessageResource.php:46` (`trans('ask.' . $value)`) |
| `lang/fr/discount_types.php` | `5 => 'Fixed'`, `10 => 'Percentage'` | Coupon system |
| `lang/fr/itemType.php` | `VEG => 'Végétarien'`, `NON_VEG => 'Non végétarien'` | `ItemExport.php:35`, `ItemsReportExport.php:34` (`trans('itemType.' . $type)`) |
| `lang/fr/orderStatus.php` | 9 statuses: PENDING, ACCEPT, PREPARING, PREPARED, OUT_FOR_DELIVERY, DELIVERED, CANCELED, REJECTED, RETURNED | `OrderExport.php:36`, 4 OrderResource classes (`trans('orderStatus.' . $status)`) |
| `lang/fr/orderType.php` | DELIVERY, TAKEAWAY, POS, DINING_TABLE | `OrderExport.php:32`, `pdf/online_orders.blade.php:115` (`trans('orderType.' . $type)`) |
| `lang/fr/payment_gateway.php` | CASH_ON_DELIVERY, E_WALLET, PAYPAL | `SalesReportExport.php:63` (`trans('payment_gateway.' . $method)`) |
| `lang/fr/payment_status.php` | PAID, UNPAID, PENDING_COUNTER, REFUNDED | `SalesReportExport.php:38`, `pdf/sales_report.blade.php:135` |
| `lang/fr/pos_payment_method.php` | CARD, CASH, OTHER, MOBILE_BANKING, TICKET_RESTAURANT, COUNTER_DEFERRED | `SalesReportExport.php:59`, `pdf/sales_report.blade.php:98` |
| `lang/fr/statuse.php` | Status::ACTIVE => 'Actif', INACTIVE => 'Inactif' | 5 Export classes (DiningTable, Item, Offer, Administrator, Chef) |

The audit's "empty shell" classification appears to have come from a naive `count(array_keys($file))`-style scan that miscounted enum-cased keys (`OrderStatus::PENDING`).

**Cosmetic noise observed:** 9 FR files have a trailing `'' => ''` entry, which acts as a defensive fallback (returns `''` for `trans('namespace.')` with empty key concatenation, e.g. `trans('pos_payment_method.' . $emptyString)`). The callsite `SalesReportExport.php:59` already handles this defensively via `!= "pos_payment_method."` conditional. Removing the `''` fallback would NOT break any code, but the safety-vs-aesthetics trade-off does not justify a removal commit under the scope-minimal mandate. Document as truly-cosmetic V1.0.x.

### PHASE 6 — Dead event-listener pair → **2 files deleted**

**Status:** EXECUTED (commit `2c0b7e606`)

Per F-X-DUPS audit + EventServiceProvider analysis:
- `app/Events/SendEmailVerification.php` (24 lines) → DELETED
- `app/Listeners/SendEmailVerificationNotification.php` (37 lines) → DELETED

Evidence:
- `app/Providers/EventServiceProvider.php:80` imports `Illuminate\Auth\Listeners\SendEmailVerificationNotification` (Laravel built-in).
- Line 92 binds `SendEmailVerificationNotification::class` to the framework-built-in `Illuminate\Auth\Events\Registered::class` event.
- Both local files are referenced ONLY by themselves (the listener `use App\Events\SendEmailVerification` is the local pair). Grep across `app/`, `resources/`, `tests/`, `config/`, `routes/`, `database/` confirmed zero external references.

Verified post-deletion: `php artisan event:list` reports the framework binding correctly; sentinel tests 24/24 PASS.

---

## Commits SHA List

| Phase | Commit | Title |
| ----- | ------ | ----- |
| 6 | `2c0b7e606` | chore(i18n-cleanup): dead-listener-pair — 2 files removed |
| 1 | `0a1a01a16` | chore(i18n-cleanup): dead-keys-phase1 — 187 verified dead i18n keys removed |
| 3 | `86656f1d1` | chore(i18n-cleanup): empty-trailing-dot-keys-phase3 — 3 fr.json keys fixed |

Branch HEAD: `86656f1d1` (heal/cms-pr1-quickwins-2026-05-18, +3 commits vs origin start of session, total +10 ahead).

No push to remote performed.

---

## Sentinel Test Verification

```
php artisan test --filter "I18n|Locale"
```

Result post-cleanup: **24/24 PASS** (2.18 - 2.46s wall-clock per run).

Suite includes:
- `Tests\Feature\I18n\StudioKeyParityTest`
- `Tests\Feature\KioskMultiBranch\KioskLocaleMiddlewareTest` (multiple data sets)
- `Tests\Feature\KioskPhase1\AllergensSeederTest`
- `Tests\Feature\KioskPhase1\BranchAvailableLocalesTest`
- `Tests\Feature\KioskPhase1\Phase1MigrationsTest`
- `Tests\Feature\Sentinels\FrenchRuntimeNoBangladeshDemoDataSentinelTest`
- `Tests\Feature\Stock\StockDashboardI18nIntegrityTest` (across fr/en/ar)

The Stock i18n sentinel guards the 16 canonical keys (`title`, `subtitle`, `cron_enabled`, etc.) inside `admin.stock_rupture` — independent from the 4 removed keys (`confirm_bulk`, `mark_rupture_selected`, `no_selection`, `toggle_success`).

JSON validity verified post-cleanup: `json_decode(file_get_contents(...), JSON_THROW_ON_ERROR)` OK for all 3 locales. Top-level key counts: fr=16, en=14 (was 15: collapsed empty `number`), ar=14 (was 15: collapsed empty `number`).

---

## Why "scope-minimal" trumped the audit numbers

| Audit claim | Adjusted reality | Reason |
| ----------- | ---------------- | ------ |
| 240 dead keys safe to remove | 187 truly safe | Dynamic-template scan caught 53 false-positives the audit's 13-pattern filter missed (esp. `$t('menu.' + menu.language)` for 21 menu.* keys) |
| 55 Bangladesh keys safe purge | 0 safe | Vue `$t("label." + opt.option)` consumes them dynamically + 22 FormRequest classes still active under dynamic resolution `'App\\Http\\PaymentGateways\\Requests\\' . ucfirst($type)` |
| 10/16 PHP namespace empty shells | 0 empty | All 10 files contain valid enum-keyed translations the Exports + Resources + PDF Blades consume at runtime |
| 253 duplicate FR values consolidation | 0 done | Per task constraint: requires migrating all callers; out of scope-minimal budget |
| 3 empty-string fr.json keys | 3 fixed | Audit was right on this one; renamed (2) and removed (1 with prior collision) |
| 1 dead listener pair | 2 files | Confirmed: framework-shadowed by `Illuminate\Auth\Listeners\SendEmailVerificationNotification` |

Net: **3 of 6 audit-claimed phases delivered legitimate cleanup; 3 had factually wrong claims that this sweep had to reject on evidence.** Documenting the rejections is itself a deliverable for the V1.0.x backlog (avoids re-litigating the same audit claims).

---

## V1.0.x Backlog (deferred)

1. **Bangladesh-gateway full retirement** (Phase 2 deferred): if owner decides to retire bkash/easypaisa/cashfree/clickatell/flutterwave/mercadopago/paystack/sslcommerz/razorpay/bulksmsbd, do a coordinated sprint touching FormRequests + DB seed + Vue admin gateway components + i18n keys together.
2. **Duplicate FR consolidation** (Phase 4 deferred): introduce `common.action.{cancel,retry,close,delete,continue}`, `common.surface.{kiosk,pos,online}`, `common.order_type.{dine_in,takeaway}` canonical namespace, migrate ~250 redundant keys.
3. **PHP namespace `'' => ''` cleanup** (Phase 5 deferred): 9 FR files have defensive `'' => ''` fallback. Either harden callers to not rely on it OR keep as-is.
4. **`audit_locale_keys.mjs` CI wire-in**: add to `.github/workflows/` or pre-push to ratchet down the ~49 remaining FR `$t()` UNDEFINED keys.
5. **`I18nKeyIntegritySentinelTest.php`**: lock in the dead-key count via sentinel.

---

## Files written by this sweep

- `reports/audit/foundation-2026-05-18/cleanup/STATUS.md` (this file)

---

## Wall-clock

Start: 1779130059 (timestamp file `/tmp/cleanup_start.txt`)
End: ~25 minutes later
Target: 30-45 min → delivered under target.
