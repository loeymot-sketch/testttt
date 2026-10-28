import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import RealtimeReportComponent from '../../../resources/js/components/admin/dashboard/RealtimeReportComponent.vue';

/**
 * [G5 · T5.1 + T5.2 2026-09-03] « Suivi en direct » — le Ticket Moyen.
 *
 * CE COMPOSANT ÉTAIT DÉJÀ À MI-CHEMIN, et c'est ce qui rend son défaut instructif.
 * Un correctif antérieur avait ajouté un drapeau `failed` pour éviter qu'une 403 ne
 * laisse « 0,00 € » à l'écran. Bien vu. Mais le rendu retenu était :
 *
 *     if (this.failed) return '—';
 *     ...
 *     return this.report.average_ticket ?? '—';
 *
 * Les deux branches rendent LE MÊME CARACTÈRE. Le drapeau existe, il est correctement
 * levé, et il ne change rien à ce que l'exploitant voit. « — » quand le serveur n'a rien
 * renvoyé pour le ticket moyen, « — » quand le serveur n'a pas répondu du tout. C'est la
 * forme la plus coûteuse du défaut : un correctif qui a l'air appliqué.
 *
 * Ce banc mesure l'ÉCRAN, pas le drapeau. Et il ajoute la mesure qui manquait au
 * correctif de contraste du 2026-09-02 (`RealtimeReportComponent.vue:14-16`) : les
 * classes `text-white` sur le libellé ET sur la valeur, sur fond `bg-[#1A1A1A]`. Ce
 * correctif n'avait jamais eu la moindre assertion.
 */
function monter(dispatch) {
    return mount(RealtimeReportComponent, {
        global: { mocks: { $store: { dispatch }, $t: (k) => k } },
    });
}

const succes = (data) => vi.fn().mockResolvedValue({ data: { data } });
const echec = (payload) => vi.fn().mockRejectedValue(payload);

describe('RealtimeReportComponent — suivi en direct', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('succès : affiche le ticket moyen renvoyé par le serveur', async () => {
        const w = monter(succes({ average_ticket: '14,80 €', daily_sales: '1 480,00 €', daily_orders: 100 }));
        await flushPromises();

        expect(w.find('[data-testid="realtime-ticket"]').text()).toBe('14,80 €');
        expect(w.find('[data-testid="realtime-report-error"]').exists()).toBe(false);
    });

    it('journée sans vente : le serveur répond, le ticket moyen est vide — et ce n\'est pas une panne', async () => {
        const w = monter(succes({}));
        await flushPromises();

        expect(w.find('[data-testid="realtime-report-error"]').exists()).toBe(false);
        expect(w.find('[data-testid="realtime-ticket"]').text()).toBe('—');
    });

    /**
     * LE CAS QUI COMPTE. Avant correctif, cette assertion échouait : `displayTicket`
     * rendait « — » dans les deux cas, et rien d'autre à l'écran ne les séparait.
     */
    it("403 : l'écran distingue « pas de donnée » de « je n'ai pas pu lire »", async () => {
        const enPanne = monter(echec({ response: { status: 403 } }));
        await flushPromises();

        const vide = monter(succes({}));
        await flushPromises();

        expect(
            enPanne.find('[data-testid="realtime-report-error"]').exists(),
            'le drapeau `failed` était levé sans que rien ne change à l\'écran',
        ).toBe(true);
        expect(vide.find('[data-testid="realtime-report-error"]').exists()).toBe(false);

        expect(
            enPanne.text(),
            'panne et journée sans vente rendaient un texte STRICTEMENT identique',
        ).not.toBe(vide.text());
    });

    it('500 : état d\'erreur explicite', async () => {
        const w = monter(echec({ response: { status: 500 } }));
        await flushPromises();

        expect(w.find('[data-testid="realtime-report-error"]').exists()).toBe(true);
    });

    it('délai dépassé (ECONNABORTED) : état d\'erreur explicite', async () => {
        const w = monter(echec({ code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded' }));
        await flushPromises();

        expect(w.find('[data-testid="realtime-report-error"]').exists()).toBe(true);
    });

    it('un rafraîchissement réussi efface l\'erreur : le suivi en direct doit pouvoir se rétablir', async () => {
        const dispatch = vi.fn()
            .mockRejectedValueOnce({ response: { status: 500 } })
            .mockResolvedValueOnce({ data: { data: { average_ticket: '9,90 €' } } });

        const w = monter(dispatch);
        await flushPromises();
        expect(w.find('[data-testid="realtime-report-error"]').exists()).toBe(true);

        w.vm.fetchData();
        await flushPromises();
        expect(w.find('[data-testid="realtime-report-error"]').exists()).toBe(false);
        expect(w.find('[data-testid="realtime-ticket"]').text()).toBe('9,90 €');
    });

    // ---- Minuteur : c'est le SEUL des six à en poser un ----

    it('pose un minuteur de rafraîchissement au montage et le RETIRE au démontage', async () => {
        vi.useFakeTimers();
        const dispatch = vi.fn().mockResolvedValue({ data: { data: { average_ticket: '1,00 €' } } });
        const w = mount(RealtimeReportComponent, {
            global: { mocks: { $store: { dispatch }, $t: (k) => k } },
        });

        expect(dispatch).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(30000);
        expect(dispatch, 'le suivi « en direct » doit se rafraîchir tout seul').toHaveBeenCalledTimes(2);

        w.unmount();
        vi.advanceTimersByTime(120000);
        expect(
            dispatch,
            'un minuteur non nettoyé continue d\'interroger le serveur après la fermeture de l\'écran',
        ).toHaveBeenCalledTimes(2);
    });

    it('un échec ne tue pas le minuteur : le suivi doit repartir seul quand le serveur revient', async () => {
        vi.useFakeTimers();
        const dispatch = vi.fn()
            .mockRejectedValueOnce({ response: { status: 500 } })
            .mockResolvedValue({ data: { data: { average_ticket: '7,20 €' } } });

        const w = mount(RealtimeReportComponent, {
            global: { mocks: { $store: { dispatch }, $t: (k) => k } },
        });
        await vi.advanceTimersByTimeAsync(30000);

        expect(dispatch).toHaveBeenCalledTimes(2);
        w.unmount();
    });

    // ---- T5.2 : contraste du Ticket Moyen, enfin mesuré ----

    /**
     * Le correctif du 2026-09-02 pose `text-white` sur le libellé ET sur la valeur, parce
     * que `public/css/app.css` porte `h1..h6 { color: rgb(31 31 57) }` — une règle directe
     * qui bat la couleur héritée du parent. Sur fond `#1A1A1A`, le contraste mesuré était
     * de 1,088:1 là où WCAG 2.1 AA exige 4,5:1. Le chiffre le plus regardé de l'écran était
     * illisible. Aucune assertion ne gardait ce correctif : le voici.
     */
    it('le Ticket Moyen porte `text-white` sur le libellé ET sur la valeur (WCAG 2.1 AA)', async () => {
        const w = monter(succes({ average_ticket: '14,80 €' }));
        await flushPromises();

        const carte = w.find('[data-testid="realtime-ticket-card"]');
        expect(carte.exists()).toBe(true);
        expect(carte.classes()).toContain('bg-[#1A1A1A]');

        const libelle = w.find('[data-testid="realtime-ticket-label"]');
        const valeur = w.find('[data-testid="realtime-ticket"]');

        expect(
            libelle.classes(),
            'sans `text-white` SUR le titre, la règle h1..h6 de app.css le repeint en rgb(31,31,57) — contraste 1,088:1',
        ).toContain('text-white');
        expect(valeur.classes()).toContain('text-white');
    });
});
