import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('@vuepic/vue-datepicker', () => ({ default: { name: 'Datepicker', template: '<input />' } }));
vi.mock('@vuepic/vue-datepicker/dist/main.css', () => ({}));

import CouponCreateComponent from '../../resources/js/components/admin/coupons/CouponCreateComponent.vue';

/**
 * [AUDIT ABUSIF 2026-11-06 · tour 3 · ECR-R3-002] Formulaire de code promo vide → 422 : les erreurs s'affichaient en haut du
 * tiroir, hors de l'écran, et rien n'apparaissait près d'« Enregistrer » (échec muet, cf. mémoire formulaire-admin-echec-muet).
 */
function monter(rejet) {
    const form = { name: '', description: '', code: '', discount: '', discount_type: 5, start_date: '', end_date: '', minimum_order: '',
        maximum_discount: '', limit_per_user: '', status: 5, max_uses_global: '', valid_hours_start: '', valid_hours_end: '',
        valid_days_of_week: [], surfaces: [], branch_scope: [] };
    return mount(CouponCreateComponent, {
        props: { props: { form, search: {} } },
        global: {
            mocks: {
                $t: (k) => k,
                $store: { getters: { 'coupon/temp': { temp_id: null } }, dispatch: vi.fn(() => Promise.reject(rejet)) },
            },
            stubs: { SmSidebarModalCreateComponent: true, LoadingComponent: true, Datepicker: true, 'vue-select': true },
        },
    });
}

describe('admin — code promo : l\'échec d\'enregistrement se voit près du bouton', () => {
    it('erreurs de validation : message près d\'« Enregistrer »', async () => {
        const w = monter({ response: { status: 422, data: { message: 'The name field is required.', errors: { name: ['Le nom est obligatoire.'] } } } });
        await w.find('form').trigger('submit');
        await flushPromises();
        const msg = w.find('[data-testid="coupon-save-error"]');
        expect(msg.exists()).toBe(true);
        expect(msg.attributes('role')).toBe('alert');
        expect(msg.text()).toContain('pas enregistré');
        expect(w.text()).toContain('Le nom est obligatoire.');
        w.unmount();
    });

    it('réseau coupé (pas de réponse) : message lisible, pas de plantage', async () => {
        const w = monter(new Error('Network Error'));
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(w.find('[data-testid="coupon-save-error"]').text()).toContain('Vérifiez la connexion');
        w.unmount();
    });
});
