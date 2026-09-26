import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import SalesSummaryComponent from '../../../resources/js/components/admin/dashboard/SalesSummaryComponent.vue';

/**
 * [G5 · T5.1 + T5.2 2026-09-03] Carte « Résumé des ventes » — total, moyenne par jour,
 * et la courbe.
 *
 * DÉFAUT MESURÉ AVANT CORRECTIF : `total_sales` et `avg_per_day` partent à `null` (rendus
 * comme du vide par Vue) et `options` reste `null`, ce qui masque entièrement la courbe
 * (`v-if="options"`). Le `.catch` ne conservait rien de l'échec.
 *
 * Sur 403 / 500 / réseau coupé : deux libellés (« Ventes totales », « Moyenne par jour »)
 * suivis de blanc, et aucune courbe. C'est la carte du CHIFFRE D'AFFAIRES. Un exploitant
 * qui la voit vide conclut à une journée creuse, pas à un tableau de bord aveugle — et
 * c'est une conclusion sur laquelle on prend des décisions commerciales.
 *
 * Ce banc exige l'aveu explicite de l'échec, et vérifie que la vraie période sans vente
 * affiche bien un montant à zéro plutôt que du blanc.
 */
const VENTES = {
    total_sales: '4 820,00 €',
    avg_per_day: '160,66 €',
    per_day_sales: [120, 340, 210],
    per_day_labels: ['2026-09-01', '2026-09-02', '2026-09-03'],
};

const SANS_VENTE = {
    total_sales: '0,00 €',
    avg_per_day: '0,00 €',
    per_day_sales: [],
    per_day_labels: [],
};

function monter(dispatch) {
    return mount(SalesSummaryComponent, {
        global: {
            mocks: { $store: { dispatch }, $t: (k) => k },
            stubs: { LoadingComponent: true, Datepicker: true, apexchart: true },
        },
    });
}

const succes = (data) => vi.fn().mockResolvedValue({ data: { data } });
const echec = (payload) => vi.fn().mockRejectedValue(payload);

describe('SalesSummaryComponent — résumé des ventes', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('succès : total, moyenne et courbe alimentée avec ses dates en abscisse', async () => {
        const w = monter(succes(VENTES));
        await flushPromises();

        expect(w.find('[data-testid="sales-summary-total"]').text()).toBe('4 820,00 €');
        expect(w.find('[data-testid="sales-summary-avg"]').text()).toBe('160,66 €');
        expect(w.vm.options.xaxis.categories).toEqual(VENTES.per_day_labels);
        expect(w.find('[data-testid="sales-summary-error"]').exists()).toBe(false);
    });

    it('période réelle sans vente : affiche « 0,00 € », pas du blanc', async () => {
        const w = monter(succes(SANS_VENTE));
        await flushPromises();

        expect(w.find('[data-testid="sales-summary-total"]').text()).toBe('0,00 €');
        expect(w.find('[data-testid="sales-summary-error"]').exists()).toBe(false);
    });

    /** LE CAS QUI COMPTE : erreur ≠ période creuse. */
    it("403 : l'échec est nommé — le chiffre d'affaires non lu n'est pas un chiffre d'affaires nul", async () => {
        const w = monter(echec({ response: { status: 403 } }));
        await flushPromises();

        expect(
            w.find('[data-testid="sales-summary-error"]').exists(),
            'une carte de chiffre d\'affaires muette se lit comme une mauvaise journée',
        ).toBe(true);
        expect(w.find('[data-testid="sales-summary-total"]').text()).not.toBe('0,00 €');
    });

    it('500 : état d\'erreur explicite', async () => {
        const w = monter(echec({ response: { status: 500 } }));
        await flushPromises();

        expect(w.find('[data-testid="sales-summary-error"]').exists()).toBe(true);
    });

    it('délai dépassé (ECONNABORTED) : état d\'erreur, et le voile de chargement retombe', async () => {
        const w = monter(echec({ code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded' }));
        await flushPromises();

        expect(w.find('[data-testid="sales-summary-error"]').exists()).toBe(true);
        expect(w.vm.loading.isActive).toBe(false);
    });

    /**
     * Le changement de période emprunte le MÊME `salesSummary()`. Une panne survenue en
     * choisissant « Mois dernier » laissait la courbe PRÉCÉDENTE affichée sans un mot :
     * l'exploitant croyait lire le mois dernier, il lisait encore le mois courant.
     */
    it('un échec après changement de période est signalé, et ne laisse pas croire à la période demandée', async () => {
        const dispatch = vi.fn()
            .mockResolvedValueOnce({ data: { data: VENTES } })
            .mockRejectedValueOnce({ response: { status: 500 } });

        const w = monter(dispatch);
        await flushPromises();
        expect(w.find('[data-testid="sales-summary-error"]').exists()).toBe(false);

        w.vm.salesSummary([new Date('2026-08-01'), new Date('2026-08-31')]);
        await flushPromises();

        expect(w.find('[data-testid="sales-summary-error"]').exists()).toBe(true);
    });

    it('un rechargement réussi efface l\'état d\'erreur précédent', async () => {
        const dispatch = vi.fn()
            .mockRejectedValueOnce({ response: { status: 500 } })
            .mockResolvedValueOnce({ data: { data: VENTES } });

        const w = monter(dispatch);
        await flushPromises();
        expect(w.find('[data-testid="sales-summary-error"]').exists()).toBe(true);

        w.vm.salesSummary();
        await flushPromises();
        expect(w.find('[data-testid="sales-summary-error"]').exists()).toBe(false);
    });

    // ---- T5.2 : accessibilité ----

    it('les pictogrammes de la carte sont décoratifs et masqués aux lecteurs d\'écran', async () => {
        const w = monter(succes(VENTES));
        await flushPromises();

        const icones = w.findAll('i.lab');
        expect(icones.length).toBeGreaterThan(0);
        const exposees = icones.filter((i) => i.attributes('aria-hidden') !== 'true');
        expect(exposees.map((i) => i.attributes('class'))).toEqual([]);
    });

    it('ne pose aucun minuteur : rien à nettoyer au démontage', async () => {
        const pose = vi.spyOn(global, 'setInterval');
        const w = monter(succes(VENTES));
        await flushPromises();

        expect(pose).not.toHaveBeenCalled();
        w.unmount();
        expect(w.exists()).toBe(false);
    });
});
