/**
 * BUILD-4 Round 4 sentinel — verifies the StockRuptureDashboardComponent.vue
 * source contains the load-bearing wireup we just added:
 *
 *   - Echo helper import from services/eventContract
 *   - data-testid hooks for toggle / bulk / search / branch-filter / modal
 *   - All new i18n keys are referenced from $t()
 *   - permissionChecker('items_edit') gating
 *
 * Static source-level sentinel (not a mounted Vitest test) — matches the
 * pattern of tests/js/stockRuptureRoute.spec.js.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const componentPath = resolve(
    process.cwd(),
    'resources/js/components/admin/stock/StockRuptureDashboardComponent.vue'
);

const source = readFileSync(componentPath, 'utf-8');

describe('Stock rupture dashboard component — V1.0.2 BUILD-4 wireup', () => {
    it('imports the Echo onEvents helper', () => {
        expect(source).toMatch(/from\s+["']\.\.\/\.\.\/\.\.\/services\/eventContract["']/);
        expect(source).toContain('onEvents');
    });

    it('subscribes to the 3 availability broadcast events', () => {
        expect(source).toContain("broadcastAs: 'ItemAvailabilityChanged'");
        expect(source).toContain("broadcastAs: 'ItemVariationAvailabilityChanged'");
        expect(source).toContain("broadcastAs: 'ItemExtraAvailabilityChanged'");
    });

    it('gates write actions behind items_edit permission', () => {
        expect(source).toMatch(/permissionChecker\(["']items_edit["']\)/);
        expect(source).toContain('canEditAvailability');
    });

    it('exposes data-testid hooks for the new UI surface', () => {
        const hooks = [
            'stock-rupture-search',
            'stock-rupture-branch-select',
            'stock-rupture-bulk-bar',
            'stock-rupture-bulk-restore',
            'stock-rupture-reason-modal',
            'stock-rupture-reason-confirm',
            'stock-rupture-error',
        ];
        hooks.forEach((hook) => {
            expect(source).toContain(hook);
        });
    });

    it('references the new i18n keys via $t', () => {
        const keys = [
            'admin.stock_rupture.mark_rupture',
            'admin.stock_rupture.restore',
            'admin.stock_rupture.restore_selected',
            'admin.stock_rupture.reason_label',
            'admin.stock_rupture.search_placeholder',
            'admin.stock_rupture.branch_filter_all',
            'admin.stock_rupture.branch_filter_label',
            'admin.stock_rupture.selected_count',
            'admin.stock_rupture.toggle_error',
            'admin.stock_rupture.bulk_partial_error',
            'admin.stock_rupture.loading_error',
        ];
        keys.forEach((key) => {
            expect(source).toContain(key);
        });
    });

    it('targets only existing AvailabilityController endpoints (no new routes)', () => {
        expect(source).toContain('admin/availability/toggle');
        // We do NOT introduce a new bulk endpoint — bulk uses sequential POSTs.
        expect(source).not.toMatch(/admin\/availability\/bulk/);
        expect(source).not.toMatch(/admin\/stock\/bulk/);
    });
});
