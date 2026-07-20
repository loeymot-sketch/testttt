import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KdsScheduledBanner, { formatScheduledTime, formatScheduledDayPrefix } from '../../../resources/js/components/admin/kitchenDisplaySystem/KdsScheduledBanner.vue';

/**
 * [E6 KDS-SCHEDULED 2026-07-20] Bandeau « commandes programmées à venir ».
 *
 * Contrat : GET admin/kds-order expose (lane backend parallèle, PEUT ne pas
 * encore exister) `meta.scheduled_upcoming` =
 *   [{ id, order_serial_no, scheduled_at, order_type, customer_name? }]
 * triée asc, max 20. Le front doit être 100% défensif : meta absente/vide ou
 * forme inattendue ⇒ bandeau masqué, ZÉRO erreur.
 *
 * Couvre les 2 couches de la lane E6 :
 *  - composant KdsScheduledBanner (rendu / masquage / format H:i / a11y) ;
 *  - store kitchenDisplaySystemOrder (commit défensif de la meta dans
 *    l'action `lists`, mutation, état initial).
 */

// ——— Mocks hermétiques pour la couche store ————————————————————————————
// axios : l'action `lists` fait un GET réel sinon.
vi.mock('axios', () => ({
    default: { get: vi.fn(), post: vi.fn() },
}));
// appService importe le store Vuex complet + vue3-simple-alert → on ne garde
// que requestHandler (utilisé pour construire l'URL du GET).
vi.mock('../../../resources/js/services/appService', () => ({
    default: { requestHandler: vi.fn(() => '') },
}));

import axios from 'axios';
import { kitchenDisplaySystemOrder } from '../../../resources/js/store/modules/kitchenDisplaySystemOrder';

// Fixtures TZ-indépendantes : datetimes NAÏFS (sans suffixe Z ni offset) —
// parsés en heure LOCALE, donc « H:i » affiché identique quel que soit le
// fuseau de la machine de CI.
const ENTRY_2030 = { id: 1234, order_serial_no: '1234', scheduled_at: '2026-07-20 20:30:00', order_type: 5 };
const ENTRY_2100 = { id: 1240, order_serial_no: '1240', scheduled_at: '2026-07-20T21:00:00', order_type: 5, customer_name: 'Ali' };

function mountBanner(props) {
    return mount(KdsScheduledBanner, { props });
}

