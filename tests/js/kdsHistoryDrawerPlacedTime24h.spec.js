/**
 * [INT KDS-OSS-V1 2026-06-13] KDS « Historique du jour » — heure de prise de
 * commande au format 24h cohérent.
 * -----------------------------------------------------------------------------
 * Constat audit : le tiroir Historique affiche `placedTime(order)` qui lie
 * `order.order_time` BRUT. Or le backend formate `order_time` via
 * AppLibrary::time() = env('TIME_FORMAT'). Quand TIME_FORMAT vaut un motif 12h
 * (`h:i A`), le tiroir montre « 02:17 PM » TANDIS QUE les cartes live KDS
 * passent `order_datetime` par kdsDisplayDateTime() qui normalise en 24h
 * (« 14:17 »). Résultat : le même écran mélange 02:17 PM et 14:20 → incohérent.
 *
 * Invariant : placedTime() normalise toujours en 24h (HH:MM), qu'on lui donne
 * un order_time 12h (« 02:17 PM ») ou déjà 24h (« 14:20 »).
 */
import { describe, it, expect } from 'vitest';
import KdsHistoryDrawer from '../../resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue';

// Lie les méthodes entre elles (placedTime appelle this._to24h) comme Vue le
// ferait à l'instanciation.
const ctx = {
    _to24h: KdsHistoryDrawer.methods._to24h,
    placedTime: KdsHistoryDrawer.methods.placedTime,
};
const placedTime = (order) => ctx.placedTime.call(ctx, order);

describe('[KDS-OSS-V1] KdsHistoryDrawer.placedTime — format 24h', () => {
    it('convertit un order_time 12h « 02:17 PM » en 24h « 14:17 »', () => {
        expect(placedTime({ order_time: '02:17 PM' })).toBe('14:17');
    });

    it('convertit « 12:05 AM » (minuit) en « 00:05 »', () => {
        expect(placedTime({ order_time: '12:05 AM' })).toBe('00:05');
    });

    it('convertit « 12:30 PM » (midi) en « 12:30 »', () => {
        expect(placedTime({ order_time: '12:30 PM' })).toBe('12:30');
    });

    it('laisse intact un order_time déjà 24h « 14:20 »', () => {
        expect(placedTime({ order_time: '14:20' })).toBe('14:20');
    });

    it('retombe sur order_datetime (normalisé 24h) si order_time absent', () => {
        expect(placedTime({ order_datetime: '02:17 PM, 13-06-2026' })).toBe('14:17, 13-06-2026');
    });

    it('renvoie une chaîne vide si aucune heure disponible', () => {
        expect(placedTime({})).toBe('');
        expect(placedTime(null)).toBe('');
    });
});
