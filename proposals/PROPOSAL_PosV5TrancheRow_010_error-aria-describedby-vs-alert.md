# PROPOSAL — PosV5TrancheRow — Error `<p role="alert">` vs. linked `aria-describedby` (announcement timing)

**ID**: PROP-PosV5TrancheRow-010
**Author**: PROPOSAL AGENT (Phase B.5, ULTRA-DEEP audit 2026-05-23)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: **P3** (a11y micro-fix; closely related to PROP-003)
**Touch**: `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
**Frozen reason**: `CLAUDE.md §7` — POS V5 tranche row protected file

---

## 1. Finding (read-only audit)

Lines 88-94:

```html
<p
  v-if="invalid && validationMsg"
  class="pos-v5-tranche-row__error"
  role="alert"
>
  {{ validationMsg }}
</p>
```

`role="alert"` is correct **for the first announcement** (urgent, interrupts the screen reader). But:

1. **Echo-spam**: each tranche row that becomes invalid simultaneously (e.g. after a "Diviser à parts égales" without tendered set) fires its own alert. With N tranches, the SR user hears N alerts back-to-back. NVDA / JAWS may drop or pile them.
2. **Re-focus silence**: once announced, refocusing the input does not re-announce the error. PROP-003 addresses this with `aria-describedby`.
3. **Mixed model**: V5 design system likely standardizes one or the other (alert vs. describedby) — this file mixes neither cleanly.

### Personas impacted
- **Screen-reader cashier** (LOW today; relevant for V2 SaaS B2B accessibility-mandated contracts)

---

## 2. Reasoning fort (multi-perspective)

### Cashier-multitask
N/A.

### Owner perspective
V1 — non-issue. V2 — potential audit finding.

### Multi-tenant-future
Standardise on one approach across V5 atoms.

### Adversarial dispute
- **False positive?** Very borderline. `role="alert"` is fine in isolation. The "echo-spam" risk is real only for N≥3 simultaneous invalid rows — an edge case.
- **Better merged into PROP-003?** Possibly — both are a11y. Kept separate because the FIX is structural (assertive→polite + aria-describedby vs. alert).
- **Scope-minimal?** YES — change `role="alert"` to `aria-live="polite"` if PROP-003 is also applied (aria-describedby providing the per-input link).

---

## 3. Proposed change (under LOCK)

```diff
     <p
       v-if="invalid && validationMsg"
       :id="errorId"
       class="pos-v5-tranche-row__error"
-      role="alert"
+      aria-live="polite"
+      role="status"
     >
       {{ validationMsg }}
     </p>
```

(Requires PROP-003 `errorId` + `aria-describedby` to be applied first or in the same LOCK.)

Total source LOC delta: **3 lines** (drop role="alert", add aria-live + role="status").

---

## 4. Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Single invalid tranche | Announced once (polite) instead of urgent (alert). Slight a11y degradation if user expects assertive | Same as today |
| N invalid tranches simultaneously | Polite — queued; SR speaks them in order without dropping | alert — may stack/drop |
| Re-focus on input | aria-describedby re-announces error (via PROP-003) | Silent re-focus |
| Frozen-zone regression | LOW — text-only attribute change | None |

---

## 5. LOCK feasibility

- ≤3 LOC, requires PROP-003 to land first or together
- Owner gate required (file FROZEN per `§7`)

---

## 6. Owner recommendation

- [ ] APPLY-WITH-LOCK (paired with PROP-003 in same LOCK)
- [x] **DEFER-V1.0.2** (recommended — batch with PROP-003 and other a11y polish)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS

**Signed-off-by-owner**: ___________  **Date**: ___________

---

## 7. References
- PROP-PosV5TrancheRow-003 (companion proposal)
- WCAG 2.1 SC 3.3.1, 4.1.3
- `CLAUDE.md §7` Frozen Zones
