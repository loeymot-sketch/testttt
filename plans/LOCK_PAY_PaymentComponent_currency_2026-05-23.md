# LOCK Plan — D3 PaymentComponent.vue Hero Amount FR Currency Format

**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : none (this is the FIRST LOCK exception proposed for this file)
**Owner decision** : D3=`lock-fix` (2026-05-23 via goal.html owner choices)

## Finding (read-only audit, evidence-based)

PaymentComponent.vue hero amount renders `4.90€` (point decimal + no nbsp + glued €) instead of the FR canonical format `4,90 €` (virgule + nbsp + €). Surfaced by Wave Final 2026-05-23 S2-002 P3 pre-existing.

**Evidence from this read-only audit** : `resources/js/components/admin/pos/PaymentComponent.vue:25-31` :

```html
<div class="pos-v4-payment-total-card pos-v5-payment-total-card">
    <p class="pos-v5-payment-total-label">{{ $t('label.total_amount') }}</p>
    <p class="pos-v5-payment-total-value pos-v5-tabular">{{
        currencyFormat(props.form.total,
            setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
            setting.site_currency_position)
    }}</p>
</div>
```

The local method `currencyFormat` (line 535-537) delegates to `appService.currencyFormat` :

```js
// PaymentComponent.vue:535
currencyFormat: function (amount, decimal, currency, position) {
    return appService.currencyFormat(amount, decimal, currency, position);
},
```

Which in `resources/js/services/appService.js:71-77` does :

```js
currencyFormat(amount, decimal, currency, position) {
    if (position === currencyPositionEnum.LEFT) {
        return currency + parseFloat(amount).toFixed(decimal);
    } else {
        return parseFloat(amount).toFixed(decimal) + currency;
    }
},
```

`parseFloat(amount).toFixed(decimal)` produces `4.90` (point) and `+ currency` produces `4.90€` (no separator, no nbsp). This is the documented Wave Final S2-002 P3.

**Comparison with canonical FR helper** (CashOverviewComponent.vue:509-522, Wave Polish Q5) :

```js
formatMoney(v) {
    const n = Number(v || 0);
    try {
        const locale = (this.$i18n && this.$i18n.locale) || 'fr-FR';
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: 'EUR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(n);
    } catch (e) {
        return `${n.toFixed(2)} €`;
    }
},
formatMoneyEuro(v) {
    return this.formatMoney(v);
},
```

This produces `4,90 €` (Intl.NumberFormat fr-FR canonical) — matching the AFNOR NF Z11-001 expectation. The Cash Overview surface uses this everywhere post Wave Polish Q5. The lone outlier is PaymentComponent.

Surfaces affected (template references found in PaymentComponent.vue) :
- Line 27-31 — Hero "À encaisser" total card (PRIMARY — the "moment de vérité" comment line 23)
- Line 186-188 — Split summary "Couvert" (data-testid `pos-payment-total-covered`)
- Line 203-205 — Split summary "Reste dû" (data-testid `pos-payment-remaining-due`)
- Line 219-221 — Split summary "Monnaie totale" (data-testid `pos-payment-total-change`)

All four call-sites use the same `currencyFormat` method delegating to `appService.currencyFormat`.

## Reasoning fort (multi-perspective)

### Chef perspective
Indirect — chef does not see PaymentComponent in normal workflow. No impact.

### Client perspective
The customer at the kiosk borne completes the wizard, then sees the payment screen. The amount displayed in `4.90€` format breaks the FR brand expectation. French customers expect `4,90 €` per AFNOR NF Z11-001. The friction is small but persistent — every transaction surfaces this micro-inconsistency on the most visible numeric on the screen (48px monospace tabular hero — explicit "moment de vérité" comment line 23-24).

### Cashier perspective
When the cashier views a kiosk-paid order from the POS, they see this amount briefly during transition between screens. The format mismatch vs Cash Overview (where `formatMoneyEuro` was applied Wave Polish Q5) makes the system feel inconsistent — degrades trust under stress. Worse, the split-payment subtotal rows ("Couvert", "Reste dû", "Monnaie totale") use the SAME unformatted helper, so a multi-tender transaction shows `42.30€ / 17.70€ / 0.00€` — every row glued, every row point-decimal — while the Cash Overview opened in a neighboring tab shows `82,50 €`.

