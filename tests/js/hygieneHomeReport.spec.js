/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Accueil à tuiles (reste à faire + alertes) et dossier de contrôle sanitaire (période, taux réels,
 * incidents avec action corrective, téléchargements) montés pour de vrai, serveur simulé.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import HygieneHomeComponent from '../../resources/js/components/admin/hygiene/HygieneHomeComponent.vue';
import HygieneReportComponent from '../../resources/js/components/admin/hygiene/HygieneReportComponent.vue';
import { addDaysIso } from '../../resources/js/helpers/hygieneCommon';

const tile = (key, status, headline, detail = '') => ({ key, label: key, icon: 'fa-solid fa-x', route: `/admin/hygiene/${key}`, status, headline, detail, counts: {} });
const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' };

async function mountHome(tiles, meta) {
    h.axios.get.mockReset();
    h.axios.get.mockResolvedValue({ data: { data: tiles, meta: { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris', ...meta } } });
    const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLinkStub } } });
    await flushPromises();
    return w;
}

describe('accueil — rappels et boutons d’action (2026-10-17)', () => {
    const REM = [
        { key: 'trace-silent', domain: 'trace', level: 'todo', text: 'Aucun produit tracé depuis 3 jours.', route: '/admin/hygiene/trace' },
        { key: 'temperature-late-morning', domain: 'temperature', level: 'alert', text: 'Relevé du matin non fait : Frigo 1, Frigo 2.', route: '/admin/hygiene/temperature' },
        { key: 'dlc-expired', domain: 'dlc', level: 'alert', text: '2 étiquettes DLC dépassées : à jeter, ou à marquer « utilisé ».', route: '/admin/labels/dlc' },
    ];

    it('« À traiter maintenant » : alertes d’abord, une phrase chacune, reliée à son écran', async () => {
        const w = await mountHome([tile('temperature', 'alert', '0/2 relevés')], { alerts: 1, todo: 0, reminders: REM });
        const items = w.findAll('[data-testid="home-reminder"]');
        expect(items.map((i) => i.text())).toEqual([
            expect.stringContaining('Relevé du matin non fait : Frigo 1, Frigo 2.'),
            expect.stringContaining('2 étiquettes DLC dépassées'),
            expect.stringContaining('Aucun produit tracé depuis 3 jours.'),
        ]);
        expect(items[0].classes()).toContain('is-alert');
        expect(items[2].classes()).toContain('is-todo');
        expect(items[0].get('a').attributes('href')).toBe('/admin/hygiene/temperature');
        expect(items[1].get('a').attributes('href')).toBe('/admin/labels/dlc');
    });

    it('aucun rappel : la section n’existe pas (pas de « 0 chose à traiter »)', async () => {
        const w = await mountHome([tile('tasks', 'ok', '5/5 fiches')], { alerts: 0, todo: 0, reminders: [] });
        expect(w.find('[data-testid="home-reminders"]').exists()).toBe(false);
        const w2 = await mountHome([tile('tasks', 'ok', '5/5 fiches')], { alerts: 0, todo: 0 });
        expect(w2.find('[data-testid="home-reminders"]').exists()).toBe(false);
    });

    it('chaque tuile dit le geste à faire (« Réaliser un relevé »), pas « Ouvrir »', async () => {
        const w = await mountHome([{ ...tile('temperature', 'todo', '1/6 relevés'), cta: 'Réaliser un relevé' }, { ...tile('oil', 'empty', 'À configurer'), cta: 'Configurer' }], { alerts: 0, todo: 1 });
        expect(w.get('[data-testid="home-cta-temperature"]').text()).toBe('Réaliser un relevé →');
        expect(w.get('[data-testid="home-cta-oil"]').text()).toBe('Configurer →');
        expect(w.text()).not.toMatch(/Ouvrir/);
    });
});

