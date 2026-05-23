# PROPOSAL — PosV5TrancheRow — Payment-mode icons rendered as raw emoji (i18n, a11y, brand)

**ID**: PROP-PosV5TrancheRow-012
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P3** (brand consistency + a11y polish; not blocking)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

Mode icons rendered as Unicode emoji (lines 130-136):

```js
modeIcon() {
    const m = Number(this.tranche.mode);
    if (m === posPaymentMethodEnum.CASH) return '💵';
    if (m === posPaymentMethodEnum.CARD) return '💳';
    if (m === posPaymentMethodEnum.MOBILE_BANKING) return '📱';
    if (m === posPaymentMethodEnum.TICKET_RESTAURANT) return '🍽️';
    return '💼';
},
```

Rendered in template line 10: `<span class="pos-v5-tranche-row__icon" aria-hidden="true">{{ modeIcon }}</span>`.

### Why raw emoji is a brand/UX concern

1. **Cross-platform rendering inconsistency**: 💵 on macOS Safari = US dollar bill (green). On Android Chrome = neutral bill. On Windows = US-specific. **For a French QSR**, the US-dollar visual on a French POS is brand-incoherent.
2. **Color accessibility**: emoji are decorative full-color glyphs; they ignore the V5 design tokens (`--pos-v5-ink-soft`, `--pos-v5-brand-red`). Cannot be themed for high-contrast mode.
3. **Cultural appropriateness**: 🍽️ for "Ticket Restaurant" is a generic plate — the French meal-ticket voucher has a specific visual identity (Sodexo/Edenred/Apetiz logos).
4. **Future redesign brittleness**: emoji set may change per OS update.
5. **a11y**: `aria-hidden="true"` is correctly applied (sighted decoration only), so SR users don't hear "money bag, money bag" — this part is fine.

### Personas impacted
- **Cashier-multitask** (LOW — sees the icons; not a friction)
- **Owner-brand** (MEDIUM — V5 design system explicitly uses CSS tokens; emoji break that contract)
- **V2 SaaS multi-tenant** (HIGH — each tenant brand wants their icon set)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
Emoji are recognisable enough. Zero friction.

### Owner perspective
V1 Le Cayenne — owner has expressed strong brand control intent. The dollar-bill emoji clashes with French euro brand. Real concern.

### Multi-tenant-future
V2 SaaS — tenants demand brand asset control.

### Adversarial dispute
- **False positive?** Partial — emoji are intentional design shortcut. Sister atoms (PosV5Button, PosV5Pill) may also use emoji. Singling out this atom would be selective. Counter: this atom is the most user-facing of the V5 set in the payment flow.
- **Codebase-wide?** Verified: emoji also in PaymentComponent.vue (✓, ✕, ✨, +). The pattern is endemic. Realistic scope = ALL V5 atoms in one LOCK, not just this file.
- **Scope-minimal?** Replace `modeIcon` returns with SVG component refs OR brand-specific CSS class names. Could be deferred to a brand-icon-system wave.

---

## 3. Proposed change (under LOCK)

### Two paths

**Path A — Quick brand-correct emoji swap** (1-line per mode)

```diff
     modeIcon() {
         const m = Number(this.tranche.mode);
-        if (m === posPaymentMethodEnum.CASH) return '💵';
-        if (m === posPaymentMethodEnum.CARD) return '💳';
+        if (m === posPaymentMethodEnum.CASH) return '💶'; // EUR banknote (not USD)
+        if (m === posPaymentMethodEnum.CARD) return '💳';
         if (m === posPaymentMethodEnum.MOBILE_BANKING) return '📱';
         if (m === posPaymentMethodEnum.TICKET_RESTAURANT) return '🍽️';
         return '💼';
     },
```

(Just the CASH icon swap fixes the dollar-bill clash. LOC delta: 1.)

**Path B — SVG component-based icon system** (V5 icon registry)

Refactor to a `PosV5Icon name="cash|card|mobile|tr|other"` component that pulls from a central V5 SVG icon set. Out-of-scope for a single-atom proposal — would require a separate V5 design system migration plan.

### Recommendation

Path A as a one-line brand-correct quick fix. Path B as a V2-wave system migration.

Total source LOC delta (Path A): **1 line**.

---

## 4. Risk analysis

| Scenario | Risk if Path A applied | Risk if NOT applied |
|----------|------------------------|---------------------|
| FR cashier | Sees EUR banknote (culturally correct) | Sees USD banknote |
| Cross-OS render | 💶 still varies per platform but always green/EUR | Same |
| Frozen-zone regression | LOW — text-only return value change | None |
| Sentinel | None — no test asserts the specific emoji | None |

---

## 5. LOCK feasibility (Path A)

- ≤1 LOC, single concern? **YES**
- Owner gate required (file FROZEN per `§7`)

---

## 6. Owner recommendation

- [x] **APPLY-WITH-LOCK** (Path A — 1-line brand-correctness fix)
- [ ] DEFER-V1.0.2 (Path B — SVG system migration)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (owner accepts US-dollar emoji on French POS)

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:130-136`
- Unicode codepoints: U+1F4B5 USD vs U+1F4B6 EUR
- `CLAUDE.md §7` Frozen Zones
