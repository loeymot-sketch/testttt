/**
 * PosCashDrawerSessionDialog.spec.js — Sprint 1A
 *
 * Couvre les 3 modes du dialog session caisse :
 *   - mode "open" → render quand pas de session, submit appelle l'action Vuex
 *   - mode "close" → variance calculée correctement, raison requise si écart
 *   - mode "movements" → rendu du tableau
 *
 * Le service CashDrawerService est mocké via vi.mock pour isoler la couche
 * dialog Vue de la couche réseau.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';

// Mock the service module BEFORE importing anything that uses it.
vi.mock('../../resources/js/services/CashDrawerService', () => {
    const computeExpected = (openingAmount, movementsList) => {
        const base = Number(openingAmount) || 0;
        if (!Array.isArray(movementsList)) return base;
        const sum = movementsList.reduce((acc, m) => {
            const amt = Number(m && m.amount) || 0;
            const sign = m && m.direction === 'in' ? 1 : -1;
            return acc + sign * amt;
        }, 0);
        return Math.round((base + sum) * 100) / 100;
    };
    return {
        default: {
            currentSession: vi.fn(),
            openSession: vi.fn(),
            closeSession: vi.fn(),
            reconcile: vi.fn(),
            movements: vi.fn(),
            computeExpected,
        },
        currentSession: vi.fn(),
        openSession: vi.fn(),
        closeSession: vi.fn(),
        reconcile: vi.fn(),
        movements: vi.fn(),
        computeExpected,
    };
});

import PosCashDrawerSessionDialog from '../../resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue';

const messages = {
    'label.cash_session_dialog_title': 'Gestion de la caisse',
    'label.cash_session_open': 'Ouvrir la caisse',
    'label.cash_session_close': 'Clôturer la caisse',
    'label.cash_session_opening_amount': 'Fond de caisse initial',
    'label.cash_session_closing_amount': 'Montant compté',
    'label.cash_session_variance': 'Écart',
    'label.cash_session_variance_reason': "Raison de l'écart",
    'label.cash_session_movements': 'Mouvements de caisse',
    'label.cash_session_active': 'Session active',
    'label.cash_session_no_session': 'Aucune caisse ouverte',
    'label.cash_session_expected_amount': 'Montant attendu',
    'label.cash_session_opened_at': 'Ouverte le',
    'label.cash_session_movements_count': 'Mouvements',
    'label.cash_session_no_movements': 'Aucun mouvement enregistré.',
    'label.cash_session_confirm_close': 'Valider la clôture',
    'label.cash_session_view_movements': 'Voir les mouvements',
    'label.cash_session_back': 'Retour',
    'label.cash_session_header_btn': 'Caisse',
    'label.cash_session_required_reason': "L'écart nécessite une raison.",
    'label.cash_session_failure': 'Opération impossible.',
    'button.close': 'Fermer',
    'button.cancel': 'Annuler',
    'button.clear': 'Effacer',
    'label.amount': 'Montant',
    'label.date': 'Date',
    'label.notes': 'Notes',
};

function makeStore({ session = null, movements = [], loading = false } = {}) {
    const openSession = vi.fn().mockImplementation(async () => {
        const created = { id: 7, branch_id: 1, status: 'open', opening_amount: 50, opened_at: new Date().toISOString() };
        return created;
    });
    const closeSession = vi.fn().mockImplementation(async () => ({
        id: session ? session.id : 7,
        expected: 50,
        variance: 0,
    }));
    const loadCurrentSession = vi.fn().mockResolvedValue(session);
    const loadMovements = vi.fn().mockResolvedValue(movements);

    const store = createStore({
        modules: {
            cashDrawer: {
                namespaced: true,
                state: () => ({ session, movements, loading, error: null }),
                getters: {
                    session: (state) => state.session,
                    isOpen: (state) => !!(state.session && state.session.status === 'open'),
                    movements: (state) => state.movements,
                    loading: (state) => state.loading,
                    error: (state) => state.error,
                    openingAmount: (state) => (state.session ? Number(state.session.opening_amount) || 0 : 0),
                    sessionId: (state) => (state.session ? state.session.id : null),
                },
                actions: {
                    openSession,
                    closeSession,
                    loadCurrentSession,
                    loadMovements,
                },
            },
        },
    });
    return { store, openSession, closeSession, loadCurrentSession, loadMovements };
}

function mountDialog({ session = null, movements = [], initialMode = 'auto' } = {}) {
    const { store, openSession, closeSession, loadCurrentSession, loadMovements } = makeStore({ session, movements });
    const wrapper = mount(PosCashDrawerSessionDialog, {
        props: { open: true, initialMode },
        global: {
            plugins: [store],
            mocks: {
                $t: (key) => messages[key] || key,
            },
        },
    });
    return { wrapper, store, openSession, closeSession, loadCurrentSession, loadMovements };
}

describe('PosCashDrawerSessionDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders "Ouvrir la caisse" form when no session exists', async () => {
        const { wrapper } = mountDialog({ session: null });
        await flushPromises();
        expect(wrapper.find('[data-testid="cash-session-open-form"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Aucune caisse ouverte');
        expect(wrapper.text()).toContain('Fond de caisse initial');
    });

    it('renders "Session active" view when an OPEN session is present', async () => {
        const openSession = {
            id: 42,
            branch_id: 1,
            status: 'open',
            opening_amount: 50,
            opened_at: new Date().toISOString(),
        };
        const { wrapper } = mountDialog({ session: openSession });
        await flushPromises();
        expect(wrapper.find('[data-testid="cash-session-active-view"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="cash-session-stat-opening"]').text()).toMatch(/50/);
    });

    it('submits opening_amount via the openSession Vuex action', async () => {
        const { wrapper, openSession } = mountDialog({ session: null });
        await flushPromises();

        // Bump opening by clicking +50 chip twice → 50 (default) + 50 + 50 = 150
        await wrapper.find('[data-testid="cash-session-open-inc-50"]').trigger('click');
        await wrapper.find('[data-testid="cash-session-open-inc-50"]').trigger('click');

        await wrapper.find('[data-testid="cash-session-open-submit"]').trigger('click');
        await flushPromises();

        expect(openSession).toHaveBeenCalledTimes(1);
        // The 2nd arg of the dispatch is the payload (openingAmount).
        // In our store mock, openSession action receives (context, payload).
        const lastCall = openSession.mock.calls[0];
        // payload is the 2nd arg
        expect(lastCall[1]).toBe(150);
    });

    it('calculates variance correctly in close mode', async () => {
        const openSession = {
            id: 42,
            status: 'open',
            opening_amount: 50,
            opened_at: new Date().toISOString(),
        };
        const movements = [
            { id: 1, amount: 10, direction: 'in', type: 'order_payment', created_at: new Date().toISOString() },
            { id: 2, amount: 5, direction: 'out', type: 'adjustment', created_at: new Date().toISOString() },
        ];
        // expected = 50 + 10 - 5 = 55
        const { wrapper } = mountDialog({ session: openSession, movements, initialMode: 'close' });
        await flushPromises();

        expect(wrapper.find('[data-testid="cash-session-close-form"]').exists()).toBe(true);

        const expectedDisplay = wrapper.find('[data-testid="cash-session-close-expected"]').text();
        expect(expectedDisplay).toMatch(/55/);

        // Add +50 to closing → 50 ; variance = 50 - 55 = -5
        await wrapper.find('[data-testid="cash-session-close-inc-50"]').trigger('click');
        await flushPromises();
        const varianceText = wrapper.find('[data-testid="cash-session-close-variance"]').text();
        expect(varianceText).toMatch(/5/);
    });

    it('requires a variance reason when |variance| > 0 before allowing close submit', async () => {
        const openSession = {
            id: 42,
            status: 'open',
            opening_amount: 50,
            opened_at: new Date().toISOString(),
        };
        const { wrapper, closeSession } = mountDialog({
            session: openSession,
            movements: [],
            initialMode: 'close',
        });
        await flushPromises();

        // expected = 50, counted = 60 → variance = +10, reason required
        await wrapper.find('[data-testid="cash-session-close-inc-50"]').trigger('click');
        await wrapper.find('[data-testid="cash-session-close-inc-10"]').trigger('click');
        await flushPromises();

        // No reason yet → submit button should be disabled
        const submit = wrapper.find('[data-testid="cash-session-close-submit"]');
        expect(submit.attributes('disabled')).toBeDefined();

        // Add a reason
        await wrapper.find('[data-testid="cash-session-reason-input"]').setValue('Erreur de comptage du caissier');
        await flushPromises();
        expect(submit.attributes('disabled')).toBeUndefined();

        await submit.trigger('click');
        await flushPromises();

        expect(closeSession).toHaveBeenCalledTimes(1);
        const payload = closeSession.mock.calls[0][1];
        expect(payload.closingAmount).toBe(60);
        expect(payload.varianceReason).toBe('Erreur de comptage du caissier');
    });

    it('allows close submit without reason when variance is zero', async () => {
        const openSession = {
            id: 42,
            status: 'open',
            opening_amount: 50,
            opened_at: new Date().toISOString(),
        };
        const { wrapper, closeSession } = mountDialog({
            session: openSession,
            movements: [],
            initialMode: 'close',
        });
        await flushPromises();

        // expected = 50, counted = 50 → variance = 0 ; no reason needed.
        await wrapper.find('[data-testid="cash-session-close-inc-50"]').trigger('click');
        await flushPromises();

        const submit = wrapper.find('[data-testid="cash-session-close-submit"]');
        expect(submit.attributes('disabled')).toBeUndefined();
        await submit.trigger('click');
        await flushPromises();

        expect(closeSession).toHaveBeenCalledTimes(1);
    });

    it('renders movements list in movements mode', async () => {
        const openSession = {
            id: 42,
            status: 'open',
            opening_amount: 50,
            opened_at: new Date().toISOString(),
        };
        const movements = [
            { id: 1, amount: 12.5, direction: 'in', type: 'order_payment', notes: 'Order #1', created_at: new Date().toISOString() },
            { id: 2, amount: 3, direction: 'out', type: 'adjustment', notes: null, created_at: new Date().toISOString() },
        ];
        const { wrapper } = mountDialog({ session: openSession, movements, initialMode: 'movements' });
        await flushPromises();
        expect(wrapper.find('[data-testid="cash-session-movements-view"]').exists()).toBe(true);
        const rows = wrapper.findAll('[data-testid="cash-session-movement-row"]');
        expect(rows.length).toBe(2);
    });

    it('shows empty state when no movements', async () => {
        const openSession = {
            id: 42,
            status: 'open',
            opening_amount: 50,
            opened_at: new Date().toISOString(),
        };
        const { wrapper } = mountDialog({ session: openSession, movements: [], initialMode: 'movements' });
        await flushPromises();
        expect(wrapper.find('[data-testid="cash-session-movements-empty"]').exists()).toBe(true);
    });

    it('emits "close" when backdrop is clicked', async () => {
        const { wrapper } = mountDialog({ session: null });
        await flushPromises();
        await wrapper.find('[data-testid="cash-session-overlay"]').trigger('click');
        await flushPromises();
        expect(wrapper.emitted('close')).toBeTruthy();
    });
});
