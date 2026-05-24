import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-L5.3-KEYBOARD-NAV-001
 * @source Phase L5.3 keyboard navigation deep audit (2026-05-24)
 *
 * Sentinel locks the keyboard-accessibility invariants surfaced by the
 * L5.3 audit. It does NOT mount Vue components in jsdom (focus order is
 * unreliable there per advisor guidance) — instead it source-greps the
 * structural keyboard contracts so they cannot silently drift between
 * commits.
 *
 * What this sentinel CANNOT cover:
 *  - real focus order across modal mounts (jsdom focus is unreliable)
 *  - keyboard trap escape under live browser (use Playwright a11y spec)
 *  - `:focus-visible` ring rendering (CSS-level visual, not source-grep)
 *
 * What it DOES lock (regression floor):
 *  - KdsOrderCard.vue tabindex=0 + @keydown.enter binding
 *  - KdsV2Grid.vue [A]-[H] global keydown shortcuts
 *  - KdsHistoryDrawer.vue Escape handler + aria-modal
 *  - KioskWizardComponent.vue Tab-trap + focus return on close
 *  - KioskPaymentComponent.vue Enter/Space on payment methods
 *  - KioskCartComponent.vue Enter on promo input
 *  - PosComponent.vue delivery autocomplete arrow keys + Enter + Esc
 *  - Pos ItemComponent.vue Enter + Space addItem (the only POS grid keys)
 *
 * Path note: task scope spec asked for
 *   `tests/Feature/A11y/KeyboardNavigationSentinel.spec.js`
 * but `tests/Feature/A11y/` is PHPUnit territory and `.spec.js` is Vitest.
 * The project convention places Vitest sentinels under
 *   `tests/js/sentinels/`
 * which is where this file lives.
 */

const ROOT = process.cwd();
const read = (rel) => readFileSync(resolve(ROOT, rel), 'utf8');

