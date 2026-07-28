/* [VERSION-BEACON 2026-07-28 — GOAL robustesse, demande owner « ma caisse n'est pas à jour »]
 * Les écrans du resto (caisse SPA, KDS, OSS, borne) tournent en continu : après un
 * deploy, l'ANCIEN code reste en mémoire tant que personne ne recharge la page — le
 * serveur est à jour mais l'écran non (même famille que le chunk périmé borne).
 * Ce beacon compare le hash mix de /js/app.js (mix-manifest.json, no-store) entre le
 * boot et maintenant (poll 5 min + retour d'onglet). S'il change → bandeau discret
 * « Mettre à jour » (palette Cayenne). JAMAIS de reload forcé : une commande peut
 * être en cours à la caisse — l'humain choisit le moment.
 * Hand-written, ES5, zéro dépendance. Chargé par master.blade.php (asset + time()).
 */
(function () {
  'use strict';
  var bootId = null;
  var POLL_MS = 5 * 60 * 1000;

  function currentId(cb) {
    try {
      fetch('/mix-manifest.json?_=' + Date.now(), { cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (m) { cb(m && m['/js/app.js'] ? String(m['/js/app.js']) : null); })
        .catch(function () { cb(null); });
    } catch (e) { cb(null); }
  }

  // indirection testable (vitest ne peut pas mocker location.reload)
  window.__fkBeaconReload = window.__fkBeaconReload || function () { window.location.reload(); };

  function showBanner() {
    if (document.getElementById('fk-version-banner')) return;
    var d = document.createElement('div');
    d.id = 'fk-version-banner';
    d.setAttribute('role', 'status');
    d.style.cssText = 'position:fixed;bottom:14px;left:50%;transform:translateX(-50%);z-index:2147483000;' +
      'background:#1A1A1A;color:#fff;padding:10px 16px;border-radius:12px;' +
      'font:600 14px system-ui,-apple-system,sans-serif;display:flex;gap:12px;align-items:center;' +
      'box-shadow:0 8px 24px rgba(0,0,0,.35);max-width:92vw';
    var span = document.createElement('span');
    span.textContent = '🔄 Nouvelle version disponible';
    var btn = document.createElement('button');
    btn.textContent = 'Mettre à jour';
    btn.style.cssText = 'background:#F4501E;color:#fff;border:0;border-radius:8px;padding:6px 12px;' +
      'font:700 14px system-ui,-apple-system,sans-serif;cursor:pointer';
    btn.onclick = function () { window.__fkBeaconReload(); };
    d.appendChild(span);
    d.appendChild(btn);
    (document.body || document.documentElement).appendChild(d);
  }

  function check() {
    currentId(function (id) {
      if (!id) return;               // manifest illisible (dev sans build…) → silencieux
      if (bootId === null) { bootId = id; return; }
      if (id !== bootId) showBanner();
    });
  }

  check(); // fixe bootId
  setInterval(check, POLL_MS);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') check();
  });
})();
