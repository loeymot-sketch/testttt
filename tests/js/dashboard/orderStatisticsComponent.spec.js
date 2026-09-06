import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import OrderStatisticsComponent from '../../../resources/js/components/admin/dashboard/OrderStatisticsComponent.vue';

/**
 * [G5 · T5.1 + T5.2 2026-09-03] Les dix compteurs de commandes du tableau de bord.
 *
 * DÉFAUT MESURÉ AVANT CORRECTIF : les dix compteurs partent à `null`. Vue rend `null`
 * comme une chaîne vide. Le `.catch` ne faisait que retomber le voile de chargement,
 * sans rien conserver de l'échec. Résultat, sur une 403 ou une 500 : dix tuiles avec
 * leur libellé (« Total », « En attente », « Livrées »…) et, dessous, **du blanc**.
 *
 * Ce n'est pas seulement indiscernable d'une journée sans commande — c'est PIRE. Une
 * vraie journée à zéro renvoie `0` et affiche « 0 ». Le blanc, lui, n'existe dans
 * aucune journée réelle : c'est un état que le produit ne sait pas nommer, et qu'il
 * laisse l'exploitant interpréter. La plupart liront « zéro ».
 *
 * Ce banc exige un état d'échec nommé, et vérifie au passage que la vraie journée à
 * zéro affiche bien « 0 » — sinon la distinction resterait théorique.
 */
const CHIFFRES = {
    total_order: 128,
    pending_order: 3,
    accept_order: 12,
    preparing_order: 4,
    prepared_order: 6,
    out_for_delivery_order: 1,
    delivered_order: 95,
    canceled_order: 5,
    returned_order: 1,
    rejected_order: 1,
};

const ZERO = Object.fromEntries(Object.keys(CHIFFRES).map((k) => [k, 0]));

function monter(dispatch) {
    return mount(OrderStatisticsComponent, {
        global: {
            mocks: { $store: { dispatch }, $t: (k) => k },
            stubs: { LoadingComponent: true, Datepicker: true },
        },
    });
}

const succes = (data) => vi.fn().mockResolvedValue({ data: { data } });
const echec = (payload) => vi.fn().mockRejectedValue(payload);

describe('OrderStatisticsComponent — les dix compteurs de commandes', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('succès : les dix compteurs portent leur valeur', async () => {
        const w = monter(succes(CHIFFRES));
        await flushPromises();

        expect(w.find('[data-testid="order-stat-total_order"]').text()).toBe('128');
        expect(w.find('[data-testid="order-stat-delivered_order"]').text()).toBe('95');
        expect(w.find('[data-testid="order-statistics-error"]').exists()).toBe(false);
    });

    /**
     * La vraie journée sans commande. Elle DOIT afficher « 0 » — c'est ce qui rend
     * l'état d'échec distinguable : le blanc n'appartient à aucune journée réelle.
     */
    it('journée réelle sans commande : affiche « 0 », pas du blanc', async () => {
        const w = monter(succes(ZERO));
        await flushPromises();

        expect(w.find('[data-testid="order-stat-total_order"]').text()).toBe('0');
        expect(w.find('[data-testid="order-stat-pending_order"]').text()).toBe('0');
        expect(w.find('[data-testid="order-statistics-error"]').exists()).toBe(false);
    });

    /** LE CAS QUI COMPTE : erreur ≠ journée à zéro. */
    it("403 : l'échec est nommé, les compteurs ne se lisent pas comme un zéro", async () => {
        const w = monter(echec({ response: { status: 403 } }));
        await flushPromises();

        expect(
            w.find('[data-testid="order-statistics-error"]').exists(),
            'dix tuiles blanches sans un mot d\'explication, c\'est un mensonge par omission',
        ).toBe(true);
        expect(
            w.find('[data-testid="order-stat-total_order"]').text(),
            'un compteur non lu ne doit surtout pas ressembler à « 0 »',
        ).not.toBe('0');
    });

    it('500 : état d\'erreur explicite', async () => {
        const w = monter(echec({ response: { status: 500 } }));
        await flushPromises();

        expect(w.find('[data-testid="order-statistics-error"]').exists()).toBe(true);
    });

    it('délai dépassé (ECONNABORTED) : état d\'erreur, et le voile de chargement retombe', async () => {
        const w = monter(echec({ code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded' }));
        await flushPromises();

        expect(w.find('[data-testid="order-statistics-error"]').exists()).toBe(true);
        expect(w.vm.loading.isActive).toBe(false);
    });

    /**
     * Le sélecteur de période emprunte le MÊME chemin d'échec. Une panne survenue après
     * un changement de dates était tout aussi muette que la panne initiale.
     */
    it('un échec après changement de période est signalé lui aussi', async () => {
        const dispatch = vi.fn()
            .mockResolvedValueOnce({ data: { data: CHIFFRES } })
            .mockRejectedValueOnce({ response: { status: 500 } });

        const w = monter(dispatch);
        await flushPromises();
        expect(w.find('[data-testid="order-statistics-error"]').exists()).toBe(false);

        w.vm.handleDate([new Date('2026-03-01'), new Date('2026-03-31')]);
        await flushPromises();

        expect(w.find('[data-testid="order-statistics-error"]').exists()).toBe(true);
    });

    it('un rechargement réussi efface l\'état d\'erreur précédent', async () => {
        const dispatch = vi.fn()
            .mockRejectedValueOnce({ response: { status: 500 } })
            .mockResolvedValueOnce({ data: { data: CHIFFRES } });

        const w = monter(dispatch);
        await flushPromises();
        expect(w.find('[data-testid="order-statistics-error"]').exists()).toBe(true);

        w.vm.orderStatistic();
        await flushPromises();
        expect(w.find('[data-testid="order-statistics-error"]').exists()).toBe(false);
    });

    // ---- T5.2 : accessibilité ----

    it('les pictogrammes des tuiles sont décoratifs et masqués aux lecteurs d\'écran', async () => {
        const w = monter(succes(CHIFFRES));
        await flushPromises();

        const icones = w.findAll('i.lab');
        expect(icones.length).toBeGreaterThan(0);
        const exposees = icones.filter((i) => i.attributes('aria-hidden') !== 'true');
        expect(
            exposees.map((i) => i.attributes('class')),
            'une icône purement décorative lue à voix haute pollue le parcours au lecteur d\'écran',
        ).toEqual([]);
    });

    it('ne pose aucun minuteur : rien à nettoyer au démontage', async () => {
        const pose = vi.spyOn(global, 'setInterval');
        const w = monter(succes(CHIFFRES));
        await flushPromises();

        expect(pose).not.toHaveBeenCalled();
        w.unmount();
        expect(w.exists()).toBe(false);
    });
});
