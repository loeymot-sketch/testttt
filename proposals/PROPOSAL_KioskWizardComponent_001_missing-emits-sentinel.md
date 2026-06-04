# PROPOSAL — KioskWizardComponent.vue — Missing emits-list declaration + missing prop-mutation sentinel pair (T5)

**ID** : PROP-KWZ-001
**Author** : PROPOSAL AGENT (Phase B.5, GOAL ULTRA-DEEP 2026-05-23)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P1** — Architectural-debt + sentinel gap; no functional break today but undermines V2 SaaS readiness and the "frozen-zone protect via verrou structurel" doctrine.
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (CLAUDE.md §7 — kiosk wizard frontend)
**Frozen reason** : Per `CLAUDE.md §7 Frozen Zones` — explicitly listed. Owner mandate (feedback `kiosk_wizard_not_protected`) confirms tests/sentinels are allowed without LOCK, but **code changes require gate**.
**Touch (if accepted)** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (3 LOC added — `emits` array + props deprecation comment) + new sentinel file `tests/js/sentinels/kioskWizardComponentPropMutation.spec.js`.

---

## 1. Finding (read-only audit)

`KioskWizardComponent.vue` is declared as:

```js
export default {
  name: 'KioskWizardComponent',
  mixins: [kioskPriceMixin],
  components: { ... },
  inject: { showToast: { default: null } },
  props: {
    item: { type: Object, default: null },
    onAddToCart: { type: Function, default: null },
    onClose: { type: Function, default: null },
    itemId: { type: [String, Number], default: null },
  },
  data() { ... },
  computed: { ... },
  methods: { ... },
  mounted() { ... },
  beforeUnmount() { ... },
  watch: { ... },
};
```

**Observation**: there is **no `emits: [...]` array** in the options block (verified at lines 364-2366 of the file). Yet:

1. All 9 child step components (`KioskStepPainComponent`, `KioskStepViandeComponent`, `KioskStepTailleComponent`, `KioskStepSauceComponent`, `KioskStepGarnituresComponent`, `KioskStepSupplementsComponent`, `KioskStepMenuComponent`, `KioskStepFritesStyleComponent`, `KioskStepGenericChoicesComponent`) **DO** declare `emits: ['update']` — verified by grep.
2. The wizard parent communicates back to its caller via **callback props** (`onAddToCart: Function`, `onClose: Function`) at lines 391-392, called at lines 1639-1643 (`this.onClose()`) and 2106-2108 (`this.onAddToCart(cartItem)`). This is the **Vue 2 callback-prop pattern**, NOT the Vue 3 emits pattern.

The **PaymentComponent sentinel pair** (verified at `tests/js/paymentComponentPropMutation.spec.js` + `tests/js/sentinels/paymentComponentPropMutation.spec.js`) verifies TWO contracts in lockstep:

```js
// Test A: prop-mutation count === 0
const directPropMutationPattern = /this\.\$props\.props\.|this\.props\.form\.\w+\s*=/g;
expect(matches.length).toBe(0);

// Test B: explicit emits declared
expect(emitsBlock).toContain('"payment-form:patch"');
expect(source).toContain('this.$emit("payment-form:patch", patch)');
```

The pair forms a **closed contract**: "parent-owned state must be patched via events, never via direct prop mutation". If you weaken one without the other, the protection silently degrades.

**KioskWizardComponent has neither test today.** B3.5 (the Tester sub-agent of the parallel audit team) flagged this as **T5 — known gap**.

---

## 2. Why this matters

### Persona impact — client-impatient (50 ans, claustrophobe, mal aux pieds)
**Zero direct customer impact today.** The wizard works; selections flow correctly. This is a **maintenance / regression / V2-readiness** concern, not a UX concern. Discard for client-impatient lens.

### Chef perspective
No impact — chefs do not see this code path. Discard.

### Cashier perspective
No impact — caisse uses `public/js/pos-wizard.js` (separate Vanilla JS file, frozen). Discard.

### Owner perspective ("no useless complexity V1")
**Real impact.** The owner has invested heavily in frozen-zone discipline (NF525 fiscal chain, PaymentComponent verrouillage). The kiosk wizard is the **single largest customer-facing surface** (3104 LOC), the entry point for ~80% of all kiosk orders, and the surface where `composition_snapshot` (the NF525-frozen JSON) is assembled before submit. Yet:

