# F-7 i18n + L10n — Foundation Audit Round 1

**Date:** 2026-05-18
**Mode:** read-only, parallel with 9 other foundation sub-agents
**Scope:** `lang/{fr,en,ar,bn,de}/*.php`, `resources/js/languages/{fr,en,ar,bn,de}.json`, `tools/i18n/audit_locale_keys.mjs`, sentinel coverage, hardcoded-string surfaces
**Branch:** v1-0-1-hardening-2026-05-17

---

## 0. TL;DR — V1 Verdict

**GO for V1 Le Cayenne ship (FR locale).** No P0. 4 P1 findings (3 cosmetic empty-key bugs + 1 raw error fallback). EN/AR locales NOT V1 — defer to V1.0.x. i18n is foundation debt, not foundation blocker.

---

## 1. BRAIN Correction Notes (read first)

This audit measured ground truth and found BRAIN figures (Wave Z-8) were partially wrong. Future agents must use these corrected numbers, not BRAIN's:

| BRAIN claim                                           | Measured reality                                                      |
| ----------------------------------------------------- | --------------------------------------------------------------------- |
| 19 `$t()` UNDEFINED keys                              | **49–50** (tools/i18n/audit_locale_keys.mjs report 2026-05-18 19:44) |
| 3 empty-string keys at fr.json lines **260/629/1432** | They are **keys with empty-string names `""`** at lines **287/656/1459** producing trailing-dot flat keys `menu.`, `label.`, `kiosk.filters.` (NOT empty values; lines 260/629/1432 contain valid keys) |
| 6 fr↔en/ar drift                                      | **75** keys FR\EN, **194** keys EN\FR, **223** keys FR\AR, **192** AR\FR (much larger; Bangladesh legacy + organic admin growth) |
| audit_locale_keys.mjs dormant since 9 mai             | Confirmed — tool works, runs successfully, but no CI/pre-commit wires it |

The Z-8 spot-checked specific user-facing surfaces; this F-7 audit measured the full inventory. BRAIN's "V1 sentinel guarantee" conclusion (admin=FR) remains correct.

---

## 2. Recon Snapshot

### Vue JSON locales (`resources/js/languages/`)
| Locale | File size | Flat keys (incl. nested) |
| ------ | --------- | ------------------------ |
| fr.json | 107242 B | 1912 |
| en.json | 105136 B | 2031 |
| ar.json | 118619 B | 1881 |
| bn.json | 117398 B | (out of V1 scope) |
| de.json |  87266 B | (out of V1 scope) |

### Laravel PHP locales (`lang/`)
| Locale | Lines | Active keys (non-empty namespaces) |
| ------ | ----- | ---------------------------------- |
| fr     | 686   | 226 (all.php) + 65 installer + 128 validation + 5 passwords + 3 auth + 2 pagination = 429 active across 6/16 files |
| en     | 670   | 236 + 58 + 128 + 5 + 3 + 2 = 432 active across 6/16 files |
| ar     | 615   | 185 + 57 + 128 + 5 + 3 + 2 = 380 active across 6/16 files |

10/16 PHP namespace files are empty shells (`addressType.php`, `discount_types.php`, `itemType.php`, `orderStatus.php`, `orderType.php`, `pagination.php`-partial, `payment_gateway.php`, `payment_status.php`, `pos_payment_method.php`, `statuse.php`).

### Tool inventory & sentinels
- `tools/i18n/audit_locale_keys.mjs` — works, dormant since 9 mai, generates 6 CSV reports under `reports/i18n/`. NOT in CI.
- `tests/Feature/Sentinels/*I18n*.php` — **none exist** (verified `find tests/Feature/Sentinels -name '*I18n*'` returns 0)
- `tests/Feature/Sentinels/*Locale*.php` — **none exist**

### Code references
- 1503 distinct `$t()` keys in Vue/JS files
- 1692 raw `$t()` call sites (counting repetitions)
- 159 distinct PHP-side keys (`__()` + `trans()` + Lang facade)
- 281 raw `trans()`/`__()` call sites in Blade + app/

