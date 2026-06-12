import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [DISPUTE-R1 A-RED-11 2026-06-12] — P2 : /admin/pos-orders/show tirait
 * `GET /api/admin/delivery-boy` inconditionnellement au mounted() →
 * 403 silencieux à CHAQUE visite pour le rôle caissier (la route backend
 * est gatée `permission:delivery-boys` — DeliveryBoyController:29).
 *
 * Contrat verrouillé :
 *   1. plus AUCUN dispatch deliveryBoy/lists inconditionnel au mounted ;
 *   2. le chargement est conditionné à (a) commande de type LIVRAISON et
 *      (b) permission `delivery-boys` via appService.permissionChecker ;
 *   3. le dispatch porte un .catch (pas d'AxiosError non gérée).
 */

const src = readFileSync(
  resolve(process.cwd(), 'resources/js/components/admin/posOrders/PosOrderShowComponent.vue'),
  'utf8',
);

describe('PosOrderShowComponent — chargement livreurs gaté (A-RED-11)', () => {
  it('aucun dispatch deliveryBoy/lists inconditionnel avant le show', () => {
    // L'ancien pattern : mounted() { loading…; this.$store.dispatch('deliveryBoy/lists', …); this.$store.dispatch('posOrder/show'…
    const mountedStart = src.indexOf('mounted()');
    const showDispatch = src.indexOf("dispatch('posOrder/show'", mountedStart);
    const between = src.slice(mountedStart, showDispatch);
    expect(between).not.toContain("dispatch('deliveryBoy/lists'");
  });

  it('le chargement livreurs est conditionné au type LIVRAISON + permission delivery-boys', () => {
    const guardIdx = src.indexOf("appService.permissionChecker('delivery-boys')");
    expect(guardIdx).toBeGreaterThan(-1);
    // La garde type LIVRAISON accompagne la garde permission.
    const around = src.slice(guardIdx - 400, guardIdx + 400);
    expect(around).toMatch(/order_type\s*===\s*orderTypeEnum\.DELIVERY/);
    expect(around).toContain("dispatch('deliveryBoy/lists'");
  });

  it('le dispatch deliveryBoy/lists porte un catch (pas de rejection non gérée)', () => {
    const dispatchIdx = src.indexOf("dispatch('deliveryBoy/lists'");
    const after = src.slice(dispatchIdx, dispatchIdx + 400);
    expect(after).toMatch(/\.catch\(/);
  });
});