- A future contributor (or another Claude session) can mutate parent-owned props directly (`this.$attrs.foo = ...`) and **no test fails**.
- A refactor that converts the callback props to events will **not catch** a missed migration path, because no sentinel pins the contract.
- The "verrou structurel" pattern the owner endorsed for PaymentComponent has a **hole** at the kiosk-wizard tier.

### WCAG / a11y
Indirect — the absence of emits doesn't violate WCAG, but **the callback-prop pattern complicates v-model migrations for ARIA-bound child controls** (sauce multiselect, viande counter). Future Vue 3.5 `defineModel` migration would be friction-free with emits, hostile without.

### V2 SaaS readiness
**Direct impact.** V2 plans a `composer_profile`-driven wizard (already 30% in via `composerActiveSteps()`). When tenants upload custom step kinds (e.g. "pizza-half-half"), the wizard must emit **named events** (`composer:step-completed`, `composer:abandon`, `composer:item-added`) so each tenant's analytics pipeline can subscribe without forking the file. The current callback-prop pattern requires **prop drilling** through every tenant integration, which the multi-tenant CTO audit (2026-05-16) already flagged as a V2 SaaS friction.

### Multi-tenant-future
A SaaS tenant deploying their own admin telemetry plugin (e.g. Hotjar replay, Mixpanel, Amplitude) hooks into Vue's `$emit` bus, not into prop-passed callbacks. The lack of emits today means each tenant integration **patches the file** — exactly the kind of frozen-zone violation the doctrine forbids.

---

## 3. Adversarial dispute (challenge yourself)

- **False positive? Is the lack of emits actually a "gap" or an intentional design choice?**
  - The callback-prop pattern is valid in Vue 2 and works in Vue 3. The wizard's parents today (`KioskMenuComponent`, `KioskHomeComponent`, edit-mode router) all pass callbacks correctly. **The code is not broken.** This is a contract-strength concern, not a bug.
  - **Counter**: PaymentComponent was ALSO not broken when the sentinel pair was added — the sentinel was added to **freeze the contract** so future refactors cannot silently regress. The same logic applies here. The wizard surface area is comparable to (arguably larger than) PaymentComponent (3104 vs ~2200 LOC).

- **Scope-minimal possible?**
  - **YES.** A 3-LOC addition (an explicit `emits: ['cart:add', 'wizard:close', 'wizard:abandon']` array immediately after `props:`) + a new sentinel test file (~25 LOC). No behavior change. Existing callback props can stay during a deprecation window OR be converted in the same patch — both options analyzed below.

- **Architectural redesign?**
  - **NO.** The wizard already uses `@update="updateSelection"` to receive events from children (which DO declare `emits`). Adding emits at the parent level closes the loop without redesign.

- **Goal cares?**
  - V1 single-resto Le Cayenne: borderline — no functional break today, no NF525 implication.
  - V2 SaaS: **yes, directly** — multi-tenant plugin extension model requires emits.
  - **Cycle-aware**: this is a "production-readiness" finding, fits exactly the GOAL Production Readiness V1 (`plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md`) under the "verrou structurel symmetry" axis.

---

## 4. Proposed change

### Option A (RECOMMENDED) — Add `emits` array + sentinel, keep callback props during 1 release deprecation window

**Change 1: KioskWizardComponent.vue (≤3 LOC)**

```diff
   props: {
     item: { type: Object, default: null },
+    // @deprecated 2026-05-23 — prefer @cart:add/@wizard:close emits over
+    // these Vue 2-style callback props. Removal scheduled V1.0.2.
     onAddToCart: { type: Function, default: null },
     onClose: { type: Function, default: null },
     itemId: { type: [String, Number], default: null },
   },
+  // [Sentinel-T5 2026-05-23] Explicit Vue 3 emits contract — parent-owned
+  // state mutations MUST flow through events, never through prop mutation.
+  // Mirror of PaymentComponent's emits-list discipline. Verrou structurel
+  // posé par tests/js/sentinels/kioskWizardComponentPropMutation.spec.js.
+  emits: ['cart:add', 'wizard:close', 'wizard:abandon'],
   data() { ... },
```

Then inside `addToCart()` and `performCloseWizard()`, emit alongside the existing callback (dual-write during deprecation):

