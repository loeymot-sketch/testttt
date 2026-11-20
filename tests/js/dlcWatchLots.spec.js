/**
 * [VG-2026-10-21 M7-003] Deux étiquettes DISTINCTES du même produit, échues dans la même minute, étaient fusionnées en un lot
 * (clé = produit + minute) : l'écran disait « non confirmée » d'une étiquette confirmée et masquait le second N°, un décongelé
 * passait pour une « ouverture », et le widget du tableau de bord additionnait les copies du lot ENTIER (2 « non confirmées »
 * quand l'accueil Hygiène en dit 1). Un lot ne réunit plus que des lignes du même événement et du même état ; le widget compte
 * comme l'accueil (Overview::dlc : copies des seules lignes concernées, encore en cours).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));

import { watchList } from '../../resources/js/helpers/dlcLabelBuilder';
import DlcWatchWidget from '../../resources/js/components/admin/dashboard/DlcWatchWidget.vue';

const NOW = Date.parse('2026-10-22T10:00:00Z');
const IN_2H = new Date(NOW + 2 * 3600000).toISOString();
const row = (over) => ({ id: 1, dlc_product_id: 7, status: 'printed', product_name: 'Salade 24h', copies: 1, lot: 'L261022-5569', action: 'prepared', expires_at: IN_2H, ...over });

const mountWidget = () => mount(DlcWatchWidget, {
    global: { mocks: { $t: (k) => k, $store: { getters: { authPermission: [{ url: 'pos-orders', access: true }] } } }, stubs: { 'router-link': { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
});
const count = (w, what) => {
    const el = w.find(`[data-testid="dlc-watch-widget-count-${what}"]`);
    return el.exists() ? Number(el.text().split(':').pop().trim()) : 0;
};

beforeEach(() => { h.axios.get.mockReset(); });

describe('lots à surveiller (VG-2026-10-21 M7-003)', () => {
    it('une étiquette confirmée et une non confirmée, même produit, même minute : deux lots, chacun son N° et son état', () => {
        const { items } = watchList([
            row({ id: 5569, lot: 'L261022-5569', status: 'printed' }),
            row({ id: 5571, lot: 'L261022-5571', status: 'unconfirmed' }),
        ], NOW);
        expect(items).toHaveLength(2);
        const ok = items.find((w) => w.ids.includes(5569));
        const unsure = items.find((w) => w.ids.includes(5571));
        expect(ok.unconfirmed).toBe(false);
        expect(ok.lot).toBe('L261022-5569');
        expect(unsure.unconfirmed).toBe(true);
        expect(unsure.lot).toBe('L261022-5571');
    });

    it('deux événements différents (ouverture, décongélation) ne se fondent jamais sous l’action du premier', () => {
        const { items } = watchList([
            row({ id: 1, action: 'opened', lot: 'L1' }),
            row({ id: 2, action: 'thawed', lot: 'L2' }),
        ], NOW);
        expect(items.map((w) => w.action).sort()).toEqual(['opened', 'thawed']);
    });

    it('« à vérifier » : seule la ligne signalée porte l’alerte, ses copies seules sont comptées', () => {
        const { items } = watchList([
            row({ id: 1, copies: 4, lot: 'L1' }),
            row({ id: 2, copies: 1, lot: 'L2', anomaly: true, message: '⚠ Fiche modifiée.' }),
        ], NOW);
        expect(items).toHaveLength(2);
        expect(items.filter((w) => w.anomaly).map((w) => w.copies)).toEqual([1]);
    });

    it('le même lot (même événement, même état) se regroupe encore, et AUCUN N° n’est masqué', () => {
        const { items } = watchList([
            row({ id: 1, copies: 2, lot: 'L261022-0001' }),
            row({ id: 2, copies: 1, lot: 'L261022-0002' }),
        ], NOW);
        expect(items).toHaveLength(1);
        expect(items[0].copies).toBe(3);
        expect(items[0].ids).toEqual([1, 2]);
        expect(items[0].lot).toBe('L261022-0001, L261022-0002');
    });

    it('widget = accueil Hygiène : 3 copies confirmées + 1 non confirmée → « non confirmées : 1 » ; 4 saines + 1 à vérifier → « à vérifier : 1 »', async () => {
        // [VG-2026-10-21 M7-003] Le widget lit la VRAIE horloge (server_now) : une échéance fixe (IN_2H = 14:00 le 22/10)
        // rendait ce test faux dès 14:00 (étiquette « dépassée », comme le produit doit le dire). Échéance relative à maintenant.
        const soon = new Date(Date.now() + 2 * 3600000).toISOString();
        h.axios.get.mockResolvedValue({ data: { data: [
            row({ id: 1, copies: 3, lot: 'L1', status: 'printed', expires_at: soon }),
            row({ id: 2, copies: 1, lot: 'L2', status: 'unconfirmed', expires_at: soon }),
            row({ id: 3, product_name: 'Sauce', copies: 4, lot: 'L3', expires_at: soon }),
            row({ id: 4, product_name: 'Sauce', copies: 1, lot: 'L4', anomaly: true, expires_at: soon }),
        ], meta: { server_now: new Date().toISOString() } } });
        const w = mountWidget();
        await flushPromises();
        expect(count(w, 'unconfirmed')).toBe(1);
        expect(count(w, 'anomaly')).toBe(1);
    });

    it('une non confirmée déjà ÉCHUE est comptée « dépassée », pas « non confirmée » (l’accueil ne compte que celles en cours)', async () => {
        const past = new Date(Date.now() - 3600000).toISOString();
        h.axios.get.mockResolvedValue({ data: { data: [row({ id: 9, status: 'unconfirmed', copies: 2, expires_at: past })], meta: { server_now: new Date().toISOString() } } });
        const w = mountWidget();
        await flushPromises();
        expect(count(w, 'expired')).toBe(2);
        expect(count(w, 'unconfirmed')).toBe(0);
    });
});
