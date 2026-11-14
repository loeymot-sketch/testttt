// api/catalog-sync.js — la carte et les horaires de l'app suivent le SERVEUR (2026-11-14).
//
// Pourquoi : la carte de l'app était figée dans data/menu.js. Mesuré le 2026-11-14 :
//   - « Galette Normale 6,50 € » n'existe pas au serveur (« Galette Classique 7,40 € ») → tout panier
//     contenant une galette était REFUSÉ en entier (« Article introuvable côté caisse ») ;
//   - un produit en rupture (Bol Riz) ou une boisson de menu en rupture restait commandable → refus 422
//     au moment de payer ;
//   - horaires « 11h — 00h » et « Ouvert maintenant » écrits en dur (vrais horaires 18h – 00h30).
//
// Règle : le serveur fait foi pour le NOM, le PRIX et la DISPONIBILITÉ de chaque produit déjà présent
// dans la carte de l'app (la composition des assistants reste celle de data/menu.js). Un produit que
// le serveur ne connaît pas est retiré de l'app : il ne pourrait pas être commandé. Le prix encaissé
// reste calculé par le serveur (PricingService) ; ceci n'aligne que l'AFFICHAGE.
// Sans réseau : la carte embarquée reste affichée, marquée « non synchronisée » (LC.catalog.status).
(function () {
  'use strict';
  window.LC = window.LC || {};
  var LCC = window.LC.config || {};

  function norm(s) {
    return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/\s+/g, ' ').trim();
  }
  function base() { return String(LCC.apiBase || '').replace(/\/+$/, ''); }
  function headers() {
    var h = { Accept: 'application/json' };
    if (LCC.apiKey) h['x-api-key'] = LCC.apiKey;
    return h;
  }
  function getJson(path, timeoutMs) {
    var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
    var t = ctrl ? setTimeout(function () { ctrl.abort(); }, timeoutMs || 8000) : null;
    return fetch(base() + path, { headers: headers(), signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) { if (!r.ok) throw { kind: 'http', status: r.status }; return r.json(); })
      .finally(function () { if (t) clearTimeout(t); });
  }

  // Boissons de formule (ids d-*) → slug du produit Boissons de la carte (même table que api/client.js).
  var DRINK_ID_TO_SLUG = {
    'd-coca': 'coca', 'd-coca-zero': 'coca-zero', 'd-fanta': 'fanta', 'd-sprite': 'sprite',
    'd-oasis': 'oasis', 'd-orangina': 'orangina', 'd-eau': 'eau-plate', 'd-capri': 'capri-sun',
    'd-coca-cherry': 'coca-cherry', 'd-tropico': 'tropico', 'd-ice-tea-peche': 'ice-tea-peche',
    'd-fanta-citron': 'fanta-citron', 'd-fuze-tea': 'fuze-tea', 'd-hawai': 'hawai', 'd-perrier': 'perrier',
  };

  var state = {
    status: 'pending',      // pending | live | offline
    syncedAt: null,
    removed: [],            // noms retirés (inconnus du serveur)
    hours: null,            // réponse /api/frontend/horaires (data)
  };
  window.LC.catalog = state;

  function emit() {
    try { window.dispatchEvent(new CustomEvent('lc:catalog', { detail: { status: state.status } })); } catch (_) {}
  }

  // Applique la liste serveur à un tableau d'items (mutation EN PLACE : les écrans gardent la même référence).
  function applyTo(arr, byName) {
    if (!Array.isArray(arr)) return;
    for (var i = arr.length - 1; i >= 0; i--) {
      var it = arr[i];
      var srv = byName[norm(it.name)];
      if (!srv) {
        if (state.removed.indexOf(it.name) === -1) state.removed.push(it.name);
        arr.splice(i, 1);
        continue;
      }
      var p = Number(srv.price);
      if (isFinite(p) && p >= 0) it.price = Math.round(p * 100) / 100;
      it.name = srv.name;
      it.server_id = srv.id;
      it.available = (Number(srv.status) === 5) && srv.is_available !== false;
      it.availability_reason = srv.availability_reason || null;
    }
  }

  function applyCatalogue(rows) {
    var byName = {};
    rows.forEach(function (r) { if (r && r.name) byName[norm(r.name)] = r; });
    var M = window.LC.menu;
    if (!M) return;
    state.removed = [];
    // Sauces vendues (« Sauce Ketchup » …) : seules proposées comme sauce frites de formule.
    state.sauceNames = {};
    rows.forEach(function (r) {
      if (r && /^sauce\s/i.test(r.name || '') && Number(r.status) === 5) state.sauceNames[norm(r.name).replace(/^sauce\s+/, '')] = true;
    });
    applyTo(M.items, byName);
    applyTo(window.ITEMS, byName);
    // Boissons de formule : indisponibles si le produit Boissons correspondant l'est.
    (M.formuleDrinks || []).forEach(function (d) {
      var slug = DRINK_ID_TO_SLUG[d.id];
      var item = slug && M.items.find(function (x) { return x.slug === slug; });
      d.available = item ? item.available !== false : !!byName[norm(d.name)];
    });
  }

  function syncCatalogue() {
    // branch_id OBLIGATOIRE : sans lui, une rupture posée pour le restaurant (item_branch_availability)
    // n'apparaît pas dans la liste (mesuré : Coca en rupture locale renvoyé « disponible » sans branch_id).
    return getJson('/api/frontend/item?branch_id=' + encodeURIComponent(LCC.branchId || 1), 9000).then(function (r) {
      var rows = (r && (r.data || r)) || [];
      if (!Array.isArray(rows) || !rows.length) throw { kind: 'empty' };
      applyCatalogue(rows);
      state.status = 'live';
      state.syncedAt = Date.now();
    }).catch(function () {
      if (state.status !== 'live') state.status = 'offline';
    });
  }

  function syncHours() {
    return getJson('/api/frontend/horaires', 8000).then(function (r) {
      state.hours = (r && r.data) || null;
    }).catch(function () { /* horaires inconnus : l'accueil n'affirme rien */ });
  }

  // Résumé lisible des horaires, ou null si inconnus.
  window.LC.hoursInfo = function () {
    var h = state.hours;
    if (!h) return null;
    var open = h.ouvert === true;
    var label;
    if (open) label = 'Ouvert maintenant';
    else if (h.fermeture_exceptionnelle) label = 'Fermé aujourd’hui';
    else if (h.prochaine_ouverture) {
      var d = new Date(h.prochaine_ouverture);
      var hh = isNaN(d) ? '' : d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris' }).replace(':', 'h');
      label = hh ? 'Fermé · ouvre à ' + hh : 'Fermé pour le moment';
    } else label = 'Fermé pour le moment';
    return { open: open, label: label, summary: h.resume || null };
  };

  function run() {
    return Promise.all([syncCatalogue(), syncHours()]).then(emit, emit);
  }
  window.LC.catalogSync = { run: run, applyCatalogue: applyCatalogue };

  run();
  // Revenir sur l'app (premier plan) relit la carte : une rupture posée en caisse apparaît sans relancer.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && (!state.syncedAt || Date.now() - state.syncedAt > 60000)) run();
  });
})();
