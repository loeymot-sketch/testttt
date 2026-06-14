/**
 * Ultrareview remediation — frontend regression suite.
 * Pins the specific defects the ultrareview (+ adversarial agents) found, so
 * they can never silently come back. Imports the REAL modules.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { categorize, renderItem, orderHasAnyAllergen } from '../../resources/js/helpers/kdsCustomization.js';
import { OssSyncService } from '../../resources/js/services/OssSyncService.js';

describe('#4 categorize — unanchored "eau" no longer steals desserts', () => {
    it('classifies Gâteau as dessert (not drink)', () => {
        expect(categorize({ item_name: 'Gâteau au chocolat' })).toBe('dessert');
    });
    it('does not classify Plateau / Château as drink', () => {
        expect(categorize({ item_name: 'Plateau de fromages' })).not.toBe('drink');
        expect(categorize({ item_name: 'Château glacé' })).not.toBe('drink');
    });
    it('still classifies real drinks (Eau, Jus, Thé, Café) as drink', () => {
        expect(categorize({ item_name: 'Eau minérale 50cl' })).toBe('drink');
        expect(categorize({ item_name: "Jus d'orange pressé" })).toBe('drink');
        expect(categorize({ item_name: 'Thé à la menthe' })).toBe('drink');
        expect(categorize({ item_name: 'Café allongé' })).toBe('drink');
    });
});

describe('#8 (P0 food-safety) allergen codes are never dropped by type', () => {
    it('keeps NUMERIC allergen codes (renderItem)', () => {
        const out = renderItem({ item_name: 'Burger', quantity: 1, allergens_snapshot: [1, 7, 14] });
        expect(out.hasAllergen).toBe(true);
        const allergenLine = out.lines.find((l) => l.type === 'allergen');
        expect(allergenLine).toBeTruthy();
        expect(allergenLine.codes).toEqual(['1', '7', '14']);
    });
    it('keeps a falsy-zero code and mixed types', () => {
        const out = renderItem({ item_name: 'Plat', quantity: 1, allergens_snapshot: [0, 'gluten', null, ''] });
        expect(out.hasAllergen).toBe(true);
        expect(out.lines.find((l) => l.type === 'allergen').codes).toEqual(['0', 'gluten']);
    });
    it('orderHasAnyAllergen returns true for numeric codes', () => {
        expect(orderHasAnyAllergen([{ allergens_snapshot: [7] }])).toBe(true);
        expect(orderHasAnyAllergen([{ allergens_snapshot: [0] }])).toBe(true);
    });
    it('still returns false for genuinely empty', () => {
        expect(orderHasAnyAllergen([{ allergens_snapshot: [] }, { allergens_snapshot: [null, ''] }])).toBe(false);
    });
});

describe('OssSyncService — #6 (4xx backoff) + #7 (emit guard)', () => {
    afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); });

    it('#6 a persistent 4xx routes to backoff (was: only 5xx)', async () => {
        vi.useFakeTimers();
        const store = { dispatch: vi.fn().mockRejectedValue({ response: { status: 401 } }) };
        const svc = new OssSyncService();
        svc._started = true;
        svc._store = store;

        const errors = [];
        svc.on('error', (e) => errors.push(e));

        await svc._poll();

        expect(svc.state()).toBe('backoff');
        // backoff emits a backoffMs delay, not a silent normal-cadence reschedule
        expect(errors.at(-1)).toMatchObject({ status: 401 });
        expect(errors.at(-1).backoffMs).toBeGreaterThan(0);
    });

    it('#6 a network error (status 0) also backs off', async () => {
        vi.useFakeTimers();
        const store = { dispatch: vi.fn().mockRejectedValue(new Error('network down')) };
        const svc = new OssSyncService();
        svc._started = true;
        svc._store = store;
        await svc._poll();
        expect(svc.state()).toBe('backoff');
    });

    it('#7 _emit: a throwing listener does not stop the others or throw', () => {
        const svc = new OssSyncService();
        let reached = false;
        svc.on('sync', () => { throw new Error('listener boom'); });
        svc.on('sync', () => { reached = true; });

        expect(() => svc._emit('sync', { rows: [] })).not.toThrow();
        expect(reached).toBe(true);
    });
});
