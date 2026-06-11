// [UIUX-W2 H2 2026-06-11] Sentinels for the W2 (lot 2) heal clusters G1-G7.
// Static-source assertions (same pattern as userReportedBlockersRuntime.spec.js):
// each block locks a healed defect so it cannot silently regress.
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import PosOrderShowComponent from '../../resources/js/components/admin/posOrders/PosOrderShowComponent.vue';

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

describe('G2 — POS a11y: target-size, low-contrast grays, vue-select orphan labels', () => {
  it('gives the vue-select search input a >=24px hit target and fixes the 2.81:1 refresh gray', () => {
    const pos = read('resources/js/components/admin/pos/PosComponent.vue');

    expect(pos).toMatch(/:deep\(\.db-field-control \.vue-input input\)\s*{\s*min-height:\s*24px;/);
    // #9a9a9a on white = 2.81:1 — must not come back on the refresh label.
    expect(pos).not.toMatch(/\.pos-shortcuts__refresh\s*{[^}]*#9a9a9a/);
    expect(pos).toMatch(/\.pos-shortcuts__refresh\s*{[^}]*var\(--pos-v5-muted, #6b6b6b\)/);
  });

  it('names every filter combobox via aria-label (vue-next-select overrides the passed id)', () => {
    const list = read('resources/js/components/admin/posOrders/PosOrderListComponent.vue');
    const hist = read('resources/js/components/admin/orderHistory/HistoriqueListComponent.vue');

    expect(list).toMatch(/id="searchStatus"\s*\n\s*:aria-label="\$t\('label\.status'\)"/);
    expect(list).toMatch(/id="user_id"\s*\n\s*:aria-label="\$t\('label\.customer'\)"/);
    expect(hist).toMatch(/id="searchOrigin"\s*\n\s*:aria-label="\$t\('label\.origin'\)"/);
    expect(hist).toMatch(/id="searchStatus"\s*\n\s*:aria-label="\$t\('label\.status'\)"/);
    expect(hist).toMatch(/id="searchPayment"\s*\n\s*:aria-label="\$t\('label\.payment_status'\)"/);
  });
});

describe('G3 — floorplan: FR labels + explanatory empty/dine-in-off states', () => {
  it('replaces raw EN "seats" with FR and explains the blank canvas', () => {
    const floorplan = read('resources/js/components/admin/pos/FloorplanComponent.vue');

    expect(floorplan).not.toMatch(/}}\s*seats/);
    expect(floorplan).toMatch(/place'/);
    // dine-in disabled (V1 default) must show an explanatory FR state, not a blank canvas
    expect(floorplan).toMatch(/data-testid="floorplan-dinein-off"/);
    expect(floorplan).toMatch(/Le service en salle est désactivé\./);
    expect(floorplan).toMatch(/data-testid="floorplan-empty"/);
    expect(floorplan).toMatch(/Aucune table configurée\./);
    // flag read defensively, same contract as PosComponent.dineInEnabled
    expect(floorplan).toMatch(/pos_dine_in_enabled/);
  });
});

describe('G4 — order show polish: orphan variations, empty state, kiosk customer masking', () => {
  const variationsText = PosOrderShowComponent.methods.variationsText;

  it('never renders orphan "name: " or ": value" variation fragments', () => {
    expect(variationsText({ item_variations: [
      { variation_name: 'Poulet Mariné', name: '' },
      { variation_name: 'Algérienne', name: null },
    ] })).toBe('Poulet Mariné, Algérienne');

    expect(variationsText({ item_variations: [
      { variation_name: 'Viande', name: 'Poulet Mariné' },
      { variation_name: '', name: 'Algérienne' },
    ] })).toBe('Viande: Poulet Mariné, Algérienne');

    expect(variationsText({ item_variations: [{ variation_name: '', name: '' }] })).toBe('');
    expect(variationsText({ item_variations: [] })).toBe('');
    expect(variationsText({})).toBe('');
  });

  it('masks the kiosk machine (admin) account as "Client borne" like the encaissement list', () => {
    const isKiosk = (order, orderUser) =>
      PosOrderShowComponent.computed.isKioskCustomer.call({ order, orderUser });

    expect(isKiosk({ source_surface: 'kiosk' }, { name: 'Admin' })).toBe(true);
    expect(isKiosk({ source_surface: null }, { name: 'soak-kiosk-6a1866ebc6835' })).toBe(true);
    expect(isKiosk({ source_surface: 'pos' }, { name: 'Jean Dupont' })).toBe(false);

    const show = read('resources/js/components/admin/posOrders/PosOrderShowComponent.vue');
    expect(show).toMatch(/\$t\('label\.client_borne'\)/);
    expect(show).toMatch(/data-testid="order-items-empty"/);
    expect(show).toMatch(/Aucun article dans cette commande\./);
  });
});