### Dormant tool authoritative numbers (executed 2026-05-18 19:44)
```
VUE i18n: fr 50 missing | en 98 missing | ar 235 missing | de 412 missing | bn 413 missing
         Used: 1597 | Dead: 558 | Identical fr=en: 170
LARAVEL i18n: fr 1 missing | en 19 missing | ar 26 missing | de 41 missing | bn 38 missing
             Used: 160 | Dead: 277 | Identical fr=en: 137
```

---

## 3. The 4-List

### LIST A — DEAD KEYS (safe to remove from FR/EN/AR)

**Raw count from tool: 558 dead keys.**

After filtering against the 13 dynamic-key patterns found in code (`label.${normalized}`, `admin.help.${concept}.body/title`, `admin.stock_rupture.reason.${value}`, `admin.product_wizard.steps.${step.key}`, `kiosk.filters.${f}`, `kiosk.pay_screen.${tpeKey}`, `kiosk.wizard.instruction.${key}`, `label.delivery_cash_mvt_${type}`, `label.delivery_cash_status_${session.status}`, `${s}.menu_label_*`, `warning.catalog.${code}.${segment}`, `allergens.${code}`, `kiosk.allergens.${code}`):

**High-confidence dead: 240 keys.**

(Full list at `reports/audit/foundation-2026-05-18/round-1/F-7-I18N/_high_confidence_dead.txt`.)

Top categories:

1. **`kiosk.admin_screen.fb_*` family — ~40 keys** (kiosk admin screen feedback toasts; many wrapped in conditional UI never reachable in current routes). E.g. `kiosk.admin_screen.fb_consent_saved`, `kiosk.admin_screen.fb_drawer_browser`, `kiosk.admin_screen.fb_drawer_err`, `kiosk.admin_screen.fb_terminal_stub`.
2. **Bangladesh legacy payment gateways — 55 keys in en.json + ar.json** (NOT in fr.json). E.g. `label.bkash_app_key`, `label.easypaisa_mode`, `label.cashfree_status`, `label.clickatell_apikey`, `label.flutterwave_*`, `label.mercadopago_*`, `label.paystack_mode`, `label.sslcommerz_*`, `label.razorpay_*`. NO Blade template grep match for these → safe purge.
3. **`error.network_issue`, `error.printer_unreachable`, `error.rate_limited`, `error.service_unavailable`** — declared but no `$t()` reference (probably error toasts queued for unfinished UI work).
4. **`a11y.cart_updated`, `a11y.loading_items`** — leftover a11y aria-live placeholders.

**RECOMMENDATION:** before any deletion, runtime-smoke each key (load admin/kiosk/KDS surfaces, console-check for `vue-i18n :: Cannot translate the value of key`). Estimated yield: 150–200 keys safe to remove after smoke verification.

---

### LIST B — DUPLICATE KEYS (consolidation candidates)

**178 French values appear 2+ times, totaling 253 redundant key copies** (16% of FR JSON).

Top 10 consolidation candidates (by occurrence count):

