/**
 * KioskThemeManagerPage.spec.js — [V2-5 Phase 2]
 *
 * Couvre :
 *   - Mount + render des 3 cartes (standard / halloween / christmas)
 *   - Bootstrap : dispatch branch/lists + GET admin/kiosk-theme/{id}
 *   - Sélection d'un thème → PATCH admin/kiosk-theme/{id} + status success
 *   - Erreur GET → fallback "standard"
 *   - Erreur PATCH → status type=error
 *   - Active card highlighted (is-active prop)
 *   - A11y : role=radiogroup + aria-label + role=status sur le message
 *   - Branch selector visible uniquement pour head-office (authBranchId=0)
 *
 * Mocks :
 *   - axios (vi.mock) : .get / .patch contrôlables par test
 *   - KioskSkeletonLoader : stub minimal (déjà testé ailleurs)
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';

// vi.mock DOIT être hoisté avant l'import du composant testé.
vi.mock('axios', () => {
    const get = vi.fn();
    const patch = vi.fn();
    return {
        default: { get, patch },
    };
});

vi.mock('../../resources/js/components/frontend/kiosk/KioskSkeletonLoader.vue', () => ({
    default: {
        name: 'KioskSkeletonLoader',
        props: ['type', 'count'],
        template: '<div data-testid="skeleton-stub" :data-type="type" :data-count="count" />',
    },
}));

import axios from 'axios';
import KioskThemeManagerPage from '../../resources/js/components/admin/kioskTheme/KioskThemeManagerPage.vue';

function makeStore({ authBranchId = 0, branchLists = [] } = {}) {
    return createStore({
        getters: {
            authBranchId: () => authBranchId,
        },
        modules: {
            branch: {
                namespaced: true,
                state: () => ({ lists: branchLists }),
                getters: {
                    lists: (state) => state.lists,
                },
                actions: {
                    lists: vi.fn(() => Promise.resolve()),
                },
            },
        },
    });
}

function mountPage({ authBranchId = 0, branchLists = [{ id: 1, name: 'HQ' }, { id: 2, name: 'Marseille' }] } = {}) {
    const store = makeStore({ authBranchId, branchLists });
    const w = mount(KioskThemeManagerPage, {
        global: {
            plugins: [store],
            mocks: { $t: (k) => k },
        },
    });
    return { w, store };
}

describe('KioskThemeManagerPage [V2-5 Phase 2]', () => {
    beforeEach(() => {
        axios.get.mockReset();
        axios.patch.mockReset();
        // Default : GET returns standard
        axios.get.mockResolvedValue({ data: { active_theme: 'standard' } });
        // Default : PATCH ok
        axios.patch.mockResolvedValue({ data: { status: true, active_theme: 'halloween' } });
    });

    it('renders the 3 theme preview cards after bootstrap', async () => {
        const { w } = mountPage();
        await flushPromises();

        expect(w.find('[data-testid="kiosk-theme-grid"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-theme-card-standard"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-theme-card-halloween"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-theme-card-christmas"]').exists()).toBe(true);
    });

    it('shows skeleton loader during bootstrap', async () => {
        // Block the GET so loading remains true
        let resolveGet;
        axios.get.mockReturnValue(new Promise((resolve) => { resolveGet = resolve; }));

        const { w } = mountPage();
        // micro-task : let mount run, GET fired but pending
        await Promise.resolve();
        expect(w.find('[data-testid="kiosk-theme-loading"]').exists()).toBe(true);

        resolveGet({ data: { active_theme: 'standard' } });
        await flushPromises();
        expect(w.find('[data-testid="kiosk-theme-loading"]').exists()).toBe(false);
    });

    it('calls GET admin/kiosk-theme/{branchId} with the user branch when scoped Admin', async () => {
        const { w } = mountPage({ authBranchId: 2 });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith('admin/kiosk-theme/2');
        // No /api/ prefix double-up : the relative path matches the codebase convention.
        expect(axios.get.mock.calls[0][0].startsWith('/api/')).toBe(false);
    });

    it('falls back to first branch when user is head-office (authBranchId=0)', async () => {
        const { w } = mountPage({ authBranchId: 0, branchLists: [{ id: 7, name: 'HQ' }, { id: 9, name: 'Lyon' }] });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith('admin/kiosk-theme/7');
    });

    it('hides branch selector for scoped Admin (single branch)', async () => {
        const { w } = mountPage({ authBranchId: 3 });
        await flushPromises();

        expect(w.find('[data-testid="kiosk-theme-branch-select"]').exists()).toBe(false);
    });

    it('shows branch selector for head-office Admin with multiple branches', async () => {
        const { w } = mountPage({
            authBranchId: 0,
            branchLists: [{ id: 1, name: 'HQ' }, { id: 2, name: 'Marseille' }],
        });
        await flushPromises();

        const select = w.find('[data-testid="kiosk-theme-branch-select"]');
        expect(select.exists()).toBe(true);
        // Both branches rendered as options
        expect(select.findAll('option')).toHaveLength(2);
    });

    it('highlights the active theme card after GET resolves', async () => {
        axios.get.mockResolvedValue({ data: { active_theme: 'halloween' } });
        const { w } = mountPage();
        await flushPromises();

        const halloweenCard = w.find('[data-testid="kiosk-theme-card-halloween"]');
        expect(halloweenCard.classes()).toContain('active');
        expect(halloweenCard.attributes('aria-checked')).toBe('true');

        const standardCard = w.find('[data-testid="kiosk-theme-card-standard"]');
        expect(standardCard.classes()).not.toContain('active');
        expect(standardCard.attributes('aria-checked')).toBe('false');
    });

    it('falls back to "standard" when GET fails', async () => {
        axios.get.mockRejectedValue(new Error('network'));
        const { w } = mountPage();
        await flushPromises();

        const standardCard = w.find('[data-testid="kiosk-theme-card-standard"]');
        expect(standardCard.classes()).toContain('active');
    });

    it('triggers PATCH admin/kiosk-theme/{id} with new theme on click', async () => {
        const { w } = mountPage({ authBranchId: 5 });
        await flushPromises();

        await w.find('[data-testid="kiosk-theme-card-halloween"]').trigger('click');
        await flushPromises();

        expect(axios.patch).toHaveBeenCalledWith('admin/kiosk-theme/5', { theme: 'halloween' });
    });

    it('updates active card after successful PATCH', async () => {
        const { w } = mountPage({ authBranchId: 5 });
        await flushPromises();

        await w.find('[data-testid="kiosk-theme-card-christmas"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="kiosk-theme-card-christmas"]').classes()).toContain('active');
    });

    it('shows success status after successful PATCH', async () => {
        const { w } = mountPage({ authBranchId: 5 });
        await flushPromises();

        await w.find('[data-testid="kiosk-theme-card-halloween"]').trigger('click');
        await flushPromises();

        const status = w.find('[data-testid="kiosk-theme-status"]');
        expect(status.exists()).toBe(true);
        expect(status.classes()).toContain('kt-status-success');
        expect(status.attributes('role')).toBe('status');
        expect(status.attributes('aria-live')).toBe('polite');
    });

    it('shows error status when PATCH fails', async () => {
        axios.patch.mockRejectedValue({ response: { data: { message: 'Branch scope denied.' } } });
        const { w } = mountPage({ authBranchId: 5 });
        await flushPromises();

        await w.find('[data-testid="kiosk-theme-card-halloween"]').trigger('click');
        await flushPromises();

        const status = w.find('[data-testid="kiosk-theme-status"]');
        expect(status.exists()).toBe(true);
        expect(status.classes()).toContain('kt-status-error');
    });

    it('exposes role=radiogroup with aria-label on the theme grid (a11y)', async () => {
        const { w } = mountPage();
        await flushPromises();

        const grid = w.find('[data-testid="kiosk-theme-grid"]');
        expect(grid.attributes('role')).toBe('radiogroup');
        expect(grid.attributes('aria-label')).toBe('kiosk.admin.theme_select_aria');
    });

    it('keyboard activation (Enter) on a card triggers select', async () => {
        const { w } = mountPage({ authBranchId: 5 });
        await flushPromises();

        await w.find('[data-testid="kiosk-theme-card-halloween"]').trigger('keydown.enter');
        await flushPromises();

        expect(axios.patch).toHaveBeenCalledWith('admin/kiosk-theme/5', { theme: 'halloween' });
    });

    it('does not call PATCH when no branch is selected', async () => {
        const { w } = mountPage({ authBranchId: 0, branchLists: [] });
        await flushPromises();
        axios.patch.mockClear();

        await w.find('[data-testid="kiosk-theme-card-halloween"]').trigger('click');
        await flushPromises();

        expect(axios.patch).not.toHaveBeenCalled();
    });

    // [A11y-HEAL-2026-05-08] P1 fix — radiogroup keyboard nav.
    // WAI-ARIA APG radiogroup pattern : roving tabindex + arrow keys move
    // focus *and* selection (selection follows focus, like native radios).
    describe('radiogroup arrow nav + roving tabindex (P1 a11y)', () => {
        it('uses roving tabindex (only the active card is tabindex=0)', async () => {
            // Default GET resolves to "standard" → only the standard card
            // should expose tabindex=0 ; the two others should be tabindex=-1.
            const { w } = mountPage({ authBranchId: 5 });
            await flushPromises();

            const cards = w.findAll('[role="radio"]');
            expect(cards.length).toBe(3);
            const tabindexes = cards.map((c) => c.attributes('tabindex'));
            expect(tabindexes.filter((t) => t === '0').length).toBe(1);
            expect(tabindexes.filter((t) => t === '-1').length).toBe(2);

            // The single tabbable card must be the active one.
            const activeCard = w.find('[data-testid="kiosk-theme-card-standard"]');
            expect(activeCard.attributes('tabindex')).toBe('0');
        });

        it('roving tabindex follows the active theme after PATCH', async () => {
            const { w } = mountPage({ authBranchId: 5 });
            await flushPromises();

            // Click halloween → it becomes active → it must take tabindex=0.
            await w.find('[data-testid="kiosk-theme-card-halloween"]').trigger('click');
            await flushPromises();

            const halloweenCard = w.find('[data-testid="kiosk-theme-card-halloween"]');
            const standardCard = w.find('[data-testid="kiosk-theme-card-standard"]');
            expect(halloweenCard.attributes('tabindex')).toBe('0');
            expect(standardCard.attributes('tabindex')).toBe('-1');
        });

        it('ArrowRight on a card moves selection to the next card (selection-follows-focus)', async () => {
            const { w } = mountPage({ authBranchId: 5 });
            await flushPromises();
            // active = standard (idx 0) → ArrowRight should target halloween (idx 1)
            axios.patch.mockClear();

            await w.find('[data-testid="kiosk-theme-card-standard"]').trigger('keydown', { key: 'ArrowRight' });
            await flushPromises();

            expect(axios.patch).toHaveBeenCalledWith('admin/kiosk-theme/5', { theme: 'halloween' });
        });

        it('ArrowDown also navigates to next (vertical equivalent)', async () => {
            const { w } = mountPage({ authBranchId: 5 });
            await flushPromises();
            axios.patch.mockClear();

            await w.find('[data-testid="kiosk-theme-card-standard"]').trigger('keydown', { key: 'ArrowDown' });
            await flushPromises();

            expect(axios.patch).toHaveBeenCalledWith('admin/kiosk-theme/5', { theme: 'halloween' });
        });

        it('ArrowLeft on a card moves selection to the previous card (with wrap-around)', async () => {
            const { w } = mountPage({ authBranchId: 5 });
            await flushPromises();
            // active = standard (idx 0) → ArrowLeft wraps to christmas (idx 2)
            axios.patch.mockClear();

            await w.find('[data-testid="kiosk-theme-card-standard"]').trigger('keydown', { key: 'ArrowLeft' });
            await flushPromises();

            expect(axios.patch).toHaveBeenCalledWith('admin/kiosk-theme/5', { theme: 'christmas' });
        });

        it('ArrowUp also navigates to previous (vertical equivalent + wrap-around)', async () => {
            const { w } = mountPage({ authBranchId: 5 });
            await flushPromises();
            axios.patch.mockClear();

            await w.find('[data-testid="kiosk-theme-card-standard"]').trigger('keydown', { key: 'ArrowUp' });
            await flushPromises();

            expect(axios.patch).toHaveBeenCalledWith('admin/kiosk-theme/5', { theme: 'christmas' });
        });

        it('ArrowRight wraps from the last card to the first (christmas → standard)', async () => {
            // Pre-set active = christmas via GET
            axios.get.mockResolvedValue({ data: { active_theme: 'christmas' } });
            const { w } = mountPage({ authBranchId: 5 });
            await flushPromises();
            axios.patch.mockClear();

            await w.find('[data-testid="kiosk-theme-card-christmas"]').trigger('keydown', { key: 'ArrowRight' });
            await flushPromises();

            expect(axios.patch).toHaveBeenCalledWith('admin/kiosk-theme/5', { theme: 'standard' });
        });

        it('emits navigate from KioskThemePreviewCard on ArrowRight (parent contract)', async () => {
            const { w } = mountPage({ authBranchId: 5 });
            await flushPromises();

            // Find the child component instance and verify it emits 'navigate'.
            const card = w.findComponent({ name: 'KioskThemePreviewCard' });
            expect(card.exists()).toBe(true);
            await card.find('[role="radio"]').trigger('keydown', { key: 'ArrowRight' });
            // KioskThemePreviewCard re-emits 'navigate' to the parent via @keydown.right.
            expect(card.emitted('navigate')).toBeTruthy();
            expect(card.emitted('navigate')[0]).toEqual(['next']);
        });
    });
});
