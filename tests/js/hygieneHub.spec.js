/**
 * [CAYENNE HYGIÈNE 2026-10-18 · owner : « une seule colonne dans la barre latérale »] Le hub : UNE entrée allumée sur tous les écrans du
 * module, un accueil qui rend les outils sans compteur, et un fil d'Ariane qui remonte toujours au hub.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';

// Chaque test démonte ses composants : un accueil resté monté garde ses écouteurs (réveil, réseau) et répondrait aux événements du test suivant.
enableAutoUnmount(afterEach);

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

    it.each(['/admin/hygiene/oil', '/admin/labels/printer'])('%s : la ligne allumée est aussi EXPOSÉE comme page courante (aria-current), pas seulement colorée', (path) => {
        expect(hubLink(mountMenu(path)).attributes('aria-current')).toBe('true');
    });

    it('les majuscules ne cassent pas l’allumage (le routeur ne les distingue pas)', () => {
        expect(hubLink(mountMenu('/admin/LABELS/printer')).classes()).toContain('router-link-active');
        expect(hubLink(mountMenu('/admin/Hygiene/Oil')).classes()).toContain('router-link-active');
    });

    it('hors module : pas d’aria-current', () => {
        expect(hubLink(mountMenu('/admin/items')).attributes('aria-current')).toBeUndefined();
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

    it('la barre porte SA feuille de style (les écrans d’étiquettes n’importent pas hygiene.css : sans cela, lien nu après un F5)', () => {
        const src = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/hygiene/HygieneHubBar.vue'), 'utf8');
        expect(src).toContain('<style src="./hygiene.css"></style>');
        const css = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/hygiene/hygiene.css'), 'utf8');
        expect(css).toMatch(/\.hy-hubbar-link\s*\{[^}]*min-height:\s*44px/);
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

    describe('droits : jamais une carte vers un écran que le serveur refusera', () => {
        const perms = (names) => names.map((n) => ({ name: n, url: n, access: true }));
        async function mountWith(store) {
            h.axios.get.mockReset();
            h.axios.get.mockResolvedValue({ data: { data: [], meta: { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris', reminders: [] } } });
            const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLink }, mocks: store ? { $store: store } : {} } });
            await flushPromises();
            return w;
        }
        const hrefs = (w) => w.get('[data-testid="home-tools"]').findAll('a').map((a) => a.attributes('href'));

        it('responsable (droit « settings ») : les 4 outils', async () => {
            const w = await mountWith({ getters: { authPermission: perms(['pos-orders', 'settings']) } });
            expect(hrefs(w)).toEqual(['/admin/labels/orders', '/admin/labels/printer', '/admin/hygiene/alerts', '/admin/hygiene/team']);
        });

        it('caissier (sans « settings ») : alertes et codes d’équipe ne sont PAS proposés', async () => {
            const w = await mountWith({ getters: { authPermission: perms(['pos-orders', 'pos']) } });
            expect(hrefs(w)).toEqual(['/admin/labels/orders', '/admin/labels/printer']);
            expect(w.find('[data-testid="home-tools-settings"]').exists(), 'groupe vide affiché').toBe(false);
        });

        it('droits pas encore chargés (démarrage à froid) : on propose tout, comme la barre latérale', async () => {
            const w = await mountWith({ getters: { authPermission: {} } });
            expect(hrefs(w)).toHaveLength(4);
        });
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

describe('accueil — actualisation économe et honnête (tablette allumée toute la journée)', () => {
    const META = { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris', reminders: [] };
    const setVisibility = (state) => {
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
        document.dispatchEvent(new Event('visibilitychange'));
    };
    const calls = () => h.axios.get.mock.calls.filter((c) => c[0] === 'admin/hygiene/overview').length;
    async function open() {
        h.axios.get.mockReset();
        h.axios.get.mockResolvedValue({ data: { data: [], meta: META } });
        const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLink } } });
        await flushPromises();
        return w;
    }

    it('écran masqué : le tour de 60 s n’appelle PAS le serveur ; écran visible : il l’appelle', async () => {
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
        try {
            const w = await open();
            expect(calls()).toBe(1);
            setVisibility('hidden');
            await vi.advanceTimersByTimeAsync(180000);
            expect(calls(), 'écran masqué : aucun appel').toBe(1);
            Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
            await vi.advanceTimersByTimeAsync(60000);
            expect(calls()).toBe(2);
            w.unmount();
        } finally { vi.useRealTimers(); Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' }); }
    });

    it('au réveil : rafraîchit tout de suite si les chiffres ont plus de 15 s, pas s’ils sont frais', async () => {
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
        try {
            const w = await open();
            setVisibility('hidden');
            await vi.advanceTimersByTimeAsync(5000);
            setVisibility('visible');
            await flushPromises();
            expect(calls(), 'chiffres de 5 s : pas de rafraîchissement').toBe(1);
            setVisibility('hidden');
            await vi.advanceTimersByTimeAsync(20000);
            setVisibility('visible');
            await flushPromises();
            expect(calls(), 'chiffres de 25 s : rafraîchis au réveil').toBe(2);
            w.unmount();
        } finally { vi.useRealTimers(); Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' }); }
    });

    it('retour du réseau après un échec : rafraîchit tout de suite', async () => {
        h.axios.get.mockReset();
        h.axios.get.mockRejectedValueOnce(new Error('Network Error')).mockResolvedValue({ data: { data: [], meta: META } });
        const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLink } } });
        await flushPromises();
        expect(calls()).toBe(1);
        window.dispatchEvent(new Event('online'));
        await flushPromises();
        expect(calls()).toBe(2);
        w.unmount();
    });

    it('jamais deux appels en même temps (réponse lente + réveil)', async () => {
        h.axios.get.mockReset();
        let release;
        h.axios.get.mockImplementation(() => new Promise((res) => { release = () => res({ data: { data: [], meta: META } }); }));
        const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLink } } });
        await flushPromises();
        expect(calls()).toBe(1);
        await w.vm.load();
        await w.vm.load();
        expect(calls(), 'un appel est déjà en vol').toBe(1);
        release();
        await flushPromises();
        w.unmount();
    });

    it('quitter le hub retire les écouteurs (plus d’appel après démontage)', async () => {
        const w = await open();
        w.unmount();
        const before = calls();
        window.dispatchEvent(new Event('online'));
        document.dispatchEvent(new Event('visibilitychange'));
        await flushPromises();
        expect(calls()).toBe(before);
    });
});

describe('accueil — jamais « tout est en ordre » sur une réponse qui n’est pas celle du serveur', () => {
    const GOOD = { data: [{ key: 'tasks', label: 'tasks', icon: 'fa-solid fa-x', route: '/admin/hygiene/tasks', status: 'ok', headline: '5/5 fiches', detail: '', counts: {} }], meta: { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris', alerts: 0, todo: 0, reminders: [] } };
    const mountWith = async (resp) => {
        h.axios.get.mockReset();
        h.axios.get.mockResolvedValue({ data: resp });
        const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLink } } });
        await flushPromises();
        return w;
    };

    it.each([
        ['objet vide {}', {}],
        ['data vide sans meta', { data: [] }],
        ['meta sans horloge', { data: [], meta: {} }],
        ['page HTML (portail captif, proxy, connexion)', '<html>Please login</html>'],
        ['null', null],
        ['data qui n’est pas une liste', { data: { x: 1 }, meta: { server_now: '2026-10-20T10:00:00+02:00' } }],
    ])('premier chargement avec %s : message d’erreur honnête, aucune bannière verte', async (_label, resp) => {
        const w = await mountWith(resp);
        expect(w.find('[data-testid="home-banner"]').exists()).toBe(false);
        expect(w.text()).not.toContain('Tout est en ordre');
        expect(w.get('[data-testid="home-error"]').text()).toContain('Accueil indisponible');
    });

    it('un bon chargement puis une réponse vide au rafraîchissement : on garde les tuiles et on dit que les chiffres datent', async () => {
        vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
        try {
            const w = await mountWith(GOOD);
            expect(w.get('[data-testid="home-banner"]').text()).toContain('Tout est en ordre');
            h.axios.get.mockResolvedValue({ data: {} });
            await vi.advanceTimersByTimeAsync(60000);
            expect(w.get('[data-testid="home-stale"]').text()).toMatch(/Réseau indisponible/);
            expect(w.find('[data-testid="home-banner"]').exists()).toBe(false);
            expect(w.find('[data-testid="home-tile-tasks"]').exists(), 'les dernières tuiles restent affichées').toBe(true);
        } finally { vi.useRealTimers(); }
    });
});

describe('accueil — l’urgent d’abord, nom du module, appel du dossier', () => {
    const META = { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris', alerts: 1, todo: 0, setup: { steps: [{ key: 'equipment', label: 'Créer vos frigos', done: false, optional: false, route: '/admin/hygiene/temperature', detail: 'x', cta: 'Créer' }], required_total: 1, required_done: 0, next: { key: 'equipment', label: 'Créer vos frigos', detail: 'x', cta: 'Créer', route: '/admin/hygiene/temperature' }, complete: false }, reminders: [{ key: 'temperature-late-morning', domain: 'temperature', level: 'alert', text: 'Relevé du matin non fait : Frigo 1.', route: '/admin/hygiene/temperature' }] };

    async function home() {
        h.axios.get.mockReset();
        h.axios.get.mockResolvedValue({ data: { data: [], meta: META } });
        const w = mount(HygieneHomeComponent, { attachTo: document.body, global: { stubs: { 'router-link': RouterLink } } });
        await flushPromises();
        return w;
    }

    it('« À traiter maintenant » vient AVANT le guide de mise en place (un relevé en retard passe avant une étape de configuration)', async () => {
        const w = await home();
        const rem = w.get('[data-testid="home-reminders"]').element;
        const setup = w.get('[data-testid="home-setup"]').element;
        expect(rem.compareDocumentPosition(setup) & Node.DOCUMENT_POSITION_FOLLOWING, 'le guide précède les rappels').toBeTruthy();
    });

    it('le titre porte le nom du module, comme la barre latérale et le bouton de retour', async () => {
        const w = await home();
        expect(w.get('.hy-title').text()).toMatch(/^Hygiène & étiquettes — /);
        expect(w.text()).not.toContain('Cayenne Hygiène —');
    });

    it('la tuile « Dossier de contrôle » dit le geste, comme les autres', async () => {
        const w = await home();
        expect(w.get('[data-testid="home-cta-report"]').text()).toBe('Voir le dossier →');
    });
});

describe('barre latérale — le tiroir se referme après un appui sur un lien (téléphone)', () => {
    const withMedia = (matches) => { window.matchMedia = vi.fn().mockReturnValue({ matches, addEventListener() {}, removeEventListener() {} }); };
    function menu() {
        document.body.innerHTML = '<div class="db-main expand"></div>';
        return mount(BackendMenuComponent, {
            attachTo: document.body,
            global: { stubs: { RouterLink }, mocks: { $route: { path: '/admin/hygiene/temperature' }, $t: (k) => k, $store: { getters: { 'frontendSetting/lists': { theme_logo: '/l.png' }, authMenu: [], 'globalState/lists': { topSidebar: false } }, dispatch: () => Promise.resolve() } } },
        });
    }
    const hubItem = (w) => w.findAll('li.db-sidebar-nav-item').find((li) => li.find('a[href="/admin/hygiene/home"]').exists());

    it('< lg, tiroir ouvert : un appui sur le hub le referme (et libère la page)', async () => {
        withMedia(true);
        const w = menu();
        w.element.classList.add('active');
        await hubItem(w).trigger('click');
        expect(w.element.classList.contains('active')).toBe(false);
        expect(document.querySelector('.db-main').classList.contains('expand')).toBe(false);
        w.unmount();
    });

    it('>= lg : « active » veut dire REPLIÉ, on n’y touche pas', async () => {
        withMedia(false);
        const w = menu();
        w.element.classList.add('active');
        await hubItem(w).trigger('click');
        expect(w.element.classList.contains('active')).toBe(true);
        w.unmount();
    });

    it('< lg, tiroir déjà fermé : un appui ne le rouvre pas', async () => {
        withMedia(true);
        const w = menu();
        await hubItem(w).trigger('click');
        expect(w.element.classList.contains('active')).toBe(false);
        w.unmount();
    });
});
