import { describe, it, expect, vi, afterEach } from 'vitest';
import {
  b64ToBytes,
  isCaisseBridgeAvailable,
  printEscPosViaCaisseBridge,
  printEscPosViaKitchenBridge,
  markPrintedOnceCaisse,
  _resetPrintedCaisse,
  _resetCaisseBridgeHealthCache,
  CAISSE_BRIDGE_URL,
  KITCHEN_BRIDGE_URL,
} from '../../resources/js/helpers/posLocalPrinter';

// [CAISSE-BRIDGE 2026-06-28] Impression silencieuse caisse via pont local : le serveur
// rend l'ESC/POS (base64), le helper le décode + POST RAW au pont 127.0.0.1:9100/raw.

describe('b64ToBytes', () => {
  it('décode le base64 en octets exacts (passthrough fiscal)', () => {
    const bytes = b64ToBytes(btoa('\x1B@HELLO\x1DV')); // ESC @ ... GS V
    expect(bytes[0]).toBe(0x1B);
    expect(bytes[1]).toBe(0x40);
    expect(String.fromCharCode(...bytes.slice(2, 7))).toBe('HELLO');
  });
  it('vide/null → Uint8Array vide (jamais throw)', () => {
    expect(b64ToBytes('').length).toBe(0);
    expect(b64ToBytes(null).length).toBe(0);
  });
});

describe('isCaisseBridgeAvailable', () => {
  // [PRINT-INSTANT 2026-07-06] Le health est MÉMOÏSÉ (TTL) → purge entre les tests.
  afterEach(() => { vi.restoreAllMocks(); _resetCaisseBridgeHealthCache(); });
  it('true quand /health → UP', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('UP') });
    expect(await isCaisseBridgeAvailable()).toBe(true);
    expect(global.fetch).toHaveBeenCalledWith(CAISSE_BRIDGE_URL + '/health', expect.any(Object));
  });
  it('false si pont injoignable', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('ECONNREFUSED'));
    expect(await isCaisseBridgeAvailable()).toBe(false);
  });
  it('MÉMOÏSÉ : 2 appels rapprochés = 1 seul fetch /health (TTL 20 s)', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('UP') });
    expect(await isCaisseBridgeAvailable()).toBe(true);
    expect(await isCaisseBridgeAvailable()).toBe(true);
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });
  it('force:true bypass le cache (re-print manuel après relance du pont)', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('ECONNREFUSED'));
    expect(await isCaisseBridgeAvailable()).toBe(false);
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('UP') });
    expect(await isCaisseBridgeAvailable(800, { force: true })).toBe(true);
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });
});

describe('printEscPosViaCaisseBridge', () => {
  afterEach(() => vi.restoreAllMocks());
  it('POST RAW (octet-stream) au pont et renvoie {ok} sur 200', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('PRINTED') });
    const r = await printEscPosViaCaisseBridge(btoa('\x1B@TICKET'));
    expect(r).toEqual({ ok: true, method: 'caisse-bridge' });
    const [url, opts] = global.fetch.mock.calls[0];
    expect(url).toBe(CAISSE_BRIDGE_URL + '/raw');
    expect(opts.method).toBe('POST');
    expect(opts.headers['Content-Type']).toMatch(/octet-stream/);
    expect(opts.body).toBeInstanceOf(Uint8Array); // octets bruts, pas de re-encodage
  });
  it('null si pont échoue → fall-through window.print', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: false, text: () => Promise.resolve('ERR') });
    expect(await printEscPosViaCaisseBridge(btoa('x'))).toBeNull();
  });
  it('null sur b64 vide (rien à imprimer)', async () => {
    global.fetch = vi.fn();
    expect(await printEscPosViaCaisseBridge('')).toBeNull();
    expect(global.fetch).not.toHaveBeenCalled();
  });
});

