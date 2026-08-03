# Wave Polish — Re-Verification Audit

**Date** : 2026-05-21
**Baseline** : `ce09a44e9` (plan)  →  **Current HEAD** : `1116b3957`
**Verifier** : Independent (read-only)
**Scope** : Re-measure each Wave Polish item against current tree to detect drift from parallel session fixes.

---

## Item-by-item verdict

### Item 1 — EN catalog gap "112 keys missing"

**STATUS** : **STILL-OPEN (confirmed exactly)**

- Flattened deep-key count : `fr.json` = **1875**, `en.json` = **1939**, missing in EN = **112**.
- The fix shipped by the parallel session touched `lang/en/all.php` (PHP catalog, 4 `delivery_cash_*` keys at lines 173-176). That file is read by Laravel Blade, **not** by Vue components.
- Vue components (e.g. `CashOverviewComponent.vue` line 18 `$t('menu.cash_overview')`) read from `resources/js/languages/en.json` — which is **untouched** since `d424f8402` (commit "wave-z-5c"). 0 `delivery_cash` keys in `resources/js/languages/*.json`.
- Plan number stands : **112**.

**Severity** : P0 — every admin page that opens with `?lang=en` leaks raw keys.

---

### Item 2 — AR catalog gap "263 keys missing"

**STATUS** : **STILL-OPEN (confirmed exactly)**

- `ar.json` = **1786** flat keys, missing in AR = **263**.
- Same root cause as Item 1. PHP `lang/ar/all.php` fix (if any) does not propagate to Vue.

**Severity** : P0 (AR is announced as a Le Cayenne target language).

---

### Item 3 — Cash Overview EN/AR breakage

**STATUS** : **STILL-OPEN (REVISED count)**

- 33 distinct `$t('xxx')` calls in `resources/js/components/admin/cashOverview/CashOverviewComponent.vue`.
- Missing in FR : **0**.
- Missing in EN : **21** (plan implied ~10 examples; actual is 21).
- Missing in AR : **22** (plan said "magnifié", confirmed).

Confirmed missing in EN :
```
label.all_methods            label.cash_overview_capped_notice    label.mode_card
label.all_sources            label.drawer_opened_at               label.mode_cash
label.breakdown_by_method    label.grand_total                    label.mode_mobile
label.cash_drawer_reconciliation                                  label.mode_other
                                                                  label.mode_ticket
label.no_data_available      label.opening_amount                 label.order_number
label.source_borne           label.source_caisse                  label.source_livreur
label.time                   label.transactions_short             menu.cash_overview
```

Plan was partially inaccurate on specific keys :
- Plan claimed `label.cash_collected_today` missing → **actually PRESENT** in en.json ("Cash collected today").
- Plan claimed `label.expected_in_drawer` missing → **actually PRESENT**.
- Plan claimed `label.cash_drawer_count_pending_note` missing → **actually PRESENT**.

**Severity** : P0 — page leaks ~21 raw labels in EN view.

---

### Item 4 — Wave Y `{seconds}` placeholder drift

**STATUS** : **OVERSTATED**

- `error.rate_limited` exists with `{seconds}` placeholder in **all three** catalogs :
  - FR : "Trop de requêtes — patientez **{seconds}**s avant de réessayer."
  - EN : "Too many requests — wait **{seconds}**s before retrying."
  - AR : "طلبات كثيرة جدًا — انتظر **{seconds}** ثانية قبل المحاولة مجددًا."
- The Wave Y commit (`2e2400724`) propagated to all 3 languages. Plan claim "FR-only" is wrong.

**Severity** : non-issue.

---

### Item 5 — POS PosComponent hardcoded FR strings

**STATUS** : **STILL-OPEN (REVISED count : 12, not 11)**

- The close-button aria-label fix on `PosComponent.vue:1126` only touched ONE button; all the body strings remain hardcoded.
- Confirmed hardcoded strings (non-comment, in rendered template) :

| Line | Snippet | File |
|------|---------|------|
| 380  | `aria-label="Catégories"` | PosComponent.vue |
| 810  | `<p>Aucun article. Sélectionnez un produit dans la grille.</p>` | PosComponent.vue |
| 1110 | `<h3>🖥️ Commandes borne — à encaisser</h3>` | PosComponent.vue |
| 1134 | `Aucune commande borne en attente.` | PosComponent.vue |
| 1161 | `+{{ … }} autres` | PosComponent.vue |
| 1176 | `<strong>Variations:</strong>` | PosComponent.vue |
| 1180 | `<strong>Extras:</strong>` | PosComponent.vue |
| 1184 | `<strong>Instructions:</strong>` | PosComponent.vue |
| 1188 | `<strong>Allergenes:</strong>` (typo : missing accent) | PosComponent.vue |
| 1223 | `{{ order._collecting ? '…' : '✓ Encaisser' }}` | PosComponent.vue |
| 1230 | `{{ order._canceling ? '…' : 'Annuler' }}` | PosComponent.vue |
| 1236 | `<button … >↻ Actualiser</button>` | PosComponent.vue |

