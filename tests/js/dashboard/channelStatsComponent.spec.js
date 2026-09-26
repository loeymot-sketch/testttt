import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ChannelStatsComponent from '../../../resources/js/components/admin/dashboard/ChannelStatsComponent.vue';

/**
 * [G5 · T5.1 + T5.2 2026-09-03] « Répartition par Canal (Aujourd'hui) ».
 *
 * DÉFAUT MESURÉ AVANT CORRECTIF : `fetchData()` n'avait **aucun `.catch`**. Une 403,
 * une 500 ou une coupure réseau laissait `stats` à `[]` — c'est-à-dire exactement
 * l'écran d'une journée sans aucune commande — et poussait en prime un rejet de
 * promesse non traité dans la console. L'exploitant qui ouvre son tableau de bord un
 * jour de panne lit « aucune commande sur aucun canal » et n'a rien pour le
 * contredire. C'est le pire des deux : ni le chiffre juste, ni l'aveu de l'absence
 * de chiffre.
 *
 * Le banc exige donc que les deux situations soient DISCERNABLES à l'écran, pas
 * seulement dans l'état interne.
 *
 * T5.2 s'ajoute ici : les barres de progression n'étaient que des `<div>` de largeur
 * variable — un lecteur d'écran ne lisait qu'un pourcentage nu sans savoir de quelle
 * échelle il parle — et la clé de boucle était l'`index`, qui se réattribue dès que
 * le serveur réordonne les canaux.
 */
const CANAUX = [
    { name: 'Web', value: 45 },
    { name: 'Kiosk/App', value: 35 },
    { name: 'POS', value: 20 },
];

function monter(dispatch) {
    return mount(ChannelStatsComponent, {
        global: { mocks: { $store: { dispatch }, $t: (k) => k } },
    });
}

const succes = (data) => vi.fn().mockResolvedValue({ data: { data } });
const echec = (payload) => vi.fn().mockRejectedValue(payload);

describe('ChannelStatsComponent — répartition par canal', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('succès : rend une barre par canal, avec son nom et son pourcentage', async () => {
        const w = monter(succes(CANAUX));
        await flushPromises();

        const barres = w.findAll('[data-testid^="channel-stats-bar-"]');
        expect(barres).toHaveLength(3);
        expect(w.text()).toContain('Web');
        expect(w.text()).toContain('45%');
        expect(w.find('[data-testid="channel-stats-error"]').exists()).toBe(false);
        expect(w.find('[data-testid="channel-stats-empty"]').exists()).toBe(false);
    });

    it('liste vide (vraie journée sans commande) : le dit, et ne crie pas à la panne', async () => {
        const w = monter(succes([]));
        await flushPromises();

        expect(w.find('[data-testid="channel-stats-empty"]').exists()).toBe(true);
        expect(w.find('[data-testid="channel-stats-error"]').exists()).toBe(false);
        expect(w.findAll('[data-testid^="channel-stats-bar-"]')).toHaveLength(0);
    });

    /**
     * LE CAS QUI COMPTE. Sans lui, les trois suivants seraient de la couverture polie.
     */
    it("403 : l'erreur ne se déguise PAS en journée sans vente", async () => {
        const w = monter(echec({ response: { status: 403 } }));
        await flushPromises();

        expect(
            w.find('[data-testid="channel-stats-error"]').exists(),
            'une panne doit être ANNONCÉE, jamais rendue comme une journée à zéro commande',
        ).toBe(true);
        expect(
            w.find('[data-testid="channel-stats-empty"]').exists(),
            "l'état vide et l'état d'erreur ne doivent jamais coexister ni se confondre",
        ).toBe(false);
    });

    it('500 : même exigence — état d\'erreur explicite', async () => {
        const w = monter(echec({ response: { status: 500 } }));
        await flushPromises();

        expect(w.find('[data-testid="channel-stats-error"]').exists()).toBe(true);
        expect(w.find('[data-testid="channel-stats-empty"]').exists()).toBe(false);
    });

    it('délai dépassé (ECONNABORTED) : état d\'erreur, pas un écran vide', async () => {
        const w = monter(echec({ code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded' }));
        await flushPromises();

        expect(w.find('[data-testid="channel-stats-error"]').exists()).toBe(true);
        expect(w.find('[data-testid="channel-stats-empty"]').exists()).toBe(false);
    });

    it('un échec ne laisse aucun rejet de promesse non traité', async () => {
        const naufrage = vi.fn();
        process.on('unhandledRejection', naufrage);

        const w = monter(echec({ response: { status: 500 } }));
        await flushPromises();
        await new Promise((r) => setTimeout(r, 0));
        process.off('unhandledRejection', naufrage);

        expect(naufrage, 'le composant doit traiter son échec, pas le laisser filer').not.toHaveBeenCalled();
        w.unmount();
    });

    // ---- T5.2 : accessibilité ----

    it('chaque barre est une vraie barre de progression pour un lecteur d\'écran', async () => {
        const w = monter(succes(CANAUX));
        await flushPromises();

        const barre = w.find('[data-testid="channel-stats-bar-Web"]');
        expect(barre.attributes('role')).toBe('progressbar');
        expect(barre.attributes('aria-valuenow')).toBe('45');
        expect(barre.attributes('aria-valuemin')).toBe('0');
        expect(barre.attributes('aria-valuemax')).toBe('100');
        expect(barre.attributes('aria-label')).toContain('Web');
    });

    /**
     * Preuve COMPORTEMENTALE, pas textuelle : on ne relit pas le fichier pour y chercher
     * `:key="index"`, on permute les canaux et on regarde si le nœud DOM de « Web » a
     * survécu. Avec une clé positionnelle, Vue garde les nœuds en place et réécrit leur
     * contenu : le nœud de « Web » deviendrait celui de « POS ». Avec une clé stable, le
     * nœud voyage avec sa donnée.
     */
    it('la clé de boucle est le canal, pas sa position : un réordonnancement déplace les nœuds', async () => {
        const w = monter(succes(CANAUX));
        await flushPromises();

        const avant = w.find('[data-testid="channel-stats-bar-Web"]').element;

        await w.setData({ stats: [...CANAUX].reverse() });

        const apres = w.find('[data-testid="channel-stats-bar-Web"]').element;
        expect(
            apres,
            'clé positionnelle détectée : le nœud de « Web » a été recyclé pour un autre canal',
        ).toBe(avant);
    });

    it('ne pose aucun minuteur : il n\'y a rien à fuir au démontage', async () => {
        const pose = vi.spyOn(global, 'setInterval');
        const w = monter(succes(CANAUX));
        await flushPromises();

        expect(pose).not.toHaveBeenCalled();
        w.unmount();
        expect(w.exists()).toBe(false);
    });
});
