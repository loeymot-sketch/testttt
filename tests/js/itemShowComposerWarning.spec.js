import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import ComposerProfileWarningBadge from '../../resources/js/components/admin/items/ComposerProfileWarningBadge.vue';
import fr from '../../resources/js/languages/fr.json';

const localeFr = fr?.default ?? fr;

/** @typedef {{code:string, severity:string, context?:object}} ApiWarning */

function testI18n() {
    return createI18n({
        legacy: true,
        locale: 'fr',
        messages: { fr: localeFr },
        silentFallbackWarn: true,
    });
}

function mountBadge(warnings, routeId = '42') {
    const push = vi.fn(() => Promise.resolve());
    const i18n = testI18n();
    return mount(ComposerProfileWarningBadge, {
        props: {
            warnings,
            dismissible: false,
        },
        global: {
            plugins: [i18n],
            mocks: {
                $route: { params: { id: routeId }, name: '' },
                $router: { push },
            },
        },
    });
}

describe('ComposerProfileWarningBadge', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('affiche warning composer_unpublished et textes i18n FR', async () => {
        const warnings = [
            {
                code: 'composer_unpublished',
                severity: 'warning',
                context: { profile_id: 3 },
            },
        ];
        const wrapper = mountBadge(warnings);
        await flushPromises();
        const badge = wrapper.get('[data-testid="warning-badge-composer_unpublished"]');
        expect(badge.attributes('data-severity')).toBe('warning');
        expect(badge.text()).toContain(localeFr.warning.catalog.composer_unpublished.title);
        expect(badge.text()).toContain(localeFr.warning.catalog.composer_unpublished.action);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.find('[aria-live="assertive"]').exists()).toBe(false);
        expect(wrapper.find('[aria-live="polite"]').exists()).toBe(true);
    });

    it('affiche blocker composer_missing pour item complex sans profil', async () => {
        const warnings = [
            {
                code: 'composer_missing_for_complex_kind',
                severity: 'blocker',
                context: [],
            },
        ];
        const wrapper = mountBadge(warnings);
        await flushPromises();
        const badge = wrapper.get('[data-testid="warning-badge-composer_missing_for_complex_kind"]');
        expect(badge.attributes('data-severity')).toBe('blocker');
        expect(badge.text()).toContain(localeFr.warning.catalog.composer_missing_for_complex_kind.title);
        expect(badge.attributes('role')).toBe('alert');
        expect(badge.attributes('aria-live')).toBe('assertive');
    });

    it('n’affiche pas de badges composer quand response ne contient que channels_null', async () => {
        const warnings = [
            {
                code: 'channels_null',
                severity: 'info',
                context: [],
            },
        ];
        const wrapper = mountBadge(warnings);
        await flushPromises();
        expect(wrapper.find('[data-testid="warning-badge-composer_unpublished"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="warning-badge-composer_missing_for_complex_kind"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toContain(localeFr.warning.catalog.channels_null.title);
    });

    // [2026-09-02] Le bouton visait `/admin/items/show/{id}/composer`, une route ABSENTE du routeur
    // (`itemRoutes.js` déclare `/admin/items/:id/composer`). Le clic ne menait nulle part, et rien
    // ne l'attrapait : ce test figeait la faute au lieu de la voir.
    it('clic Publier appelle vue-router vers la vraie route Composeur produit', async () => {
        const warnings = [
            {
                code: 'composer_unpublished',
                severity: 'warning',
                context: { profile_id: 7 },
            },
        ];
        const wrapper = mountBadge(warnings);
        await flushPromises();
        await wrapper.get('[data-testid="warning-action-composer_unpublished"]').trigger('click');
        await flushPromises();
        expect(wrapper.vm.$router.push).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.$router.push).toHaveBeenCalledWith('/admin/items/42/composer');
    });

    // Le wizard se gère PAR CATÉGORIE : quand l'avertissement en désigne une, c'est là qu'il faut
    // atterrir — c'est le seul écran où « Synchroniser les produits » existe.
    it('un avertissement qui designe une categorie ouvre le wizard de la categorie', async () => {
        const warnings = [
            {
                code: 'composer_item_not_synced',
                severity: 'warning',
                context: { category_id: 5, category_profile_id: 38 },
            },
        ];
        const wrapper = mountBadge(warnings);
        await flushPromises();
        await wrapper.get('[data-testid="warning-action-composer_item_not_synced"]').trigger('click');
        await flushPromises();
        expect(wrapper.vm.$router.push).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.$router.push).toHaveBeenCalledWith('/admin/categories/5/composer');
    });
});
