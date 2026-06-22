# LOCK_PAYMENT_CONFIRM_DEBOUNCE — debounce confirmOrder() against rapid double-click

> Frozen-zone override authorization. This LOCK doc is the contract between
> Owner (human gate), Claude (planner), Sub-agent (implementer), and any
> future safety-check hook (mechanical guard).

## §1. Identification

- **LOCK ID**: `LOCK_PAYMENT_CONFIRM_DEBOUNCE`
- **Created**: 2026-05-10
- **Cycle**: iter16-pos-debounce (NEW cycle — `.cursor/ACTIVE_CYCLE.md` is stale at PHASE=`(none)`)
- **Phase at creation**: PLAN (this LOCK + plan precede execution)
- **Status**: `DRAFT` — pending owner sign-off in §10

## §2. Frozen file(s) targeted

| Path | Why originally frozen | Lines targeted |
|---|---|---|
| `resources/js/components/admin/pos/PaymentComponent.vue` | Wizard popup + payment surface, design protected (Graphiti `IS_LOCATED_IN: zone FROZEN`); referenced as `(frozen)` in `tasks/execute-2026-04-20/V14_07_T11_*.md`, `V14_11_T19_*.md`, `V14_12_T21_*.md`. POS V5 paiement is the most fiscally-sensitive surface (NF525). | `~837-862` (the `confirmOrder` method body) |

**Note**: project does NOT currently have an automated `safety-check.sh` hook (the FROZEN list is a convention enforced by code review, not by a pre-commit hook). This LOCK is therefore a **code-review + audit-trail contract**, not a mechanical override. See §9.

## §3. Justification — why the override is necessary

**Paragraph 1 (the bug — hypothetical for this test case)**: When a cashier
rapidly double-taps the "Confirmer paiement" button on cash mode, the bug
hypothesis is that two `posOrderStore` API requests fire concurrently. Both
include the same `idempotency_key`. The second request returns HTTP 422
"duplicate idempotency_key", which the cashier sees as an opaque error toast
even though the first request actually succeeded. UX impact: cashier confusion
about whether the order was placed.

**Paragraph 2 (existing defense + why we still need this)**: `confirmOrder`
already has TWO defenses:
1. The button is `:disabled="loading.isActive || ..."` (line 281) → Vue rerender
   gates re-clicks
2. The function early-returns at line 842 with `if (this.loading.isActive) return;`

So the race window is **already extremely small**: only the few microseconds
between click 1 entering the function and reaching line 851 (`loading.isActive
= true`). The proposed debounce (800ms wrapper) is therefore **defense in depth**,
not strictly required. The owner should consider an alternative path FIRST:
move `this.loading.isActive = true` to the line immediately after the early-return
guard (line 843, before the multi-mode check). This kills the race in 1 line,
no debounce needed, no new dependency.

**Recommendation**: surface this to the owner before proceeding. If the owner
wants the debounce anyway (defense in depth + UX feel), this LOCK proceeds.
Otherwise, the LOCK shrinks to a 1-line move.

## §4. Scope — exactly what changes

**Surgical** — minimal patch, defense in depth.

**Tasks** (atomic):
1. At top of `<script>` block, import a debounce helper (lodash if already a
   dep — verify via `cat package.json | grep lodash`; otherwise inline a
   minimal implementation, ~6 lines)
2. In the component's `created()` lifecycle (or as data property), wrap
   `confirmOrder` once in an 800ms-leading-edge debounce; expose it as
   `this.confirmOrderDebounced`
3. Update the button `@click="confirmOrder"` (template line 279) to
   `@click="confirmOrderDebounced"`
4. The original `confirmOrder` keeps its existing guard (defense in depth, two
   layers)

**Diff sketch** (using inline minimal debounce, no lodash dep):

```diff
+ // [LOCK_PAYMENT_CONFIRM_DEBOUNCE] iter16 — defense-in-depth against rapid double-tap
+ function leadingDebounce(fn, wait) {
+   let timer = null;
+   return function (...args) {
+     if (timer) return;          // leading-edge: only first call passes
+     timer = setTimeout(() => { timer = null; }, wait);
+     return fn.apply(this, args);
+   };
+ }

  data() {
    return {
+     // populated in created() since arrow vs context
+     confirmOrderDebounced: () => {},
      // ...
    };
  },

+ created() {
+   this.confirmOrderDebounced = leadingDebounce(this.confirmOrder, 800);
+ },
```

```diff
- @click="confirmOrder"
+ @click="confirmOrderDebounced"
```

Total LOC added: ~12. Total LOC modified: 1 (template binding).

## §5. Files to modify

| File | Lines | Type of change |
|---|---|---|
| `resources/js/components/admin/pos/PaymentComponent.vue` | top of script (~315), data() (~347), template button (~279) | add helper, add data + created hook, swap binding |

