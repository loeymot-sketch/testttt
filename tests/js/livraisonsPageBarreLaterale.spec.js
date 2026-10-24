import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';

// [LIVRAISONS-PAGE 2026-10-23] Accès par la barre latérale : la page « Livraisons » (planche des départs + bascule nos livreurs ⇄ Uber)
// et l'écran livreur. Ce que ces tests verrouillent : la planche en PAGE n'est pas une fenêtre (pas de dialogue, pas de « Fermer »),
// la planche en fenêtre reste telle quelle, la route existe avec la porte de l'API, les deux libellés existent dans les 3 langues.
const board = { status: true, enabled: true, uber_enabled: true, couriers: [], data: [] };
vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: board })), post: vi.fn() } }));

import PosDeliveryBoard from '../../resources/js/components/admin/pos/PosDeliveryBoard.vue';
import CaisseSecondaryNav from '../../resources/js/components/admin/pos/CaisseSecondaryNav.vue';
import posRoutes from '../../resources/js/router/modules/posRoutes.js';

const RouterLink = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' };

describe('planche des tournées : page ou fenêtre', () => {
    it('en fenêtre (caisse) : dialogue modal avec « Fermer » — inchangé', async () => {
        const w = mount(PosDeliveryBoard);
        await flushPromises();
        const root = w.get('[data-testid="pos-delivery-board"]');
        expect(root.attributes('role')).toBe('dialog');
        expect(root.attributes('aria-modal')).toBe('true');
        expect(root.classes()).toContain('fixed');
        const fermer = w.findAll('button').find((b) => b.text() === 'Fermer');
        expect(fermer).toBeTruthy();
        await fermer.trigger('click');
        expect(w.emitted('close')).toHaveLength(1);
        w.unmount();
    });

    it('en page (barre latérale) : ni dialogue, ni voile plein écran, ni « Fermer » ; la planche reste servie', async () => {
        const w = mount(PosDeliveryBoard, { props: { page: true } });
        await flushPromises();
        const root = w.get('[data-testid="pos-delivery-board"]');
        expect(root.attributes('role')).toBeUndefined();
        expect(root.attributes('aria-modal')).toBeUndefined();
        expect(root.classes()).not.toContain('fixed');
        expect(w.findAll('button').some((b) => b.text() === 'Fermer')).toBe(false);
        expect(w.text()).toContain('Tournées de livraison');
        // cliquer à côté d'une carte ne « ferme » rien : aucun événement close.
        await root.trigger('click');
        expect(w.emitted('close')).toBeUndefined();
        w.unmount();
    });
});

describe('navigation caisse : accès Livraisons et écran livreur', () => {
    const nav = (current = '') => mount(CaisseSecondaryNav, {
        props: { current },
        global: { stubs: { RouterLink }, mocks: { $t: (k) => k } },
    });

    it('« Livraisons » mène à la route admin.livraisons et s\'allume sur sa page', () => {
        const w = nav('livraisons');
        const lien = w.get('[data-testid="csn-livraisons"]');
        expect(JSON.parse(lien.attributes('data-to'))).toEqual({ name: 'admin.livraisons' });
        expect(w.text()).toContain('menu.livraisons');
    });

    it('« Écran livreur » est une ancre /livreur en nouvel onglet (page autonome hors du routeur)', () => {
        const a = nav().get('a[data-testid="csn-livreur"]');
        expect(a.attributes('href')).toBe('/livreur');
        expect(a.attributes('target')).toBe('_blank');
        expect(a.attributes('rel')).toBe('noopener');
    });
});

describe('route et libellés', () => {
    it('/admin/livraisons existe, porte la permission de l\'API pos-delivery (`pos`)', () => {
        const r = posRoutes.find((x) => x.name === 'admin.livraisons');
        expect(r, 'route admin.livraisons absente').toBeTruthy();
        expect(r.path).toBe('/admin/livraisons');
        expect(r.meta.auth).toBe(true);
        expect(r.meta.permissionUrl).toBe('pos');
        // Même porte que le serveur : un lien affiché dont la page répondrait 403 est un piège.
        const api = fs.readFileSync(path.resolve(__dirname, '../../routes/api.php'), 'utf8');
        expect(api).toMatch(/Route::prefix\('pos-delivery'\)[^\n]*middleware\('permission:pos'\)/);
    });

    it.each(['fr', 'en', 'ar'])('les deux libellés de menu existent en %s', (lang) => {
        const d = JSON.parse(fs.readFileSync(path.resolve(__dirname, `../../resources/js/languages/${lang}.json`), 'utf8'));
        expect(typeof d.menu.livraisons).toBe('string');
        expect(d.menu.livraisons.length).toBeGreaterThan(2);
        expect(typeof d.menu.livreur_screen).toBe('string');
        expect(d.menu.livreur_screen.length).toBeGreaterThan(2);
    });
});

// [LIVRAISONS-PAGE 2026-10-23] Accès rapides du tableau de bord : mêmes deux entrées, même porte (`pos`).
describe('tableau de bord : accès rapides Livraisons et Écran livreur', () => {
    const fs2 = fs;
    it('les deux accès sont déclarés derrière la permission `pos`, l\'écran livreur en nouvel onglet', () => {
        const src = fs2.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/dashboard/DashboardComponent.vue'), 'utf8');
        expect(src).toContain("push('/admin/livraisons', this.$t('menu.livraisons'), 'fa-solid fa-motorcycle', 'pos', false);");
        expect(src).toContain("push('/livreur', this.$t('menu.livreur_screen'), 'fa-solid fa-mobile-screen-button', 'pos', true, true);");
        expect(src).toContain(":target=\"link.newTab ? '_blank' : null\"");
    });
});
