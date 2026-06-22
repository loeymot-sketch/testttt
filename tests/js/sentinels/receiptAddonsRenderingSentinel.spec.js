/**
 * [G2-HEAL-03 / G.5 G5-F-002 P1 2026-05-23] Sentinel
 * -------------------------------------------------------------------
 * Lock-in test for the receipt addons rendering fix. Before the fix,
 * `composition_snapshot.addons[]` was silently dropped from the customer
 * ticket — menu_formule bundled drinks (e.g. Coca with Big Burger) were
 * invisible despite being charged via `menuRoleAdjustedAddonPrice`.
 *
 * Asserts:
 *  1. `normalizeReceiptAddons()` exists and shapes the snapshot payload
 *     correctly (uses `addon_name`, not `name`; uses `line_total`, not
 *     `catalog_price`).
 *  2. `ReceiptComponent` renders an `<li>`-equivalent block for each
 *     `item.item_addons[]` line on BOTH client + kitchen tickets.
 *  3. Rendered amount is the ratio-adjusted `line_total`, NEVER the raw
 *     `catalog_price`.
 *
 * Source: Phase G.5 finding G5-F-002 P1 (G2-HEAL-03 prompt)
 */
import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

import {
    normalizeReceiptAddons,
} from '../../../resources/js/helpers/posReceiptBuilder';

vi.mock('vue3-print-nb', () => ({
    default: {
        directiveName: 'print',
        mounted() {},
    },
}));

vi.mock('../../../resources/js/services/alertService', () => ({
    default: {
        success: vi.fn(), warning: vi.fn(), info: vi.fn(),
        error: vi.fn(), default: vi.fn(),
    },
}));

vi.mock('axios');

import ReceiptComponent from '../../../resources/js/components/admin/pos/ReceiptComponent.vue';

const ITEM_WITH_ADDONS = {
    id: 1,
    item_name: 'Big Burger',
    quantity: 1,
    tax_rate: 0,
    total_without_tax_currency_price: '8,00 €',
    item_variations: [],
    item_extras: [],
    // NF525-critical: catalog_price=3.00 but line_total=1.20 (40% drink ratio).
    // The customer ticket MUST show 1.20 — what they were actually charged.
    item_addons: [
        {
            addon_id: 11,
            addon_item_id: 22,
            addon_name: 'Coca-Cola',
            role: 'menu_boisson',
            quantity: 1,
            unit_price: 1.20,
            line_total: 1.20,
            catalog_price: 3.00,
        },
        {
            addon_id: 12,
            addon_item_id: 33,
            addon_name: 'Frites',
            role: 'menu_frites',
            quantity: 2,
            unit_price: 0.90,
            line_total: 1.80,
            catalog_price: 3.00,
        },
    ],
};

const baseGetters = {
    'company/lists': { company_name: 'FK' },
    'backendGlobalState/branchShow': { address: '1 rue', phone: '0102030405' },
    'posOrder/orderItems': [ITEM_WITH_ADDONS],
    'frontendLanguage/show': { display_mode: 0 },
    'setting/lists': {
        site_digit_after_decimal_point: 2,
        site_default_currency_symbol: '€',
        site_currency_position: 1, // RIGHT
    },
};

const storeMock = {
    getters: new Proxy(baseGetters, {
        get(target, property) {
            return property in target ? target[property] : {};
        },
    }),
    dispatch: vi.fn(() => Promise.resolve()),
    commit: vi.fn(),
};

const mountReceipt = () => shallowMount(ReceiptComponent, {
    props: {
        order: {
            id: 42,
            order_serial_no: 'A001',
            receipt_print_count: 0,
            order_type: 1,
            subtotal_without_tax_currency_price: '8,00 €',
            total_tax_currency_price: '0,00 €',
            discount_currency_price: '0,00 €',
            total_currency_price: '11,00 €',
        },
    },
    global: {
        mocks: { $store: storeMock, $t: (k) => k },
        directives: { print: { mounted() {} } },
    },
});

