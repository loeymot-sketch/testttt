// PWA gate (B3-M, 2026-06-10) — manifest + service worker + registration wiring.
//
// Run: node mobile/tests/node/pwa.test.mjs   (from repo root, like the other gates)

import assert from 'node:assert';
import fs from 'node:fs';
import path from 'node:path';
import { parse } from '@babel/parser';

const MOBILE = path.resolve(process.cwd(), 'mobile');
const read = (f) => fs.readFileSync(path.join(MOBILE, f), 'utf8');

let pass = 0, fail = 0;
const results = [];
function check(id, desc, fn) {
  try { fn(); pass++; results.push(`  PASS  ${id}  ${desc}`); }
  catch (e) { fail++; results.push(`  FAIL  ${id}  ${desc}\n          → ${e.message}`); }
}

// ── P-1 — manifest.webmanifest is valid JSON with the required installability fields ──
let manifest = null;
check('P-1.1', 'manifest.webmanifest parses as JSON', () => {
  manifest = JSON.parse(read('manifest.webmanifest'));
});
check('P-1.2', 'manifest required fields (name/short_name/display/orientation/start_url/scope)', () => {
  assert.ok(manifest, 'manifest did not parse');
  assert.strictEqual(manifest.name, 'Le Cayenne');
  assert.ok(manifest.short_name && manifest.short_name.length <= 12, 'short_name missing or > 12 chars');
  assert.strictEqual(manifest.display, 'standalone');
  assert.strictEqual(manifest.orientation, 'portrait');
  assert.strictEqual(manifest.start_url, './index.html');
  assert.strictEqual(manifest.scope, './');
  assert.strictEqual(manifest.lang, 'fr');
});
check('P-1.3', 'manifest palette mandate: theme #FF5A1F (NEVER #F4501E) + noir background', () => {
  assert.ok(manifest, 'manifest did not parse');
  assert.strictEqual(manifest.theme_color.toUpperCase(), '#FF5A1F');
  assert.strictEqual(manifest.background_color.toUpperCase(), '#0A0A0A');
  assert.ok(!JSON.stringify(manifest).toUpperCase().includes('#F4501E'),
    'Cayenne-backend red #F4501E is FORBIDDEN on mobile');
});
check('P-1.4', 'manifest declares 192 + 512 PNG icons and the files are real PNGs of that size', () => {
  assert.ok(manifest, 'manifest did not parse');
  for (const wanted of ['192x192', '512x512']) {
    const entry = (manifest.icons || []).find((i) => i.sizes === wanted && i.type === 'image/png');
    assert.ok(entry, `no ${wanted} PNG icon entry in manifest`);
    const buf = fs.readFileSync(path.join(MOBILE, entry.src));
    // PNG signature
    assert.strictEqual(buf.subarray(0, 8).toString('hex'), '89504e470d0a1a0a', `${entry.src} is not a PNG`);
    // IHDR width/height at offsets 16/20
    const px = parseInt(wanted, 10);
    assert.strictEqual(buf.readUInt32BE(16), px, `${entry.src} width ≠ ${px}`);
    assert.strictEqual(buf.readUInt32BE(20), px, `${entry.src} height ≠ ${px}`);
  }
  assert.ok((manifest.icons || []).some((i) => (i.purpose || '').includes('maskable')),
    'at least one maskable icon required for Android adaptive icons');
});

