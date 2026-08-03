// mobile/api/client.js — [GOAL-SYNC 2026-07-08] Couche réseau RÉELLE mobile → API FoodKing / caisse.
//   Expose window.LC.mobileApi. Portage du pattern WEB (/Users/…/web/api.js) adapté au shape
//   buildLineItem MOBILE (meatIds / painId / sauceIds / cruditeRemoved / supplementIds /
//   bolSupplementIds / extraMeatIds / menuChoice / drinkId / bolDrinkId / fritesStyleId…).
//
//   Auth  : guest-signup OTP → token Sanctum kiosk:order stocké via LC.storage.setAuth().
//   Headers : X-API-Key (LC.config.apiKey) + Authorization: Bearer + X-Idempotency-Key.
//   Prix  : recalculés serveur (PricingService SSOT) — on n'envoie JAMAIS de prix client.
//   Résolution PAR NOM canonique (norm() identique au web) vers les vrais ids DB
//   (item / variations attrs 1-2-5-6-8 / extras crudite-supplement-supplement_bol / addons roles).
//
//   Erreurs typées : {kind:'network'|'http'|'auth'|'resolve', message FR} — API down ⇒
//   kind:'network' et les écrans retombent sur le mode local V0 (dégradation douce).
//
//   ⚠ Script CLASSIQUE (IIFE, zéro import) chargé APRÈS api/storage.js + data/menu.js et
//     AVANT React. window.LC.config est défini par index.html AVANT tout script (contrat §4) ;
//     fallbacks sûrs sinon. Aucun fetch au chargement (tout est lazy, à l'appel).
(function () {
  'use strict';

  // ---- Config (window.LC.config posé par index.html — fallbacks contrat §4) ----
  var LCC = (typeof window !== 'undefined' && window.LC && window.LC.config) || {};
  var CFG = {
    base: LCC.apiBase || 'http://127.0.0.1:8766',
    apiKey: LCC.apiKey || 'b6d68vy2-m7g5-20r0-5275-h103w73453q120', // même fallback que web api.js:21
    branchId: parseInt(LCC.branchId, 10) || 1,
    countryCode: '+33',
    onlineCardEnabled: LCC.onlineCardEnabled === true, // OFF par défaut (double verrou serveur)
  };

  // ---- Accès lazy aux couches sœurs (storage / menu) ----
  function storage() { return (window.LC && window.LC.storage) || null; }
  function menu() { return (window.LC && window.LC.menu) || {}; }

  function getToken() {
    var s = storage();
    if (s && typeof s.getToken === 'function') return s.getToken();
    var a = s && s.getAuth ? s.getAuth() : null;
    return (a && a.token) || null;
  }

  // ---- UUID (X-Idempotency-Key) ----
  function uuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0, v = c === 'x' ? r : (r & 0x3) | 0x8; return v.toString(16);
    });
  }

  // ---- HTTP wrapper (identique web : erreurs typées, JSON tolérant) ----
  async function req(method, path, opts) {
    opts = opts || {};
    var headers = {
      'Accept': 'application/json',
      'X-API-Key': CFG.apiKey,
    };
    if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
    if (opts.auth) { var t = getToken(); if (t) headers['Authorization'] = 'Bearer ' + t; }
    if (opts.idempotencyKey) headers['X-Idempotency-Key'] = opts.idempotencyKey;

    var res;
    try {
      res = await fetch(CFG.base + path, {
        method: method,
        headers: headers,
        body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
      });
    } catch (e) {
      throw { kind: 'network', message: 'Réseau indisponible. Vérifie ta connexion.', cause: e };
    }
    var text = await res.text();
    var json = null;
    try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
    if (!res.ok) {
      var msg = (json && (json.message || json.error)) || ('Erreur ' + res.status);
      // [GOAL-SYNC 2026-07-08] UI 100% FR : Laravel renvoie « Too Many Attempts. » /
      // « Unauthenticated. » (EN) — traduits ici pour tous les écrans (OTP, QR, redeem…).
      if (res.status === 429) msg = 'Trop de tentatives — patiente une minute puis réessaie.';
      else if (res.status === 401 || res.status === 419) msg = 'Session expirée — reconnecte-toi pour continuer.';
      throw { kind: 'http', status: res.status, message: msg, body: json };
    }
    return json;
  }

  // ---- Auth (guest-signup OTP → token kiosk:order, TTL 30 j) ----
  async function guestOtp(phone) {
    return req('POST', '/api/auth/guest-signup/otp', { body: { phone: phone, code: CFG.countryCode } });
  }
  async function guestVerify(phone, otp) {
    var r = await req('POST', '/api/auth/guest-signup/verify', { body: { phone: phone, code: CFG.countryCode, token: String(otp) } });
    if (r && r.token) {
      var s = storage();
      if (s && s.setAuth) s.setAuth({ token: r.token, phone: phone, user_id: (r.user && r.user.id) || null });
    }
    return r;
  }
  function isAuthed() { return !!getToken(); }
  function logout() { var s = storage(); if (s && s.clearAuth) s.clearAuth(); }

  // ---- Profil (Bearer) — inclut loyalty_points / loyalty_code / name ----
  async function profile() {
    var r = await req('GET', '/api/profile', { auth: true });
    return (r && (r.data || r.user)) || r;
  }

  // ---- Fidélité (contrat §2 — les taux viennent du backend, JAMAIS hardcodés) ----
  async function loyaltyConfig() {
    var r = await req('GET', '/api/frontend/loyalty/config', {});
    return (r && r.data) || r;
  }
  async function loyaltyHistory(page) {
    var qs = '?page=' + (parseInt(page, 10) || 1) + '&per_page=20';
    return req('GET', '/api/frontend/loyalty/history' + qs, { auth: true });
  }
  // QR fidélité signé (throttle backend 30/min) → {token:'lqr.…', expires_at, ttl_seconds, loyalty_code}.
  // ⚠ JAMAIS de QR legacy 'FK:<code>' côté client (rejeté backend).
  async function loyaltyQr() {
    var r = await req('POST', '/api/frontend/loyalty/qr', { auth: true });
    return (r && r.data) || r;
  }
  // Utiliser des points (100 pts = 1 €) — erreurs remappées en FR propre :
  // 400 = points non multiples de 100 / solde insuffisant ; 422 = kill-switch V1.
  async function loyaltyRedeem(code, points) {
    try {
      var r = await req('POST', '/api/frontend/loyalty/redeem', {
        auth: true,
        // [GOAL-SYNC-HEAL 2026-07-08] branch_id requis par le backend (parité placeOrder) —
        // son absence provoquait un 422 de validation avant tout traitement fidélité.
        body: { branch_id: CFG.branchId, code: String(code || '').trim(), points: parseInt(points, 10) || 0 },
        idempotencyKey: 'mob-lr' + uuid(),
      });
      return (r && r.data) || r;
    } catch (e) {
      if (e && e.kind === 'http') {
        var msg = e.message;
        if (!msg || /^Erreur \d+$/.test(msg)) {
          if (e.status === 422) msg = 'La remise fidélité est désactivée pour le moment.';
          else if (e.status === 400) msg = 'Points invalides : utilisez un multiple de 100 dans la limite de votre solde.';
        }
        throw { kind: 'http', status: e.status, message: msg, body: e.body };
      }
      throw e;
    }
  }

  // ---- Index menu (nom canonique normalisé → vrais ids DB) ----
  var _itemIndex = null;   // { bySlug:{slug:realId}, byName:{normName:realId}, rows }
  var _detailsCache = {};  // realId -> details.data

  // norm() COPIE du web api.js — strip accents + préfixe « sauce » + espaces.
  function norm(s) {
    return String(s == null ? '' : s)
      .toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/^sauce\s+/, '').replace(/\s+/g, ' ').trim();
  }

  async function buildItemIndex(force) {
    if (_itemIndex && !force) return _itemIndex;
    var r = await req('GET', '/api/frontend/item', {});
    var rows = (r && (r.data || r)) || [];
    var bySlug = {}, byName = {};
    rows.forEach(function (it) {
      if (it.slug) bySlug[it.slug] = it.id;
      if (it.name) byName[norm(it.name)] = it.id;
    });
    _itemIndex = { bySlug: bySlug, byName: byName, rows: rows };
    return _itemIndex;
  }

  async function itemDetails(realId) {
    if (_detailsCache[realId]) return _detailsCache[realId];
    var r = await req('GET', '/api/frontend/item/details/' + realId, {});
    var d = (r && r.data) || r;
    _detailsCache[realId] = d;
    return d;
  }

  // Ligne mobile (slug/name) → vrai item_id backend (slug d'abord, puis NOM canonique).
  async function resolveItemId(mobileItem) {
    var idx = await buildItemIndex();
    if (mobileItem.slug && idx.bySlug[mobileItem.slug] != null) return idx.bySlug[mobileItem.slug];
    if (mobileItem.name && idx.byName[norm(mobileItem.name)] != null) return idx.byName[norm(mobileItem.name)];
    return null;
  }

  // details → {variationByAttr, extraByGroup, addonByRole, attrMeta} (copie web).
  function indexDetails(d) {
    var variationByAttr = {}, extraByGroup = {}, addonByRole = {}, attrMeta = {};
    var vs = d.variations || {};
    Object.keys(vs).forEach(function (attrId) {
      variationByAttr[attrId] = {};
      var opts = [];
      (vs[attrId] || []).forEach(function (v) {
        variationByAttr[attrId][norm(v.name)] = v.id;
        opts.push({ id: v.id, name: v.name });
      });
      var first = (vs[attrId] || [])[0];
      attrMeta[attrId] = {
        min: first && first.item_attribute ? (first.item_attribute.min_select || 0) : 0,
        max: first && first.item_attribute ? (first.item_attribute.max_select || 0) : 0,
        options: opts,
      };
    });
    (d.extras || []).forEach(function (e) {
      var g = e.group_label || 'other';
      extraByGroup[g] = extraByGroup[g] || {};
      extraByGroup[g][norm(e.name)] = e.id;
    });
    (d.addons || []).forEach(function (a) { if (a.role) addonByRole[a.role] = a.id; });
    return { variationByAttr: variationByAttr, extraByGroup: extraByGroup, addonByRole: addonByRole, attrMeta: attrMeta };
  }

  // Variation par nom à travers plusieurs attrs — préfère un attr non utilisé
  // (2 viandes → Viande 1 puis Viande 2). Copie web.
  function pickVariation(detIdx, attrIds, valueName, usedAttrSet) {
    var n = norm(valueName);
    for (var i = 0; i < attrIds.length; i++) {
      var a = attrIds[i];
      if (usedAttrSet[a]) continue;
      var m = detIdx.variationByAttr[a];
      if (m && m[n] != null) { usedAttrSet[a] = true; return m[n]; }
    }
    for (var j = 0; j < attrIds.length; j++) {
      var mm = detIdx.variationByAttr[attrIds[j]];
      if (mm && mm[n] != null) return mm[n];
    }
    return null;
  }

  function findExtraId(detIdx, groups, valueName) {
    var n = norm(valueName);
    for (var i = 0; i < groups.length; i++) {
      var g = detIdx.extraByGroup[groups[i]];
      if (g && g[n] != null) return g[n];
    }
    return null;
  }

  // ---- Pools mobiles (fallbacks NORMATIFS contrat §5 si le mirror n'exporte pas encore) ----
  var PAINS_FALLBACK = [
    { id: 'pain-classique', name: 'Pain' },
    { id: 'pain-galette', name: 'Galette' },
  ];
  var BOL_SAUCES_FALLBACK = [
    { id: 'bs-fromagere', name: 'Sauce fromagère maison' },
    { id: 'bs-spicy', name: 'Sauce spicy' },
  ];
  // id d'un pool mobile → nom canonique. poolKeys = liste de clés LC.menu à sonder dans l'ordre.
  function nameFromPools(poolKeys, id, fallbackPool) {
    var M = menu();
    for (var i = 0; i < poolKeys.length; i++) {
      var pool = M[poolKeys[i]];
      if (!Array.isArray(pool)) continue;
      for (var j = 0; j < pool.length; j++) if (pool[j].id === id) return pool[j].name;
    }
    if (Array.isArray(fallbackPool)) {
      for (var k = 0; k < fallbackPool.length; k++) if (fallbackPool[k].id === id) return fallbackPool[k].name;
    }
    return null;
  }

  // formule-drink id (d-*) → slug item Boissons — le bolDrinkId devient sa PROPRE ligne
  // au prix catalogue (parité web DRINK_ID_TO_SLUG, +7 saveurs canoniques ids 1009-1015).
  var DRINK_ID_TO_SLUG = {
    'd-coca': 'coca', 'd-coca-zero': 'coca-zero', 'd-fanta': 'fanta', 'd-sprite': 'sprite',
    'd-oasis': 'oasis', 'd-orangina': 'orangina', 'd-eau': 'eau-plate', 'd-capri': 'capri-sun',
    'd-coca-cherry': 'coca-cherry', 'd-tropico': 'tropico', 'd-ice-tea-peche': 'ice-tea-peche',
    'd-fanta-citron': 'fanta-citron', 'd-fuze-tea': 'fuze-tea', 'd-hawai': 'hawai', 'd-perrier': 'perrier',
  };

  // ---- Résolution d'UNE ligne cart mobile (shape buildLineItem) → ligne backend ----
  async function resolveLine(line) {
    line = line || {};
    var M = menu();
    var inMenu = !!(line.menuChoice && line.menuChoice !== 'none');

    // Style de frites STANDALONE = un SKU backend séparé (ex. « Grande Frites Cheddar fondu »)
    // → swap par NOM. Dans une formule (cascade), le style reste une instruction (voir plus bas).
    var resolveTarget = line;
    var styleName = null;
    if (line.fritesStyleId) {
      var fsDef = (M.fritesStyles || []).find(function (x) { return x.id === line.fritesStyleId; });
      if (fsDef && fsDef.name && fsDef.name !== 'Nature') styleName = fsDef.name;
    }
    if (styleName && line.name && !inMenu) {
      var skuId = await resolveItemId({ name: line.name + ' ' + styleName, slug: null });
      if (skuId != null) resolveTarget = { name: line.name + ' ' + styleName, slug: null };
    }

    var realId = await resolveItemId(resolveTarget);
    if (realId == null) throw { kind: 'resolve', message: 'Article introuvable côté caisse : ' + (line.name || line.slug || '?') };
    var d = await itemDetails(realId);
    var dIdx = indexDetails(d);
    var item_variations = [];
    var item_extras = [];
    var item_addons = [];

    // Viandes (meatIds = ids pool m-*) → attrs 1/2 (Viande 1 / Viande 2) par nom.
    var meatAttrs = ['1', '2'];
    var usedAttr = {};
    (Array.isArray(line.meatIds) ? line.meatIds : []).forEach(function (mid) {
      var nm = nameFromPools(['meats'], mid);
      if (!nm) return;
      var vid = pickVariation(dIdx, meatAttrs, nm, usedAttr);
      if (vid != null) item_variations.push({ id: vid, quantity: 1 });
    });

    // Pain (painId = pain-classique | pain-galette) → attr 6.
    if (line.painId) {
      var pn = nameFromPools(['pains'], line.painId, PAINS_FALLBACK);
      var pid = pn ? pickVariation(dIdx, ['6'], pn, {}) : null;
      if (pid != null) item_variations.push({ id: pid, quantity: 1 });
    }

    // Sauces (sauceIds = bs-* pool bol OU s-* pool générique) → attr 5 (sauce) ou 8 (sauce bol).
    var sauceAttrs = ['5', '8'];
    (Array.isArray(line.sauceIds) ? line.sauceIds : []).forEach(function (sid) {
      var nm = nameFromPools(['bolSauces', 'sauces'], sid, BOL_SAUCES_FALLBACK);
      if (!nm) return;
      var vid = pickVariation(dIdx, sauceAttrs, nm, {});
      if (vid != null) item_variations.push({ id: vid, quantity: 1 });
    });

    // Crudités — sémantique mobile NÉGATIVE (cruditeRemoved) : on envoie les crudités
    // GARDÉES = pool (4) moins retirées. cruditeIds (gardées) prime s'il est présent.
    if (line.has_crudites) {
      var keptNames = [];
      if (Array.isArray(line.cruditeIds)) {
        keptNames = line.cruditeIds.map(function (cid) { return nameFromPools(['crudites'], cid); }).filter(Boolean);
      } else {
        var removed = (Array.isArray(line.cruditeRemoved) ? line.cruditeRemoved : []).map(norm);
        keptNames = (M.crudites || [])
          .map(function (c) { return c.name; })
          .filter(function (nm) { return removed.indexOf(norm(nm)) === -1; });
      }
      keptNames.forEach(function (nm) {
        var eid = findExtraId(dIdx, ['crudite'], nm);
        if (eid != null) item_extras.push({ id: eid, quantity: 1 });
      });
    }

    // Suppléments (supplementIds = sup-*) → extras group 'supplement'.
    (Array.isArray(line.supplementIds) ? line.supplementIds : []).forEach(function (sid) {
      var nm = nameFromPools(['supplements'], sid);
      if (!nm) return;
      var eid = findExtraId(dIdx, ['supplement'], nm);
      if (eid != null) item_extras.push({ id: eid, quantity: 1 });
    });

    // Suppléments bol (bolSupplementIds = sb-*) → extras group 'supplement_bol'.
    (Array.isArray(line.bolSupplementIds) ? line.bolSupplementIds : []).forEach(function (sid) {
      var nm = nameFromPools(['supplementsBols'], sid);
      if (!nm) return;
      var eid = findExtraId(dIdx, ['supplement_bol'], nm);
      if (eid != null) item_extras.push({ id: eid, quantity: 1 });
    });

    // Viande supplémentaire +2,50 € (extraMeatIds = ids pool m-*) → extra « Viande supplémentaire ».
    var extraMeatCount = (Array.isArray(line.extraMeatIds) ? line.extraMeatIds.length : 0);
    if (extraMeatCount > 0) {
      var emId = findExtraId(dIdx, ['supplement', 'supplement_bol'], 'Viande supplémentaire');
      if (emId != null) item_extras.push({ id: emId, quantity: extraMeatCount });
    }

    // Formule menu (menuChoice full|frites|boisson) → addon backend par role.
    // Le drink/style choisis dans la cascade ne sont PAS des variations d'addon backend →
    // relayés à la cuisine via l'instruction de ligne (parité web, prix = addon seul).
    // [GOAL-SYNC-HEAL 2026-07-08] La formule est PORTÉE par l'unique addon role
    // 'menu_component' de l'item ; le rôle ENVOYÉ (menu_full/menu_frites/menu_boisson)
    // décide du prix backend (PricingService SSOT : +2,50 / +1,50 / +1,00). Le backend
    // (ValidatesAddonRoles) REJETTE 422 menu_frites/menu_boisson s'ils ne ciblent PAS
    // 'menu_component' → on ne cible donc PLUS les addons side/drink.
    var menuNoteParts = [];
    if (inMenu) {
      var isMenu = line.menuChoice === 'full' || line.menuChoice === 'frites' || line.menuChoice === 'boisson';
      var addonId = isMenu ? dIdx.addonByRole['menu_component'] : null;
      if (addonId != null) {
        var sentRole = line.menuChoice === 'full' ? 'menu_full' : line.menuChoice === 'frites' ? 'menu_frites' : 'menu_boisson';
        item_addons.push({ id: addonId, quantity: 1, role: sentRole });
      }
      if (styleName) menuNoteParts.push('Frites: ' + styleName);
      if (line.drinkId) {
        var dn = nameFromPools(['formuleDrinks'], line.drinkId);
        if (dn) menuNoteParts.push('Boisson menu: ' + dn);
      }
      var fsNames = (Array.isArray(line.fritesSauceIds) ? line.fritesSauceIds : [])
        .map(function (sid) { return nameFromPools(['sauces'], sid); }).filter(Boolean);
      if (fsNames.length) menuNoteParts.push('Sauce frites: ' + fsNames.join('/'));
    }

    // Complète les attributs REQUIS (min_select) non couverts par la compo mobile —
    // sans ça le backend rejette (422). Top-up à min via les 1res options (jamais > max).
    Object.keys(dIdx.attrMeta).forEach(function (attrId) {
      var meta = dIdx.attrMeta[attrId];
      if (!meta || meta.min <= 0) return;
      var have = item_variations.filter(function (v) {
        return meta.options.some(function (o) { return o.id === v.id; });
      }).length;
      for (var k = have; k < meta.min && k < meta.options.length; k++) {
        item_variations.push({ id: meta.options[k].id, quantity: 1 });
      }
    });

    var out = { item_id: realId, quantity: line.qty || 1 };
    if (item_variations.length) out.item_variations = item_variations;
    if (item_extras.length) out.item_extras = item_extras;
    if (item_addons.length) out.item_addons = item_addons;
    var noteAll = [line.instruction].concat(menuNoteParts).filter(Boolean).join(' · ');
    if (noteAll) out.instruction = String(noteAll).slice(0, 500);
    return out;
  }

  // ---- Résolution du panier complet (lignes buildLineItem) ----
  async function resolveOrderItems(lines) {
    var items = [];
    var arr = Array.isArray(lines) ? lines : [];
    for (var i = 0; i < arr.length; i++) {
      var line = arr[i];
      items.push(await resolveLine(line));
      // Boisson du bol (bolDrinkId, prix catalogue déjà compté par priceFor) → sa PROPRE
      // ligne réelle pour que la caisse la facture (parité web).
      var bd = line && line.bolDrinkId;
      if (bd && bd !== '__none' && DRINK_ID_TO_SLUG[bd]) {
        var M = menu();
        var drinkItem = M.findItem ? M.findItem(DRINK_ID_TO_SLUG[bd]) : null;
        if (drinkItem) {
          items.push(await resolveLine(Object.assign({}, drinkItem, { qty: 1 })));
        }
      }
    }
    return items;
  }

  // ---- Passage de commande (POST /api/frontend/order) ----
  // o = { cart:[lignes buildLineItem], orderType?, loyaltyCode?, idempotencyKey? }.
  // payment_method 1 = paiement au comptoir (flag Stripe OFF ⇒ toujours 1, contrat §4).
  async function placeOrder(o) {
    o = o || {};
    if (!isAuthed()) throw { kind: 'auth', message: 'Connexion requise pour commander.' };
    var cart = o.cart || o.lines || [];
    if (!cart.length) throw { kind: 'resolve', message: 'Panier vide.' };
    var items = await resolveOrderItems(cart);
    var body = {
      branch_id: o.branchId || CFG.branchId,
      order_type: o.orderType || 10,          // 10 = À EMPORTER (défaut)
      source: 5,                               // contrat §7
      is_advance_order: 0,
      payment_method: 1,                       // comptoir — jamais 4 tant que flag OFF
      items: JSON.stringify(items),
    };
    if (o.loyaltyCode) body.loyalty_code = o.loyaltyCode;
    var r = await req('POST', '/api/frontend/order', {
      auth: true,
      body: body,
      idempotencyKey: o.idempotencyKey || ('mob' + uuid().replace(/-/g, '').slice(0, 24)),
    });
    return (r && r.data) || r;
  }

  async function getOrder(id) {
    var r = await req('GET', '/api/frontend/order/show/' + id, { auth: true });
    return (r && r.data) || r;
  }

  async function orderHistory(limit) {
    var r = await req('GET', '/api/frontend/order' + (limit ? ('?limit=' + limit) : ''), { auth: true });
    return (r && r.data) || [];
  }

  // ---- EXPORT ----
  window.LC = window.LC || {};
  window.LC.mobileApi = {
    config: CFG,
    // auth
    guestOtp: guestOtp, guestVerify: guestVerify, isAuthed: isAuthed, logout: logout,
    // profil + fidélité
    profile: profile, loyaltyConfig: loyaltyConfig, loyaltyHistory: loyaltyHistory,
    loyaltyQr: loyaltyQr, loyaltyRedeem: loyaltyRedeem,
    // menu / résolution
    buildItemIndex: buildItemIndex, itemDetails: itemDetails,
    resolveLine: resolveLine, resolveOrderItems: resolveOrderItems, _norm: norm,
    // commandes
    placeOrder: placeOrder, getOrder: getOrder, orderHistory: orderHistory, uuid: uuid,
  };
})();