describe('accueil du module', () => {
    it('montre une tuile par domaine avec son compteur, plus le dossier de contrôle', async () => {
        const w = await mountHome([tile('temperature', 'alert', '4/6 relevés', '1 écart'), tile('tasks', 'todo', '3/5 fiches', '2 à faire'), tile('oil', 'empty', 'À configurer')], { alerts: 1, todo: 1 });
        expect(w.get('[data-testid="home-headline-temperature"]').text()).toBe('4/6 relevés');
        expect(w.get('[data-testid="home-detail-temperature"]').text()).toBe('1 écart');
        expect(w.get('[data-testid="home-tile-temperature"]').classes()).toContain('is-alert');
        expect(w.get('[data-testid="home-tile-tasks"]').classes()).toContain('is-todo');
        expect(w.get('[data-testid="home-tile-oil"]').classes()).toContain('is-empty');
        expect(w.get('[data-testid="home-tile-temperature"]').attributes('href')).toBe('/admin/hygiene/temperature');
        expect(w.find('[data-testid="home-tile-report"]').exists()).toBe(true);
    });

    it('une tuile « idle » (rien attendu, rien eu lieu) reste neutre et ne fait pas dire « à configurer »', async () => {
        const w = await mountHome([tile('tasks', 'ok', '5/5 fiches'), tile('reception', 'idle', 'Aucune livraison'), tile('trace', 'idle', 'Rien tracé')], { alerts: 0, todo: 0 });
        expect(w.get('[data-testid="home-tile-trace"]').classes()).toContain('is-idle');
        expect(w.get('[data-testid="home-tile-trace"]').classes()).not.toContain('is-ok');
        expect(w.get('[data-testid="home-banner"]').text()).toContain('Tout est en ordre');
    });

    it('la bannière dit l’essentiel : alertes d’abord, puis le reste à faire, puis « tout est en ordre »', async () => {
        let w = await mountHome([tile('temperature', 'alert', 'x')], { alerts: 2, todo: 3 });
        expect(w.get('[data-testid="home-banner"]').text()).toBe('2 domaines en alerte à traiter · 3 autres à compléter');
        expect(w.get('[data-testid="home-banner"]').classes()).toContain('is-alert');
        w = await mountHome([tile('tasks', 'todo', 'x')], { alerts: 0, todo: 1 });
        expect(w.get('[data-testid="home-banner"]').text()).toBe('1 domaine à compléter aujourd\'hui');
        w = await mountHome([tile('tasks', 'ok', 'x')], { alerts: 0, todo: 0 });
        expect(w.get('[data-testid="home-banner"]').text()).toContain('Tout est en ordre');
        expect(w.get('[data-testid="home-banner"]').classes()).toContain('is-ok');
        w = await mountHome([tile('tasks', 'empty', 'x'), tile('oil', 'empty', 'x')], { alerts: 0, todo: 0 });
        expect(w.get('[data-testid="home-banner"]').text()).toContain('configurer');
        // un domaine non configuré à côté de domaines sans alerte : JAMAIS « tout est en ordre » (cas vu en e2e le 2026-10-17)
        w = await mountHome([tile('temperature', 'empty', 'À configurer'), tile('oil', 'empty', 'À configurer'), tile('trace', 'ok', 'Rien tracé')], { alerts: 0, todo: 0 });
        const banner = w.get('[data-testid="home-banner"]');
        expect(banner.text()).not.toContain('Tout est en ordre');
        expect(banner.text()).toBe('Rien d\'urgent, mais à configurer : temperature, oil.');
        expect(banner.classes()).toContain('is-todo');
        // une alerte l'emporte toujours, même avec un domaine à configurer
        w = await mountHome([tile('temperature', 'alert', 'x'), tile('oil', 'empty', 'x')], { alerts: 1, todo: 0 });
        expect(w.get('[data-testid="home-banner"]').classes()).toContain('is-alert');
    });

    it('serveur injoignable au premier chargement : message clair', async () => {
        h.axios.get.mockReset();
        h.axios.get.mockRejectedValue(new Error('net'));
        const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLinkStub } } });
        await flushPromises();
        expect(w.get('[data-testid="home-error"]').text()).toContain('indisponible');
    });
});

