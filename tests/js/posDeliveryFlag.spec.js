import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

/**
 * [ULTRA-AUDIT 2026-09-26 · A18] Le bouton "Livraison" du sélecteur de type de
 * commande n'avait AUCUNE garde de flag (contrairement à "Sur place", gardé par
 * `dineInEnabled`) : il restait toujours sélectionnable même réglage
 * `order_setup_delivery` sur DISABLE (Réglages > Configuration des commandes >
 * Livraison). Rapport externe confirmé reproduit : réglage réel en base = 10
 * (Activity::DISABLE) alors que le bouton était inconditionnellement rendu.
 *
 * `order_setup_delivery` est exposé par SettingResource en valeur BRUTE de
 * `App\Enums\Activity` (ENABLE=5 / DISABLE=10) — un conventionnement NUMÉRIQUE
 * différent de `pos_dine_in_enabled` (booléen-esque '0'/'1') : comparaison
 * explicite à ENABLE, jamais la coercion `String(...) === '1'` du flag voisin
 * (qui serait TOUJOURS fausse ici puisque 5 !== '1').
 *
 * ──────────────────────────────────────────────────────────────────────────
 * [QA 2026-09-28] CE BANC ÉTAIT AU MAUVAIS PÉRIMÈTRE — corrigé ici.
 *
 * Il n'importait PAS `PosComponent.vue` : son unique import était
 * `{ describe, it, expect }` et il redéclarait sa propre copie de la règle
 * (`function deliveryEnabledFrom(setting) {...}`). Il testait donc un DOUBLON
 * du garde, et serait resté VERT si `v-if="deliveryEnabled"` avait été
 * supprimé du gabarit, ou si la propriété calculée du composant avait changé
 * de sémantique. Un banc vert sur le mauvais périmètre est pire que pas de
 * banc : il fabrique une confiance non gagnée (CLAUDE.md §3ter,
 * « instrument avant produit »).
 *
 * Les six cas d'origine sont conservés à l'identique ; ils interrogent
 * désormais la VRAIE propriété calculée du composant, et un garde de gabarit
 * vérifie que le rendu la consomme réellement.
 *
 * ⚠️ `tests/js/posDineInFlag.spec.js` présente exactement le même défaut de
 * périmètre (même forme, même redéclaration locale) — signalé dans le rapport
 * QA, non modifié ici pour garder ce correctif borné.
 * ──────────────────────────────────────────────────────────────────────────
 */
const ACTIVITY_ENABLE = 5;
const ACTIVITY_DISABLE = 10;

/** Interroge la VRAIE propriété calculée du composant servi. */
function deliveryEnabledFrom(setting) {
    return PosComponent.computed.deliveryEnabled.call({ setting });
}

describe('POS delivery feature flag (order_setup_delivery)', () => {
    it('la propriété calculée existe réellement sur PosComponent', () => {
        expect(typeof PosComponent.computed.deliveryEnabled).toBe('function');
    });

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

    /**
     * GARDE DE PÉRIMÈTRE — la raison d'être de la réécriture de ce banc.
     * Une propriété calculée correcte ne prouve rien si le gabarit ne l'utilise
     * pas. On vérifie que le rendu du sélecteur consomme bien le flag, et que la
     * restauration du type de commande mémorisé est gardée elle aussi.
     */
    it('le gabarit garde réellement le choix Livraison avec ce flag', () => {
        const src = readFileSync(
            resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'),
            'utf8'
        );

        expect(src).toContain('v-if="deliveryEnabled"');
        // La restauration d'un type de commande mémorisé doit aussi respecter le flag,
        // sinon un rechargement réactiverait la livraison désactivée.
        expect(src).toMatch(/orderTypeEnum\.DELIVERY\s*&&\s*this\.deliveryEnabled/);
    });
});