describe('G2-HEAL-03 / G5-F-002 P1 — Receipt addons rendering', () => {
    describe('normalizeReceiptAddons helper', () => {
        it('uses addon_name (snapshot field) as primary name', () => {
            const out = normalizeReceiptAddons([
                { addon_name: 'Coca', name: 'wrong-fallback', quantity: 1, line_total: 1.2 },
            ]);
            expect(out).toHaveLength(1);
            expect(out[0].name).toBe('Coca');
        });

        it('falls back to name then addon_item_name when addon_name absent', () => {
            expect(normalizeReceiptAddons([{ name: 'A', quantity: 1, line_total: 1 }])[0].name).toBe('A');
            expect(normalizeReceiptAddons([{ addon_item_name: 'B', quantity: 1, line_total: 1 }])[0].name).toBe('B');
        });

        it('uses line_total (ratio-adjusted) — NEVER catalog_price', () => {
            const out = normalizeReceiptAddons([
                { addon_name: 'Coca', quantity: 1, line_total: 1.2, catalog_price: 3.00 },
            ]);
            expect(out[0].line_total).toBe(1.2);
            expect(out[0]).not.toHaveProperty('catalog_price');
        });

        it('derives line_total from unit_price * quantity when line_total missing', () => {
            const out = normalizeReceiptAddons([
                { addon_name: 'X', quantity: 2, unit_price: 1.5 },
            ]);
            expect(out[0].line_total).toBe(3);
        });

        it('returns [] for null / undefined / empty inputs', () => {
            expect(normalizeReceiptAddons(null)).toEqual([]);
            expect(normalizeReceiptAddons(undefined)).toEqual([]);
            expect(normalizeReceiptAddons([])).toEqual([]);
        });

        it('filters lines with empty name', () => {
            const out = normalizeReceiptAddons([
                { addon_name: '', quantity: 1, line_total: 1 },
                { addon_name: 'OK', quantity: 1, line_total: 1 },
            ]);
            expect(out).toHaveLength(1);
            expect(out[0].name).toBe('OK');
        });

        it('clamps negative line_total to 0', () => {
            const out = normalizeReceiptAddons([
                { addon_name: 'X', quantity: 1, line_total: -5 },
            ]);
            expect(out[0].line_total).toBe(0);
        });
    });

    describe('ReceiptComponent template — DOM rendering', () => {
        it('renders one addon line per item_addons[] entry on the client ticket', () => {
            const wrapper = mountReceipt();
            const lines = wrapper.findAll('[data-testid="receipt-addon-line"]');
            expect(lines).toHaveLength(2);
            // Both addon names must surface in DOM
            const html = wrapper.html();
            expect(html).toContain('Coca-Cola');
            expect(html).toContain('Frites');
        });

        it('renders the ratio-adjusted line_total — NEVER the catalog_price (NF525 correctness)', () => {
            const wrapper = mountReceipt();
            const html = wrapper.html();
            // line_total = 1.20 must appear; catalog_price = 3.00 must NOT.
            // Use the formatted currency strings (right-position, € suffix).
            // [uiux-deep 2026-06-17] addon price now FR-formatted (comma) — was en-US toFixed "1.20".
            expect(html).toMatch(/\+1,20\s*€/);
            expect(html).toMatch(/\+1,80\s*€/);
            // 3.00 (catalog) should NEVER be rendered for these addon lines.
            // Note: the order total (11,00 €) is fine — we test the addon string
            // shape specifically.
            const addonLines = wrapper.findAll('[data-testid="receipt-addon-line"]');
            const addonText = addonLines.map((n) => n.text()).join(' | ');
            expect(addonText).not.toMatch(/3\.00/);
            expect(addonText).not.toMatch(/3,00/);
        });

        it('renders addon name + qty on the kitchen ticket (no price)', () => {
            const wrapper = mountReceipt();
            const lines = wrapper.findAll('[data-testid="receipt-addon-line-kitchen"]');
            // Kitchen ticket renders ONE wrapper <p> per item that joins all addons inline
            expect(lines.length).toBeGreaterThanOrEqual(1);
            const kitchenText = lines[0].text();
            expect(kitchenText).toContain('Coca-Cola');
            expect(kitchenText).toContain('Frites');
            // Kitchen prep tickets do NOT render prices
            expect(kitchenText).not.toMatch(/1,20\s*€/);
            expect(kitchenText).not.toMatch(/€\s*1,20/);
        });

        it('does not render addon block when item_addons is empty (no empty extras gap)', () => {
            const noAddonGetters = {
                ...baseGetters,
                'posOrder/orderItems': [{
                    ...ITEM_WITH_ADDONS,
                    item_addons: [],
                }],
            };
            const localStore = {
                getters: new Proxy(noAddonGetters, {
                    get(t, p) { return p in t ? t[p] : {}; },
                }),
                dispatch: vi.fn(() => Promise.resolve()),
                commit: vi.fn(),
            };
            const wrapper = shallowMount(ReceiptComponent, {
                props: { order: { id: 42, order_type: 1 } },
                global: {
                    mocks: { $store: localStore, $t: (k) => k },
                    directives: { print: { mounted() {} } },
                },
            });
            expect(wrapper.findAll('[data-testid="receipt-addon-line"]')).toHaveLength(0);
            expect(wrapper.findAll('[data-testid="receipt-addon-line-kitchen"]')).toHaveLength(0);
        });
    });
});