describe('dossier de contrôle', () => {
    const SUMMARY = {
        period: { from: '2026-10-01', to: '2026-10-20', days: 20 },
        temperature: { count: 100, alerts: 2, expected: 120, covered: 108, coverage_pct: 90 },
        tasks: { count: 80, expected: 100, done: 80, partial: 5, missed: 15, completion_pct: 80, missed_list: [{ date: '2026-10-18', task: 'Salle' }] },
        receptions: { count: 6, alerts: 1, refused: 1 }, trace: { count: 9, alerts: 0, without_lot: 1 }, oil: { tests: 18, alerts: 1, coverage_pct: 100, max_polar: 22 },
        dlc: { count: 40, discarded: 12, used: 20, expired_not_out: 2 },
        incidents: [{ at: '2026-10-19T18:00:00+02:00', module: 'temperature', text: 'Frigo 2 : 7,5 °C (trop chaud)', action: 'Produits jetés', operator: 'Karim' }],
    };
    const SERVER = { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris' };
    let gets;
    let clicks;
    let createdUrl;

    beforeEach(() => {
        gets = [];
        clicks = [];
        h.axios.get.mockReset();
        h.axios.get.mockImplementation(async (url, cfg) => {
            gets.push([url, cfg]);
            if (cfg && cfg.responseType === 'blob') return { data: new Blob(['x']) };
            return { data: { data: SUMMARY, meta: SERVER } };
        });
        createdUrl = vi.fn(() => 'blob:x');
        globalThis.URL.createObjectURL = createdUrl;
        globalThis.URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () { clicks.push(this.download); });
    });
    afterEach(() => vi.restoreAllMocks());

    it('charge le mois en cours par défaut, avec les taux et les incidents', async () => {
        const w = mount(HygieneReportComponent);
        await flushPromises();
        const [url, cfg] = gets[0];
        expect(url).toBe('admin/hygiene/report/summary');
        expect(cfg.params).toEqual({}); // 1er chargement SANS période : le serveur choisit le mois en cours (horloge serveur, pas celle de la tablette)
        expect(w.get('[data-testid="rep-from"]').element.value).toBe('2026-10-01');
        expect(w.get('[data-testid="rep-to"]').element.value).toBe('2026-10-20');
        expect(w.get('[data-testid="rep-kpi-temperature"]').text()).toContain('90 %');
        expect(w.get('[data-testid="rep-kpi-temperature"]').text()).toContain('108 sur 120');
        expect(w.get('[data-testid="rep-kpi-temperature"]').classes()).toContain('is-partial');
        expect(w.get('[data-testid="rep-kpi-tasks"]').classes()).toContain('is-alert');
        expect(w.get('[data-testid="rep-kpi-oil"]').classes()).toContain('is-done');
        expect(w.get('[data-testid="rep-kpi-dlc"]').classes()).toContain('is-alert');
        expect(w.get('[data-testid="rep-incidents"]').text()).toContain('Produits jetés');
        expect(w.get('[data-testid="rep-missed"]').text()).toContain('15 fiche(s)');
    });

    it('les préréglages calculent la bonne période', async () => {
        const w = mount(HygieneReportComponent);
        await flushPromises();
        const t = '2026-10-20'; // « aujourd'hui » = date du serveur
        await w.get('[data-testid="rep-preset-week"]').trigger('click');
        await flushPromises();
        expect(gets.at(-1)[1].params).toEqual({ from: addDaysIso(t, -6), to: t });
        await w.get('[data-testid="rep-preset-today"]').trigger('click');
        await flushPromises();
        expect(gets.at(-1)[1].params).toEqual({ from: t, to: t });
        await w.get('[data-testid="rep-preset-lastmonth"]').trigger('click');
        await flushPromises();
        const { from, to } = gets.at(-1)[1].params;
        expect(from.endsWith('-01')).toBe(true);
        expect(addDaysIso(to, 1).endsWith('-01')).toBe(true); // finit le dernier jour du mois
        expect(from.slice(0, 7)).toBe(to.slice(0, 7));
        expect(to < `${t.slice(0, 8)}01`).toBe(true);
    });

    it('une fin avant le début est refusée sans appeler le serveur', async () => {
        const w = mount(HygieneReportComponent);
        await flushPromises();
        const n = gets.length;
        await w.get('[data-testid="rep-to"]').setValue('2020-01-01');
        await flushPromises();
        expect(w.get('[data-testid="rep-error"]').text()).toContain('précède');
        expect(gets.length).toBe(n);
    });

    it('le PDF et chaque CSV se téléchargent avec la période et un nom de fichier parlant', async () => {
        const w = mount(HygieneReportComponent);
        await flushPromises();
        const from = '2026-10-01';
        const to = '2026-10-20';
        expect(w.get('[data-testid="rep-pdf"]').attributes('disabled')).toBeUndefined(); // une chaîne vide ne désactive pas le bouton
        await w.get('[data-testid="rep-pdf"]').trigger('click');
        await flushPromises();
        const pdfCall = gets.find(([u, c]) => u === 'admin/hygiene/report/pdf' && c.responseType === 'blob');
        expect(pdfCall[1].params).toEqual({ from, to });
        expect(clicks).toContain(`dossier-hygiene-${from}-${to}.pdf`);
        await w.get('[data-testid="rep-csv-oil"]').trigger('click');
        await flushPromises();
        const csvCall = gets.find(([u]) => u === 'admin/hygiene/report/csv');
        expect(csvCall[1].params).toEqual({ module: 'oil', from, to });
        expect(clicks).toContain(`hygiene-oil-${from}-${to}.csv`);
    });

    it('aucun incident : message clair', async () => {
        h.axios.get.mockResolvedValue({ data: { data: { ...SUMMARY, incidents: [], tasks: { ...SUMMARY.tasks, missed: 0, missed_list: [] } }, meta: SERVER } });
        const w = mount(HygieneReportComponent);
        await flushPromises();
        expect(w.get('[data-testid="rep-no-incident"]').text()).toContain('Aucun incident');
        expect(w.find('[data-testid="rep-missed"]').exists()).toBe(false);
    });
});


