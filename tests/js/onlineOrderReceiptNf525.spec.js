// @vitest-environment happy-dom
/**
 * [SEC-FALSIFY-2026-06-08 PRINTER-RECEIPT-4-01] The online-order "Imprimer la facture"
 * receipt (OnlineOrderReceiptComponent) must render the NF525 legal-mention header
 * (SIRET / TVA intra / établissement / opérateur) and footer (fiscal sequence / legal
 * footer) when the OrderDetailsResource supplies them — parity with the POS
 * ReceiptComponent. Before the heal the component printed only company/address/phone,
 * so a document labeled "facture" omitted legally-mandatory fiscal mentions.
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import OnlineOrderReceiptComponent from '../../resources/js/components/admin/onlineOrders/OnlineOrderReceiptComponent.vue';

function mountReceipt(order) {
    return mount(OnlineOrderReceiptComponent, {
        props: {
            order,
            orderItems: [],
            orderUser: { name: 'Client', phone: '', country_code: '+33' },
            orderAddress: {},
        },
        global: {
            directives: { print: {} },
            mocks: {
                $t: (k) => k,
                $store: {
                    getters: {
                        'company/lists': { company_name: 'E.DELICE SAS' },
                        'backendGlobalState/branchShow': { address: '1 rue', phone: '123' },
                        'frontendLanguage/show': { display_mode: 'ltr' },
                    },
                    dispatch: () => Promise.resolve(),
                },
            },
        },
    });
}

describe('OnlineOrderReceiptComponent — NF525 legal mentions (PRINTER-RECEIPT-4-01)', () => {
    it('renders SIRET / TVA intra / register / operator header when the order carries them', () => {
        const w = mountReceipt({
            order_serial_no: 'A0001', order_type: 10, payment_method: 1,
            item_variations: {},
            pos_siret: '10417050100019',
            pos_vat_intra: 'FR19104170501',
            pos_register_id: 'CAISSE-1',
            operator_name: 'Caissier Test',
            fiscal_sequence_no: 2042,
            pos_legal_footer: 'Logiciel certifié NF525',
        });
        const html = w.html();
        expect(html).toContain('10417050100019'); // SIRET
        expect(html).toContain('FR19104170501');  // TVA intra
        expect(html).toContain('CAISSE-1');        // register
        expect(html).toContain('Caissier Test');   // operator
        // Footer: fiscal sequence + legal footer (via buildNf525Footer SSOT).
        expect(html).toContain('2042');
        expect(html).toContain('Logiciel certifié NF525');
    });

    it('omits the fiscal header for a non-fiscal order (no SIRET/operator) — guarded, no empty block', () => {
        const w = mountReceipt({
            order_serial_no: 'A0002', order_type: 10, payment_method: 1, item_variations: {},
            // no pos_siret / pos_vat_intra / pos_register_id / operator_name / fiscal_sequence_no
        });
        const html = w.html();
        expect(html).not.toContain('label.siret');
        expect(html).not.toContain('label.operator');
    });
});