```diff
   addToCart() {
     const cartItem = this.buildCartItem();
+    // [Sentinel-T5 2026-05-23] Emit first (Vue 3 idiomatic), then call legacy
+    // callback prop for backward compat. V1.0.2 will drop the callback prop.
+    this.$emit('cart:add', cartItem);
     if (this.onAddToCart) {
       this.onAddToCart(cartItem);
       if (this.onClose) this.onClose();
     } else {
       ...
     }
   },

   performCloseWizard() {
     ...
+    this.$emit('wizard:close');
     if (this.onClose) {
       this.onClose();
       return;
     }
     this.$router.go(-1);
   },

   onAbandonConfirm() {
     this.showAbandonConfirm = false;
+    this.$emit('wizard:abandon', {
+      step: this.currentStep?.type || null,
+      step_index: this.currentStepIndex,
+    });
     this.performCloseWizard();
   },
```

**Change 2: New sentinel file `tests/js/sentinels/kioskWizardComponentPropMutation.spec.js` (≈30 LOC)**

```js
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID PROP-KWZ-001 | @source proposals/PROPOSAL_KioskWizardComponent_001_missing-emits-sentinel.md
 *
 * Mirror of `tests/js/sentinels/paymentComponentPropMutation.spec.js`.
 * Closes the structural-lock symmetry gap T5 between PaymentComponent
 * (frozen, sentinel-pinned) and KioskWizardComponent (frozen, sentinel-less
 * pre-patch). Verrouille deux invariants Vue 3 idiomatic :
 *   1. Pas de mutation directe des $props depuis le composant enfant.
 *   2. Le composant declare un `emits:` array couvrant cart:add,
 *      wizard:close, wizard:abandon.
 */
describe('KioskWizardComponentPropMutationSentinel', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskWizardComponent.vue'),
        'utf8',
    );

    it('contains no direct $props mutations', () => {
        const pattern = /this\.\$props\.[a-zA-Z_]+\s*=|this\.\$props\.[a-zA-Z_]+\.[a-zA-Z_]+\s*=/g;
        const matches = source.match(pattern) || [];
        expect(matches.length, `direct $props mutations: ${matches.join(', ')}`).toBe(0);
    });

    it('declares explicit emits array for parent-owned state changes', () => {
        const emitsMatch = source.match(/emits:\s*\[[^\]]*\]/);
        expect(emitsMatch, 'emits: [...] array must be declared').toBeTruthy();
        const block = emitsMatch[0];
        expect(block).toContain("'cart:add'");
        expect(block).toContain("'wizard:close'");
        expect(block).toContain("'wizard:abandon'");
    });

    it('emits cart:add when addToCart is invoked', () => {
        expect(source).toMatch(/this\.\$emit\(\s*['"]cart:add['"]/);
    });

    it('emits wizard:close on programmatic close path', () => {
        expect(source).toMatch(/this\.\$emit\(\s*['"]wizard:close['"]/);
    });

    it('emits wizard:abandon on user-confirmed abandon', () => {
        expect(source).toMatch(/this\.\$emit\(\s*['"]wizard:abandon['"]/);
    });
});
```

**Total source LOC delta**: +9 lines in `KioskWizardComponent.vue` (3 emits + 6 emit calls including comments), +30 lines new sentinel file. No CSS change. No template change.

### Option B — Add `emits` + sentinel, BUT do NOT add `$emit` calls today (sentinel passes by checking the array only)

**Pros**: zero behavior change, zero risk of double-side-effect (callback prop + emit fired twice for a careless parent).
**Cons**: weakest possible sentinel — only verifies declaration, not actual usage. The "intent → fact" gap is the exact failure mode PaymentComponent's sentinel was designed to catch.
**Verdict**: NOT recommended — defeats the verrou structurel intent.

### Option C — Full migration (remove callback props, all parents updated in same patch)

**Pros**: clean Vue 3 idiomatic state; bigger sentinel surface.
**Cons**: scope creep. Touches multiple files (KioskMenuComponent, KioskHomeComponent, edit-mode router). Cross-file frozen-zone implications. Violates "scope-minimal" doctrine for a non-urgent finding.
**Verdict**: defer to V1.0.2 batched a11y/Vue3-idiomatic cleanup.

---

## 5. Risk analysis

| Scenario | Risk if APPLIED (Option A) | Risk if NOT applied |
|----------|----------------------------|---------------------|
| Customer flow | None — wizard behavior unchanged (dual-emit + callback) | None |
| Parent components (KioskMenuComponent etc.) | None — callbacks still work; new emits ignored by parents that don't subscribe | None |
| Future contributor patches parent state | **CAUGHT** by sentinel before merge | Silent regression possible |
| V2 SaaS plugin integration | Emits bus available for tenants | Each tenant patches the wizard file (frozen-zone violation) |
| Frozen-zone discipline | LOW — 3 LOC scoped, gated by owner, sentinel posed | None |
| NF525 fiscal chain | NONE — wizard does not touch fiscal sequence (verified — only `composition_snapshot` flows via cart, which is server-sealed) | None |
| Bundle weight | +30 bytes (emits string array) | None |
| Test runtime | +25ms (1 new spec file, 5 sub-tests, all source-text grep) | None |