### Owner perspective
Cash Overview now uses `8,50 €` everywhere (Wave Polish Q5). Receipts FR-format. The lone outlier is PaymentComponent — and it's the most visible screen at end of every transaction. Owner aesthetic mandate `lock-fix` reflects this isn't optional once spotted.

### Multi-tenant-future
For V2 SaaS multi-resto, this format is FR-locale-specific. The LOCK applies in FR contexts only — if EN/AR catalogs are activated for other restaurants, the formatter must already handle that. `Intl.NumberFormat(locale, {style:'currency', currency:'EUR'})` is locale-aware (en-GB → `€4.90`, ar-MA → `٤٫٩٠ €`), so adopting the Cash Overview pattern is multi-tenant-safe. The `appService.currencyFormat` helper as it stands today is NOT locale-aware — it only honors a static `site_currency_position` setting that does not encode separator/nbsp rules.

### Adversarial dispute (challenge yourself)
- **False positive ?** NO — explicit owner D3=`lock-fix` decision (2026-05-23 via goal.html owner choices). Bug also documented Wave Final S2-002 P3 (pre-existing).
- **Goal cares ?** YES — V1 single-resto FR brand polish mandate, owner explicitly named the "4.90€ → 4,90 €" example.
- **Scope-minimal ?** YES — single-method rewrite of `currencyFormat` in PaymentComponent.vue (line 535-537) to delegate to Intl.NumberFormat fr-FR. ≤8 LOC (the method body), zero template change required since the four call-sites already invoke `currencyFormat(amount, ...)`. Alternative: add a brand new local `formatMoneyEuro` helper and edit each of the 4 call-sites — more invasive but cleaner separation. **Recommendation: rewrite the method body** since the existing call-site signature is consistent across all 4 surfaces and the rewrite preserves the function name.
- **Persona impact ?** Client + Owner + Cashier (split surfaces). Chef + Multi-tenant-future neutral / positive.
- **Owner mandate respect ?** YES — `lock-fix` = LOCK doc + apply post countersign. This doc is the first half.
- **Could we fix `appService.currencyFormat` globally instead ?** NO — appService.currencyFormat is called from 8+ files (PaymentComponent, PosComponent, CheckoutComponent, ItemComponent kiosk + table + admin, CouponComponent, table/CheckoutComponent). A global rewrite of appService would be cross-surface impact requiring its own ultra-audit + heal cycle, and would change the contract of a service used by non-frozen files too. The LOCK is scoped to PaymentComponent ONLY per owner D3.

