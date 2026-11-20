// [APP HYBRIDE W2a · 2026-11-03] Écran « Vitrine » : l'indicateur de contraste dit la même chose que le serveur, et un refus
// du serveur s'affiche À CÔTÉ du bouton qui l'a provoqué (mémoire formulaire-admin-echec-muet), jamais en haut de page.
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('axios', () => ({ default: vi.fn() }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

import axios from 'axios';
import StorefrontComponent, { contrastRatio } from '../../resources/js/components/admin/storefront/StorefrontComponent.vue';

const DEFAULT_THEME = {
    primary: '#FF5A1F', on_primary: '#0A0A0A', accent: '#FFD93D', background: '#FAF7F2',
    surface: '#FFFFFF', text: '#0A0A0A', banner_bg: '#0A0A0A', banner_text: '#FFD93D',
};

function state(over = {}) {
    return {
        draft: { enabled: true, theme: { ...DEFAULT_THEME }, delivery_on_message: null, delivery_off_message: null },
        banners: [], published: { version: 2, published_at: '2026-11-03T10:00:00+01:00', published_by: 'Responsable' },
        history: [{ version: 2, action: 'publish', source_version: null, published_at: '2026-11-03T10:00:00+01:00', published_by: 'Responsable', banners: 0, enabled: true },
            { version: 1, action: 'publish', source_version: null, published_at: '2026-11-02T10:00:00+01:00', published_by: 'Responsable', banners: 0, enabled: true }],
        has_unpublished_changes: false,
        preview: { version: 2, enabled: true, theme: DEFAULT_THEME, banners: [], delivery: { enabled: true, message: 'Livraison disponible.' }, next_change_at: null },
        delivery: { enabled: true },
        meta: {
            tokens: Object.keys(DEFAULT_THEME).map((key) => ({ key, label: key })),
            contrast_pairs: [['text', 'background'], ['text', 'surface'], ['on_primary', 'primary'], ['banner_text', 'banner_bg']],
            min_contrast: 4.5, default_theme: DEFAULT_THEME,
            link_routes: [{ route: 'menu', link: '#menu', label: 'La carte' }], max_banners: 20,
            delivery_messages: { on: 'Livraison disponible.', off: 'Suspendue.' }, timezone: 'Europe/Paris',
        },
        ...over,
    };
}
const ok = (data) => Promise.resolve({ data: { status: true, data } });

describe('Écran Vitrine', () => {
    beforeEach(() => { axios.mockReset(); });

    it('calcule le contraste comme le serveur (WCAG 2.1)', () => {
        expect(contrastRatio('#FFFFFF', '#000000')).toBeCloseTo(21, 3);
        expect(contrastRatio('#FFFFFF', '#FF5A1F')).toBeLessThan(4.5);
        expect(contrastRatio('#767676', '#FFFFFF')).toBeGreaterThanOrEqual(4.5);
        expect(contrastRatio('#777777', '#FFFFFF')).toBeLessThan(4.5);
    });

    it('signale un contraste trop faible et bloque l\'enregistrement', async () => {
        axios.mockImplementation(() => ok(state()));
        const w = mount(StorefrontComponent);
        await flushPromises();
        await w.find('[data-testid="sf-hex-on_primary"]').setValue('#FFFFFF');
        const bad = w.findAll('[data-testid="sf-contrast"] li.is-bad');
        expect(bad).toHaveLength(1);
        expect(bad[0].text()).toContain('3,11:1');
        expect(bad[0].text()).toContain('4,5:1');
        expect(w.find('[data-testid="sf-save-settings"]').attributes('disabled')).toBeDefined();
    });

    it('affiche le refus du serveur juste au-dessus du bouton Publier', async () => {
        axios.mockImplementation((cfg) => {
            if (cfg.method === 'post' && cfg.url === 'admin/storefront/publish') {
                return Promise.reject({ response: { status: 409, data: { status: false, message: "Une autre publication vient d'avoir lieu (version 3). Rechargez la vitrine avant de publier." } } });
            }
            return ok(state({ has_unpublished_changes: true }));
        });
        const w = mount(StorefrontComponent);
        await flushPromises();
        await w.find('[data-testid="sf-publish"]').trigger('click');
        await flushPromises();
        const errors = w.find('[data-testid="sf-publish-errors"]');
        expect(errors.exists()).toBe(true);
        expect(errors.text()).toContain('Une autre publication');
        // Même section que le bouton : l'erreur est visible là où l'on vient de cliquer.
        expect(errors.element.closest('section')).toBe(w.find('[data-testid="sf-publish"]').element.closest('section'));
        const publishCall = axios.mock.calls.find(([c]) => c.url === 'admin/storefront/publish');
        expect(publishCall[0].data).toEqual({ base_version: 2 });
    });

    it('affiche les erreurs d\'un bandeau dans le formulaire du bandeau', async () => {
        axios.mockImplementation((cfg) => {
            if (cfg.method === 'post' && cfg.url === 'admin/storefront/banners') {
                return Promise.reject({ response: { status: 422, data: { message: 'x', errors: { ends_at: ['La fin doit être après le début.'] } } } });
            }
            return ok(state());
        });
        const w = mount(StorefrontComponent);
        await flushPromises();
        await w.find('[data-testid="sf-new-banner"]').trigger('click');
        await w.find('[data-testid="sf-b-title"]').setValue('Noël');
        await w.find('[data-testid="sf-editor"]').trigger('submit');
        await flushPromises();
        const errors = w.find('[data-testid="sf-editor"] [data-testid="sf-banner-errors"]');
        expect(errors.text()).toContain('La fin doit être après le début.');
    });

    it('enregistrer un bandeau ne perd pas les réglages non enregistrés, et Publier les enregistre d\'abord', async () => {
        // Régression constatée en navigateur le 2026-11-03 : la vitrine publiée était ÉTEINTE.
        const off = state({ draft: { enabled: false, theme: null, delivery_on_message: null, delivery_off_message: null } });
        const calls = [];
        axios.mockImplementation((cfg) => {
            calls.push(`${cfg.method} ${cfg.url}`);
            if (cfg.method === 'put' && cfg.url === 'admin/storefront/settings') {
                return ok(state({ draft: { enabled: cfg.data.enabled, theme: cfg.data.theme, delivery_on_message: null, delivery_off_message: null } }));
            }
            return ok(off);
        });
        const w = mount(StorefrontComponent);
        await flushPromises();
        await w.find('[data-testid="sf-enabled"]').trigger('click');
        await w.find('[data-testid="sf-custom-theme"]').trigger('click');
        await w.find('[data-testid="sf-new-banner"]').trigger('click');
        await w.find('[data-testid="sf-b-title"]').setValue('Noël');
        await w.find('[data-testid="sf-editor"]').trigger('submit');
        await flushPromises();
        expect(w.find('[data-testid="sf-enabled"]').attributes('aria-pressed')).toBe('true');
        expect(w.find('[data-testid="sf-custom-theme"]').attributes('aria-pressed')).toBe('true');
        expect(w.find('[data-testid="sf-unpublished"]').exists()).toBe(true);

        await w.find('[data-testid="sf-publish"]').trigger('click');
        await flushPromises();
        const put = calls.indexOf('put admin/storefront/settings');
        const pub = calls.indexOf('post admin/storefront/publish');
        expect(put).toBeGreaterThan(-1);
        expect(pub).toBeGreaterThan(put);
        const putCall = axios.mock.calls.find(([c]) => c.url === 'admin/storefront/settings');
        expect(putCall[0].data.enabled).toBe(true);
        expect(putCall[0].data.theme.primary).toBe('#FF5A1F');
    });

    it('« Publier » est inactif quand l\'application a déjà la dernière version', async () => {
        axios.mockImplementation(() => ok(state()));
        const w = mount(StorefrontComponent);
        await flushPromises();
        expect(w.find('[data-testid="sf-publish"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="sf-nothing-to-publish"]').exists()).toBe(true);
        await w.find('[data-testid="sf-enabled"]').trigger('click'); // une modification locale suffit à le réactiver
        expect(w.find('[data-testid="sf-publish"]').attributes('disabled')).toBeUndefined();
    });
});
