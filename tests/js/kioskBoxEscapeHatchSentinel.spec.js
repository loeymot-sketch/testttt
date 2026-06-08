import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 6] Box render escape-hatch sentinel.
 *
 * A full-price box renders via the NON-frozen KioskStepGenericChoicesComponent. For a step
 * to fall through to it, its step_key must NOT be in STEP_KEY_REGISTRY and its addon_role must
 * NOT be in ADDON_ROLE_TO_TYPE (those route to the FROZEN specialized/menu components). These
 * invariants live in the frozen KioskWizardComponent — this sentinel pins them so a future edit
 * can't silently re-route a box page into the frozen menu-ratio path.
 *
 * It also pins the pricing-display asymmetry that makes EXTRA components (not addon components)
 * the correct non-frozen box modelling: buildCartItem sums composerExtraTotal into the displayed
 * line total but has NO composerAddonTotal — so an addon-role box would under-display (server still
 * charges full). The extra_group box displays AND bills full. (Addon-box display = owner gate.)
 */
const root = process.cwd();
const SRC = fs.readFileSync(
  path.join(root, 'resources/js/components/frontend/kiosk/KioskWizardComponent.vue'),
  'utf8'
);

function registryBlock(constName) {
  const start = SRC.indexOf(`const ${constName} = Object.freeze({`);
  expect(start, `${constName} must exist`).toBeGreaterThan(-1);
  return SRC.slice(start, SRC.indexOf('});', start));
}

describe('[W6] box generic-choices escape-hatch invariants', () => {
  it('STEP_KEY_REGISTRY does not claim a "box" key — box_* step_keys fall through to generic', () => {
    const registry = registryBlock('STEP_KEY_REGISTRY');
    expect(/\bbox\s*:/.test(registry)).toBe(false);
    expect(/\bbox_/.test(registry)).toBe(false);
  });

  it('ADDON_ROLE_TO_TYPE does not map "upsell" — full-price addons avoid the frozen menu component', () => {
    const roles = registryBlock('ADDON_ROLE_TO_TYPE');
    expect(/upsell/.test(roles)).toBe(false);
    // The roles that DO route to the frozen menu component (kept as the documented contract).
    expect(/menu_component/.test(roles)).toBe(true);
  });

  it('unknown step with non-empty choices falls back to the non-frozen generic component', () => {
    expect(SRC).toContain('KioskStepGenericChoices');
    // The documented fallback behaviour (registry comment) must remain.
    expect(SRC).toMatch(/sans choices, il est loggué puis skippé|generic/i);
  });

  it('display total sums composerExtraTotal but has NO composerAddonTotal (why box components are extras)', () => {
    expect(SRC).toContain('composerExtraTotal');
    expect(SRC).toContain('itemExtraTotal += composerExtraTotal');
    // No addon display-total exists — pinning the gap that makes extra_group the non-frozen box path.
    expect(SRC).not.toContain('composerAddonTotal');
  });
});
