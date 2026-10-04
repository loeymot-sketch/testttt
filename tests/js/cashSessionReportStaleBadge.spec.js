/**
 * [ULTRA-AUDIT 2026-09-26 · A20] Rapport externe (Codex) : deux sessions de
 * caisse concurrentes (branche 1) restées `Ouverte` simultanément, la plus
 * ancienne depuis 78+ jours, sans aucun signal sur l'écran conçu précisément
 * pour "voir les caisses chaque jour, début et fin" (mandat owner d'origine).
 * `open_since_hours` est désormais calculé côté serveur (voir
 * CashSessionReportControllerTest::test_open_session_exposes_its_age_in_hours)
 * — ce spec prouve que l'écran l'utilise réellement pour alerter visuellement,
 * pas seulement le recevoir sans jamais l'afficher.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } } }),
    },
}));
vi.mock('../../resources/js/services/CashDrawerService', () => ({ reconcile: vi.fn() }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

import CashSessionReportListComponent from '../../resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue';

function makeSession(overrides = {}) {
    return {
        id: 1, branch_id: 1, business_date: '2026-09-26',
        opened_at: '2026-09-26T08:00:00Z', closed_at: null,
        opened_by_name: 'Caissier A', opening_amount: 100,
        closing_amount: null, expected_closing_amount: null,
        variance: null, variance_reason: null,
        status: 'open', transactions_count: 12,
        open_since_hours: null,
        ...overrides,
    };
}

async function mountWithSessions(sessions) {
    const wrapper = mount(CashSessionReportListComponent, {
        global: { mocks: { $t: (k, params) => (params ? `${k}:${JSON.stringify(params)}` : k), $i18n: { locale: 'fr-FR' } } },
    });
    await flushPromises();
    wrapper.vm.sessions = sessions;
    await wrapper.vm.$nextTick();
    return wrapper;
}

describe('CashSessionReportListComponent — badge de session ouverte anormalement longtemps (A20)', () => {
    it('affiche le badge d\'alerte pour une session open depuis 78 jours (1872h)', async () => {
        const wrapper = await mountWithSessions([makeSession({ open_since_hours: 1872 })]);
        expect(wrapper.find('[data-testid="cash-session-stale-badge"]').exists()).toBe(true);
    });

    it('n\'affiche PAS le badge pour une session open depuis seulement 2h (usage normal)', async () => {
        const wrapper = await mountWithSessions([makeSession({ open_since_hours: 2 })]);
        expect(wrapper.find('[data-testid="cash-session-stale-badge"]').exists()).toBe(false);
    });

    it('n\'affiche jamais le badge pour une session déjà fermée, même avec un âge présent par erreur', async () => {
        const wrapper = await mountWithSessions([makeSession({ status: 'closed', open_since_hours: 1872 })]);
        expect(wrapper.find('[data-testid="cash-session-stale-badge"]').exists()).toBe(false);
    });

    it('pile au seuil (24h) affiche déjà le badge (>= pas >)', async () => {
        const wrapper = await mountWithSessions([makeSession({ open_since_hours: 24 })]);
        expect(wrapper.find('[data-testid="cash-session-stale-badge"]').exists()).toBe(true);
    });

    it('juste sous le seuil (23h) n\'affiche pas encore le badge', async () => {
        const wrapper = await mountWithSessions([makeSession({ open_since_hours: 23 })]);
        expect(wrapper.find('[data-testid="cash-session-stale-badge"]').exists()).toBe(false);
    });
});
