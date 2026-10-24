/**
 * [VG-2026-10-21 M7-101] Recongeler le MÊME steak : la fiche d'impression retirait de la liste d'origine tous les lots du produit
 * lui-même ; pour une congélation (origine obligatoire), seule restait « Aucun : produit neuf », que le serveur accepte — la garde
 * « jamais recongeler un décongelé » (LabelController::dlcPrint, refreeze_forbidden) ne pouvait donc pas se déclencher depuis
 * l'écran pour le cas le plus courant. Une congélation propose désormais les lots du produit lui-même, en tête.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('../../resources/js/helpers/labelPrintJob', () => ({
    printBlocks: vi.fn(),
    previewUrls: (list) => ({ urls: list.map(() => 'data:image/png;base64,AA=='), overflow: false }),
}));

import DlcPrintSheet from '../../resources/js/components/admin/labels/DlcPrintSheet.vue';

const settings = { mediaId: 'c62', autoCut: true, mirror: true, turn180: false };
const STEAK = {
    id: 4910, name: 'Steak haché', category: 'Viandes', storage: 'Congélateur −18 °C', thawed_storage: '0 à +2 °C',
    actions: [{ key: 'thawed', value: 24, unit: 'hours' }, { key: 'frozen', value: 90, unit: 'days' }, { key: 'opened', value: 1, unit: 'days' }],
    allergens: [], no_allergens: true, needs_parent: false, ask_original_dlc: false, print_operator: true, default_copies: 1,
};
const later = (h) => new Date(Date.now() + h * 3600000).toISOString();
const LOTS = [
    { id: 5579, dlc_product_id: 4910, status: 'printed', product_name: 'Steak haché', action: 'thawed', lot: 'L261022-5579', expires_at: later(20) },
    { id: 6001, dlc_product_id: 12, status: 'printed', product_name: 'Sauce maison', action: 'prepared', lot: 'L261022-6001', expires_at: later(10) },
];
const parentIds = (w) => w.findAll('[data-testid^="dlc-sheet-parent-"]')
    .map((b) => b.attributes('data-testid'))
    .filter((t) => /^dlc-sheet-parent-\d+$/.test(t));

describe('congélation : les lots du produit lui-même sont proposés (VG-2026-10-21 M7-101)', () => {
    it('recongeler le même steak : son lot décongelé est proposé (en tête), pas seulement « produit neuf »', async () => {
        const w = mount(DlcPrintSheet, { props: { product: STEAK, settings, lots: LOTS, initialAction: 'frozen' } });
        await w.vm.$nextTick(); // la liste d'origine s'ouvre au montage (origine obligatoire)
        expect(w.get('[data-testid="dlc-sheet-parent-required"]').exists()).toBe(true);
        expect(parentIds(w)[0]).toBe('dlc-sheet-parent-5579');
        await w.get('[data-testid="dlc-sheet-parent-5579"]').trigger('click');
        await w.get('[data-testid="dlc-sheet-print"]').trigger('click');
        // l'origine part au serveur : c'est LUI qui refuse la recongélation (refreeze_forbidden) et l'écran le dit
        expect(w.emitted('print')[0][0]).toMatchObject({ action: 'frozen', parent_log_id: 5579 });
    });

    it('hors congélation, un produit n’est pas proposé comme sa propre origine (comportement inchangé)', () => {
        const w = mount(DlcPrintSheet, { props: { product: { ...STEAK, needs_parent: true }, settings, lots: LOTS, initialAction: 'opened' } });
        expect(parentIds(w)).toEqual(['dlc-sheet-parent-6001']);
    });
});
