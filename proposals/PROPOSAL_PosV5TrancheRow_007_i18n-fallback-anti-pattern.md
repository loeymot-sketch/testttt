# PROPOSAL — PosV5TrancheRow — `$t('key') || 'fallback'` i18n anti-pattern (8 occurrences)

**ID**: PROP-PosV5TrancheRow-007
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P3** (i18n hygiene; cosmetic risk)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

Throughout the file, the pattern `$t('key') || 'fallback'` appears 8 times:

| Line | Code |
|------|------|
| 153  | `${this.$t('label.payment') \|\| 'Paiement'} ${this.index + 1} (${this.modeLabel})` |
| 159  | `${this.$t('button.remove') \|\| 'Supprimer'} #${this.index + 1}` |
| 170  | `return this.$t('pos.split_tendered_required') \|\| 'Le montant reçu est requis pour la tranche cash.';` |
| 173  | `return this.$t('pos.split_tendered_below_amount') \|\| 'Le montant reçu est inférieur au montant de la tranche.';` |
| 176  | `return this.$t('pos.split_amount_required') \|\| 'Le montant de la tranche est requis.';` |
| 179  | `return this.$t('pos.split_mode_invalid') \|\| 'Mode de paiement invalide.';` |

(And implicitly the other `$t` calls without fallback that may surface raw keys.)

### Why this is an anti-pattern

1. **`$t()` returns the key as a string when not found** — so `$t('missing.key') || 'fallback'` evaluates to `'missing.key' || 'fallback'`, which is `'missing.key'`. The fallback **never fires** in vue-i18n. The cashier sees the raw key. (Verified by behavior of vue-i18n v8 — silent return of key.)
2. **Hides missing translations from i18n hygiene tooling**: lint/sentinel pass because the string literal exists in code, but the FR/AR/EN files have no entry.
3. **Translator pain**: the truth is in TWO places — translation files AND inline fallbacks. Drift inevitable.
4. **Empty-string edge**: if a translator sets the key to `""` (empty), `$t() || 'fallback'` DOES fire the fallback — silently substituting code-side French where the translator intended the empty.

### Personas impacted
- **Developer / translator** (MEDIUM — invisible drift)
- **Multi-locale tenant** (HIGH — AR translation file gap surfaces as raw key, not as French fallback)

---

## 2. Reasoning fort (multi-perspective)

### Cashier perspective
French cashier sees French either way (key exists in fr.json or inline fallback). Today's V1 single-locale Le Cayenne — no impact.

### Owner perspective
V1 mono-locale — invisible. The risk surfaces only at V2 multi-tenant or AR-locale customer onboarding.

### Multi-tenant-future
V2 SaaS — AR/EN tenant onboarding will see raw keys (`label.payment`, `button.remove`, etc.) the moment the translator omits one.

### Adversarial dispute
- **False positive?** Partial — confirmed by vue-i18n v8 behavior: `$t('missing.key')` returns `'missing.key'` (truthy). The `|| 'fallback'` is therefore a no-op for missing keys. Real concern.
- **Codebase-wide pattern?** YES — grep of `$t.*||` across resources/js shows the pattern is endemic (PaymentComponent uses it too). Singling out this atom is selective; this proposal should escalate to a codebase-wide hygiene issue, but **within scope of this audit task**, the recommendation is to either:
  - (a) remove fallbacks here AND add missing keys to fr/ar/en JSON
  - (b) defer to a codebase-wide i18n hygiene wave
- **Scope-minimal?** YES — within the atom, only 8 spots.

---

## 3. Proposed change (under LOCK)

### Verify keys exist + remove fallbacks

```diff
     ariaGroupLabel() {
-        return `${this.$t('label.payment') || 'Paiement'} ${this.index + 1} (${this.modeLabel})`;
+        return `${this.$t('label.payment')} ${this.index + 1} (${this.modeLabel})`;
     },
```

(Repeat for the other 7 occurrences.)

**Pre-flight required**: confirm fr.json + ar.json + en.json have:
- `label.payment` → "Paiement" / "دفع" / "Payment"
- `button.remove` → "Supprimer" / ... / "Remove"
- `pos.split_tendered_required`
- `pos.split_tendered_below_amount`
- `pos.split_amount_required`
- `pos.split_mode_invalid`
- `label.amount`, `label.received_amount`, `label.change_due`, `label.cash`, `label.card`, `label.mobile_banking`, `label.ticket_restaurant`, `label.other`, `label.payment_method` (the other `$t` calls without fallback)

If any are missing, add them in the same LOCK as part of the fix.

Total source LOC delta: **8 fallback removals + N keys added across 3 JSON files**.

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| All keys present | Identical UX | Identical UX |
| Key missing in FR | Cashier sees raw key (forces fix-forward) | Cashier sees inline FR fallback (silent hide) |
| Key missing in AR (V2) | Cashier sees raw key (forces fix-forward) | Cashier sees raw key today (fallback is French, not Arabic) |
| Frozen-zone regression | LOW — text-only changes | None |
| Tests | None affected by string change | None |

**The "if NOT applied" column for AR is the smoking gun** — the inline French fallback does NOT help an Arabic-locale tenant. The fallback is misleading: it makes developers feel safe while AR users see raw keys anyway (or worse, French keys).

---

## 5. LOCK feasibility

- ≤8 inline removals + JSON updates? **YES**
- Architectural redesign needed? **NO**
- Owner gate required because file is FROZEN per `§7`
- JSON files are NOT in §7 freeze list — only the .vue file requires LOCK

---

## 6. Owner recommendation

- [ ] APPLY-WITH-LOCK (paired with codebase-wide i18n hygiene wave)
- [x] **DEFER-V1.0.2** (recommended — mono-locale V1 has zero customer-facing impact; batch with codebase-wide hygiene)
- [ ] DEFER-V2 (V2 multi-locale is the natural trigger)
- [ ] KEEP-AS-IS

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:153, 159, 170, 173, 176, 179`
- vue-i18n v8 documentation on missing-key behavior
- `CLAUDE.md §7` Frozen Zones
