# W3 — Caisse parity — GATE-W6 OWNER DECISION (SURFACED, NOT EXECUTED)

Date: 2026-06-09 · Status: **BLOCKED on owner — frozen-zone LOCK required (CLAUDE.md §7/§10).**

## The §12 contradiction (named, parked)
The owner asked for wizards "synchronized and well done for the **borne AND caisse**" — which requires
**caisse parity**. The owner also (2026-06-08) answered **"Defer"** to GATE-W6 (the caisse work). These
contradict. Per §0.6 of the GOAL, the borne side was completed regardless (W1/W2/W4 GREEN); the caisse
leg is parked here for an explicit owner re-confirmation. **"Borne GREEN" does NOT mean "parity GREEN."**

## Why caisse parity is a frozen-zone change (sized honestly, verified 2026-06-09)
1. POS renders composer wizards **only if** `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true`
   (`config/catalog_v15.php:104`, default **false**).
2. Even with the flag ON, the FROZEN `public/js/pos-wizard.js` render dispatch (`:1131-1152`) has
   branches for viande/sauce/.../recap but **NO `generic_choices` branch**. The type resolves to
   `'generic_choices'` at `:515` but is **never rendered** → a builder-created/dynamic page would show
   blank or crash on the caisse.
3. So GATE-W6 = **flip the flag AND WRITE a `renderGenericChoicesStep()` renderer + dispatch branch +
   `composerAddonTotal` (addon pricing)** inside a FROZEN file. That is a frozen logic change, not a
   config toggle. It must be coordinated with any CAISSE-01 work touching the same file (one LOCK,
   one commit).

## Two paths — owner picks

| Path | What I do | Cost | Result |
|---|---|---|---|
| **A. LOCK GATE-W6** | Owner countersigns a frozen-edit LOCK + "design parfait" waiver. I write `renderGenericChoicesStep` in `pos-wizard.js`, flip the flag **on the :8766 clone only**, prove caisse renders the same wizards as the borne with matching totals, triple-vert + visual, separate frozen commit. | Frozen edit to the "design parfait" POS wizard (owner-protected). | Full borne+caisse parity. |
| **B. Keep Defer** | Nothing on the caisse. Document caisse non-parity as **V1.0.X backlog debt**. | None. | Borne wizards GREEN; caisse does NOT render dynamic/personal pages (renders the pre-existing hardcoded templates only). |

## Gate
| Gate | WHO | WHAT (unblocks) | WHERE |
|---|---|---|---|
| GATE-W6 | Physical owner | Countersigned LOCK doc + "design parfait" waiver for `public/js/pos-wizard.js` | LOCK §10 sign-off + separate frozen commit tag |

## Recommendation
Borne side is production-proven (W1/W2/W4). The caisse renderer is a bounded, well-understood change
but it touches the owner-protected "perfect" POS wizard — that is exactly the call only the owner makes.
**No frozen line will be touched without the LOCK.** Reply "LOCK GATE-W6" (path A) or "keep Defer" (path B).