**Files to read for context (not modified)**:
- `tests/e2e/iter15-bugs-regression.spec.js` — existing rate-limit + session-expired regression tests; the new debounce must not break these
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` — backend dedup the bug surfaces; understand its current behavior

**Files NOT touched** (explicit):
- All other methods in PaymentComponent.vue — only `created()` + button binding + helper
- `app/Services/OrderService.php` (frozen, LOCK_B_POS_9_2_3) — the backend already returns 422 correctly, no change needed there
- `KioskPaymentComponent.vue` — kiosk has its own flow, separate concern

## §6. Acceptance criteria (verifiable, binary)

Before declaring the LOCK CLOSED, ALL of these must check :

- [ ] `npx vitest run tests/js/posSplitPaymentValidation.spec.js` → 39 passed (no regression on payment helpers)
- [ ] New test: `tests/e2e/iter16-pos-debounce-regression.spec.js` — fires 2 click events within 50ms on `[data-testid="pos-payment-confirm"]` AND asserts exactly **1** `POST api/admin/pos/order` network request fires
- [ ] `npm run dev` → "webpack compiled successfully"
- [ ] Re-run `tests/e2e/iter15-bugs-regression.spec.js` → 3 passed (BUG-2/3/4 hold)
- [ ] Manual visual: open POS, add Frites, click Confirmer rapidly 5x — should see exactly 1 receipt modal
- [ ] DOM: `grep "iter16-pos-debounce-regression"` evidence captured in `tests/e2e/__screenshots__/iter16-pos-debounce/01-after-5-rapid-clicks.png` shows single receipt modal

## §7. Rollback plan

**If the patch breaks something post-commit**:

1. **Code rollback** (preferred):
   ```bash
   git revert <patch-sha>
   ```
   Note: revert preserves history. Only use `git reset --hard HEAD~1` if local
   + unpushed AND owner confirms.

2. **Bundle rollback** (frontend file changed):
   ```bash
   npm run dev   # rebuilds against the reverted source
   ```

3. **Data rollback** (none — pure UI change, no DB/cache implications)

4. **User notification** if the patch had reached production:
   - Notify cashiers via internal channel that "Confirmer paiement" reverted to
     pre-debounce behavior; advise them to wait for the receipt modal before
     re-clicking
   - Otherwise: N/A — dev environment only at fix time

5. **Risk this rollback re-introduces**: if the original double-tap race bug
   was actually causing 422s in production (not just hypothetical), reverting
   the debounce brings the bug back. Verify with cashier feedback before
   reverting.

## §8. Sub-agent + execution path

- **Sub-agent assigned**: `foodking-complex-implementer` (per project memory:
  routine-implementer is FORBIDDEN from frozen zones)
- **Why this sub-agent**: PaymentComponent is the most fiscally-sensitive UI
  file in the project; complex-implementer has the precedent (Phase 9 LOCKs)
  and the surgical discipline
- **Sub-agent prompt path**: pass via Agent tool with skill `test-e2e` reference
  for the regression spec, or use `~/.claude/skills/handoff-cursor` to write a
  brief if delegating to Cursor instead
- **Verification post-patch**: orchestrator (Claude) runs §6 acceptance, NOT
  the sub-agent self-reporting

## §9. Safety-check override config

**Project does NOT have a `safety-check.sh` hook in this repo**. The freeze
convention is enforced by:
- Code review (the LOCK is the audit trail for the reviewer)
- Graphiti memory (`PaymentComponent IS_LOCATED_IN: zone FROZEN`)
- Task docs in `tasks/execute-2026-04-20/*.md` calling the file `(frozen)`

This means: this LOCK functions as a **code-review + audit-trail convention**,
not a mechanical bypass. The PR reviewer is expected to:
1. See `[LOCK_PAYMENT_CONFIRM_DEBOUNCE]` markers in the diff
2. Verify this LOCK file exists and is APPROVED in §10
3. Approve the PR

**Code-side markers** (mandatory — add near each modification):
```js
// [LOCK_PAYMENT_CONFIRM_DEBOUNCE] iter16 defense-in-depth, owner-approved 2026-05-10
```

**Future hook integration**: if the project later adds `safety-check.sh`, this
LOCK is already in `tasks/iter16-pos-debounce/` (search-path-compatible). Add
to that hook:
```bash
APPROVED_LOCKS=$(grep -lE "Status.*APPROVED|Decision.*\[X\] APPROVED" tasks/**/LOCK_*.md)
```

## §10. Owner sign-off (human gate)

> **DO NOT proceed with the patch until the owner has explicitly signed off
> below.**

- **Owner**: TristaOdette596
- **Signed at**: __________________________ (timestamp)
- **Decision**: [ ] APPROVED  [ ] REJECTED  [ ] NEEDS CHANGES (e.g. take the
  1-line alternative from §3 instead of the full debounce)
- **Comments / conditions**: __________________________

After APPROVED:
- Sub-agent executes §4 tasks
- Acceptance §6 verified
- Status transitions: APPROVED → APPLIED → CLOSED
- Final sha: __________________

If REJECTED or NEEDS CHANGES:
- LOCK stays at DRAFT/REJECTED status
- File NOT modified
- A new LOCK can be drafted (separate ID)

---

**End of LOCK_PAYMENT_CONFIRM_DEBOUNCE**
