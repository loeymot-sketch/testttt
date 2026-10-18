/**
 * [CAYENNE HYGIÈNE 2026-10-18 · owner : « une seule colonne dans la barre latérale »] Le hub : UNE entrée allumée sur tous les écrans du
 * module, un accueil qui rend les outils sans compteur, et un fil d'Ariane qui remonte toujours au hub.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import BackendMenuComponent from '../../resources/js/components/layouts/backend/BackendMenuComponent.vue';
import HygieneHubBar from '../../resources/js/components/admin/hygiene/HygieneHubBar.vue';
import HygieneHomeComponent from '../../resources/js/components/admin/hygiene/HygieneHomeComponent.vue';
import fs from 'fs';
import path from 'path';

const RouterLink = { props: ['to'], template: '<a :href="to"><slot /></a>' };

function mountMenu(path) {
    return mount(BackendMenuComponent, {
        global: {
            stubs: { RouterLink },
            mocks: {
                $route: { path },
                $t: (k) => k,
                $store: { getters: { 'frontendSetting/lists': { theme_logo: '/l.png' }, authMenu: [], 'globalState/lists': { topSidebar: false } }, dispatch: () => Promise.resolve() },
            },
        },
    });
}

const hubLink = (w) => w.findAllComponents(RouterLink).find((c) => c.props('to') === '/admin/hygiene/home');

describe('barre latérale — une seule entrée pour tout le module', () => {
    it.each([
        '/admin/hygiene/home', '/admin/hygiene/temperature', '/admin/hygiene/oil', '/admin/hygiene/report',
        '/admin/labels/dlc', '/admin/labels/orders', '/admin/labels/printer',
    ])('%s : la ligne du hub est allumée', (path) => {
        expect(hubLink(mountMenu(path)).classes()).toContain('router-link-active');
    });

    it.each(['/admin/items', '/admin/pos-orders', '/admin/hygienexyz', '/admin/labelsx'])('%s : la ligne du hub n’est PAS allumée', (path) => {
        expect(hubLink(mountMenu(path)).classes()).not.toContain('router-link-active');
    });
});

describe('barre « retour au hub » — en tête de chaque écran du module', () => {
    it('un lien vers le hub, une cible de 44 px (classe dédiée), un nom accessible', () => {
        const w = mount(HygieneHubBar, { global: { stubs: { 'router-link': RouterLink } } });
        const link = w.get('[data-testid="hub-bar-link"]');
        expect(link.attributes('href')).toBe('/admin/hygiene/home');
        expect(link.text()).toContain('Hygiène & étiquettes');
        expect(w.get('nav').attributes('aria-label')).toBe('Retour au hub');
        expect(link.classes()).toContain('hy-hubbar-link');
    });

    // Sentinelle sur le PÉRIMÈTRE RÉEL : chaque écran déclaré dans les routes du module (et le module d'étiquettes) doit poser la barre.
    // Le fichier de routes est lu, pas une liste recopiée : un écran ajouté demain sans la barre fait rougir ce test.
    const root = path.resolve(__dirname, '../..');
    const screens = [];
    for (const f of ['hygieneRoutes.js', 'labelRoutes.js']) {
        const src = fs.readFileSync(path.join(root, 'resources/js/router/modules', f), 'utf8');
        for (const m of src.matchAll(/import\([^)]*?"\.\.\/\.\.\/(components\/[^"]+)"\)/g)) screens.push(path.join(root, 'resources/js', m[1] + '.vue'));
    }

    it('le périmètre lu dans les routes est le bon (12 écrans : accueil + 8 hygiène + 3 étiquettes)', () => {
        expect(screens.map((f) => path.basename(f, '.vue')).sort()).toEqual([
            'HygieneAlertsComponent', 'HygieneHomeComponent', 'HygieneOilComponent', 'HygieneReceptionComponent', 'HygieneReportComponent',
            'HygieneTasksComponent', 'HygieneTeamComponent', 'HygieneTemperatureComponent', 'HygieneTraceabilityComponent',
            'LabelDlcComponent', 'LabelOrdersComponent', 'LabelPrinterSettingsComponent',
        ]);
    });

    it.each(screens.map((f) => [path.basename(f, '.vue'), f]))('%s : pose la barre de retour (sauf l’accueil, qui est le hub)', (name, file) => {
        const src = fs.readFileSync(file, 'utf8');
        const tpl = src.slice(0, src.indexOf('</template>\n\n<script') > 0 ? src.indexOf('</template>\n\n<script') : src.indexOf('<script'));
        if (name === 'HygieneHomeComponent') {
            expect(tpl).not.toContain('<HygieneHubBar');
        } else {
            expect(tpl, `${name} n'a pas de barre « retour au hub »`).toContain('<HygieneHubBar />');
            expect(src, `${name} n'enregistre pas HygieneHubBar`).toMatch(/components:\s*\{[^}]*HygieneHubBar/);
        }
    });
});

describe('ouverture d’un écran du module : le conteneur de l’admin revient en haut', () => {
    it('la barre remet `.db-main` en haut (le routeur ne scrolle que la fenêtre)', () => {
        const main = document.createElement('div');
        main.className = 'db-main';
        document.body.appendChild(main);
        main.scrollTop = 640;
        expect(main.scrollTop).toBe(640);
        const w = mount(HygieneHubBar, { global: { stubs: { 'router-link': RouterLink } } });
        expect(main.scrollTop).toBe(0);
        w.unmount();
        main.remove();
    });

    it('sans conteneur (autre coquille) : aucune erreur', () => {
        expect(() => mount(HygieneHubBar, { global: { stubs: { 'router-link': RouterLink } } })).not.toThrow();
    });
});

describe('accueil — tous les outils sans compteur sont là', () => {
    it('étiquettes de commande, imprimante, alertes et codes d’équipe sont atteignables depuis l’accueil', async () => {
        h.axios.get.mockReset();
        h.axios.get.mockResolvedValue({ data: { data: [], meta: { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris', reminders: [] } } });
        const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLink } } });
        await flushPromises();
        const tools = w.get('[data-testid="home-tools"]');
        const hrefs = tools.findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toEqual(['/admin/labels/orders', '/admin/labels/printer', '/admin/hygiene/alerts', '/admin/hygiene/team']);
        expect(w.find('[data-testid="home-alerts-link"]').exists()).toBe(true);
        expect(w.find('[data-testid="home-team-link"]').exists()).toBe(true);
    });

    it('chaque ancienne sous-entrée de la barre a toujours un chemin : tuile, outil ou dossier', async () => {
        h.axios.get.mockReset();
        const keys = ['temperature', 'tasks', 'dlc', 'reception', 'trace', 'oil'];
        h.axios.get.mockResolvedValue({
            data: { data: keys.map((k) => ({ key: k, label: k, icon: 'fa-solid fa-x', route: k === 'dlc' ? '/admin/labels/dlc' : `/admin/hygiene/${k}`, status: 'ok', headline: 'x', detail: '', counts: {} })), meta: { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris' } },
        });
        const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLink } } });
        await flushPromises();
        const hrefs = new Set(w.findAll('a').map((a) => a.attributes('href')));
        for (const old of ['/admin/hygiene/temperature', '/admin/hygiene/tasks', '/admin/hygiene/reception', '/admin/hygiene/oil', '/admin/hygiene/trace', '/admin/hygiene/report', '/admin/labels/dlc', '/admin/labels/orders', '/admin/labels/printer', '/admin/hygiene/alerts', '/admin/hygiene/team']) {
            expect(hrefs.has(old), `${old} n’est plus atteignable depuis le hub`).toBe(true);
        }
    });
});
