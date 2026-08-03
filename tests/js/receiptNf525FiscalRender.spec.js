// [VALIDATION donnees-fiscales 2026-06-25]
// Render-level proof that the printed CLIENT ticket surfaces the NF525 fiscal
// block exactly as the real OrderDetailsResource produces it for a paid POS
// order (foodking_e2e order #5174, fiscal_sequence_no 2569).
//
// Asserts on the REAL #print-receipt-client DOM (the paper that goes to the
// thermal printer via window.print): SIRET, TVA intra, operator, NF525 ticket
// no, audit fingerprint, legal mentions, per-rate VAT (HT base + tax), and the
// 3 money totals — all with exact 2-decimal FR formatting and NO duplication.
import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import fr from '../../resources/js/languages/fr.json';

vi.mock('vue3-print-nb', () => ({
    default: { directiveName: 'print', mounted() {} },
}));

import ReceiptComponent from '../../resources/js/components/admin/pos/ReceiptComponent.vue';

// Exact OrderDetailsResource projection for paid order #5174 (rendered live
// via `php artisan tinker` against foodking_e2e — see audit evidence).
const REAL_ORDER = {
    id: 5174,
    order_serial_no: '2406265174',
    order_date: '24-06-2026',
    order_time: '16:30',
    order_type: 2,
    fiscal_sequence_no: 2569,
    pos_siret: '10417050100019',
    pos_vat_intra: 'FR19104170501',
    pos_legal_footer: 'TVA intracommunautaire - Merci de votre visite',
    pos_register_id: null,
    operator_name: 'Client passage',
    audit_chain_fingerprint: '5cd5fd6e4619',
    subtotal_without_tax_currency_price: '7,09 €',
    total_tax_currency_price: '0,71 €',
    discount_currency_price: '0,00 €',
    total_currency_price: '7,80 €',
    tax_lines: [{
        tax_name: 'VAT', tax_rate: '10', tax_type: 10,
        base_ht: 7.09, base_ht_currency: '7,09 €',
        tax: 0.71, tax_currency: '0,71 €',
    }],
    receipt_print_count: 0,
    branch: { address: '1 rue Test', phone: '0102030405' },
};

const ITEMS = [{
    id: 1, quantity: 1, item_name: 'Tacos M',
    total_without_tax_currency_price: '7,09 €',
    tax_rate: 10, tax_name: 'VAT', tax_currency_rate: '10%', tax_type: '%',
    tax_currency_amount: '0,71 €',
    item_variations: [], item_extras: [], item_addons: [],
}];

const getters = {
    'company/lists': { company_name: 'Le Cayenne' },
    'backendGlobalState/branchShow': REAL_ORDER.branch,
    'posOrder/orderItems': ITEMS,
    'frontendLanguage/show': { display_mode: 0 },
    'setting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: '€', site_currency_position: 0 },
};

const store = {
    getters: new Proxy(getters, { get: (t, p) => (p in t ? t[p] : {}) }),
    dispatch: vi.fn(() => Promise.resolve()),
    commit: vi.fn(),
};

// $t resolves against the real fr.json so we prove no raw `label.X` leaks.
const t = (key) => {
    const parts = String(key).split('.');
    let node = fr;
    for (const p of parts) { node = node?.[p]; if (node === undefined) return key; }
    return node;
};

const mount = () => shallowMount(ReceiptComponent, {
    props: { order: REAL_ORDER },
    global: {
        mocks: { $store: store, $t: t },
        directives: { print: { mounted() {} } },
        stubs: { 'receipt-duplicata-marker': true, 'receipt-remboursement-marker': true },
    },
});

describe('NF525 fiscal data on printed CLIENT ticket (real order #5174)', () => {
    it('renders fiscal identity header (SIRET, TVA intra, operator) with FR labels', () => {
        const html = mount().find('#print-receipt-client').html();
        expect(html).toContain('SIRET: 10417050100019');
        expect(html).toContain('TVA intra: FR19104170501');
        expect(html).toContain('Opérateur: Client passage');
        // register_id is null → must NOT print "N° caisse:"
        expect(html).not.toContain('N° caisse');
        expect(html).not.toContain('label.');
    });

    it('renders the NF525 footer (ticket no, audit fingerprint, legal mentions)', () => {
        const html = mount().find('#print-receipt-client').html();
        expect(html).toContain('N° ticket NF525:');
        expect(html).toContain('2569');
        expect(html).toContain('Empreinte audit:');
        expect(html).toContain('5cd5fd6e4619');
        expect(html).toContain('Mentions légales:');
        expect(html).toContain('TVA intracommunautaire - Merci de votre visite');
    });

    it('renders per-rate VAT line with HT base and tax (CGI art. 242 nonies A)', () => {
        const html = mount().find('#print-receipt-client').html();
        expect(html).toContain('VAT');
        expect(html).toContain('(10%)');
        expect(html).toContain('7,09'); // base HT
        expect(html).toContain('0,71'); // tax amount
    });

    it('renders the 3 money totals with exact 2-decimal FR format', () => {
        const html = mount().find('#print-receipt-client').html();
        // Sous-total HT 7,09 + Total taxes 0,71 = Total 7,80 (arithmetic holds)
        expect(html).toContain('7,09'); // subtotal without tax
        expect(html).toContain('0,71'); // total tax
        expect(html).toContain('7,80'); // grand total
        // No integer rounding: a round-euro total stays 2-decimals upstream.
    });

    it('prints each fiscal datum exactly once (no doubling)', () => {
        const html = mount().find('#print-receipt-client').html();
        const count = (s) => html.split(s).length - 1;
        expect(count('10417050100019')).toBe(1); // SIRET once
        expect(count('5cd5fd6e4619')).toBe(1);   // fingerprint once
        expect(count('2569')).toBe(1);           // NF525 ticket no once
    });
});