describe('dossier de contrôle — réponses dans le désordre', () => {
    it('une réponse lente d’une ancienne période n’écrase pas la dernière demandée', async () => {
        const mk = (count) => ({ period: { from: '2026-10-01', to: '2026-10-20', days: 20 }, temperature: { count: 1, alerts: 0, expected: 1, covered: 1, coverage_pct: 100 },
            tasks: { count: 0, expected: 0, done: 0, partial: 0, missed: 0, completion_pct: null, missed_list: [] }, receptions: { count, alerts: 0, refused: 0 },
            trace: { count: 0, alerts: 0, without_lot: 0 }, oil: { tests: 0, alerts: 0, coverage_pct: null, max_polar: null }, dlc: { count: 0, discarded: 0, used: 0, expired_not_out: 0 }, incidents: [] });
        let release;
        h.axios.get.mockReset();
        h.axios.get.mockImplementation((url, cfg) => {
            if (!cfg.params.from) return Promise.resolve({ data: { data: mk(1), meta: { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris' } } });
            if (cfg.params.from === cfg.params.to) return Promise.resolve({ data: { data: mk(2), meta: {} } });
            return new Promise((resolve) => { release = () => resolve({ data: { data: mk(99), meta: {} } }); });
        });
        const w = mount(HygieneReportComponent);
        await flushPromises();
        await w.get('[data-testid="rep-preset-week"]').trigger('click'); // lente, restera en attente
        await w.get('[data-testid="rep-preset-today"]').trigger('click'); // rapide
        await flushPromises();
        release(); // la réponse lente de « 7 jours » arrive APRÈS
        await flushPromises();
        expect(w.get('[data-testid="rep-kpi-receptions"]').text()).toContain('2'); // « aujourd'hui », pas 99
        expect(w.get('[data-testid="rep-preset-today"]').classes()).toContain('is-on');
        // aucune mesure de polaires : jamais « max — de polaires » (vu en e2e le 2026-10-17)
        expect(w.get('[data-testid="rep-kpi-oil"]').text()).toContain('aucune mesure de polaires');
        expect(w.get('[data-testid="rep-kpi-oil"]').text()).not.toMatch(/max\s*—/);
    });
});
