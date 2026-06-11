// [UIUX-W2 H2 2026-06-11] Sentinels for the W2 (lot 2) heal clusters G1-G7.
// Static-source assertions (same pattern as userReportedBlockersRuntime.spec.js):
// each block locks a healed defect so it cannot silently regress.
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const root = resolve(process.cwd());
const read = (rel) => readFileSync(resolve(root, rel), 'utf-8');

describe('G1 — navbar profile popup is a valid ARIA dialog (no invalid menu children)', () => {
  it('drops role="menu"/menuitem in favor of role="dialog" popup', () => {
    const navbar = read('resources/js/components/layouts/backend/BackendNavbarComponent.vue');

    // The panel contains a profile card (figure/file-input/email) — it can never
    // be a valid role="menu" (axe aria-required-children CRITICAL on every page).
    expect(navbar).not.toMatch(/^\s*role="menu"\s*$/m);
    expect(navbar).not.toMatch(/role="menuitem"\s+tabindex/);
    expect(navbar).toMatch(/role="dialog"/);
    expect(navbar).toMatch(/aria-haspopup="dialog"/);
    // Keyboard helper must not query the removed menuitem role.
    expect(navbar).not.toMatch(/querySelectorAll\('\[role="menuitem"\]'\)/);
    expect(navbar).toMatch(/querySelectorAll\('\.paper-link'\)/);
  });
});
