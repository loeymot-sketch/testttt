/**
 * [Mission 1 — Stock-Rupture UI Simplification 2026-05-21]
 *
 * Source-level sentinel for StockRuptureDashboardComponent.vue V2 (unified
 * catalogue browser). Replaces the V1.0.2 BUILD-4 sentinel that locked the
 * old multi-axis dashboard.
 *
 * Locks the load-bearing wireup of the V2 rewrite:
 *   - Echo helper import from services/eventContract
 *   - 3 availability event subscriptions (Item / ItemExtra / ItemVariation)
 *   - items_edit permission gate (canEditAvailability)
 *   - data-testid hooks for the rail / search / toggle / error / read-only
 *   - i18n keys for the new admin.stock_mgmt.* namespace
 *   - Correct backend routes (admin/menu/availability/...) — the V1 component
 *     referenced admin/availability/* which IS NOT registered (latent bug,
 *     repaired by this rewrite).
 *   - No new bulk endpoint (sequential fan-out preserved).
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const componentPath = resolve(
    process.cwd(),
    'resources/js/components/admin/stock/StockRuptureDashboardComponent.vue'
);

const source = readFileSync(componentPath, 'utf-8');

describe('Stock management v2 component — Mission 1 unified catalogue browser', () => {
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

    it('exposes the V2 data-testid hooks for the unified browser', () => {
        const hooks = [
            'stock-management-v2',
            'stock-mgmt-rail',
            'stock-mgmt-search',
            'stock-mgmt-error',
        ];
        hooks.forEach((hook) => {
            expect(source).toContain(hook);
        });
    });

    it('references the new admin.stock_mgmt.* i18n keys via $t', () => {
        const keys = [
            'admin.stock_mgmt.title',
            'admin.stock_mgmt.subtitle',
            'admin.stock_mgmt.search',
            'admin.stock_mgmt.empty',
            'admin.stock_mgmt.in_stock',
            'admin.stock_mgmt.out_of_stock',
            'admin.stock_mgmt.loading_error',
            'admin.stock_mgmt.toggle_error',
            'admin.stock_mgmt.branch_label',
            'admin.stock_mgmt.read_only',
        ];
        keys.forEach((key) => {
            expect(source).toContain(key);
        });
    });

    it('targets the CORRECT AvailabilityController endpoints (admin/menu/availability/*)', () => {
        // V1 used `admin/availability/toggle` which IS NOT registered in routes/api.php
        // → silent 404 / HTML masquerade. The rewrite repairs the paths.
        expect(source).toContain('admin/menu/availability/toggle');
        expect(source).toContain('admin/menu/availability/extra/toggle');
        expect(source).toContain('admin/menu/availability/variation/toggle');
    });

    it('reads the catalog via the new unified endpoint', () => {
        expect(source).toContain('admin/stock/catalog-overview');
    });

    it('keeps the no-new-bulk-route promise (sequential fan-out only)', () => {
        expect(source).not.toMatch(/admin\/availability\/bulk/);
        expect(source).not.toMatch(/admin\/stock\/bulk/);
        expect(source).not.toMatch(/admin\/menu\/availability\/bulk/);
    });
});
