# PROPOSAL — PaymentComponent.vue:144 — `cardInput` type="number" strips leading zeros from card last-4

**ID** : PROP-PAY-002
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — separate concern, currency format)

## Finding (read-only audit)

The "enter card last 4 digits" input is declared `type="number"` at `resources/js/components/admin/pos/PaymentComponent.vue:144-149` :

```html
<input
    id="cardInput"
    ref="cardInput"
    type="number"
    class="pos-v5-payment-input pos-v5-tabular"
    required
/>
```

`type="number"` semantics (HTML5) interpret the entered value as a *Number*. Browsers strip leading zeros at value commit time and `parseInt`/`Number()` will reduce `"0001"` → `1`. The downstream `collectPaymentInputPatch` (line 698-711) writes `this.$refs.cardInput.value` straight into `pos_payment_note` :

```js
patch.pos_payment_note =
    form.pos_payment_method === this.posPaymentMethodEnum.CARD && this.$refs.cardInput?.value
        ? this.$refs.cardInput.value
        : "";
```

When the cashier types card-ending `0007` on a Visa/Mastercard ending with the digits 0-0-0-7, the value persisted to `pos_payment_note` is `"7"` (or `"0.007e3"` if scientific notation triggers, browser-dependent). The receipt + audit trail then records a wrong card reference, breaking end-of-day reconciliation against the TPE batch report.

Additionally, `type="number"` allows the spinner controls (up/down arrows), decimal separators (per browser locale — comma vs period), `e` (scientific), `+/-` signs, and silently discards leading zeros — none of which are meaningful for a 4-digit PAN suffix.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A — chef never touches PaymentComponent.

### Client perspective
Indirect — at checkout the customer may glance at the screen and notice the cashier's typed value drop a leading `0` from their card last-4. Minor confusion ("c'est pas ma carte"), but mostly cosmetic at this micro-moment.

### Cashier perspective
At end-of-day, the cashier reconciles the TPE batch report against the POS pos_payment_note column. If the customer's card ended `0007` and the system stored `7`, the reconciliation row mismatches. Cashier must hand-correct or live with the discrepancy. Under stress (rush, busy night, ten transactions in a row) the mistakes compound.

### Owner perspective
NF525 declares the receipt + audit_log as the SSOT of cash trail. The card last-4 is not legally binding — but for owner-side dispute resolution (chargebacks, customer claims "I didn't pay") the last-4 is the cross-link to the TPE record. A stripped leading zero breaks that link. Soft compliance hit, hard operational pain.

### Multi-tenant-future
For V2 SaaS, other restaurants may use different payment terminals with different batch-report formats. The card last-4 will still be the operational pivot. Bug is locale-independent and will surface in every V2 tenant.

### Adversarial dispute (challenge yourself)
- **False positive ?** Verified : input is `type="number"` (line 146), `pos_payment_note` is consumed downstream (line 705-708). The browser's input filtering is documented HTML5 spec. **NOT a false positive.**
- **Could be cosmetic only ?** No — `pos_payment_note` is written to the order record (`OrderPayment.pos_payment_note` column, NF525 audit_log includes order_payments). Reconciliation breaks.
- **Could the cashier just retype ?** Yes, but only if they notice. Under rush conditions, they don't. The fix is one-line and ergonomically superior (mobile/tablet → numeric keypad via `inputmode="numeric"`).
- **Why was it built this way ?** `type="number"` looks like a sensible default for "card digits". Authors likely did not know that HTML5 number input strips leading zeros at commit time.

## Proposed change

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 143-149 @@
                         <input
                             id="cardInput"
                             ref="cardInput"
-                            type="number"
+                            type="text"
+                            inputmode="numeric"
+                            pattern="[0-9]{4}"
+                            maxlength="4"
+                            autocomplete="off"
                             class="pos-v5-payment-input pos-v5-tabular"
                             required
                         />
```

Net diff : +4 attributes, -1 attribute = +3 LOC.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Cashier types `0007` on tablet | None — `type=text inputmode=numeric` shows numeric keypad on iPad/Android, and `text` preserves the string verbatim. `pattern` + `maxlength=4` are advisory (no auto-trim). `pos_payment_note` receives `"0007"`. | HIGH — `pos_payment_note` records `"7"`; end-of-day reconciliation row mismatch. |
| Cashier types invalid `abcd` | `pattern` triggers a visual validation hint on form submit (HTML5 native). Without explicit form submit it is purely advisory. Cashier sees own typo. | Existing behavior already accepts non-numeric `e` / `+` / `-` (HTML5 number input quirk). |
| `floatNumber($event)` was bound (line 90 for cashInput) | Card input does NOT have `floatNumber` binding — so removing `type=number` does not break any numeric-only enforcement that existed before. | N/A |
| Numpad component writes to `cardInput` (line 545-554 numpadInput/Back/Clear via getElementById) | Numpad emits characters → `el.value += val` → with `type=text` this works the same. With `type=number`, certain non-numeric chars (e.g. comma) get silently dropped. Switching to `type=text` is consistent with numpad behavior. | N/A |
| NF525 fiscal audit chain | None — `pos_payment_note` is part of the order payload but not part of the HMAC audit-chain (chain HMACs `order_id`, `total`, `created_at`, prior `chain_hash` per ZReportService). Note format unaffected. | None |
| Bundle freshness | `npx mix` rebuild needed post change (Q12 sentinel). | None |
| Backwards compatibility with existing orders | Existing rows are already stored — historical `pos_payment_note` of "7" stays as is. Forward-only fix. | None |

## LOCK feasibility

- ≤5 LOC ? **YES** (+3 net LOC, single template element, one file).
- Architectural redesign needed ? **NO** — pure HTML attribute swap. Zero JS, zero CSS, zero template structure change.
- Reversible ? Single `git revert`. Recovery time ≤30s.
- Existing test impact : if `tests/js/components/admin/pos/PaymentComponent.spec.js` asserts the input's `type` attribute, adjust to `"text"`. Probably no impact.

## Owner recommendation

[ ] APPLY-WITH-LOCK — countersign + sub-agent applies 5-line attribute swap + sentinel + mix rebuild + commit
[ ] DEFER-V1.0.2 — accept reconciliation friction for V1 (owner judges low risk vs LOCK ceremony)
[ ] DEFER-V2 — wait for SaaS context
[ ] KEEP-AS-IS — owner accepts the leading-zero strip

**Signed-off-by-owner** : ___________  **Date** : ___________
