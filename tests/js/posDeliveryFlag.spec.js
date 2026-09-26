import { describe, it, expect } from 'vitest';

/**
 * [ULTRA-AUDIT 2026-09-26 · A18] Le bouton "Livraison" du sélecteur de type de
 * commande n'avait AUCUNE garde de flag (contrairement à "Sur place", gardé par
 * `dineInEnabled` — voir tests/js/posDineInFlag.spec.js) : il restait toujours
 * sélectionnable même réglage `order_setup_delivery` sur DISABLE (Réglages >
 * Configuration des commandes > Livraison). Rapport externe confirmé reproduit :
 * réglage réel en base = 10 (Activity::DISABLE) alors que le bouton était
 * inconditionnellement rendu.
 *
 * `order_setup_delivery` est exposé par SettingResource en valeur BRUTE de
 * `App\Enums\Activity` (ENABLE=5 / DISABLE=10) — un conventionnement NUMÉRIQUE
 * différent de `pos_dine_in_enabled` (booléen-esque '0'/'1') : comparaison
 * explicite à ENABLE, jamais la coercion `String(...) === '1'` du flag voisin
 * (qui serait TOUJOURS fausse ici puisque 5 !== '1').
 */
const ACTIVITY_ENABLE = 5;
const ACTIVITY_DISABLE = 10;

function deliveryEnabledFrom(setting) {
    const s = setting || {};
    const raw = s.order_setup_delivery;
    return Number(raw) === ACTIVITY_ENABLE;
}

describe('POS delivery feature flag (order_setup_delivery)', () => {
    it('le cas réel du rapport : réglage DISABLE (10) → livraison masquée', () => {
        expect(deliveryEnabledFrom({ order_setup_delivery: ACTIVITY_DISABLE })).toBe(false);
    });

    it('réglage ENABLE (5) → livraison visible', () => {
        expect(deliveryEnabledFrom({ order_setup_delivery: ACTIVITY_ENABLE })).toBe(true);
    });

    it('absent / vide → masquée par défaut (sûr en cas de backend régressé)', () => {
        expect(deliveryEnabledFrom({})).toBe(false);
        expect(deliveryEnabledFrom(null)).toBe(false);
        expect(deliveryEnabledFrom(undefined)).toBe(false);
    });

    it('ne confond jamais avec le conventionnement booléen du flag Dine-In voisin', () => {
        // '1' est vrai pour pos_dine_in_enabled, mais N'EST PAS Activity::ENABLE (5).
        expect(deliveryEnabledFrom({ order_setup_delivery: '1' })).toBe(false);
        expect(deliveryEnabledFrom({ order_setup_delivery: 1 })).toBe(false);
        expect(deliveryEnabledFrom({ order_setup_delivery: true })).toBe(false);
    });

    it('accepte la valeur ENABLE en string ("5"), comme les autres payloads Eloquent', () => {
        expect(deliveryEnabledFrom({ order_setup_delivery: '5' })).toBe(true);
    });

    it('rejette toute valeur numérique autre que 5', () => {
        expect(deliveryEnabledFrom({ order_setup_delivery: 0 })).toBe(false);
        expect(deliveryEnabledFrom({ order_setup_delivery: 50 })).toBe(false);
        expect(deliveryEnabledFrom({ order_setup_delivery: NaN })).toBe(false);
    });
});