// ── P-2 — sw.js parses and implements the contract (precache / cleanup / strategies) ──
let sw = '';
check('P-2.1', 'sw.js parses as valid script (@babel/parser)', () => {
  sw = read('sw.js');
  parse(sw, { sourceType: 'script' });
});
check('P-2.2', 'sw.js is versioned and cleans up old caches at activate', () => {
  assert.ok(/SW_VERSION\s*=/.test(sw), 'no SW_VERSION constant');
  assert.ok(sw.includes("addEventListener('activate'"), 'no activate handler');
  assert.ok(sw.includes('caches.delete'), 'activate does not delete stale caches');
  assert.ok(sw.includes('caches.keys'), 'activate does not enumerate caches');
});
check('P-2.3', 'sw.js precaches the critical app shell (atomic addAll)', () => {
  assert.ok(sw.includes("addEventListener('install'"), 'no install handler');
  assert.ok(sw.includes('cache.addAll(SHELL_ASSETS)'), 'install does not addAll the shell');
  for (const critical of ['./index.html', './styles.css', './manifest.webmanifest',
    './data/menu.js', './data/loyalty.js', './api/storage.js', './screens-main.jsx',
    './assets/icons/icon-192.png']) {
    assert.ok(sw.includes(`'${critical}'`), `critical shell file ${critical} missing from precache`);
  }
});
check('P-2.4', 'every SHELL_ASSETS entry exists on disk (no 404 → install would fail atomically)', () => {
  const m = sw.match(/const SHELL_ASSETS = \[([\s\S]*?)\];/);
  assert.ok(m, 'SHELL_ASSETS array not found');
  const entries = [...m[1].matchAll(/'([^']+)'/g)].map((x) => x[1]).filter((e) => e !== './');
  assert.ok(entries.length >= 25, `suspiciously small shell (${entries.length} entries)`);
  for (const e of entries) {
    const f = e === './index.html' ? 'index.html' : e.replace(/^\.\//, '');
    assert.ok(fs.existsSync(path.join(MOBILE, f)), `precached file missing on disk: ${e}`);
  }
});
check('P-2.5', 'CDN (unpkg React/Babel + fonts) is runtime-cached for offline boot (documented debt)', () => {
  for (const host of ['unpkg.com', 'fonts.googleapis.com', 'fonts.gstatic.com']) {
    assert.ok(sw.includes(host), `CDN host ${host} not runtime-cached`);
  }
  assert.ok(/staleWhileRevalidate/.test(sw), 'no stale-while-revalidate strategy for CDN');
  assert.ok(/cacheFirst/.test(sw), 'no cache-first strategy for local runtime');
});

// ── P-3 — index.html wires manifest + registration + install prompt ──
let html = '';
check('P-3.1', 'index.html references the manifest + apple-touch-icon + aligned theme-color', () => {
  html = read('index.html');
  assert.ok(/<link rel="manifest" href="manifest\.webmanifest">/.test(html), 'manifest <link> missing');
  assert.ok(html.includes('apple-touch-icon'), 'apple-touch-icon missing (iOS A2HS)');
  assert.ok(/<meta name="theme-color" content="#FF5A1F">/.test(html), 'theme-color not aligned on #FF5A1F');
});
check('P-3.2', 'index.html registers sw.js (guarded http/https) + captures beforeinstallprompt', () => {
  assert.ok(html.includes("serviceWorker.register('sw.js')"), 'no SW registration');
  assert.ok(html.includes("'serviceWorker' in navigator"), 'registration not feature-guarded');
  assert.ok(html.includes("addEventListener('beforeinstallprompt'"), 'beforeinstallprompt not captured');
  assert.ok(html.includes("addEventListener('appinstalled'"), 'appinstalled not handled');
  assert.ok(html.includes('lc:pwa-can-install'), 'install availability event not dispatched');
});
check('P-3.3', 'ScreenProfile exposes the discrete install button (a11y label, palette vars)', () => {
  const screens = read('screens-main.jsx');
  assert.ok(screens.includes('pwa-install-btn'), 'install button testid missing in ScreenProfile');
  assert.ok(screens.includes("Installer l'application Le Cayenne"), 'aria-label missing');
  assert.ok(screens.includes('lc:pwa-can-install'), 'ScreenProfile not bound to availability event');
  assert.ok(screens.includes('promptInstall'), 'button does not trigger LC.pwa.promptInstall');
});

console.log('\nPWA gate (B3-M)\n');
for (const r of results) console.log(r);
console.log(`\n  ${pass} pass · ${fail} fail\n`);
if (fail > 0) process.exit(1);
