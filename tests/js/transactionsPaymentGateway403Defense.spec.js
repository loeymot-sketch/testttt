import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [DISPUTE-R1 B-R1-19 2026-06-12] — P0 (part FRONT, défense en profondeur) :
 * /admin/transactions crashait pour le rôle Branch Manager.
 *
 * Constat adversarial : `GET /api/admin/setting/payment-gateway` est gaté
 * `permission:settings` → 403 pour BM ; le dispatch `paymentGateway/lists`
 * au mounted() n'avait AUCUN catch → `PAGEERROR AxiosError` non interceptée
 * à CHAQUE visite + filtre « Mode de paiement » vide cassé.
 * (Le fix d'authz backend appartient à H1 — ici on verrouille la défense
 * front : jamais de rejection non gérée, dégradation propre.)
 */

const src = readFileSync(
  resolve(process.cwd(), 'resources/js/components/admin/transactions/TransactionListComponent.vue'),
  'utf8',
);

describe('TransactionListComponent — défense 403 payment-gateway (B-R1-19)', () => {
  it('le dispatch paymentGateway/lists porte un catch (plus de PAGEERROR AxiosError)', () => {
    const idx = src.indexOf("dispatch('paymentGateway/lists'");
    expect(idx).toBeGreaterThan(-1);
    const after = src.slice(idx, idx + 400);
    expect(after).toMatch(/\.catch\(\(\)\s*=>\s*\{/);
    expect(after).toContain('paymentGatewaysUnavailable = true');
  });

  it('le filtre « Mode de paiement » est masqué proprement quand la liste est inaccessible', () => {
    expect(src).toMatch(/v-if="!paymentGatewaysUnavailable"/);
    expect(src).toMatch(/paymentGatewaysUnavailable:\s*false/);
  });

  it('la liste des transactions garde son propre catch (erreur FR surfacée, pas avalée)', () => {
    const idx = src.indexOf("dispatch('transaction/lists'");
    const after = src.slice(idx, idx + 500);
    expect(after).toMatch(/\.catch\(\(err\)\s*=>\s*\{/);
    expect(after).toContain("message.no_data_available");
  });
});
