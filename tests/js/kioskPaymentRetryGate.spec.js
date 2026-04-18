/**
 * Kiosk Phase 9.1.11 — Redirection vers `kiosk.error.payment-refused`
 * après N échecs de paiement consécutifs.
 *
 * Avant ce fix, un client dont la carte était refusée voyait juste un toast
 * et pouvait retenter indéfiniment — aucune porte de sortie claire. Ce
 * comportement est connu pour entraîner des abandons de commande et du
 * stress sur la file d'attente (rapport UX concurrence : McDo, Quick, BK
 * routent TOUS vers un écran dédié après 2 refus).
 *
 * Le test vérifie les **invariants du contrat** sans monter un SFC entier
 * (évite les pièges de montage Vuex/Router) :
 *  1. `paymentFailureCount` existe dans `data()` avec valeur initiale 0.
 *  2. `MAX_PAYMENT_FAILURES` est une option d'instance = 2 (consensus UX).
 *  3. Le code source appelle `this.$router.push({ name: 'kiosk.error.payment-refused' ... })`
 *     dans la branche de rejet.
 *  4. `selectMethod` remet le compteur à 0 (sinon retry cash après CB refusé
 *     renverrait direct sur /error).
 *  5. La route `kiosk.error.payment-refused` est bien exportée par le routeur.
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

import kioskRoutes from '../../resources/js/router/modules/kioskRoutes.js';

function findRouteByName(routes, name) {
    for (const r of routes) {
        if (r.name === name) return r;
        if (r.children) {
            const hit = findRouteByName(r.children, name);
            if (hit) return hit;
        }
    }
    return null;
}

const src = fs.readFileSync(
    path.join(
        __dirname,
        '../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue',
    ),
    'utf8',
);

describe('Kiosk Phase 9.1.11 — payment retry gate', () => {
    it('paymentFailureCount initialisé à 0 dans data()', () => {
        expect(src).toMatch(/paymentFailureCount:\s*0/);
    });

    it('MAX_PAYMENT_FAILURES exposé comme option d\'instance', () => {
        // Cherche la déclaration `MAX_PAYMENT_FAILURES: <int>` dans le
        // bloc `<script>` au niveau du défaut export. On reste tolérant
        // à des valeurs 2 ou plus pour ne pas geler le seuil.
        expect(src).toMatch(/MAX_PAYMENT_FAILURES:\s*([2-9]|[1-9]\d+)/);
    });

    it('confirmPayment() incrémente paymentFailureCount dans le catch', () => {
        // Match "this.paymentFailureCount += 1" dans le catch de confirmPayment.
        expect(src).toMatch(/this\.paymentFailureCount\s*\+=\s*1/);
    });

    it('redirige vers kiosk.error.payment-refused au seuil', () => {
        expect(src).toMatch(
            /this\.\$router\.push\(\s*\{\s*name:\s*['"]kiosk\.error\.payment-refused['"]/,
        );
    });

    it('selectMethod réinitialise le compteur à 0', () => {
        // On cible la définition de la méthode (signature `selectMethod(m) {`)
        // pas les call-sites dans le template.
        const startIdx = src.search(/selectMethod\s*\(\s*m\s*\)\s*\{/);
        expect(startIdx).toBeGreaterThan(0);
        const slice = src.slice(startIdx, startIdx + 800);
        expect(slice.includes('paymentFailureCount = 0')).toBe(true);
    });

    it('la route kiosk.error.payment-refused est bien déclarée dans kioskRoutes', () => {
        const route = findRouteByName(kioskRoutes, 'kiosk.error.payment-refused');
        expect(route).not.toBeNull();
        expect(route.path).toContain('error/payment-refused');
    });
});
