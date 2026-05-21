import { describe, expect, it } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID  MISSION-1 | @source plan plans/PLAN_MISSION_1_STOCK_RUPTURE_SIMPLIFICATION_2026-05-21.md
 * @reason
 *   Mission 1 rewrites StockRuptureDashboardComponent.vue to the V2 unified
 *   catalogue browser per owner spec 2026-05-21 (single page, binary
 *   per-product toggle, no quantity / reason / bulk-select / scan trigger).
 *
 *   This sentinel locks the V2 layout + wiring contract so future refactors
 *   do not regress:
 *     - root `data-testid="stock-management-v2"` (E2E spec stable hook)
 *     - left rail + bucket buttons
 *     - product cards + role="switch" toggles
 *     - correct backend endpoints (admin/menu/availability/* — the V1
 *       component referenced admin/availability/* which IS NOT registered,
 *       silently 404'd; rewrite repairs that latent bug)
 *     - bulk fan-out preserved (no new bulk endpoint)
 */
describe('StockManagement V2 — Mission 1 unified catalogue browser', () => {
    const componentPath = resolve(
        process.cwd(),
        'resources/js/components/admin/stock/StockRuptureDashboardComponent.vue',
    );

    it('component file exists at the expected path', () => {
        expect(existsSync(componentPath)).toBe(true);
    });

    const source = existsSync(componentPath) ? readFileSync(componentPath, 'utf8') : '';

    it('declares the canonical component name', () => {
        expect(source).toMatch(/name:\s*['"]StockRuptureDashboardComponent['"]/);
    });

    it('roots the section with the V2 testid + aria-busy binding', () => {
        expect(source).toMatch(/data-testid="stock-management-v2"/);
        expect(source).toMatch(/:aria-busy="loading"/);
    });

    it('renders the left rail with bucket buttons', () => {
        expect(source).toMatch(/data-testid="stock-mgmt-rail"/);
        // Bucket testid is interpolated `stock-mgmt-bucket-${bucket.key}`.
        expect(source).toMatch(/:data-testid="`stock-mgmt-bucket-\$\{bucket\.key\}`"/);
    });

    it('renders product cards with toggle switches and ARIA role="switch"', () => {
        expect(source).toMatch(/:data-testid="`stock-mgmt-product-\$\{product\.key\}`"/);
        expect(source).toMatch(/:data-testid="`stock-mgmt-toggle-\$\{product\.key\}`"/);
        expect(source).toMatch(/role="switch"/);
        expect(source).toMatch(/:aria-checked=/);
    });

    it('exposes the search input + branch filter testids', () => {
        expect(source).toMatch(/data-testid="stock-mgmt-search"/);
        expect(source).toMatch(/data-testid="stock-mgmt-branch-select"/);
    });

    it('uses the unified read endpoint admin/stock/catalog-overview', () => {
        expect(source).toContain('admin/stock/catalog-overview');
    });

    it('uses the canonical AvailabilityController endpoints (admin/menu/availability/*)', () => {
        expect(source).toContain('admin/menu/availability/toggle');
        expect(source).toContain('admin/menu/availability/extra/toggle');
        expect(source).toContain('admin/menu/availability/variation/toggle');
    });

    it('does NOT reintroduce a bulk/scan/reason modal endpoint', () => {
        expect(source).not.toMatch(/admin\/availability\/bulk/);
        expect(source).not.toMatch(/admin\/stock\/bulk/);
        expect(source).not.toMatch(/admin\/menu\/availability\/bulk/);
        expect(source).not.toMatch(/scan-rupture\/run/);
    });

    it('gates writes behind items_edit permission (read-only fallback for viewers)', () => {
        expect(source).toMatch(/permissionChecker\(['"]items_edit['"]\)/);
        expect(source).toMatch(/canEditAvailability/);
    });

    it('subscribes to the 3 availability broadcast events', () => {
        expect(source).toContain("broadcastAs: 'ItemAvailabilityChanged'");
        expect(source).toContain("broadcastAs: 'ItemExtraAvailabilityChanged'");
        expect(source).toContain("broadcastAs: 'ItemVariationAvailabilityChanged'");
    });

    it('default reason on OFF toggle is in the StockLevel whitelist (out_of_stock_manual)', () => {
        // Backend ToggleExtraAvailabilityRequest + ToggleVariationAvailabilityRequest
        // require `reason` ∈ MANUAL_UNAVAILABLE_REASONS when is_available=false.
        // The owner spec drops the reason picker, so the component must send a
        // sane whitelisted default. Locking the constant value here prevents
        // accidental drift to a non-whitelisted slug → 422 on every OOS toggle.
        expect(source).toMatch(/DEFAULT_REASON\s*=\s*['"]out_of_stock_manual['"]/);
    });

    it('preserves the V2 i18n namespace admin.stock_mgmt.*', () => {
        expect(source).toContain('admin.stock_mgmt.title');
        expect(source).toContain('admin.stock_mgmt.in_stock');
        expect(source).toContain('admin.stock_mgmt.out_of_stock');
    });
});
