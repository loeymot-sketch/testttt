import { describe, it, expect, vi, afterEach } from 'vitest';
import {
  b64ToBytes,
  printEscPosViaKitchenBridge,
  hasKitchenPrinted,
  markKitchenPrinted,
  seedKitchenPrinted,
  _resetPrintedKitchen,
  KITCHEN_BRIDGE_URL,
} from '../../resources/js/helpers/kitchenLocalPrinter';

// [KITCHEN-BRIDGE 2026-07-09] Auto-impression silencieuse du ticket CUISINE via le
// pont local : le KDS récupère l'ESC/POS cuisine (base64), le helper le décode +
// POST RAW au pont 127.0.0.1:9101/raw. Dé-dup persistée localStorage.

describe('KITCHEN_BRIDGE_URL', () => {
  it('cible le port cuisine 9101 (distinct de la caisse 9100)', () => {
    expect(KITCHEN_BRIDGE_URL).toMatch(/127\.0\.0\.1:9101$/);
  });
});

describe('b64ToBytes (cuisine)', () => {
  it('décode le base64 en octets exacts (passthrough fiscal)', () => {
    const bytes = b64ToBytes(btoa('\x1B@KDS\x1DV'));
    expect(bytes[0]).toBe(0x1B);
    expect(bytes[1]).toBe(0x40);
    expect(String.fromCharCode(...bytes.slice(2, 5))).toBe('KDS');
  });
  it('vide/null → Uint8Array vide (jamais throw)', () => {
    expect(b64ToBytes('').length).toBe(0);
    expect(b64ToBytes(null).length).toBe(0);
  });
});

describe('printEscPosViaKitchenBridge', () => {
  afterEach(() => vi.restoreAllMocks());
  it('POST RAW (octet-stream) au pont cuisine et renvoie {ok} sur 202', async () => {
    global.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('') });
    const r = await printEscPosViaKitchenBridge(btoa('\x1B@KITCHEN'));
    expect(r).toEqual({ ok: true, method: 'kitchen-bridge' });
    const [url, opts] = global.fetch.mock.calls[0];
    expect(url).toBe(KITCHEN_BRIDGE_URL + '/raw');
    expect(opts.method).toBe('POST');
    expect(opts.headers['Content-Type']).toMatch(/octet-stream/);
    expect(opts.body).toBeInstanceOf(Uint8Array); // octets bruts, pas de re-encodage
  });
  it('null si pont échoue/éteint → best-effort, jamais throw', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('ECONNREFUSED'));
    expect(await printEscPosViaKitchenBridge(btoa('x'))).toBeNull();
  });
  it('null sur b64 vide (rien à imprimer)', async () => {
    global.fetch = vi.fn();
    expect(await printEscPosViaKitchenBridge('')).toBeNull();
    expect(global.fetch).not.toHaveBeenCalled();
  });
});

describe('dé-dup cuisine — exactement 1 ticket par commande', () => {
  afterEach(() => { _resetPrintedKitchen(); try { window.localStorage.clear(); } catch (_) {} });

  it('markKitchenPrinted : 1er appel true (imprimer), 2e false (déjà imprimé)', () => {
    expect(markKitchenPrinted(5561)).toBe(true);
    expect(markKitchenPrinted(5561)).toBe(false);
    expect(hasKitchenPrinted(5561)).toBe(true);
  });

  it('false / true logiques sans référence (id null)', () => {
    expect(markKitchenPrinted(null)).toBe(false);
    expect(hasKitchenPrinted(null)).toBe(true); // null = « déjà traité » → jamais imprimé
  });

  it('persiste en localStorage → survit au reload (nouvelle instance mémoire)', () => {
    markKitchenPrinted(42);
    // Simule un reload : la garde mémoire est vidée mais localStorage reste.
    _resetPrintedKitchen();
    expect(hasKitchenPrinted(42)).toBe(true);
    expect(markKitchenPrinted(42)).toBe(false); // pas de ré-impression au reload
  });

  it('seedKitchenPrinted : marque le backlog SANS imprimer (pas de re-print massif)', () => {
    seedKitchenPrinted([100, 101, 102]);
    expect(hasKitchenPrinted(100)).toBe(true);
    expect(hasKitchenPrinted(101)).toBe(true);
    // Une NOUVELLE commande hors backlog reste à imprimer.
    expect(hasKitchenPrinted(999)).toBe(false);
    expect(markKitchenPrinted(999)).toBe(true);
  });

  it('clé localStorage = kds.printedKitchenIds', () => {
    markKitchenPrinted(7);
    const raw = window.localStorage.getItem('kds.printedKitchenIds');
    expect(raw).toBeTruthy();
    expect(JSON.parse(raw)).toContain('7');
  });
});
