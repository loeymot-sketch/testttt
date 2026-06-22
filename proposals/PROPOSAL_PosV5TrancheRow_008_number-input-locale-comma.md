# PROPOSAL — PosV5TrancheRow — `type="number"` + French-comma decimals on tablet (cashier-typed `12,50` → empty)

**ID**: PROP-PosV5TrancheRow-008
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P2** (input rejection on French-locale tablets; cashier-multitask persona impact)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

Amount + tendered inputs at lines 42-52 / 59-69:

```html
<input
  :id="amountId"
  type="number"
  inputmode="decimal"
  step="0.01"
  min="0"
  class="pos-v5-tranche-row__input pos-v5-tabular"
  :value="tranche.amount"
  ...
  @input="onAmountInput"
/>
```

And the handler at line 199-202:

```js
onAmountInput(e) {
    const v = e.target.value;
    this.$emit('update', { amount: v === '' ? 0 : Number(v) });
},
```

### Tablet / French-locale interaction

`type="number"` in browsers respects the **OS locale** for decimal separator on iPad/iOS Safari, BUT:
- On French-locale iOS, the keypad shows comma `,` as the decimal key.
- The cashier types `12,50`.
- For `type="number"`, the browser **invalidates the input** when it doesn't parse as a number per the W3C parse algorithm — which expects a dot `.` decimal separator regardless of locale.
- `e.target.value` returns `""` (empty string) when the input is invalid.
- The handler interprets `""` as `0`.

**Result**: cashier types `12,50`, the field appears to clear, the patch emits `amount: 0`, validation fires "Le montant de la tranche est requis". Cashier confused. This is the actual W3C-spec behavior of `type="number"` per the HTML Living Standard §4.10.5.1.13.

### Real-world evidence

This is a known cross-platform Safari/Firefox/Chrome behavior. The mitigation in production POS systems is either:
- (a) Use `type="text" inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*"` and normalise on input
- (b) Use a numpad component (the codebase has `PosV5Numpad.vue` already)
- (c) Use a locale-aware input mask

### Personas impacted
- **Cashier-multitask on French iPad** (HIGH — primary persona, primary device for Le Cayenne hospitality model)
- **Cashier-multitask on French-keyboard PC** (LOW — desktop keyboard layout independent; user types `.` deliberately)

The kiosk wizard uses the V5 numpad (`PosV5Numpad.vue`) explicitly to avoid this — POS payment modal should benefit similarly.

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
The bug is hit the first time a French cashier instinctively types comma. They lose seconds, may mis-confirm with `amount: 0`, fail validation, retype. Repeat = real-world friction.

### Owner perspective
Le Cayenne uses what device? If it's desktop with keyboard, low impact. If iPad on the counter, high impact. Verification needed.

### Multi-tenant-future
V2 SaaS — many tenants will be French QSR using iPad POS. Same risk.

### Adversarial dispute
- **False positive?** Possible — modern iOS Safari (≥14) auto-converts comma to dot in some configurations. Need device-specific Playwright fixture. Counter: even partial breakage is real cashier friction.
- **Scope-minimal?** YES — change `type="number"` to `type="text" inputmode="decimal"` + add `pattern` + normalise comma to dot in handler.
- **`PosV5Numpad` already in modal?** Verified yes — the modal exposes a numpad. So why does the cashier touch the keyboard? Because the inputs ALSO accept keyboard. If we go numpad-only, this entire concern dies. But that's a bigger UX call.

---

## 3. Proposed change (under LOCK)

### Template change (both inputs)

```diff
         <input
           :id="amountId"
-          type="number"
+          type="text"
           inputmode="decimal"
-          step="0.01"
-          min="0"
+          pattern="^[0-9]+([.,][0-9]{0,2})?$"
           class="pos-v5-tranche-row__input pos-v5-tabular"
-          :value="tranche.amount"
+          :value="formatNumberForInput(tranche.amount)"
           :data-testid="amountTestid"
           @input="onAmountInput"
         />
```

### Handler change

```diff
     onAmountInput(e) {
-        const v = e.target.value;
-        this.$emit('update', { amount: v === '' ? 0 : Number(v) });
+        const raw = String(e.target.value || '').replace(',', '.').trim();
+        if (raw === '') {
+            this.$emit('update', { amount: 0 });
+            return;
+        }
+        const v = Number(raw);
+        if (!Number.isFinite(v) || v < 0) return; // reject; keep last good
+        this.$emit('update', { amount: v });
     },
     onTenderedInput(e) {
-        const v = e.target.value;
-        this.$emit('update', { tendered: v === '' ? null : Number(v) });
+        const raw = String(e.target.value || '').replace(',', '.').trim();
+        if (raw === '') {
+            this.$emit('update', { tendered: null });
+            return;
+        }
+        const v = Number(raw);
+        if (!Number.isFinite(v) || v < 0) return;
+        this.$emit('update', { tendered: v });
     },
+    formatNumberForInput(value) {
+        if (value === null || value === undefined || value === '') return '';
+        return String(value);
+    },
```

Total source LOC delta: **~20 lines** (template change + handler hardening + helper).

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| FR cashier types `12,50` | Parsed correctly → 12.5 | Becomes 0 silently |
| FR cashier types `12.50` | Parsed correctly | Parsed correctly |
| EN cashier types `12.50` | Parsed correctly | Parsed correctly |
| AR cashier (Eastern Arabic numerals ١٢٫٥٠) | Still broken — Number() doesn't parse Arabic-Indic digits. Defer to V2 wave. | Still broken |
| Validation msg | Same — `validateTranche` runs on the parsed Number | Same |
| Backend payload | Identical — Number serialised to JSON unchanged | None |
| `min="0"` removal | Now enforced in JS handler (rejects negative) | Already enforced JS-side |
| `step="0.01"` removal | Numeric-spinner UI gone; for touch-numpad-driven workflow that's OK | Spinner stays |
| Frozen-zone regression | LOW — handler hardening only; visible behavior identical for the dot-typing path | None |

**One subtle gotcha**: removing `type="number"` loses the up/down number-spinner on desktop. Verify the design system tolerates this — most QSR POS UIs prefer no spinner anyway.

---

## 5. LOCK feasibility

- ≤20 LOC, single concern (locale-tolerant numeric input)? **YES**
- Architectural redesign needed? **NO**
- Owner gate required because file is FROZEN per `§7`
- Sentinel impact: any unit test asserting `e.target.value === ''` paths needs update (verify before commit)

---

## 6. Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended IF Le Cayenne POS device is iPad and French-locale — high-frequency persona hit)
- [ ] DEFER-V1.0.2 (acceptable IF the cashier device is desktop with hard keyboard)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (owner accepts French-comma data loss)

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- HTML Living Standard §4.10.5.1.13 (number input parsing)
- `resources/js/components/admin/pos/v5/PosV5Numpad.vue` (existing numpad that avoids this)
- `CLAUDE.md §7` Frozen Zones
