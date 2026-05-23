# PROPOSAL 013 — Analytics try/catch hides the no-consent silent-drop;
upsell impressions vanish without ops signal

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P4 (observability nuance)
**Reasoning angle**: Client-impatient persona (indirect)

---

## Observation

Three analytics emit sites in the component:

- Line 172–176 — `upsell_shown` (in `loadSuggestions`)
- Line 247–253 — `upsell_accepted` (in `addAndContinue`)
- Line 261–266 — `upsell_rejected` (in `skip`)

All three are wrapped in `try { ... } catch (_) {}`. The catch is empty:

```js
try {
  kioskAnalytics.track('upsell_shown', { suggested_count: this.suggestions.length });
} catch (_) {}
```

But `kioskAnalytics.track()` (helpers/kioskAnalytics.js:263–280) **never
throws**. It returns `false` if:

- Consent not granted (`!state.consent`)
- Event name not in whitelist
- Payload contains PII

The try/catch is **defensive overkill** — won't ever fire. Worse, it
hides the legitimate cases:

- **No consent**: every `upsell_shown` call is silently dropped. There is
  no way to know whether 0 analytics events come from *"no customers"*
  vs. *"every customer declined consent at the cookie banner"*.
- **Returns false**: silent-drop. Code can't distinguish "queued
  successfully" from "rejected due to PII" from "no consent".

## Risks

- KPIs based on `upsell_shown` count appear lower than reality.
- Ops cannot diagnose *"why does our upsell conversion look weird this
  week?"* — could be a consent change, a network outage, an analytics
  controller bug, or a real conversion drop.
- Future bugs in `kioskAnalytics.track` that DO throw would still be
  hidden by the empty catch.

## Proposed fix

### A — Drop the try/catch (track already non-throwing)

```js
kioskAnalytics.track('upsell_shown', {
  suggested_count: this.suggestions.length,
});
```

If a future refactor introduces a throw in track, the right answer is
to fix track, not to wrap each call site.

### B — Capture the return value for high-value events

```js
const tracked = kioskAnalytics.track('upsell_shown', { ... });
if (!tracked) {
  // Lightweight local counter for ops dashboards
  this._localMetrics.upsell_shown_dropped += 1;
}
```

Expose dropped counts via a debug endpoint or dev console — not for
production replay, just for hand-debugging.

### C — Add a dedicated `consent_required_for_upsell` warning event

If consent is missing, push a single `idle_event` outside the analytics
whitelist (using the `trackKioskErrorEvent` channel) so ops at least
know analytics is **expected but suppressed**. This event is
gate-bypassing — emitted regardless of consent — and contains zero PII.

**Recommendation**: A is enough; B + C are bonuses. The empty try/catch
is harmful pattern and should be removed by default.

## Scope estimate

- ~5 LOC removed from `KioskUpsellComponent.vue` (frozen — LOCK doc).
- Documentation note added to `kioskAnalytics.js` JSDoc:
  *"`track()` never throws — wrapping call sites in try/catch is
  unnecessary."*

## Acceptance criteria

- Component no longer wraps analytics calls in empty try/catch.
- Vitest assertion: a mocked `kioskAnalytics.track` that throws → causes
  the component to fail (showing the wrapper is no longer needed to
  protect the user-facing flow).
- Code review: confirm no other kiosk component has the same defensive
  wrapping pattern (or document why it should be kept everywhere).

## Rollback

Trivial single-file revert.