Plan said 11. Actual : **12** (Catégories aria-label, Aucun article on 810, plus 10 in the kiosk-cash panel block). Mis-spelling `Allergenes` (sic) still present.

**Severity** : P1 — these strings appear in the central POS surface every time a kiosk-cash order is rendered. EN cashier sees raw FR.

---

### Item 6 — KDS status badge contrast

**STATUS** : **STILL-OPEN (REVISED severity)**

- `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue` lines 379-388 :
  ```css
  .kds-history-drawer__item { border-left: 4px solid #888; background: #fafafa; }
  .is-prepared  { border-left-color: #1e88e5; }
  .is-out       { border-left-color: #fb8c00; }   /* ≈ 2.4:1 vs #fafafa — fails WCAG 1.4.11 */
  .is-delivered { border-left-color: #2e7d32; }   /* ≈ 4.7:1 — OK */
  ```
- The border-left is **non-text decorative** — WCAG 1.4.11 (Non-text Contrast) requires 3:1. The orange `#fb8c00` measures ~2.4:1 against the `#fafafa` background → fails. Default `#888` ≈ 3.0:1 (borderline). Blue ≈ 3.5:1 OK.
- Plan said "3:1 → 4.5:1" — that's the AA text rule; the actual applicable rule is 1.4.11 (3:1). The orange OUT badge is the real WCAG fail; the rest borderline.

**Severity** : P2 (single status color sub-3:1; not a text contrast issue).

---

### Item 7 — formatMoneyEuro global propagation

**STATUS** : **STILL-OPEN (confirmed)**

- `CashOverviewComponent.vue:380-393` defines `formatMoneyEuro` with `Intl.NumberFormat … currency: EUR`.
- It is used at lines 133, 140, 147 — **reconciliation card only**.
- Aggregate cards (line 94) and table cells (line 230) use plain `formatMoney(v)` (line 374) which returns `n.toFixed(2)` with **no currency symbol**.
- The author left a comment (line 379) acknowledging this scoping decision : *"Plain `formatMoney` kept untouched … to minimise visual diff outside the reconciliation strip."*

**Severity** : P2 (visual polish).

---

### Item 8 — Cash Overview empty-state

**STATUS** : **STILL-OPEN (confirmed)**

- `CashOverviewComponent.vue:184-191` :
  ```html
  <div v-else-if="!transactions.length" class="p-6 text-center text-gray-500" data-testid="cash-overview-empty">
      {{ $t('label.no_data_available') }}
  </div>
  ```
- One-liner copy, no illustration, no reset CTA, no help text.

**Severity** : P2 (UX polish).

---

### Item 9 — POS shortcuts empty-state

**STATUS** : **STILL-OPEN (REVISED nature)**

- `PosComponent.vue:262-353` wraps the entire shortcuts strip with `v-if="readyOrders.length > 0 || kioskCashOrders.length > 0"`.
- When both arrays are empty → the entire DOM region is removed (no empty-state at all). Cashier sees nothing — not even a "no current pending orders" hint.
- Plan called for a copy line ; reality is **nothing displays at all**. Plan finding is correct, severity is the same.

**Severity** : P2.

---

### Item 10 — URL filter sync Cash Overview

**STATUS** : **STILL-OPEN (confirmed)**

- 0 occurrences of `router.push`, `router.replace`, `$route.query` in `CashOverviewComponent.vue`.
- Filters live in component state (`filters: { from, to, source, mode }` at line 261). Refresh / share-link loses state.

**Severity** : P2.

---

### Item 11 — `mode=other` silent no-op

**STATUS** : **REVISED**

- The mode `<select>` (lines 47-53) offers `cash / card / mobile / ticket` ONLY. No `other` option in the dropdown.
- `modeLabel('other')` exists in the data renderer (line 368) — used to label a column when the backend returns `mode_bucket=other`.
- Real defect : **user cannot filter on the "other" bucket** even though the backend supports it. Not a silent no-op — it's a gap in the filter UI.
- Plan framing was imprecise. The underlying gap is real.

**Severity** : P3 (rare bucket; filter completeness).

---

### Item 12 — Counter-collect modal numpad below-fold

**STATUS** : **STILL-OPEN (confirmed)**