## Proposed change

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 535-537 @@
-        currencyFormat: function (amount, decimal, currency, position) {
-            return appService.currencyFormat(amount, decimal, currency, position);
-        },
+        // [LOCK-PAY-D3 2026-05-23] Owner-mandated FR currency format (D3 lock-fix).
+        // Hero amount + split-summary rows now render `4,90 €` (Intl.NumberFormat
+        // fr-FR, virgule + nbsp + €) instead of `4.90€` (point + glued €). Mirrors
+        // CashOverviewComponent.formatMoney (Wave Polish Q5). The `decimal`,
+        // `currency`, `position` args are accepted for back-compat with the
+        // 4 template call-sites but ignored — Intl.NumberFormat honors locale +
+        // EUR canonical separators directly. Multi-tenant-safe: locale falls back
+        // to fr-FR when $i18n is unset (production V1 Le Cayenne FR).
+        currencyFormat: function (amount /* decimal, currency, position */) {
+            const n = Number(amount || 0);
+            try {
+                const locale = (this.$i18n && this.$i18n.locale) || 'fr-FR';
+                return new Intl.NumberFormat(locale, {
+                    style: 'currency',
+                    currency: 'EUR',
+                    minimumFractionDigits: 2,
+                    maximumFractionDigits: 2,
+                }).format(n);
+            } catch (e) {
+                return `${n.toFixed(2)} €`;
+            }
+        },
```

Total diff : ≈18 lines added (8 LOC body + 10 LOC documentation comment), 3 LOC removed. NET +15 lines, all inside the existing `currencyFormat` method block. Zero template change. Zero import change (Intl is global, this.$i18n already exists via Vue i18n plugin).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Production-real cash transaction | LOW — visual format change only, NO arithmetic change, totals math identical | LOW — micro friction persists |
| NF525 audit | NONE — no chain impact, no fiscal write, no Order/audit_log mutation | NONE |
| Split-payment math | NONE — Intl.NumberFormat formats the SAME `totalCoveredEur` / `remainingDueEur` / `totalChangeEur` values already computed by `splitFromCents` (cents-source-of-truth, helper untouched) | NONE |
| V2 SaaS migration | LOW — Intl.NumberFormat is locale-aware out of the box | MEDIUM — would need second LOCK for V2 |
| Sentinel `paymentComponentEmitsJsdocList` | NONE — no emits change, no JSdoc change | NONE |
| Existing PaymentComponent tests | MUST RE-CHECK — any tests asserting the literal string `"4.90€"` will need updating to `"4,90 €"`. Sub-agent applier must run `npx vitest run tests/js/**/Payment*.spec.js` BEFORE commit and adjust assertions if they hit a now-formatted string. | NONE |
| Bundle freshness Q12 | MANDATORY rebuild via `npx mix` post-apply | NONE |
| Other 4 call-sites in same file | None — they invoke `currencyFormat(totalCoveredEur, ...)`, `currencyFormat(remainingDueEur, ...)`, `currencyFormat(totalChangeEur, ...)` — all three positional args ignored in new body, function signature compat preserved | NONE |
| Receipt printing | NONE — receipts render server-side via ReceiptService (PHP), unaffected by this Vue change | NONE |
| Customer-facing display (kiosk borne) | NONE — kiosk uses KioskWizardComponent.vue (different file, frozen separately), not PaymentComponent | NONE |

## LOCK feasibility

- Single-method body rewrite (≤18 LOC including doc comment). YES viable.
- Pure presentational formatter change. SQL byte-equivalent N/A (no SQL).
- Arithmetic byte-equivalent : YES — `Number(v||0)` ≡ `parseFloat(v)` for our value range (positive decimals from `splitFromCents` cents math); Intl.NumberFormat fr-FR EUR with 2-decimal min/max produces the same numeric value (`4.90` → `4,90`) just with different separator/nbsp/symbol-position.
- Reversible via single `git revert`. Recovery time ≤30s.
- Bundle rebuild required (`npx mix`) — Q12 sentinel mandate.

## Owner recommendation

[ ] APPLY-WITH-LOCK — countersign this doc, then sub-agent applies the method rewrite + sentinel verification + bundle rebuild + commit
[ ] DEFER-V1.0.2 — V1 ships with `4.90€` format (consistent with previous V1 acceptance pre Wave Polish Q5 — note: post Q5, Cash Overview is `4,90 €` so deferring means living with the inconsistency)
[ ] DEFER-V2 — wait for multi-resto SaaS context
[ ] KEEP-AS-IS — frozen for a reason, owner accepts the format mismatch

**Signed-off-by-owner** : ___________  **Date** : ___________

## How to apply (if owner approves)

1. **Verify LOCK doc countersigned** — the "Signed-off-by-owner" line above must be filled with owner name + date. The orchestrator's frozen-zone diff scan blocks any commit touching PaymentComponent.vue without this doc countersigned (`feedback_frozen_zone_override_2026-05-06.md` discipline).
2. **Pre-audit** : capture screenshot of current `4.90€` hero (POS modal "À encaisser"), save to `reports/diff-audits/D3-pre.png`. Capture also a split-payment scenario showing the "Couvert / Reste dû / Monnaie totale" rows.
3. **Apply diff** scope-minimal :
   - Edit `resources/js/components/admin/pos/PaymentComponent.vue` line 535-537 only.
   - Replace the 3-line delegating method body with the 18-line Intl.NumberFormat body shown in "Proposed change" above.
   - DO NOT touch any template line (25-31, 186-188, 203-205, 219-221) — the call-site signature remains compatible.
   - DO NOT touch any import line (332-362) — Intl is global, this.$i18n is already available.
4. **Bundle rebuild** : `npx mix` (Q12 sentinel mandate). Verify `public/mix-manifest.json` updated.
5. **Post-audit** : re-screenshot, diff verify only the intended method body changed.
6. **Sentinel re-run** :
   - `npx vitest run tests/js/sentinels/paymentComponentEmitsJsdocList.spec.js` — must stay GREEN (we change body only, not emits/JSdoc list).
   - `npx vitest run tests/js/components/admin/pos/PaymentComponent.spec.js` (if exists) — must stay GREEN.
   - If any test asserts the literal `"4.90€"` string, update the assertion to `"4,90 €"` (with nbsp ` `, NOT a normal space — Intl.NumberFormat fr-FR uses U+00A0 NO-BREAK SPACE between the number and €).
7. **Frozen-zone diff verify** : `git diff main -- resources/js/components/admin/pos/PaymentComponent.vue` must show ONLY the intended method body change (and nothing else). `git diff main -- resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` must show no NEW lines (baseline diff preserved). Both numbers must match the pre-apply baseline + 15 net lines for PaymentComponent.vue ONLY.
8. **Commit** :

   ```
   feat(lock-pay-d3): PaymentComponent hero € FR format — countersigned 2026-05-XX

   LOCK doc: plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md
   Owner countersign: <date>

   Rewrites the local currencyFormat() method body to delegate to
   Intl.NumberFormat fr-FR EUR so the customer + cashier see
   "4,90 €" (FR comma + nbsp + €) instead of "4.90€" (point + glued €) on
   the payment screen hero amount AND the split-payment summary rows
   (Couvert / Reste dû / Monnaie totale). Mirrors Cash Overview
   (Wave Polish Q5) + counter-collect (Wave Final D2).

   The 4 template call-sites are unchanged — the function signature
   `currencyFormat(amount, decimal, currency, position)` is preserved
   (extra args ignored, Intl.NumberFormat honors locale + EUR canon).

   Source: owner D3 decision 2026-05-23.
   Bundle rebuilt via `npx mix`.

   Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
   ```

9. **Final frozen-zone diff verify** : `git diff main -- resources/js/components/admin/pos/PaymentComponent.vue` must show ONLY the intended method body change (and the V5 design refactor delta already on the branch). No other §7 file touched (verify via `git diff main -- $(git ls-files | grep -E 'PaymentComponent|PosV5TrancheRow|FiscalSequence|ZReport|AuditLog|BranchScope|IdempotencyKey|PricingService|OrderStateMachine|KioskWizardComponent|KioskAppComponent|KioskUpsellComponent|pos-wizard\.(js|css)|admin-pos-v4\.blade')` is empty for unrelated files).

## Status

**CURRENT STATUS** : `DRAFT — AWAITING OWNER COUNTERSIGN`

Per CLAUDE.md §7+§10 and frozen-zone discipline (`feedback_frozen_zone_override_2026-05-06.md`), the sub-agent applier MUST verify the countersign line is filled BEFORE editing PaymentComponent.vue. The orchestrator's frozen-zone diff scan will block any commit touching PaymentComponent.vue without this doc countersigned.

LOCK DOC AGENT A.3 wrote this doc only — `resources/js/components/admin/pos/PaymentComponent.vue` is **UNTOUCHED** in this agent run. Verification command:

```bash
git diff main -- resources/js/components/admin/pos/PaymentComponent.vue | wc -l
# Pre-apply baseline (V5 design refactor already on branch): 1625 lines
# Post LOCK-DOC-A.3 (this commit): MUST still equal 1625 lines
```

Same for `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` (baseline 358 lines).
