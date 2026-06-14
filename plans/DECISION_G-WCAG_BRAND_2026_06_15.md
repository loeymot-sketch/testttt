# Owner decision package — G-WCAG brand contrast (DUX-6)

**Status:** awaiting owner decision (no code applied). Prepared 2026-06-15 per GOAL 2.0 Phase 5.
**Scope:** the brand orange `#F4501E` on light backgrounds.

## The contrast facts
- `#F4501E` on white = **3.49:1**.
- WCAG AA **PASSES** for: large text (≥24px, or ≥18.66px bold) and non-text UI components (≥3:1).
- WCAG AA **FAILS** for: small body text (needs 4.5:1) — e.g. orange labels/prices < ~18px on light.

So the brand orange is AA-compliant everywhere it's used as a heading, a button fill, an icon, or large numerals. It only fails when used as **small body text** on a light background.

## What was done autonomously (DUX-5 "free half") vs deferred
- The **free half** (swap specific small orange body-text occurrences in NON-frozen kiosk components to a darker AA color, or bump them to the large-text threshold — brand hex untouched) is a legitimate a11y fix. It is **deferred together with DUX-6** below rather than applied piecemeal, because the small-text occurrences live mostly in components the owner has attested (kiosk summary/confirmation/waiting), and a per-occurrence change deserves one reviewed pass + visual validation alongside this decision — not a scattered set of edits.

## The decision (pick one)
| Option | What it means | Trade-off |
|---|---|---|
| **A — keep `#F4501E`, enlarge frozen small-text labels** | Where orange-as-small-text sits in a frozen kiosk component, bump those labels to ≥24px (passes AA-large). | Touches frozen components → needs a LOCK + triple-green. Brand hex unchanged. |
| **B — add `--kiosk-primary-text` darker AA token (Recommended)** | Introduce a darker orange token (e.g. `#C23A12`, ≥4.5:1 on white) used ONLY for small orange body text; brand fills/headings keep `#F4501E`. | Brand identity untouched at large sizes; small text becomes AA-compliant. Non-frozen token addition + the few small-text occurrences switch to it. |
| **C — accept AA-large posture as brand-intentional** | Treat the orange as a brand color that is AA-compliant at the sizes it's actually used (headings/buttons/numerals), and document the small-text exception as accepted. | Zero change. Honest if the small-text orange occurrences are rare/non-critical. |

**Recommendation: B** — it keeps the brand at large sizes (where the owner attested it) while making small orange text AA-compliant via a dedicated darker token. No brand-hex change, no frozen edit for the non-frozen occurrences.

## If the owner picks A or B
- Produce a **per-occurrence contrast table** (file:line + size + current ratio + fixed ratio) before any edit.
- Any frozen-component touch (KioskWizardComponent / KioskCartComponent orange-as-text) → its own LOCK + triple-green.
- Rebuild + Read-the-screenshot visual validation on kiosk surfaces.

## Related deferred (cosmetic, low-value-high-risk)
- **DUX-4 (KDS card time overflow ~23px at 1200px/4-col):** cosmetic, fully legible (`overflow:visible`), only at the admin-centered 1200px/4-col viewport (a real KDS terminal runs wider/fewer columns). The KDS type scale is owner-attested for 3m readability (Wave T) — lowering the clamp floors risks that mandate. Defer unless the owner runs KDS at exactly 1200px/4-col.
- **DUX-7 (broad visual-polish sweep):** open-ended; fold into the next UI pass with a consolidated finding list + per-fix screenshot.
