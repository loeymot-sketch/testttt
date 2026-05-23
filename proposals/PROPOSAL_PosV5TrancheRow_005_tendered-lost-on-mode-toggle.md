# PROPOSAL — PosV5TrancheRow — Mode toggle (cash → card → cash) silently erases tendered amount

**ID**: PROP-PosV5TrancheRow-005
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P2** (UX data-loss, low frequency)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

In `onModeChange` (lines 191-198):

```js
onModeChange(e) {
    const mode = Number(e.target.value);
    const patch = { mode };
    if (!isCashMode(mode)) {
        patch.tendered = null;   // ← erases tendered when switching away from cash
    }
    this.$emit('update', patch);
},
```

The behavior is correct in principle — a card tranche should not carry a `tendered` value. BUT: if the cashier toggles **cash → card → cash** (e.g. they tapped the wrong row, or the customer changed their mind), the originally-entered tendered value is **gone**. The row re-renders with empty tendered, and the cashier must re-enter it.

### Worked example

Cashier enters a cash tranche: amount = 20 €, tendered = 50 € (cashier saw the customer pull a 50 € note out).
Cashier accidentally selects "Carte" on the mode dropdown → tendered = null. Realises mistake → selects "Espèces" again → tendered is still null. Cashier must re-type 50.

### Personas impacted
- **Cashier-multitask** (LOW frequency, MEDIUM annoyance when it happens; persona-fit per the primary "cashier-multitask" angle)
- **Cashier-rush** (MEDIUM — wasted seconds during lunch peak; 50% chance of forgetting to re-enter tendered → validation fires after they've already moved focus)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
The data loss is silent. There's no confirmation "Are you sure? This tranche has 50 € tendered." A defensive UX caches the tendered when switching away and restores when switching back.

### Chef perspective
N/A.

### Owner perspective
"No useless complexity V1" — this is a borderline polish item. But the fix is trivial (~5 lines).

### Multi-tenant-future
V2 SaaS — same UX pattern likely needed regardless of tenant.

### Adversarial dispute
- **False positive?** Partially — clearing tendered when mode != cash is **correct** to avoid sending stale data to backend. Counter: cache it in COMPONENT-LOCAL state and restore on cash-toggle. Backend payload remains correct.
- **Worth a LOCK?** Borderline. Could be solved in PaymentComponent.updateTranche by preserving the value too, without touching this file. Need to verify how the parent handles the patch.
- **Real-world frequency?** Low — but the data-loss-without-warning aspect is the real concern, not the frequency.

---

## 3. Proposed change (under LOCK)

### Add data field

```diff
   data() {
     return {
+      // Cache last tendered when switching away from cash, restore on cash toggle.
+      _cachedTendered: null,
     };
   },
```

(Vue 2 syntax — verify component declaration style; the file currently has no `data()` so add it.)

### Method change

```diff
     onModeChange(e) {
       const mode = Number(e.target.value);
       const patch = { mode };
+      const wasCash = this.isCash;
+      const willBeCash = isCashMode(mode);
+      if (wasCash && !willBeCash) {
+        // Cache before clearing
+        this._cachedTendered = this.tranche.tendered;
+        patch.tendered = null;
+      } else if (!wasCash && willBeCash && this._cachedTendered != null) {
+        // Restore on cash re-toggle
+        patch.tendered = this._cachedTendered;
+      } else if (!willBeCash) {
+        patch.tendered = null;
+      }
-      if (!isCashMode(mode)) {
-          patch.tendered = null;
-      }
       this.$emit('update', patch);
     },
```

Total source LOC delta: **~10 lines**.

### Alternative (zero local state) — simpler, recommended

Just preserve `tendered` if it was set, even when mode goes to non-cash. The serializer (`serializeTranches:217-243`) explicitly does `isCashMode(t.mode) ? toCents(t.tendered) : null` — it ALREADY discards non-cash tendered at payload time. So the value can stay in the local tranche object harmlessly.

```diff
     onModeChange(e) {
       const mode = Number(e.target.value);
-      const patch = { mode };
-      if (!isCashMode(mode)) {
-          patch.tendered = null;
-      }
+      // Keep tendered locally — serializer drops it for non-cash modes
+      // anyway. Restoring on cash re-toggle without local state.
+      const patch = { mode };
       this.$emit('update', patch);
     },
```

Total LOC delta (alternative): **−3 lines** (net reduction). This is the cleanest fix.

---

## 4. Risk analysis

| Scenario | Risk if applied (alternative) | Risk if NOT applied |
|----------|------------------------------|---------------------|
| Cash → card switch | Tendered stays on object but hidden (v-if isCash). Payload still null per serializer. Backend unchanged. | Data lost. |
| Card → cash re-toggle | Tendered re-appears with previous value. Cashier sees it, can override. | Empty field; must re-type. |
| Validation | validateTranche checks `isCashMode(mode)` before tendered. Hidden value on card tranche doesn't trigger errors. | None. |
| Backend payload | Identical — serializer drops non-cash tendered. | None. |
| Frozen-zone regression | LOW — net code reduction | None. |
| Test brittleness | Existing tests may assert tendered = null after switch. If so, they pass for the wrong reason and need update. | Tests pass today. |

**Pre-flight check**: grep test suite for assertions about tendered being null after mode change.

---

## 5. LOCK feasibility

- ≤10 LOC (cache variant) or −3 LOC (alternative)? **YES**
- Architectural redesign needed? **NO**
- Owner gate required because file is FROZEN per `§7`
- **Recommendation**: the **alternative** (no local cache) — net code reduction, no new state.

---

## 6. Owner recommendation

- [ ] APPLY-WITH-LOCK (alternative variant, −3 LOC)
- [x] **DEFER-V1.0.2** (acceptable — low frequency; batch with other UX polish)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:191-198`
- `resources/js/helpers/posSplitPayment.js:217-243` (serializeTranches drops non-cash tendered)
- `CLAUDE.md §7` Frozen Zones
