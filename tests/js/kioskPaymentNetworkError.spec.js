/**
 * [UIUX-W4 K10 2026-06-11] — Offline paiement : « Network Error » brut.
 *
 * Repro W3-C8 (C8-OFFLINE-01) : setOffline(true) sur /kiosk/payment →
 * confirmer → le catch de confirmPayment affichait le message axios brut
 * « Network Error » (EN), sans écran d'attente ni reprise.
 *
 * Contrat verrouillé (pattern source-contract de
 * kioskPaymentRetryGate.spec.js — pas de mount du SFC lourd) :
 *  1. Le catch détecte l'erreur réseau axios (request sans response /
 *     ERR_NETWORK / 'Network Error').
 *  2. Message FR `kiosk.pay_screen.network_lost` (défini dans les 5
 *     locales) au lieu du message brut.
 *  3. Route vers `kiosk.error.network` (CTA Réessayer + staff, panier
 *     intact) AVANT l'incrément du compteur de refus TPE (une coupure
 *     réseau n'est pas un refus de paiement).
 *  4. La route kiosk.error.network existe dans kioskRoutes.
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

import kioskRoutes from '../../resources/js/router/modules/kioskRoutes.js';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';
import ar from '../../resources/js/languages/ar.json';
import de from '../../resources/js/languages/de.json';
import bn from '../../resources/js/languages/bn.json';

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

describe('Kiosk payment network error handling (K10)', () => {
    it('détecte l\'erreur réseau axios dans le catch de confirmPayment', () => {
        expect(src).toMatch(/isNetworkError\s*=\s*!err\?\.response/);
        expect(src).toContain("err?.code === 'ERR_NETWORK'");
        expect(src).toContain("err?.message === 'Network Error'");
    });

    it('affiche le message FR network_lost (jamais le « Network Error » brut)', () => {
        expect(src).toContain("$t('kiosk.pay_screen.network_lost')");
    });

    it('route vers kiosk.error.network AVANT le compteur de refus TPE', () => {
        const pushIdx = src.search(/this\.\$router\.push\(\s*\{\s*name:\s*['"]kiosk\.error\.network['"]/);
        const counterIdx = src.search(/this\.paymentFailureCount\s*\+=\s*1/);
        expect(pushIdx).toBeGreaterThan(0);
        expect(counterIdx).toBeGreaterThan(0);
        expect(pushIdx).toBeLessThan(counterIdx);
        // La branche réseau sort du catch (return) sans empiler le compteur.
        const slice = src.slice(pushIdx, counterIdx);
        expect(slice).toContain('return;');
    });

    it('la route kiosk.error.network est déclarée dans kioskRoutes', () => {
        const route = findRouteByName(kioskRoutes, 'kiosk.error.network');
        expect(route).not.toBeNull();
        expect(route.path).toContain('error/network');
    });

    it('kiosk.pay_screen.network_lost défini dans les 5 locales', () => {
        for (const [code, messages] of Object.entries({ fr, en, ar, de, bn })) {
            expect(messages.kiosk?.pay_screen?.network_lost, `${code} network_lost`).toBeTruthy();
        }
        expect(fr.kiosk.pay_screen.network_lost).toMatch(/Connexion perdue/);
        expect(fr.kiosk.pay_screen.network_lost).toMatch(/pas été envoyée/);
        expect(en.kiosk.pay_screen.network_lost).not.toBe(fr.kiosk.pay_screen.network_lost);
    });
});
