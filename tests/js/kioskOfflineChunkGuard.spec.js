/**
 * [W-REM T-R3.1a F-BV-03 2026-06-12] Parcours d'achat borne — chunks critiques
 * offline-safe.
 * -----------------------------------------------------------------------------
 * Constat (audit borne F-BV-03) : panier / upsell / paiement étaient lazy
 * (chunk "kiosk-shell"). Si la connexion tombe APRÈS le chargement du
 * catalogue mais AVANT la première navigation vers /kiosk/cart|upsell|payment,
 * le `import()` du chunk échoue (ChunkLoadError, artisan serve sans
 * Cache-Control → pas de réutilisation cache), la navigation est avortée
 * SILENCIEUSEMENT : bouton « Payer » mort, zéro feedback client.
 *
 * Le même mode de défaillance a déjà été prouvé et corrigé pour
 * shell/idle/catalogue (tests/e2e/kiosk-spa-black-screen-guard.spec.js) et
 * pour les 4 écrans d'erreur (kioskErrorScreensEagerOffline.spec.js, D-001 :
 * webpackPrefetch RÉFUTÉ offline). Ce spec étend l'invariant au tunnel
 * d'achat : cart, upsell, payment doivent être importés STATIQUEMENT dans
 * kioskRoutes.js — déjà en mémoire avant la coupure réseau.
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

const PURCHASE_COMPONENTS = [
    'KioskCartComponent',
    'KioskUpsellComponent',
    'KioskPaymentComponent',
];

const PURCHASE_ROUTE_NAMES = [
    'kiosk.cart',
    'kiosk.upsell',
    'kiosk.payment',
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

describe('[F-BV-03] kiosk purchase funnel (cart/upsell/payment) must be offline-safe', () => {
    it.each(PURCHASE_COMPONENTS)('%s is statically imported (no dynamic import())', (name) => {
        // Import statique présent…
        expect(routesSrc).toMatch(new RegExp(`import\\s+${name}\\s+from`));
        // …et AUCUN import() dynamique résiduel pour ce composant.
        expect(routesSrc).not.toMatch(new RegExp(`import\\([^)]*${name}`));
    });

    it.each(PURCHASE_ROUTE_NAMES)('route %s resolves to a concrete component object (not a lazy factory)', (name) => {
        const route = findRouteByName(kioskRoutes, name);
        expect(route).toBeTruthy();
        // Un SFC importé statiquement est un objet ; une route lazy expose une
        // fonction `() => import(...)`. Offline, seule la forme objet rend
        // sans round-trip réseau — le bouton « Payer » reste donc vivant.
        expect(typeof route.component).toBe('object');
        expect(route.component).not.toBeNull();
    });
});
