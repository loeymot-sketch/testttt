import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsOrderCard from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue';

/**
 * [F-03 AUDIT CUISINIER 2026-08-01 · P1] Le badge « produit 86 » sur les tickets EN COURS
 * existait sur l'écran KDS legacy et avait DISPARU du board V2 : l'événement
 * `ItemAvailabilityChanged` partait bien (domain_events sent=1) et le store `kdsInflight`
 * marquait bien l'item, mais plus aucun composant ne l'affichait. Le cuisinier composait donc
 * un produit en rupture sans le moindre avertissement.
 *
 * Cette sentinelle empêche la régression de se reproduire silencieusement au prochain
 * remaniement de carte : ce n'est pas le store qui est testé, c'est le RENDU.
 */
const order = {
    id: 1,
    status: 2,
    order_serial_no: 'A0042',
    source_surface: 'pos',
    order_items: [{ id: 10, item_id: 77, item_name: 'Chicken Burger', quantity: 1 }],
};

function mountCard(isOos) {
    return mount(KdsOrderCard, {
        props: { order },
        global: {
            mocks: {
                $t: (key, params) => (params && params.name ? `${key}:${params.name}` : key),
                $store: {
                    getters: {
                        'kdsInflight/isItemRecentlyDeavailable': () => isOos,
                    },
                },
            },
            stubs: { KdsOrderLine: true, KdsRecallBadge: true },
        },
    });
}

describe('KDS V2 — badge rupture 86 sur ticket en cours (F-03)', () => {
    it('affiche une alerte quand un produit du ticket vient de passer 86', () => {
        const w = mountCard(true);
        const badge = w.find('.kds-card__oos-badge');
        expect(badge.exists()).toBe(true);
        expect(badge.attributes('role')).toBe('alert');
        expect(badge.text()).toContain('Chicken Burger');
    });

    it("n'affiche RIEN quand le produit est disponible", () => {
        expect(mountCard(false).find('.kds-card__oos-badge').exists()).toBe(false);
    });

    it('ne casse pas la carte si le module kdsInflight est absent', () => {
        const w = mount(KdsOrderCard, {
            props: { order },
            global: {
                mocks: { $t: (k) => k, $store: { getters: {} } },
                stubs: { KdsOrderLine: true, KdsRecallBadge: true },
            },
        });
        expect(w.find('.kds-card__oos-badge').exists()).toBe(false);
        // La carte reste rendue et exploitable (pas d'exception, corps présent).
        expect(w.find('.kds-card').exists()).toBe(true);
        expect(w.findAll('.kds-card__item-block').length).toBe(1);
    });
});