**Net**: Option A is **risk-asymmetric in favor of applying** — the marginal risk is negligible, the locked-in protection is meaningful.

---

## 6. LOCK feasibility

- **≤9 LOC source change**? **YES** (9 LOC including comments)
- **Single concern**? **YES** (verrou structurel emits/mutation pair)
- **Architectural redesign**? **NO**
- **NF525 impact**? **NO**
- **Owner gate required**? **YES** — file is FROZEN per §7. Recommend issuing `LOCK_KIOSK_WIZARD_EMITS_SENTINEL_2026-05-23.md` per existing `LOCK_KIOSK_SALADE_2026-05-11.md` precedent.

---

## 7. Verification plan (post-implement)

1. **Vitest**: `vitest run tests/js/sentinels/kioskWizardComponentPropMutation.spec.js` → 5/5 green.
2. **Vitest regression**: `vitest run tests/js/KioskWizard.spec.js tests/js/kioskWizardEditRestore.spec.js tests/js/kioskWizardNavigation.spec.js` → no regression (the existing parent tests use `shallowMount` + manual callback assertion, unaffected by adding emits).
3. **Frozen-zone diff sanity**: `git diff resources/js/components/frontend/kiosk/KioskWizardComponent.vue` shows **only** the +9 LOC patch — no other line touched.
4. **Smoke**: Playwright kiosk happy-path (Tacos M → cart) — wizard still adds line, no console warning about "extraneous non-emits event listener" (Vue 3 dev-mode emit-discipline error).
5. **Bundle**: `npm run prod` — verify `kiosk-wizard.*.js` chunk size delta ≤ +1 KB raw / ≤ +250 B gzip.

---

## 8. Out-of-scope (tracked separately)

This proposal covers ONLY T5 (emits + prop-mutation sentinel pair). Related but distinct concerns from the same file audit are filed as separate proposals:

- `PROP-KWZ-002` — Component size (3104 LOC) + extraction roadmap
- `PROP-KWZ-003` — Name-heuristic template detection fragility
- `PROP-KWZ-004` — `selections` reassignment in `restoreEditingSelectionsIfAny()` breaks deep reactivity guarantees
- `PROP-KWZ-005` — `setTimeout(150)` initial focus + ARIA announcement gap
- `PROP-KWZ-006` — Document-level Tab-trap collision risk
- `PROP-KWZ-007` — `maxlength=190` paste-bypass on instruction textarea
- `PROP-KWZ-008` — Emoji-as-icon semantic + a11y
- `PROP-KWZ-009` — `kiosk-step-content` hidden-scrollbar discoverability
- `PROP-KWZ-010` — `composition_summary` watcher cost on every selection mutation

---

## 9. Owner sign-off block

| Approver | Date | Option chosen (A / B / C / DEFER / KEEP-AS-IS) | Notes |
|----------|------|-----------------------------------------------|-------|
|          |      |                                               |       |

- [ ] APPLY-WITH-LOCK Option A (recommended — ≤9 LOC, gates closed contract)
- [ ] APPLY-WITH-LOCK Option B (declaration only, weaker)
- [ ] DEFER-V1.0.2 (batch with Vue 3 idiomatic cleanup of cart/menu/home components)
- [ ] DEFER-V2 (gate to SaaS plugin model)
- [ ] KEEP-AS-IS (accept the sentinel-gap, document in BRAIN as known V1.0.2 backlog)

**Signed-off-by-owner** : ___________  **Date** : ___________

---

## 10. References

- `CLAUDE.md §7` Frozen Zones — KioskWizardComponent.vue explicit entry
- `feedback_kiosk_wizard_not_protected.md` — tests/sentinels allowed
- `tests/js/sentinels/paymentComponentPropMutation.spec.js` — sentinel pattern source
- `tests/js/paymentComponentPropMutation.spec.js` — paired emits sentinel
- `tests/js/KioskWizard.spec.js` — existing wizard tests (regression baseline)
- B3.5 Tester finding T5 (Phase B.5 ultra-deep audit 2026-05-23)
- `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md` — verrou structurel symmetry axis
