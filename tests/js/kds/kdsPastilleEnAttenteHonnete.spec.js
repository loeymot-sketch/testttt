import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsV2Grid, { KDS_RENDER_MAX, KDS_CARTES_PAR_ECRAN_DEFAUT } from '../../../resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue';
import { ORDER_STATUS } from '../../../resources/js/helpers/kdsState.js';

/**
 * [VG-2026-10-21 M6-005 = M1-001] Au-delà de KDS_RENDER_MAX (24) commandes actives, les plus
 * récentes ne sont pas montées. Mesuré : 26 actives, 4 colonnes → pastille « +22 en attente »,
 * mais le défilement n'en atteint que 20. Les 2 dernières n'avaient aucune carte ET aucun signal
 * distinct : la pastille laissait croire qu'on pouvait les trouver en défilant.
 *
 * Le PLAFOND n'est pas touché (décision propriétaire). Seul le compte devient honnête, depuis
 * UNE définition : « affichées » = visibleActiveOrders (celle que rend le v-for), « en attente »
 * = actives − colonnes (inchangée, cf. KdsV2GridOverflowChipSentinel invariant 4).
 */
function commandes(n) {
    const base = Date.UTC(2026, 9, 21, 20, 0, 0);
    return Array.from({ length: n }, (_, i) => ({
        id: 1000 + i,
        queue_number: `A${String(i + 1).padStart(4, '0')}`,
        status: ORDER_STATUS.ACCEPT,
        created_at_iso: new Date(base + i * 60_000).toISOString(),
    }));
}

describe('KDS — la pastille « +N en attente » dit combien de commandes n\'ont PAS de carte', () => {
    let wrapper;
    afterEach(() => { if (wrapper) { wrapper.unmount(); wrapper = null; } });

    function monter(n) {
        wrapper = mount(KdsV2Grid, {
            props: { orders: commandes(n) },
            global: {
                mocks: { $t: (k) => k },
                stubs: { KdsOrderCard: true, KdsStatusBanner: true },
            },
        });
        return wrapper;
    }

    it(`${KDS_RENDER_MAX + 2} actives, ${KDS_CARTES_PAR_ECRAN_DEFAUT} colonnes : la pastille annonce les 2 commandes non affichées`, () => {
        const w = monter(KDS_RENDER_MAX + 2);
        const cartes = w.findAllComponents({ name: 'KdsOrderCard' });
        expect(cartes.length).toBe(KDS_RENDER_MAX); // plafond inchangé
        const pastille = w.get('.kds-cols-picker__waiting');
        expect(pastille.text()).toContain(`+${KDS_RENDER_MAX + 2 - KDS_CARTES_PAR_ECRAN_DEFAUT} en attente`);
        expect(pastille.text(), 'les 2 commandes sans carte doivent être annoncées').toContain('dont 2 non affichées');
    });

    it('le compte « non affichées » dérive du rendu réel (actives − cartes montées)', () => {
        const w = monter(KDS_RENDER_MAX + 5);
        const cartes = w.findAllComponents({ name: 'KdsOrderCard' }).length;
        expect(w.vm.hiddenActiveCount).toBe(w.vm.activeOrders.length - cartes);
        expect(w.get('.kds-cols-picker__waiting').text()).toContain('dont 5 non affichées');
    });

    it('une seule commande cachée : singulier', () => {
        const w = monter(KDS_RENDER_MAX + 1);
        expect(w.get('.kds-cols-picker__waiting').text()).toContain('dont 1 non affichée');
        expect(w.get('.kds-cols-picker__waiting').text()).not.toContain('affichées');
    });

    it('sous le plafond : pastille inchangée, aucune mention « non affichée »', () => {
        const w = monter(10);
        const pastille = w.get('.kds-cols-picker__waiting');
        expect(pastille.text()).toBe(`+${10 - KDS_CARTES_PAR_ECRAN_DEFAUT} en attente`);
        expect(w.vm.hiddenActiveCount).toBe(0);
    });
});
