# PROPOSAL — PaymentComponent.vue:38-78 — Tab pattern incomplete: missing `aria-controls`, `role="tabpanel"`, `aria-labelledby`, keyboard arrow nav

**ID** : PROP-PAY-005
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The mode-picker nav at `resources/js/components/admin/pos/PaymentComponent.vue:38-78` declares :

```html
<nav class="pos-v4-payment-methods pos-v5-payment-methods pos-v5-payment-methods--3col" role="tablist">
    <button data-tab="#cash" type="button" role="tab" :aria-selected="paymentMode === 'cash'" ...>
    <button data-tab="#card" type="button" role="tab" :aria-selected="paymentMode === 'card'" ...>
    <button data-tab="#multi" type="button" role="tab" :aria-selected="paymentMode === 'multi'" ...>
</nav>
```

Then panels at lines 82, 109, 176 :

```html
<div id="cash" class="data-tab hidden" :class="paymentMode === 'cash' ? 'active' : ''">
<div id="card" class="data-tab hidden" :class="paymentMode === 'card' ? 'active' : ''">
<div v-if="paymentMode === 'multi'" id="multi" class="pos-v5-split-block">
```

**Missing from the ARIA tab pattern (WAI-ARIA 1.2)** :
- Tabs have `role="tab"` and `aria-selected` ✓
- Tabs LACK `aria-controls="<panel-id>"` ✗
- Panels LACK `role="tabpanel"` ✗
- Panels LACK `aria-labelledby="<tab-id>"` ✗
- Tabs LACK `tabindex` management — non-selected tabs should have `tabindex="-1"` so Tab key skips to the selected tab only ✗
- Container LACKS keyboard arrow nav — ArrowRight/ArrowLeft should move between tabs ✗
- The dialog uses `<nav>` for the tablist — `<nav role="tablist">` is acceptable but the `nav` landmark is then nested oddly (a nav inside a dialog modal). `<div role="tablist">` would be cleaner.

Without `aria-controls` + `role="tabpanel"`, screen readers cannot link tab → panel content. The cashier-with-AT presses the cash tab, no announcement of which panel is now active.

The third tab "multi" has `data-testid="pos-payment-mode-multi"` and emoji icon 🔀 with `aria-hidden="true"`. Accessible name resolves to label text `$t('label.split_payment') || 'Multi-paiement'` ✓ (accessibility name OK).

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
Same as PROP-004 — minimal direct impact.

### Cashier perspective
Sighted cashier : zero difference (tabs visually respond to clicks). Keyboard-only or AT cashier : tab announcement broken. Arrow nav between tabs not working.

### Owner perspective
Same RGAA / WCAG note as PROP-004. Tab pattern is a well-known pattern in ARIA spec — incomplete implementation flags in any a11y audit.

### Multi-tenant-future
V2 SaaS — same as PROP-004.

### Adversarial dispute (challenge yourself)
- **False positive ?** Verified : no `aria-controls` (grep PaymentComponent.vue → only `aria-labelledby` mentions are zero). No `role="tabpanel"`. Verified at file lines 38-78, 82, 109, 176.
- **Could the existing pattern work for sighted users ?** YES — visually the segmented control works. The bug is AT-only.
- **Scope of fix ?** Add `:aria-controls="'cash'"` (and `'card'`, `'multi'`) to each tab + `role="tabpanel"` + `:aria-labelledby="'tab-id'"` to each panel + tab id attributes. ~9 attributes added across 6 elements + maybe a keydown handler for arrow nav (`@keydown.arrow-right` / `@keydown.arrow-left`). 15-25 LOC.
- **Could be deferred ?** YES — borderline, kissue similar to PROP-004. Same call.

## Proposed change

### Minimum-viable patch (no arrow-key nav, just ARIA links — ≤15 LOC)

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 38-78 @@
                     <nav class="pos-v4-payment-methods pos-v5-payment-methods pos-v5-payment-methods--3col" role="tablist">
                         <button
+                            id="tab-cash"
                             data-tab="#cash"
                             type="button"
                             role="tab"
                             :aria-selected="paymentMode === 'cash'"
+                            aria-controls="cash"
+                            :tabindex="paymentMode === 'cash' ? 0 : -1"
                             ...
                         >
                         <button
+                            id="tab-card"
                             ...
                             :aria-selected="paymentMode === 'card'"
+                            aria-controls="card"
+                            :tabindex="paymentMode === 'card' ? 0 : -1"
                             ...
                         >
                         <button
+                            id="tab-multi"
                             ...
                             :aria-selected="paymentMode === 'multi'"
+                            aria-controls="multi"
+                            :tabindex="paymentMode === 'multi' ? 0 : -1"
                             ...
                         >
                     </nav>

@@ line 82 @@
-                <div id="cash" class="data-tab hidden"
+                <div id="cash" role="tabpanel" aria-labelledby="tab-cash" class="data-tab hidden"
                     :class="paymentMode === 'cash' ? 'active' : ''">

@@ line 109 @@
-                <div id="card" class="data-tab hidden"
+                <div id="card" role="tabpanel" aria-labelledby="tab-card" class="data-tab hidden"
                     :class="paymentMode === 'card' ? 'active' : ''">

@@ line 176 @@
                     v-if="paymentMode === 'multi'"
                     id="multi"
+                    role="tabpanel"
+                    aria-labelledby="tab-multi"
                     class="pos-v5-split-block"
```

Net : ~14 LOC. **Above 5-LOC LOCK threshold but still scope-minimal template-only.**

### Full WAI-ARIA tab-pattern patch (arrow-key nav + roving tabindex — 30+ LOC, DEFER-V1.0.2)

Adds keyboard listeners for ArrowRight/Left/Home/End + roving tabindex logic in the JS layer. Architectural.

## Risk analysis

| Scenario | Risk if minimum applied | Risk if NOT applied |
|----------|------------------------|---------------------|
| Sighted cashier | Zero | Zero |
| AT cashier | Improves announcement of tab/panel association | Cannot navigate tab → panel meaningfully |
| WCAG audit | Closer to AA on 4.1.2 Name/Role/Value | Failing 4.1.2 |
| Existing tests | No impact (purely attribute additions) | None |
| Bundle rebuild | Yes | None |
| Frozen-zone diff | +14 LOC template only | None |

## LOCK feasibility

- Minimum-viable (template-only, +14 LOC) : **YES** if owner accepts > 5 LOC LOCK threshold for a single concern. **BORDERLINE.**
- Full tab pattern (with roving tabindex JS) : **NO — architectural-ish.**

## Owner recommendation

[ ] APPLY-WITH-LOCK (minimum-viable, +14 LOC template attributes only)
[ ] DEFER-V1.0.2 (full keyboard tab navigation)
[ ] DEFER-V2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