| Value | Count | Existing keys (sample) | Proposed canonical |
| ----- | ----- | ---------------------- | ------------------ |
| Annuler | 10 | label.cancel, button.cancel, label.kds_undo_bump, button.kds_recall, admin.stock_rupture.cancel | `common.action.cancel` |
| Menu | 7 | menu., label.composer.template_menu, label.kds_group_menu, label.menu, button.menu | `common.menu` (and fix empty-key) |
| Réessayer | 7 | kiosk.retry, kiosk.error.retry, kiosk.login_screen.retry, kiosk.app.retry, label.ingredient.retry | `common.action.retry` |
| Fermer | 6 | label.dismiss, button.close, button.kds_allergens_modal_close, kiosk.waiting_screen.close | `common.action.close` |
| Borne | 5 | admin.item_preview.surface_kiosk, label.kiosk, label.channel_kiosk, label.kds_type_kiosk, pos.tracker.source_kiosk | `common.surface.kiosk` |
| Sur place | 5 | label.dine_in, label.kds_type_dinein, label.dinein_orders, kiosk.order_type.dine_in, kiosk.dine_in | `common.order_type.dine_in` |
| Supprimer | 4 | label.delete, button.delete, button.remove, pos.discard | `common.action.delete` |
| Caisse | 4 | label.channel_pos, label.kds_type_pos, pos.tracker.source_pos, label.cash_session_header_btn | `common.surface.pos` |
| En ligne | 4 | label.online, label.online_orders, label.kds_source_online, pos.tracker.source_online | `common.surface.online` |
| Continuer | 4 | label.continue, button.continue, kiosk.wizard.abandon_continue, kiosk.consent.cta_accept | `common.action.continue` |

**RECOMMENDATION:** V1.0.x cycle — introduce `common.*` canonical namespace with action / surface / order_type / status subspaces. Migrate ~250 keys to canonical. Keep old keys as aliases for 1 release for safety, then deprecate.

---

### LIST C — HARDCODED-STRINGS (V1.0.X heal recommendations)

**Total hardcoded user-facing strings detected: 5 in resources/js (admin only) + 5+ in compiled `public/js/pos-app.js` (Google Maps geocoding).**

| File | Line(s) | Hardcoded text | V1 blocker? | Recommendation |
| ---- | ------- | -------------- | ----------- | -------------- |
| `resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue` | 65 | "Encaisser & Valider (Kiosk)" | NO (admin=FR sentinel) | V1.0.x: replace with `$t('admin.online_orders.cash_validate_kiosk')` |
| `resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue` | 468 | `placeholder="Nom du client"` | NO | V1.0.x |
| `resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue` | 476 | `placeholder="Téléphone"` | NO | V1.0.x |
| `resources/js/components/admin/dashboard/ChannelStatsComponent.vue` | 4 | "Répartition par Canal (Aujourd'hui)" | NO | V1.0.x |
| `public/js/pos-app.js` | 78, 136, 179, 15467, 56039, 56108, 61092, 61161 | `alert('The Geolocation service failed.')` | NO (Google Maps callback, rare) | V1.0.x: route through `$t('admin.geocoding.failed')` |

**POSITIVE finding (kiosk discipline):** ZERO hardcoded user-facing strings in `resources/js/components/frontend/kiosk/*.vue`. The only match was a CSS comment `/* Confirmer */` in `KioskPaymentComponent.vue:1155` — not rendered. Kiosk i18n discipline is EXEMPLARY.

**POSITIVE finding (XSS):** ZERO raw HTML tags in any value of fr.json/en.json/ar.json (`grep -E '<[a-z]+' resources/js/languages/*.json` = 0 results). i18n interpolation surface is safe from injection at translation layer.

---

### LIST D — KEY-NAMESPACE-DRIFT (recommendation)

#### D.1 — Empty-string-key structural bugs (P1)
Three `"":...` entries inside nested namespaces in `resources/js/languages/fr.json` produce trailing-dot flat keys that collide with their parent namespace and risk leaking to UI:

1. **fr.json:287** — `""` inside `menu` namespace, value `"Menu"` → flat key `menu.`
2. **fr.json:656** — `""` inside `label` namespace, value `"Libellé"` → flat key `label.` (collides with the 261-key `label.*` namespace)
3. **fr.json:1459** — `""` inside `kiosk.filters` namespace, value `"Tous"` → flat key `kiosk.filters.` (and `$t(\`kiosk.filters.${f}\`)` with empty `f` would hit it)

**Remediation:** rename the empty-string keys to explicit names (e.g. `menu.label`, `label.label`, `kiosk.filters.all`) preserving the existing values. Single-commit, FR-only fix; ~3 lines edited. EN/AR don't have this bug (verified).