describe('Keyboard navigation — KDS bump (FK-L5.3-001)', () => {
    const kdsOrderCard = read('resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue');
    const kdsV2Grid = read('resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue');

    it('KdsOrderCard root is focusable via tabindex=0', () => {
        expect(kdsOrderCard).toMatch(/tabindex="0"/);
    });

    it('KdsOrderCard binds @keydown.enter on the card root', () => {
        expect(kdsOrderCard).toMatch(/@keydown\.enter="onCardKeydownEnter"/);
    });

    it('KdsOrderCard onCardKeydownEnter respects cash-pending gate', () => {
        // The keyboard path mirrors the click gate so a chef cannot bypass
        // the cash-at-counter wait with Enter (Wave S-2 P-OWNER 2026-05-20).
        expect(kdsOrderCard).toMatch(/onCardKeydownEnter\s*\(\)\s*\{[\s\S]*?isCashPending[\s\S]*?return;/);
    });

    it('KdsV2Grid registers a global keydown listener for [A]-[H] shortcuts', () => {
        expect(kdsV2Grid).toMatch(/window\.addEventListener\(\s*['"]keydown['"]/);
        expect(kdsV2Grid).toMatch(/window\.removeEventListener\(\s*['"]keydown['"]/);
        expect(kdsV2Grid).toMatch(/const SHORTCUTS\s*=\s*\[\s*'A'\s*,\s*'B'\s*,\s*'C'\s*,\s*'D'\s*,\s*'E'\s*,\s*'F'\s*,\s*'G'\s*,\s*'H'\s*\]/);
    });

    it('KdsV2Grid [A]-[H] shortcut skips cash-pending rows (defense-in-depth)', () => {
        expect(kdsV2Grid).toMatch(/payment_pending_counter\s*===\s*true/);
    });
});

describe('Keyboard navigation — KDS history drawer (FK-L5.3-001)', () => {
    const kdsHistoryDrawer = read('resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue');

    it('KdsHistoryDrawer declares role=dialog + aria-modal=true', () => {
        expect(kdsHistoryDrawer).toMatch(/role="dialog"/);
        expect(kdsHistoryDrawer).toMatch(/aria-modal="true"/);
    });

    it('KdsHistoryDrawer installs Escape-to-close on document keydown', () => {
        expect(kdsHistoryDrawer).toMatch(/document\.addEventListener\(\s*['"]keydown['"]\s*,\s*this\._onEsc\)/);
        expect(kdsHistoryDrawer).toMatch(/document\.removeEventListener\(\s*['"]keydown['"]\s*,\s*this\._onEsc\)/);
        // The handler must actually check for the Escape key.
        expect(kdsHistoryDrawer).toMatch(/e\.key\s*===\s*['"]Escape['"]/);
    });
});

describe('Keyboard navigation — Kiosk wizard focus trap (FK-L5.3-001)', () => {
    const wizard = read('resources/js/components/frontend/kiosk/KioskWizardComponent.vue');

    it('Kiosk wizard root declares aria-modal=true + tabindex=-1 (programmatic focus)', () => {
        expect(wizard).toMatch(/aria-modal="true"/);
        expect(wizard).toMatch(/tabindex="-1"/);
    });

    it('Kiosk wizard installs a document-level Tab trap (cycles focus)', () => {
        // _wizardRootKeydown captures Tab, computes focusables, and wraps.
        expect(wizard).toMatch(/_wizardRootKeydown\s*=\s*\(e\)\s*=>/);
        expect(wizard).toMatch(/document\.addEventListener\(\s*['"]keydown['"]\s*,\s*this\._wizardRootKeydown/);
        expect(wizard).toMatch(/document\.removeEventListener\(\s*['"]keydown['"]\s*,\s*this\._wizardRootKeydown/);
        expect(wizard).toMatch(/if\s*\(e\.key\s*!==\s*['"]Tab['"]\)/);
    });

    it('Kiosk wizard restores caller focus on unmount', () => {
        expect(wizard).toMatch(/this\._wizardReturnFocusEl\s*=\s*document\.activeElement/);
        expect(wizard).toMatch(/returnFocusEl\.focus\(\)/);
    });

    it('Kiosk wizard abandon modal cycles Tab + Escape closes it', () => {
        expect(wizard).toMatch(/_abandonDocKeydown\s*=\s*\(e\)\s*=>/);
        expect(wizard).toMatch(/e\.key\s*===\s*['"]Escape['"][\s\S]*?onAbandonCancel/);
    });
});

describe('Keyboard navigation — Kiosk payment + cart (FK-L5.3-001)', () => {
    const kioskPayment = read('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');
    const kioskCart = read('resources/js/components/frontend/kiosk/KioskCartComponent.vue');

    it('KioskPaymentComponent wires Enter+Space on every payment method (card/cash/tr)', () => {
        // Match at minimum 3 occurrences of Enter and 3 of Space tied to selectMethod.
        const enter = kioskPayment.match(/@keydown\.enter\.prevent="selectMethod\(/g) || [];
        const space = kioskPayment.match(/@keydown\.space\.prevent="selectMethod\(/g) || [];
        expect(enter.length).toBeGreaterThanOrEqual(3);
        expect(space.length).toBeGreaterThanOrEqual(3);
    });

    it('KioskCartComponent binds Enter on the promo-code input', () => {
        expect(kioskCart).toMatch(/@keydown\.enter\.prevent="applyPromo"/);
    });

    it('KioskCartComponent closes the clear-confirm dialog with Escape', () => {
        expect(kioskCart).toMatch(/@keydown\.esc="showClearConfirm\s*=\s*false"/);
    });
});

describe('Keyboard navigation — POS grid + delivery autocomplete (FK-L5.3-001)', () => {
    const posItem = read('resources/js/components/admin/pos/ItemComponent.vue');
    const pos = read('resources/js/components/admin/pos/PosComponent.vue');

    it('POS ItemComponent grid tiles add an item via Enter or Space', () => {
        expect(posItem).toMatch(/@keyup\.enter\.prevent="addItem\(item\)"/);
        expect(posItem).toMatch(/@keyup\.space\.prevent="addItem\(item\)"/);
    });

    it('POS delivery autocomplete wires arrow keys + Enter + Esc', () => {
        expect(pos).toMatch(/@keydown\.down\.prevent="deliveryNavDown"/);
        expect(pos).toMatch(/@keydown\.up\.prevent="deliveryNavUp"/);
        expect(pos).toMatch(/@keydown\.enter\.prevent="deliveryNavSelect"/);
        expect(pos).toMatch(/@keydown\.esc="deliveryInline\.suggestions\s*=\s*\[\]"/);
    });
});

describe('Keyboard navigation — focus-visible CSS contract (FK-L5.3-001)', () => {
    const appCss = read('public/css/app.css');

    it('Global :focus-visible ring is defined for buttons and [role=button]', () => {
        expect(appCss).toMatch(/button:focus-visible/);
        expect(appCss).toMatch(/\[role="button"\]:focus-visible/);
    });

    it('Kiosk touch surfaces declare a focus-visible outline (WCAG 2.4.7)', () => {
        expect(appCss).toMatch(/\.kiosk-touch-btn:focus-visible/);
    });
});
