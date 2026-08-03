/**
 * Round4 A11y heal — modal focus-trap + icon-button accessible names.
 *
 * Pings the WCAG 2.4.3 (Focus Order) / 2.1.1 (Keyboard) / 4.1.2 (Name) gaps
 * from reports/test-e2e/all-systems-2026-06-26/round4/a11y-wcag.md :
 *   1. focusTrap mixin (factored from ds/KsModal.vue) — focus first, Tab cycle
 *      first<->last with preventDefault, restore on close.
 *   2. KioskCartComponent "vider le panier" dialog — focus moves into the
 *      dialog on open + is restored to the trigger on close.
 *   3. PosLoyaltyRedeemModal (representative POS money modal) — focus enters,
 *      Tab wraps, focus restored on close.
 *   4. PosRefundModal / PosCounterCollectModal / PosLoyaltyRedeemModal +
 *      ItemComponent + CreateCustomerAddressComponent — accessible names /
 *      trap wiring present (source-level, robust against mount fragility).
 *
 * Focus assertions REQUIRE attachTo: document.body so document.activeElement
 * reflects real focus.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, shallowMount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import focusTrap from '../../../resources/js/mixins/focusTrap';
import KioskCartComponent from '../../../resources/js/components/frontend/kiosk/KioskCartComponent.vue';
import PosLoyaltyRedeemModal from '../../../resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue';
import ItemComponent from '../../../resources/js/components/admin/pos/ItemComponent.vue';
import frMessages from '../../../resources/js/languages/fr.json';

const FOCUSABLE_SELECTOR =
    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

// The activate chain is up to 3 nextTicks deep (reactive flush -> open watcher
// $nextTick -> activateFocusTrap $nextTick -> focus). flushPromises drains the
// whole microtask chain; the trailing nextTick is belt-and-braces.
async function settle() {
    await flushPromises();
    await nextTick();
}

let activeWrapper = null;
afterEach(() => {
    if (activeWrapper) {
        activeWrapper.unmount();
        activeWrapper = null;
    }
    document.body.innerHTML = '';
});

/* ============================================================================
 * 1. focusTrap mixin (unit) — the canonical pattern, isolated.
 * ========================================================================== */
const Host = {
    mixins: [focusTrap],
    data() {
        return { open: false };
    },
    watch: {
        open(v) {
            if (v) this.$nextTick(() => this.activateFocusTrap(this.$refs.panel));
            else this.deactivateFocusTrap();
        },
    },
    template: `
        <div>
            <button data-testid="outside">outside</button>
            <div v-if="open" ref="panel" role="dialog" aria-modal="true" tabindex="-1">
                <button data-testid="first">first</button>
                <input data-testid="mid" />
                <button data-testid="last">last</button>
            </div>
        </div>
    `,
};

describe('focusTrap mixin (canonical KsModal pattern, factored)', () => {
    it('moves focus to the first focusable inside the panel on activate', async () => {
        const wrapper = mount(Host, { attachTo: document.body });
        activeWrapper = wrapper;
        wrapper.find('[data-testid="outside"]').element.focus();
        wrapper.vm.open = true;
        await settle();
        expect(document.activeElement).toBe(
            wrapper.find('[data-testid="first"]').element
        );
    });

    it('Tab from the LAST focusable wraps back to the first (preventDefault)', async () => {
        const wrapper = mount(Host, { attachTo: document.body });
        activeWrapper = wrapper;
        wrapper.vm.open = true;
        await settle();
        const first = wrapper.find('[data-testid="first"]').element;
        const last = wrapper.find('[data-testid="last"]').element;
        last.focus();
        expect(document.activeElement).toBe(last);
        const ev = new KeyboardEvent('keydown', {
            key: 'Tab',
            bubbles: true,
            cancelable: true,
        });
        last.dispatchEvent(ev);
        expect(ev.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(first);
    });

    it('Shift+Tab from the FIRST focusable wraps to the last (preventDefault)', async () => {
        const wrapper = mount(Host, { attachTo: document.body });
        activeWrapper = wrapper;
        wrapper.vm.open = true;
        await settle();
        const first = wrapper.find('[data-testid="first"]').element;
        const last = wrapper.find('[data-testid="last"]').element;
        first.focus();
        const ev = new KeyboardEvent('keydown', {
            key: 'Tab',
            shiftKey: true,
            bubbles: true,
            cancelable: true,
        });
        first.dispatchEvent(ev);
        expect(ev.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(last);
    });

    it('restores focus to the trigger element on deactivate', async () => {
        const wrapper = mount(Host, { attachTo: document.body });
        activeWrapper = wrapper;
        const outside = wrapper.find('[data-testid="outside"]').element;
        outside.focus();
        wrapper.vm.open = true;
        await settle();
        expect(document.activeElement).not.toBe(outside);
        wrapper.vm.open = false;
        await settle();
        expect(document.activeElement).toBe(outside);
    });
});

/* ============================================================================
 * 2. KioskCartComponent — "vider le panier" confirm dialog.
 * ========================================================================== */
function makeKioskStore() {
    return createStore({
        modules: {
            kioskCart: {
                namespaced: true,
                getters: {
                    items: () => [
                        { item_id: 1, name: 'Tacos', quantity: 1, total: 7.9, convert_price: 7.9 },
                    ],
                    count: () => 1,
                    subtotal: () => 7.9,
                    total: () => 7.9,
                    loyaltyDiscount: () => 0,
                    upsellShown: () => false,
                    orderType: () => 25,
                    promoCode: () => '',
                    promoDiscount: () => 0,
                    promoError: () => null,
                    promoLoading: () => false,
                },
                actions: {
                    updateQuantity: vi.fn(),
                    removeItem: vi.fn(),
                    reset: vi.fn(),
                    markUpsellShown: vi.fn(),
                    popItem: vi.fn(),
                    setOrderType: vi.fn(),
                    quoteOrder: vi.fn(),
                    validatePromo: vi.fn(),
                    clearPromo: vi.fn(),
                    startEditingCartItem: vi.fn(),
                    pruneUnavailableLines: vi.fn(),
                },
            },
            kioskMenu: {
                namespaced: true,
                getters: {
                    categories: () => [],
                    selectedCategoryId: () => null,
                    allItems: () => [],
                },
            },
            frontendSetting: {
                namespaced: true,
                getters: { lists: () => ({ pos_dine_in_enabled: 0 }) },
                actions: { lists: vi.fn() },
            },
            kioskSettings: {
                namespaced: true,
                getters: { customerProfile: () => null },
            },
        },
    });
}

const kioskI18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
    missingWarn: false,
    fallbackWarn: false,
});

