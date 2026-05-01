const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.describe('Kiosk legacy products redirect contract', () => {
    test('router redirects legacy product category links and emits legacy telemetry', async () => {
        const root = process.cwd();
        const routerSource = fs.readFileSync(
            path.join(root, 'resources/js/router/modules/kioskRoutes.js'),
            'utf8',
        );
        const analyticsSource = fs.readFileSync(
            path.join(root, 'resources/js/helpers/kioskAnalytics.js'),
            'utf8',
        );

        expect(routerSource).toContain('name: "kiosk.products"');
        expect(routerSource).toContain('redirect: redirectLegacyProductsRoute');
        expect(routerSource).toContain("name: 'kiosk.categories'");
        expect(routerSource).toMatch(/\.\.\.\(to\.query \|\| \{\}\),\s*cat: categoryId/s);
        expect(routerSource).toContain('trackLegacyRouteHit');
        expect(routerSource).toContain('query_keys: Object.keys(to.query || {}).sort()');

        expect(analyticsSource).toContain('function trackLegacyRouteHit');
        expect(analyticsSource).toContain("event_name: 'legacy_route_hit'");
        expect(analyticsSource).toContain("subtype: 'legacy_route_hit'");
        expect(analyticsSource).toContain("type: 'idle_event'");
    });
});