- `PosCounterCollectModal.vue` contains **only 1** `@media` rule (line 518, font-size only).
- Modal has `max-height: 92vh` (line 436). On a 720p screen with the operator OS chrome, the numpad can land below the fold without a media query to compact it.

**Severity** : P2 (depends on operator screen — typical kiosk display is 1080p ok, but a 720p back-office monitor could clip).

---

### Item 13 — Modal close aria-label

**STATUS** : **FIXED-BY-DRIFT** (both surfaces now done)

- `PosCounterCollectModal.vue:53` : `:aria-label="$t('button.close')"` ✓
- `PosComponent.vue:1126` : `:aria-label="$t('button.close')"` ✓ (parallel session)
- Item closed.

**Severity** : closed.

---

### Item 14 — KDS history drawer focus-visible after close

**STATUS** : **STILL-OPEN (REVISED — half done)**

- Escape handler IS now installed in `KdsHistoryDrawer.vue:189-201` (parallel session fix). Escape emits `@close`.
- `KitchenDisplaySystemComponent.vue:8-21` has the trigger button with `:focus-visible` styling defined (KDS file line 2409 — yellow outline).
- **MISSING** : no logic returns focus to the trigger button after `@close`. Once the drawer dismisses, focus drops to `<body>`. A keyboard user must Tab around to reach the trigger again. To meet WAI-ARIA dialog pattern (WCAG 2.1.2), focus should return to the invoking element.

**Severity** : P2 (a11y — keyboard-only chef).

---

## Summary

### Revised count : 11 of 14 items still actionable

| # | Status | Severity |
|---|--------|----------|
| 1  | STILL-OPEN | P0 |
| 2  | STILL-OPEN | P0 |
| 3  | STILL-OPEN (revised : 21 EN / 22 AR, not "112/263") | P0 |
| 4  | OVERSTATED — already fixed in all 3 langs | — |
| 5  | STILL-OPEN (revised : 12 strings, not 11) | P1 |
| 6  | STILL-OPEN (revised : WCAG 1.4.11 non-text, not 1.4.3 text) | P2 |
| 7  | STILL-OPEN | P2 |
| 8  | STILL-OPEN | P2 |
| 9  | STILL-OPEN | P2 |
| 10 | STILL-OPEN | P2 |
| 11 | REVISED (no `other` option in dropdown, not "no-op") | P3 |
| 12 | STILL-OPEN | P2 |
| 13 | **FIXED-BY-DRIFT** | — |
| 14 | STILL-OPEN (Escape ✓, focus-return ✗) | P2 |

### Items inadvertently closed by parallel session
- **Item 13** — `PosComponent.vue:1126` aria-label.
- **Item 14 partial** — Escape handler in `KdsHistoryDrawer.vue` (focus-return still pending).

### Items the parallel session created false-positive belief in
- The `lang/en/all.php` + `lang/fr/all.php` `delivery_cash_*` fix targets the **Blade / Laravel PHP catalog** — used by Blade templates and `__('all.label.…')` calls. It does **not** propagate to the Vue runtime, which reads `resources/js/languages/*.json`. Items 1, 2, 3 are entirely unchanged on the Vue side.

### Items confidently real and isolated
- Items 1, 2, 3, 5, 7, 8, 9, 10, 12, 14.

### Items overstated by the original plan
- Item 4 (rate-limit `{seconds}` already in EN+AR).
- Item 11 (framing wrong, real issue smaller).
- Item 13 (already done).
- Item 6 (referenced wrong WCAG rule; severity drops from P1 to P2).
- Item 3 (count was an upper-bound from the global 112/263 number; real surface-level leak is 21 EN / 22 AR keys).

### Final verdict on Wave Polish plan accuracy

The plan was **directionally correct (12 of 14 items remain real)** but **numerically loose** :
- Headline numbers (112 / 263) are accurate at the global catalog level.
- "Cash Overview unusable in EN" claim is materially correct — 21 keys leak, including `label.grand_total`, `label.source_borne`, and the entire mode/source enum.
- Plan over-counted 1 item (#4 — already healed by Wave Y across 3 langs), and 1 already-shipped (#13).
- Plan got the **WCAG rule wrong** for the KDS badge (it's a non-text 1.4.11, not the 1.4.3 text rule cited).
- 2 framings need rewording (#3 specific keys, #11 underlying defect).

**Net** : Wave Polish remains a valid micro-cycle. Effort estimate stays roughly accurate (~3-4h α / ~3-4h β / ~3-4h γ). Two items (4, 13) drop out. Plan should be re-scoped to 12 items.

---

**EOF — Re-verification complete.**
