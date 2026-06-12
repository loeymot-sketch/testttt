/**
 * [HEAL dispute-r3 B-R1-16 2026-06-12] — « Voir les clôtures Z » → Transactions.
 * -----------------------------------------------------------------------------
 * R1/R2 adversarial : le widget « Dernier Z » du dashboard liait
 * `admin.transactions.list` (0 Z affiché) — le commentaire W3.5 admettait
 * « Router target (transactions list) unchanged ». Arbitrage avec B-R1-18
 * (« aucune UI admin Z ») : la cible honnête N'EXISTAIT PAS → création d'une
 * page MINIMALE lecture-seule /admin/z-reports (GET admin/fiscal/z-report,
 * API existante POS-9.4.9, permission backend pos-manage-fiscal) et
 * re-ciblage du widget.
 *
 * Invariants verrouillés :
 *  1. Le widget pointe `admin.zReports.list` (plus jamais transactions).
 *  2. La route existe : /admin/z-reports, name admin.zReports.list,
 *     enregistrée dans le router.
 *  3. La page rend les clôtures (séquence, statut FR, dates fr-FR, total TTC,
 *     commandes) + empty state propre.
 *  4. Clefs i18n FR + parité EN.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const fr = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf-8'));
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf-8'));

const widgetSrc = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/dashboard/LastZReportWidget.vue'),
    'utf-8'
);

describe('[B-R1-16] widget « Voir les clôtures Z » — cible router', () => {
    it('ne cible PLUS admin.transactions.list', () => {
        expect(widgetSrc).not.toMatch(/admin\.transactions\.list/);
    });

    it('cible la vraie page Z (admin.zReports.list)', () => {
        expect(widgetSrc).toMatch(/admin\.zReports\.list/);
    });
});

describe('[B-R1-16] route /admin/z-reports enregistrée', () => {
    it('le module zReportRoutes expose path + name', async () => {
        const routes = (await import('../../resources/js/router/modules/zReportRoutes.js')).default;
        const route = routes.find((r) => r.name === 'admin.zReports.list');
        expect(route).toBeTruthy();
        expect(route.path).toBe('/admin/z-reports');
        expect(route.meta.auth).toBe(true);
    });

    it('le router index importe le module', () => {
        const routerSrc = readFileSync(resolve(process.cwd(), 'resources/js/router/index.js'), 'utf-8');
        expect(routerSrc).toMatch(/zReportRoutes/);
    });
});

describe('[B-R1-16] page ZReportListComponent', () => {
    async function mountPage(rows) {
        const axiosModule = (await import('axios')).default;
        const spy = vi.spyOn(axiosModule, 'get').mockResolvedValue({ data: { data: rows } });
        const { default: ZReportListComponent } = await import(
            '../../resources/js/components/admin/fiscal/ZReportListComponent.vue'
        );
        const wrapper = mount(ZReportListComponent, {
            global: {
                mocks: {
                    $t: (key, params) => key + (params ? ':' + JSON.stringify(params) : ''),
                },
                stubs: { LoadingComponent: true },
            },
        });
        await flushPromises();
        return { wrapper, spy };
    }

    it('rend les clôtures: séquence, statut FR, total TTC, commandes', async () => {
        const { wrapper, spy } = await mountPage([
            {
                id: 22,
                sequence_no: 20,
                status: 'closed',
                opened_at: '2026-06-10T07:04:52+02:00',
                closed_at: '2026-06-10T07:06:45+02:00',
                total_ttc: '42.50',
                order_count: 7,
            },
            {
                id: 21,
                sequence_no: 19,
                status: 'open',
                opened_at: '2026-06-12T08:00:00+02:00',
                closed_at: null,
                total_ttc: '0.00',
                order_count: 0,
            },
        ]);
        try {
            expect(spy).toHaveBeenCalledWith('admin/fiscal/z-report');
            const rows = wrapper.findAll('[data-testid^="z-report-row-"]');
            expect(rows.length).toBe(2);
            const first = rows[0].text();
            expect(first).toContain('20');
            expect(first).toContain('label.cash_status_closed');
            expect(first).toContain('42,50');
            expect(first).toContain('7');
            // open row → statut FR open, pas de date de clôture cassée
            const second = rows[1].text();
            expect(second).toContain('label.cash_status_open');
            expect(second).not.toContain('Invalid');
        } finally {
            spy.mockRestore();
        }
    });

    it('empty state propre quand aucune clôture', async () => {
        const { wrapper, spy } = await mountPage([]);
        try {
            expect(wrapper.find('[data-testid="z-reports-empty"]').exists()).toBe(true);
            expect(wrapper.findAll('[data-testid^="z-report-row-"]').length).toBe(0);
        } finally {
            spy.mockRestore();
        }
    });
});

describe('[B-R1-16] clefs i18n (FR + parité EN)', () => {
    const LABEL_KEYS = ['z_sequence', 'z_opened_at', 'z_closed_at', 'z_total_ttc', 'z_order_count'];

    it('menu.z_reports existe (titre page) en FR', () => {
        expect(typeof fr.menu.z_reports).toBe('string');
        expect(fr.menu.z_reports).toMatch(/Z/);
    });

    it.each(LABEL_KEYS)('fr.json label.%s existe', (key) => {
        expect(typeof fr.label[key]).toBe('string');
    });

    it.each(LABEL_KEYS)('en.json label.%s existe (parité)', (key) => {
        expect(typeof en.label[key]).toBe('string');
    });
});
