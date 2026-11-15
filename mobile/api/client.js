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

  // [APPSTORE-ACCOUNT-DELETION 2026-10-16] Apple requires an in-app path to
  // delete the customer account. The server remains the authority: this call
  // does not purge local state and never reports success on a failed response.
  async function deleteAccount() {
    try {
      return await req('POST', '/api/delete-account', { auth: true });
    } catch (e) {
      if (e && e.kind === 'http' && e.status === 401) {
        throw Object.assign({}, e, { message: 'La vérification de ton compte est requise avant sa suppression.' });
      }
      throw e;
    }
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
    // [2026-11-15] Fiche demandée POUR LA BRANCHE (le serveur n'indique la rupture d'une viande ou d'un supplément
    // que si la branche est précisée) et gardée 45 s seulement : une rupture posée en caisse doit arriver dans
    // l'assistant sans relancer l'application.
    var c = _detailsCache[realId];
    if (c && c.d && Date.now() - c.t < 45000) return c.d;
    var r = await req('GET', '/api/frontend/item/details/' + realId + '?branch_id=' + encodeURIComponent(CFG.branchId), {});
    var d = (r && r.data) || r;
    _detailsCache[realId] = { d: d, t: Date.now() };
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
    // [2026-11-15 relecture adverse P0] Avec un profil publié, SEULS ses choix disponibles existent pour la ligne :
    // une viande ou une sauce passée en rupture depuis l'ajout au panier était placée, puis retirée EN SILENCE par
    // le filtre du profil (Tacos L payé, une seule viande servie). Elle devient introuvable ici → la commande
    // s'arrête avec un message, panier intact ; le placement des viandes ne compte que les emplacements réels.
    var profLigne = profileChoiceIds(d);
    var enRuptureFiche = {};
    Object.keys(d.variations || {}).forEach(function (a) {
      (d.variations[a] || []).forEach(function (v) {
        if (v && v.is_available === false) {
          enRuptureFiche[norm(v.name)] = true;
          if (!profLigne && dIdx.variationByAttr[a]) delete dIdx.variationByAttr[a][norm(v.name)];
        }
      });
    });
    if (!profLigne) {   // le complément automatique (sauce obligatoire…) ne prend jamais un choix en rupture
      Object.keys(dIdx.attrMeta).forEach(function (a) {
        var ko = {};
        (d.variations[a] || []).forEach(function (v) { if (v && v.is_available === false) ko[v.id] = true; });
        dIdx.attrMeta[a].options = dIdx.attrMeta[a].options.filter(function (o) { return !ko[o.id]; });
      });
    }
    Object.keys(dIdx.variationByAttr).forEach(function (a) {   // disponible sur un autre emplacement = pas en rupture
      Object.keys(dIdx.variationByAttr[a]).forEach(function (n) { delete enRuptureFiche[n]; });
    });
    if (profLigne) {
      Object.keys(dIdx.variationByAttr).forEach(function (a) {
        var m = dIdx.variationByAttr[a];
        Object.keys(m).forEach(function (n) { if (!profLigne.variations[m[n]]) delete m[n]; });
      });
      Object.keys(dIdx.attrMeta).forEach(function (a) {
        dIdx.attrMeta[a].options = dIdx.attrMeta[a].options.filter(function (o) { return !!profLigne.variations[o.id]; });
      });
    }
    var item_variations = [];
    var item_extras = [];
    var item_addons = [];

    // [2026-11-14] Une option choisie que le serveur ne propose pas pour CE produit faisait l'objet d'un
    // abandon SILENCIEUX : le client voyait (et croyait payer) une Boule gratinée qui n'arrivait jamais en
    // cuisine. Désormais la commande s'arrête avec un message clair, panier intact.
    var optionAbsente = function (nm) {
      if ((profLigne && (profLigne.rupture.variations[norm(nm)] || profLigne.rupture.extras[norm(nm)])) || (!profLigne && enRuptureFiche[norm(nm)])) {
        throw { kind: 'resolve', message: '« ' + nm + ' » est en rupture pour ' + (line.name || 'ce produit') + '. Retire ce produit du panier et recompose-le avec un autre choix.' };
      }
      throw { kind: 'resolve', message: '« ' + nm + ' » n’est plus proposé pour ' + (line.name || 'ce produit') + '. Retire-le du panier et recommence ce produit.' };
    };
    var choisis = [];          // extras choisis par le client (jamais retirés en silence)
    // [2026-11-15] Un supplément pris deux fois = UNE entrée avec quantité 2 (le serveur compte la quantité).
    var ajouteExtra = function (eid, nm) {
      var deja = null;
      item_extras.forEach(function (x) { if (x.id === eid) deja = x; });
      if (deja) { deja.quantity += 1; return; }
      item_extras.push({ id: eid, quantity: 1 });
      choisis.push({ id: eid, nom: nm });
    };
    var viandesEnPlus = null;
    // Viandes (meatIds = ids pool m-*) → attrs 1/2 (Viande 1 / Viande 2) par nom.
    var meatAttrs = ['1', '2', '3']; // [2026-11-14 R6-002] Tacos XL : 3 emplacements (Viande 1/2/3)
    var usedAttr = {};
    // [2026-11-15] La viande proposée sur le moins d'emplacements est placée en premier : sinon une viande présente
    // sur un seul emplacement pouvait le trouver déjà pris par une autre (deux viandes sur le même emplacement).
    // L'ordre d'affichage du client n'est pas modifié (ligne du panier).
    var nbEmplacements = function (nm) {
      var n = norm(nm), k = 0;
      meatAttrs.forEach(function (a) { var m = dIdx.variationByAttr[a]; if (m && m[n] != null) k++; });
      return k;
    };
    var viandesChoisies = (Array.isArray(line.meatIds) ? line.meatIds : [])
      .map(function (mid) { return nameFromPools(['meats'], mid); }).filter(Boolean)
      .map(function (nm, i) { return { nm: nm, i: i, k: nbEmplacements(nm) }; })
      .sort(function (a, b) { return (a.k - b.k) || (a.i - b.i); });
    viandesChoisies.forEach(function (v) {
      var nm = v.nm;
      // Placement STRICT : un emplacement libre qui propose cette viande, sinon arrêt avec message — jamais deux
      // viandes sur le même emplacement (le repli de pickVariation le faisait quand un emplacement était en rupture).
      var vid = null;
      for (var ai = 0; ai < meatAttrs.length && vid == null; ai++) {
        var am = dIdx.variationByAttr[meatAttrs[ai]];
        if (!usedAttr[meatAttrs[ai]] && am && am[norm(nm)] != null) { usedAttr[meatAttrs[ai]] = true; vid = am[norm(nm)]; }
      }
      if (vid == null) optionAbsente(nm);
      item_variations.push({ id: vid, quantity: 1 });
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
      if (vid == null && !/^sans/i.test(nm)) optionAbsente(nm);
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
      if (eid == null) optionAbsente(nm);
      ajouteExtra(eid, nm);
    });

    // Suppléments bol (bolSupplementIds = sb-*) → extras group 'supplement_bol'.
    (Array.isArray(line.bolSupplementIds) ? line.bolSupplementIds : []).forEach(function (sid) {
      var nm = nameFromPools(['supplementsBols'], sid);
      if (!nm) return;
      var eid = findExtraId(dIdx, ['supplement_bol'], nm);
      if (eid == null) optionAbsente(nm);
      ajouteExtra(eid, nm);
    });

    // Viande supplémentaire +2,50 € (extraMeatIds = ids pool m-*) → extra « Viande supplémentaire ».
    var extraMeatCount = (Array.isArray(line.extraMeatIds) ? line.extraMeatIds.length : 0);
    if (extraMeatCount > 0) {
      // « Viande supplémentaire » ou, sur le Cayenne et le Suprême en production, « Viande en plus » (même extra).
      var emNom = 'Viande supplémentaire';
      var emId = findExtraId(dIdx, ['supplement', 'supplement_bol'], emNom);
      if (emId == null) { emNom = 'Viande en plus'; emId = findExtraId(dIdx, ['supplement', 'supplement_bol'], emNom); }
      if (emId == null) optionAbsente('Viande supplémentaire');
      item_extras.push({ id: emId, quantity: extraMeatCount });
      choisis.push({ id: emId, nom: emNom });
      // [2026-11-14] R3-002 : la cuisine doit savoir QUELLE viande (format lu par le serveur, plafonné aux
      // extras payés) — avant, seul « Viande supplémentaire » partait.
      var emNoms = line.extraMeatIds.map(function (mid) { return nameFromPools(['meats'], mid); }).filter(Boolean);
      // [2026-11-15] La même viande en plus deux fois s'écrit « 2× Tenders » (convention de la caisse et de la borne) :
      // les lecteurs de la cuisine dédoublonnent les noms (KitchenTicketSymbolicFormatter::splitViandeList,
      // kdsSymbolic.js) — « Tenders, Tenders » n'annonçait qu'UNE viande pour deux payées.
      var emGroupes = [];
      emNoms.forEach(function (nm) {
        var g = null; emGroupes.forEach(function (x) { if (x.nm === nm) g = x; });
        if (g) g.n += 1; else emGroupes.push({ nm: nm, n: 1 });
      });
      if (emNoms.length) viandesEnPlus = 'Viandes en plus : ' + emGroupes.map(function (x) { return x.n > 1 ? x.n + '× ' + x.nm : x.nm; }).join(', ') + '.';
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
      // Format lu par le serveur (FrontendOrderService::instructionWebAssainie) : « Sauce frites : X »,
      // espace avant les deux-points ; la 1re sauce frites est offerte, une seule est envoyée.
      if (fsNames.length) menuNoteParts.push('Sauce frites : ' + fsNames[0]);
    }

    // Complète les attributs REQUIS (min_select) non couverts par la compo mobile —
    // sans ça le backend rejette (422). Top-up à min via les 1res options (jamais > max).
    Object.keys(dIdx.attrMeta).forEach(function (attrId) {
      var meta = dIdx.attrMeta[attrId];
      if (!meta || meta.min <= 0) return;
      var have = item_variations.filter(function (v) {
        return meta.options.some(function (o) { return o.id === v.id; });
      }).length;
      // [2026-11-15 relecture adverse P0] Jamais de VIANDE choisie à la place du client : le complément prenait la
      // première option (« Poulet mariné ») et un Cheese Burger partait en cuisine avec une viande ni choisie ni
      // affichée. L'assistant demande désormais la viande ; une ligne sans viande s'arrête avec un message.
      if (have < meta.min && meatAttrs.indexOf(String(attrId)) !== -1 && meta.options.length) {
        throw { kind: 'resolve', message: 'Il manque le choix de la viande pour ' + (line.name || 'ce produit') + '. Retire-le du panier et recompose-le.' };
      }
      for (var k = have; k < meta.min && k < meta.options.length; k++) {
        item_variations.push({ id: meta.options[k].id, quantity: 1 });
      }
    });

    // [2026-11-14] Le PROFIL DE COMPOSITION publié fait foi (comme la borne et la caisse) : un choix absent
    // du profil est refusé en 422 (« le choix #708 n'appartient pas au profil publié » — le pain du Sandwich
    // Classique). On n'envoie donc que les choix du profil ; une rubrique que le profil ne demande pas
    // (ex. type de pain) n'est pas envoyée.
    var prof = profileChoiceIds(d);
    // [2026-11-14 R9-002] « Galette » choisie alors que le profil du produit ne propose pas le choix du pain
    // (options non chargées dans l'assistant) : on arrête avec un message au lieu d'envoyer un sandwich sans
    // la galette annoncée. Le pain par défaut, lui, n'a pas besoin d'être transmis.
    if (prof && line.painId && line.painId !== 'pain-classique') {
      var painNom = nameFromPools(['pains'], line.painId, PAINS_FALLBACK) || 'Galette';
      var painVid = pickVariation(dIdx, ['6'], painNom, {});
      if (painVid == null || !prof.variations[painVid]) optionAbsente(painNom);
    }
    if (prof) {
      item_variations = item_variations.filter(function (v) { return prof.variations[v.id]; });
      item_extras = item_extras.filter(function (e) { return prof.extras[e.id]; });
      choisis.forEach(function (c) { if (!prof.extras[c.id]) optionAbsente(c.nom); });
    }
    var out = { item_id: realId, quantity: line.qty || 1 };
    if (item_variations.length) out.item_variations = item_variations;
    if (item_extras.length) out.item_extras = item_extras;
    if (item_addons.length) out.item_addons = item_addons;
    // [2026-11-14] UNE rubrique par LIGNE (le serveur découpe sur les retours à la ligne et retire toute
    // ligne inconnue) ; la consigne du client voyage en « [Note : … ] » — sinon la cuisine ne voyait ni la
    // boisson du menu, ni la sauce frites, ni « sans oignon » (relecture adverse A2-004/A2-005, vérifié au code).
    var note = String(line.instruction || '').replace(/[\[\]\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
    // [2026-11-14 R8 P2] Les rubriques de commande (viande en plus, boisson du menu, sauce frites) passent
    // TOUJOURS ; seule la note libre est raccourcie pour tenir dans les 500 octets.
    var fixes = (viandesEnPlus ? [viandesEnPlus] : []).concat(menuNoteParts);
    var place = 500 - (typeof TextEncoder === 'function' ? new TextEncoder().encode(fixes.join('\n')).length : fixes.join('\n').length * 4) - (fixes.length ? 1 : 0) - '[Note : ]'.length - 2;
    var noteCourte = note && place > 0 ? coupeOctets(note, place).trim() : '';
    var noteAll = (noteCourte ? ['[Note : ' + noteCourte + ']'] : []).concat(fixes).join('\n');
    // [2026-11-14 R7-003] Le serveur limite la consigne à 500 OCTETS (UTF-8), pas 500 caractères : une note riche
    // en emojis passait à 400 caractères mais dépassait 500 octets → 422. On coupe au dernier caractère entier.
    if (noteAll) out.instruction = coupeOctets(String(noteAll), 500);
    return out;
  }

  // Ids des choix du profil de composition publié (null s'il n'y en a pas : on garde alors les variations).
  function profileChoiceIds(d) {
    var steps = d && d.composer_profile && d.composer_profile.is_published !== false && d.composer_profile.steps;
    if (!Array.isArray(steps) || !steps.length) return null;
    var out = { variations: {}, extras: {}, names: { variations: {}, extras: {} }, sauceMax: null,
      // [2026-11-15] Règles de quantité du profil publié : l'assistant propose « deux fois le même » et la viande
      // en plus dans la limite EXACTE que le serveur accepte (max_select / allow_repeat de l'étape), jamais plus.
      limits: { extrasMax: 0, extrasRepeat: false, meatSlots: [], meatMin: 0 },
      // [2026-11-15] Choix vendus avec ce plat mais EN RUPTURE aujourd'hui (aucun emplacement ne les propose) :
      // l'assistant les montre grisés « Rupture » au lieu de les faire disparaître sans explication.
      rupture: { variations: {}, extras: {} } };
    var vus = { variations: {}, extras: {} };
    steps.slice().sort(function (a, b) { return (Number(a && a.position) || 0) - (Number(b && b.position) || 0); }).forEach(function (st) {
      if (st && st.is_active === false) return;
      if (st && st.step_key === 'sauce' && Number(st.max_select) > 0) out.sauceMax = Number(st.max_select);
      if (st && st.source_type === 'extra_group' && /^supplement/.test(String(st.source_ref || ''))) {
        out.limits.extrasMax = Number(st.max_select) || 0;
        out.limits.extrasRepeat = !!st.allow_repeat;
      }
      if (st && st.source_type === 'item_attribute' && /^viande/.test(String(st.step_key || ''))) {
        if (!out.limits.meatSlots.length) out.limits.meatMin = Number(st.min_select) || 0;
        var slot = {};
        (st.choices || []).forEach(function (c) {
          if (c && c.is_available !== false && (c.status == null || Number(c.status) === 5)) slot[norm(c.name)] = true;
        });
        out.limits.meatSlots.push(slot);
      }
      ((st && st.choices) || []).forEach(function (c) {
        var kk = c && (c.source_type === 'extra' ? 'extras' : c.source_type === 'variation' ? 'variations' : null);
        if (kk && c.is_available === false && (c.status == null || Number(c.status) === 5)) vus[kk][norm(c.name)] = true;
        if (!c || c.is_available === false || (c.status != null && Number(c.status) !== 5)) return;
        var k = c.source_type === 'extra' ? 'extras' : c.source_type === 'variation' ? 'variations' : null;
        if (!k) return;
        out[k][c.id] = true;
        out.names[k][norm(c.name)] = true;
      });
    });
    ['variations', 'extras'].forEach(function (k) {
      Object.keys(vus[k]).forEach(function (n) { if (!out.names[k][n]) out.rupture[k][n] = true; });
    });
    return out;
  }

  // [2026-11-14] Options réellement proposées par le serveur pour CET article (noms normalisés) :
  // l'assistant n'affiche que celles-là (variations = viandes/sauces/pain, extras = suppléments).
  async function optionNames(realId) {
    var d = await itemDetails(realId);
    var prof = profileChoiceIds(d);
    // Nombre de viandes comprises = règle du serveur (`viande_count`), jamais la carte locale de l'app.
    if (prof) prof.limits.viandeCount = Number(d && d.viande_count) || 0;
    if (prof) return { variations: prof.names.variations, extras: prof.names.extras, profile: true, sauceMax: prof.sauceMax, limits: prof.limits, rupture: prof.rupture };
    var out = { variations: {}, extras: {}, rupture: { variations: {}, extras: {} } };
    var vs = (d && d.variations) || {};
    Object.keys(vs).forEach(function (a) {
      (vs[a] || []).forEach(function (v) { if (v && v.is_available !== false) out.variations[norm(v.name)] = true; });
    });
    ((d && d.extras) || []).forEach(function (e) { if (e && e.is_available !== false) out.extras[norm(e.name)] = true; });
    Object.keys(vs).forEach(function (a) {
      (vs[a] || []).forEach(function (v) { if (v && v.is_available === false && !out.variations[norm(v.name)]) out.rupture.variations[norm(v.name)] = true; });
    });
    ((d && d.extras) || []).forEach(function (e) { if (e && e.is_available === false && !out.extras[norm(e.name)]) out.rupture.extras[norm(e.name)] = true; });
    // [2026-11-15] Produit SANS profil publié (en production : Cayenne, galettes, tacos, sandwichs) : les emplacements
    // viande et leur minimum se lisent sur les attributs « Viande 1/2/3 » (ids 1, 2, 3). Sans cela l'assistant ne
    // demandait pas la viande du Cayenne et l'envoi la choisissait à la place du client.
    out.limits = { extrasMax: 0, extrasRepeat: false, meatSlots: [], meatMin: 0, viandeCount: Number(d && d.viande_count) || 0 };
    ['1', '2', '3'].forEach(function (a) {
      var rows = vs[a] || [];
      if (!rows.length) return;
      if (!out.limits.meatSlots.length) {
        var ia = rows[0] && rows[0].item_attribute;
        out.limits.meatMin = ia ? (Number(ia.min_select) || 0) : 0;
      }
      var slot = {};
      rows.forEach(function (v) { if (v && v.is_available !== false) slot[norm(v.name)] = true; });
      out.limits.meatSlots.push(slot);
    });
    return out;
  }

  function coupeOctets(t, max) {
    // Une moitié d'emoji (surrogate isolé) rend le JSON refusé par le serveur : on l'enlève d'abord.
    t = String(t).replace(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(^|[^\uD800-\uDBFF])[\uDC00-\uDFFF]/g, '$1');
    if (typeof TextEncoder !== 'function') return t.slice(0, Math.floor(max / 4));
    var enc = new TextEncoder();
    if (enc.encode(t).length <= max) return t;
    var chars = Array.from(t), out = '';
    for (var i = 0; i < chars.length; i++) { if (enc.encode(out + chars[i]).length > max) break; out += chars[i]; }
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
          // [2026-11-14 R6-003] une boisson PAR bol commandé (avant : toujours 1 → 2 bols, 1 boisson facturée et servie).
          items.push(await resolveLine(Object.assign({}, drinkItem, { qty: Math.max(1, Number(line.qty) || 1) })));
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
    profile: profile, deleteAccount: deleteAccount, loyaltyConfig: loyaltyConfig, loyaltyHistory: loyaltyHistory,
    loyaltyQr: loyaltyQr, loyaltyRedeem: loyaltyRedeem,
    // menu / résolution
    buildItemIndex: buildItemIndex, itemDetails: itemDetails,
    resolveLine: resolveLine, resolveOrderItems: resolveOrderItems, _norm: norm, optionNames: optionNames,
    // commandes
    placeOrder: placeOrder, getOrder: getOrder, orderHistory: orderHistory, uuid: uuid,
  };
})();