describe('KdsScheduledBanner — composant (bandeau programmées à venir)', () => {
    it('liste vide → rien rendu (pas de bandeau, pas de texte)', () => {
        const wrapper = mountBanner({ entries: [] });
        expect(wrapper.find('[data-testid="kds-scheduled-banner"]').exists()).toBe(false);
        expect(wrapper.text()).toBe('');
        wrapper.unmount();
    });

    it('prop absente (défaut) → rien rendu, zéro erreur', () => {
        const wrapper = mountBanner(undefined);
        expect(wrapper.find('[data-testid="kds-scheduled-banner"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('2 entrées → compteur (2), 2 heures formatées H:i + serials, séparateur ·', () => {
        const wrapper = mountBanner({ entries: [ENTRY_2030, ENTRY_2100] });

        const banner = wrapper.find('[data-testid="kds-scheduled-banner"]');
        expect(banner.exists()).toBe(true);

        const text = banner.text();
        expect(text).toContain('Programmées (2)');
        expect(text).toContain('20:30');
        expect(text).toContain('#1234');
        expect(text).toContain('21:00');
        expect(text).toContain('#1240');
        expect(text).toContain('·');

        const entries = wrapper.findAll('.kds-scheduled-banner__entry');
        expect(entries.length).toBe(2);
        expect(entries[0].text()).toContain('20:30');
        expect(entries[0].text()).toContain('#1234');
        expect(entries[1].text()).toContain('21:00');
        expect(entries[1].text()).toContain('#1240');
        wrapper.unmount();
    });

    it('a11y : role="status" + aria-live="polite" sur le bandeau', () => {
        const wrapper = mountBanner({ entries: [ENTRY_2030] });
        const banner = wrapper.find('[data-testid="kds-scheduled-banner"]');
        expect(banner.attributes('role')).toBe('status');
        expect(banner.attributes('aria-live')).toBe('polite');
        wrapper.unmount();
    });

    it('entrées malformées (null, {}, scheduled_at inparsable, serial vide) → défensif, zéro erreur', () => {
        const wrapper = mountBanner({
            entries: [null, {}, { id: 7, order_serial_no: '', scheduled_at: 'garbage' }],
        });
        // null filtré ; {} et l'entrée id=7 rendues avec replis (--:-- / id / ?).
        const entries = wrapper.findAll('.kds-scheduled-banner__entry');
        expect(entries.length).toBe(2);
        expect(entries[0].text()).toContain('--:--');
        expect(entries[0].text()).toContain('#?');
        expect(entries[1].text()).toContain('--:--');
        expect(entries[1].text()).toContain('#7');
        wrapper.unmount();
    });
});

/**
 * [FIX SCHEDULED-STALE P3 2026-07-20] Désambiguïsation multi-jours : le board
 * admet désormais les programmées créées J-1..J-7 → le bandeau peut porter des
 * cibles AU-DELÀ d'aujourd'hui. Une heure nue « 20:30 » serait ambiguë : si la
 * date cible ≠ aujourd'hui, l'heure est préfixée « sam. 26/07 » (jour court FR
 * en dur — V1 FR-locked ADR-007, même rationale que le libellé du bandeau).
 * Source de la date : `scheduled_date` (Y-m-d serveur, Paris-local) avec repli
 * défensif sur la date de `scheduled_at` (contrat : meta PEUT être en retard).
 */
describe('KdsScheduledBanner — désambiguïsation multi-jours (scheduled_date)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 6, 20, 12, 0, 0)); // aujourd'hui = lun. 20/07/2026 (local)
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    it('cible un AUTRE jour → heure préfixée « sam. 25/07 » ; cible aujourd\'hui → heure nue', () => {
        const sameDay   = { id: 1301, order_serial_no: '1301', scheduled_at: '2026-07-20 21:00:00', scheduled_date: '2026-07-20', order_type: 5 };
        const futureDay = { id: 1300, order_serial_no: '1300', scheduled_at: '2026-07-25 20:30:00', scheduled_date: '2026-07-25', order_type: 5 };
        const wrapper = mountBanner({ entries: [sameDay, futureDay] });

        const entries = wrapper.findAll('.kds-scheduled-banner__entry');
        expect(entries.length).toBe(2);
        expect(entries[0].text()).toContain('21:00');
        expect(entries[0].text()).not.toContain('lun.');
        expect(entries[0].text()).not.toContain('20/07');
        expect(entries[1].text()).toContain('sam. 25/07 20:30');
        expect(entries[1].text()).toContain('#1300');
        wrapper.unmount();
    });

    it('scheduled_date ABSENT (backend en retard) → repli défensif sur la date de scheduled_at', () => {
        const wrapper = mountBanner({
            entries: [{ id: 1302, order_serial_no: '1302', scheduled_at: '2026-07-26T00:30:00', order_type: 5 }],
        });
        expect(wrapper.find('[data-testid="kds-scheduled-entry-0"]').text()).toContain('dim. 26/07 00:30');
        wrapper.unmount();
    });
});

describe('formatScheduledDayPrefix — helper préfixe jour', () => {
    const TODAY = new Date(2026, 6, 20, 12, 0, 0); // lun. 20/07/2026 (local)

    it('même jour → "" (pas de préfixe, affichage historique intact)', () => {
        expect(formatScheduledDayPrefix('2026-07-20', TODAY)).toBe('');
        expect(formatScheduledDayPrefix('2026-07-20 20:30:00', TODAY)).toBe('');
    });

    it('jour différent → « jjj. JJ/MM » (jour court FR en dur — déterministe sans ICU)', () => {
        expect(formatScheduledDayPrefix('2026-07-25', TODAY)).toBe('sam. 25/07');
        expect(formatScheduledDayPrefix('2026-07-21T09:05:00', TODAY)).toBe('mar. 21/07');
    });

    it('valeurs vides / inparsables → "" (zéro erreur)', () => {
        expect(formatScheduledDayPrefix(null, TODAY)).toBe('');
        expect(formatScheduledDayPrefix(undefined, TODAY)).toBe('');
        expect(formatScheduledDayPrefix('', TODAY)).toBe('');
        expect(formatScheduledDayPrefix('garbage', TODAY)).toBe('');
    });
});

describe('formatScheduledTime — helper H:i', () => {
    it('datetime SQL naïf → H:i local', () => {
        expect(formatScheduledTime('2026-07-20 20:30:00')).toBe('20:30');
    });
    it('ISO naïf → H:i local, minuit zéro-paddé', () => {
        expect(formatScheduledTime('2026-07-20T09:05:00')).toBe('09:05');
    });
    it('valeurs vides / inparsables → "" ou repli regex HH:MM', () => {
        expect(formatScheduledTime(null)).toBe('');
        expect(formatScheduledTime(undefined)).toBe('');
        expect(formatScheduledTime('')).toBe('');
        expect(formatScheduledTime('n/a')).toBe('');
    });
});

describe('kitchenDisplaySystemOrder store — commit défensif de meta.scheduled_upcoming', () => {
    let context;

    beforeEach(() => {
        vi.clearAllMocks();
        context = { commit: vi.fn(), dispatch: vi.fn() };
    });

    it('meta ABSENTE de la réponse → commit scheduledUpcoming [] (aucune erreur)', async () => {
        axios.get.mockResolvedValueOnce({ data: { data: [{ id: 9 }] } });

        await kitchenDisplaySystemOrder.actions.lists(context, { vuex: true });

        expect(context.commit).toHaveBeenCalledWith('lists', [{ id: 9 }]);
        expect(context.commit).toHaveBeenCalledWith('scheduledUpcoming', []);
    });

    it('meta présente mais scheduled_upcoming non-tableau → commit []', async () => {
        axios.get.mockResolvedValueOnce({ data: { data: [], meta: { scheduled_upcoming: 'oops' } } });

        await kitchenDisplaySystemOrder.actions.lists(context, { vuex: true });

        expect(context.commit).toHaveBeenCalledWith('scheduledUpcoming', []);
    });

    it('meta.scheduled_upcoming fournie → committée telle quelle', async () => {
        const upcoming = [ENTRY_2030, ENTRY_2100];
        axios.get.mockResolvedValueOnce({ data: { data: [], meta: { scheduled_upcoming: upcoming } } });

        await kitchenDisplaySystemOrder.actions.lists(context, { vuex: true });

        expect(context.commit).toHaveBeenCalledWith('scheduledUpcoming', upcoming);
    });

    it('payload.vuex === false → AUCUN commit (gate vuex existante respectée)', async () => {
        axios.get.mockResolvedValueOnce({ data: { data: [], meta: { scheduled_upcoming: [ENTRY_2030] } } });

        await kitchenDisplaySystemOrder.actions.lists(context, { vuex: false });

        expect(context.commit).not.toHaveBeenCalled();
    });

    it('état initial scheduledUpcoming = [] ; mutation défensive (non-tableau ⇒ [])', () => {
        expect(kitchenDisplaySystemOrder.state.scheduledUpcoming).toEqual([]);

        const state = { scheduledUpcoming: [] };
        kitchenDisplaySystemOrder.mutations.scheduledUpcoming(state, [ENTRY_2030]);
        expect(state.scheduledUpcoming).toEqual([ENTRY_2030]);

        kitchenDisplaySystemOrder.mutations.scheduledUpcoming(state, undefined);
        expect(state.scheduledUpcoming).toEqual([]);
    });

    it('getter scheduledUpcoming expose l\'état', () => {
        const state = { scheduledUpcoming: [ENTRY_2100] };
        expect(kitchenDisplaySystemOrder.getters.scheduledUpcoming(state)).toEqual([ENTRY_2100]);
    });
});
