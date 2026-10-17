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
import { addDaysIso, todayIso } from '../../resources/js/helpers/hygieneCommon';

const tile = (key, status, headline, detail = '') => ({ key, label: key, icon: 'fa-solid fa-x', route: `/admin/hygiene/${key}`, status, headline, detail, counts: {} });
const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' };

async function mountHome(tiles, meta) {
    h.axios.get.mockReset();
    h.axios.get.mockResolvedValue({ data: { data: tiles, meta: { server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris', ...meta } } });
    const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLinkStub } } });
    await flushPromises();
    return w;
}

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

    it('la bannière dit l’essentiel : alertes d’abord, puis le reste à faire, puis « tout est en ordre »', async () => {
        let w = await mountHome([tile('temperature', 'alert', 'x')], { alerts: 2, todo: 3 });
        expect(w.get('[data-testid="home-banner"]').text()).toBe('2 alertes à traiter · 3 choses à faire');
        expect(w.get('[data-testid="home-banner"]').classes()).toContain('is-alert');
        w = await mountHome([tile('tasks', 'todo', 'x')], { alerts: 0, todo: 1 });
        expect(w.get('[data-testid="home-banner"]').text()).toBe('1 chose à faire aujourd\'hui');
        w = await mountHome([tile('tasks', 'ok', 'x')], { alerts: 0, todo: 0 });
        expect(w.get('[data-testid="home-banner"]').text()).toContain('Tout est en ordre');
        expect(w.get('[data-testid="home-banner"]').classes()).toContain('is-ok');
        w = await mountHome([tile('tasks', 'empty', 'x'), tile('oil', 'empty', 'x')], { alerts: 0, todo: 0 });
        expect(w.get('[data-testid="home-banner"]').text()).toContain('configurer');
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
            return { data: { data: SUMMARY, meta: {} } };
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
        expect(cfg.params.from).toBe(`${todayIso().slice(0, 8)}01`);
        expect(cfg.params.to).toBe(todayIso());
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
        const t = todayIso();
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
        const { from, to } = gets[0][1].params;
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
        h.axios.get.mockResolvedValue({ data: { data: { ...SUMMARY, incidents: [], tasks: { ...SUMMARY.tasks, missed: 0, missed_list: [] } }, meta: {} } });
        const w = mount(HygieneReportComponent);
        await flushPromises();
        expect(w.get('[data-testid="rep-no-incident"]').text()).toContain('Aucun incident');
        expect(w.find('[data-testid="rep-missed"]').exists()).toBe(false);
    });
});
