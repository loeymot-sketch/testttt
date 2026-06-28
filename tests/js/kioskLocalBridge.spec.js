import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  isLocalBridgeAvailable,
  printViaLocalBridge,
  buildBridgePayload,
  buildReceiptData,
  markPrintedOnce,
  LOCAL_BRIDGE_URL,
} from '../../resources/js/helpers/kioskPrinter';

// [BORNE-LOCAL-BRIDGE 2026-06-28] Pont d'impression WinUSB local sur la borne :
// un serveur HTTP `http://127.0.0.1:9100` (bridge.js / node-usb) écrit l'ESC/POS
// au SK1-31. La borne (Chrome, pas Electron) POSTe le ticket au pont au lieu de
// window.print(). Ces tests figent le contrat (détection /health + POST JSON +
// ASCII-fold codepage-safe).

const receipt = {
  restaurantName: 'Le Cayenne',
  queueNumber: 'A0001',
  orderDate: '28/06/2026 12:00',
  items: [
    { name: 'Méga Tacos', quantity: 2, unitPrice: 11.4, instruction: 'Sans oignon. Sauce algérienne' },
    { name: 'Coca-Cola', quantity: 1, unitPrice: 1.9, instruction: null },
  ],
  subtotal: 24.7,
  discount: 0,
  total: 24.7,
  paymentMethod: 'À la caisse',
  thankYou: 'Merci et à bientôt !',
};

describe('buildBridgePayload — codepage-safe (ASCII)', () => {
  it('mappe le reçu vers le JSON du pont {title,subtitle,order,lines,total,footer}', () => {
    const p = buildBridgePayload(receipt);
    expect(p.title).toBe('Le Cayenne');
    expect(p.order).toBe('A0001');
    expect(p.subtitle).toBe('28/06/2026 12:00');
    expect(Array.isArray(p.lines)).toBe(true);
    expect(p.total).toBe('24,70 EUR'); // FR money, ASCII (pas de €)
  });

  it('ASCII-fold les accents (codepage thermique imprévisible) : Méga→Mega, é→e', () => {
    const p = buildBridgePayload(receipt);
    const blob = p.lines.join('\n') + p.footer;
    expect(blob).not.toMatch(/[éèàùâêîôûç€]/i);
    expect(p.lines.join('\n')).toMatch(/Mega Tacos/);
    expect(p.footer).toMatch(/Merci et a bientot/);
  });

  it('inclut quantité, nom, prix par ligne + instructions en > ', () => {
    const p = buildBridgePayload(receipt);
    expect(p.lines.some(l => /2x Mega Tacos/.test(l))).toBe(true);
    expect(p.lines.some(l => /22,80 EUR/.test(l))).toBe(true); // 2 × 11,40
    expect(p.lines.some(l => /> Sans oignon/.test(l))).toBe(true);
  });

  it('robuste sur reçu vide (jamais throw)', () => {
    expect(() => buildBridgePayload({})).not.toThrow();
    expect(() => buildBridgePayload(null)).not.toThrow();
  });
});

describe('G8/G11 — thankYou propagé + bloc fidélité (audit adversaire)', () => {
  it('[G8] buildReceiptData propage thankYou jusqu\'au footer du pont (était droppé)', () => {
    const r = buildReceiptData({ restaurantName: 'Le Cayenne', queueNumber: 'A1', cartItems: [], total: 5, thankYou: 'Au revoir Le Cayenne !' });
    expect(r.thankYou).toBe('Au revoir Le Cayenne !');
    expect(buildBridgePayload(r).footer).toMatch(/Au revoir Le Cayenne/);
  });
  it('[G11] buildBridgePayload imprime les points fidélité + nom', () => {
    const p = buildBridgePayload({ restaurantName: 'X', queueNumber: 'A1', items: [], total: 5, loyaltyPointsEarned: 5, loyaltyCustomerName: 'Marie' });
    expect(p.lines.some(l => /\+5 pts - Marie/.test(l))).toBe(true);
  });
  it('[G11] pas de ligne fidélité si 0 point', () => {
    const p = buildBridgePayload({ items: [], total: 5, loyaltyPointsEarned: 0 });
    expect(p.lines.some(l => /pts/.test(l))).toBe(false);
  });
});

describe('markPrintedOnce — garde anti-double module-level', () => {
  it('true au 1er appel, false aux suivants (même commande)', () => {
    const ref = 'A-ONCE-' + Math.floor(performance.now());
    expect(markPrintedOnce(ref)).toBe(true);
    expect(markPrintedOnce(ref)).toBe(false);
    expect(markPrintedOnce(ref)).toBe(false);
  });
  it('false sur ref vide/null (jamais imprimer sans référence)', () => {
    expect(markPrintedOnce('')).toBe(false);
    expect(markPrintedOnce(null)).toBe(false);
    expect(markPrintedOnce(undefined)).toBe(false);
  });
  it('commandes différentes → chacune imprimable une fois', () => {
    const a = 'A-' + Math.floor(performance.now()) + '-x';
    const b = 'B-' + Math.floor(performance.now()) + '-y';
    expect(markPrintedOnce(a)).toBe(true);
    expect(markPrintedOnce(b)).toBe(true);
    expect(markPrintedOnce(a)).toBe(false);
  });
});

describe('isLocalBridgeAvailable — détection du pont via /health', () => {
  afterEach(() => { vi.restoreAllMocks(); });

  it('true quand /health répond "UP"', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('UP') });
    expect(await isLocalBridgeAvailable()).toBe(true);
    expect(global.fetch).toHaveBeenCalledWith(LOCAL_BRIDGE_URL + '/health', expect.any(Object));
  });

  it('false quand le pont est injoignable (fetch reject)', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('ECONNREFUSED'));
    expect(await isLocalBridgeAvailable()).toBe(false);
  });

  it('false quand /health ne renvoie pas UP', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('nope') });
    expect(await isLocalBridgeAvailable()).toBe(false);
  });
});

describe('printViaLocalBridge — POST du ticket au pont', () => {
  afterEach(() => { vi.restoreAllMocks(); });

  it('POST JSON au pont et renvoie {method:"local-bridge"} sur 200', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('PRINTED') });
    const r = await printViaLocalBridge(receipt);
    expect(r).toEqual({ method: 'local-bridge' });
    const [url, opts] = global.fetch.mock.calls[0];
    expect(url).toBe(LOCAL_BRIDGE_URL + '/');
    expect(opts.method).toBe('POST');
    expect(opts.headers['Content-Type']).toMatch(/application\/json/);
    expect(JSON.parse(opts.body).order).toBe('A0001');
  });

  it('null si le pont échoue (500) → fall-through window.print', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: false, text: () => Promise.resolve('ERR') });
    expect(await printViaLocalBridge(receipt)).toBeNull();
  });

  it('null si fetch reject (jamais throw)', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('boom'));
    expect(await printViaLocalBridge(receipt)).toBeNull();
  });
});
