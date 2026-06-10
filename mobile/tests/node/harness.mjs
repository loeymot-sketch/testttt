// Node technical-layer harness for the Le Cayenne data layer.
//
// The app's data files are browser IIFEs that attach to window.LC.*. This loader
// stubs the minimal browser globals they touch (window, localStorage, crypto,
// CustomEvent, dispatch/add/removeEventListener) and evaluates them in global
// scope so `window.LC` resolves to globalThis.LC — giving us a runnable harness
// for the TECHNICAL (T) layer of the GOAL's E2E gate without a browser.
//
// Covers data-rooted findings (prices, allergens, slugs, progress, ids). The
// VISUAL (V) + DOM-INTERFACE (I) layers live in the Playwright specs (deferred
// to a disk-healthy environment — see tests/mobile-e2e/).

import fs from 'node:fs';
import path from 'node:path';
import { webcrypto } from 'node:crypto';

export function loadLC() {
  const store = new Map();
  globalThis.localStorage = {
    getItem: (k) => (store.has(k) ? store.get(k) : null),
    setItem: (k, v) => store.set(k, String(v)),
    removeItem: (k) => store.delete(k),
    clear: () => store.clear(),
  };
  globalThis.window = globalThis;
  if (!globalThis.crypto) globalThis.crypto = webcrypto;
  globalThis.CustomEvent = class CustomEvent {
    constructor(name, opts) { this.type = name; this.detail = (opts && opts.detail) || {}; }
  };
  globalThis.dispatchEvent = () => {};
  globalThis.addEventListener = () => {};
  globalThis.removeEventListener = () => {};

  const dir = path.resolve(process.cwd(), 'mobile');
  // Same order as index.html script tags (storage before loyalty hydrate, dev last).
  const ORDER = [
    'api/storage.js',
    'data/menu.js',
    'data/loyalty.js',
    'data/loyaltyRewardState.js',
    'data/orders.js',
    'data/user.js',
    'data/wallet-spec.js',
    'data/dev-helpers.js',
  ];
  for (const f of ORDER) {
    const code = fs.readFileSync(path.join(dir, f), 'utf8');
    // new Function(...)() runs in global scope; bare `window` => globalThis.window.
    // eslint-disable-next-line no-new-func
    new Function(code)();
  }
  return globalThis.LC;
}