describe('KioskCartComponent — clear-cart dialog focus trap (P2)', () => {
    function mountKioskCart() {
        return mount(KioskCartComponent, {
            attachTo: document.body,
            global: {
                plugins: [makeKioskStore(), kioskI18n],
            },
        });
    }

    it('moves focus to the cancel ("Non") button when the dialog opens', async () => {
        const wrapper = mountKioskCart();
        activeWrapper = wrapper;
        wrapper.vm.showClearConfirm = true;
        await settle();
        const cancelBtn = wrapper.find('[data-testid="kiosk-cart-clear-no"]').element;
        expect(document.activeElement).toBe(cancelBtn);
    });

    it('restores focus to the trigger after the dialog closes', async () => {
        const wrapper = mountKioskCart();
        activeWrapper = wrapper;
        const trigger = wrapper.find('[data-testid="kiosk-cart-clear"]').element;
        trigger.focus();
        wrapper.vm.showClearConfirm = true;
        await settle();
        expect(document.activeElement).not.toBe(trigger);
        wrapper.vm.showClearConfirm = false;
        await settle();
        expect(document.activeElement).toBe(trigger);
    });

    it('clear-confirm dialog div carries tabindex="-1" so it can hold focus', async () => {
        const wrapper = mountKioskCart();
        activeWrapper = wrapper;
        wrapper.vm.showClearConfirm = true;
        await settle();
        const dialog = wrapper.find('[data-testid="kiosk-cart-clear-modal"]');
        expect(dialog.attributes('tabindex')).toBe('-1');
    });
});

/* ============================================================================
 * 3. PosLoyaltyRedeemModal — representative POS money modal.
 * ========================================================================== */
