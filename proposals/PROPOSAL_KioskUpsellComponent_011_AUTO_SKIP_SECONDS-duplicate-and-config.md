# PROPOSAL 011 — `AUTO_SKIP_SECONDS` constant duplicated; not configurable
per branch

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P4 (code hygiene · ops flexibility)
**Reasoning angle**: Client-impatient persona

---

## Observation

Module-level (line 125):

```js
const AUTO_SKIP_SECONDS = 30;
```

Then exposed as a computed property (lines 146–150):

```js
computed: {
  ...
  AUTO_SKIP_SECONDS() { return AUTO_SKIP_SECONDS; },
},
```

This pattern means:

1. The component is **not consuming branch configuration** for the
   auto-skip duration. Every restaurant runs the same 30s.
2. Duplication of `AUTO_SKIP_SECONDS` in two scopes (module + computed)
   to expose it to the template. Minor smell.
3. There is no way to disable auto-skip for an attendant-mode kiosk or
   to extend it for an elderly-friendly mode.

## Risks

- Restaurant manager cannot configure pace per branch (busy lunch vs.
  quiet afternoon).
- Accessibility users / attendant kiosks have no opt-out.

## Proposed fix

### A — Read from `globalState.lists` config

`globalState.lists` already carries per-branch settings (currency,
position, digits). Extend with `kiosk_upsell_auto_skip_seconds` (default
30, range 10–120):

```js
data() {
  return {
    ...,
    autoSkipSeconds: this.$store.state.globalState?.lists?.kiosk_upsell_auto_skip_seconds || 30,
  };
},
computed: {
  AUTO_SKIP_SECONDS() { return this.autoSkipSeconds; },
},
```

Backend admin UI gains a setting under Branch → Kiosk → Upsell pace.

### B — Disable via a sentinel value (0 or null)

If `autoSkipSeconds <= 0`, never start the timer; skip button always
present, customer-driven dismissal only.

### C — A11y user setting

If `globalState.a11y.reduced_pace` is true, multiply by 2 (60s default).

**Recommendation**: A + B. Per-branch tunability with explicit
disable-by-zero is enough. C is a bonus but couples to broader a11y
preferences which may not exist yet.

## Scope estimate

- 1 backend migration (settings table or branch table column).
- ~10 LOC in `KioskUpsellComponent.vue` (frozen — LOCK doc).
- Admin UI form addition.

## Acceptance criteria

- `kiosk_upsell_auto_skip_seconds = 60` → progress bar takes 60s to
  empty.
- `kiosk_upsell_auto_skip_seconds = 0` → no auto-skip; button-only
  dismissal; progressbar hidden.
- Default (no setting) → 30s, identical to today.

## Rollback

Three-file revert. Default behavior is a no-op.
