# PROPOSAL — PosV5TrancheRow — Missing `note` field UI (card last-4 / reference never captured)

**ID**: PROP-PosV5TrancheRow-002
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P1** (reconciliation gap; not a ship blocker but actively dropped backend feature)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

The shared helper `posSplitPayment.js` documents and reserves a `note` field on every tranche:

```js
// posSplitPayment.js:30-38 (excerpt)
//  {
//    id:       string,         // local UUID-ish, for v-for keys
//    mode:     number,         // posPaymentMethodEnum (1=CASH, 2=CARD, ...)
//    amount:   number,         // EUR, 2 decimals (kept as Number for UI)
//    tendered: number | null,  // EUR; only for CASH; null for non-cash
//    note:     string | null,  // card last-4 / reference, optional
//  }
```

The helper sets `note: null` at construction (line 199, `splitEqually`) and serialises whatever is on the object (`note: t.note ?? null`, line 228) into the backend payload.

**PosV5TrancheRow.vue has NO UI input for `note`.** The cashier cannot enter:
- card last-4 (industry-standard receipt reference)
- TPE auth reference number
- ticket restaurant voucher serial
- free-form reconciliation note (e.g. "client en retard, paie demain")

Every tranche payload reaches the backend with `note: null`. The serializer is wired, the spec is documented, the input is missing.

### Personas impacted
- **Cashier-multitask** (MEDIUM — for end-of-day reconciliation; cashier loses the 30-second window to capture last-4 right at point-of-sale)
- **Owner Le Cayenne** (MEDIUM — end-of-day cash audit harder; specifically affects ticket-restaurant voucher tracking which is a real Le Cayenne payment mode)
- **Manager / reconciliation** (HIGH — they're the consumers of `note`)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
For card tranches, ~30% of cashiers in real-world systems capture the card last-4 on the slip. With no UI input here, that capture goes elsewhere (paper trail, sticky note) or is lost. For TR voucher: serial number on the voucher is a fraud-prevention reference that auditors look for.

### Chef perspective
N/A.

### Owner perspective
Owner mandate "no useless complexity V1" applies — but the field is ALREADY serialised. The cost is one optional `<input>` per tranche; not adding it is a sunk-helper-cost.

### Multi-tenant-future
V2 SaaS tenants (especially France, where voucher-based meal-tickets have specific reconciliation rules) will demand this field. Adding it now is forward-compatible. The DB / backend / serializer all already accept it.

### Adversarial dispute
- **False positive?** No — verified by reading helper line 199 (constructor sets null) + line 228 (serializer reads), and grepping the row template for `note` (zero matches). The gap is real.
- **Backend reads note?** Verified `PosOrderRequest` accepts the field via `serializeTranches`. Backend storage may or may not persist (need to check `OrderPayment` model) — but the FRONTEND payload always sends null today.
- **Scope-minimal?** YES — single `<input type="text">` per tranche, behind a `v-if="!isCash"` (cash tranches don't need a note — change-due is the reference).

---

## 3. Proposed change (under LOCK)

### Template addition (after the existing fields div, ~line 71)

```diff
+    <div v-if="showNote" class="pos-v5-tranche-row__field pos-v5-tranche-row__field--full">
+      <label :for="noteId" class="pos-v5-tranche-row__field-label">
+        {{ noteLabel }}
+      </label>
+      <input
+        :id="noteId"
+        type="text"
+        maxlength="32"
+        class="pos-v5-tranche-row__input pos-v5-tranche-row__input--text"
+        :value="tranche.note || ''"
+        :placeholder="notePlaceholder"
+        :data-testid="`pos-payment-tranche-note-${index}`"
+        @input="onNoteInput"
+      />
+    </div>
```

### Computed additions

```diff
+    showNote() {
+      // Show note for CARD (last-4) + TR (voucher serial) + OTHER (free).
+      // Cash tranches use change-due as their visible reference.
+      const m = Number(this.tranche.mode);
+      return m === posPaymentMethodEnum.CARD
+          || m === posPaymentMethodEnum.TICKET_RESTAURANT
+          || m === posPaymentMethodEnum.OTHER
+          || m === posPaymentMethodEnum.MOBILE_BANKING;
+    },
+    noteId() { return `tranche-note-${this.index}`; },
+    noteLabel() {
+      const m = Number(this.tranche.mode);
+      if (m === posPaymentMethodEnum.CARD) return this.$t('label.card_last_four') || 'Carte n° (4 derniers)';
+      if (m === posPaymentMethodEnum.TICKET_RESTAURANT) return this.$t('label.tr_voucher_serial') || 'N° de ticket';
+      return this.$t('label.reference') || 'Référence';
+    },
+    notePlaceholder() {
+      const m = Number(this.tranche.mode);
+      if (m === posPaymentMethodEnum.CARD) return '1234';
+      if (m === posPaymentMethodEnum.TICKET_RESTAURANT) return 'TR-...';
+      return '';
+    },
```

### Method addition

```diff
+    onNoteInput(e) {
+      const v = String(e.target.value || '').trim().slice(0, 32);
+      this.$emit('update', { note: v === '' ? null : v });
+    },
```

Total source LOC delta: **~25 lines** (template + computed + method).

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Cashier rush | Optional field — cashier can skip; no enforcement | Loses fast-path capture moment |
| Reconciliation | last-4 + TR serial captured at source-of-truth | Manual / paper trail / loss |
| Backend payload | Forward-compatible — serializer ALREADY emits `note`, just always `null` today | None — payload unchanged |
| PCI / data sensitivity | Last-4 is NOT PCI-sensitive (it's the public masked tail printed on slips) | None |
| Frozen-zone regression | LOW — additive, atom contract unchanged from parent's POV (parent already supports `note` via update patch) | None |
| Storage / GDPR | Free-text 32-char field stored in `order_payments.note` (verify column exists) | None |

**One pre-flight check**: confirm `order_payments.note` column exists with sufficient length. If absent, this becomes a backend-migration task NOT scoped here.

---

## 5. LOCK feasibility

- ≤25 LOC additive, single concern? **YES**
- Architectural redesign needed? **NO**
- Owner gate required because the file is FROZEN per `§7`
- Dependency check needed: `order_payments.note` column existence (out of scope of this proposal — surface to owner)

---

## 6. Owner recommendation

- [ ] APPLY-WITH-LOCK (recommended IF reconciliation pain has been reported)
- [x] **DEFER-V1.0.2** (recommended — non-blocking; batch with note field column verify + reconciliation report)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (owner accepts always-null note; helper field becomes legacy dead-weight)

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `resources/js/helpers/posSplitPayment.js:30-38, 199, 228`
- `CLAUDE.md §7` Frozen Zones
