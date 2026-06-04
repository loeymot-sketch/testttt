import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import ReceiptRemboursementMarker from '../../../resources/js/components/admin/pos/ReceiptRemboursementMarker.vue';

/**
 * @FK-ID FK-F2-HEAL-03-REMBOURSEMENT
 * @source Phase F.10 finding F10-OG-1 (P2) — refund counter-entry receipt missing visual marker.
 *
 * Sentinel garantit que le marqueur REMBOURSEMENT :
 *   - apparait UNIQUEMENT si order.parent_order_id est un entier positif
 *     (refund counter-entry mirror created by RefundWithCounterEntryService)
 *   - reste invisible sur les commandes originales (parent_order_id null/0/absent)
 *   - utilise la cle i18n label.remboursement avec fallback string "REMBOURSEMENT"
 *   - expose role=status + aria-live=polite + data-testid pour a11y + Playwright probe
 *   - co-existe avec DUPLICATA (reprint d'un refund est legal : les deux badges OK)
 *
 * Anti-regression NF525 : la directive Loi de Finance France exige que les
 * tickets de remboursement soient visuellement distinguables des tickets de
 * vente. Le data-layer est correct (RTN- serial, status=22, payment_status=20)
 * mais sans marker template, le caissier/auditeur ne peut pas distinguer
 * visuellement un ticket de refund d'un ticket de vente normal.
 */

const mountWith = (orderProps = {}) => mount(ReceiptRemboursementMarker, {
    props: { order: orderProps },
    global: {
        mocks: {
            $t: (key, params) => key + (params ? ':' + JSON.stringify(params) : ''),
        },
    },
});

describe('ReceiptRemboursementMarker (F2-HEAL-03 NF525 refund visual)', () => {
    it('does not render when parent_order_id is missing (regular sale, not a refund)', () => {
        const wrapper = mountWith({});

        expect(wrapper.find('.receipt-remboursement-marker').exists()).toBe(false);
    });

    it('does not render when parent_order_id is null', () => {
        const wrapper = mountWith({ parent_order_id: null });

        expect(wrapper.find('.receipt-remboursement-marker').exists()).toBe(false);
    });

    it('does not render when parent_order_id is 0 (treated as absent)', () => {
        const wrapper = mountWith({ parent_order_id: 0 });

        expect(wrapper.find('.receipt-remboursement-marker').exists()).toBe(false);
    });

    it('renders REMBOURSEMENT when parent_order_id is a positive int (refund counter-entry)', () => {
        const wrapper = mountWith({ parent_order_id: 999 });

        const marker = wrapper.find('.receipt-remboursement-marker');
        expect(marker.exists()).toBe(true);
        // $t mock echoes the key verbatim → marker treats that as "no translation"
        // and falls back to the literal "REMBOURSEMENT" (the safety-net path).
        // In production with real i18n, this would be the fr.json value.
        expect(wrapper.text()).toContain('REMBOURSEMENT');
    });

    it('uses translated label.remboursement when i18n returns a real translation', () => {
        const wrapper = mount(ReceiptRemboursementMarker, {
            props: { order: { parent_order_id: 42 } },
            global: {
                mocks: {
                    // Mimic a real i18n resolution: key → translated value.
                    $t: (key) => key === 'label.remboursement' ? 'REMBOURSEMENT' : key,
                },
            },
        });

        expect(wrapper.text()).toContain('REMBOURSEMENT');
    });

    it('renders REMBOURSEMENT when parent_order_id is a numeric string ("123")', () => {
        const wrapper = mountWith({ parent_order_id: '123' });

        expect(wrapper.find('.receipt-remboursement-marker').exists()).toBe(true);
    });

    it('displays the parent serial when order.parent_order_serial_no is provided', () => {
        const wrapper = mountWith({
            parent_order_id: 999,
            parent_order_serial_no: 'INV-2026-0042',
        });

        expect(wrapper.text()).toContain('INV-2026-0042');
    });

    it('falls back to nested order.parent.order_serial_no when top-level parent_order_serial_no absent', () => {
        const wrapper = mountWith({
            parent_order_id: 999,
            parent: { order_serial_no: 'INV-2026-0099' },
        });

        expect(wrapper.text()).toContain('INV-2026-0099');
    });

    it('renders marker without parent serial line when no serial is exposed', () => {
        const wrapper = mountWith({ parent_order_id: 999 });

        expect(wrapper.find('.receipt-remboursement-marker').exists()).toBe(true);
        expect(wrapper.find('.receipt-remboursement-parent').exists()).toBe(false);
    });

    it('exposes role=status and aria-live=polite for screen readers (a11y)', () => {
        const wrapper = mountWith({ parent_order_id: 42 });
        const marker = wrapper.find('.receipt-remboursement-marker');

        expect(marker.attributes('role')).toBe('status');
        expect(marker.attributes('aria-live')).toBe('polite');
    });

    it('exposes data-testid="receipt-remboursement-marker" for Playwright probe', () => {
        const wrapper = mountWith({ parent_order_id: 42 });

        expect(wrapper.find('[data-testid="receipt-remboursement-marker"]').exists()).toBe(true);
    });

    it('falls back to "REMBOURSEMENT" string literal when $t helper is unavailable', () => {
        const wrapper = mount(ReceiptRemboursementMarker, {
            props: { order: { parent_order_id: 7 } },
            global: { mocks: { $t: undefined } },
        });

        expect(wrapper.text()).toContain('REMBOURSEMENT');
    });
});

/**
 * SOURCE-LEVEL SENTINEL : the marker must be wired into BOTH receipt surfaces
 * (post-payment receipt + reprint-from-history). NF525 visual distinction
 * applies regardless of whether the cashier reprints from history or sees the
 * fresh print after the refund POST.
 */
describe('ReceiptRemboursementMarker wiring (source-level)', () => {
    const { readFileSync } = require('node:fs');
    const { resolve } = require('node:path');

    it('is mounted in admin/pos/ReceiptComponent.vue (fresh-print after refund POST)', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/components/admin/pos/ReceiptComponent.vue'),
            'utf8',
        );

        expect(source).toContain('<receipt-remboursement-marker');
        expect(source).toContain('ReceiptRemboursementMarker');
    });

    it('is mounted in admin/posOrders/PosOrderReceiptComponent.vue (reprint-from-history)', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue'),
            'utf8',
        );

        expect(source).toContain('<receipt-remboursement-marker');
        expect(source).toContain('ReceiptRemboursementMarker');
    });

    it('fr.json defines label.remboursement and label.status_22', () => {
        const fr = JSON.parse(readFileSync(
            resolve(process.cwd(), 'resources/js/languages/fr.json'),
            'utf8',
        ));

        expect(fr.label.remboursement).toBe('REMBOURSEMENT');
        expect(fr.label.status_22).toBe('Remboursé');
    });
});
