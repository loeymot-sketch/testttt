import { describe, it, expect, vi, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [F2 2026-07-24] Ticket cuisine 100% symbolique SANS légende à l'écran.
 * Source: reports/goal-global-validation-2026-07-24/ACCES-cuisine-mobile-findings.md
 *
 * Un cuisinier neuf ne décode pas G|SANDWICH|P|SAM|O̲ sans mémoriser les tables. Le heal :
 * une LÉGENDE repliable (toggle « Afficher les noms »), persistée localStorage comme les
 * autres prefs KDS — SANS retirer les codes (design owner). La clé est le miroir des
 * tables owner de resources/js/helpers/kdsSymbolic.js.
 */

import KDS from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';

const SRC = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
    'utf8',
);

describe('F2 — KDS symbol legend (onboarding)', () => {
    afterEach(() => { vi.restoreAllMocks(); });

    it('symbolLegend maps the owner symbol tables (kdsSymbolic.js twin)', () => {
        const legend = KDS.computed.symbolLegend.call({});
        const flat = {};
        legend.forEach((g) => g.entries.forEach(([code, label]) => { flat[`${g.group}|${code}`] = label; }));

        // Un échantillon couvrant chaque rôle (support/viande/sauce/crudité/formule).
        expect(flat['Support|G']).toBe('Galette');
        expect(flat['Viande|P']).toBe('Poulet');
        expect(flat['Viande|K']).toBe('Steak haché');
        expect(flat['Sauce|MAY']).toBe('Mayonnaise');
        expect(flat['Sauce|SAM']).toBe('Samouraï');
        expect(flat['Crudités|O̲']).toBe('Oignons cuits'); // O + U+0332, string exact du twin
        expect(flat['Formule|MENU']).toBe('Menu (formule)');
        expect(flat['Formule|F']).toBe('Frites');
        // [HEAL P2 2026-07-30] kdsSymbolic émet aussi FRITES/BOISSON (menu_frites/menu_boisson) —
        // la légende DOIT les couvrir sinon un chef ne les décode pas (audit V-finale).
        expect(flat['Formule|FRITES']).toBe('Frites (formule)');
        expect(flat['Formule|BOISSON']).toBe('Boisson (formule)');
    });

    it('toggleSymbolLegend flips the flag and persists', () => {
        const persist = vi.fn();
        const ctx = { showSymbolLegend: false, persistKdsUiPrefs: persist };
        KDS.methods.toggleSymbolLegend.call(ctx);
        expect(ctx.showSymbolLegend).toBe(true);
        expect(persist).toHaveBeenCalledTimes(1);
        KDS.methods.toggleSymbolLegend.call(ctx);
        expect(ctx.showSymbolLegend).toBe(false);
    });

    it('persistKdsUiPrefs writes kds.show_symbol_legend (1/0)', () => {
        const store = {};
        global.localStorage = {
            setItem: (k, v) => { store[k] = String(v); },
            getItem: (k) => (k in store ? store[k] : null),
        };
        const ctx = {
            stationFilter: 'all', groupByTable: false, soundEnabled: true, soundVolume: 80,
            autoPrintKitchen: true, showSymbolLegend: true, kdsAuthUserId: () => 0,
        };
        KDS.methods.persistKdsUiPrefs.call(ctx);
        expect(store['kds.show_symbol_legend']).toBe('1');

        ctx.showSymbolLegend = false;
        KDS.methods.persistKdsUiPrefs.call(ctx);
        expect(store['kds.show_symbol_legend']).toBe('0');
    });

    // Source sentinels — le chargement de la pref (created) et le câblage template
    // ne sont pas rejouables à froid sans monter le composant entier.
    it('created() loads the persisted pref', () => {
        expect(SRC).toMatch(/showSymbolLegend\s*=\s*localStorage\.getItem\("kds\.show_symbol_legend"\)\s*===\s*"1"/);
    });

    it('template wires the toggle button and the collapsible legend panel', () => {
        expect(SRC).toContain('data-testid="kds-legend-toggle"');
        expect(SRC).toContain('@click="toggleSymbolLegend"');
        expect(SRC).toContain('id="kds-symbol-legend"');
        expect(SRC).toMatch(/v-show="showSymbolLegend"/);
        expect(SRC).toMatch(/v-for="grp in symbolLegend"/);
        // Les codes restent affichés : la légende est un AJOUT (data-testid dédié), on
        // ne doit PAS avoir supprimé le rendu symbolique existant.
        expect(SRC).toContain('aria-controls="kds-symbol-legend"');
    });
});