describe('printEscPosViaKitchenBridge — timeout cuisine (D5)', () => {
  afterEach(() => vi.restoreAllMocks());

  // [D5 2026-08-15] Le pont cuisine répond le RÉSULTAT RÉEL (borné 15000ms côté serveur,
  // kitchen-bridge.js:54), pas un 202 optimiste comme la caisse. Un timeout client trop
  // court abandonne AVANT que le pont ait fini d'imprimer → faux échec → boucle de
  // réimpression. Régression réelle introduite le 2026-08-14 (e2d2ca3b4) : ce test aurait
  // dû rougir ce soir-là (le fichier n'avait AUCUNE couverture kitchen avant ce GOAL).
  it('POST RAW au pont CUISINE (pas caisse) et renvoie {ok, method:kitchen-bridge} sur 200', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('PRINTED') });
    const r = await printEscPosViaKitchenBridge(btoa('\x1B@TICKET'));
    expect(r).toEqual({ ok: true, method: 'kitchen-bridge' });
    const [url, opts] = global.fetch.mock.calls[0];
    expect(url).toBe(KITCHEN_BRIDGE_URL + '/raw');
    expect(opts.method).toBe('POST');
  });

  it('le timeout par défaut du pont cuisine (20000ms) est STRICTEMENT supérieur à celui de la caisse (3000ms)', async () => {
    // Capture le délai réellement passé à setTimeout par fetchWithTimeout (interne,
    // non-exporté) — c'est la seule façon d'observer le VRAI budget d'attente client,
    // pas une valeur déclarative qui pourrait diverger du code exécuté.
    const setTimeoutSpy = vi.spyOn(global, 'setTimeout');
    global.fetch = vi.fn(() => new Promise(() => {})); // ne résout jamais — on ne veut QUE le setup du timer

    printEscPosViaKitchenBridge(btoa('\x1B@TICKET'));
    printEscPosViaCaisseBridge(btoa('\x1B@TICKET'));
    await Promise.resolve(); // laisse les deux appels async poser leur setTimeout

    const delays = setTimeoutSpy.mock.calls.map(([, ms]) => ms).filter((ms) => Number.isFinite(ms));
    const kitchenDelay = Math.max(...delays);
    const caisseDelay = Math.min(...delays);

    expect(kitchenDelay).toBe(20000);
    expect(caisseDelay).toBe(3000);
    // Garde anti-régression structurelle : même si les valeurs par défaut changent un
    // jour, la cuisine (résultat réel, borné 15s serveur) doit TOUJOURS attendre plus
    // longtemps que la caisse (ack 202 immédiat) — sinon le bug de ce soir revient.
    expect(kitchenDelay).toBeGreaterThan(caisseDelay);
    expect(kitchenDelay).toBeGreaterThan(15000); // > timeout serveur kitchen-bridge.js (15000ms)
  });

  it('respecte window.foodkingConfig.kitchenBridgeRawTimeoutMs si posé (override explicite)', async () => {
    const setTimeoutSpy = vi.spyOn(global, 'setTimeout');
    global.fetch = vi.fn(() => new Promise(() => {}));
    window.foodkingConfig = { kitchenBridgeRawTimeoutMs: 9999 };

    printEscPosViaKitchenBridge(btoa('\x1B@TICKET'));
    await Promise.resolve();

    const delays = setTimeoutSpy.mock.calls.map(([, ms]) => ms).filter((ms) => Number.isFinite(ms));
    expect(delays).toContain(9999);
    delete window.foodkingConfig;
  });

  it('null si b64 vide (rien à imprimer) — jamais de fetch', async () => {
    global.fetch = vi.fn();
    expect(await printEscPosViaKitchenBridge('')).toBeNull();
    expect(global.fetch).not.toHaveBeenCalled();
  });
});

describe('markPrintedOnceCaisse — anti-double caisse', () => {
  afterEach(() => { _resetPrintedCaisse(); try { window.localStorage.clear(); } catch (_) {} });
  it('1 ticket max par (commande,type) — re-print bloqué', () => {
    expect(markPrintedOnceCaisse(5176, 'client')).toBe(true);
    expect(markPrintedOnceCaisse(5176, 'client')).toBe(false);
    expect(markPrintedOnceCaisse(5176, 'kitchen')).toBe(true); // type différent = OK
  });
  it('false sans référence', () => {
    expect(markPrintedOnceCaisse('', 'client')).toBe(false);
    expect(markPrintedOnceCaisse(null, 'client')).toBe(false);
  });
});