#### D.2 — Cross-locale drift
- FR \ EN: 75 keys present in FR missing in EN (mostly `label.cash_session_*`, `kiosk.promo.*`, `kiosk.consent.privacy_body`)
- EN \ FR: 194 keys present in EN missing in FR (mostly Bangladesh legacy: `label.bkash_*`, `label.easypaisa_*`, `label.cashfree_*`, `label.clickatell_*`, `label.flutterwave_*`, `label.bulksmsbd_*`)
- FR \ AR: 223 keys present in FR missing in AR (includes the entire `admin.observability_outbox.*` namespace + `label.cash_session_*`)
- AR \ FR: 192 keys present in AR missing in FR (Bangladesh legacy, same family as EN\FR)

**Verdict:** EN+AR locales are NOT V1-activatable. Defer to V1.0.x for EN (Le Cayenne probably FR-only V1) and V1.x for AR (RTL not audited).

#### D.3 — Namespace organization smell
- `label.*` is a 261-key catch-all (pagination, forms, kiosk admin, KDS, POS, fiscal labels, delivery cash) → recommend split into `pagination.*`, `kds.*`, `pos.*`, `fiscal.*`, `delivery.*` subspaces with `common.*` for truly shared atoms.
- `lang/fr/all.php` is a 226-key god-namespace → split into `lang/fr/admin.php`, `lang/fr/fiscal.php`, `lang/fr/payment.php` for maintainability.
- 10/16 PHP namespace files (`lang/fr/{addressType,itemType,orderStatus,orderType,payment_gateway,payment_status,pos_payment_method,statuse,discount_types,ask}.php`) are EMPTY shells from Bangladesh template — recommend delete to reduce dead files.

#### D.4 — Identical fr=en (170 entries)
The tool flagged 170 keys where FR text equals EN text. Triage:
- **~60 legitimate**: brand/proper nouns ("Ketchup", "Mayonnaise", "Edenred · Swile · Sodexo", "Exception"), numeric placeholders ("06 12 34 56 78"), template `({n}s)`.
- **~40 legitimate interpolation**: `{item}`, `{count}`, `{name}`-only values.
- **~70 genuine drift**: real French strings leaked into the EN slot (e.g. `a11y.skip_to_cart` = "Aller au panier" in EN, `kiosk.wizard.frites_sauce.ketchup` = "Ketchup" might be acceptable but `button.add_item` = "Ajouter un article" in EN is wrong).

**Recommendation:** V1.0.x — manual triage of 70-key subset when activating EN locale.

#### D.5 — Sentinel & CI gaps
- NO `tests/Feature/Sentinels/*I18n*Test.php` exists (verified).
- `tools/i18n/audit_locale_keys.mjs` not wired in `.github/workflows/` or `composer.json` scripts.

**Recommendation:** V1.0.x — create `I18nKeyIntegritySentinelTest.php` running the dormant tool and asserting `Undefined keys <= threshold` (currently 49 in FR; set threshold 50 then ratchet down). Wire `node tools/i18n/audit_locale_keys.mjs` into pre-push or CI smoke.

---

## 4. P0/P1/P2 Summary

| Priority | Count | Items |
| -------- | ----- | ----- |
| P0       | **0**     | none |
| P1       | **4**     | (1) fr.json empty-key trailing-dot bugs lines 287/656/1459 [Architect+UX+RED concur]; (2) `error.something_wrong` missing from fr.json (raw key leak in FR error paths) [RED-I18N-004]; (3) `label.kds_status_conflict` UNDEFINED — if KDS conflict state fires, chef screen leaks raw key [RED-I18N-002 subset]; (4) `admin.product_wizard.{title,subtitle,progress,finish}` UNDEFINED — admin product wizard renders raw keys [RED-I18N-002 subset] |
| P2       | **8**     | 240 high-confidence dead keys after dynamic filter; 253 redundant duplicate FR values; Bangladesh legacy 55 keys in en.json/ar.json; 10/16 PHP namespace empty shells; `audit_locale_keys.mjs` not in CI; no I18n sentinel; EN locale 98 missing $t keys (not activatable); AR locale 235 missing (not activatable, no RTL audit) |

