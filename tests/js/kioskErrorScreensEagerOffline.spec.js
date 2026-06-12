/**
 * [dispute-r1 D-001 2026-06-12] Écrans d'erreur kiosk — import EAGER obligatoire.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial (D-borne-robustesse/ADVERSARIAL_VERDICT.md, D-001 P1) :
 * le chunk lazy `kiosk-errors` (même avec webpackPrefetch, heal W4-N1) est
 * RE-FETCHÉ réseau au moment du `import()` (artisan serve sans Cache-Control)
 * → offline, `/kiosk/error/network` ne rend JAMAIS :
 *   ChunkLoadError: Loading chunk 28 failed (/js/kiosk-errors.js)
 * L'écran « Connexion perdue » était donc injoignable précisément dans son
 * unique cas d'usage, avec pageerror non gérée sur idle/payment.
 *
 * Invariant verrouillé ici : les 4 composants d'erreur kiosk sont importés
 * STATIQUEMENT dans kioskRoutes.js (donc embarqués dans le bundle principal
 * déjà en mémoire avant la coupure réseau) — plus aucun `import()` dynamique
 * pour eux.
 */
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

vi.mock('../../resources/js/store/index.js', () => ({
    default: {
        state: { kioskCart: { kioskToken: 'token', orderRef: null } },
        getters: {
            'kioskCart/isEmpty': false,
            'kioskFilter/hydrated': true,
        },
        dispatch: vi.fn().mockResolvedValue(),
    },
}));

import kioskRoutes from '../../resources/js/router/modules/kioskRoutes.js';

const routesSrc = readFileSync(
    resolve(process.cwd(), 'resources/js/router/modules/kioskRoutes.js'),
    'utf-8',
);

const ERROR_COMPONENTS = [
    'KioskErrorNetworkComponent',
    'KioskErrorMenuUnavailableComponent',
    'KioskErrorProductRemovedComponent',
    'KioskErrorPaymentRefusedComponent',
];

const ERROR_ROUTE_NAMES = [
    'kiosk.error.network',
    'kiosk.error.menu-unavailable',
    'kiosk.error.product-removed',
    'kiosk.error.payment-refused',
];

function findRouteByName(routes, name) {
    for (const route of routes) {
        if (route.name === name) return route;
        if (Array.isArray(route.children)) {
            const child = findRouteByName(route.children, name);
            if (child) return child;
        }
    }
    return null;
}

describe('[D-001] kiosk error screens must be eagerly importable offline', () => {
    it.each(ERROR_COMPONENTS)('%s is statically imported (no dynamic import())', (name) => {
        // Import statique présent…
        expect(routesSrc).toMatch(new RegExp(`import\\s+${name}\\s+from`));
        // …et AUCUN import() dynamique résiduel pour ce composant.
        expect(routesSrc).not.toMatch(new RegExp(`import\\([^)]*${name}`));
    });

    it.each(ERROR_ROUTE_NAMES)('route %s resolves to a concrete component object (not a lazy factory)', (name) => {
        const route = findRouteByName(kioskRoutes, name);
        expect(route).toBeTruthy();
        // Un composant Vue SFC importé statiquement est un objet ; une route
        // lazy expose une fonction `() => import(...)`. Offline, seule la
        // forme objet rend sans round-trip réseau.
        expect(typeof route.component).toBe('object');
        expect(route.component).not.toBeNull();
    });

    it('no kiosk-errors webpack chunk remains in kioskRoutes.js', () => {
        expect(routesSrc).not.toMatch(/webpackChunkName:\s*["']kiosk-errors["']/);
    });
});
