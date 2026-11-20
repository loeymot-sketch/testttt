// [GOAL LIVRAISON-TICKETS 2026-11-10 · T-3.1.3] Écran « Vitrine » : thèmes programmés (« design d'un jour »).
// L'éditeur ajoute un thème au brouillon local (contrôles côté écran identiques au serveur : nom, dates, contraste), le brouillon
// devient « à enregistrer », « Enregistrer » envoie la liste au serveur (dates en heure de Paris sans fuseau), et l'aperçu
// téléphone montre le thème en cours tel que le serveur l'a calculé.
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// [SUPERVISION 2026-11-10] Les états « Programmé / En cours / Terminé » se calculent sur l'horloge réelle du poste : sans date
// figée, ce banc devenait rouge de lui-même le 24/12/2026 à 18 h. Seule `Date` est simulée (pas les minuteurs : flushPromises).
beforeEach(() => { vi.useFakeTimers({ toFake: ['Date'] }); vi.setSystemTime(new Date('2026-11-10T12:00:00+01:00')); });
afterEach(() => { vi.useRealTimers(); });

vi.mock('axios', () => ({ default: vi.fn() }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

import axios from 'axios';
import StorefrontComponent from '../../resources/js/components/admin/storefront/StorefrontComponent.vue';

const DEFAULT_THEME = {
    primary: '#FF5A1F', on_primary: '#0A0A0A', accent: '#FFD93D', background: '#FAF7F2',
    surface: '#FFFFFF', text: '#0A0A0A', banner_bg: '#0A0A0A', banner_text: '#FFD93D',
};
const NOEL = { primary: '#B91C1C', on_primary: '#FFFFFF', accent: '#FDE68A', background: '#FFF7ED', surface: '#FFFFFF', text: '#111827', banner_bg: '#14532D', banner_text: '#FFFFFF' };

function state(over = {}) {
    return {
        draft: { enabled: true, theme: null, scheduled_themes: [], delivery_on_message: null, delivery_off_message: null },
        banners: [], published: { version: 1, published_at: '2026-11-10T10:00:00+01:00', published_by: 'Responsable' },
        history: [{ version: 1, action: 'publish', source_version: null, published_at: '2026-11-10T10:00:00+01:00', published_by: 'Responsable', banners: 0, enabled: true }],
        has_unpublished_changes: false, draft_hash: 'h1',
        preview: { version: 1, enabled: true, theme: null, theme_name: null, banners: [], delivery: { enabled: true, message: 'Livraison disponible.' }, next_change_at: null },
        delivery: { enabled: true },
        meta: {
            tokens: Object.keys(DEFAULT_THEME).map((key) => ({ key, label: key })),
            contrast_pairs: [['text', 'background'], ['text', 'surface'], ['on_primary', 'primary'], ['banner_text', 'banner_bg']],
            min_contrast: 4.5, default_theme: DEFAULT_THEME, link_routes: [], max_banners: 20, max_scheduled_themes: 12,
            delivery_messages: { on: 'Livraison disponible.', off: 'Suspendue.' }, timezone: 'Europe/Paris',
        },
        ...over,
    };
}
const ok = (data) => Promise.resolve({ data: { status: true, data } });

async function monter(s = state()) {
    axios.mockImplementation(() => ok(s));
    const w = mount(StorefrontComponent);
    await flushPromises();
    return w;
}

async function remplir(w, { name = 'Noël', start = '2026-12-24T18:00', end = '2026-12-26T00:00', theme = NOEL } = {}) {
    await w.find('[data-testid="sf-new-theme"]').trigger('click');
    await w.find('[data-testid="sf-t-name"]').setValue(name);
    await w.find('[data-testid="sf-t-start"]').setValue(start);
    await w.find('[data-testid="sf-t-end"]').setValue(end);
    for (const [k, v] of Object.entries(theme)) await w.find(`[data-testid="sf-t-hex-${k}"]`).setValue(v);
    await w.find('[data-testid="sf-theme-editor"]').trigger('submit');
    await flushPromises();
}

describe('Écran Vitrine — thèmes programmés', () => {
    beforeEach(() => { axios.mockReset(); window.confirm = () => true; });

    it('ajoute un thème au brouillon, le brouillon devient à enregistrer, et « Enregistrer » envoie la liste au serveur', async () => {
        const w = await monter();
        expect(w.find('[data-testid="sf-themes-empty"]').exists()).toBe(true);
        expect(w.find('[data-testid="sf-save-settings"]').attributes('disabled')).toBeDefined();

        await remplir(w);
        const lignes = w.findAll('[data-testid="sf-theme-row"]');
        expect(lignes).toHaveLength(1);
        expect(lignes[0].text()).toContain('Noël');
        expect(lignes[0].text()).toContain('du 24/12/2026 18:00 au 26/12/2026 00:00');
        expect(lignes[0].text()).toContain('Programmé');
        expect(w.find('[data-testid="sf-unpublished"]').exists()).toBe(true);
        expect(w.find('[data-testid="sf-save-settings"]').attributes('disabled')).toBeUndefined();

        axios.mockImplementation((cfg) => ok(state({ draft: { ...state().draft, scheduled_themes: [{ id: 1, name: 'Noël', starts_at: '2026-12-24T18:00:00+01:00', ends_at: '2026-12-26T00:00:00+01:00', priority: 0, active: true, theme: NOEL, state: 'scheduled' }] }, has_unpublished_changes: true })));
        await w.find('[data-testid="sf-save-settings"]').trigger('click');
        await flushPromises();
        const envoi = axios.mock.calls.find(([cfg]) => cfg.method === 'put' && cfg.url === 'admin/storefront/settings');
        expect(envoi).toBeTruthy();
        expect(envoi[0].data.scheduled_themes).toEqual([{ id: 1, name: 'Noël', starts_at: '2026-12-24T18:00', ends_at: '2026-12-26T00:00', priority: 0, active: true, theme: NOEL }]);
        // Après la réponse du serveur (dates avec fuseau), rien n'est « à enregistrer » : même empreinte.
        expect(w.find('[data-testid="sf-save-settings"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="sf-settings-msg"]').text()).toContain('Brouillon enregistré');
    });

    it('refuse à l\'écran un thème illisible ou aux dates inversées, avec le message près du bouton', async () => {
        const w = await monter();
        await remplir(w, { start: '2026-12-26T00:00', end: '2026-12-24T18:00', theme: { ...NOEL, on_primary: '#FFFFFF', primary: '#FFFFFF' } });
        const erreurs = w.find('[data-testid="sf-theme-errors"]');
        expect(erreurs.exists()).toBe(true);
        expect(erreurs.attributes('role')).toBe('alert');
        expect(erreurs.text()).toContain('La fin doit être après le début.');
        expect(erreurs.text()).toContain('pas assez contrastées');
        expect(w.findAll('[data-testid="sf-theme-row"]')).toHaveLength(0);
        expect(w.find('[data-testid="sf-theme-editor"]').exists()).toBe(true);
    });

    it('relit les thèmes du serveur, montre leur état, et l\'aperçu annonce le thème en cours', async () => {
        const s = state({
            draft: { ...state().draft, scheduled_themes: [
                { id: 1, name: 'Noël', starts_at: '2026-12-24T18:00:00+01:00', ends_at: '2026-12-26T00:00:00+01:00', priority: 5, active: true, theme: NOEL, state: 'live' },
                { id: 2, name: 'Vieux', starts_at: '2025-12-24T18:00:00+01:00', ends_at: '2025-12-26T00:00:00+01:00', priority: 0, active: false, theme: NOEL, state: 'off' },
            ] },
            preview: { ...state().preview, theme: NOEL, theme_name: 'Noël' },
        });
        const w = await monter(s);
        const lignes = w.findAll('[data-testid="sf-theme-row"]');
        expect(lignes).toHaveLength(2);
        expect(lignes[0].text()).toContain('priorité 5');
        expect(lignes[1].text()).toContain('Désactivé');
        expect(w.find('[data-testid="sf-phone-theme"]').text()).toContain('Thème « Noël » en cours');
        expect(w.find('[data-testid="sf-phone"]').attributes('style')).toContain('--p: #B91C1C');
        expect(w.find('[data-testid="sf-save-settings"]').attributes('disabled')).toBeDefined();

        // Retirer un thème : brouillon à enregistrer.
        await lignes[1].find('button.sf-btn--ghost').trigger('click');
        await flushPromises();
        expect(w.findAll('[data-testid="sf-theme-row"]')).toHaveLength(1);
        expect(w.find('[data-testid="sf-save-settings"]').attributes('disabled')).toBeUndefined();
    });
});
