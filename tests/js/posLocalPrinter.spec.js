import { describe, it, expect, vi, afterEach } from 'vitest';
import {
  b64ToBytes,
  isCaisseBridgeAvailable,
  printEscPosViaCaisseBridge,
  markPrintedOnceCaisse,
  _resetPrintedCaisse,
  _resetCaisseBridgeHealthCache,
  CAISSE_BRIDGE_URL,
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
