// Le Cayenne — Service worker (B3-M PWA, 2026-06-10)
//
// Strategy:
//   • PRECACHE (install, atomic addAll — install fails if any shell file 404s):
//     the full app shell = index.html + styles + every local script the page
//     loads (data layer, hooks, components, screens) + manifest + icons.
//   • RUNTIME same-origin (e.g. assets/menu/*.png — 251 images, ~lazy):
//     cache-first with network fallback. NOT precached on purpose: blocking
//     install on ~15-25 MB of menu images would make first install fragile;
//     each image is cached on first view and served offline afterwards.
//   • RUNTIME CDN (unpkg React/ReactDOM/Babel-standalone + Google Fonts):
//     stale-while-revalidate so the app boots offline after the first online
//     visit. ⚠️ KNOWN DEBT (documented, backlog): the app is prototype-grade
//     Babel-in-browser; real offline-first hardening = Vite/esbuild prod build
//     bundling React locally, which removes the CDN dependency entirely.
//
// Versioning: bump SW_VERSION on ANY change to the precache list or strategy;
// activate() deletes every cache not in CURRENT_CACHES.

const SW_VERSION = 'v1';
const PRECACHE = `lc-shell-${SW_VERSION}`;
const RUNTIME_LOCAL = `lc-runtime-${SW_VERSION}`;
const RUNTIME_CDN = `lc-cdn-${SW_VERSION}`;
const CURRENT_CACHES = [PRECACHE, RUNTIME_LOCAL, RUNTIME_CDN];

// App shell — mirrors the <script>/<link> graph of index.html exactly.
const SHELL_ASSETS = [
  './',
  './index.html',
  './manifest.webmanifest',
  './styles.css',
  './redesigns-styles.css',
  // Data layer (load order matters in the page, not in the cache)
  './api/storage.js',
  './data/menu.js',
  './data/loyalty.js',
  './data/loyaltyRewardState.js',
  './data/orders.js',
  './data/user.js',
  './data/wallet-spec.js',
  './data/dev-helpers.js',
  // Shared atoms + icons
  './image-slot.js',
  './icons.jsx',
  './shared.jsx',
  // Hooks & components
  './hooks/useCountdown.js',
  './hooks/useLoyaltyQR.js',
  './components/BarcodeMock.jsx',
  './components/LoyaltyQR.jsx',
  './components/WizardRedeem.jsx',
  // Screens
  './screens-onboarding.jsx',
  './screens-item-steps.jsx',
  './screens-main.jsx',
  './screens-modals.jsx',
  // PWA icons
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
];

const CDN_HOSTS = ['unpkg.com', 'fonts.googleapis.com', 'fonts.gstatic.com'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(PRECACHE)
      .then((cache) => cache.addAll(SHELL_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(
        names
          .filter((n) => n.startsWith('lc-') && !CURRENT_CACHES.includes(n))
          .map((n) => caches.delete(n))
      ))
      .then(() => self.clients.claim())
  );
});

// cache-first with network fallback (local shell + same-origin runtime)
function cacheFirst(request, cacheName) {
  return caches.match(request).then((hit) => {
    if (hit) return hit;
    return fetch(request).then((resp) => {
      if (resp && (resp.ok || resp.type === 'opaque')) {
        const copy = resp.clone();
        caches.open(cacheName).then((c) => c.put(request, copy));
      }
      return resp;
    });
  });
}

// stale-while-revalidate (CDN: React/Babel/fonts)
function staleWhileRevalidate(request, cacheName) {
  return caches.open(cacheName).then((cache) =>
    cache.match(request).then((hit) => {
      const refresh = fetch(request)
        .then((resp) => {
          if (resp && (resp.ok || resp.type === 'opaque')) cache.put(request, resp.clone());
          return resp;
        })
        .catch(() => hit); // offline: keep serving the stale copy
      return hit || refresh;
    })
  );
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  let url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

  if (url.origin === self.location.origin) {
    // Shell hits the precache, everything else local (menu images, uploads)
    // is runtime cache-first.
    event.respondWith(cacheFirst(req, RUNTIME_LOCAL));
    return;
  }

  if (CDN_HOSTS.includes(url.hostname)) {
    event.respondWith(staleWhileRevalidate(req, RUNTIME_CDN));
  }
  // Other cross-origin requests: let the network handle them untouched.
});