### V1 Blocker assessment
**0 P0 → V1 GO** for Le Cayenne single-restaurant. Admin=FR sentinel + kiosk i18n discipline + 0 NF525 i18n surface (fiscal labels FR-only, no user-facing locale switch) = ship-ready.

### V1.0.x heal priority
1. Fix 3 empty-string keys in fr.json (1 commit, 3 lines) — Architect+UX+RED unanimous P1.
2. Add `error.something_wrong` to fr.json (1 commit, 1 line) — RED P1.
3. Add `admin.product_wizard.{title,subtitle,progress,finish}` to fr.json (1 commit, 4 lines).
4. Wire `audit_locale_keys.mjs` to pre-push hook + create `I18nKeyIntegritySentinelTest.php` (1 PR).
5. Bangladesh legacy purge from en.json/ar.json/bn.json/de.json (1 PR, ~55 keys × 4 locales = 220-line diff, save 6KB).

### V1.x backlog
6. Consolidate 253 redundant keys into `common.*` canonical namespace (3 PRs, careful with template-literal patterns).
7. EN locale activation: fill 98 missing $t keys + 70 untranslated copies + Bangladesh purge (1-2 weeks).
8. AR locale activation: fill 235 missing $t keys + RTL audit + Bangladesh purge (3-4 weeks, includes RTL CSS).

---

## 5. Deliverables in this Round

- `reports/audit/foundation-2026-05-18/round-1/F-7-I18N/architect.json` (1480 words)
- `reports/audit/foundation-2026-05-18/round-1/F-7-I18N/ux-a11y.json` (1100 words)
- `reports/audit/foundation-2026-05-18/round-1/F-7-I18N/red.json` (1450 words)
- `reports/audit/foundation-2026-05-18/round-1/F-7-I18N/_high_confidence_dead.txt` (240 keys after dynamic-filter)
- `reports/audit/foundation-2026-05-18/round-1/F-7-I18N/STATUS.md` (this file)

External pre-existing tool reports:
- `reports/i18n/dead_keys_VUE_2026-04-20.csv` (558 raw entries)
- `reports/i18n/dead_keys_LARAVEL_2026-04-20.csv` (277 raw entries)
- `reports/i18n/missing_keys_per_locale_VUE_2026-04-20.csv` (per-locale missing)
- `reports/i18n/missing_keys_per_locale_LARAVEL_2026-04-20.csv` (per-locale missing)
- `reports/i18n/identical_fr_en_VUE_2026-04-20.csv` (170 entries)
- `reports/i18n/identical_fr_en_LARAVEL_2026-04-20.csv` (137 entries)

---

## 6. User-Friendly Questions for Owner

1. **EN locale activation timeline?** Currently 98 missing $t() keys + 70 untranslated copies. If EN is V1.0.x target, allocate ~1 week of i18n work + axe-aria audit.
2. **Bangladesh-legacy purge approval?** 55 keys in en.json/ar.json/bn.json/de.json carry bkash/easypaisa/clickatell/etc. No Blade refers to them. Purge safe? (~220-line cleanup PR).
3. **Common-namespace consolidation appetite?** 253 redundant FR keys (Annuler ×10, Menu ×7, Réessayer ×7…). Migration is mechanical but touches many files. Worth a V1.0.x sprint?
4. **Should `bn.json` and `de.json` be deleted?** They have 413 and 412 missing $t() keys respectively. They were never V1 scope. Deletion reduces noise in CI reports.

---

## 7. KEEP working

This sub-agent operated in read-only mode for ~25 minutes wall-clock. No source files modified. The 4-list above feeds the F-7 owner-gate decision before any V1.0.x heal cycle starts.