describe('PosLoyaltyRedeemModal — focus trap + restore (P3)', () => {
    function mountModal() {
        return mount(PosLoyaltyRedeemModal, {
            attachTo: document.body,
            props: { open: false, orderId: 42, rate: 100 },
            global: { mocks: { $t: (k) => k } },
        });
    }

    it('moves focus into the dialog (code input) when opened', async () => {
        const trigger = document.createElement('button');
        document.body.appendChild(trigger);
        trigger.focus();

        const wrapper = mountModal();
        activeWrapper = wrapper;
        await wrapper.setProps({ open: true });
        await settle();
        const codeInput = wrapper.find('[data-testid="pos-loyalty-redeem-code-input"]').element;
        expect(document.activeElement).toBe(codeInput);
    });

    it('Tab from the last focusable wraps to the first (close button)', async () => {
        const wrapper = mountModal();
        activeWrapper = wrapper;
        await wrapper.setProps({ open: true });
        await settle();
        const panel = wrapper.find('.pos-loyalty-redeem-dialog').element;
        const focusables = Array.from(panel.querySelectorAll(FOCUSABLE_SELECTOR)).filter(
            (el) => !el.hasAttribute('disabled') && el.getAttribute('aria-hidden') !== 'true'
        );
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        expect(focusables.length).toBeGreaterThan(1);
        last.focus();
        const ev = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        last.dispatchEvent(ev);
        expect(ev.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(first);
    });

    it('restores focus to the trigger when the modal closes', async () => {
        const trigger = document.createElement('button');
        document.body.appendChild(trigger);
        trigger.focus();

        const wrapper = mountModal();
        activeWrapper = wrapper;
        await wrapper.setProps({ open: true });
        await settle();
        expect(document.activeElement).not.toBe(trigger);
        await wrapper.setProps({ open: false });
        await settle();
        expect(document.activeElement).toBe(trigger);
    });
});

/* ============================================================================
 * 4a. ItemComponent — icon-only modal-close buttons have accessible names.
 * ========================================================================== */
describe('ItemComponent — modal-close buttons accessible name (P3)', () => {
    it('every .modal-close button exposes a non-empty aria-label', async () => {
        const wrapper = shallowMount(ItemComponent, {
            props: { items: [] },
            global: {
                stubs: { Swiper: true, SwiperSlide: true },
                mocks: {
                    $t: (key) => key,
                    $store: {
                        dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })),
                        getters: {
                            'frontendSetting/lists': {
                                site_digit_after_decimal_point: 2,
                                site_default_currency_symbol: 'EUR',
                                site_currency_position: 'left',
                            },
                        },
                    },
                },
            },
        });
        // Render BOTH modals (info + variation) so both close buttons exist.
        wrapper.vm.itemInfo = { name: 'Tacos', caution: 'Contient gluten' };
        wrapper.vm.item = {
            id: 1,
            name: 'Tacos',
            thumb: '',
            description: 'desc',
            offer: [],
            itemAttributes: [],
            extras: [],
            addons: [],
            caution: '',
        };
        await settle();

        const closeButtons = wrapper.findAll('.modal-close');
        expect(closeButtons.length).toBeGreaterThanOrEqual(2);
        closeButtons.forEach((btn) => {
            const label = btn.attributes('aria-label');
            expect(label).toBeTruthy();
            expect(label.length).toBeGreaterThan(0);
        });
    });
});

/* ============================================================================
 * 4b. Source-level wiring sentinels — robust against mount fragility.
 * ========================================================================== */
describe('Focus-trap + aria-label source wiring (sentinel)', () => {
    const read = (rel) => readFileSync(resolve(process.cwd(), rel), 'utf8');

    it('the focusTrap mixin exists and exposes activate/deactivate', () => {
        const src = read('resources/js/mixins/focusTrap.js');
        expect(src).toContain('activateFocusTrap');
        expect(src).toContain('deactivateFocusTrap');
        expect(src).toMatch(/e\.preventDefault\(\)/);
    });

    it.each([
        'resources/js/components/admin/pos/PosRefundModal.vue',
        'resources/js/components/admin/pos/PosCounterCollectModal.vue',
        'resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue',
    ])('%s imports the focusTrap mixin and wires activate/deactivate', (rel) => {
        const src = read(rel);
        expect(src).toContain("mixins/focusTrap");
        expect(src).toContain('activateFocusTrap');
        expect(src).toContain('deactivateFocusTrap');
        expect(src).toMatch(/ref="panel"/);
    });

    it('PosCounterCollectModal dialog announces aria-modal="true"', () => {
        const src = read('resources/js/components/admin/pos/PosCounterCollectModal.vue');
        expect(src).toMatch(/role="dialog"[\s\S]{0,120}aria-modal="true"|aria-modal="true"[\s\S]{0,120}role="dialog"/);
    });

    it('ItemComponent close buttons carry :aria-label="$t(\'button.close\')"', () => {
        const src = read('resources/js/components/admin/pos/ItemComponent.vue');
        // The close <button> attributes may span two lines, so scan a window
        // after each `modal-close` occurrence rather than the single line.
        const indices = [];
        let from = 0;
        let idx;
        while ((idx = src.indexOf('modal-close', from)) !== -1) {
            indices.push(idx);
            from = idx + 11;
        }
        expect(indices.length).toBeGreaterThanOrEqual(2);
        indices.forEach((i) => {
            const slice = src.slice(i, i + 220);
            expect(slice).toMatch(/aria-label="\$t\('button\.close'\)"/);
        });
    });

    it('POS CreateCustomerAddressComponent close button carries an aria-label', () => {
        const src = read('resources/js/components/admin/pos/CreateCustomerAddressComponent.vue');
        const closeLine = src.split('\n').find((l) => l.includes('modal-close'));
        expect(closeLine).toBeTruthy();
        // aria-label may sit on the next attribute line of the same <button>.
        const idx = src.indexOf('modal-close');
        const slice = src.slice(idx, idx + 200);
        expect(slice).toMatch(/aria-label/);
    });
});
